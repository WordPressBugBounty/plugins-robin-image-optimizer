<?php
/**
 * What a media library bulk run will do, shown before it starts.
 *
 * @package robin-image-optimizer
 */

use WRIO\Storage\Store;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts the outputs that a new bulk run will request, one page of attachments at a time. The app sums the pages.
 *
 * Each output of each file is counted once, from its state rows, as after the errors reset of a new Start (a
 * failure that is not permanent and not a plan limit is due, and a due retry stays due). The item is picked as the
 * bulk queries pick it (Queries, WRIO_Image_Query): the status of the main file's row decides, a pending file or a
 * due failure picks it for the optimization, and a conversion needs a done optimization. Then an output counts as
 * Store::plan() would request it. Nothing is written. An image whose main file is missing is not counted: the run
 * cannot process it.
 *
 * @phpstan-import-type Row from Store
 */
class WRIO_Bulk_Summary {

	/**
	 * Counts of one page of attachments.
	 *
	 * @param int   $after Last attachment ID of the previous page, 0 for the first page.
	 * @param int   $limit Attachments per page.
	 * @param mixed $from  Only images uploaded from this date, Y-m-d, as the run's date filter. '': no limit.
	 * @param mixed $to    Only images uploaded until this date, Y-m-d, both included. '': no limit.
	 *
	 * @return array{images: int, thumbnails: int, webp: int, avif: int, missing: int, bytes: int, next: int|null, saving_percent?: float|null}|WP_Error
	 *         An error while the old queue rows are still copied: the bulk queries read them then, not the state rows.
	 *
	 * images: images with work. thumbnails: their other sizes to optimize. webp, avif: files to convert. missing:
	 * images with work whose main file is missing. bytes: size of the files to optimize. next: the after value of
	 * the next page, null after the last page. saving_percent (last page only): the saving of the optimized files so
	 * far, null when no file is optimized yet.
	 */
	public static function step( $after, $limit, $from = '', $to = '' ) {
		if ( ! \WRIO\Storage\Backfill::reads() ) {
			return new WP_Error( 'wrio_summary_unavailable', __( 'The counts are available when the plugin has copied its optimization data. Try again in a few minutes.', 'robin-image-optimizer' ), [ 'status' => 409 ] );
		}

		$counts  = [
			'images'     => 0,
			'thumbnails' => 0,
			'webp'       => 0,
			'avif'       => 0,
			'missing'    => 0,
			'bytes'      => 0,
		];
		$limit   = max( 1, (int) $limit );
		$ids     = WRIO_Image_Query::get_instance()->get_ids_after( max( 0, (int) $after ), $limit, WRIO_Image_Query::valid_dates( $from, $to ) );
		$rows    = Store::rows_of_items( 'attachment', $ids );
		$formats = WRIO_Formats::get_enabled_formats();
		$convert = wrio_get_conversion_formats();

		// The posts and their meta of the whole page in two queries, not two per image.
		_prime_post_caches( $ids, false, true );

		foreach ( $ids as $id ) {
			$files = ( new WIO_Attachment( $id ) )->get_files();
			$file  = (string) get_attached_file( $id );

			// Without metadata the run makes it first, then optimizes the main file and the new sizes.
			if ( empty( $files ) && '' !== $file ) {
				$files = [
					'original' => [
						'path'     => $file,
						'optimize' => true,
					],
				];
			}

			$targets = in_array( get_post_mime_type( $id ), $convert, true ) ? $formats : [];
			$item    = $rows[ $id ] ?? [];
			$work    = [];
			$picked  = [ 'optimized' => self::pending( $item, 'optimized' ) ];

			foreach ( $item as $row ) {
				$picked['optimized'] = $picked['optimized'] || Store::PENDING === $row['status'] || ( 'optimized' === $row['format'] && self::due( $row ) );
			}

			foreach ( $targets as $format ) {
				$picked[ $format ] = $picked['optimized'] || ( self::done( $item, 'optimized' ) && ( self::pending( $item, $format ) || [] !== array_filter(
					$item,
					function ( $row ) use ( $format ) {
						return $format === $row['format'] && self::due( $row );
					}
				) ) );
			}

			foreach ( $files as $size => $file ) {
				foreach ( array_merge( $file['optimize'] ? [ 'optimized' ] : [], $targets ) as $output ) {
					if ( ( $picked[ $output ] ?? false ) && self::requested( $item[ $size . '|' . $output ] ?? null, $file['path'] ) ) {
						$work[ $size ][] = $output;
					}
				}
			}

			if ( empty( $work ) ) {
				continue;
			}

			if ( ! isset( $files['original'] ) || ! is_file( $files['original']['path'] ) ) {
				++$counts['missing'];
				continue;
			}

			++$counts['images'];

			foreach ( $work as $size => $outputs ) {
				foreach ( $outputs as $output ) {
					if ( 'optimized' !== $output ) {
						++$counts[ $output ];
					} elseif ( 'original' !== $size ) {
						++$counts['thumbnails'];
					}
				}

				if ( in_array( 'optimized', $outputs, true ) && is_file( $files[ $size ]['path'] ) ) {
					$counts['bytes'] += (int) filesize( $files[ $size ]['path'] );
				}
			}
		}

		if ( count( $ids ) === $limit ) {
			$counts['next'] = (int) end( $ids );
		} else {
			$counts['next']           = null;
			$counts['saving_percent'] = self::saving_percent();
		}

		return $counts;
	}

	/**
	 * Whether a new Start requests an output of a picked item, from its row. Read-only: Store::plan() decides the
	 * same, but writes.
	 *
	 * @param Row|null $row  State row of the output, null without one.
	 * @param string   $path Source file.
	 *
	 * @return bool
	 */
	private static function requested( $row, $path ) {
		$state = Store::state( $row, $path );

		if ( in_array( $state, [ Store::OK, Store::SKIPPED ], true ) ) {
			return false;
		}

		return ! $row || Store::FAILED !== $state || self::due( $row );
	}

	/**
	 * The output of the main file is done for the bulk queries: its row is ok or skipped (Queries::done()). A
	 * stale file does not count: the queries see only the status.
	 *
	 * @param array<string, Row> $item   Rows of the item.
	 * @param string             $output optimized, webp or avif.
	 *
	 * @return bool
	 */
	private static function done( array $item, $output ) {
		return in_array( $item[ 'original|' . $output ]['status'] ?? '', [ Store::OK, Store::SKIPPED ], true );
	}

	/**
	 * The output of the main file is pending for the bulk queries: not done, and not a failure that waits
	 * (Queries::pending()).
	 *
	 * @param array<string, Row> $item   Rows of the item.
	 * @param string             $output optimized, webp or avif.
	 *
	 * @return bool
	 */
	private static function pending( array $item, $output ) {
		$row = $item[ 'original|' . $output ] ?? null;

		return ! self::done( $item, $output ) && ( ! $row || ! in_array( $row['status'], [ Store::FAILED, Store::MISSING ], true ) || self::due( $row ) );
	}

	/**
	 * A failure that is due after the errors reset of a new Start: its retry time passed (also a plan limit after
	 * a plan change), or the reset makes it due (not permanent, not a plan limit, see Store::errors_reset()). A failure
	 * of the file whose retry time is still to come waits for it.
	 *
	 * @param Row $row State row.
	 *
	 * @return bool
	 */
	private static function due( $row ) {
		if ( ! in_array( $row['status'], [ Store::FAILED, Store::MISSING ], true ) || 0 !== (int) $row['permanent'] ) {
			return false;
		}

		if ( ! empty( $row['next_attempt_at'] ) && $row['next_attempt_at'] <= gmdate( 'Y-m-d H:i:s' ) ) {
			return true;
		}

		if ( ! empty( $row['next_attempt_at'] ) && ! Store::is_start_code( (string) $row['code'] ) ) {
			return false;
		}

		return ! in_array( $row['code'], Store::PLAN_CODES, true );
	}

	/**
	 * Saving of the optimized main files and sizes so far, as a percentage of their original size.
	 *
	 * @return float|null Null when no file is optimized yet.
	 */
	public static function saving_percent() {
		$sizes = \WRIO\Storage\Queries::sum_sizes( 'attachment', 'optimized' );

		if ( $sizes['original_size'] <= 0 ) {
			return null;
		}

		return round( max( 0, $sizes['original_size'] - $sizes['final_size'] ) * 100 / $sizes['original_size'], 1 );
	}
}
