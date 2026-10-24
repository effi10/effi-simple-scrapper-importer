<?php
/**
 * Validation des fichiers Markdown téléversés.
 *
 * Le fichier n'est jamais déplacé dans un dossier public : il est lu depuis
 * le dossier temporaire de PHP, puis supprimé à la fin de la requête.
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFFISSI_Upload_Validator {

	/**
	 * Taille maximale d'un fichier Markdown, en octets.
	 *
	 * @return int
	 */
	public static function max_file_size() {
		/**
		 * Taille maximale d'un fichier .md (2 Mo par défaut, plafonnée par la configuration PHP).
		 *
		 * @param int $bytes Taille en octets.
		 */
		return (int) apply_filters( 'effissi_max_file_size', min( 2 * MB_IN_BYTES, wp_max_upload_size() ) );
	}

	/**
	 * Valide une entrée de $_FILES et retourne le chemin du fichier temporaire.
	 *
	 * Contrôles : erreur de téléversement, fichier réellement téléversé,
	 * extension .md, taille, type MIME réel de type texte.
	 *
	 * @param array $file Entrée de $_FILES.
	 * @return string Chemin du fichier temporaire.
	 * @throws Exception Si le fichier est refusé.
	 */
	public function validate( array $file ) {
		if ( ! isset( $file['tmp_name'], $file['name'], $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) ) {
			throw new Exception( __( 'Aucun fichier reçu.', 'effi-simple-scrapper-importer' ) );
		}

		$error = (int) $file['error'];

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new Exception( __( 'Fichier trop volumineux pour la configuration du serveur.', 'effi-simple-scrapper-importer' ) );
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			/* translators: %d: code d'erreur PHP */
			throw new Exception( sprintf( __( 'Erreur lors du téléversement (code %d).', 'effi-simple-scrapper-importer' ), $error ) );
		}

		$tmp = $file['tmp_name'];

		if ( '' === $tmp || ! $this->is_uploaded_file( $tmp ) ) {
			throw new Exception( __( 'Fichier téléversé introuvable.', 'effi-simple-scrapper-importer' ) );
		}

		$name = sanitize_file_name( $file['name'] );

		if ( 'md' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new Exception( __( 'Extension refusée : seuls les fichiers .md sont acceptés.', 'effi-simple-scrapper-importer' ) );
		}

		$size = (int) filesize( $tmp );

		if ( $size <= 0 ) {
			throw new Exception( __( 'Fichier vide.', 'effi-simple-scrapper-importer' ) );
		}

		if ( $size > self::max_file_size() ) {
			/* translators: %s: taille maximale */
			throw new Exception( sprintf( __( 'Fichier trop volumineux (maximum %s).', 'effi-simple-scrapper-importer' ), size_format( self::max_file_size() ) ) );
		}

		$mime = $this->detect_mime_type( $tmp );

		if ( '' !== $mime && 0 !== strpos( $mime, 'text/' ) ) {
			/* translators: %s: type MIME détecté */
			throw new Exception( sprintf( __( 'Type de fichier refusé (%s) : un fichier texte Markdown est attendu.', 'effi-simple-scrapper-importer' ), $mime ) );
		}

		return $tmp;
	}

	/**
	 * Le fichier provient-il bien d'un téléversement HTTP POST ?
	 *
	 * @param string $path Chemin.
	 * @return bool
	 */
	protected function is_uploaded_file( $path ) {
		return is_uploaded_file( $path );
	}

	/**
	 * Type MIME réel (extension fileinfo), ou chaîne vide si indisponible.
	 *
	 * @param string $path Chemin.
	 * @return string
	 */
	private function detect_mime_type( $path ) {
		if ( ! class_exists( 'finfo' ) ) {
			return '';
		}

		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->file( $path );

		return is_string( $mime ) ? strtolower( $mime ) : '';
	}
}
