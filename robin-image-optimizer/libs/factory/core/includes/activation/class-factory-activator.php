<?php
/**
 * The file contains a base class for plugin activators.
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
abstract class Wbcr_Factory600_Activator {

	/**
	 * Curent plugin.
	 *
	 * @var Wbcr_Factory600_Plugin
	 */
	public $plugin;

	public function __construct( Wbcr_Factory600_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function activate() {
	}

	public function deactivate() {
	}

	public function update() {
	}
}
