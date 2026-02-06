<?php

/**
 * Factory Processing
 *
 * Use https://github.com/deliciousbrains/wp-background-processing
 *
 * @since         1.0.0
 *
 * @package       factory-processing
 *
 * @version       1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'FACTORY_PROCESSING_759_LOADED' ) || ( defined( 'FACTORY_PROCESSING_STOP' ) && FACTORY_PROCESSING_STOP ) ) {
	return;
}

define( 'FACTORY_PROCESSING_759_LOADED', true );
define( 'FACTORY_PROCESSING_759_VERSION', '1.1.3' );

define( 'FACTORY_PROCESSING_759_DIR', __DIR__ );
define( 'FACTORY_PROCESSING_759_URL', plugins_url( '', __FILE__ ) );

// load_plugin_textdomain( 'wbcr_factory_processing_759', false, dirname( plugin_basename( __FILE__ ) ) . '/langs' );

require_once FACTORY_PROCESSING_759_DIR . '/includes/classes/wp-async-request.php';
require_once FACTORY_PROCESSING_759_DIR . '/includes/classes/wp-background-process.php';


/**
 * @param Wbcr_Factory600_Plugin $plugin
 */
add_action(
	'wbcr_factory_processing_759_plugin_created',
	function ( $plugin ) {
		/* @var Wbcr_Factory600_Plugin $plugin */

		/*
		Settings of Processing
		$settings = [
		'dir' => null,
		'file' => 'app.log',
		'flush_interval' => 1000,
		'rotate_size' => 5000000,
		'rotate_limit' => 3,
		];

		$plugin->set_logger( "WBCR\Factory_Processing_759\Processing", $settings );
		*/
	}
);
