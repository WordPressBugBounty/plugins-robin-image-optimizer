<?php
/**
 * Factory Bootstrap
 *
 * @since         1.0.0
 * @package       factory-bootstrap
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// module provides function only for the admin area
if ( ! is_admin() ) {
	return;
}

if ( defined( 'FACTORY_BOOTSTRAP_500_LOADED' ) ) {
	return;
}

define( 'FACTORY_BOOTSTRAP_500_VERSION', '5.0.2' );

define( 'FACTORY_BOOTSTRAP_500_LOADED', true );

if ( ! defined( 'FACTORY_FLAT_ADMIN' ) ) {
	define( 'FACTORY_FLAT_ADMIN', true );
}

define( 'FACTORY_BOOTSTRAP_500_DIR', __DIR__ );
define( 'FACTORY_BOOTSTRAP_500_URL', plugins_url( '', __FILE__ ) );

require_once FACTORY_BOOTSTRAP_500_DIR . '/includes/functions.php';

/**
 * @param Wbcr_Factory600_Plugin $plugin
 */
add_action(
	'wbcr_factory_bootstrap_500_plugin_created',
	function ( $plugin ) {
		$manager = new Wbcr_FactoryBootstrap500_Manager( $plugin );
		$plugin->setBootstap( $manager );
	}
);
