<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс отвечает за работу страницы логов.
 *
 * @version       1.0
 */
class WRIO_LogPage extends Wbcr_FactoryLogger359_PageBase {

	/**
	 * {@inheritdoc}
	 */
	public $id = 'rio_logs'; // Уникальный идентификатор страницы

	/**
	 * Hide bottom sidebar - only show on Settings page
	 *
	 * @var bool
	 */
	public $show_bottom_sidebar = false;

	/**
	 * {@inheritdoc}
	 *
	 * @var string
	 */
	public $page_parent_page = 'rio_general';

	/**
	 * {@inheritdoc}
	 */
	public $available_for_multisite = false;

	/**
	 * {@inheritdoc}
	 */
	public $clearfy_collaboration = false;

	/**
	 *
	 * Whether to show the right sidebar in options.
	 *
	 * @var bool
	 */
	public $show_right_sidebar_in_options = true;

	/**
	 * Menu target for WordPress admin submenu.
	 *
	 * @var string
	 */
	public $menu_target = 'rio_general-robin-image-optimizer';

	/**
	 * Use admin.php as base URL instead of menu_target.
	 *
	 * @var bool
	 */
	public $custom_target = true;

	/**
	 * The page is internal and should not be displayed in the menu.
	 *
	 * @var bool
	 */
	public $internal = false;

	/**
	 * View instance for rendering templates.
	 *
	 * @since  1.3.0
	 * @var WRIO_Views
	 */
	protected $view;

	/**
	 * Подменяем пространство имен для меню плагина, если активирован плагин
	 * Меню текущего плагина будет добавлено в общее меню
	 *
	 * @return string
	 */
	public function getMenuScope() {
		if ( $this->clearfy_collaboration ) {
			$this->page_parent_page = 'rio_general';

			return 'wbcr_clearfy';
		}

		return 'robin-image-optimizer';
	}

	/**
	 * @param WRIO_Plugin $plugin
	 */
	public function __construct( WRIO_Plugin $plugin ) {
		$this->menu_title                  = __( 'Error Log', 'robin-image-optimizer' );
		$this->page_menu_short_description = __( 'Plugin debug report', 'robin-image-optimizer' );

		$this->view = WRIO_Views::get_instance( WRIO_PLUGIN_DIR );
		if ( is_multisite() && defined( 'WCL_PLUGIN_ACTIVE' ) ) {
			if ( WRIO_Plugin::app()->isNetworkActive() && WCL_Plugin::app()->isNetworkActive() ) {
				$this->clearfy_collaboration = true;
			}
		} elseif ( defined( 'WCL_PLUGIN_ACTIVE' ) ) {
			$this->clearfy_collaboration = true;
		}

		parent::__construct( $plugin );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function assets( $scripts, $styles ) {
		parent::assets( $scripts, $styles );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMenuTitle() {
		return defined( 'LOADING_ROBIN_IMAGE_OPTIMIZER_AS_ADDON' ) ? __( 'Image optimizer', 'robin-image-optimizer' ) : __( 'Error Log', 'robin-image-optimizer' );
	}
}
