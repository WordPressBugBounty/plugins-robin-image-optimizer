<?php
/**
 * Actions the admin app performs, shared by the REST routes and the admin-ajax handlers.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each method returns data for the response or a WP_Error with an HTTP status.
 */
class WRIO_Actions {

	/**
	 * Retry or ignore one failed image of the failures list.
	 *
	 * Retry makes its failures due now: the next bulk run optimizes it. Ignore excludes a Media Library image, as its
	 * "Exclude from optimization" option does, which also undoes it.
	 *
	 * @param string $scope  Bulk scope.
	 * @param int    $id     Item ID: the attachment, the custom folder queue row or the NextGEN image.
	 * @param string $action retry or ignore.
	 *
	 * @return array{done: string}|WP_Error
	 */
	public static function failure_action( $scope, $id, $action ) {
		$source = WRIO_App::get_statistics( $scope )::SOURCE;

		if ( $id <= 0 || ! in_array( $action, [ 'retry', 'ignore' ], true ) || ( 'ignore' === $action && 'attachment' !== $source ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'This image cannot be changed this way.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		if ( 'attachment' === $source && 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The image does not exist.', 'robin-image-optimizer' ), [ 'status' => 404 ] );
		}

		if ( 'ignore' === $action ) {
			update_post_meta( $id, '_wrio_exclude', '1' );
		} else {
			\WRIO\Storage\Store::retry_now( $source, $id );

			// Only an attachment ID is a post ID: a custom folder row or a NextGEN image has no post meta.
			if ( 'attachment' === $source ) {
				delete_post_meta( $id, WRIO_Image_Query::SKIP_META );
			}
		}

		WRIO_Image_Query::clear_cache();

		return [ 'done' => $action ];
	}

	/**
	 * Restore up to 25 backed-up images and report the progress.
	 *
	 * @param int[] $skip Attachments that failed in an earlier step of the run.
	 *
	 * @return array<string, mixed> "failed" lists the attachments that failed in this step.
	 */
	public static function restore_backup_step( array $skip = [] ) {
		$per_request = 25;
		$remain      = 0;
		$total       = 0;

		$filter_results = apply_filters( 'wbcr/rio/backup/restore_filter', $per_request ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( isset( $filter_results['remane'] ) ) {
			$remain += $filter_results['remane'];
		}
		if ( isset( $filter_results['total'] ) ) {
			$total += $filter_results['total'];
		}

		$media_library = WRIO_Media_Library::get_instance();
		$restored      = $media_library->restoreAllFromBackup( $per_request, $skip );
		$total        += $restored['total'];
		$remain       += $restored['remain'];

		$restored['total']   = $total;
		$restored['percent'] = $total > 0 ? 100 - ( $remain * 100 / $total ) : 0;
		if ( $remain <= 0 ) {
			$restored['end'] = true;
		}

		return $restored;
	}

	/**
	 * Delete the backup folder of the current site.
	 *
	 * @return array{cleared: bool}|WP_Error Error when files are left behind.
	 */
	public static function clear_backup() {
		$backup = WIO_Backup::get_instance();

		if ( ! $backup->removeBackupDir() ) {
			return new WP_Error(
				'wrio_backup_not_cleared',
				__( 'Some backup files could not be deleted. Check the permissions of the backup folder.', 'robin-image-optimizer' ),
				[ 'status' => 500 ]
			);
		}
		self::forget_backups();

		return [ 'cleared' => true ];
	}

	/**
	 * The files are gone, so no row may still say it has one. Otherwise the
	 * delete dialog counts them and the media library offers a restore that
	 * cannot work.
	 *
	 * @return void
	 */
	protected static function forget_backups() {
		global $wpdb;
		$table = RIO_Process_Queue::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the model.
		$wpdb->query( "UPDATE {$table} SET is_backed_up = 0 WHERE is_backed_up = 1" );
		\WRIO\Storage\Store::backups_deleted();
	}

	/**
	 * Activate, deactivate or unsubscribe the licence.
	 *
	 * @param string $action activate, deactivate or unsubscribe.
	 * @param string $key    Licence key for activate.
	 *
	 * @return array{message: string}|WP_Error
	 */
	public static function license( $action, $key ) {
		if ( ! class_exists( 'WRIO_License_Page_View' ) ) {
			require_once WRIO_PLUGIN_DIR . '/admin/includes/class-wrio-subscribe-widget.php';
			require_once WRIO_PLUGIN_DIR . '/admin/pages/class-rio-license.php';
		}
		$view = WRIO_License_Page_View::$instance ? WRIO_License_Page_View::$instance : new WRIO_License_Page_View();

		return $view->run_action( (string) $action, (string) $key );
	}

	/**
	 * Subscribe an email address to the plugin newsletter.
	 *
	 * @param string $email Email.
	 *
	 * @return array{message: string}|WP_Error
	 */
	public static function subscribe( $email ) {
		if ( ! class_exists( 'WRIO_Subscribe_Widget' ) ) {
			require_once WRIO_PLUGIN_DIR . '/admin/includes/class-wrio-subscribe-widget.php';
		}

		return ( new WRIO_Subscribe_Widget() )->subscribe( (string) $email );
	}

	/**
	 * Hide the AVIF upsell banner for the current user.
	 *
	 * @return array{dismissed: bool}
	 */
	public static function dismiss_avif_banner() {
		update_user_meta( get_current_user_id(), 'wrio_avif_banner_dismissed', 1 );

		return [ 'dismissed' => true ];
	}

	/**
	 * Hide a rail card for the current user. A milestone card comes back at the next milestone.
	 *
	 * The AVIF card has its own flag, so a user who closed the older AVIF banner sees it once. Closing the card also
	 * keeps the banner closed.
	 *
	 * @param string $notice milestone|review|avif.
	 *
	 * @return array{dismissed: bool}
	 */
	public static function dismiss_notice( $notice ) {
		$value = 'milestone' === $notice ? WRIO_App::reached_milestone( WRIO_Image_Statistic::get_instance()->get() ) : 1;
		update_user_meta( get_current_user_id(), 'wrio_' . $notice . '_dismissed', $value );

		if ( 'avif' === $notice ) {
			self::dismiss_avif_banner();
		}

		return [ 'dismissed' => true ];
	}

	/**
	 * Delete the plugin log files.
	 *
	 * @return array{message: string}|WP_Error
	 */
	public static function clear_logs() {
		if ( ! WRIO_Plugin::app()->logger->clean_up() ) {
			return new WP_Error( 'wrio_logs_not_cleared', __( 'Failed to clear logs. Please try again later.', 'robin-image-optimizer' ), [ 'status' => 500 ] );
		}

		return [ 'message' => __( 'Logs cleared successfully.', 'robin-image-optimizer' ) ];
	}
}
