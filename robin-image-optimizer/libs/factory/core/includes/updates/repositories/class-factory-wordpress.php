<?php

namespace WBCR\Factory_600\Updates;

// Exit if accessed directly
use Wbcr_Factory600_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @version       1.0
 */
class Wordpress_Repository extends Repository {

	/**
	 * WordPress constructor.
	 *
	 * @param Wbcr_Factory600_Plugin $plugin
	 * @param array                  $settings
	 */
	public function __construct( Wbcr_Factory600_Plugin $plugin, array $settings = [] ) {
		$this->plugin = $plugin;
	}

	public function init() {
		// TODO: Implement init() method.
	}

	/**
	 * @return bool
	 */
	public function need_check_updates() {
		return false;
	}

	/**
	 * @return bool
	 */
	public function is_support_premium() {
		return false;
	}

	/**
	 * @return string
	 */
	public function get_download_url() {
		return '';
	}

	/**
	 * @return string
	 */
	public function get_last_version() {
		return '0.0.0';
	}

	public function check_updates() {
	}

	/**
	 * @return bool
	 */
	public function need_update() {
		return false;
	}
}
