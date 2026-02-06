<?php
/**
 * Ajax handlers
 *
 * @version       1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Обработчик ajax запросов для проверки, активации, деактивации лицензионного ключа
 *
 * @param Wbcr_Factory600_Plugin $plugin_instance
 *
 * @since         2.0.7
 */
function wbcr_factory_templates_600_check_license( $plugin_instance ) {

	$plugin_name = $plugin_instance->request->post( 'plugin_name', null, true );

	if ( ( $plugin_instance->getPluginName() !== $plugin_name ) || ! $plugin_instance->current_user_can() ) {
		wp_die( -1, 403 );
	}

	$action      = $plugin_instance->request->post( 'license_action', false, true );
	$license_key = $plugin_instance->request->post( 'licensekey', null );

	check_admin_referer( "clearfy_activate_license_for_{$plugin_name}" );

	if ( empty( $action ) || ! in_array( $action, [ 'activate', 'deactivate', 'sync', 'unsubscribe' ] ) ) {
		wp_send_json_error( [ 'error_message' => __( 'This licensing action is not permitted.', 'robin-image-optimizer' ) ] );
		die();
	}

	$result          = null;
	$success_message = '';

	try {
		switch ( $action ) {
			case 'activate':
				if ( empty( $license_key ) ) {
					wp_send_json_error( [ 'error_message' => __( 'License key is empty.', 'robin-image-optimizer' ) ] );
				} else {
					$plugin_instance->premium->activate( $license_key );
					$success_message = __( 'Your license has been successfully activated', 'robin-image-optimizer' );
				}
				break;
			case 'deactivate':
				$plugin_instance->premium->deactivate();
				$success_message = __( 'The license is deactivated', 'robin-image-optimizer' );
				break;
			case 'sync':
				$plugin_instance->premium->sync();
				$success_message = __( 'The license has been updated', 'robin-image-optimizer' );
				break;
			case 'unsubscribe':
				$plugin_instance->premium->cancel_paid_subscription();
				$success_message = __( 'Subscription success cancelled', 'robin-image-optimizer' );
				break;
		}
	} catch ( Exception $e ) {

		/**
		 * Экшен выполняется, когда проверка лицензии вернула ошибку
		 *
		 * @param string $action
		 * @param mixed  $license_key
		 * @param string $error_message
		 *
		 * @since 2.0.7
		 * @since 2.1.2 Переименован в {$plugin_name}/factory/clearfy/check_license_error
		 */
		do_action( "{$plugin_name}/factory/clearfy/check_license_error", $action, $license_key, $e->getMessage() );

		wp_send_json_error( [ 'error_message' => $e->getMessage() ] );
		die();
	}

	/**
	 * Экшен выполняется, когда проверка лицензии успешно завершена
	 *
	 * @param string $license_key
	 *
	 * @param string $action
	 * @since 2.1.2 Переименован в {$plugin_name}/factory/clearfy/check_license_success
	 * @since 2.0.7
	 */
	do_action( "{$plugin_name}/factory/clearfy/check_license_success", $action, $license_key );

	wp_send_json_success( [ 'message' => $success_message ] );

	die();
}
