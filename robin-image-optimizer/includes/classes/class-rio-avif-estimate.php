<?php
/**
 * The AVIF saving of a free site, measured on a few of its own images with a dry run of the API.
 *
 * @package robin-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends 6 random originals to POST /optimize with dry_run=1 and keeps how much smaller AVIF is than WebP.
 *
 * Only original bytes go to the server: the backup of an optimized image, or the file of an image that is not
 * optimized yet. An optimized image without a backup is skipped, because its compressed file would give a wrong number.
 */
class WRIO_Avif_Estimate {

	/**
	 * Option with the last result: percent (null when nothing was measured), images and at (time).
	 */
	const OPTION = 'wrio_avif_estimate';

	/**
	 * WP-Cron event that measures the images.
	 */
	const EVENT = 'wrio_avif_estimate';

	/**
	 * Images in one measure. The server allows 30 dry runs per day per site.
	 */
	const SAMPLE = 6;

	/**
	 * A result is used this long. A run that measured nothing is tried again after a day.
	 */
	const TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Hook the measure to its event.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::EVENT, [ __CLASS__, 'run' ] );
	}

	/**
	 * The last result, and a measure in the background when it is missing or old.
	 *
	 * @return array{percent: int|null, images: int}|null Null until the first measure ended.
	 */
	public static function get() {
		$estimate = get_option( self::OPTION );
		$valid    = is_array( $estimate ) && isset( $estimate['at'] );
		$age      = $valid ? time() - (int) $estimate['at'] : PHP_INT_MAX;

		if ( $age > ( $valid && null === $estimate['percent'] ? DAY_IN_SECONDS : self::TTL ) && ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time(), self::EVENT );
		}

		if ( ! $valid ) {
			return null;
		}

		return [
			'percent' => null === $estimate['percent'] ? null : (int) $estimate['percent'],
			'images'  => (int) $estimate['images'],
		];
	}

	/**
	 * Measure WebP and AVIF on the sample and save how much smaller AVIF is.
	 *
	 * @return void
	 */
	public static function run() {
		$client  = \WRIO\V2\Client::from_settings();
		$options = ( new \WRIO\V2\Optimizer() )->options( null );
		$webp    = 0;
		$avif    = 0;
		$images  = 0;

		foreach ( self::sample() as $path ) {
			$sizes = $client->measure( $path, [ 'webp', 'avif' ], $options );

			// No time left in this request: keep the last result, the next request measures again.
			if ( is_wp_error( $sizes ) && 'wrio_v2_time_limit' === $sizes->get_error_code() && 0 === $images ) {
				return;
			}

			// Over the daily cap, or the server is down: the next images get the same answer.
			if ( is_wp_error( $sizes ) ) {
				break;
			}

			// An image where one format was skipped (not_smaller, same_format) cannot compare the two.
			if ( isset( $sizes['outputs']['webp'], $sizes['outputs']['avif'] ) ) {
				$webp += $sizes['outputs']['webp'];
				$avif += $sizes['outputs']['avif'];
				++$images;
			}
		}

		update_option(
			self::OPTION,
			[
				'percent' => $webp > 0 ? (int) round( ( 1 - $avif / $webp ) * 100 ) : null,
				'images'  => $images,
				'at'      => time(),
			],
			false
		);
	}

	/**
	 * Paths of up to SAMPLE random originals of JPEG and PNG images.
	 *
	 * @return string[]
	 */
	public static function sample() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a random pick, not worth a cache.
		$ids    = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png') ORDER BY RAND() LIMIT 50" );
		$backup = new WIO_Backup();
		$paths  = [];

		foreach ( $ids as $id ) {
			$path = ( new WIO_Attachment( (int) $id ) )->isOptimized()
				? $backup->getAttachmentBackupPath( (int) $id )
				: wrio_file_path( get_attached_file( (int) $id ) );

			if ( $path && is_readable( $path ) ) {
				$paths[] = $path;
			}

			if ( count( $paths ) >= self::SAMPLE ) {
				break;
			}
		}

		return $paths;
	}
}
