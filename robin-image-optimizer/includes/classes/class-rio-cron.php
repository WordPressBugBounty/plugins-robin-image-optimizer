<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы оптимизации по расписанию
 *
 * @version       1.0
 */
class WRIO_Cron {

	/**
	 * The one-time event of the WP-Cron test of Background optimization (#244).
	 */
	const PROBE_HOOK = 'wrio/cron/probe';

	/**
	 * The wrio/cron/* hooks that this version handles. Any other wrio/cron/* event is left by an older version, for
	 * example the conversion_process and avif_conversion_process events of 2.0.
	 */
	const HOOKS = [ 'wrio/cron/optimization_process', 'wrio/cron/background_test', self::PROBE_HOOK ];

	/**
	 * Инициализация оптимизации по расписанию
	 */
	public function __construct() {
		$this->initHooks();
	}

	/**
	 * Подключение хуков
	 */
	public function initHooks() {
		add_action( 'wrio/cron/optimization_process', [ $this, 'optimization_process' ], 10, 1 );
		add_action( 'wrio/cron/background_test', [ __CLASS__, 'background_test' ] );
		add_action( self::PROBE_HOOK, [ __CLASS__, 'probe' ] );
		// The events of 2.0 that nothing handles now: the first one that runs removes them all.
		add_action( 'wrio/cron/conversion_process', [ __CLASS__, 'clear_old_events' ] );
		add_action( 'wrio/cron/avif_conversion_process', [ __CLASS__, 'clear_old_events' ] );
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- intervals come from self::schedules(), none below a minute.
		add_filter( 'cron_schedules', [ $this, 'intervals' ], 100, 1 );
	}

	/**
	 * The one list of optimization schedules: cron interval and the label the
	 * settings page shows. Add or change entries here only.
	 *
	 * WP cron can read the schedules before init (WooCommerce does), and a
	 * translation before init logs a "loaded too early" notice. Before init
	 * the labels stay in English.
	 *
	 * @return array<string, array{interval: int, label: string}>
	 */
	public static function schedules() {
		$translate = did_action( 'init' );

		return [
			'wio_1_min'  => [
				'interval' => MINUTE_IN_SECONDS,
				'label'    => $translate ? __( '1 min', 'robin-image-optimizer' ) : '1 min',
			],
			'wio_2_min'  => [
				'interval' => 2 * MINUTE_IN_SECONDS,
				'label'    => $translate ? __( '2 min', 'robin-image-optimizer' ) : '2 min',
			],
			'wio_5_min'  => [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'label'    => $translate ? __( '5 min', 'robin-image-optimizer' ) : '5 min',
			],
			'wio_10_min' => [
				'interval' => 10 * MINUTE_IN_SECONDS,
				'label'    => $translate ? __( '10 min', 'robin-image-optimizer' ) : '10 min',
			],
			'wio_30_min' => [
				'interval' => 30 * MINUTE_IN_SECONDS,
				'label'    => $translate ? __( '30 min', 'robin-image-optimizer' ) : '30 min',
			],
			'wio_hourly' => [
				'interval' => HOUR_IN_SECONDS,
				'label'    => $translate ? __( '1 hour', 'robin-image-optimizer' ) : '1 hour',
			],
			'wio_daily'  => [
				'interval' => DAY_IN_SECONDS,
				'label'    => $translate ? __( '1 day', 'robin-image-optimizer' ) : '1 day',
			],
		];
	}

	/**
	 * Registers the schedules with WP cron.
	 *
	 * @param array<string, array<string, mixed>> $intervals Registered intervals.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function intervals( $intervals ) {
		foreach ( self::schedules() as $key => $schedule ) {
			$intervals[ $key ] = [
				'interval' => $schedule['interval'],
				'display'  => $schedule['label'],
			];
		}

		return $intervals;
	}

	/**
	 * The monthly health test of Background optimization (#244). A failure shows a notice on the Robin pages.
	 *
	 * @return void
	 */
	public static function background_test() {
		if ( wrio_is_license_activate() && 'background' === WRIO_Plugin::app()->getPopulateOption( 'image_optimization_type', 'schedule' ) ) {
			wrio_test_background();
		}
	}

	/**
	 * Records that WP-Cron ran the test event of wrio_test_background().
	 *
	 * @return void
	 */
	public static function probe() {
		update_option( 'wrio_cron_probe', time(), false );
	}

	/**
	 * Removes every wrio/cron/* event that this version does not handle (#244). Nothing would ever remove it.
	 *
	 * @return void
	 */
	public static function clear_old_events() {
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'wrio/cron/' ) && ! in_array( $hook, self::HOOKS, true ) ) {
					wp_unschedule_hook( (string) $hook );
				}
			}
		}
	}

	/**
	 * Запуск Cron задачи
	 */
	public static function start_single( $attachment_id ) {
		wp_schedule_single_event( time() + 10, 'wrio/cron/optimization_process', [ $attachment_id ] );
	}

	/**
	 * During a backoff that a 429 or any 5xx started, optimize the attachment by cron after the wait.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool Whether a backoff runs.
	 *
	 * @phpstan-impure An optimization between two calls can start a backoff.
	 */
	public static function after_backoff( $attachment_id ) {
		$wait = \WRIO\V2\Client::backoff_seconds();

		if ( $wait > 0 && ! wp_next_scheduled( 'wrio/cron/optimization_process', [ $attachment_id ] ) ) {
			wp_schedule_single_event( time() + $wait, 'wrio/cron/optimization_process', [ $attachment_id ] );
		}

		return $wait > 0;
	}

	/**
	 * After a transient failure, optimize the attachment by cron at its next retry time (#243).
	 *
	 * The run that failed ends or goes on to the next image: without this event, no driver comes back for the item.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public static function schedule_retry( $attachment_id ) {
		$next = null;

		foreach ( \WRIO\Storage\Store::rows( 'attachment', (int) $attachment_id ) as $row ) {
			if ( \WRIO\Storage\Store::FAILED === $row['status'] && '1' !== $row['permanent'] && null !== $row['next_attempt_at'] && ( null === $next || $row['next_attempt_at'] < $next ) ) {
				$next = $row['next_attempt_at'];
			}
		}

		if ( null !== $next && ! wp_next_scheduled( 'wrio/cron/optimization_process', [ $attachment_id ] ) ) {
			wp_schedule_single_event( max( time(), (int) strtotime( $next . ' UTC' ) ), 'wrio/cron/optimization_process', [ $attachment_id ] );
		}
	}

	/**
	 * Запуск Cron задачи
	 */
	public static function start( $type = 'optimization' ) {
		$interval = WRIO_Plugin::app()->getPopulateOption( 'image_autooptimize_shedule_time', 'wio_5_min' );
		if ( ! wp_next_scheduled( "wrio/cron/{$type}_process" ) ) {
			wp_schedule_event( time(), $interval, "wrio/cron/{$type}_process" );
		}
	}

	/**
	 * Ends the scheduled run: removes the recurring event and the saved scope together, so no event wakes the site
	 * when no work waits. The single-attachment events (with arguments) stay.
	 *
	 * @param string $type Cron type.
	 *
	 * @return void
	 */
	public static function stop( $type = 'optimization' ) {
		wp_clear_scheduled_hook( "wrio/cron/{$type}_process" );
		WRIO_Plugin::app()->deletePopulateOption( 'cron_running' );
		// The option of older versions.
		WRIO_Plugin::app()->deletePopulateOption( "{$type}_cron_running" );
	}

	/**
	 * Stops the cron job when its saved scope cannot be processed.
	 *
	 * @param string $option Populate option holding the scope.
	 * @param string $type   Cron type, as used by self::stop().
	 * @param mixed  $scope  Scope that could not be processed. Any type, so it is
	 *                       never formatted with %s directly.
	 *
	 * @return void
	 */
	private function stop_unsupported_scope( $option, $type, $scope ) {
		WRIO_Plugin::app()->logger->warning(
			sprintf(
				'Cron job stopped. Unsupported scope: %s',
				is_string( $scope ) ? $scope : wp_json_encode( $scope )
			)
		);
		WRIO_Plugin::app()->deletePopulateOption( $option );
		self::stop( $type );
	}

	/**
	 * Optimizes images in a cron request: one attachment (upload) or one step of the scheduled run.
	 *
	 * @param int $attachment_id Attachment ID, or 0 for a step of the scheduled run.
	 *
	 * @return void
	 */
	public function optimization_process( $attachment_id = 0 ) {
		$this->run_step( $attachment_id );
	}

	/**
	 * The work of optimization_process(), with its result for wrio_run_queue().
	 *
	 * @param int $attachment_id Attachment ID, or 0 for a step of the scheduled run.
	 *
	 * @return array<string, mixed>|WP_Error|null The result of the step, null when no step ran.
	 */
	public function run_step( $attachment_id = 0 ) {
		// Other events of this cron request used the time: the next request runs the step (#169).
		if ( ! wrio_has_time_left() ) {
			if ( $attachment_id && ! wp_next_scheduled( 'wrio/cron/optimization_process', [ $attachment_id ] ) ) {
				self::start_single( $attachment_id );
			}

			return null;
		}

		// The API asked the site to wait (a 429 or any 5xx). Send nothing until the wait ends.
		if ( $attachment_id ? self::after_backoff( $attachment_id ) : \WRIO\V2\Client::backoff_seconds() > 0 ) {
			return null;
		}

		// Optimize single image via cron
		if ( $attachment_id ) {
			// Excluded after the upload scheduled the job.
			if ( WRIO_Media_Library::skips_queued( (int) $attachment_id ) ) {
				return null;
			}

			$media_library = WRIO_Media_Library::get_instance();

			// A bulk run optimized the upload before this job: optimizeAttachment() would restore it and send it again.
			if ( $media_library->getAttachment( $attachment_id )->isOptimized() && ! \WRIO\Storage\Store::has_work( 'attachment', (int) $attachment_id ) ) {
				delete_post_meta( $attachment_id, \WRIO\V2\Client::TRIGGER_META );

				return null;
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'START auto optimize cron job. Attachment: %s', $attachment_id ) );
			\WRIO\V2\Client::use_queued_trigger( $attachment_id );
			// The optimization also converts to the enabled formats.
			$media_library->optimizeAttachment( $attachment_id );
			// A 429 or any 5xx of this request: the same job after the wait.
			if ( ! self::after_backoff( $attachment_id ) ) {
				delete_post_meta( $attachment_id, \WRIO\V2\Client::TRIGGER_META );
			}

			// Other jobs of this WP-Cron request are bulk.
			\WRIO\V2\Client::$trigger = 'bulk';

			WRIO_Plugin::app()->logger->info( sprintf( 'END auto optimize cron job. Attachment: %s', $attachment_id ) );

			return null;
		}

		$max_process_per_request = WRIO_Plugin::app()->getPopulateOption( 'image_autooptimize_items_number_per_interation', 3 );
		$cron_running_page       = WRIO_Plugin::app()->getPopulateOption( 'cron_running', false );

		if ( ! $cron_running_page ) {
			// A recurring event without a run: remove it.
			self::stop();

			return null;
		}

		if ( ! is_string( $cron_running_page ) ) {
			$this->stop_unsupported_scope( 'cron_running', 'optimization', $cron_running_page );

			return null;
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'Start cron job. Scope: %s', $cron_running_page ) );

		$result = null;

		if ( 'media-library' == $cron_running_page ) {
			$media_library = WRIO_Media_Library::get_instance();
			$result        = $media_library->processUnoptimizedImages( $max_process_per_request );
		} elseif ( in_array( $cron_running_page, [ 'nextgen-gallery', 'nextgen' ], true ) && class_exists( 'WRIO_Nextgen_Gallery' ) ) {
			$nextgen_gallery = WRIO_Nextgen_Gallery::get_instance();
			$result          = $nextgen_gallery->processUnoptimizedImages( $max_process_per_request );
		} elseif ( 'custom-folders' === $cron_running_page && class_exists( 'WRIO_Custom_Folders' ) ) {
			$cf     = WRIO_Custom_Folders::get_instance();
			$result = $cf->processUnoptimizedImages( $max_process_per_request );
		} else {
			$this->stop_unsupported_scope( 'cron_running', 'optimization', $cron_running_page );

			return null;
		}

		if ( is_wp_error( $result ) ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Cron job failed. Error: %s', $result->get_error_message() ) );
			self::stop();

			return $result;
		}

		// The queue is empty: no event stays behind (#244).
		if ( is_array( $result ) && isset( $result['remain'] ) && $result['remain'] <= 0 ) {
			self::stop();
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'End cron job. Scope: %s', $cron_running_page ) );

		return $result;
	}
}
