<?php

namespace WBCR\Factory_Templates_759\Pages;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wbcr_FactoryLicense000_LicensePage is used as template to display form to active premium functionality.
 *
 * @since         2.0.7
 * @deprecated Should be removed in future versions.
 */
class License extends PageBase {

	/**
	 * {@inheritdoc}
	 *
	 * @since  2.1.2
	 * @var string
	 */
	public $type = 'page';

	/**
	 * {@inheritdoc}
	 *
	 * @since  2.1.2
	 * @var string
	 */
	public $page_menu_dashicon = 'dashicons-admin-network';

	/**
	 * {@inheritdoc}
	 *
	 * @since  2.1.2
	 * @var bool
	 */
	public $show_right_sidebar_in_options = false;

	/**
	 * {@inheritdoc}
	 *
	 * @since  2.1.2
	 * @var int
	 */
	public $page_menu_position = 0;

	/**
	 * {@inheritdoc}
	 *
	 * @since  2.1.2
	 * @var bool
	 */
	public $available_for_multisite = true;

	/**
	 * @since  2.1.2
	 * @var string
	 */
	public $plugin_name;

	/**
	 * @var string Name of the paid plan.
	 */
	public $plan_name;

	// PREMIUM SECTION
	// ------------------------------------------------------------------
	/**
	 * @since 2.0.7
	 * @var bool
	 */
	protected $is_premium;

	/**
	 * @since 2.0.7
	 * @var \WBCR\Factory_600\Premium\Provider
	 */
	protected $premium;

	/**
	 * @since 2.0.7
	 * @var bool
	 */
	protected $is_premium_active;

	/**
	 * @since 2.0.7
	 * @var bool
	 */
	protected $premium_has_subscription;

	/**
	 * @since 2.0.7
	 * @var \WBCR\Factory_600\Premium\Interfaces\License
	 */
	protected $premium_license;

	// END PREMIUM SECTION
	// ------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 *
	 * @param \Wbcr_Factory600_Plugin $plugin
	 */
	public function __construct( \Wbcr_Factory600_Plugin $plugin ) {
		$this->plugin = $plugin;

		parent::__construct( $plugin );

		if ( ! $this->id ) {
			$this->id = $this->plugin->getPrefix() . 'license';
		}

		$this->plugin_name              = $this->plugin->getPluginName();
		$this->premium                  = $plugin->premium;
		$this->is_premium               = $this->premium->is_activate();
		$this->is_premium_active        = $this->premium->is_active();
		$this->premium_has_subscription = $this->premium->has_paid_subscription();
		$this->premium_license          = $this->premium->get_license();
	}

	/**
	 * [MAGIC] Magic method that configures assets for a page.
	 */
	public function assets( $scripts, $styles ) {
		parent::assets( $scripts, $styles );

		$this->styles->add( FACTORY_TEMPLATES_459_URL . '/assets/css/license-manager.css' );
		$this->scripts->add( FACTORY_TEMPLATES_459_URL . '/assets/js/clearfy-license-manager.js' );
	}

	/**
	 * Регистрируем ajax обработчик для текущей страницы
	 *
	 * @since 2.0.7
	 */
	public function ajax_handler() {
	}

	/**
	 * {@inheritdoc}
	 *
	 * @deprecated No longer used. Should be removed in future versions.
	 */
	public function showPageContent() {
	}
}
