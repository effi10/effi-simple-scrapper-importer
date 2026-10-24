<?php
/**
 * Import d'un fichier : analyse, conversion en blocs, contrôle des doublons,
 * création de l'article, images.
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFFISSI_Importer {

	/** Statuts proposés. */
	const ALLOWED_STATUSES = array( 'draft', 'pending', 'publish' );

	/** @var EFFISSI_Markdown_Parser */
	private $parser;

	public function __construct() {
		$this->parser = new EFFISSI_Markdown_Parser();
	}

	/**
	 * Options par défaut d'un import.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'post_status'        => 'draft',
			'duplicates'         => 'skip',
			'featured_by_number' => false,
			'import_images'      => false,
		);
	}

	/**
	 * Importe un fichier Markdown.
	 *
	 * @param string $file_path Chemin du fichier (temporaire).
	 * @param array  $options   Voir default_options().
	 * @return array Résultat : status (imported|skipped), message, title, slug, post_id, edit_url, warnings.
	 * @throws Exception En cas d'erreur bloquante.
	 */
	public function import_file( $file_path, array $options = array() ) {
		$options = $this->normalize_options( $options );
		$data    = $this->parser->parse_file( $file_path );

		return $this->import_data( $data, $options );
	}

	/**
	 * Importe des données déjà analysées.
	 *
	 * @param array $data    Données de EFFISSI_Markdown_Parser::parse_content().
	 * @param array $options Options normalisées.
	 * @return array
	 * @throws Exception En cas d'erreur bloquante.
	 */
	private function import_data( array $data, array $options ) {
		$warnings = array();
		$existing = $this->find_posts_with_slug( $data['slug'] );

		if ( $existing ) {
			if ( 'skip' === $options['duplicates'] ) {
				return array(
					'status'   => 'skipped',
					'title'    => $data['title'],
					'slug'     => $data['slug'],
					'post_id'  => 0,
					'edit_url' => (string) get_edit_post_link( $existing[0], 'raw' ),
					'warnings' => array(),
					'message'  => sprintf(
						/* translators: 1: slug, 2: ID de l'article existant */
						__( 'Fichier ignoré : le slug « %1$s » est déjà utilisé par l\'article n° %2$d.', 'effi-simple-scrapper-importer' ),
						$data['slug'],
						$existing[0]
					),
				);
			}

			$warnings[] = sprintf(
				/* translators: 1: slug, 2: ID de l'article existant */
				__( 'Le slug « %1$s » était déjà utilisé par l\'article n° %2$d : WordPress ajoutera un suffixe (-2, -3...) à la publication.', 'effi-simple-scrapper-importer' ),
				$data['slug'],
				$existing[0]
			);
		}

		$images = null;

		if ( $options['import_images'] ) {
			if ( current_user_can( 'upload_files' ) ) {
				$images = new EFFISSI_Image_Importer();
			} else {
				$warnings[] = __( 'Images non importées : votre compte n\'a pas le droit de téléverser des fichiers.', 'effi-simple-scrapper-importer' );
			}
		}

		$converter = new EFFISSI_Gutenberg_Converter( $images ? array( $images, 'import' ) : null );
		$content   = $converter->convert_to_blocks( $data['markdown'], $data['source_url'], $this->must_filter_html() );

		if ( $images ) {
			$warnings = array_merge( $warnings, $images->get_warnings() );
		}

		$post_data = $this->build_post_data( $data, $content, $options['post_status'] );
		$post_id   = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			if ( $images ) {
				$images->delete_created();
			}

			$message = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'La création de l\'article a échoué.', 'effi-simple-scrapper-importer' );

			throw new Exception( $message );
		}

		$post_id = (int) $post_id;

		if ( $images ) {
			$images->attach_to_post( $post_id );
		}

		$actual_slug = (string) get_post_field( 'post_name', $post_id );

		if ( '' !== $actual_slug && $actual_slug !== $data['slug'] ) {
			$warnings[] = sprintf(
				/* translators: 1: slug demandé, 2: slug attribué */
				__( 'Slug « %1$s » indisponible : WordPress a attribué « %2$s ».', 'effi-simple-scrapper-importer' ),
				$data['slug'],
				$actual_slug
			);
		}

		if ( $options['featured_by_number'] ) {
			$featured = $this->attach_featured_image( $post_id, $data['slug'] );

			if ( '' !== $featured ) {
				$warnings[] = $featured;
			}
		}

		/**
		 * Après l'import d'un article.
		 *
		 * @param int   $post_id ID de l'article créé.
		 * @param array $data    Titre, slug, URL source et Markdown.
		 * @param array $options Options de l'import.
		 */
		do_action( 'effissi_post_imported', $post_id, $data, $options );

		return array(
			'status'   => 'imported',
			'title'    => $post_data['post_title'],
			'slug'     => '' !== $actual_slug ? $actual_slug : $data['slug'],
			'post_id'  => $post_id,
			'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
			'warnings' => $warnings,
			'message'  => sprintf(
				/* translators: 1: titre, 2: ID de l'article */
				__( 'Article « %1$s » importé (n° %2$d).', 'effi-simple-scrapper-importer' ),
				$post_data['post_title'],
				$post_id
			),
		);
	}

	/**
	 * Prépare les données de l'article, sans rien enregistrer.
	 *
	 * Le contenu provient d'un site tiers : il est filtré par wp_kses_post(),
	 * sauf si l'utilisateur possède unfiltered_html ET que le filtre
	 * effissi_allow_unfiltered_html l'autorise explicitement.
	 *
	 * @param array  $data        Données analysées.
	 * @param string $content     Contenu en blocs.
	 * @param string $post_status Statut.
	 * @return array Données pour wp_insert_post() (non échappées).
	 */
	public function build_post_data( array $data, $content, $post_status ) {
		$title = trim( wp_strip_all_tags( (string) $data['title'] ) );

		if ( $this->must_filter_html() ) {
			$content = wp_kses_post( (string) $content );
		}

		$post_data = array(
			'post_title'   => $title,
			'post_name'    => (string) $data['slug'],
			'post_content' => (string) $content,
			'post_status'  => in_array( $post_status, self::ALLOWED_STATUSES, true ) ? $post_status : 'draft',
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);

		/**
		 * Filtre les données passées à wp_insert_post() (avant wp_slash()).
		 *
		 * @param array $post_data Données de l'article.
		 * @param array $data      Titre, slug, URL source et Markdown.
		 */
		return (array) apply_filters( 'effissi_post_data', $post_data, $data );
	}

	/**
	 * Le HTML doit-il être filtré ?
	 *
	 * @return bool
	 */
	public function must_filter_html() {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return true;
		}

		/**
		 * Autorise un utilisateur disposant de unfiltered_html à importer le HTML
		 * brut sans filtrage. Désactivé par défaut : le contenu vient d'un site tiers.
		 *
		 * @param bool $allow Faux par défaut.
		 */
		return ! apply_filters( 'effissi_allow_unfiltered_html', false );
	}

	/**
	 * Articles (hors corbeille) qui utilisent déjà ce slug.
	 *
	 * @param string $slug Slug.
	 * @return int[]
	 */
	public function find_posts_with_slug( $slug ) {
		if ( '' === (string) $slug ) {
			return array();
		}

		$ids = get_posts(
			array(
				'name'             => (string) $slug,
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 5,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Normalise et valide les options.
	 *
	 * @param array $options Options brutes.
	 * @return array
	 */
	public function normalize_options( array $options ) {
		$options = wp_parse_args( $options, self::default_options() );
		$status  = sanitize_key( (string) $options['post_status'] );

		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			$status = 'draft';
		}

		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'pending';
		}

		return array(
			'post_status'        => $status,
			'duplicates'         => 'import' === $options['duplicates'] ? 'import' : 'skip',
			'featured_by_number' => (bool) $options['featured_by_number'],
			'import_images'      => (bool) $options['import_images'],
		);
	}

	/**
	 * Définit l'image à la une d'après le numéro final du slug.
	 * Exemple : « mon-article-c123 » => image « 123.jpg » de la médiathèque.
	 *
	 * @param int    $post_id ID de l'article.
	 * @param string $slug    Slug.
	 * @return string Avertissement éventuel (chaîne vide si tout va bien).
	 */
	private function attach_featured_image( $post_id, $slug ) {
		$number = $this->extract_number_from_slug( $slug );

		if ( '' === $number ) {
			return __( 'Image à la une : aucun numéro en fin de slug.', 'effi-simple-scrapper-importer' );
		}

		$attachment_id = $this->find_image_by_number( $number );

		if ( ! $attachment_id ) {
			/* translators: %s: numéro recherché */
			return sprintf( __( 'Image à la une : aucune image « %s.jpg/png/gif/webp/avif » dans la médiathèque.', 'effi-simple-scrapper-importer' ), $number );
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return '';
	}

	/**
	 * Numéro en fin de slug : -c123, -n123, -123...
	 *
	 * @param string $slug Slug.
	 * @return string Numéro ou chaîne vide.
	 */
	public function extract_number_from_slug( $slug ) {
		if ( preg_match( '/-[a-z]?(\d{1,10})$/i', (string) $slug, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Cherche dans la médiathèque une image dont le nom de fichier est
	 * exactement « {numéro}.{jpg|jpeg|png|gif|webp|avif} » (ou sa version
	 * « -scaled » créée par WordPress pour les grandes images).
	 *
	 * @param string $number Numéro.
	 * @return int ID de la pièce jointe ou 0.
	 */
	public function find_image_by_number( $number ) {
		if ( ! preg_match( '/^\d{1,10}$/', (string) $number ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_mime_type'   => 'image',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_attached_file',
						'value'   => '(^|/)' . $number . '(-scaled)?\\.(jpe?g|png|gif|webp|avif)$',
						'compare' => 'REGEXP',
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}
}
