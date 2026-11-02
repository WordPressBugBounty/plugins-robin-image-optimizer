<?php
/**
 * Copy of the old queue rows into the state table.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\Storage;

use RIO_Process_Queue;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies the rows of rio_process_queue into wrio_files, without changing the old table.
 *
 * - It pages through the old rows by ID (keyset), 500 rows per batch, for 20 seconds per cron run, and keeps its
 *   cursor in an option, so it resumes after a stop.
 * - Every plugin update runs it again from the start: after a rollback, the older version wrote the old table
 *   only. It never replaces a row that exists (INSERT IGNORE), so it only fills gaps.
 * - An item that the copy did not reach yet is copied when the optimizer reads it (see item()).
 * - When the first full run ends, the reads switch to the new table (reads()).
 *
 * @phpstan-import-type Source from Store
 */
class Backfill {

	/**
	 * Option with the cursor, whether the run is done, and the plugin version it ran for.
	 */
	const OPTION = 'wrio_storage_backfill';

	/**
	 * Option that is set once a full copy ended: the queries and the statistics read the new table.
	 */
	const READS_OPTION = 'wrio_storage_reads';

	/**
	 * Cron hook of one batch run.
	 */
	const HOOK = 'wrio_storage_backfill';

	/**
	 * Old rows per query.
	 */
	const BATCH = 500;

	/**
	 * Seconds per cron run.
	 */
	const BUDGET = 20;

	/**
	 * Register the cron hook, and start a run after an update.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );

		$state = self::state();

		if ( self::build() !== $state['version'] ) {
			self::restart();

			return;
		}

		// PHP died during a run: WordPress removed the event before it ran, and nothing scheduled the next one.
		if ( ! $state['done'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Start the copy again from the first old row, for example after an update or an activation.
	 *
	 * @return void
	 */
	public static function restart() {
		update_option(
			self::OPTION,
			[
				'cursor'  => 0,
				'done'    => false,
				'version' => self::build(),
			],
			true
		);

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Continue the copy after its last row when the old table has newer rows (an older version wrote them).
	 *
	 * @return void
	 */
	public static function catch_up() {
		global $wpdb;

		$state = self::state();
		$table = RIO_Process_Queue::table_name();

		if ( $state['done'] && (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ) > $state['cursor'] ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
			update_option( self::OPTION, array_merge( $state, [ 'done' => false ] ), true );
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * The plugin version and the time of its main file. A reinstall of the same version (the way back after a
	 * rollback) writes new files, so the copy runs again for what the older version did.
	 *
	 * @return string
	 */
	private static function build() {
		return WRIO_PLUGIN_VERSION . '@' . (int) filemtime( WRIO_PLUGIN_FILE );
	}

	/**
	 * Whether the reads use the new table.
	 *
	 * @return bool
	 */
	public static function reads() {
		return (bool) get_option( self::READS_OPTION, false );
	}

	/**
	 * Whether the current run reached the last old row.
	 *
	 * @return bool
	 */
	public static function is_done() {
		return self::state()['done'];
	}

	/**
	 * Copy batches of old rows until the time budget ends, then schedule the next run.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		$state = self::state();

		if ( $state['done'] ) {
			return;
		}

		$table    = RIO_Process_Queue::table_name();
		$deadline = microtime( true ) + self::BUDGET;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id LIMIT %d", $state['cursor'], self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.

			// A database error is not the end of the table: try again later, from the same row.
			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );

				return;
			}

			$copied = 0;

			foreach ( $rows as $row ) {
				// A failed write: keep the cursor before this row, and try again later.
				if ( ! self::copy( $row ) ) {
					update_option( self::OPTION, $state, true );
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );

					return;
				}

				$state['cursor'] = (int) $row['id'];

				// Save the progress inside a slow batch, before the request can reach its time limit.
				++$copied;

				if ( 0 === $copied % 50 ) {
					update_option( self::OPTION, $state, true );
				}

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}

			$state['done'] = count( $rows ) < self::BATCH && count( $rows ) === $copied;
			update_option( self::OPTION, $state, true );
		} while ( ! $state['done'] && microtime( true ) < $deadline );

		if ( ! $state['done'] ) {
			wp_schedule_single_event( time(), self::HOOK );

			return;
		}

		update_option( self::READS_OPTION, 1, true );
		Store::log(
			[
				'action' => 'backfill',
				'status' => Store::OK,
				'detail' => (string) $state['cursor'],
			]
		);
	}

	/**
	 * Copy the old rows of one item: one that the run did not reach yet, or one that a writer of the old table
	 * changed after the run (an older version after a rollback, the post meta migration). Rows that exist stay.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return bool False when a write failed.
	 */
	public static function item( $source, $object_id ) {
		global $wpdb;

		$table = RIO_Process_Queue::table_name();
		$own   = 'cf_image' === $source ? 'id' : 'object_id';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ( {$own} = %d AND item_type = %s ) OR ( object_id = %d AND item_type IN ('webp', 'avif') )", $object_id, $source, $object_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, $own is one of two columns.

		$copied = true;

		foreach ( (array) $rows as $row ) {
			$copied = self::copy( $row ) && $copied;
		}

		return $copied;
	}

	/**
	 * Copy one old row into the state rows of its files.
	 *
	 * An item row (attachment, cf_image, nextgen) holds the sizes of the whole item, without the sizes of each file:
	 * the main file gets them, and the other files get ok rows without sizes. An old success row does not say which
	 * thumbnails were optimized, so every file on disk counts as optimized: a file is never compressed twice, and a
	 * size that was not optimized stays as it is, as with the old table.
	 *
	 * @param array<string, mixed> $row Old row.
	 *
	 * @return bool False when a write failed.
	 */
	private static function copy( array $row ) {
		$model  = new RIO_Process_Queue( $row );
		$status = (string) $model->get_result_status();
		$extra  = $model->get_extra_data();

		if ( ! in_array( $status, [ RIO_Process_Queue::STATUS_SUCCESS, RIO_Process_Queue::STATUS_ERROR, RIO_Process_Queue::STATUS_SKIP ], true ) ) {
			return true;
		}

		$code = $extra ? (string) $extra->get_error_code() : '';
		$type = $model->get_item_type();

		// An old failure without a code: "Failed in an earlier version", not "Error unknown".
		if ( RIO_Process_Queue::STATUS_ERROR === $status && '' === $code ) {
			$code = 'legacy_error';
		}

		if ( in_array( $type, [ 'webp', 'avif' ], true ) ) {
			// Without the item type that the format belongs to, a row would have no item.
			if ( ! $extra instanceof \RIOP_WebP_Extra_Data || ! $model->get_object_id() || '' === (string) $extra->get_source_path() || ! Store::is_source( $extra->get_convert_from() ) ) {
				return true;
			}

			$skip = RIO_Process_Queue::STATUS_SKIP === $status;

			return null !== Store::import(
				[
					'source'    => $extra->get_convert_from(),
					'object_id' => (int) $model->get_object_id(),
				],
				(string) $extra->get_converted_from_size(),
				$type,
				(string) $extra->get_source_path(),
				[
					'status'        => self::status( $status ),
					'code'          => $skip ? (string) $extra->get_error_msg() : $code,
					'original_size' => (int) $model->get_original_size(),
					'final_size'    => (int) $model->get_final_size(),
					'mime_type'     => (string) $model->get_original_mime_type(),
					'level'         => (string) $model->get_processing_level(),
				]
			);
		}

		// An item type without files, such as a row of an old addon: nothing to copy.
		if ( ! Store::is_source( $type ) ) {
			return true;
		}

		$item   = [
			'source'    => $type,
			'object_id' => 'cf_image' === $type ? (int) $model->get_id() : (int) $model->get_object_id(),
			'level'     => (string) $model->get_processing_level(),
			'backed_up' => (bool) $model->get_is_backed_up(),
		];
		$files  = self::files( $item['source'], $item['object_id'], $model );
		$copied = false;
		$code   = RIO_Process_Queue::STATUS_ERROR === $status ? $code : '';

		foreach ( $files as $size => $file ) {
			$main = 'original' === $size;

			// A failure belongs to the main file: the queue row does not say which file failed. The rows of the v2
			// pipeline say which sizes it optimized: a size that it only converted gets no row here.
			if ( ! $main && ( RIO_Process_Queue::STATUS_SUCCESS !== $status || self::has_v2_rows( $item ) ) ) {
				continue;
			}

			$result = Store::import(
				$item,
				(string) $size,
				'optimized',
				$file['path'],
				[
					'status'        => self::status( $status ),
					'code'          => $code,
					'original_size' => $main ? (int) $model->get_original_size() : 0,
					'final_size'    => $main ? (int) $model->get_final_size() : 0,
					'mime_type'     => (string) $model->get_original_mime_type(),
				]
			);

			if ( null === $result ) {
				return false;
			}

			// A new main row: the copy reached the item for the first time. A size row alone (a size that a later
			// copy found) is no new history, and an import row would hide the item's newer attempts.
			$copied = $copied || ( $main && $result );
		}

		// The old row is the history of the item: the log list shows it. Once, when its main row was new, or when an
		// earlier copy saved the row but not its history row (PHP stopped between the two).
		if ( $copied || ( isset( $files['original'] ) && Store::only_imported( $item['source'], $item['object_id'] ) ) ) {
			Store::log(
				[
					'action'        => 'import',
					'source'        => $item['source'],
					'object_id'     => $item['object_id'],
					'size_name'     => 'original',
					'status'        => self::status( $status ),
					'original_size' => (int) $model->get_original_size(),
					'outputs'       => (string) wp_json_encode(
						[
							'optimized' => array_filter(
								[
									'status' => self::status( $status ),
									'code'   => $code,
									'size'   => (int) $model->get_final_size(),
								]
							),
						]
					),
					'detail'        => (string) $model->get_id(),
				]
			);
		}

		return true;
	}

	/**
	 * The files of an old item row, by size name.
	 *
	 * @param Source            $source    attachment, cf_image or nextgen.
	 * @param int               $object_id Item ID.
	 * @param RIO_Process_Queue $model     The old row.
	 *
	 * @return array<string, array{path: string}>
	 */
	private static function files( $source, $object_id, RIO_Process_Queue $model ) {
		try {
			if ( 'attachment' === $source ) {
				return ( new \WIO_Attachment( $object_id ) )->get_files( true );
			}

			if ( 'cf_image' === $source && class_exists( 'WRIO_Folder_Image' ) ) {
				return ( new \WRIO_Folder_Image( $object_id, $model ) )->get_files();
			}

			if ( 'nextgen' === $source && class_exists( 'WRIO_Image_Nextgen' ) ) {
				return ( new \WRIO_Image_Nextgen( $object_id ) )->get_files();
			}
		} catch ( \Throwable $throwable ) {
			// A NextGEN image without its gallery, for example. Nothing to copy.
			unset( $throwable );
		}

		return [];
	}

	/**
	 * Whether the v2 pipeline wrote rows of an item.
	 *
	 * @param array{source: Source, object_id: int} $item The item.
	 *
	 * @return bool
	 */
	private static function has_v2_rows( array $item ) {
		return in_array( 0, array_map( 'intval', wp_list_pluck( Store::rows( $item['source'], $item['object_id'] ), 'legacy' ) ), true );
	}

	/**
	 * The state of an old status.
	 *
	 * @param string $status success, error or skip.
	 *
	 * @return 'ok'|'skipped'|'failed'
	 */
	private static function status( $status ) {
		$states = [
			RIO_Process_Queue::STATUS_SUCCESS => Store::OK,
			RIO_Process_Queue::STATUS_SKIP    => Store::SKIPPED,
		];

		return $states[ $status ] ?? Store::FAILED;
	}

	/**
	 * The state of the copy.
	 *
	 * @return array{cursor: int, done: bool, version: string}
	 */
	private static function state() {
		$state = (array) get_option( self::OPTION, [] );

		return [
			'cursor'  => (int) ( $state['cursor'] ?? 0 ),
			'done'    => ! empty( $state['done'] ),
			'version' => (string) ( $state['version'] ?? '' ),
		];
	}
}
