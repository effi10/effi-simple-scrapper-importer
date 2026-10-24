<?php
/**
 * Import facultatif des images distantes dans la médiathèque.
 *
 * Garde-fous :
 * - URL http(s) uniquement, téléchargées avec wp_safe_remote_get() (adresses
 *   locales et privées refusées, redirections limitées) ;
 * - taille maximale contrôlée pendant le téléchargement ;
 * - type réel vérifié sur le contenu (JPEG, PNG, GIF, WebP, AVIF ; pas de SVG) ;
 * - fichier temporaire supprimé en cas d'échec ;
 * - nombre d'images limité par article ;
 * - une image déjà importée (même URL source) est réutilisée.
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFFISSI_Image_Importer {

	/** Méta posée sur les pièces jointes créées, pour éviter les doublons. */
	const SOURCE_META = '_effissi_source_url';

	/** Types acceptés => extension du fichier enregistré. */
	const ALLOWED_TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/** @var array<string, array|false> Résultats déjà calculés, par URL. */
	private $cache = array();

	/** @var int[] Pièces jointes créées pendant cet import. */
	private $created = array();

	/** @var string[] Avertissements à afficher. */
	private $warnings = array();

	/** @var int Nombre de téléchargements tentés. */
	private $downloads = 0;

	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Importe une image (appelée par le convertisseur pour chaque image).
	 *
	 * @param string $src URL de l'image.
	 * @param string $alt Texte alternatif.
	 * @return array{id: int, url: string}|null
	 */
	public function import( $src, $alt = '' ) {
		$src = (string) $src;

		if ( ! array_key_exists( $src, $this->cache ) ) {
			$result              = $this->import_uncached( $src, (string) $alt );
			$this->cache[ $src ] = $result ? $result : false;
		}

		return $this->cache[ $src ] ? $this->cache[ $src ] : null;
	}

	/**
	 * Rattache les images créées à l'article importé.
	 *
	 * @param int $post_id ID de l'article.
	 */
	public function attach_to_post( $post_id ) {
		foreach ( $this->created as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => (int) $post_id,
				)
			);
		}
	}

	/**
	 * Supprime les images créées (utilisé si la création de l'article échoue).
	 */
	public function delete_created() {
		foreach ( $this->created as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		$this->created = array();
	}

	/**
	 * @return string[]
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * @return int Nombre d'images effectivement ajoutées à la médiathèque.
	 */
	public function get_created_count() {
		return count( $this->created );
	}

	/**
	 * Taille maximale d'une image, en octets.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return (int) apply_filters( 'effissi_max_image_size', min( 5 * MB_IN_BYTES, wp_max_upload_size() ) );
	}

	/**
	 * Nombre maximal d'images téléchargées par article.
	 *
	 * @return int
	 */
	public static function max_images() {
		return max( 0, (int) apply_filters( 'effissi_max_images_per_post', 10 ) );
	}

	/**
	 * @param string $src URL.
	 * @param string $alt Texte alternatif.
	 * @return array{id: int, url: string}|null
	 */
	private function import_uncached( $src, $alt ) {
		$url = esc_url_raw( $src, array( 'http', 'https' ) );

		if ( '' === $url ) {
			/* translators: %s: adresse de l'image */
			$this->warn( __( 'Image ignorée : adresse non http(s) (%s).', 'effi-simple-scrapper-importer' ), $src );
			return null;
		}

		$existing = $this->find_existing( $url );

		if ( $existing ) {
			$existing_url = wp_get_attachment_url( $existing );

			if ( $existing_url ) {
				return array(
					'id'  => $existing,
					'url' => $existing_url,
				);
			}
		}

		if ( $this->downloads >= self::max_images() ) {
			/* translators: %s: URL de l'image */
			$this->warn( __( 'Image laissée sur le site source : limite d\'images par article atteinte (%s).', 'effi-simple-scrapper-importer' ), $url );
			return null;
		}

		++$this->downloads;

		$tmp = $this->download( $url );

		if ( '' === $tmp ) {
			return null;
		}

		$mime = wp_get_image_mime( $tmp );

		if ( ! $mime || ! isset( self::ALLOWED_TYPES[ $mime ] ) ) {
			wp_delete_file( $tmp );
			/* translators: %s: URL de l'image */
			$this->warn( __( 'Image ignorée : type non autorisé (%s).', 'effi-simple-scrapper-importer' ), $url );
			return null;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( wp_basename( is_string( $path ) ? $path : '' ) );
		$name = (string) preg_replace( '/\.[^.]*$/', '', $name );
		$name = '' !== $name ? $name : 'image';

		$file = array(
			'name'     => $name . '.' . self::ALLOWED_TYPES[ $mime ],
			'tmp_name' => $tmp,
		);

		$alt           = sanitize_text_field( $alt );
		$attachment_id = media_handle_sideload( $file, 0, '' !== $alt ? $alt : null );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			/* translators: 1: URL de l'image, 2: message d'erreur */
			$this->warn( __( 'Image non importée (%1$s) : %2$s', 'effi-simple-scrapper-importer' ), $url, $attachment_id->get_error_message() );
			return null;
		}

		$attachment_id   = (int) $attachment_id;
		$this->created[] = $attachment_id;

		update_post_meta( $attachment_id, self::SOURCE_META, $url );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );

		if ( ! $attachment_url ) {
			return null;
		}

		return array(
			'id'  => $attachment_id,
			'url' => $attachment_url,
		);
	}

	/**
	 * Télécharge une image dans un fichier temporaire, avec limite de taille.
	 *
	 * @param string $url URL.
	 * @return string Chemin du fichier temporaire, ou chaîne vide en cas d'échec.
	 */
	private function download( $url ) {
		$max = self::max_bytes();
		$tmp = wp_tempnam( $url );

		if ( ! $tmp ) {
			/* translators: %s: URL de l'image */
			$this->warn( __( 'Image non importée : impossible de créer un fichier temporaire (%s).', 'effi-simple-scrapper-importer' ), $url );
			return '';
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			/* translators: 1: URL de l'image, 2: message d'erreur */
			$this->warn( __( 'Image non importée (%1$s) : %2$s', 'effi-simple-scrapper-importer' ), $url, $response->get_error_message() );
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			/* translators: 1: URL, 2: code HTTP */
			$this->warn( __( 'Image non importée (%1$s) : réponse HTTP %2$d.', 'effi-simple-scrapper-importer' ), $url, $code );
			return '';
		}

		clearstatcache( true, $tmp );
		$size = (int) filesize( $tmp );

		if ( $size <= 0 || $size > $max ) {
			wp_delete_file( $tmp );
			/* translators: 1: URL, 2: taille maximale */
			$this->warn( __( 'Image non importée (%1$s) : fichier vide ou plus lourd que %2$s.', 'effi-simple-scrapper-importer' ), $url, size_format( $max ) );
			return '';
		}

		return $tmp;
	}

	/**
	 * Cherche une image déjà importée depuis la même URL.
	 *
	 * @param string $url URL source.
	 * @return int ID de la pièce jointe ou 0.
	 */
	private function find_existing( $url ) {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Ajoute un avertissement (format sprintf).
	 *
	 * @param string $format  Format.
	 * @param mixed  ...$args Arguments.
	 */
	private function warn( $format, ...$args ) {
		$this->warnings[] = vsprintf( $format, $args );
	}
}
