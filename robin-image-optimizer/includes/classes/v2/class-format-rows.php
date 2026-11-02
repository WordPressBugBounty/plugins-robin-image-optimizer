<?php
/**
 * Queue rows of the converted formats.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\V2;

use RIO_Process_Queue;
use RIOP_WebP_Extra_Data;
use WP_Error;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue rows of the converted files: one row per source file and format (item_type webp or avif).
 *
 * Delivery finds the converted files on disk. The rows feed the statistics (sums of final_size),
 * the media library column and the bulk counts. A row is found by sha256(source URL|format).
 *
 * @phpstan-import-type FileSpec from Optimizer
 * @phpstan-import-type FileResult from Optimizer
 * @phpstan-import-type Source from \WRIO\Storage\Store
 * @phpstan-import-type Format from \WRIO\Storage\Store
 */
class Format_Rows {

	const FORMATS = [ 'webp', 'avif' ];

	/**
	 * Save the format results of an item's files.
	 *
	 * @param Source                             $convert_from Item type: attachment, cf_image or nextgen.
	 * @param int                                $object_id    Item ID. Only attachment format rows store it as object_id.
	 * @param array<string, FileSpec>            $files        Files by size name.
	 * @param array<string, FileResult|WP_Error> $results      Results of Optimizer::run().
	 * @param Format[]                           $formats      Requested formats.
	 *
	 * @return void
	 */
	public static function save( $convert_from, $object_id, array $files, array $results, array $formats ) {
		$level = \WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level', RIO_Process_Queue::LEVEL_NORMAL );

		foreach ( $files as $size => $file ) {
			if ( ! isset( $results[ $size ] ) ) {
				continue;
			}

			// A format that the run did not request can be in the results: Optimizer dropped its earlier file.
			foreach ( self::FORMATS as $format ) {
				$result = is_wp_error( $results[ $size ] ) ? $results[ $size ] : ( $results[ $size ][ $format ] ?? null );

				if ( null === $result || ( ! in_array( $format, $formats, true ) && ( is_wp_error( $result ) || empty( $result['recheck'] ) ) ) ) {
					continue;
				}

				$row       = self::find( $file['url'], $format );
				$converted = $file['path'] . '.' . $format;
				$extra     = new RIOP_WebP_Extra_Data(
					[
						'convert_from'        => $convert_from,
						'converted_from_size' => $size,
						'source_src'          => $file['url'],
						'source_path'         => $file['path'],
						'thumbnails_count'    => count( $files ) - 1,
					]
				);

				// The old reads look format rows up by object_id and item_type only: a custom-folder or NextGEN ID
				// would match an attachment with the same number. Only attachment rows carry the item ID.
				$row->object_id          = 'attachment' === $convert_from ? (int) $object_id : null;
				$row->item_type          = $format;
				$row->processing_level   = $level;
				$row->is_backed_up       = false;
				$row->original_size      = wrio_get_file_size( $file['path'] );
				$row->original_mime_type = is_file( $file['path'] ) ? (string) wp_get_image_mime( $file['path'] ) : '';
				$row->final_mime_type    = 'image/' . $format;
				$row->final_size         = 0;

				if ( is_wp_error( $result ) ) {
					$row->result_status = RIO_Process_Queue::STATUS_ERROR;
					// Keep the code and the permanent flag: reset_errors() keeps the row of a damaged file.
					$extra->set_failure( 'request', $result );
				} elseif ( 'ok' === $result['status'] ) {
					$row->result_status = RIO_Process_Queue::STATUS_SUCCESS;
					$row->final_size    = wrio_get_file_size( $converted );
					$extra->set_converted_src( $file['url'] . '.' . $format );
					$extra->set_converted_path( $converted );
				} elseif ( 'skipped' === $result['status'] && 'not_in_plan' === ( $result['reason'] ?? '' ) ) {
					// The plan can change (a renewed license), so this is an error that a later run tries again.
					$row->result_status = RIO_Process_Queue::STATUS_ERROR;
					// translators: %s is the format name, WEBP or AVIF.
					$extra->set_failure( 'not_in_plan', Client::error( 'not_in_plan', sprintf( __( 'Your plan does not include %s.', 'robin-image-optimizer' ), strtoupper( $format ) ), [ 'retryable' => true ] ) );
				} elseif ( 'skipped' === $result['status'] ) {
					// For example, the format is not smaller than the file. A skipped row is not converted again.
					$row->result_status = RIO_Process_Queue::STATUS_SKIP;
					$extra->set_error( 'skipped' );
					$extra->set_error_msg( (string) ( $result['reason'] ?? '' ) );
				} else {
					$row->result_status = RIO_Process_Queue::STATUS_ERROR;
					$code               = (string) ( $result['error']['code'] ?? 'processing_failed' );
					$extra->set_failure( 'processing', Client::error( $code, (string) ( $result['error']['detail'] ?? '' ), [ 'permanent' => in_array( $code, Client::PERMANENT_CODES, true ) ] ) );
				}

				$row->extra_data = $extra;

				// A row that was not stored is no success for the listeners.
				if ( $row->save() && RIO_Process_Queue::STATUS_SUCCESS === $row->result_status ) {
					/**
					 * Fires after a successful format conversion.
					 *
					 * @param RIO_Process_Queue $row    The format row.
					 * @param Format            $format webp or avif.
					 */
					do_action( 'wbcr/rio/format_conversion_success', $row, $format ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- existing hook name.

					if ( 'webp' === $format ) {
						do_action( 'wbcr/rio/webp_success', $row ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- existing hook name.
					}
				}
			}
		}
	}

	/**
	 * Delete the converted files and their rows, for example after a restore.
	 *
	 * @param array<string, FileSpec> $files Files by size name.
	 *
	 * @return void
	 */
	public static function delete( array $files ) {
		foreach ( $files as $file ) {
			foreach ( self::FORMATS as $format ) {
				if ( file_exists( $file['path'] . '.' . $format ) ) {
					wp_delete_file( $file['path'] . '.' . $format );
				}

				$row = RIO_Process_Queue::find_by_hash( RIO_Process_Queue::generate_item_hash( $file['url'] . '|' . $format ) );

				if ( ! $row ) {
					continue;
				}

				$row->delete();
			}
		}
	}

	/**
	 * Size of a file's converted format, or 0 when the format was not made.
	 *
	 * @param string $url    Source URL.
	 * @param Format $format webp or avif.
	 *
	 * @return int
	 */
	public static function final_size( $url, $format = 'webp' ) {
		$row = RIO_Process_Queue::find_by_hash( RIO_Process_Queue::generate_item_hash( $url . '|' . $format ) );

		return $row && RIO_Process_Queue::STATUS_SUCCESS === $row->get_result_status() ? (int) $row->get_final_size() : 0;
	}

	/**
	 * The row of a file and format, or a new row.
	 *
	 * @param string $url    Source URL.
	 * @param Format $format webp or avif.
	 *
	 * @return RIO_Process_Queue
	 */
	private static function find( $url, $format ) {
		$row = RIO_Process_Queue::find_by_hash( RIO_Process_Queue::generate_item_hash( $url . '|' . $format ) );

		if ( $row ) {
			return $row;
		}

		$row = new RIO_Process_Queue();
		$row->set_item_hash( $url . '|' . $format );

		return $row;
	}
}
