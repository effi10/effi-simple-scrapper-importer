<?php
/**
 * Chargeur de la copie de Parsedown embarquée par l'extension.
 *
 * La bibliothèque est déclarée sous le nom préfixé EFFISSI_Parsedown : elle ne
 * peut donc pas entrer en conflit avec une autre extension qui charge la
 * classe globale « Parsedown », et l'extension utilise toujours la version
 * qu'elle a testée.
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFFISSI_Parsedown_Loader {

	/**
	 * Retourne une nouvelle instance de Parsedown configurée pour l'import.
	 *
	 * Le HTML brut éventuellement présent dans le Markdown est conservé ici :
	 * il est filtré plus loin par wp_kses_post() avant l'enregistrement.
	 *
	 * @return EFFISSI_Parsedown
	 * @throws Exception Si la bibliothèque est absente.
	 */
	public static function get_parsedown() {
		if ( ! class_exists( 'EFFISSI_Parsedown', false ) ) {
			$parsedown_file = EFFISSI_PLUGIN_DIR . 'vendor/parsedown/Parsedown.php';

			if ( ! is_readable( $parsedown_file ) ) {
				throw new Exception( __( 'Bibliothèque Parsedown introuvable (vendor/parsedown/Parsedown.php). Réinstallez l\'extension.', 'effi-simple-scrapper-importer' ) );
			}

			require_once $parsedown_file;
		}

		$parsedown = new EFFISSI_Parsedown();
		$parsedown->setBreaksEnabled( false );
		$parsedown->setMarkupEscaped( false );
		$parsedown->setUrlsLinked( true );

		return $parsedown;
	}
}
