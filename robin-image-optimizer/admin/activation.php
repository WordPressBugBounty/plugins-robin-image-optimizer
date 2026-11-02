<?php

/**
 * Activator for the Robin image optimizer
 *
 * @version       1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation and deactivation.
 */
class WIO_Activation {

	/**
	 * Runs activation actions.
	 *
	 * @since 1.0.0
	 * @throws \Exception
	 */
	public function activate() {
		WRIO_Plugin::app()->logger->info( 'Parent plugin start installation!' );

		// Set defaults for new installations only. A site activated by WP-CLI before 2.1 has no version but can have
		// saved settings: they stay.
		if ( 0 === $this->get_plugin_version_in_db() ) {
			foreach ( [ 'backup_origin_images', 'save_exif_data', 'auto_optimize_when_upload', 'convert_webp_format' ] as $option ) {
				if ( null === WRIO_Plugin::app()->getPopulateOption( $option, null ) ) {
					WRIO_Plugin::app()->updatePopulateOption( $option, 1 );
				}
			}
		}

		// The deactivation removed the .htaccess rules, with or without a license: write them again for the delivery
		// settings.
		require_once WRIO_PLUGIN_DIR . '/libs/addons/robin-image-optimizer-premium.php';
		wrio_premium_activate();

		// Images queued in the Media Library before a deactivation; images saved while the plugin was off have no size
		// for "Largest first" yet.
		WRIO_Media_Bulk_Action::activate();
		WRIO_Image_Query::restart_filesize_backfill();

		$db_version             = RIO_Process_Queue::get_db_version();
		$plugin_version_in_db   = $this->get_plugin_version_in_db();
		$current_plugin_version = WRIO_PLUGIN_VERSION;

		$create_table_log_message  = "Plugin installation: try create plugin tables.\r\n";
		$create_table_log_message .= "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t-DB Version: {$db_version}\r\n";
		$create_table_log_message .= "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t-Plugin Version in DB: {$plugin_version_in_db}\r\n";
		$create_table_log_message .= "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t-Current Plugin Version: {$current_plugin_version}";

		WRIO_Plugin::app()->logger->info( $create_table_log_message );

		RIO_Process_Queue::try_create_plugin_tables();

		// An older version can have run since the last copy (a rollback): copy its rows too.
		if ( class_exists( '\WRIO\Storage\Backfill' ) ) {
			\WRIO\Storage\Backfill::restart();
		}

		wrio_flush_page_cache();

		WRIO_Plugin::app()->logger->info( 'Parent plugin installation complete!' );
	}

	/**
	 * Get previous plugin version
	 *
	 * @since  1.3.8
	 * @return number
	 */
	public function get_plugin_version_in_db() {
		if ( WRIO_Plugin::app()->isNetworkActive() ) {
			return get_site_option( WRIO_Plugin::app()->getOptionName( 'plugin_version' ), 0 );
		}

		return get_option( WRIO_Plugin::app()->getOptionName( 'plugin_version' ), 0 );
	}

	/**
	 * Runs activation actions.
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		WRIO_Plugin::app()->logger->info( 'Parent plugin start deactivation!' );

		if ( class_exists( 'WRIO_Cron' ) ) {
			WRIO_Cron::stop();
		}

		wp_clear_scheduled_hook( \WRIO\Storage\Backfill::HOOK );
		wp_clear_scheduled_hook( \WRIO\Storage\Events::CLEANUP_HOOK );
		wp_clear_scheduled_hook( WRIO_Media_Bulk_Action::CRON_HOOK );
		wp_clear_scheduled_hook( WRIO_Image_Query::FILESIZE_BACKFILL_HOOK );
		wp_clear_scheduled_hook( 'wrio/cron/background_test' );
		wp_clear_scheduled_hook( 'wrio/cron/probe' );

		// Free WebP delivery writes the .htaccess rules too: remove them with or without a license.
		require_once WRIO_PLUGIN_DIR . '/libs/addons/robin-image-optimizer-premium.php';
		wrio_premium_deactivate();

		WRIO_Plugin::app()->logger->info( 'Parent plugin deactivation complete!' );
	}
}
