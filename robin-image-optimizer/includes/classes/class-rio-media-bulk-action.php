<?php
/**
 * "Optimize with Robin" in the bulk actions of the Media Library list.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues the selected attachments and optimizes them in WP-Cron, a few per event, until the queue is empty.
 *
 * The queue is one post meta row per attachment (QUEUE_META, the time it was queued), so a request that adds
 * images and a worker that takes them never write over each other.
 */
class WRIO_Media_Bulk_Action {

	/**
	 * Bulk action name.
	 */
	const ACTION = 'wrio_optimize';

	/**
	 * Post meta of a queued attachment: the time it was queued.
	 */
	const QUEUE_META = '_wrio_bulk_queued';

	/**
	 * Cron hook of the worker.
	 */
	const CRON_HOOK = 'wrio_optimize_selection';

	/**
	 * Images per cron event at most.
	 */
	const BATCH = 5;

	/**
	 * Seconds after which the worker starts again when an event ended with a fatal error.
	 */
	const RECOVERY = 600;

	/**
	 * Hook the action, its handler, the notice and the worker.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'bulk_actions-upload', [ __CLASS__, 'add_action' ] );
		add_filter( 'handle_bulk_actions-upload', [ __CLASS__, 'handle' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
		add_filter( 'removable_query_args', [ __CLASS__, 'removable_query_args' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'work' ] );
	}

	/**
	 * Add the action to the menu, for the users who can optimize.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public static function add_action( $actions ) {
		if ( current_user_can( 'manage_options' ) ) {
			$actions[ self::ACTION ] = __( 'Optimize with Robin', 'robin-image-optimizer' );
		}

		return $actions;
	}

	/**
	 * Queue the selected images and start the worker. WordPress checked the nonce of the list.
	 *
	 * @param string       $redirect URL to go back to.
	 * @param string       $action   Selected bulk action.
	 * @param array<mixed> $ids   Selected attachment IDs.
	 *
	 * @return string
	 */
	public static function handle( $redirect, $action, $ids ) {
		if ( self::ACTION !== $action || ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}

		$allowed = (array) wrio_get_allowed_formats();
		$ids     = array_values(
			array_filter(
				array_map( 'absint', (array) $ids ),
				function ( $id ) use ( $allowed ) {
					return in_array( get_post_mime_type( $id ), $allowed, true );
				}
			)
		);

		self::queue( $ids );

		return add_query_arg( 'wrio_queued', count( $ids ), $redirect );
	}

	/**
	 * Add IDs to the queue and schedule the worker.
	 *
	 * @param int[] $ids Attachment IDs.
	 *
	 * @return void
	 */
	public static function queue( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			// A queued image keeps its place.
			add_post_meta( $id, self::QUEUE_META, time(), true );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * The IDs that wait, oldest first.
	 *
	 * @param int $limit Number of IDs at most, 0 for all.
	 *
	 * @return int[]
	 */
	public static function queued( $limit = 0 ) {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY CAST( meta_value AS UNSIGNED ) ASC, post_id ASC", self::QUEUE_META );

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		return array_map( 'absint', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; the queue changes between reads.
	}

	/**
	 * Optimize the next images of the queue, then schedule the next event while some wait.
	 *
	 * @return void
	 */
	public static function work() {
		if ( self::wait() ) {
			return;
		}

		// WP-Cron removed this event before it called the worker. If an image ends the request with a fatal error,
		// this one starts the worker again.
		wp_schedule_single_event( time() + self::RECOVERY, self::CRON_HOOK );

		$media_library = WRIO_Media_Library::get_instance();
		$done          = 0;

		foreach ( self::queued( self::BATCH ) as $id ) {
			if ( ! wrio_has_time_left() ) {
				break;
			}

			if ( 'attachment' !== get_post_type( $id ) ) {
				delete_post_meta( $id, self::QUEUE_META );
				continue;
			}

			// Another process (an upload, a restore) has the image: it stays queued for a later event. The
			// optimization takes the same lock again, which the lock allows in one request.
			if ( ! \WRIO\Storage\Store::lock( 'attachment', $id ) ) {
				continue;
			}

			try {
				$queued = get_post_meta( $id, self::QUEUE_META, true );

				// Off the queue first: an image that ends the request with a fatal error is not picked for ever.
				if ( ! delete_post_meta( $id, self::QUEUE_META ) ) {
					continue;
				}

				++$done;

				// The user asked for these images: send failures again too, as the Optimize button of the column does.
				\WRIO\Storage\Store::retry_now( 'attachment', $id );
				\WRIO\V2\Client::$trigger = 'manual';
				$media_library->optimizeAttachment( $id );

				// A 429 or any 5xx during this image: the API sent nothing after it. The image goes back to its place.
				if ( \WRIO\V2\Client::backoff_seconds() > 0 ) {
					add_post_meta( $id, self::QUEUE_META, $queued, true );
					self::wait();

					return;
				}
			} finally {
				\WRIO\Storage\Store::unlock( 'attachment', $id );
			}
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( ! empty( self::queued( 1 ) ) ) {
			// Only locked images were left: do not start again on every page load while their lock lasts.
			wp_schedule_single_event( time() + ( $done > 0 ? 0 : MINUTE_IN_SECONDS ), self::CRON_HOOK );
		}
	}

	/**
	 * Start the worker again after an activation, for the images queued before a deactivation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! empty( self::queued( 1 ) ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the worker after the backoff of the API, if there is one.
	 *
	 * @return bool Whether the API asked the site to wait (a 429 or any 5xx).
	 */
	private static function wait() {
		$wait = \WRIO\V2\Client::backoff_seconds();

		if ( $wait <= 0 ) {
			return false;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + $wait, self::CRON_HOOK );

		return true;
	}

	/**
	 * Say how many images were queued, after the redirect of the bulk action.
	 *
	 * @return void
	 */
	public static function notice() {
		$screen = get_current_screen();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count for a message, no action.
		if ( ! isset( $_GET['wrio_queued'] ) || ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$count = absint( $_GET['wrio_queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count for a message, no action.
		$text  = $count > 0
			/* translators: %d: number of images. */
			? sprintf( _n( '%d image was added to the Robin optimization queue. It is optimized in the background.', '%d images were added to the Robin optimization queue. They are optimized in the background.', $count, 'robin-image-optimizer' ), $count )
			: __( 'No selected file can be optimized. Robin optimizes the image formats that are enabled in its settings.', 'robin-image-optimizer' );

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', $count > 0 ? 'success' : 'warning', esc_html( $text ) );
	}

	/**
	 * Remove the count from the URL after the notice.
	 *
	 * @param string[] $args Query args.
	 *
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args[] = 'wrio_queued';

		return $args;
	}
}
