<?php
	/**
	 * A group of classes and methods to create and manage pages.
	 *
	 * @package core
	 * @since 1.0.0
	 */

	// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
	add_action( 'admin_menu', 'Wbcr_FactoryPages600::actionAdminMenu', 9 );
	add_action( 'network_admin_menu', 'Wbcr_FactoryPages600::actionAdminMenu', 9 );

if ( ! class_exists( 'Wbcr_FactoryPages600' ) ) {
	/**
	 * A base class to manage pages.
	 *
	 * @since 1.0.0
	 */
	class Wbcr_FactoryPages600 {

		/**
		 * @var Wbcr_FactoryPages600_Page[]
		 */
		private static $pages = [];

		/**
		 * @param Wbcr_Factory600_Plugin $plugin
		 * @param $class_name
		 */
		public static function register( $plugin, $class_name ) {
			if ( ! isset( self::$pages[ $plugin->getPluginName() ] ) ) {
				self::$pages[ $plugin->getPluginName() ] = [];
			}
			$page = new $class_name( $plugin );
			if ( is_multisite() && is_network_admin() && ! $page->available_for_multisite ) {
				return;
			}
			self::$pages[ $plugin->getPluginName() ][] = $page;
		}

		public static function actionAdminMenu() {
			if ( empty( self::$pages ) ) {
				return;
			}

			foreach ( self::$pages as $plugin_pages ) {
				foreach ( $plugin_pages as $page ) {
					$page->connect();
				}
			}
		}

		public static function getPageUrl( Wbcr_Factory600_Plugin $plugin, $page_id, $args = [] ) {
			if ( isset( self::$pages[ $plugin->getPluginName() ] ) ) {
				$pages = self::$pages[ $plugin->getPluginName() ];

				foreach ( $pages as $page ) {
					if ( $page->id == $page_id ) {
						return $page->getBaseUrl( $page_id, $args );
					}
				}
			} else {
				_doing_it_wrong( __METHOD__, __( 'You are trying to call this earlier than the plugin menu will be registered.', 'robin-image-optimizer' ), '4.0.8' );
			}
		}

		/**
		 * @param Wbcr_Factory600_Plugin $plugin
		 * @return array
		 */
		public static function getIds( $plugin ) {
			if ( ! isset( self::$pages[ $plugin->getPluginName() ] ) ) {
				return [];
			}

			$result = [];
			foreach ( self::$pages[ $plugin->getPluginName() ] as $page ) {
				$result[] = $page->getResultId();
			}

			return $result;
		}
	}
}

if ( ! function_exists( 'wbcr_factory_pages_600_get_page_id' ) ) {
	/**
	 *
	 * @param Wbcr_Factory600_Plugin $plugin
	 * @param string                 $page_id
	 * @return string
	 */
	function wbcr_factory_pages_600_get_page_id( $plugin, $page_id ) {
		return $page_id . '-' . $plugin->getPluginName();
	}
}
