<?php
/**
 * Plugin Name: Robin image optimizer
 * Plugin URI: https://robinoptimizer.com
 * Description: Optimize images without losing quality, speed up your website load, improve SEO and save money on server and CDN bandwidth.
 * Author: Themeisle <contact@themeisle.com>
 * Version: 2.1.0
 * Text Domain: robin-image-optimizer
 * Domain Path: /languages/
 * Author URI: https://themeisle.com
 * Requires at least: 6.6
 * WordPress Available:  yes
 * Requires License:    yes
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Two copies of the plugin (e.g. in different folders) must not both load.
if ( defined( 'WRIO_PLUGIN_ACTIVE' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Robin image optimizer is already active. Deactivate the other copy of the plugin.', 'robin-image-optimizer' ) . '</p></div>';
		}
	);

	return;
}

// iThemes Sync requests set WP_ADMIN; the plugin stays out of them.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'ithemes_sync_request' === $_REQUEST['action'] ) || ! empty( $_GET['ithemes-sync-request'] ) ) {
	return;
}

define( 'WRIO_PLUGIN_ACTIVE', true );
define( 'WIO_PLUGIN_ACTIVE', true );
define( 'WRIO_PLUGIN_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] );
define( 'WRIO_PLUGIN_DIR', __DIR__ );
define( 'WRIO_PLUGIN_BASE', plugin_basename( __FILE__ ) );
define( 'WRIO_PRODUCT_SLUG', basename( __DIR__ ) );
define( 'WRIO_PLUGIN_URL', plugins_url( '', __FILE__ ) );
define( 'WRIO_PLUGIN_FILE', __FILE__ );

require_once WRIO_PLUGIN_DIR . '/vendor/autoload.php';
require_once WRIO_PLUGIN_DIR . '/includes/class-rio-plugin.php';

new WRIO_Plugin();
