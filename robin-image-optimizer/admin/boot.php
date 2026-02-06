<?php
/**
 * Admin boot
 *
 * @version   1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Проверяем таблицу в базе данных
 *
 * Если таблица не существует или её структура устарела, то обновляем.
 * Проверка проводится при каждой инициализации плагина т.к. структура может измениться
 * после очередного обновления плагина.
 *
 * @return bool
 */
add_action(
	'admin_init',
	function () {
		RIO_Process_Queue::try_create_plugin_tables();
	}
);

/**
 *
 * @since  1.3.0
 */
add_filter(
	'wbcr/clearfy/components/items_list',
	function ( $components ) {
		if ( wrio_is_clearfy_license_activate() ) {
			return $components;
		}
		if ( ! empty( $components ) ) {
			foreach ( $components as $key => $component ) {
				if ( 'robin_image_optimizer' == $component['name'] ) {
					unset( $components[ $key ] );
				}
			}
		}

		return $components;
	}
);

/**
 * Добавляет карточку компонента на страницу компонентов
 *
 * @since  1.3.0
 */
add_action(
	'wbcr/clearfy/components/custom_plugins_card',
	function () {
		if ( ! wrio_is_clearfy_license_activate() ) {
			$view = WRIO_Views::get_instance( WRIO_PLUGIN_DIR );
			$view->print_template( 'clearfy-component-card' );
		}
	}
);

/**
 * We asset migration scripts to all admin panel pages
 *
 * @since  1.3.0
 */
add_action(
	'admin_enqueue_scripts',
	function () {
		if ( ! current_user_can( 'update_plugins' ) || ! wbcr_rio_has_meta_to_migrate() ) {
			return;
		}

		wp_enqueue_script(
			'wrio-meta-migrations',
			WRIO_PLUGIN_URL . '/admin/assets/js/meta-migrations.js',
			[
				'jquery',
				'wbcr-factory-clearfy-000-global',
			],
			WRIO_Plugin::app()->getPluginVersion()
		);
	}
);

/**
 * Plugin was heavy migrated into new architecture. Specifically, post meta was moved to separate table and
 * therefore it is required to migrate all of them to new table.
 *
 * This action prints a notice, which contains clickable link with JS onclick event, which invokes AJAX request
 * to migrate these post metas to new table.
 *
 * Once all post meta migrated, notice would not be shown anymore.
 *
 * @param $notices
 *
 * @return array
 * @since  1.3.0
 *
 * @see    wbcr_rio_migrate_postmeta_to_process_queue() for further information about AJAX processing function.
 * @see    wbcr_rio_has_meta_to_migrate() used to check whether to show notice or not.
 *
 * @see    RIO_Process_Queue for further information about new table.
 */
add_action(
	'wbcr/factory/admin_notices',
	function ( $notices ) {

		if ( ! current_user_can( 'update_plugins' ) || ! wbcr_rio_has_meta_to_migrate() ) {
			return $notices;
		}

		$notices[] = [
			'id'              => WRIO_Plugin::app()->getPrefix() . 'meta_to_migration',
			'type'            => 'warning',
			'dismissible'     => false,
			'dismiss_expires' => 0,
			'text'            => '<p><b>' . WRIO_Plugin::app()->getPluginTitle() . ':</b> ' . wrio_get_meta_migration_notice_text() . '</p>',
		];

		return $notices;
	}
);

/**
 * Plugin was heavy migrated into new architecture. Specifically, post meta was moved to separate table and
 * therefore it is required to migrate all of them to new table.
 *
 * This action prints a notice, which contains clickable link with JS onclick event, which invokes AJAX request
 * to migrate these post metas to new table.
 *
 * Once all post meta migrated, notice would not be shown anymore.
 *
 * @param Wbcr_Factory480_Plugin $plugin
 * @param Wbcr_FactoryPages480_ImpressiveThemplate $obj
 *
 * @since  1.3.0
 *
 * @see    wbcr_rio_migrate_postmeta_to_process_queue() for further information about AJAX processing function.
 * @see    wbcr_rio_has_meta_to_migrate() used to check whether to show notice or not.
 *
 * @see    RIO_Process_Queue for further information about new table.
 */
add_action(
	'wbcr/factory/pages/impressive/print_all_notices',
	function ( $plugin, $obj ) {
		if ( ( $plugin->getPluginName() != WRIO_Plugin::app()->getPluginName() ) || ! wbcr_rio_has_meta_to_migrate() ) {
			return;
		}

		$obj->printWarningNotice( wrio_get_meta_migration_notice_text() );
	},
	10,
	2
);

/***
 * Flush configuration after saving the settings
 *
 * @param WRIO_Plugin $plugin
 * @param Wbcr_FactoryPages480_ImpressiveThemplate $obj
 *
 * @return bool
 */
/*
add_action('wbcr_factory_480_imppage_after_form_save', function ($plugin, $obj) {
	$is_rio = WRIO_Plugin::app()->getPluginName() == $plugin->getPluginName();

	if( $is_rio ) {
		WRIO_Cron::check();
	}
}, 10, 2);*/

/**
 * Виджет отзывов
 *
 * @param string $page_url
 * @param string $plugin_name
 *
 * @return string
 */
function wio_rating_widget_url( $page_url, $plugin_name ) {
	if ( $plugin_name == WRIO_Plugin::app()->getPluginName() ) {
		return 'https://wordpress.org/support/plugin/robin-image-optimizer/reviews/#new-post';
	}

	return $page_url;
}

add_filter( 'wbcr_factory_pages_480_imppage_rating_widget_url', 'wio_rating_widget_url', 10, 2 );

/**
 *
 * @param array $widgets
 * @param string $position
 * @param Wbcr_Factory480_Plugin $plugin
 */
add_filter(
	'wbcr/factory/pages/impressive/widgets',
	function ( $widgets, $position, $plugin ) {
		if ( $plugin->getPluginName() == WRIO_Plugin::app()->getPluginName() ) {
			require_once WRIO_PLUGIN_DIR . '/admin/includes/sidebar-widgets.php';

			if ( wrio_is_license_activate() ) {
				unset( $widgets['donate_widget'] );

				if ( $position == 'right' ) {
					unset( $widgets['adverts_widget'] );
					unset( $widgets['business_suggetion'] );
					unset( $widgets['rating_widget'] );
					unset( $widgets['info_widget'] );
				}

				/*
				if ( $position == 'bottom' ) {
				$widgets['support'] = wrio_get_sidebar_support_widget();
				}*/

				return $widgets;
			} elseif ( $position == 'right' ) {
				unset( $widgets['info_widget'] );
				unset( $widgets['rating_widget'] );
				// $widgets['support'] = wrio_get_sidebar_support_widget();

			}
		}

		return $widgets;
	},
	20,
	3
);

/**
 * Заменяет заголовок в рекламном виджете
 *
 * @param array $features
 * @param string $plugin_name
 * @param string $page_id
 */
add_filter(
	'wbcr/clearfy/pages/suggetion_title',
	function ( $features, $plugin_name, $page_id ) {
		if ( ! empty( $plugin_name ) && ( $plugin_name == WRIO_Plugin::app()->getPluginName() ) ) {
			return __( 'ROBIN IMAGE OPTIMIZER PRO', 'robin-image-optimizer' );
		}

		return $features;
	},
	20,
	3
);

/**
 * Заменяем премиум возможности в рекламном виджете
 *
 * @param array $features
 * @param string $plugin_name
 * @param string $page_id
 */
add_filter(
	'wbcr/clearfy/pages/suggetion_features',
	function ( $features, $plugin_name, $page_id ) {
		if ( ! empty( $plugin_name ) && ( $plugin_name == WRIO_Plugin::app()->getPluginName() ) ) {
			$upgrade_feature   = [];
			$upgrade_feature[] = __( 'Automatic conversion to WebP', 'robin-image-optimizer' );
			$upgrade_feature[] = __( 'You can optimize custom folders', 'robin-image-optimizer' );
			$upgrade_feature[] = __( 'Supports NextGen gallery', 'robin-image-optimizer' );
			$upgrade_feature[] = __( 'Multisite support', 'robin-image-optimizer' );
			$upgrade_feature[] = __( 'Fast optimization servers', 'robin-image-optimizer' );
			$upgrade_feature[] = __( 'Ad-free experience', 'robin-image-optimizer' );
			$upgrade_feature[] = __( 'Priority support', 'robin-image-optimizer' );

			return $upgrade_feature;
		}

		return $features;
	},
	20,
	3
);

/**
 * Заменяем премиум возможности в рекламном виджете
 *
 * @param array $messages
 * @param string $type
 * @param string $plugin_name
 */
add_filter(
	'wbcr/factory/premium/notice_text',
	function ( $text, $type, $plugin_name ) {
		if ( WRIO_Plugin::app()->getPluginName() != $plugin_name ) {
			return $text;
		}

		$license_page_url = WRIO_Plugin::app()->getPluginPageUrl( 'rio_license' );

		if ( null === $license_page_url ) {
			return $text;
		}

		if ( 'need_activate_license' == $type ) {
			return sprintf(
			// translators: %1$s is opening <a> tag, %2$s is closing </a> tag.
				__( '%1$sLicense activation%2$s required. A license is required to get premium plugin updates, as well as to get additional services.', 'robin-image-optimizer' ),
				'<a href="' . esc_url( $license_page_url ) . '">',
				'</a>'
			);
		} elseif ( 'need_renew_license' == $type ) {
			return sprintf(
			// translators: %1$s is opening <a> tag, %2$s is closing </a> tag.
				__( 'Your %1$slicense%2$s has expired. You can no longer get premium plugin updates, premium support and your access to services has been suspended.', 'robin-image-optimizer' ),
				'<a href="' . esc_url( $license_page_url ) . '">',
				'</a>'
			);
		}

		return $text;
	},
	10,
	3
);

/**
 * Check if the AVIF upsell banner has been dismissed by the current user.
 *
 * @return bool True if dismissed, false otherwise.
 */
function wrio_is_avif_banner_dismissed() {
	return (bool) get_user_meta( get_current_user_id(), 'wrio_avif_banner_dismissed', true );
}

/**
 * AJAX handler for dismissing the AVIF upsell banner.
 */
add_action(
	'wp_ajax_wrio_dismiss_avif_banner',
	function () {
		check_ajax_referer( 'wrio_dismiss_avif_banner', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'robin-image-optimizer' ) ] );
		}

		// Dismiss permanently.
		update_user_meta( get_current_user_id(), 'wrio_avif_banner_dismissed', 1 );

		wp_send_json_success();
	}
);
