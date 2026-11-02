<?php
/**
 * Bulk and single-image optimization AJAX handlers.
 *
 * @package robin-image-optimizer
 */

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

	/**
	 * Seconds that the bulk page waits before it asks again for an image that another process locks.
	 * A live process finishes soon. The lock of a dead process expires after Store::LOCK_TTL.
	 */
	const LOCKED_WAIT = 30;

	/**
	 * Register the AJAX actions of the media column.
	 */
	public function __construct() {
		add_action( 'wp_ajax_wio_reoptimize_image', [ $this, 'reoptimize_image' ] );
		add_action( 'wp_ajax_wio_convert_image', [ $this, 'convert_image' ] );
		add_action( 'wp_ajax_wio_restore_image', [ $this, 'restore_image' ] );
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
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );

		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			echo $media_library->getMediaColumnContent( $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the column template escapes every value.
			wp_die();
		}

		// The user asks for it: send failures again too, even permanent ones or before their retry time.
		\WRIO\Storage\Store::retry_now( 'attachment', $attachment_id );
		\WRIO\V2\Client::$trigger = 'manual';
		$optimized_data           = $media_library->optimizeAttachment( $attachment_id, $level );

		if ( is_wp_error( $optimized_data ) ) {
			echo '<p class="wio-restore-error" role="alert">' . esc_html( $optimized_data->get_error_message() ) . '</p>';
		}

		echo $media_library->getMediaColumnContent( $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the column template escapes every value.
		wp_die();
	}

	public function convert_image() {
		check_admin_referer( 'convert' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$attachment_id = (int) WRIO_Plugin::app()->request->post( 'id' );
		$format        = WRIO_Plugin::app()->request->post( 'format', 'webp', true );
		$media_library = WRIO_Media_Library::get_instance();

		if ( in_array( $format, [ 'webp', 'avif' ], true ) ) {
			// The user asks for it: send failures again too, even permanent ones or before their retry time.
			\WRIO\Storage\Store::retry_now( 'attachment', $attachment_id );
			\WRIO\V2\Client::$trigger = 'manual';
			$media_library->webpConvertAttachment( $attachment_id, $format );
		}

		echo $media_library->getMediaColumnContent( $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the column template escapes every value.
		wp_die();
	}

	public function restore_image() {
		check_admin_referer( 'restore' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
		}

		$attachment_id = (int) WRIO_Plugin::app()->request->post( 'id' );

		$media_library  = WRIO_Media_Library::get_instance();
		$wio_attachment = $media_library->getAttachment( $attachment_id );

		// An image optimized without a backup has nothing to put back (a stale page, or a direct request): its state stays.
		if ( $media_library->has_backup( $attachment_id ) ) {
			$restored = $media_library->restoreAttachment( $attachment_id );

			if ( is_wp_error( $restored ) ) {
				echo '<p class="wio-restore-error" role="alert">' . esc_html( $restored->get_error_message() ) . '</p>';
			}
		} elseif ( $wio_attachment->isOptimized() ) {
			echo '<p class="wio-restore-error" role="alert">' . esc_html__( 'The original of this image is not kept, so it cannot be restored.', 'robin-image-optimizer' ) . '</p>';
		}

		echo $media_library->getMediaColumnContent( $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the column template escapes every value.
		wp_die();
	}

	/**
	 * Shared by admin-ajax and REST: one optimization step for a scope.
	 *
	 * @param string|null $scope        media-library, custom-folders or nextgen-gallery.
	 * @param bool        $reset_errors Re-queue the images that failed last time: the first step of a new Start.
	 * @param mixed       $date_from    First upload date of the media library run, Y-m-d. A new Start sets it.
	 * @param mixed       $date_to      Last upload date of the media library run, Y-m-d. A new Start sets it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_process( $scope, $reset_errors, $date_from = '', $date_to = '' ) {
		$scope = is_string( $scope ) ? $scope : '';

		WRIO_Plugin::app()->logger->info( sprintf( 'Start bulk optimization process! Scope: %s', $scope ) );

		if ( '' === $scope ) {
			return self::bad_request();
		}

		// The API asked the site to wait (a 429 or any 5xx). The app calls this step again after the wait.
		$wait = \WRIO\V2\Client::backoff_seconds();

		if ( $wait > 0 ) {
			return [
				'wait' => $wait,
				'end'  => false,
			];
		}

		// The server does not know the license key: the run stops with the License message instead of sending every
		// image again on every step (O-2).
		$stop = \WRIO\V2\Client::from_settings()->stop_error();

		if ( $stop ) {
			return new WP_Error( $stop->get_error_code(), $stop->get_error_message(), [ 'status' => 403 ] );
		}

		$unknown = __( "Unknown error. Enable error log on the plugin's settings page, then check the error report on the Error Log page. You can export the error report and send it to the support service of the plugin.", 'robin-image-optimizer' );

		if ( 'media-library' === $scope ) {
			if ( $reset_errors ) {
				WRIO_Image_Query::set_run_dates( $date_from, $date_to );
				WRIO_Media_Library::get_instance()->resetCurrentErrors();
			}

			$result = WRIO_Optimization_Orchestrator::get_instance()->execute_next_action( 1 );

			if ( isset( $result['error'] ) ) {
				$message = ! empty( $result['error'] ) ? $result['error'] : $unknown;
				WRIO_Plugin::app()->logger->error( sprintf( 'Bulk optimization error: %s.', $message ) );

				return new WP_Error( 'wrio_process_failed', $message, [ 'status' => 500 ] );
			}

			// The API asked the site to wait during this step, or another process holds the lock of the image, and the
			// image is still to do: the step answers the wait, so the bulk page does not count the image as done.
			if ( ! empty( $result['held'] ) ) {
				$wait = \WRIO\V2\Client::backoff_seconds();

				return [
					'wait'       => $wait > 0 ? $wait : self::LOCKED_WAIT,
					// The errors reset already ran: the step after the wait must not reset the failures saved since.
					'reset_done' => (bool) $reset_errors,
					'end'        => false,
				];
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'End bulk optimization process! Scope: %s. Remain: %d', $scope, $result['remain'] ) );

			return $result;
		}

		// Context class name. If plugin expands with add-ons.
		$class_name = 'WRIO_' . wrio_dashes_to_camel_case( $scope, true );

		if ( ! class_exists( $class_name ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Bulk optimization error: Context class (%s) not found.', $class_name ) );

			if ( 'custom-folders' === $scope ) {
				$class_name = 'WRIO_Custom_Folders';
			} elseif ( 'nextgen-gallery' === $scope ) {
				$class_name = 'WRIO_Nextgen_Gallery';
			}

			if ( ! class_exists( $class_name ) ) {
				return new WP_Error( 'wrio_process_failed', __( 'Context class not found.', 'robin-image-optimizer' ), [ 'status' => 500 ] );
			}
		}

		/**
		 * Optimizer for the scope.
		 *
		 * @var WRIO_Media_Library $optimizer
		 */
		$optimizer = new $class_name();

		if ( $reset_errors ) {
			$optimizer->resetCurrentErrors();
		}

		$result = $optimizer->processUnoptimizedImages( 1 );

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			WRIO_Plugin::app()->logger->error( sprintf( 'Bulk optimization error: %s.', $message ) );

			return new WP_Error( 'wrio_process_failed', '' !== $message ? $message : $unknown, [ 'status' => 500 ] );
		}

		if ( $result['remain'] <= 0 ) {
			$result['end'] = true;
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'End bulk optimization process! Scope: %s. Remain: %d', $scope, $result['remain'] ) );

		return $result;
	}

	/**
	 * Start the scheduled (cron) or background run for a scope.
	 *
	 * @param string|null $scope     Scope.
	 * @param mixed       $date_from First upload date of a media library run, Y-m-d.
	 * @param mixed       $date_to   Last upload date of a media library run, Y-m-d.
	 *
	 * @return array<string, mixed>|WP_Error Empty array, or [ 'stop' => true ] when there is nothing to do.
	 */
	public static function run_schedule_start( $scope, $date_from = '', $date_to = '' ) {
		if ( ! is_string( $scope ) || '' === $scope ) {
			return self::bad_request();
		}

		if ( 'media-library' === $scope ) {
			// A new run asks the wrio_should_optimize filter again.
			WRIO_Image_Query::forget_skips();
			WRIO_Image_Query::set_run_dates( $date_from, $date_to );
		}

		if ( ! self::runs_in_background() ) {
			// Nothing waits: no recurring event and no run. The run itself selects with the same query.
			// ponytail: only the Media Library is checked here; the first step of another scope ends an empty run.
			if ( 'media-library' === $scope && ! WRIO_Image_Query::get_instance()->get_next_batch( 1, WRIO_Formats::get_enabled_formats() )[1] ) {
				WRIO_Cron::stop();

				return [ 'stop' => true ];
			}

			if ( WRIO_Plugin::app()->getPopulateOption( 'cron_running', false ) !== $scope ) {
				WRIO_Plugin::app()->updatePopulateOption( 'cron_running', $scope );
				WRIO_Cron::start();
			}

			return [];
		}

		$scope = wrio_processing_scope( $scope );
		if ( wrio_processing_scope( (string) WRIO_Plugin::app()->getPopulateOption( 'process_running', false ) ) === $scope ) {
			return [];
		}

		WRIO_Plugin::app()->updatePopulateOption( 'process_running', $scope );

		$processing = wrio_get_processing_class( $scope );
		if ( ! $processing ) {
			WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );

			return new WP_Error( 'wrio_process_failed', 'Processing class not found for scope: ' . $scope, [ 'status' => 500 ] );
		}

		if ( ! $processing->push_items() ) {
			WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );

			return [ 'stop' => true ];
		}

		$processing->save()->dispatch();

		return [];
	}

	/**
	 * Stop the scheduled or background run.
	 *
	 * @param string|null $scope Scope (needed to cancel a background process).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run_schedule_stop( $scope ) {
		if ( ! self::runs_in_background() ) {
			WRIO_Plugin::app()->updatePopulateOption( 'cron_running', false );
			WRIO_Cron::stop();

			return [];
		}

		if ( ! is_string( $scope ) || '' === $scope ) {
			return self::bad_request();
		}

		WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );
		$processing = wrio_get_processing_class( $scope );
		if ( $processing ) {
			$processing->cancel_process();
		}

		return [];
	}

	/**
	 * Ask the optimization API whether it answers for this site and license.
	 *
	 * The answer, and the error data when the server is down, also hold the test time, the seconds left of a
	 * rate-limit backoff and the last failed request, for the server status popover.
	 *
	 * @return array<string, mixed>|WP_Error The plan and the license state.
	 */
	public static function run_servers_status() {
		$account = \WRIO\V2\Client::from_settings()->account();
		$status  = [
			'checked_at' => time(),
			'backoff'    => \WRIO\V2\Client::backoff_seconds(),
			'last_error' => \WRIO\V2\Client::last_error(),
		];

		if ( is_wp_error( $account ) ) {
			return new WP_Error( 'wrio_server_down', $account->get_error_message(), [ 'status' => 502 ] + $status );
		}

		return [
			'plan'    => $account['plan'] ?? '',
			'license' => $account['license'] ?? '',
		] + $status;
	}

	/**
	 * Number of attachments the plugin can optimize.
	 *
	 * @return array{found_attachments: int}
	 */
	public static function run_total_attachments() {
		return [
			'found_attachments' => (int) WRIO_Image_Query::get_instance()->count_total_attachments(),
		];
	}

	/**
	 * Count the allowed thumbnails of a page of attachments; call again with next_offset and found_thumbs until done.
	 *
	 * The caller carries the running total. A total kept on the server mixed the pages of two open tabs.
	 *
	 * @param int $offset Attachment offset.
	 * @param int $limit  Attachments per call.
	 * @param int $total  Thumbnails counted on the pages before this one.
	 *
	 * @return array<string, mixed>
	 */
	public static function run_total_thumbs( $offset, $limit, $total = 0 ) {
		global $wpdb;

		$limit               = $limit > 0 ? $limit : 200;
		$allowed_formats_sql = wrio_get_allowed_formats( true );
		$allowed_sizes       = explode( ',', WRIO_Plugin::app()->getPopulateOption( 'allowed_sizes_thumbnail', wrio_get_default( 'allowed_sizes_thumbnail' ) ) );
		$excluded            = WRIO_Image_Query::get_instance()->get_exclusion_clause();

		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- formats list and prepared exclusion clause are built by the plugin.
			"SELECT posts.ID FROM {$wpdb->posts} as posts WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ({$allowed_formats_sql}){$excluded} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		// WPML: skip translated duplicates.
		if ( defined( 'WPML_PLUGIN_FILE' ) ) {
			$query = str_replace(
				'WHERE post_type =',
				"WHERE NOT EXISTS (
					SELECT icl.element_id FROM {$wpdb->prefix}icl_translations as icl
					WHERE icl.element_id = posts.ID AND icl.element_type = 'post_attachment' AND source_language_code IS NOT NULL
				) AND post_type =",
				$query
			);
		}

		$attachments = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_count = max( 0, $total );

		foreach ( $attachments as $attachment ) {
			$meta = wp_get_attachment_metadata( $attachment->ID );
			if ( $meta && isset( $meta['sizes'] ) ) {
				// PHP casts a numeric size name to an int key. The option holds strings.
				foreach ( array_map( 'strval', array_keys( $meta['sizes'] ) ) as $size_key ) {
					if ( in_array( $size_key, $allowed_sizes, true ) ) {
						++$total_count;
					}
				}
			}
		}

		if ( count( $attachments ) < $limit ) {
			return [
				'found_thumbs' => $total_count,
				'done'         => true,
			];
		}

		return [
			'found_thumbs' => $total_count,
			'done'         => false,
			'next_offset'  => $offset + $limit,
		];
	}

	/**
	 * Background processing (Pro) or WP cron, the same choice the constructor makes.
	 *
	 * @return bool
	 */
	protected static function runs_in_background() {
		return wrio_is_license_activate() && 'background' === WRIO_Plugin::app()->getPopulateOption( 'image_optimization_type', '' );
	}

	/**
	 * Error for a missing scope or parameter.
	 *
	 * @return WP_Error
	 */
	protected static function bad_request() {
		return new WP_Error( 'wrio_bad_request', __( 'Missing or invalid parameters.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
	}
}
