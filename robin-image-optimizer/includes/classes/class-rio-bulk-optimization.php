<?php

use WBCR\Factory_Processing_759\WP_Background_Process;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_Bulk_Optimization
 *
 * Handles bulk optimization operations and processes related to media attachments,
 * thumbnails, and other optimization tasks within the WordPress environment.
 */
class WRIO_Bulk_Optimization {

	public $processing;

	public function __construct() {
		$image_optimization_type = WRIO_Plugin::app()->getOption( 'image_optimization_type', '' );
		if ( wrio_is_license_activate() && $image_optimization_type === 'background' ) {
			$scope            = WRIO_Plugin::app()->request->request( 'scope', null, true );
			$this->processing = $scope ? wrio_get_processing_class( $scope ) : $scope;

			add_action( 'wp_ajax_wrio-cron-start', [ $this, 'processing_start' ] );
			add_action( 'wp_ajax_wrio-cron-stop', [ $this, 'processing_stop' ] );

			add_action( 'wp_ajax_wrio-webp-cron-start', [ $this, 'webp_processing_start' ] );
			add_action( 'wp_ajax_wrio-webp-cron-stop', [ $this, 'webp_processing_stop' ] );
			add_action( 'wp_ajax_wrio-avif-cron-start', [ $this, 'avif_processing_start' ] );
			add_action( 'wp_ajax_wrio-avif-cron-stop', [ $this, 'avif_processing_stop' ] );
		} else {
			add_action( 'wp_ajax_wrio-cron-start', [ $this, 'cron_start' ] );
			add_action( 'wp_ajax_wrio-cron-stop', [ $this, 'cron_stop' ] );

			add_action( 'wp_ajax_wrio-webp-cron-start', [ $this, 'webp_cron_start' ] );
			add_action( 'wp_ajax_wrio-webp-cron-stop', [ $this, 'webp_cron_stop' ] );
			add_action( 'wp_ajax_wrio-avif-cron-start', [ $this, 'avif_cron_start' ] );
			add_action( 'wp_ajax_wrio-avif-cron-stop', [ $this, 'avif_cron_stop' ] );
		}

		add_action( 'wp_ajax_wrio-bulk-optimization-process', [ $this, 'bulk_optimization_process' ] );
		add_action( 'wp_ajax_wrio-bulk-conversion-process', [ $this, 'bulk_conversion_process' ] );
		add_action( 'wp_ajax_wio_reoptimize_image', [ $this, 'reoptimize_image' ] );
		add_action( 'wp_ajax_wio_convert_image', [ $this, 'convert_image' ] );
		add_action( 'wp_ajax_wio_restore_image', [ $this, 'restore_image' ] );

		add_action( 'wp_ajax_wbcr-rio-check-servers-status', [ $this, 'check_servers_status' ] );
		add_action( 'wp_ajax_wbcr-rio-check-user-balance', [ $this, 'check_user_balance' ] );

		// add_action( 'wp_ajax_wbcr-rio-calculate-total-images', [ $this, 'calculate_total_images' ] );
		add_action( 'wp_ajax_wbcr-rio-calculate-total-images', [ $this, 'calculate_total_images' ] );
		add_action( 'wp_ajax_wbcr-rio-calculate-total-attachments', [ $this, 'calculate_total_attachments' ] );
		add_action( 'wp_ajax_wbcr-rio-calculate-total-thumbs', [ $this, 'calculate_total_thumbs' ] );
	}

	/**
	 * Calculates the total number of attachments that meet specified criteria.
	 * Retrieves the total count of attachment posts with allowed formats, excluding duplicates
	 * if WPML is active, and sends the result as a JSON response.
	 * Includes nonce verification and checks user permissions.
	 *
	 * @return void Outputs JSON response with the total count of attachments.
	 */
	public function calculate_total_attachments() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		WRIO_Plugin::app()->deletePopulateOption( 'wrio_partial_total_count' );

		$total_attachments = WRIO_Image_Query::get_instance()->count_total_attachments();

		wp_send_json_success(
			[
				'found_attachments' => (int) $total_attachments,
			]
		);
	}

	/**
	 * Calculates the total number of attachment thumbnails that match specified criteria.
	 * Processes a batch of attachments, counting the allowed thumbnail sizes, and updates
	 * the total count in the database. Handles AJAX requests, including user permissions
	 * and nonce verification.
	 *
	 * @return void Outputs JSON response with the total count of thumbnails and processing status.
	 */
	public function calculate_total_thumbs() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		global $wpdb;

		$offset = (int) WRIO_Plugin::app()->request->post( 'offset', 0 );
		$limit  = (int) WRIO_Plugin::app()->request->post( 'limit', 200 );

		$allowed_formats_sql = wrio_get_allowed_formats( true );
		$allowed_sizes       = explode( ',', WRIO_Plugin::app()->getPopulateOption( 'allowed_sizes_thumbnail', '' ) );

		$query = $wpdb->prepare(
			"
	        SELECT posts.ID
	        FROM {$wpdb->posts} as posts
	        WHERE post_type = 'attachment'
	            AND post_status = 'inherit'
	            AND post_mime_type IN ({$allowed_formats_sql})
	        LIMIT %d OFFSET %d
	    ",
			$limit,
			$offset
		);

		// Учитываем WPML (исключение дубликатов)
		if ( defined( 'WPML_PLUGIN_FILE' ) ) {
			$query = str_replace(
				'WHERE post_type =',
				"WHERE NOT EXISTS (
            SELECT icl.element_id 
            FROM {$wpdb->prefix}icl_translations as icl 
            WHERE icl.element_id = posts.ID 
            AND icl.element_type = 'post_attachment'
            AND source_language_code IS NOT NULL
        ) AND post_type =",
				$query
			);
		}

		$attachments = $wpdb->get_results( $query );
		$total_count = (int) WRIO_Plugin::app()->getPopulateOption( 'wrio_partial_total_count', 0 );

		$current_batch_count = 0;

		foreach ( $attachments as $attachment ) {
			$meta = wp_get_attachment_metadata( $attachment->ID );
			if ( $meta && isset( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_key => $size_value ) {
					if ( in_array( $size_key, $allowed_sizes ) ) {
						++$current_batch_count;
					}
				}
			}
		}

		$total_count += $current_batch_count;
		WRIO_Plugin::app()->updatePopulateOption( 'wrio_partial_total_count', $total_count );

		// Если больше данных для обработки нет — завершаем и сохраняем в кеш
		if ( count( $attachments ) < $limit ) {
			WRIO_Plugin::app()->deletePopulateOption( 'wrio_partial_total_count' );

			wp_send_json_success(
				[
					'found_thumbs' => $total_count,
					'done'         => true,
				]
			);
		}

		wp_send_json_success(
			[
				'found_thumbs' => $total_count,
				'done'         => false,
				'next_offset'  => $offset + $limit,
			]
		);
	}

	public function cron_start() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		// where was runned cron
		$cron_running_place = WRIO_Plugin::app()->getPopulateOption( 'cron_running', false );

		if ( $scope == $cron_running_place ) {
			wp_send_json_success();
		}

		WRIO_Plugin::app()->updatePopulateOption( 'cron_running', $scope );
		WRIO_Cron::start();

		wp_send_json_success();
	}

	public function cron_stop() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		WRIO_Plugin::app()->updatePopulateOption( 'cron_running', false );
		WRIO_Cron::stop();

		wp_send_json_success();
	}

	public function webp_cron_start() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		$type = 'conversion';

		// where was runned cron
		$cron_running_place = WRIO_Plugin::app()->getPopulateOption( "{$type}_cron_running", false );

		if ( $scope == $cron_running_place ) {
			wp_send_json_success();
		}

		WRIO_Plugin::app()->updatePopulateOption( "{$type}_cron_running", $scope );
		WRIO_Cron::start( $type );

		wp_send_json_success();
	}

	public function webp_cron_stop() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$type = 'conversion';

		WRIO_Plugin::app()->updatePopulateOption( "{$type}_cron_running", false );
		WRIO_Cron::stop( $type );

		wp_send_json_success();
	}

	public function processing_start() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		// where was runned
		$process_running_place = WRIO_Plugin::app()->getPopulateOption( 'process_running', false );

		if ( $scope == $process_running_place ) {
			wp_send_json_success();
		}

		WRIO_Plugin::app()->updatePopulateOption( 'process_running', $scope );

		$processing = wrio_get_processing_class( $scope );
		if ( ! $processing ) {
			WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );
			wp_send_json_error( [ 'message' => 'Processing class not found for scope: ' . $scope ] );
		}

		if ( $processing->push_items() ) {
			$processing->save()->dispatch();
		} else {
			// WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );
			wp_send_json_success(
				[
					'stop' => true,
				]
			);
		}

		wp_send_json_success();
	}

	public function processing_stop() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );
		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );
		$processing = wrio_get_processing_class( $scope );
		if ( $processing ) {
			$processing->cancel_process();
		}

		wp_send_json_success();
	}

	public function webp_processing_start() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		$scope = $scope . '_webp';

		// where was runned
		$process_running_place = WRIO_Plugin::app()->getPopulateOption( "{$scope}_process_running", false );

		if ( $scope == $process_running_place ) {
			wp_send_json_success();
		}

		WRIO_Plugin::app()->updatePopulateOption( "{$scope}_process_running", $scope );

		$processing = wrio_get_processing_class( $scope );
		if ( ! $processing ) {
			WRIO_Plugin::app()->updatePopulateOption( "{$scope}_process_running", false );
			wp_send_json_error( [ 'message' => 'Processing class not found for scope: ' . $scope ] );
		}

		if ( $processing->push_items() ) {
			$processing->save()->dispatch();
		} else {
			// WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );
			wp_send_json_success(
				[
					'stop' => true,
				]
			);
		}

		wp_send_json_success();
	}

	public function webp_processing_stop() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );
		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		$scope = $scope . '_webp';

		WRIO_Plugin::app()->updatePopulateOption( "{$scope}_process_running", false );
		$processing = wrio_get_processing_class( $scope );
		if ( $processing ) {
			$processing->cancel_process();
		}

		wp_send_json_success();
	}

	/**
	 * Start AVIF conversion cron job.
	 *
	 * @return void
	 */
	public function avif_cron_start() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		$type = 'avif_conversion';

		// where was runned cron
		$cron_running_place = WRIO_Plugin::app()->getPopulateOption( "{$type}_cron_running", false );

		if ( $scope == $cron_running_place ) {
			wp_send_json_success();
		}

		WRIO_Plugin::app()->updatePopulateOption( "{$type}_cron_running", $scope );
		WRIO_Cron::start( $type );

		wp_send_json_success();
	}

	/**
	 * Stop AVIF conversion cron job.
	 *
	 * @return void
	 */
	public function avif_cron_stop() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$type = 'avif_conversion';

		WRIO_Plugin::app()->updatePopulateOption( "{$type}_cron_running", false );
		WRIO_Cron::stop( $type );

		wp_send_json_success();
	}

	/**
	 * Start AVIF conversion background processing.
	 *
	 * @return void
	 */
	public function avif_processing_start() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		$scope = $scope . '_avif';

		// where was runned
		$process_running_place = WRIO_Plugin::app()->getPopulateOption( "{$scope}_process_running", false );

		if ( $scope == $process_running_place ) {
			wp_send_json_success();
		}

		WRIO_Plugin::app()->updatePopulateOption( "{$scope}_process_running", $scope );

		$processing = wrio_get_processing_class( $scope );
		if ( ! $processing ) {
			WRIO_Plugin::app()->updatePopulateOption( "{$scope}_process_running", false );
			wp_send_json_error( [ 'message' => 'Processing class not found for scope: ' . $scope ] );
		}

		if ( $processing->push_items() ) {
			$processing->save()->dispatch();
		} else {
			wp_send_json_success(
				[
					'stop' => true,
				]
			);
		}

		wp_send_json_success();
	}

	/**
	 * Stop AVIF conversion background processing.
	 *
	 * @return void
	 */
	public function avif_processing_stop() {
		check_ajax_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$scope = WRIO_Plugin::app()->request->request( 'scope', null, true );
		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		$scope = $scope . '_avif';

		WRIO_Plugin::app()->updatePopulateOption( "{$scope}_process_running", false );
		$processing = wrio_get_processing_class( $scope );
		if ( $processing ) {
			$processing->cancel_process();
		}

		wp_send_json_success();
	}

	public function bulk_optimization_process() {
		check_admin_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$reset_current_error = (bool) WRIO_Plugin::app()->request->request( 'reset_current_errors' );
		$scope               = WRIO_Plugin::app()->request->request( 'scope', null, true );

		WRIO_Plugin::app()->logger->info( sprintf( 'Start bulk optimization process! Scope: %s', $scope ) );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		// Use orchestrator for media-library scope
		if ( 'media-library' === $scope ) {
			if ( $reset_current_error ) {
				$media_library = WRIO_Media_Library::get_instance();
				$media_library->resetCurrentErrors();
			}

			$orchestrator = WRIO_Optimization_Orchestrator::get_instance();
			$result       = $orchestrator->execute_next_action( 1 );

			if ( isset( $result['error'] ) ) {
				$error_massage = $result['error'];

				if ( empty( $error_massage ) ) {
					$error_massage = __( "Unknown error. Enable error log on the plugin's settings page, then check the error report on the Error Log page. You can export the error report and send it to the support service of the plugin.", 'robin-image-optimizer' );
				}

				WRIO_Plugin::app()->logger->error( sprintf( 'Bulk optimization error: %s.', $error_massage ) );

				wp_send_json_error( [ 'error_message' => $error_massage ] );
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'End bulk optimization process! Scope: %s. Remain: %d', $scope, $result['remain'] ) );

			wp_send_json_success( $result );
		}

		// Fall back to old behavior for custom-folders, nextgen, etc.
		// Context class name. If plugin expands with add-ons
		$class_name = 'WRIO_' . wrio_dashes_to_camel_case( $scope, true );

		if ( ! class_exists( $class_name ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Bulk optimization error: Context class (%s) not found.', $class_name ) );

			// todo: Temporary bug fix.
			if ( 'custom-folders' === $scope ) {
				$class_name = 'WRIO_Custom_Folders';
			} elseif ( 'nextgen-gallery' == $scope ) {
				$class_name = 'WRIO_Nextgen_Gallery';
			}

			if ( ! class_exists( $class_name ) ) {
				wp_send_json_error( [ 'error_message' => 'Context class not found.' ] );
			}
		}

		/**
		 * Create an instance of the class depending on the context in which scope user
		 * has runned optimization.
		 *
		 * @see WRIO_Custom_Folders
		 * @see WRIO_Nextgen_Gallery
		 * @var WRIO_Media_Library $optimizer
		 */
		$optimizer = new $class_name();

		if ( $reset_current_error ) {
			$optimizer->resetCurrentErrors();
		}

		$result = $optimizer->processUnoptimizedImages( 1 );

		if ( is_wp_error( $result ) ) {
			$error_massage = $result->get_error_message();

			if ( empty( $error_massage ) ) {
				$error_massage = __( "Unknown error. Enable error log on the plugin's settings page, then check the error report on the Error Log page. You can export the error report and send it to the support service of the plugin.", 'robin-image-optimizer' );
			}

			WRIO_Plugin::app()->logger->error( sprintf( 'Bulk optimization error: %s.', $result->get_error_message() ) );

			wp_send_json_error( [ 'error_message' => $error_massage ] );
		}

		// If all images are processed, send completion command
		if ( $result['remain'] <= 0 ) {
			$result['end'] = true;
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'End bulk optimization process! Scope: %s. Remain: %d', $scope, $result['remain'] ) );

		wp_send_json_success( $result );
	}

	public function bulk_conversion_process() {
		check_admin_referer( 'bulk_conversion' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$reset_current_error = (bool) WRIO_Plugin::app()->request->request( 'reset_current_errors' );
		$scope               = WRIO_Plugin::app()->request->request( 'scope', null, true );
		$format              = WRIO_Plugin::app()->request->request( 'format', 'webp', true );

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'Start bulk conversion process! Scope: %s, Format: %s', $scope, $format ) );

		if ( empty( $scope ) ) {
			wp_die( - 1 );
		}

		// Context class name. If plugin expands with add-ons
		$class_name = 'WRIO_' . wrio_dashes_to_camel_case( $scope, true );

		if ( ! class_exists( $class_name ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Bulk conversion error: Context class (%s) not found.', $class_name ) );

			// todo: Temporary bug fix.
			if ( 'media-library' === $scope ) {
				$class_name = 'WRIO_Media_Library';
			} elseif ( 'custom-folders' === $scope ) {
				$class_name = 'WRIO_Custom_Folders';
			} elseif ( 'nextgen-gallery' == $scope ) {
				$class_name = 'WRIO_Nextgen_Gallery';
			}

			if ( ! class_exists( $class_name ) ) {
				wp_send_json_error( [ 'error_message' => 'Context class not found.' ] );
			}
		}

		/**
		 * Create an instance of the class depending on the context in which scope user
		 * has runned optimization.
		 *
		 * @see WRIO_Media_Library
		 * @see WRIO_Custom_Folders
		 * @see WRIO_Nextgen_Gallery
		 * @var WRIO_Media_Library $optimizer
		 */
		$optimizer = new $class_name();

		if ( $reset_current_error ) {
			$optimizer->resetCurrentErrors(); // сбрасываем текущие ошибки оптимизации
		}

		$result = $optimizer->webpUnoptimizedImages( 1, $format );

		if ( is_wp_error( $result ) ) {
			$error_massage = $result->get_error_message();

			if ( empty( $error_massage ) ) {
				$error_massage = __( "Unknown error. Enable error log on the plugin's settings page, then check the error report on the Error Log page. You can export the error report and send it to the support service of the plugin.", 'robin-image-optimizer' );
			}

			WRIO_Plugin::app()->logger->error( sprintf( 'Bulk conversion error: %s.', $result->get_error_message() ) );

			wp_send_json_error( [ 'error_message' => $error_massage ] );
		}

		// если изображения закончились - посылаем команду завершения
		if ( $result['remain'] <= 0 ) {
			$result['end'] = true;
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'End bulk conversion process! Scope: %s, Format: %s. Remain: %d', $scope, $format, $result['remain'] ) );

		wp_send_json_success( $result );
	}

	public function reoptimize_image() {
		check_admin_referer( 'reoptimize' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$default_level = WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level', 'normal' );

		$attachment_id = (int) WRIO_Plugin::app()->request->post( 'id' );
		$level         = WRIO_Plugin::app()->request->post( 'level', $default_level, true );

		$backup               = WIO_Backup::get_instance();
		$media_library        = WRIO_Media_Library::get_instance();
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', false );

		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			echo $media_library->getMediaColumnContent( $attachment_id );
			die();
		}

		$optimized_data = $media_library->optimizeAttachment( $attachment_id, $level );

		if ( $optimized_data && isset( $optimized_data['processing'] ) ) {
			echo 'processing';
			die();
		}

		echo $media_library->getMediaColumnContent( $attachment_id );
		die();
	}

	public function convert_image() {
		check_admin_referer( 'convert' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$attachment_id = (int) WRIO_Plugin::app()->request->post( 'id' );
		$format        = WRIO_Plugin::app()->request->post( 'format', 'webp', true );
		$media_library = WRIO_Media_Library::get_instance();

		$media_library->webpConvertAttachment( $attachment_id, $format );

		echo $media_library->getMediaColumnContent( $attachment_id );
		die();
	}

	public function restore_image() {
		check_admin_referer( 'restore' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$attachment_id = (int) WRIO_Plugin::app()->request->post( 'id' );

		$media_library  = WRIO_Media_Library::get_instance();
		$wio_attachment = $media_library->getAttachment( $attachment_id );

		if ( $wio_attachment->isOptimized() ) {
			$media_library->restoreAttachment( $attachment_id );
		}

		echo $media_library->getMediaColumnContent( $attachment_id );
		die();
	}

	public function check_servers_status() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		// Auto-detect server based on license status
		$is_premium     = wrio_is_license_activate();
		$license_source = wrio_get_license_source();
		$server_name    = $is_premium ? 'server_5' : 'server_2';
		$return_data    = [ 'server_name' => $server_name ];

		$headers = [
			'User-Agent' => '',
		];

		// For SDK (ThemeIsle) licenses, the license was already validated during activation
		// via ThemeIsle's API, so we can skip the Robin API license check.
		if ( $is_premium && 'sdk' === $license_source ) {
			wp_send_json_success( $return_data );
		}

		if ( $is_premium ) {
			$api_url                     = 'https://dashboard.robinoptimizer.com/v1/license/check';
			$headers['Authorization']    = 'Bearer ' . base64_encode( wrio_get_license_key() );
			$headers['PluginId']         = wrio_get_freemius_plugin_id();
			$headers['X-License-Source'] = $license_source;
			$headers['X-Site-Url']       = home_url();
		} else {
			$api_url                  = 'https://dashboard.robinoptimizer.com/v1/free/license/check';
			$host                     = get_option( 'siteurl' );
			$headers['Authorization'] = 'Bearer ' . base64_encode( $host );
			$headers['X-Site-Url']    = home_url();
		}

		$request = wp_remote_request(
			$api_url,
			[
				'method'  => 'GET',
				'headers' => $headers,
			]
		);

		if ( is_wp_error( $request ) ) {
			$er_msg = $request->get_error_message();

			$return_data['error'] = $er_msg;
			wp_send_json_error( $return_data );
		}

		$response_code = wp_remote_retrieve_response_code( $request );

		if ( $response_code != 200 ) {
			$return_data['error'] = 'Server response ' . $response_code;
			wp_send_json_error( $return_data );
		}

		$data = json_decode( wp_remote_retrieve_body( $request ) );

		if ( isset( $data->response->server_load ) ) {
			$return_data = [ 'server_load' => $data->response->server_load ];
		}

		wp_send_json_success( $return_data );
	}

	public function check_user_balance() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		// Auto-detect server based on license status
		$is_premium     = wrio_is_license_activate();
		$license_source = wrio_get_license_source();
		$processor      = WIO_OptimizationTools::getImageProcessor();

		if ( ! $processor->has_quota_limit() ) {
			wp_send_json_error( [ 'error' => __( 'The server has no quota restrictions!', 'robin-image-optimizer' ) ] );
		}

		// For SDK (ThemeIsle) licenses, we can't check quota via Robin API yet.
		// Return unlimited quota - actual limits will be enforced server-side during optimization.
		if ( $is_premium && 'sdk' === $license_source ) {
			wp_send_json_success(
				[
					'balance'  => -1, // -1 indicates unlimited/unknown
					'reset_at' => '',
				]
			);
		}

		$headers = [];

		if ( $is_premium ) {
			$api_url                     = 'https://dashboard.robinoptimizer.com/v1/license/remaining';
			$headers['Authorization']    = 'Bearer ' . base64_encode( wrio_get_license_key() );
			$headers['PluginId']         = wrio_get_freemius_plugin_id();
			$headers['X-License-Source'] = $license_source;
			$headers['X-Site-Url']       = home_url();
		} else {
			$api_url                  = 'https://dashboard.robinoptimizer.com/v1/free/license/remaining';
			$host                     = get_option( 'siteurl' );
			$headers['Authorization'] = 'Bearer ' . base64_encode( $host );
			$headers['X-Site-Url']    = home_url();
		}

		$request = wp_remote_request(
			$api_url,
			[
				'method'  => 'GET',
				'headers' => $headers,
			]
		);

		if ( is_wp_error( $request ) ) {
			$error_msg = $request->get_error_message();

			$return_data['error'] = $error_msg;
			wp_send_json_error( $return_data );
		}

		$response_code = wp_remote_retrieve_response_code( $request );
		$response_body = wp_remote_retrieve_body( $request );

		if ( $response_code != 200 ) {
			$return_data['error'] = 'Server response ' . $response_code;
			if ( $response_code === 401 ) {
				$error_data           = @json_decode( $response_body );
				$return_data['error'] = $error_data->message;
			}
			wp_send_json_error( $return_data );
		}

		if ( empty( $response_body ) ) {
			$return_data['error'] = 'Server responded an empty request body!';
			wp_send_json_error( $return_data );
		}

		$data = @json_decode( $response_body );

		if ( ! isset( $data->status ) || $data->status != 'ok' ) {
			$return_data['error'] = 'Server responded an fail status';
			wp_send_json_error( $return_data );
		}

		$current_quota = (int) $data->response->quota;
		$processor->set_quota_limit( $current_quota );

		$output = [ 'balance' => $current_quota ];

		$reset_at           = (int) $data->response->reset_at;
		$reset_at          += (int) get_option( 'gmt_offset', 0 );
		$output['reset_at'] = gmdate( 'd-m-Y H:i', $reset_at );

		wp_send_json_success( $output );
	}

	/*
	public function calculate_total_images() {
		check_ajax_referer( 'bulk_optimization' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		global $wpdb;
		$db_table         = RIO_Process_Queue::table_name();
		$sql              = $wpdb->prepare( "SELECT *   FROM {$db_table}
					WHERE item_type = 'attachment' AND result_status IN (%s, %s)
					ORDER BY id DESC;", RIO_Process_Queue::STATUS_SUCCESS, RIO_Process_Queue::STATUS_ERROR );
		$optimized_images = $wpdb->get_results( $sql, ARRAY_A );

		$count = 0;
		if ( ! empty( $optimized_images ) ) {
			foreach ( $optimized_images as $row ) {
				$item  = new RIO_Process_Queue( $row );
				$count = $count + 1 + (int) $item->get_extra_data()->get_thumbnails_count();
			}
		}

		$allowed_formats_sql = wrio_get_allowed_formats( true );

		$sql = "SELECT posts.ID
					FROM {$wpdb->posts} as posts
				WHERE post_type = 'attachment'
					AND post_status = 'inherit'
					AND post_mime_type IN ( {$allowed_formats_sql} )";

		// If you use a WPML plugin, you need to exclude duplicate images
		if ( defined( 'WPML_PLUGIN_FILE' ) ) {
			$sql .= " AND NOT EXISTS
					(SELECT trnsl.element_id FROM {$wpdb->prefix}icl_translations as trnsl
						WHERE trnsl.element_id=posts.ID
						AND trnsl.element_type='post_attachment'
						AND source_language_code IS NOT NULL
					)";
		}

		$attachments = $wpdb->get_results( $sql );

		$allowed_sizes = explode( ',', WRIO_Plugin::app()->getPopulateOption( 'allowed_sizes_thumbnail', '' ) );
		$total_images  = 0;
		$upload        = wp_upload_dir();
		$upload        = $upload['basedir'];
		foreach ( $attachments as $attachment ) {
			$meta = wp_get_attachment_metadata( $attachment->ID );
			if ( $meta ) {
				if ( isset( $meta['file'] ) && file_exists( "{$upload}/{$meta['file']}" ) ) {
					$total_images ++;
				}

				foreach ( $meta['sizes'] as $k => $value ) {
					if ( in_array( $k, $allowed_sizes ) ) {
						$total_images ++;
					}
				}
			}
		}

		$result_total = $total_images - $count;

		wp_send_json_success( [
			'total' => $result_total >= 0 ? $result_total : 0,
		] );
	}*/
}
