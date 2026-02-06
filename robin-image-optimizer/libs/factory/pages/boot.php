<?php
/**
 * Factory Pages
 *
 * @since         1.0.1
 * @package       core
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// module provides function only for the admin area
if ( ! is_admin() ) {
	return;
}

if ( defined( 'FACTORY_PAGES_600_LOADED' ) ) {
	return;
}

define( 'FACTORY_PAGES_600_LOADED', true );

define( 'FACTORY_PAGES_600_VERSION', '4.8.0' );

define( 'FACTORY_PAGES_600_DIR', __DIR__ );
define( 'FACTORY_PAGES_600_URL', plugins_url( '', __FILE__ ) );

if ( ! defined( 'FACTORY_FLAT_ADMIN' ) ) {
	define( 'FACTORY_FLAT_ADMIN', true );
}

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'robin-image-optimizer', false, dirname( plugin_basename( __FILE__ ) ) . '/langs' );
	}
);

require FACTORY_PAGES_600_DIR . '/pages.php';
require FACTORY_PAGES_600_DIR . '/includes/page.class.php';
require FACTORY_PAGES_600_DIR . '/includes/admin-page.class.php';
