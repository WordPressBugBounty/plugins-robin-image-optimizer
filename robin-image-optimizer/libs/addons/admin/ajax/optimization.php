<?php
/**
 * AJAX handlers that re-optimize and restore NextGen images.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Переоптимизация аттачмента
 *
 * @return void
 */
function wbcr_riop_reoptimizeImage() {
	if ( ! check_ajax_referer( 'reoptimize', false, false ) || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	$image_id             = (int) $_POST['id'];
	$backup               = WRIOP_Backup::get_instance();
	$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );
	$nextgen_gallery      = WRIO_Nextgen_Gallery::get_instance();
	if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
		echo $nextgen_gallery->getMediaColumnContent( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
		wp_die();
	}
	wp_suspend_cache_addition( true );
	$default_level = WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level', 'normal' );
	$level         = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : $default_level;

	// The user asks for it: send failures again too, even permanent ones or before their retry time.
	\WRIO\Storage\Store::retry_now( 'nextgen', $image_id );
	$result = $nextgen_gallery->optimizeNextgenImage( $image_id, $level );

	// A new level without the original, for example: say why nothing changed, as custom folders do.
	if ( isset( $result['error'] ) ) {
		echo '<p class="wio-restore-error" role="alert">' . esc_html( $result['error']->get_error_message() ) . '</p>';
	}

	echo $nextgen_gallery->getMediaColumnContent( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
	wp_die();
}

/**
 * Восстановление
 *
 * @return void
 */
function wbcr_riop_restoreImage() {
	if ( ! check_ajax_referer( 'restore', false, false ) || ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	wp_suspend_cache_addition( true );
	$image_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

	$nextgen_gallery = WRIO_Nextgen_Gallery::get_instance();
	$nextgen_image   = $nextgen_gallery->getNextgenImage( $image_id );
	// Also an image whose main file failed and whose thumbnail is optimized.
	if ( $nextgen_image->isOptimized() || $nextgen_image->has_backup() ) {
		$restored = $nextgen_image->restore();

		if ( is_wp_error( $restored ) ) {
			echo '<p class="wio-restore-error" role="alert">' . esc_html( $restored->get_error_message() ) . '</p>';
		}
	}

	echo $nextgen_gallery->getMediaColumnContent( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
	wp_die();
}
