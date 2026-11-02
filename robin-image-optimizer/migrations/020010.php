<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clears the Freemius license autosync event. Nothing handles it since the Factory premium module was removed.
 * Writes the deny files into the backup and log folders that exist already.
 * Writes the redirect rules again, so they send Vary: Accept for every image response.
 */
class WIOUpdate020010 {

	/**
	 * @return void
	 */
	public function install() {
		wp_clear_scheduled_hook( 'wbcr_image_optimizer_license_autosync' );

		$backup_dir = trailingslashit( wp_upload_dir()['basedir'] ) . WIO_Backup::BACKUP_DIR_NAME;
		wrio_protect_dir( $backup_dir );
		wrio_protect_dir( apply_filters( 'wbcr/rio/backup/backup_dir', trailingslashit( $backup_dir ) ) );
		WRIO_Plugin::app()->logger->get_base_dir();

		if ( class_exists( '\\WRIO\\WEBP\\Server' ) ) {
			\WRIO\WEBP\Server::sync_htaccess_rules();
		}
	}
}
