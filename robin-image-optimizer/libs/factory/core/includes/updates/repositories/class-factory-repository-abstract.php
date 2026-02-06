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
abstract class Repository {

	/**
	 * @var bool
	 */
	protected $initialized = false;

	/**
	 * @var Wbcr_Factory600_Plugin
	 */
	protected $plugin;

	/**
	 * Repository constructor.
	 *
	 * @param Wbcr_Factory600_Plugin $plugin
	 * @param array                  $settings
	 */
	abstract public function __construct( Wbcr_Factory600_Plugin $plugin, array $settings = [] );

	/**
	 * @return void
	 */
	abstract public function init();

	/**
	 * @return bool
	 */
	abstract public function need_check_updates();

	/**
	 * @return mixed
	 */
	abstract public function is_support_premium();

	/**
	 * @return string
	 */
	abstract public function get_download_url();

	/**
	 * @return string
	 */
	abstract public function get_last_version();
}
