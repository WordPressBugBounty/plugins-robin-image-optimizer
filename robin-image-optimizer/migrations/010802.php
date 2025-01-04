<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates for altering the table used to store statistics data.
 * Adds new columns and renames existing ones in order to add support for the new social buttons.
 *
 * @author  Alex Kovalev <alex.kovalevv@gmail.com> <Telegram:@alex_kovalevv>
 */
class WIOUpdate010802 extends Wbcr_Factory480_Update {

	/**
	 * Handles the installation process for the plugin, including cleanup of
	 * old options and setting new configurations.
	 *
	 * @return void
	 */
	public function install() {
		WRIO_Plugin::app()->deleteOption( 'image_optimize_all_usage' );
		WRIO_Plugin::app()->deleteOption( 'image_optimize_flush_usage' );

		WRIO_Plugin::app()->updatePopulateOption( 'image_optimization_server', 'server_2' );
		WRIO_Plugin::app()->updatePopulateOption( 'server_2_quota_limit', 300 );

		WRIO_Plugin::app()->logger->info( 'Plugin migration to 1.8.2 was successful!' );
	}
}
