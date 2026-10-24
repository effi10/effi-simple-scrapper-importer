<?php
/**
 * Désinstallation.
 *
 * L'extension n'enregistre aucune option. La désinstallation supprime
 * uniquement :
 * - la méta technique « _effissi_source_url » posée sur les images importées
 *   dans la médiathèque (les images et les articles importés sont conservés) ;
 * - le dossier vide « uploads/effisimplyscrapper » créé à l'activation par les
 *   versions 1.0.x et jamais utilisé.
 *
 * @package EffiSimplyScrapperImporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Nettoie le site courant.
 */
function effissi_uninstall_site() {
	delete_post_meta_by_key( '_effissi_source_url' );

	$uploads = wp_upload_dir( null, false );

	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$legacy_dir = trailingslashit( $uploads['basedir'] ) . 'effisimplyscrapper';

	if ( is_dir( $legacy_dir ) ) {
		$entries = scandir( $legacy_dir );

		// Supprimé seulement s'il est vide.
		if ( is_array( $entries ) && 2 === count( $entries ) ) {
			rmdir( $legacy_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}

if ( is_multisite() ) {
	$effissi_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $effissi_site_ids as $effissi_site_id ) {
		switch_to_blog( (int) $effissi_site_id );
		effissi_uninstall_site();
		restore_current_blog();
	}
} else {
	effissi_uninstall_site();
}
