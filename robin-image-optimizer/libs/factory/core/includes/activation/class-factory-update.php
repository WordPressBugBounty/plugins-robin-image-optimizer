<?php
/**
 * The file contains a base class for update items of plugins.
 *
 * @package       factory-core
 * @since         1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Plugin Activator
 *
 * @since 1.0.0
 */
abstract class Wbcr_Factory600_Update {

	/**
	 * Current plugin
	 *
	 * @var Wbcr_Factory600_Plugin
	 */
	var $plugin;

	public function __construct( Wbcr_Factory600_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	abstract function install();

	// abstract function rollback();
}
