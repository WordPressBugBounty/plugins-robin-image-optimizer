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

// The load check reads only the version option: a lost table would leave the Bulk screen at 0 images.
add_action(
	'admin_init',
	function () {
		RIO_Process_Queue::repair_tables();
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

		WRIO_App::enqueue_script( 'wrio-meta-migrations', 'meta-migrations' );
	}
);

/**
 * Plugin was heavy migrated into new architecture. Specifically, post meta was moved to separate table and
 * therefore it is required to migrate all of them to new table.
 *
 * This prints a notice, which contains clickable link with JS onclick event, which invokes AJAX request
 * to migrate these post metas to new table.
 *
 * Once all post meta migrated, notice would not be shown anymore.
 *
 * @since  1.3.0
 *
 * @see    wbcr_rio_migrate_postmeta_to_process_queue() for further information about AJAX processing function.
 * @see    wbcr_rio_has_meta_to_migrate() used to check whether to show notice or not.
 *
 * @see    RIO_Process_Queue for further information about new table.
 */
$wrio_meta_migration_notice = function () {
	$screen = get_current_screen();

	// The plugin's own pages show this notice inside the app.
	if ( ! $screen || ! in_array( $screen->base, [ 'plugins', 'themes', 'dashboard', 'edit', 'settings', 'dashboard-network', 'plugins-network', 'themes-network', 'settings-network' ], true ) ) {
		return;
	}

	if ( ! current_user_can( 'update_plugins' ) || ! wbcr_rio_has_meta_to_migrate() ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><b>%s:</b> %s</p></div>',
		esc_html( WRIO_Plugin::app()->getPluginTitle() ),
		wp_kses_post( wrio_get_meta_migration_notice_text() )
	);
};
add_action( 'admin_notices', $wrio_meta_migration_notice );
add_action( 'network_admin_notices', $wrio_meta_migration_notice );

/**
 * Check if the AVIF upsell banner has been dismissed by the current user.
 *
 * @return bool True if dismissed, false otherwise.
 */

/**
 * AJAX handler for dismissing the AVIF upsell banner.
 */
