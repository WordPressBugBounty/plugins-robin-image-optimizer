<?php
/**
 * Background queue base class for optimization and conversion.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background queue for optimization and conversion, based on WP Background Processing
 * (deliciousbrains/wp-background-processing) as the Factory processing module shipped it.
 *
 * The identifier is "wrio_{action}_{scope}", e.g. wrio_optimize_process_media-library.
 * Batches are stored in site options "{identifier}_batch_*", the lock in the site transient
 * "{identifier}_process_lock", and the health check runs on the "{identifier}_cron" event.
 * Priority batches (new uploads) use the key "{identifier}_batch_u*": "u" is not a hex digit,
 * so get_batch() can find them and return them before the bulk batches.
 * dispatch() starts a non-blocking admin-ajax request that handles the queue.
 */
abstract class WRIO_Processing {

	/**
	 * @var string
	 */
	protected $prefix = 'wrio';

	/**
	 * @var string
	 */
	protected $action = 'optimize_process';

	/**
	 * @var string
	 */
	public $scope;

	/**
	 * @var string
	 */
	protected $identifier;

	/**
	 * Items pushed but not yet saved.
	 *
	 * @var array<int|string, mixed>
	 */
	protected $data = [];

	/**
	 * Time the current batch started.
	 *
	 * @var int
	 */
	protected $start_time = 0;

	/**
	 * @var string
	 */
	protected $cron_hook_identifier;

	/**
	 * @var string
	 */
	protected $cron_interval_identifier;

	/**
	 * Sets up the queue for a scope.
	 *
	 * @param string $scope Queue scope, e.g. media-library or media-library_webp.
	 */
	public function __construct( $scope ) {
		$this->scope      = $scope;
		$this->action     = "{$this->action}_{$scope}";
		$this->identifier = $this->prefix . '_' . str_replace( '-', '_', $this->action );

		$this->cron_hook_identifier     = $this->identifier . '_cron';
		$this->cron_interval_identifier = $this->identifier . '_cron_interval';

		add_action( 'wp_ajax_' . $this->identifier, [ $this, 'maybe_handle' ] );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, [ $this, 'maybe_handle' ] );
		add_action( $this->cron_hook_identifier, [ $this, 'handle_cron_healthcheck' ] );
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- schedule_cron_healthcheck() sets a 5-minute default.
		add_filter( 'cron_schedules', [ $this, 'schedule_cron_healthcheck' ] );
	}

	/**
	 * Pushes the items to process onto the queue.
	 *
	 * @return int Number of queued items.
	 */
	abstract public function push_items();

	/**
	 * Processes one item.
	 *
	 * @param mixed $item Queue item.
	 *
	 * @return mixed False to remove the item from the queue, otherwise the item to keep.
	 */
	abstract protected function task( $item );

	/**
	 * Adds an item to the queue.
	 *
	 * @param mixed $data Queue item.
	 *
	 * @return $this
	 */
	public function push_to_queue( $data ) {
		$this->data[] = $data;

		return $this;
	}

	/**
	 * Number of items waiting to be saved.
	 *
	 * @return int
	 */
	public function count_queue() {
		return count( $this->data );
	}

	/**
	 * Saves the pushed items as a new batch.
	 *
	 * @param bool $priority True for a new upload: the next worker cycle takes the batch before the bulk batches.
	 *
	 * @return $this
	 */
	public function save( $priority = false ) {
		if ( ! empty( $this->data ) ) {
			$prefix = $this->identifier . ( $priority ? '_batch_u' : '_batch_' );

			update_site_option( substr( $prefix . md5( microtime() . wp_rand() ), 0, 64 ), $this->data );
		}

		return $this;
	}

	/**
	 * Schedules the health check and starts the queue with a non-blocking request.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function dispatch() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event( time(), $this->cron_interval_identifier, $this->cron_hook_identifier );
		}

		// The loopback request acts as the current user, and maybe_handle() refuses this user: the health check
		// starts the queue (a front-end upload by a subscriber, for example).
		if ( ! $this->can_handle() ) {
			return new WP_Error( 'wrio_loopback_skipped', 'The current user cannot start the queue; the cron health check starts it.' );
		}

		$query_args = apply_filters(
			$this->identifier . '_query_args',
			[
				'action' => $this->identifier,
				'scope'  => $this->scope,
				'nonce'  => wp_create_nonce( $this->identifier ),
			]
		);
		$url        = add_query_arg( $query_args, apply_filters( $this->identifier . '_query_url', admin_url( 'admin-ajax.php' ) ) );

		$args = apply_filters(
			$this->identifier . '_post_args',
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'body'      => $this->data,
				'cookies'   => $_COOKIE, // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- the loopback acts as the current user.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Deletes every batch of the queue and the health check event.
	 *
	 * @return void
	 */
	public function cancel_process() {
		global $wpdb;

		$table  = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$column = is_multisite() ? 'meta_key' : 'option_name';
		$key    = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE {$column} LIKE %s", $key ) );

		foreach ( $keys as $batch_key ) {
			delete_site_option( $batch_key );
		}

		wp_clear_scheduled_hook( $this->cron_hook_identifier );
	}

	/**
	 * Handles the queue in the admin-ajax request that dispatch() starts.
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		if ( $this->is_process_running() || $this->is_queue_empty() ) {
			wp_die();
		}

		check_ajax_referer( $this->identifier, 'nonce' );

		if ( ! $this->can_handle() ) {
			wp_die( -1, 403 );
		}

		$this->handle();

		wp_die();
	}

	/**
	 * Whether the current user can run the queue in the loopback request. The nonce alone lets every user that has
	 * one run it. A request without a user is the loopback of WP-Cron or of another request without a user: its nonce
	 * is the check.
	 *
	 * @return bool
	 */
	protected function can_handle() {
		return ! is_user_logged_in() || current_user_can( 'upload_files' );
	}

	/**
	 * Restarts a stalled queue from WP-Cron.
	 *
	 * The cron request handles batches itself, for 20 seconds at most, so a blocked loopback request does not stall
	 * the queue. Then a loopback request takes the rest, or the next health check does.
	 *
	 * @return void
	 */
	public function handle_cron_healthcheck() {
		if ( $this->is_process_running() ) {
			return;
		}

		if ( $this->is_queue_empty() ) {
			$this->clear_scheduled_event();
			return;
		}

		$this->finish( $this->run_batches() );
	}

	/**
	 * Handles every batch now, in this request, without a loopback request: `wp robin queue run`.
	 *
	 * @return bool True when the queue is empty.
	 */
	public function run_now() {
		while ( wrio_has_time_left() && ! $this->is_process_running() && ! $this->is_queue_empty() ) {
			// An item that another process holds: the health check retries it.
			if ( $this->run_batches() ) {
				break;
			}
		}

		if ( ! $this->is_queue_empty() ) {
			return false;
		}

		$this->clear_scheduled_event();
		$this->handle_after_complete();

		return true;
	}

	/**
	 * Adds the health check interval (5 minutes) to the cron schedules.
	 *
	 * @param array<string, array<string, mixed>> $schedules Cron schedules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schedule_cron_healthcheck( $schedules ) {
		$interval = apply_filters( $this->identifier . '_cron_interval', 5 );

		$schedules[ $this->identifier . '_cron_interval' ] = [
			'interval' => MINUTE_IN_SECONDS * $interval,
			// Before init a translation logs a "loaded too early" notice.
			// translators: %d is the number of minutes
			'display'  => sprintf( did_action( 'init' ) ? __( 'Every %d Minutes', 'robin-image-optimizer' ) : 'Every %d Minutes', $interval ),
		];

		return $schedules;
	}

	/**
	 * Handles the queue in the loopback request, then starts the next request or completes the queue.
	 *
	 * @return void
	 */
	protected function handle() {
		$this->finish( $this->run_batches() );

		wp_die();
	}

	/**
	 * The one runner of the queue, for the loopback request, the cron health check and WP-CLI: runs batches until
	 * the time or memory limit is reached.
	 *
	 * @return bool True when a task kept its item (another process holds its lock).
	 */
	protected function run_batches() {
		$this->handle_before();

		$this->lock_process();

		// A task that returns its item keeps it in the batch (another process holds its lock). The request then ends
		// after this batch, and the cron health check retries the batch later: a new request now would find the
		// same lock.
		// ponytail: the later batches wait until the item's lock is released or expires (LOCK_TTL, longer when the
		// holder renews it); a health check then retries it. Move a kept item to a new batch if that wait matters.
		$postponed = false;
		$cancelled = false;

		do {
			$batch = $this->get_batch();

			foreach ( $batch['data'] as $key => $value ) {
				// The cron request can have used its time on other events before this health check (#169).
				if ( ! wrio_has_time_left() ) {
					break;
				}

				// Pause or Stop deleted the batches while an image of this run was optimized: start no other image (#220).
				$cancelled = $this->is_cancelled( $batch['key'] );

				if ( $cancelled ) {
					break;
				}

				$task = $this->task( $value );

				if ( false !== $task ) {
					$batch['data'][ $key ] = $task;
					$postponed             = true;
				} else {
					unset( $batch['data'][ $key ] );
				}

				if ( $this->time_exceeded() || $this->memory_exceeded() ) {
					break;
				}
			}

			if ( $cancelled ) {
				break;
			}

			if ( empty( $batch['data'] ) ) {
				delete_site_option( $batch['key'] );
			} elseif ( ! $this->is_queue_empty() ) {
				update_site_option( $batch['key'], $batch['data'] );
			}
		} while ( ! $postponed && wrio_has_time_left() && ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() );

		delete_site_transient( $this->identifier . '_process_lock' );

		$this->handle_after();

		return $postponed;
	}

	/**
	 * After a run: wait for a held item, start the next request, or complete the queue.
	 *
	 * @param bool $postponed Whether a task kept its item.
	 *
	 * @return void
	 */
	protected function finish( $postponed ) {
		if ( $postponed ) {
			if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $this->cron_interval_identifier, $this->cron_hook_identifier );
			}
		} elseif ( ! $this->is_queue_empty() ) {
			$this->dispatch();
		} else {
			$this->clear_scheduled_event();
			$this->handle_after_complete();
		}
	}

	/**
	 * Fires before the queue is handled.
	 *
	 * @return void
	 */
	protected function handle_before() {
		WRIO_Plugin::app()->logger->info( 'START auto optimize process.' );
	}

	/**
	 * Fires after a request handled the queue.
	 *
	 * @return void
	 */
	protected function handle_after() {
		WRIO_Plugin::app()->logger->info( 'END auto optimize process.' );
	}

	/**
	 * Fires when the queue is empty.
	 *
	 * @return void
	 */
	protected function handle_after_complete() {
		WRIO_Plugin::app()->updatePopulateOption( 'process_running', false );
	}

	/**
	 * Whether the queue has no batches left.
	 *
	 * @return bool
	 */
	protected function is_queue_empty() {
		global $wpdb;

		$table  = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$column = is_multisite() ? 'meta_key' : 'option_name';
		$key    = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", $key ) );

		return ! ( $count > 0 );
	}

	/**
	 * Whether cancel_process() deleted a batch since this run read it. Reads the table, not the option cache: another
	 * request cancels.
	 *
	 * @param string $batch_key Option name of the batch.
	 *
	 * @return bool
	 */
	protected function is_cancelled( $batch_key ) {
		global $wpdb;

		$table  = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$column = is_multisite() ? 'meta_key' : 'option_name';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %s", $batch_key ) );
	}

	/**
	 * Whether another request holds the process lock.
	 *
	 * @return bool
	 */
	protected function is_process_running() {
		return (bool) get_site_transient( $this->identifier . '_process_lock' );
	}

	/**
	 * Locks the queue for 60 seconds so a second request does not handle it too.
	 *
	 * @return void
	 */
	protected function lock_process() {
		$this->start_time = time();

		set_site_transient( $this->identifier . '_process_lock', microtime(), apply_filters( $this->identifier . '_queue_lock_time', 60 ) );
	}

	/**
	 * The oldest priority batch, else the oldest batch.
	 *
	 * @return array{key: string, data: array<int|string, mixed>}
	 */
	protected function get_batch() {
		global $wpdb;

		$table        = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$column       = is_multisite() ? 'meta_key' : 'option_name';
		$key_column   = is_multisite() ? 'meta_id' : 'option_id';
		$value_column = is_multisite() ? 'meta_value' : 'option_value';
		$key          = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';
		$priority_key = $wpdb->esc_like( $this->identifier . '_batch_u' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} LIKE %s ORDER BY {$column} LIKE %s DESC, {$key_column} ASC LIMIT 1", $key, $priority_key ) );

		return [
			'key'  => $row->$column,
			'data' => maybe_unserialize( $row->$value_column ),
		];
	}

	/**
	 * True when 90% of the memory limit is used.
	 *
	 * @return bool
	 */
	protected function memory_exceeded() {
		$memory_limit = function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : '128M';

		if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
			$memory_limit = '32000M';
		}

		$exceeded = memory_get_usage( true ) >= wp_convert_hr_to_bytes( $memory_limit ) * 0.9;

		return apply_filters( $this->identifier . '_memory_exceeded', $exceeded );
	}

	/**
	 * True when the request ran for 20 seconds.
	 *
	 * @return bool
	 */
	protected function time_exceeded() {
		$finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 20 );

		return apply_filters( $this->identifier . '_time_exceeded', time() >= $finish );
	}

	/**
	 * Removes the healthcheck cron event.
	 *
	 * @return void
	 */
	protected function clear_scheduled_event() {
		$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
		}
	}
}
