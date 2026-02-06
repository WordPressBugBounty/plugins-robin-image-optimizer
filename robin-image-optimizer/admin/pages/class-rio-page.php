<?php

/**
 * The page Settings.
 *
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс отвечает за работу страницы настроек
 *
 * @version       1.0
 */
class WRIO_Page extends WBCR\Factory_Templates_759\Impressive {

	/**
	 * {@inheritdoc}
	 */
	public $page_parent_page = null;

	/**
	 * {@inheritdoc}
	 */
	public $available_for_multisite = false;

	/**
	 * {@inheritdoc}
	 */
	public $clearfy_collaboration = false;

	/**
	 * {@inheritdoc}
	 *
	 * @var bool
	 */
	public $show_right_sidebar_in_options = false;

	/**
	 * Hide bottom sidebar by default - only show on Settings page
	 *
	 * @var bool
	 */
	public $show_bottom_sidebar = false;


	/**
	 * @since  1.3.0
	 * @var WRIO_Views
	 */
	protected $view;

	/**
	 * @param WRIO_Plugin $plugin
	 */
	public function __construct( WRIO_Plugin $plugin ) {
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
	 * Подменяем простраинство имен для меню плагина, если активирован плагин
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
}
