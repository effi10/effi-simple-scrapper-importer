<?php
/**
 * Lecture d'un fichier Markdown SimplyScrapper : encodage, titre, URL source,
 * slug et corps Markdown.
 *
 * Format attendu :
 *   ligne 1 : # [Titre de l'article](https://example.com/blog/slug-article)
 *   ligne 2 : _https://example.com/blog/slug-article_
 *   ligne 3 et suivantes : contenu Markdown.
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFFISSI_Markdown_Parser {

	/**
	 * Lit et analyse un fichier.
	 *
	 * @param string $file_path Chemin du fichier.
	 * @return array{title: string, slug: string, source_url: string, markdown: string}
	 * @throws Exception Si le fichier est illisible ou mal formé.
	 */
	public function parse_file( $file_path ) {
		$file_path = (string) $file_path;

		if ( '' === $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Exception( __( 'Fichier introuvable ou illisible.', 'effi-simple-scrapper-importer' ) );
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local temporaire.

		if ( false === $content ) {
			throw new Exception( __( 'Impossible de lire le fichier.', 'effi-simple-scrapper-importer' ) );
		}

		return $this->parse_content( $content );
	}

	/**
	 * Analyse le contenu brut d'un fichier.
	 *
	 * @param string $content Contenu brut.
	 * @return array{title: string, slug: string, source_url: string, markdown: string}
	 * @throws Exception Si le contenu est mal formé.
	 */
	public function parse_content( $content ) {
		$content = $this->normalize_encoding( (string) $content );
		$lines   = explode( "\n", $content );

		// Ignore les lignes vides éventuelles en tête de fichier.
		while ( $lines && '' === trim( $lines[0] ) ) {
			array_shift( $lines );
		}

		if ( count( $lines ) < 2 ) {
			throw new Exception( __( 'Format de fichier invalide : il faut au moins une ligne de titre et un contenu.', 'effi-simple-scrapper-importer' ) );
		}

		$title_line = trim( (string) array_shift( $lines ) );
		$title      = $this->extract_title( $title_line );

		if ( '' === $title ) {
			throw new Exception( __( 'Titre introuvable sur la première ligne.', 'effi-simple-scrapper-importer' ) );
		}

		// Ligne 2 : URL de la page source. Si elle est absente, on se rabat sur
		// le lien du titre et la ligne 2 est considérée comme du contenu.
		$source_url = $this->extract_url( isset( $lines[0] ) ? $lines[0] : '' );

		if ( '' !== $source_url ) {
			array_shift( $lines );
		} else {
			$source_url = $this->extract_title_url( $title_line );
		}

		$slug = '' !== $source_url ? $this->slug_from_url( $source_url ) : '';

		if ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}

		$markdown = trim( implode( "\n", $lines ) );

		if ( '' === $markdown ) {
			throw new Exception( __( 'Le fichier ne contient aucun contenu après le titre.', 'effi-simple-scrapper-importer' ) );
		}

		return array(
			'title'      => $title,
			'slug'       => $slug,
			'source_url' => $source_url,
			'markdown'   => $markdown,
		);
	}

	/**
	 * Normalise l'encodage (BOM UTF-8, UTF-16 avec BOM) et les fins de ligne.
	 *
	 * @param string $content Contenu brut.
	 * @return string Contenu UTF-8 avec des fins de ligne "\n".
	 * @throws Exception Si l'encodage n'est pas pris en charge.
	 */
	public function normalize_encoding( $content ) {
		$content = (string) $content;

		if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
			$content = substr( $content, 3 );
		} elseif ( 0 === strncmp( $content, "\xFF\xFE", 2 ) ) {
			$content = $this->convert_to_utf8( substr( $content, 2 ), 'UTF-16LE' );
		} elseif ( 0 === strncmp( $content, "\xFE\xFF", 2 ) ) {
			$content = $this->convert_to_utf8( substr( $content, 2 ), 'UTF-16BE' );
		}

		if ( false !== strpos( $content, "\0" ) ) {
			throw new Exception( __( 'Fichier binaire ou encodage non pris en charge (UTF-16 sans BOM ?). Enregistrez le fichier en UTF-8.', 'effi-simple-scrapper-importer' ) );
		}

		if ( 1 !== preg_match( '//u', $content ) ) {
			throw new Exception( __( 'Le fichier n\'est pas encodé en UTF-8. Enregistrez-le en UTF-8 puis réessayez.', 'effi-simple-scrapper-importer' ) );
		}

		return str_replace( array( "\r\n", "\r" ), "\n", $content );
	}

	/**
	 * Calcule le slug à partir du dernier segment de chemin de l'URL source.
	 *
	 * Le slug d'origine est conservé tel quel (seulement passé par
	 * sanitize_title()). Une extension .html, .htm, .php, .asp ou .aspx est
	 * retirée, la chaîne de requête et l'ancre sont ignorées.
	 *
	 * @param string $url URL source.
	 * @return string Slug, ou chaîne vide si l'URL n'a pas de chemin exploitable.
	 */
	public function slug_from_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$segments = array_values(
			array_filter(
				explode( '/', $path ),
				static function ( $segment ) {
					return '' !== $segment;
				}
			)
		);

		if ( ! $segments ) {
			return '';
		}

		$last = (string) end( $segments );
		$last = (string) preg_replace( '/\.(?:html?|php|aspx?)$/i', '', $last );

		return sanitize_title( $last );
	}

	/**
	 * Extrait le titre de la première ligne (« # [Titre](url) » ou « # Titre »).
	 *
	 * @param string $line Première ligne.
	 * @return string Titre en texte brut.
	 */
	private function extract_title( $line ) {
		$line = trim( (string) $line );

		if ( preg_match( '/^#{1,6}\s*\[(.*)\]\(\s*<?[^)\s]*>?\s*\)\s*$/u', $line, $matches ) ) {
			$title = $matches[1];
		} elseif ( preg_match( '/^#{1,6}\s*(.+?)\s*#*\s*$/u', $line, $matches ) ) {
			$title = $matches[1];
		} else {
			$title = $line;
		}

		// Retire les échappements Markdown (\* \_ \[ ...) et toute balise HTML.
		$title = (string) preg_replace( '/\\\\([\\\\`*_{}\[\]()#+\-.!|])/', '$1', $title );
		$title = wp_strip_all_tags( $title );
		$title = (string) preg_replace( '/\s+/u', ' ', $title );

		return trim( $title );
	}

	/**
	 * Extrait l'URL du lien de la première ligne, le cas échéant.
	 *
	 * @param string $line Première ligne.
	 * @return string URL ou chaîne vide.
	 */
	private function extract_title_url( $line ) {
		if ( preg_match( '/\]\(\s*<?(https?:\/\/[^)\s>]+)>?\s*\)\s*$/i', (string) $line, $matches ) ) {
			return $this->extract_url( $matches[1] );
		}

		return '';
	}

	/**
	 * Extrait une URL http(s) d'une ligne du type « _https://example.com/a_ ».
	 *
	 * @param string $line Ligne à analyser.
	 * @return string URL nettoyée, ou chaîne vide si la ligne n'est pas une URL.
	 */
	private function extract_url( $line ) {
		$line = trim( (string) $line, " \t_*<>" );

		if ( ! preg_match( '/^https?:\/\/\S+$/i', $line ) ) {
			return '';
		}

		return esc_url_raw( $line, array( 'http', 'https' ) );
	}

	/**
	 * Convertit une chaîne UTF-16 en UTF-8.
	 *
	 * @param string $content  Contenu.
	 * @param string $encoding Encodage source.
	 * @return string
	 * @throws Exception Si aucune extension de conversion n'est disponible.
	 */
	private function convert_to_utf8( $content, $encoding ) {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $content, 'UTF-8', $encoding );
		}

		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( $encoding, 'UTF-8//IGNORE', $content );

			if ( false !== $converted ) {
				return $converted;
			}
		}

		throw new Exception( __( 'Fichier UTF-16 : conversion impossible sur ce serveur (extensions mbstring et iconv absentes). Enregistrez le fichier en UTF-8.', 'effi-simple-scrapper-importer' ) );
	}
}
