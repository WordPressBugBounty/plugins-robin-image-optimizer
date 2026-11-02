<?php
/**
 * Admin-ajax handlers of the custom folders add-on (Other Media page).
 *
 * @package robin-image-optimizer
 */

/**
 * Переоптимизация cf_image. AJAX
 */
add_action(
	'wp_ajax_wio_cf_reoptimize_image',
	function () {
		if ( ! check_ajax_referer( 'reoptimize', false, false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$image_id             = (int) $_POST['id'];
		$backup               = WRIOP_Backup::get_instance();
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );
		$cf                   = WRIO_Custom_Folders::get_instance();
		if ( ! $backup instanceof WRIOP_Backup || ! $cf instanceof WRIO_Custom_Folders ) {
			wp_send_json_error( [ 'message' => 'Unavailable' ], 500 );
		}

		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			echo $cf->getMediaColumnContent( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
			wp_die();
		}

		wp_suspend_cache_addition( true );

		$default_level = WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level', 'normal' );
		$level         = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : $default_level;

		// The user asks for it: send failures again too, even permanent ones or before their retry time.
		\WRIO\Storage\Store::retry_now( 'cf_image', $image_id );
		$result = $cf->optimizeImage( $image_id, $level );

		// A new level without the original, for example: say why nothing changed, as the Media Library does.
		if ( isset( $result['error'] ) && is_wp_error( $result['error'] ) ) {
			echo '<p class="wio-restore-error" role="alert">' . esc_html( $result['error']->get_error_message() ) . '</p>';
		}

		echo $cf->getMediaColumnContent( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
		wp_die();
	}
);

/**
 * Восстановление
 */
add_action(
	'wp_ajax_wio_cf_restore_image',
	function () {
		if ( ! check_ajax_referer( 'restore', false, false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		wp_suspend_cache_addition( true );
		$image_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$cf = WRIO_Custom_Folders::get_instance();
		if ( ! $cf instanceof WRIO_Custom_Folders ) {
			wp_send_json_error( [ 'message' => 'Unavailable' ], 500 );
		}
		$cf_image = $cf->getImage( $image_id );
		if ( $cf_image->isOptimized() ) {
			$restored = $cf_image->restore();
			if ( is_wp_error( $restored ) ) {
				echo '<p class="wio-restore-error" role="alert">' . esc_html( $restored->get_error_message() ) . '</p>';
			} else {
				$folder = $cf->getFolder( $cf_image->get( 'folder_uid' ) );
				if ( $folder instanceof WRIO_Folder ) {
					$folder->reCountOptimizedFiles();
					$cf->saveFolders();
				}
			}
		}

		echo $cf->getMediaColumnContent( $image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
		wp_die();
	}
);
