<?php
/**
 * Plugin Name: Robin image optimizer premium
 * Plugin URI: https://themeisle.com
 * Description: This is an extension for Robin Image Optimizer. Adds additional features: converting images to WebP, optimization of custom directories, and NextGen gallery optimization.
 * Author: Themeisle
 * Version: 1.0.8
 * Text Domain: robin-image-optimizer
 * Domain Path: /languages/ Author
 * URI: https://themeisle.com
 *
 * @package Robin_Image_Optimizer
 */

// Выход при непосредственном доступе

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wrio_premium_load' ) ) {

	/**
	 * Loads the premium features when the free plugin is active.
	 *
	 * @return void
	 */
	function wrio_premium_load() {

		if ( ! defined( 'WRIO_PLUGIN_ACTIVE' ) ) {
			return;
		}

		// Устанавливаем контстанту, что плагин уже используется
		define( 'WRIOP_PLUGIN_ACTIVE', true );

		// Директория плагина
		define( 'WRIOP_PLUGIN_DIR', __DIR__ );

		// Относительный путь к плагину
		define( 'WRIOP_PLUGIN_BASE', plugin_basename( __FILE__ ) );

		// Ссылка к директории плагина
		define( 'WRIOP_PLUGIN_URL', plugins_url( '', __FILE__ ) );

		// Global scripts
		// ---------------------------------------------------------
		require_once WRIOP_PLUGIN_DIR . '/includes/functions.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.backup.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.image-statistic-folders.php';

		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.folder.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/models/class.folders-extra-data.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.folder-image.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.custom-folders.php';

		if ( wrio_is_active_nextgen_gallery() ) {
			require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.gallery-nextgen.php';
			require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.image-nextgen.php';
			require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.image-statistic-nextgen.php';
		}

		// Utils
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/helpers/class.url.php'; // URL helper

		// WebP and AVIF delivery. The v2 optimization makes the files (WRIO\V2\Optimizer).
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-delivery.php';

		new WRIO\WEBP\HTML\Delivery();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.wpcli-optimize.php';
		}
		// Server detection and .htaccess rules: settings are saved over REST too.
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-server.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-delivery-test.php';
		// Removes the files of a test whose tab was closed before it finished.
		add_action( 'wrio_storage_cleanup', [ 'WRIO\\WEBP\\Delivery_Test', 'delete_stale' ] );
		add_action( 'wbcr/factory/pages/impressive/after_form_save', [ 'WRIO\\WEBP\\Server', 'sync_htaccess_rules_after_save' ], 10, 2 );
		// The AVIF rule depends on the license.
		add_action( 'wrio_license_activated', [ 'WRIO\\WEBP\\Server', 'sync_htaccess_rules' ], 20, 0 );
		add_action( 'wrio_license_deactivated', [ 'WRIO\\WEBP\\Server', 'sync_htaccess_rules' ], 20, 0 );
		// The rules a WP-CLI or cron process could not write, for example in the migrations of a WP-CLI update.
		add_action( 'init', [ 'WRIO\\WEBP\\Server', 'sync_pending_htaccess_rules' ] );

		// Answers the restore filter with the custom folder and NextGEN backups.
		// The app restores over REST, which runs outside wp-admin.
		require_once WRIOP_PLUGIN_DIR . '/admin/filters/backup.php';

		// Admin scripts
		// ---------------------------------------------------------
		if ( is_admin() ) {
			WRIO_Custom_Folders::get_instance();

			// The Robin column and actions on NextGEN's Manage Images, and the cleanup when NextGEN deletes a picture.
			if ( class_exists( 'WRIO_Nextgen_Gallery' ) ) {
				WRIO_Nextgen_Gallery::get_instance();
			}

			require_once WRIOP_PLUGIN_DIR . '/admin/ajax/folders.php';
			require_once WRIOP_PLUGIN_DIR . '/admin/boot.php';
		}
	}
}

/**
 * Function is performed when the parent plugin Robin image optimizer is activated.
 *
 * @return void
 * @since  1.0.4
 */
function wrio_premium_activate() {
	require_once __DIR__ . '/includes/classes/webp/class-webp-server.php';
	require_once __DIR__ . '/includes/classes/webp/class-webp-delivery.php';

	// The redirect rules, or the types of the converted files in the other delivery modes.
	WRIO\WEBP\Server::sync_htaccess_rules();
}

/**
 * Function is performed when the parent plugin Robin image optimizer is deactivated.
 *
 * @return void
 * @since  1.0.4
 */
function wrio_premium_deactivate() {
	require_once __DIR__ . '/includes/classes/webp/class-webp-server.php';

	$is_apache       = WRIO\WEBP\Server::is_apache();
	$is_use_htaccess = WRIO\WEBP\Server::server_use_htaccess();

	if ( $is_apache && $is_use_htaccess ) {
		WRIO\WEBP\Server::htaccess_clear_webp_rules();
	}
}
