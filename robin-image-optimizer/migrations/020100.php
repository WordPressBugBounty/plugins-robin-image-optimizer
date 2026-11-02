<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the wrio/cron/* events of older versions that nothing handles now, such as the conversion_process and
 * avif_conversion_process events of 2.0 (#244).
 * Writes the .htaccess rules again: the Picture tag and Image URLs modes now get the types of the converted files
 * (#225).
 */
class WIOUpdate020100 {

	/**
	 * @return void
	 */
	public function install() {
		WRIO_Cron::clear_old_events();

		if ( class_exists( '\\WRIO\\WEBP\\Server' ) ) {
			\WRIO\WEBP\Server::sync_htaccess_rules();
		}
	}
}
