<?php
/**
 * The state machine of each file output, and its history.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\Storage;

use WP_Error;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only writer of the wrio_files and wrio_log tables.
 *
 * Each row of wrio_files is one output (optimized, webp, avif) of one file (a size of an item), and its status is
 * the state of a small state machine. Two states are not stored: "none" (no row) and "stale" (an ok or skipped row
 * whose source file changed since: its size or content is not the stored fingerprint, see source_changed()).
 *
 * Every change goes through transition() or forget(). forget() appends its history row to wrio_log; transition()
 * logs only a refused event or a failed write, and its callers (save_file(), share(), plan()) append the history row.
 *
 * The value sets of the state machine are defined here, once. Other classes import them with
 * `@phpstan-import-type Status from \WRIO\Storage\Store`.
 *
 * @phpstan-type Source 'attachment'|'cf_image'|'nextgen'
 * @phpstan-type Output 'optimized'|'webp'|'avif'
 * @phpstan-type Format 'webp'|'avif'
 * @phpstan-type Status 'ok'|'skipped'|'failed'|'missing'|'pending'
 * @phpstan-type State Status|'none'|'stale'
 * @phpstan-type Event key-of<self::TRANSITIONS>
 * @phpstan-type ForgetEvent 'restored'|'deleted'|'size_removed'|'not_processed'
 * @phpstan-type PendingEvent 'edited'|'replaced'|'format_lost'
 * @phpstan-type ItemFailure 'missing_file'|'no_metadata'|'backup_failed'|'path_denied'|'unexpected_error'|'crashed'|'memory_limit'
 * @phpstan-type Files array<string, array{path: string, url?: string, optimize?: bool, object_id?: int|null}>
 * @phpstan-type Fingerprint array{size: int<0, max>, mtime: int<0, max>, hash?: string, full_hash?: string}
 * @phpstan-type Row array{id: numeric-string, source: Source, object_id: numeric-string, size_name: string, format: Output, status: Status, path_hash: string, original_size: numeric-string, final_size: numeric-string, source_size: numeric-string, source_mtime: numeric-string, source_hash?: string, source_full_hash?: string, mime_type: string, level: string, backed_up: numeric-string, code: string, permanent: numeric-string, attempts: numeric-string, next_attempt_at: string|null, request_id: string, legacy: numeric-string, updated_at: string}
 * @phpstan-type Columns array<string, string|int|null>
 * @phpstan-type Item array{source: Source, object_id: int, level?: string, backed_up?: bool}
 * @phpstan-type Outcome array{status: 'ok'|'skipped'|'failed'|'missing', code: string, permanent: bool}
 */
class Store {

	const OK      = 'ok';
	const SKIPPED = 'skipped';
	const FAILED  = 'failed';
	const MISSING = 'missing';
	const PENDING = 'pending';
	const NONE    = 'none';
	const STALE   = 'stale';

	/**
	 * Bytes that content_hash() reads: the first and the last half.
	 */
	const HASHED_BYTES = 131072;

	/**
	 * The states from which an event may move a row. Events that delete rows (see forget()) are allowed from every state.
	 *
	 * - processed: an API result. The new state is ok, skipped or failed. A fresh ok or skipped row never takes it:
	 *   that would send an optimized file again and compress it twice.
	 * - file_missing: the source file is not on disk. An ok row stays ok, because its file can be offloaded.
	 *
	 * A pending row (see mark_pending()) is like no row, but it tells the bulk queries that the item has work.
	 */
	const TRANSITIONS = [
		'processed'    => [ self::NONE, self::PENDING, self::FAILED, self::MISSING, self::STALE ],
		'file_missing' => [ self::NONE, self::PENDING, self::FAILED, self::MISSING ],
	];

	/**
	 * Item types a row can belong to.
	 */
	const SOURCES = [ 'attachment', 'cf_image', 'nextgen' ];

	/**
	 * Outputs a row can hold.
	 */
	const OUTPUTS = [ 'optimized', 'webp', 'avif' ];

	/**
	 * Failures that come from the file itself. Sending the same file again cannot help.
	 */
	const PERMANENT_CODES = [ 'corrupt_image', 'unsupported_media_type' ];

	/**
	 * Failures that depend on the plan. A license change makes them pending again (see plan_changed()).
	 */
	const PLAN_CODES = [ 'file_too_large', 'image_too_large', 'not_in_plan' ];

	/**
	 * Failures of the connection or of the server: the automatic retries fix them without the user. The http_ codes
	 * are the statuses that the client sends again (see Client::RETRYABLE_STATUSES) without a problem code.
	 */
	const TRANSIENT_CODES = [ 'http_request_failed', 'internal_error', 'no_answer', 'invalid_response', 'download_failed', 'invalid_download', 'time_limit', 'http_0', 'http_408', 'http_500', 'http_502', 'http_504' ];

	/**
	 * Failures of the site, not of a file: no file row changes, and only the history keeps them.
	 */
	const SITE_CODES = [ 'invalid_license', 'rate_limited', 'server_busy' ];

	/**
	 * 5xx waits other than a 503 in a row after which a file gets the failure of the answer.
	 */
	const MAX_BUSY_WAITS = 3;

	/**
	 * Automatic retries of a failed output stop after this number of failures in a row.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Seconds before the next automatic retry, by the number of failures. The last value repeats.
	 */
	const RETRY_DELAYS = [ 600, 1800, 7200 ];

	/**
	 * Seconds after which the lock of an item can be taken over, for example after PHP died. One item can take
	 * minutes: each request has a 120 s timeout and up to 3 attempts, and CLI runs have no PHP time limit.
	 */
	const LOCK_TTL = 900;

	/**
	 * Processes that died with the lock of an item (a time limit, the memory limit), after which the item fails
	 * with the code "crashed". The first crash steps the settings down (see crashes()). The failure retries on the
	 * normal schedule (RETRY_DELAYS, MAX_ATTEMPTS), and each retry starts with the full settings again.
	 */
	const MAX_CRASHES = 2;

	/**
	 * Locks that this request holds, by option name, with the number of lock() calls not released yet.
	 *
	 * @var array<string, int>
	 */
	private static $held = [];

	/**
	 * The "expiry:token:crashes" value of each lock this request holds.
	 *
	 * @var array<string, string>
	 */
	private static $tokens = [];

	/**
	 * The run ID of this request.
	 *
	 * @var string|null
	 */
	private static $run_id = null;

	/**
	 * The rows of an item, keyed by "size_name|format".
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return array<string, Row>
	 */
	public static function rows( $source, $object_id ) {
		global $wpdb;

		$table = Schema::files_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s AND object_id = %d", $source, $object_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
		$keyed = [];

		foreach ( (array) $rows as $row ) {
			$keyed[ $row['size_name'] . '|' . $row['format'] ] = $row;
		}

		return $keyed;
	}

	/**
	 * The rows of some items in one query, by item ID, each one keyed as in rows().
	 *
	 * @param string $source     attachment, cf_image or nextgen.
	 * @param int[]  $object_ids Item IDs.
	 *
	 * @phpstan-param Source $source
	 *
	 * @return array<int, array<string, Row>>
	 */
	public static function rows_of_items( string $source, array $object_ids ): array {
		global $wpdb;

		$ids   = array_values( array_unique( array_map( 'intval', $object_ids ) ) );
		$keyed = array_fill_keys( $ids, [] );

		if ( empty( $ids ) ) {
			return $keyed;
		}

		$table = Schema::files_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $source ], $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- plugin table, one placeholder per ID.

		foreach ( (array) $rows as $row ) {
			$keyed[ (int) $row['object_id'] ][ $row['size_name'] . '|' . $row['format'] ] = $row;
		}

		return $keyed;
	}

	/**
	 * The rows of an item, after a copy of its old rows when it has no main optimization row.
	 *
	 * Without that row, the copy did not reach the item, or only the old table knows it (an optimization by an
	 * older version after a rollback). Its old rows say what is done. Format rows alone do not count: the main file
	 * can be optimized without a row for it yet.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return array<string, Row>|null Null when the copy failed: the rows can miss what the old rows say is done.
	 */
	private static function rows_or_import( $source, $object_id ) {
		$rows = self::rows( $source, $object_id );

		if ( ! isset( $rows['original|optimized'] ) ) {
			if ( ! Backfill::item( $source, $object_id ) ) {
				return null;
			}

			$rows = self::rows( $source, $object_id );
		}

		return $rows;
	}

	/**
	 * Whether the backup of a file is still the right one: the file is the optimized file of its state row.
	 *
	 * A new optimization of an item (for one changed size, or to rebuild a lost queue row) must not replace the
	 * backup of an optimized file with its compressed bytes. A file with other content than its row is a new
	 * original and gets a new backup. A file without a row keeps a backup that the item's queue row records (a run
	 * that stopped before any row, after the resize for example, changed the file already) or that the file can be
	 * the optimized copy of.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 * @param string $size      Size name.
	 * @param string $path      File path.
	 * @param string $backup    Backup file path.
	 * @param bool   $recorded  The item's queue row says that it is backed up.
	 *
	 * @return bool
	 */
	public static function keeps_backup( $source, $object_id, $size, $path, $backup, $recorded = false ) {
		// Before the backup is replaced: an item that only the old table knows has its optimized files on disk.
		$rows    = self::rows_or_import( $source, (int) $object_id );
		$on_disk = is_file( $backup ) && 0 < (int) filesize( $backup );

		// Without the copy of the old rows, the file can be an optimized one: its backup stays.
		if ( null === $rows ) {
			return $on_disk;
		}

		$row = $rows[ $size . '|optimized' ] ?? null;

		// Two size names can share one file. Its row is under one of them (see WIO_Attachment::get_files()).
		foreach ( $row ? [] : $rows as $other ) {
			if ( 'optimized' === $other['format'] && self::path_hash( $path ) === $other['path_hash'] ) {
				$row = $other;
				break;
			}
		}

		// No row (the tables were dropped, or the rows forgotten while the file stayed optimized, for example by the
		// image editor's "Restore image"): the file can be the optimized copy of its backup, when it shows the same
		// picture. When unsure, the backup stays: a replaced backup loses the original for good.
		if ( ! $row ) {
			$same  = $on_disk && ( $recorded || self::can_be_output_of( $path, $backup ) ) ? self::same_picture( $path, $backup ) : false;
			$keeps = false !== $same;

			// Only a file that was read and matched is the optimized copy: a damaged one is sent, and fails.
			if ( $keeps && true === $same ) {
				self::adopt( $source, (int) $object_id, $size, $path, $backup );
			}

			return $keeps;
		}

		// A row without an optimized file (a failure, for example) does not change the file: a recorded backup is
		// still the original, also after a resize.
		if ( self::OK !== $row['status'] && $recorded && $on_disk ) {
			return true;
		}

		// An unchanged optimized file is never an original, also without a backup (backups turned on after the
		// optimization): the caller makes no copy.
		if ( self::OK === $row['status'] && ! self::source_changed( $row, $path ) ) {
			return true;
		}

		return is_file( $backup ) && ! self::source_changed( $row, $path );
	}

	/**
	 * Whether a file can be the optimized (and maybe resized) copy of a backup: it is not bigger, and the image has
	 * the same shape and is not larger. A file whose dimensions cannot be read can be.
	 *
	 * @param string $path   File path.
	 * @param string $backup Backup file path.
	 *
	 * @return bool
	 */
	private static function can_be_output_of( string $path, string $backup ): bool {
		if ( self::fingerprint( $path )['size'] > self::fingerprint( $backup )['size'] ) {
			return false;
		}

		$file     = wp_getimagesize( $path );
		$original = wp_getimagesize( $backup );

		if ( ! $file || ! $original || $file[0] < 1 || $file[1] < 1 || $original[1] < 1 ) {
			return true;
		}

		// 2% for the rounding of a resize. Another image of the very same shape is not detected.
		return $file[0] <= $original[0] && $file[1] <= $original[1] && abs( $file[0] / $file[1] - $original[0] / $original[1] ) <= 0.02 * $original[0] / $original[1];
	}

	/**
	 * Whether two images show the same picture: the red, green and blue values of their 16x16 thumbnails differ by
	 * at most 8 of 255 on average. A compressed or resized copy does; another image of the same shape does not, also
	 * one of the same brightness in other colors. Null when it cannot tell (no GD, a damaged image or one
	 * over 50 megapixels).
	 *
	 * @param string $path   File path.
	 * @param string $backup Backup file path.
	 *
	 * @return bool|null
	 */
	private static function same_picture( string $path, string $backup ): ?bool {
		$file     = self::rgb_thumbnail( $path );
		$original = self::rgb_thumbnail( $backup );

		if ( null === $file || null === $original ) {
			return null;
		}

		$difference = 0;

		foreach ( $file as $index => $value ) {
			$difference += abs( $value - $original[ $index ] );
		}

		return $difference / count( $file ) <= 8;
	}

	/**
	 * The red, green and blue values of each pixel of an image scaled to 16x16 (768 values), or null when GD cannot
	 * read it.
	 *
	 * @param string $path File path.
	 *
	 * @return int[]|null
	 */
	private static function rgb_thumbnail( string $path ): ?array {
		$size = wp_getimagesize( $path );

		if ( ! function_exists( 'imagecreatefromstring' ) || ! $size || $size[0] * $size[1] > 50000000 ) {
			return null;
		}

		$open = [
			IMAGETYPE_JPEG => 'imagecreatefromjpeg',
			IMAGETYPE_PNG  => 'imagecreatefrompng',
			IMAGETYPE_GIF  => 'imagecreatefromgif',
			IMAGETYPE_WEBP => 'imagecreatefromwebp',
		][ $size[2] ] ?? '';

		// A damaged image is null, and GD does not warn about it.
		if ( '' === $open || ! function_exists( $open ) || ! \WRIO\V2\Client::is_intact( $path ) ) {
			return null;
		}

		$image = $open( $path );

		if ( ! $image ) {
			return null;
		}

		$small = imagecreatetruecolor( 16, 16 );
		imagecopyresampled( $small, $image, 0, 0, 0, 0, 16, 16, imagesx( $image ), imagesy( $image ) );
		$values = [];

		for ( $y = 0; $y < 16; $y++ ) {
			for ( $x = 0; $x < 16; $x++ ) {
				$rgb = imagecolorat( $small, $x, $y );
				array_push( $values, ( $rgb >> 16 ) & 0xFF, ( $rgb >> 8 ) & 0xFF, $rgb & 0xFF );
			}
		}

		return $values;
	}

	/**
	 * Save a file without a row as optimized when it is the compressed copy of its backup: the same picture with
	 * the same dimensions and fewer bytes. Sending it again would compress it twice, and its saving would be
	 * measured against the compressed file. A copy with other dimensions can be a resize that stopped before
	 * the optimization: it is sent.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 * @param string $size      Size name.
	 * @param string $path      File path.
	 * @param string $backup    Backup file path.
	 *
	 * @return void
	 */
	private static function adopt( $source, int $object_id, string $size, string $path, string $backup ): void {
		$file     = self::fingerprint( $path, true );
		$original = self::fingerprint( $backup );
		$shape    = wp_getimagesize( $path );
		$before   = wp_getimagesize( $backup );

		if ( ! $shape || ! $before || $file['size'] <= 0 || $file['size'] >= $original['size'] || $shape[0] !== $before[0] || $shape[1] !== $before[1] ) {
			return;
		}

		$item = [
			'source'    => $source,
			'object_id' => $object_id,
			'level'     => (string) \WRIO\V2\Optimizer::stored_level( '' ),
			'backed_up' => true,
		];

		$saved = self::transition(
			$item,
			$size,
			'optimized',
			$path,
			'processed',
			[
				'status'           => self::OK,
				'original_size'    => $original['size'],
				'final_size'       => $file['size'],
				'source_size'      => $file['size'],
				'source_mtime'     => $file['mtime'],
				'source_hash'      => $file['hash'] ?? '',
				'source_full_hash' => $file['full_hash'] ?? '',
				'mime_type'        => (string) wp_get_image_mime( $path ),
			],
			self::NONE
		);

		if ( $saved ) {
			self::log(
				[
					'action'        => 'import',
					'source'        => $source,
					'object_id'     => $object_id,
					'size_name'     => $size,
					'status'        => self::OK,
					'original_size' => $original['size'],
					'outputs'       => (string) wp_json_encode(
						[
							'optimized' => [
								'status' => self::OK,
								'size'   => $file['size'],
							],
						]
					),
				]
			);
		}
	}

	/**
	 * The state of each output of one file.
	 *
	 * @param array    $item    The item.
	 * @param string   $size    Size name.
	 * @param string   $path    Source file path.
	 * @param Output[] $outputs Outputs.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return array<Output, State>
	 */
	public static function states( array $item, $size, $path, array $outputs ) {
		$states = [];

		foreach ( self::rows_of( $item, $size, $outputs ) as $output => $row ) {
			$states[ $output ] = self::state( $row, $path );
		}

		return $states;
	}

	/**
	 * The state of a row: its status, "none" without a row, or "stale" when the source file changed.
	 *
	 * @param Row|null $row  The row.
	 * @param string   $path Source file path.
	 *
	 * @return State
	 */
	public static function state( $row, $path ) {
		if ( empty( $row ) ) {
			return self::NONE;
		}

		$status = (string) $row['status'];

		if ( ! in_array( $status, [ self::OK, self::SKIPPED ], true ) ) {
			return $status;
		}

		// The source file is gone, for example offloaded to a CDN: the output stays done. A file truncated to
		// 0 bytes is not gone: its fingerprint changed.
		if ( ! is_file( $path ) ) {
			return $status;
		}

		if ( self::source_changed( $row, $path ) ) {
			return self::STALE;
		}

		// The converted file of an ok row was deleted, for example by a cleanup plugin.
		if ( self::OK === $status && 'optimized' !== $row['format'] && ! file_exists( $path . '.' . $row['format'] ) ) {
			return self::STALE;
		}

		return $status;
	}

	/**
	 * Whether the source file of a row has other content than when the row was saved.
	 *
	 * Another size is other content. At the same size, the content hash of the row tells (see content_hash()): a
	 * modification time alone changes with a migration, a restore tool or a touch, and the file is then the same
	 * optimized file: optimizing it again would compress it twice. A row without a hash (saved before 2.1, or
	 * copied from the old table) checks the size only. A row without a fingerprint cannot be checked.
	 *
	 * @param array  $row  The row.
	 * @param string $path Source file path.
	 *
	 * @phpstan-param Row $row
	 *
	 * @return bool
	 */
	public static function source_changed( array $row, string $path ): bool {
		if ( (int) $row['source_size'] <= 0 || ! is_file( $path ) ) {
			return false;
		}

		if ( self::fingerprint( $path )['size'] !== (int) $row['source_size'] ) {
			return true;
		}

		$hash = (string) ( $row['source_hash'] ?? '' );

		return '' !== $hash && self::content_hash( $path ) !== $hash;
	}

	/**
	 * Split the outputs of an item's files into the ones to request and the ones that are already done.
	 *
	 * An output is done when its row is fresh ok or skipped, or when another item has the same file (for example
	 * a WPML copy of an attachment) with a fresh ok row: that row is copied. A file that is not on disk gets
	 * "missing" rows. A failure is requested when its retry is due. Every other output (none, missing, stale) is
	 * requested.
	 *
	 * @param array                   $item    The item.
	 * @param array                   $files   Files by size name.
	 * @param array<string, Output[]> $outputs Wanted outputs by size name.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param Files $files
	 *
	 * @param bool                    $prune   Forget the pending rows of every output that is not wanted any
	 *                                          more, for example of a size that is no longer optimized. False
	 *                                          for a conversion: it forgets those of its formats only.
	 *
	 * @return array{request: array<string, non-empty-list<Output>>, done: array<string, array<Output, Row>>}|null Null when the
	 *         old rows of the item could not be copied: what they say is done is not known, so nothing is requested.
	 */
	public static function plan( array $item, array $files, array $outputs, $prune = false ) {
		$rows = self::rows_or_import( $item['source'], $item['object_id'] );

		if ( null === $rows ) {
			return null;
		}

		$request = [];
		$done    = [];
		$stale   = [];

		// A conversion prunes the rows of its formats only.
		$formats = $prune ? self::OUTPUTS : array_unique( array_merge( [], ...array_values( $outputs ) ) );

		foreach ( $rows as $key => $row ) {
			// Pending rows, failures and missing files of an output that is no longer wanted (a format turned off, a
			// size no longer optimized, a thumbnail whose file is gone), also a plan limit: due, it would keep the
			// item in the bulk selection (Queries::work()). Permanent failures stay: they tell why.
			$retry = in_array( $row['status'], [ self::FAILED, self::MISSING ], true ) && 0 === (int) $row['permanent'];

			if ( in_array( $row['format'], $formats, true ) && ( self::PENDING === $row['status'] || $retry ) && ! in_array( $row['format'], $outputs[ $row['size_name'] ] ?? [], true ) ) {
				$stale[] = (string) $key;
			}
		}

		if ( ! empty( $stale ) ) {
			// Without this, the bulk queries would pick the item on every run (see Queries::changed()).
			self::forget_keys( $item['source'], $item['object_id'], 'not_processed', $stale );
		}

		foreach ( $outputs as $size => $wanted ) {
			$path   = $files[ $size ]['path'];
			$exists = file_exists( $path );

			foreach ( $wanted as $output ) {
				$row   = $rows[ $size . '|' . $output ] ?? null;
				$state = self::state( $row, $path );

				if ( ! $exists && ! in_array( $state, [ self::OK, self::SKIPPED ], true ) ) {
					if ( self::transition( $item, $size, $output, $path, 'file_missing', [ 'status' => self::MISSING ] ) ) {
						self::log(
							[
								'action'    => 'missing',
								'source'    => $item['source'],
								'object_id' => $item['object_id'],
								'size_name' => $size,
								'status'    => self::MISSING,
								'detail'    => $output,
							]
						);
					}

					$missing = self::rows_of( $item, $size, [ $output ] )[ $output ];

					if ( $missing ) {
						$done[ $size ][ $output ] = $missing;
					}
					continue;
				}

				// Another item with the same file (a WPML copy) may have a result for these bytes: also after a
				// failure of this item, or a change of the file that the other item already processed.
				if ( $exists && ! in_array( $state, [ self::OK, self::SKIPPED ], true ) ) {
					$shared = self::share( $item, $size, $output, $path );

					if ( $shared ) {
						$row   = $shared;
						$state = self::OK;
					}
				}

				// A failure waits for its retry time; a permanent, plan or last failure waits for good (see retry_now()).
				$waits = self::FAILED === $state && ( empty( $row['next_attempt_at'] ) || $row['next_attempt_at'] > self::now() );

				if ( $row && ( $waits || in_array( $state, [ self::OK, self::SKIPPED ], true ) ) ) {
					$done[ $size ][ $output ] = $row;
				} else {
					$request[ $size ][] = $output;
				}
			}
		}

		return [
			'request' => $request,
			'done'    => $done,
		];
	}

	/**
	 * The messages of the outputs of an item's main file that are not ok, for the media column.
	 *
	 * @param Source                  $source    attachment, cf_image or nextgen.
	 * @param int                     $object_id Item ID.
	 * @param array<string, Row>|null $rows      The item's rows when they are read already (see rows_of_items()).
	 *
	 * @return string
	 */
	public static function message( $source, $object_id, $rows = null ) {
		$messages = [];
		$detail   = null;

		foreach ( $rows ?? self::rows( $source, $object_id ) as $row ) {
			// A skipped output (not smaller) is a success, not an error.
			if ( 'original' !== $row['size_name'] || ! in_array( $row['status'], [ self::FAILED, self::MISSING ], true ) ) {
				continue;
			}

			$context = [
				'format'          => (string) $row['format'],
				'next_attempt_at' => null === $row['next_attempt_at'] ? null : (string) $row['next_attempt_at'],
				'attempts'        => (int) $row['attempts'],
			];
			$detail  = $detail ?? ( self::FAILED === $row['status'] ? self::last_detail( $source, $object_id ) : '' );

			// The server's explanation, for a code the plugin does not know.
			if ( '' !== $detail ) {
				$context['fallback'] = $detail;
			}

			$messages[] = wrio_error_message( (string) $row['status'], (string) $row['code'], self::message_context( $context ) );
		}

		// The outputs of a file that failed as a whole have the same message.
		return implode( ' ', array_unique( $messages ) );
	}

	/**
	 * The server's explanation of the latest failure of an item's main file (see save_error()). A failure of the
	 * site has a code in its history row and changes no result, so it is not this one.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return string Empty when the history has none.
	 */
	public static function last_detail( $source, $object_id ) {
		global $wpdb;

		$log = Schema::log_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- plugin table.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT detail FROM {$log} WHERE source = %s AND object_id = %d AND size_name = 'original' AND action IN ('optimize', 'convert') AND status = %s AND code = '' ORDER BY id DESC LIMIT 1", $source, $object_id, self::FAILED ) );
	}

	/**
	 * The context of wrio_error_message() with the limits of the plan, when the server sent them. They are the
	 * ones the message is about.
	 *
	 * @param array{format?: string, path?: string, limit?: int, megapixels?: float|int, next_attempt_at?: string|null, attempts?: int, fallback?: string} $context Context.
	 *
	 * @return array{format?: string, path?: string, limit?: int, megapixels?: float|int, next_attempt_at?: string|null, attempts?: int, fallback?: string}
	 */
	public static function message_context( array $context ) {
		$limits = \WRIO\V2\Client::from_settings()->cached_limits();

		return array_merge(
			$context,
			$limits ? [
				'limit'      => $limits['max_file_bytes'],
				'megapixels' => $limits['max_pixels'] / 1000000,
			] : []
		);
	}

	/**
	 * Move a row to a new state. A refused event and a failed write get a history row; the caller logs a change.
	 *
	 * @param array      $item   The item.
	 * @param string     $size   Size name.
	 * @param Output     $output optimized, webp or avif.
	 * @param string     $path   Source file path.
	 * @param Event      $event  A key of TRANSITIONS.
	 * @param array      $data   Columns of the new row. status is required.
	 * @param State|null $state  The state before the step that produced this result, when the step changed files
	 *                           (a committed format file makes a stale row look fresh). Null reads it.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param Columns $data
	 *
	 * @return bool Whether the row changed. False when the event is not allowed in the current state, or the
	 *              write failed.
	 */
	public static function transition( array $item, $size, $output, $path, $event, array $data, $state = null ) {
		global $wpdb;

		$row   = self::rows_of( $item, $size, [ $output ] )[ $output ] ?? null;
		$state = $state ?? self::state( $row, $path );

		if ( ! in_array( $state, self::TRANSITIONS[ $event ], true ) ) {
			return self::refuse( $item, $size, $output, $state, $event );
		}

		$failures = $row && self::FAILED === $row['status'] ? max( 0, (int) $row['attempts'] ) : 0;

		if ( self::FAILED === $data['status'] ) {
			$data += self::retry( $failures + 1, ! empty( $data['permanent'] ), (string) ( $data['code'] ?? '' ) );
		}

		self::upsert(
			array_merge(
				[
					'source'           => $item['source'],
					'object_id'        => $item['object_id'],
					'size_name'        => $size,
					'format'           => $output,
					'path_hash'        => self::path_hash( $path ),
					'level'            => (string) ( $item['level'] ?? '' ),
					'backed_up'        => empty( $item['backed_up'] ) ? 0 : 1,
					'original_size'    => 0,
					'final_size'       => 0,
					'source_size'      => 0,
					'source_mtime'     => 0,
					'source_hash'      => '',
					'source_full_hash' => '',
					'mime_type'        => '',
					'code'             => '',
					'permanent'        => 0,
					'attempts'         => 0,
					'next_attempt_at'  => null,
					'request_id'       => '',
					'legacy'           => 0,
				],
				$data
			)
		);

		// The write failed (a lost connection, a full disk): the result is not saved, and the caller must know.
		if ( '' !== (string) $wpdb->last_error ) {
			return self::refuse( $item, $size, $output, $state, 'write_failed' );
		}

		return true;
	}

	/**
	 * The history row of a change that transition() did not make.
	 *
	 * @param array                $item   The item.
	 * @param string               $size   Size name.
	 * @param Output               $output optimized, webp or avif.
	 * @param State                $state  State of the row.
	 * @param Event|'write_failed' $code   The refused event, or write_failed.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return false
	 */
	private static function refuse( array $item, $size, $output, $state, $code ) {
		self::log(
			[
				'action'    => 'refused',
				'source'    => $item['source'],
				'object_id' => $item['object_id'],
				'size_name' => $size,
				'status'    => $state,
				'code'      => $code,
				'detail'    => $output,
			]
		);

		return false;
	}

	/**
	 * Save the results of one file and append one history row.
	 *
	 * @param array                  $item     The item.
	 * @param string                 $size     Size name.
	 * @param string                 $path     Source file path.
	 * @param array<Output, Outcome> $outcomes Outcome of each requested output.
	 * @param array                  $source   Fingerprint of the source file as the plugin leaves it.
	 * @param array                  $context  original_size, mime_type, request_id, duration_ms, action, detail
	 *                                         (the server's explanation of a failure), and states: the state of
	 *                                         each output before its files changed.
	 * @param array<Output, int>     $sizes    Bytes of each ok output.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param array{original_size: int, mime_type?: string, request_id?: string, duration_ms?: int, action?: string, detail?: string, states?: array<Output, State>} $context
	 * @phpstan-param Fingerprint $source
	 *
	 * @return array<string, bool> Whether each output's row was saved. A site failure has no entry.
	 */
	public static function save_file( array $item, $size, $path, array $outcomes, array $source, array $context, array $sizes = [] ) {
		$log   = [];
		$saved = [];

		foreach ( $outcomes as $output => $outcome ) {
			// A 429 or 503 for one output (a retry of AVIF, for example) is the site's failure, not the file's.
			if ( self::is_site_failure( $outcome['code'] ) ) {
				self::interrupted( $item, $size, $output, $path, $outcome['code'] );
				$log[ $output ] = [
					'status' => self::FAILED,
					'code'   => $outcome['code'],
				];
				continue;
			}

			$final = self::OK === $outcome['status'] ? (int) ( $sizes[ $output ] ?? 0 ) : 0;

			// A file that the server could not make smaller keeps its size: the size of the file the plugin keeps,
			// which is smaller than original_size after a resize.
			if ( 'optimized' === $output && self::SKIPPED === $outcome['status'] ) {
				$final = $source['size'] > 0 ? (int) $source['size'] : (int) $context['original_size'];
			}

			$changed = self::transition(
				$item,
				$size,
				$output,
				$path,
				'processed',
				[
					'status'           => $outcome['status'],
					'code'             => $outcome['code'],
					'permanent'        => $outcome['permanent'] ? 1 : 0,
					'original_size'    => (int) $context['original_size'],
					'final_size'       => $final,
					'source_size'      => $source['size'],
					'source_mtime'     => $source['mtime'],
					'source_hash'      => $source['hash'] ?? '',
					'source_full_hash' => $source['full_hash'] ?? '',
					'mime_type'        => (string) ( $context['mime_type'] ?? '' ),
					'request_id'       => (string) ( $context['request_id'] ?? '' ),
				],
				$context['states'][ $output ] ?? null
			);

			$saved[ $output ] = $changed;

			// A refused result has its own history row.
			if ( ! $changed ) {
				continue;
			}

			$log[ $output ] = array_filter(
				[
					'status' => $outcome['status'],
					'code'   => $outcome['code'],
					'size'   => $final,
				]
			);
		}

		// A saved result ends the 5xx waits in a row of the file.
		foreach ( $saved as $output => $changed ) {
			if ( $changed && self::OK === $outcomes[ $output ]['status'] ) {
				delete_transient( self::busy_key( $item, $size ) );
				break;
			}
		}

		if ( empty( $log ) ) {
			return $saved;
		}

		self::log(
			[
				'action'        => (string) ( $context['action'] ?? 'optimize' ),
				'source'        => $item['source'],
				'object_id'     => $item['object_id'],
				'size_name'     => $size,
				'status'        => self::worst( wp_list_pluck( $log, 'status' ) ),
				'original_size' => (int) $context['original_size'],
				'outputs'       => (string) wp_json_encode( $log ),
				'request_id'    => (string) ( $context['request_id'] ?? '' ),
				'duration_ms'   => (int) ( $context['duration_ms'] ?? 0 ),
				'detail'        => (string) ( $context['detail'] ?? '' ),
			]
		);

		return $saved;
	}

	/**
	 * Save a file whose request failed as a whole.
	 *
	 * A failure of the site (rate limit, maintenance, unknown license) changes no result: the file did nothing
	 * wrong, and its retries are not used up. Only the history keeps it, and a size without a result becomes pending.
	 *
	 * @param array                $item    The item.
	 * @param string               $size    Size name.
	 * @param string               $path    Source file path.
	 * @param Output[]             $outputs Requested outputs.
	 * @param WP_Error             $error   The error.
	 * @param 'optimize'|'convert' $action  optimize or convert.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return void
	 */
	public static function save_error( array $item, $size, $path, array $outputs, WP_Error $error, $action = 'optimize' ) {
		$data    = (array) $error->get_error_data();
		$outcome = self::error_outcome( $error );

		// A 5xx wait that the same file gets again and again is the file's failure after all: the run goes on.
		$busy_code = is_string( $data['busy_code'] ?? null ) ? $data['busy_code'] : '';
		$busy      = '' !== $busy_code && self::busy_waits( $item, $size ) >= self::MAX_BUSY_WAITS;

		if ( $busy ) {
			$outcome = self::failure( $busy_code );
		} elseif ( self::is_site_failure( $outcome['code'], (int) ( $data['status'] ?? 0 ) ) ) {
			foreach ( $outputs as $output ) {
				self::interrupted( $item, $size, $output, $path, $outcome['code'] );
			}

			self::log(
				[
					'action'     => $action,
					'source'     => $item['source'],
					'object_id'  => $item['object_id'],
					'size_name'  => $size,
					'status'     => self::FAILED,
					'code'       => $outcome['code'],
					'detail'     => $error->get_error_message(),
					'request_id' => (string) ( $data['request_id'] ?? '' ),
				]
			);

			return;
		}

		$source = self::fingerprint( $path );

		$saved = self::save_file(
			$item,
			$size,
			$path,
			array_fill_keys( $outputs, $outcome ),
			$source,
			[
				'original_size' => $source['size'],
				'request_id'    => (string) ( $data['request_id'] ?? '' ),
				'action'        => $action,
				// The row keeps only the code: the history keeps the explanation of a code the plugin does not know.
				'detail'        => $error->get_error_message(),
			]
		);

		// The count of waits ends only with the saved failure: after a failed write, the next wait fails the file.
		if ( $busy && ! empty( $saved ) && ! in_array( false, $saved, true ) ) {
			delete_transient( self::busy_key( $item, $size ) );
		}
	}

	/**
	 * Count one more 5xx wait other than a 503 of a file in a row.
	 *
	 * @param array  $item The item.
	 * @param string $size Size name.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return int The waits in a row, this one included.
	 */
	private static function busy_waits( array $item, $size ) {
		$key   = self::busy_key( $item, $size );
		$waits = (int) get_transient( $key ) + 1;

		// A saved result or the saved failure deletes the count. The day only cleans up a file that is never picked again.
		set_transient( $key, $waits, DAY_IN_SECONDS );

		return $waits;
	}

	/**
	 * The transient of the 5xx waits of a file. The site URL is in the key: a database copied to another site starts
	 * with no count.
	 *
	 * @param array  $item The item.
	 * @param string $size Size name.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return string
	 */
	private static function busy_key( array $item, $size ) {
		return 'wrio_busy_' . md5( home_url() . '|' . $item['source'] . '|' . $item['object_id'] . '|' . $size );
	}

	/**
	 * Mark the output of a size pending when a failure of the site stopped it before any result. The main file
	 * decides the bulk queries, so an item whose main file is done would never be picked for this size again (see
	 * Queries::changed()). The output's retries are not used up. The main file needs no row: without one, the item
	 * is picked anyway.
	 *
	 * @param array  $item   The item.
	 * @param string $size   Size name.
	 * @param Output $output optimized, webp or avif.
	 * @param string $path   Source file path.
	 * @param string $code   The site failure code.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return void
	 */
	private static function interrupted( array $item, $size, $output, $path, $code ) {
		if ( 'original' === $size ) {
			return;
		}

		$state = self::state( self::rows_of( $item, $size, [ $output ] )[ $output ], $path );

		if ( in_array( $state, [ self::NONE, self::STALE ], true ) ) {
			self::transition(
				$item,
				$size,
				$output,
				$path,
				'processed',
				[
					'status' => self::PENDING,
					'code'   => $code,
				]
			);
		}
	}

	/**
	 * Give the done rows of the outputs that were not requested the fingerprint of the optimized file that replaces
	 * the source next. A WebP saved by an attempt whose optimization failed is of the same image: without this, the
	 * rename would make it stale and convert it again.
	 *
	 * @param array    $item      The item.
	 * @param string   $size      Size name.
	 * @param string   $path      Source file path, before the rename.
	 * @param array    $source    Fingerprint of the staged optimized file.
	 * @param Output[] $requested Outputs of the request.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param Fingerprint $source
	 *
	 * @return void
	 */
	public static function carry( array $item, $size, $path, array $source, array $requested ) {
		global $wpdb;

		foreach ( self::rows_of( $item, $size, array_diff( self::OUTPUTS, $requested ) ) as $row ) {
			if ( $row && in_array( self::state( $row, $path ), [ self::OK, self::SKIPPED ], true ) ) {
				$wpdb->update(
					Schema::files_table(),
					[
						'source_size'      => $source['size'],
						'source_mtime'     => $source['mtime'],
						'source_hash'      => $source['hash'] ?? '',
						'source_full_hash' => $source['full_hash'] ?? '',
					],
					[ 'id' => (int) $row['id'] ]
				);
			}
		}
	}

	/**
	 * Save a failure of an item before any request, for example a failed backup or a missing main file.
	 *
	 * Without a row, a bulk run would pick the item again on every step: the failure gets the retry schedule.
	 *
	 * @param array       $item The item.
	 * @param string      $path Main file path.
	 * @param ItemFailure $code missing_file, no_metadata, backup_failed, path_denied, unexpected_error or crashed.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return void
	 */
	public static function fail( array $item, $path, $code ) {
		$source = self::fingerprint( $path );

		self::save_file(
			$item,
			'original',
			$path,
			[ 'optimized' => self::failure( $code ) ],
			$source,
			[ 'original_size' => $source['size'] ]
		);

		// The other pending rows of the item (a regenerated size, a format after a plan change) were not processed
		// either. Pending, they would make a bulk run pick the item on every step: they wait for the same retry. A
		// missing file waits for an event or an errors reset, as the main file's row does: a conversion of an item
		// without its file (offloaded) does nothing that could move a retry on.
		global $wpdb;

		$failure = self::failure( $code );
		$retry   = self::MISSING === $failure['status'] ? [
			'attempts'        => 0,
			'next_attempt_at' => null,
		] : self::retry( 1, $failure['permanent'], $code );
		$wpdb->update(
			Schema::files_table(),
			[
				'status'          => $failure['status'],
				'code'            => $code,
				'permanent'       => $failure['permanent'] ? 1 : 0,
				'attempts'        => $retry['attempts'],
				'next_attempt_at' => $retry['next_attempt_at'],
				// A v2 result now: a copy again must not put the old success back.
				'legacy'          => 0,
				'updated_at'      => self::now(),
			],
			[
				'source'    => $item['source'],
				'object_id' => $item['object_id'],
				'status'    => self::PENDING,
			]
		);
	}

	/**
	 * Save the "crashed" failure of an item whose optimization stopped PHP MAX_CRASHES times.
	 *
	 * The fail() call saves it on the main file and on the pending rows. A due failed row of another size was part of the run
	 * too: its failures in a row go up as well, so a size that keeps crashing ends at MAX_ATTEMPTS. Done rows stay.
	 *
	 * @param array  $item The item.
	 * @param string $path Main file path.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return string The code saved: crashed, or memory_limit when the last process died at the memory limit.
	 */
	public static function crashed( array $item, string $path ): string {
		global $wpdb;

		$rows = self::rows( $item['source'], $item['object_id'] );
		// The last process died at the memory limit: say so.
		$fatal = self::fatal_name( $item['source'], $item['object_id'] );
		$code  = 'memory' === get_option( $fatal ) ? 'memory_limit' : 'crashed';
		delete_option( $fatal );

		self::fail( $item, $path, $code );

		foreach ( $rows as $key => $row ) {
			if ( 'original|optimized' === $key || self::FAILED !== $row['status'] || '1' === $row['permanent'] || null === $row['next_attempt_at'] || $row['next_attempt_at'] > self::now() ) {
				continue;
			}

			$wpdb->update(
				Schema::files_table(),
				array_merge(
					[
						'code'       => $code,
						'updated_at' => self::now(),
					],
					self::retry( max( 1, (int) $row['attempts'] + 1 ), false, $code )
				),
				[ 'id' => (int) $row['id'] ]
			);
		}

		return $code;
	}

	/**
	 * Store a new fingerprint for the rows of a file, when the file on disk is not the one saved with its rows
	 * (a rename to another file system sets a new modification time).
	 *
	 * @param array  $item   The item.
	 * @param string $size   Size name.
	 * @param array  $source Fingerprint of the file on disk.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param Fingerprint $source
	 *
	 * @return void
	 */
	public static function touch( array $item, $size, array $source ) {
		global $wpdb;

		$wpdb->update(
			Schema::files_table(),
			[
				'source_size'      => $source['size'],
				'source_mtime'     => $source['mtime'],
				'source_hash'      => $source['hash'] ?? '',
				'source_full_hash' => $source['full_hash'] ?? '',
			],
			[
				'source'    => $item['source'],
				'object_id' => $item['object_id'],
				'size_name' => $size,
			]
		);
	}

	/**
	 * Copy a row of the old table. A row that exists is not replaced, so v2 results always win. Only a copied row
	 * that no newer event made pending takes the new values: after a rollback, the old version can optimize the
	 * file again, and the old fingerprint would make the file look changed.
	 *
	 * @param array  $item   The item.
	 * @param string $size   Size name.
	 * @param Output $output optimized, webp or avif.
	 * @param string $path   Source file path.
	 * @param array  $data   status, code, original_size, final_size, mime_type, level.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param array{status: string, code: string, original_size: int, final_size: int, mime_type: string, level?: string} $data
	 *
	 * @return bool|null True for a new row, false for a row that exists, null when the write failed.
	 */
	public static function import( array $item, $size, $output, $path, array $data ) {
		$source  = self::fingerprint( $path );
		$failure = self::FAILED === $data['status'] ? self::failure( (string) $data['code'] ) : null;

		$row    = array_merge(
			[
				'source'          => $item['source'],
				'object_id'       => $item['object_id'],
				'size_name'       => $size,
				'format'          => $output,
				'path_hash'       => self::path_hash( $path ),
				'level'           => (string) ( $item['level'] ?? '' ),
				'backed_up'       => empty( $item['backed_up'] ) ? 0 : 1,
				'source_size'     => $source['size'],
				'source_mtime'    => $source['mtime'],
				'permanent'       => $failure && $failure['permanent'] ? 1 : 0,
				// An old failure is due now: the old table has no retry time.
				'attempts'        => $failure ? 1 : 0,
				'next_attempt_at' => $failure && ! $failure['permanent'] && ! in_array( $failure['code'], self::PLAN_CODES, true ) ? self::now() : null,
				'request_id'      => '',
				'legacy'          => 1,
			],
			$data
		);
		$result = self::upsert( $row, false );

		// INSERT IGNORE of a row that exists is 0 rows and no error: that row is copied already.
		if ( 0 === $result ) {
			$existing = self::rows( $item['source'], $item['object_id'] )[ $size . '|' . $output ] ?? null;

			if ( ! $existing || ! (int) $existing['legacy'] || self::PENDING === $existing['status'] ) {
				return false;
			}

			// One conditional update: a hook that made the row pending, or a v2 result, since the read keeps its state.
			global $wpdb;

			$row['updated_at'] = self::now();
			$updated           = self::query_with_repair(
				function () use ( $wpdb, $row, $existing ) {
					return $wpdb->update(
						Schema::files_table(),
						$row,
						[
							'id'     => (int) $existing['id'],
							'legacy' => 1,
							'status' => $existing['status'],
						]
					);
				}
			);

			return false === $updated ? null : false;
		}

		return false === $result ? null : 0 < $result;
	}

	/**
	 * Whether the main row of an item is a copy of an old row without its import history row: nothing but the copy
	 * wrote it, and the history does not show it.
	 *
	 * @param string $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @phpstan-param Source $source
	 *
	 * @return bool
	 */
	public static function only_imported( string $source, int $object_id ): bool {
		global $wpdb;

		$table = Schema::files_table();
		$log   = Schema::log_table();

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} f WHERE f.source = %s AND f.object_id = %d AND f.size_name = 'original' AND f.format = 'optimized' AND f.legacy = 1 AND NOT EXISTS ( SELECT 1 FROM {$log} l WHERE l.source = f.source AND l.object_id = f.object_id AND l.action IN ('import', 'optimize', 'convert') )", $source, $object_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables.
	}

	/**
	 * Whether a value is an item type of the rows. Other values come from old or damaged queue rows.
	 *
	 * @param mixed $value The value.
	 *
	 * @phpstan-assert-if-true Source $value
	 *
	 * @return bool
	 */
	public static function is_source( $value ): bool {
		return in_array( $value, self::SOURCES, true );
	}

	/**
	 * Whether a failure is the site's: a rate limit, maintenance or an unknown license. The file did nothing wrong.
	 *
	 * An empty or HTML 429/503 answer has no problem code: the client names it http_429 or http_503.
	 *
	 * @param string $code   Failure code.
	 * @param int    $status HTTP status, when known.
	 *
	 * @return bool
	 */
	public static function is_site_failure( $code, $status = 0 ) {
		return in_array( $code, self::SITE_CODES, true ) || in_array( $code, [ 'http_429', 'http_503' ], true ) || in_array( $status, [ 429, 503 ], true );
	}

	/**
	 * The outcome of an output result of the API.
	 *
	 * @param array<string, mixed> $result Output result: status, reason, error.
	 *
	 * @return Outcome
	 */
	public static function outcome( array $result ) {
		$status = (string) ( $result['status'] ?? self::FAILED );

		if ( self::OK === $status ) {
			return [
				'status'    => self::OK,
				'code'      => '',
				'permanent' => false,
			];
		}

		if ( self::SKIPPED === $status ) {
			$reason = (string) ( $result['reason'] ?? '' );

			// The plan can change, so this output is pending until it does (see plan_changed()).
			return [
				'status'    => 'not_in_plan' === $reason ? self::FAILED : self::SKIPPED,
				'code'      => $reason,
				'permanent' => false,
			];
		}

		$code = is_array( $result['error'] ?? null ) && is_scalar( $result['error']['code'] ?? null ) ? (string) $result['error']['code'] : '';

		return self::failure( '' !== $code ? $code : 'processing_failed' );
	}

	/**
	 * The outcome of a request error.
	 *
	 * @param WP_Error $error The error. Errors of WRIO\V2\Client carry the problem code in their data.
	 *
	 * @return Outcome
	 */
	public static function error_outcome( WP_Error $error ) {
		$data = (array) $error->get_error_data();

		return self::failure( (string) ( $data['code'] ?? $error->get_error_code() ) );
	}

	/**
	 * Delete rows of an item and append the event to the history. Deleting is allowed from every state.
	 *
	 * Events: restored, deleted, size_removed, not_processed.
	 *
	 * @param Source        $source    attachment, cf_image or nextgen.
	 * @param int           $object_id Item ID.
	 * @param ForgetEvent   $event     Why the rows go.
	 * @param string[]|null $sizes     Only these sizes. Null deletes every size.
	 * @param Output[]|null $formats   Only these outputs. Null deletes every output.
	 *
	 * @return array<string, Row> The deleted rows, keyed by "size_name|format".
	 */
	public static function forget( $source, $object_id, $event, $sizes = null, $formats = null ) {
		$keys = [];

		foreach ( self::rows( $source, $object_id ) as $key => $row ) {
			if ( ( null === $sizes || in_array( $row['size_name'], $sizes, true ) ) && ( null === $formats || in_array( $row['format'], $formats, true ) ) ) {
				$keys[] = (string) $key;
			}
		}

		return self::forget_keys( $source, $object_id, $event, $keys );
	}

	/**
	 * Give the done rows of an item to the other items with the same files (WPML copies), before the item goes. A
	 * copy keeps the rows it has.
	 *
	 * @param string $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 * @param int[]  $copies    IDs of the other items.
	 *
	 * @phpstan-param Source $source
	 *
	 * @return void
	 */
	public static function hand_over( string $source, int $object_id, array $copies ): void {
		foreach ( self::rows( $source, $object_id ) as $row ) {
			if ( ! in_array( $row['status'], [ self::OK, self::SKIPPED ], true ) ) {
				continue;
			}

			unset( $row['id'] );

			foreach ( $copies as $copy ) {
				if ( self::upsert( array_merge( $row, [ 'object_id' => $copy ] ), false ) ) {
					self::log(
						[
							'action'    => 'share',
							'source'    => $source,
							'object_id' => $copy,
							'size_name' => (string) $row['size_name'],
							'status'    => (string) $row['status'],
							'detail'    => $source . ':' . $object_id,
						]
					);
				}
			}
		}
	}

	/**
	 * Delete rows of an item by "size_name|format" keys, and log the event.
	 *
	 * @param Source      $source    attachment, cf_image or nextgen.
	 * @param int         $object_id Item ID.
	 * @param ForgetEvent $event     Why the rows go.
	 * @param string[]    $keys      Row keys.
	 *
	 * @return array<string, Row> The deleted rows.
	 */
	private static function forget_keys( $source, $object_id, $event, array $keys ) {
		global $wpdb;

		$rows = array_intersect_key( self::rows( $source, $object_id ), array_flip( $keys ) );

		if ( empty( $rows ) ) {
			return [];
		}

		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$table = Schema::files_table();
		$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer IDs.
		self::changed();

		self::log(
			[
				'action'    => self::action_of( $event ),
				'source'    => $source,
				'object_id' => $object_id,
				'status'    => self::NONE,
				'code'      => $event,
				'outputs'   => (string) wp_json_encode( array_keys( $rows ) ),
			]
		);

		return $rows;
	}

	/**
	 * Forget the rows that a restore made wrong: every format row (the restore deletes the format files), and the
	 * optimized rows whose file changed. A file that the restore did not replace (no backup) stays optimized, so
	 * the next run does not compress it a second time. A copied old row is the exception: the old table does not say
	 * which sizes were optimized, so the restore forgets it, as the old version forgot its whole queue row. A skipped
	 * row goes too: "not smaller" is true only for the level it ran at, and its file was never compressed. A failed,
	 * missing or pending row goes too: after a restore, the next run is the same as the first one, also
	 * for an item whose damaged main file failed for good.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 * @param array  $files     Files of the item by size name.
	 * @phpstan-param Files $files
	 *
	 * @return array<string, Row> The deleted rows.
	 */
	public static function restored( $source, $object_id, array $files ) {
		$changed = [];

		foreach ( self::rows( $source, $object_id ) as $row ) {
			$path = $files[ $row['size_name'] ]['path'] ?? '';

			if ( 'optimized' === $row['format'] && self::OK === $row['status'] && '' !== $path && ! self::source_changed( $row, $path ) && ! (int) $row['legacy'] ) {
				continue;
			}

			$changed[] = (string) $row['size_name'] . '|' . $row['format'];
		}

		if ( empty( $changed ) ) {
			return [];
		}

		return self::forget_keys( $source, $object_id, 'restored', $changed );
	}

	/**
	 * Move the rows of a size to another size name, for sizes that share one file: get_files() names a shared file
	 * after its first size, and that name changes when the first size goes. The file stays done.
	 *
	 * @param string $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 * @param string $from      Old size name.
	 * @param string $to        New size name.
	 *
	 * @phpstan-param Source $source
	 *
	 * @return void
	 */
	public static function rename_size( string $source, int $object_id, string $from, string $to ): void {
		global $wpdb;

		$wpdb->update(
			Schema::files_table(),
			[ 'size_name' => $to ],
			[
				'source'    => $source,
				'object_id' => (int) $object_id,
				'size_name' => $from,
			]
		);
	}

	/**
	 * Add pending rows for a size that has none yet, so that the bulk queries pick its item.
	 *
	 * @param string   $source    attachment, cf_image or nextgen.
	 * @param int      $object_id Item ID.
	 * @param string   $size      Size name.
	 * @param string   $path      File path.
	 * @param Output[] $outputs   Outputs to process.
	 *
	 * @phpstan-param Source $source
	 *
	 * @return void
	 */
	public static function add_pending( string $source, int $object_id, string $size, string $path, array $outputs ): void {
		foreach ( $outputs as $output ) {
			self::upsert(
				[
					'source'      => $source,
					'object_id'   => (int) $object_id,
					'size_name'   => $size,
					'format'      => $output,
					'status'      => self::PENDING,
					'path_hash'   => self::path_hash( $path ),
					'code'        => 'size_added',
					'source_size' => self::fingerprint( $path )['size'],
				],
				false
			);
		}

		self::changed();
		self::log(
			[
				'action'    => 'edit',
				'source'    => $source,
				'object_id' => (int) $object_id,
				'size_name' => $size,
				'status'    => self::PENDING,
				'code'      => 'size_added',
			]
		);
	}

	/**
	 * Whether an item has rows that wait to be processed again: pending, failed or missing (a bulk run picks a due
	 * missing file too, see Queries::due()).
	 *
	 * @param string $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @phpstan-param Source $source
	 *
	 * @return bool
	 */
	public static function has_work( string $source, int $object_id ): bool {
		return ! empty( array_intersect( [ self::PENDING, self::FAILED, self::MISSING ], wp_list_pluck( self::rows( $source, (int) $object_id ), 'status' ) ) );
	}

	/**
	 * Mark a done WebP or AVIF as "skipped: not_smaller" after its file was deleted: a newer result of the same file
	 * is smaller, for example a WebP that a later optimization beat.
	 *
	 * @param array  $item   The item.
	 * @param string $size   Size name.
	 * @param string $format webp or avif.
	 *
	 * @phpstan-param Item $item
	 * @phpstan-param Format $format
	 *
	 * @return bool Whether a done row changed.
	 */
	public static function drop_format( array $item, string $size, string $format ): bool {
		global $wpdb;

		$changed = (int) $wpdb->update(
			Schema::files_table(),
			[
				'status'     => self::SKIPPED,
				'code'       => 'not_smaller',
				'final_size' => 0,
				'updated_at' => self::now(),
			],
			[
				'source'    => $item['source'],
				'object_id' => (int) $item['object_id'],
				'size_name' => $size,
				'format'    => $format,
				'status'    => self::OK,
			]
		);

		if ( $changed < 1 ) {
			return false;
		}

		self::changed();
		self::log(
			[
				'action'    => 'cleanup',
				'source'    => $item['source'],
				'object_id' => (int) $item['object_id'],
				'size_name' => $size,
				'status'    => self::SKIPPED,
				'code'      => 'not_smaller',
				'outputs'   => (string) wp_json_encode( [ $format ] ),
			]
		);

		return true;
	}

	/**
	 * Put the rows of an edit back to done when "Restore image" undid the edit: the file at the row's path is again
	 * the optimized file that the row describes. Sending it again would compress it twice.
	 *
	 * @param array  $row  A pending row of an edit.
	 * @param string $path Its source file path now.
	 *
	 * @phpstan-param Row $row
	 *
	 * @return bool Whether the row is done again.
	 */
	public static function undo_edit( array $row, string $path ): bool {
		global $wpdb;

		if ( self::PENDING !== $row['status'] || ! self::is_edit( $row ) || self::path_hash( $path ) !== $row['path_hash'] || (int) $row['source_size'] <= 0 || self::source_changed( $row, $path ) ) {
			return false;
		}

		if ( 'optimized' === $row['format'] ) {
			// The status before the edit (see mark_pending()). An ok result can be as large as its input, so the sizes
			// cannot tell: "skipped" for a compressed file would let a new level or a restore send it again.
			$status = 'edited_skipped' === $row['code'] ? self::SKIPPED : self::OK;
		} else {
			$status = file_exists( $path . '.' . $row['format'] ) ? self::OK : self::SKIPPED;
		}

		$wpdb->update(
			Schema::files_table(),
			[
				'status'     => $status,
				'code'       => self::SKIPPED === $status && 'optimized' === $row['format'] ? 'not_smaller' : '',
				'updated_at' => self::now(),
			],
			[ 'id' => (int) $row['id'] ]
		);
		self::changed();

		return true;
	}

	/**
	 * Whether a pending row is an edit that "Restore image" can undo (see mark_pending()).
	 *
	 * @param array $row The row.
	 *
	 * @phpstan-param Row $row
	 *
	 * @return bool
	 */
	public static function is_edit( array $row ): bool {
		return in_array( $row['code'], [ 'edited', 'edited_skipped' ], true );
	}

	/**
	 * Delete the history rows older than some days, and the oldest ones over a number of rows.
	 *
	 * @param int $days Days to keep.
	 * @param int $rows Rows to keep.
	 *
	 * @return void
	 */
	public static function prune_log( int $days, int $rows ): void {
		global $wpdb;

		$log = Schema::log_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );

		$extra = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log}" ) - $rows;

		if ( $extra > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} ORDER BY id LIMIT %d", $extra ) );
		}
		// phpcs:enable
	}

	/**
	 * Delete the item locks whose process ended without an unlock.
	 *
	 * The lock holds the crash count, so an expired lock stays for one day: the next retry must count the crash.
	 * A count of 0 is also a crash: lock() adds it only when the next worker takes the lock over. A process that ends
	 * normally deletes its lock in unlock().
	 *
	 * @return void
	 */
	public static function delete_expired_locks(): void {
		global $wpdb;

		// A lock of an earlier version has no count (see crashes_of()): delete it when it expires.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < IF(LENGTH(option_value) - LENGTH(REPLACE(option_value, ':', '')) < 2, %d, %d)", $wpdb->esc_like( 'wrio_lock_' ) . '%', time(), time() - DAY_IN_SECONDS ) );
	}

	/**
	 * Delete the rows of items that are gone without the hooks: a direct SQL delete, another plugin, a removed folder.
	 *
	 * @return int Number of rows.
	 */
	public static function delete_orphans(): int {
		global $wpdb;

		$files = Schema::files_table();
		$queue = \RIO_Process_Queue::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin and core tables, integer IDs.
		$orphans = [
			"SELECT f.id FROM {$files} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.object_id WHERE f.source = 'attachment' AND p.ID IS NULL",
			"SELECT f.id FROM {$files} f LEFT JOIN {$queue} q ON q.id = f.object_id AND q.item_type = 'cf_image' WHERE f.source = 'cf_image' AND q.id IS NULL",
		];

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'ngg_pictures' ) ) ) {
			$orphans[] = "SELECT f.id FROM {$files} f LEFT JOIN {$wpdb->prefix}ngg_pictures n ON n.pid = f.object_id WHERE f.source = 'nextgen' AND n.pid IS NULL";
		}

		$deleted = 0;

		foreach ( $orphans as $select ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $select ) );

			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$deleted += (int) $wpdb->query( "DELETE FROM {$files} WHERE id IN (" . implode( ',', $chunk ) . ')' );
			}
		}
		// phpcs:enable

		self::log(
			[
				'action' => 'cleanup',
				'status' => self::NONE,
				'code'   => 'orphans',
				'detail' => (string) $deleted,
			]
		);

		return $deleted;
	}

	/**
	 * Mark the rows of some sizes as to be processed again, for example after their files were regenerated.
	 *
	 * Deleting the rows would not do: the bulk queries look at the main file of an item, so an item whose main
	 * file is done would never be picked for its other sizes. A pending row makes the item pending.
	 *
	 * @param string   $source    attachment, cf_image or nextgen.
	 * @param int      $object_id Item ID.
	 * @param string   $event     Why the rows are pending: edited, replaced or format_lost.
	 * @param string[] $sizes     Size names.
	 * @param Output[] $formats   Only these outputs. Empty: every output of the sizes.
	 *
	 * @phpstan-param Source $source
	 * @phpstan-param PendingEvent $event
	 *
	 * @return void
	 */
	public static function mark_pending( string $source, int $object_id, string $event, array $sizes, array $formats = [] ): void {
		global $wpdb;

		if ( empty( $sizes ) ) {
			return;
		}

		$table = Schema::files_table();
		$in    = implode( ',', array_fill( 0, count( $sizes ), '%s' ) );
		$only  = empty( $formats ) ? '' : ' AND format IN (' . implode( ',', array_fill( 0, count( $formats ), '%s' ) ) . ')';
		// Only a done output can be done again when "Restore image" undoes an edit (see undo_edit()). Any other
		// output, a failure for example, is "replaced": it is processed again. "edited_skipped" keeps that the file
		// was not compressed: the undo puts back "skipped", not "ok".
		$code = 'edited' === $event ? "IF( status = 'skipped' OR code = 'edited_skipped', 'edited_skipped', IF( status = 'ok' OR code = 'edited', 'edited', 'replaced' ) )" : $wpdb->prepare( '%s', $event );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET code = {$code}, status = %s, attempts = 0, next_attempt_at = NULL, updated_at = %s WHERE source = %s AND object_id = %d AND size_name IN ({$in}){$only}", array_merge( [ self::PENDING, self::now(), $source, $object_id ], $sizes, $formats ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- plugin table, one placeholder per value.
		self::changed();

		self::log(
			[
				'action'    => self::action_of( $event ),
				'source'    => $source,
				'object_id' => $object_id,
				'status'    => self::PENDING,
				'code'      => $event,
				'outputs'   => (string) wp_json_encode( array_values( $sizes ) ),
			]
		);
	}

	/**
	 * Make the failures that depend on the plan due now, after a license or plan change.
	 *
	 * @return int Number of rows.
	 */
	public static function plan_changed() {
		return self::forget_failures( 'plan_changed', self::PLAN_CODES );
	}

	/**
	 * Make the failures of an item due now, for a retry that the user asked for (the Optimize button): also the
	 * permanent ones and the ones that wait for their retry time. Automatic runs leave them (see plan()).
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return void
	 */
	public static function retry_now( $source, $object_id ) {
		global $wpdb;

		$table = Schema::files_table();
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET permanent = 0, attempts = 0, next_attempt_at = %s WHERE source = %s AND object_id = %d AND status = %s", self::now(), $source, (int) $object_id, self::FAILED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.

		if ( $count > 0 ) {
			self::log(
				[
					'action'    => 'retry',
					'source'    => $source,
					'object_id' => (int) $object_id,
					'status'    => self::FAILED,
					'detail'    => (string) $count,
				]
			);
		}
	}

	/**
	 * Failure codes that a new Start retries before their retry time: the connection, the server or the quota failed,
	 * not the file. The user starts again after the problem is gone. Another failure waits for its retry time.
	 * Every http_5xx code is one too (see is_start_code()), also a status that the plugin does not list.
	 *
	 * @return string[]
	 */
	public static function start_codes() {
		return array_merge( self::TRANSIENT_CODES, [ 'quota_exceeded' ] );
	}

	/**
	 * Whether a new Start retries a failure with this code before its retry time.
	 *
	 * @param string $code Failure code.
	 *
	 * @return bool
	 */
	public static function is_start_code( $code ) {
		return in_array( $code, self::start_codes(), true ) || 0 === strpos( $code, 'http_5' );
	}

	/**
	 * Make the failed and missing rows due now, except the permanent ones and the plan limits. A failure of the file
	 * with a retry time keeps it (see start_codes()): a new Start does not send that image before its time, and does
	 * not replace its error.
	 *
	 * @param Source|null $source Only this source.
	 * @param int[]       $absent Attachments whose main file is still missing: their missing rows stay.
	 *
	 * @return int Number of rows.
	 */
	public static function errors_reset( $source = null, array $absent = [] ) {
		return self::forget_failures( 'errors_reset', null, $source, $absent );
	}

	/**
	 * Mark the backups of every row as gone, after the backup folder was emptied.
	 *
	 * @param Source|null $source    Only this source.
	 * @param int|null    $object_id Only this item.
	 * @param string[]    $sizes     Only these sizes. Empty: every size.
	 *
	 * @return void
	 */
	public static function backups_deleted( $source = null, $object_id = null, array $sizes = [] ) {
		global $wpdb;

		$table = Schema::files_table();
		$where = '1 = 1';

		if ( null !== $source ) {
			$where .= $wpdb->prepare( ' AND source = %s', $source );
		}

		if ( null !== $object_id ) {
			$where .= $wpdb->prepare( ' AND object_id = %d', $object_id );
		}

		if ( ! empty( $sizes ) ) {
			$where .= " AND size_name IN ('" . implode( "','", array_map( 'esc_sql', $sizes ) ) . "')";
		}

		$wpdb->query( "UPDATE {$table} SET backed_up = 0 WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		self::log(
			[
				'action'    => 'cleanup',
				'source'    => (string) $source,
				'object_id' => (int) $object_id,
				'code'      => 'backups_deleted',
			]
		);
	}

	/**
	 * Append a history row.
	 *
	 * @param array<string, string|int> $data Columns of wrio_log.
	 *
	 * @return void
	 */
	public static function log( array $data ) {
		global $wpdb;

		self::query_with_repair(
			function () use ( $wpdb, $data ) {
				return $wpdb->insert(
					Schema::log_table(),
					array_merge(
						[
							'created_at' => self::now(),
							'run_id'     => self::run_id(),
							'started_by' => self::started_by(),
						],
						$data
					)
				);
			}
		);
	}

	/**
	 * Whether note_fatal() runs at the end of this process.
	 *
	 * @var bool
	 */
	private static $watching = false;

	/**
	 * Take the lock of an item, so that two processes (cron, bulk, upload) never optimize it at the same time.
	 *
	 * The lock is an option row. INSERT IGNORE takes a free lock and an UPDATE that matches only an expired time
	 * takes over a lock of a process that died, so two processes cannot both get it.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return bool
	 */
	public static function lock( $source, $object_id ) {
		global $wpdb;

		$name = self::lock_name( $source, $object_id );

		// The item class holds the lock around its backup and resize, and the optimizer takes it again.
		if ( isset( self::$held[ $name ] ) ) {
			++self::$held[ $name ];

			return true;
		}

		// "expiry:token:crashes". The token makes unlock() and renew() touch only this process's lock.
		$token = ( time() + self::LOCK_TTL ) . ':' . wp_generate_password( 12, false ) . ':';
		$value = $token . '0';
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );

		// An expired lock: its process died on this item. The takeover counts the crash, and matches the old value,
		// so that two processes cannot both take it.
		if ( ! $taken ) {
			$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

			if ( '' !== $old && (int) $old < time() ) {
				$value = $token . ( self::crashes_of( $old ) + 1 );
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old ) );
			}
		}

		if ( $taken ) {
			if ( ! self::$watching ) {
				self::$watching = true;
				register_shutdown_function( [ __CLASS__, 'note_fatal' ] );
			}

			self::$held[ $name ]   = 1;
			self::$tokens[ $name ] = $value;
		}

		return (bool) $taken;
	}

	/**
	 * At the end of a process that dies of a fatal error with item locks: note how it died, so that crashed() saves
	 * memory_limit only when the last process died at the memory limit. The name is outside the lock prefix, so the
	 * cleanup of expired locks keeps it.
	 *
	 * @return void
	 */
	public static function note_fatal(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_get_last -- reads the fatal error of this process.
		$error = error_get_last();

		if ( empty( self::$held ) || ! is_array( $error ) || ! in_array( $error['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			return;
		}

		$kind = false !== strpos( $error['message'], 'Allowed memory size' ) ? 'memory' : 'other';

		foreach ( array_keys( self::$held ) as $name ) {
			update_option( 'wrio_fatal_' . substr( $name, strlen( 'wrio_lock_' ) ), $kind, false );
		}
	}

	/**
	 * The option that note_fatal() writes for an item.
	 *
	 * @param string $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return string
	 */
	private static function fatal_name( $source, $object_id ) {
		return 'wrio_fatal_' . $source . '_' . (int) $object_id;
	}

	/**
	 * Number of processes that died with the lock of an item, in a row. An unlock (the item ended) resets it.
	 *
	 * Only a process that holds the lock knows the count: lock() reads it when it takes over an expired lock. The
	 * optimizer requests no formats and the attachment skips its resize after a crash, and the item fails at
	 * MAX_CRASHES.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return int<0, max>
	 */
	public static function crashes( $source, $object_id ): int {
		return self::crashes_of( self::$tokens[ self::lock_name( $source, $object_id ) ] ?? '' );
	}

	/**
	 * The crash count of a lock value. A lock of an earlier version has none.
	 *
	 * @param string $value "expiry:token:crashes".
	 *
	 * @return int<0, max>
	 */
	private static function crashes_of( string $value ): int {
		return max( 0, (int) ( explode( ':', $value )[2] ?? 0 ) );
	}

	/**
	 * Extend the lock of an item before a long step, such as an upload.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return bool False when the lock expired and another process took it: then stop without writing.
	 */
	public static function renew( $source, $object_id ) {
		global $wpdb;

		$name = self::lock_name( $source, $object_id );

		if ( ! isset( self::$tokens[ $name ] ) ) {
			return false;
		}

		$value = ( time() + self::LOCK_TTL ) . ':' . substr( (string) strstr( self::$tokens[ $name ], ':' ), 1 );

		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, self::$tokens[ $name ] ) );

		// Within the same second the value does not change and no row counts as updated: read it back.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) !== $value ) {
			return false;
		}

		self::$tokens[ $name ] = $value;

		return true;
	}

	/**
	 * Release the lock of an item, after as many unlock() calls as lock() calls.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return void
	 */
	public static function unlock( $source, $object_id ) {
		global $wpdb;

		$name = self::lock_name( $source, $object_id );

		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}

		if ( --self::$held[ $name ] > 0 ) {
			return;
		}

		// Only this process's lock: after an expiry, another process can own the row.
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => $name,
				'option_value' => self::$tokens[ $name ] ?? '',
			]
		);
		delete_option( 'wrio_fatal_' . substr( $name, strlen( 'wrio_lock_' ) ) );
		unset( self::$held[ $name ], self::$tokens[ $name ] );
	}

	/**
	 * Whether this request holds the lock of an item. WordPress hooks that fire for the plugin's own writes
	 * (a resize updates the attachment metadata) are ignored with this.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return bool
	 */
	public static function holds_lock( $source, $object_id ) {
		return isset( self::$held[ self::lock_name( $source, $object_id ) ] );
	}

	/**
	 * Run a function under the lock of an item.
	 *
	 * @template T
	 *
	 * @param Source        $source    attachment, cf_image or nextgen.
	 * @param int           $object_id Item ID.
	 * @param callable(): T $work      The work under the lock.
	 *
	 * @return T|null Null when another process holds the lock.
	 */
	public static function with_lock( $source, $object_id, callable $work ) {
		if ( ! self::lock( $source, $object_id ) ) {
			return null;
		}

		try {
			return $work();
		} finally {
			self::unlock( $source, $object_id );
		}
	}

	/**
	 * Whether another process holds a lock of an item that did not expire yet.
	 *
	 * A background worker that finds this lock must keep the item for a later run: after a crash, the lock of the
	 * dead process stays until it expires, and lock() then takes it over and counts the crash.
	 *
	 * @param Source $source    attachment, cf_image or nextgen.
	 * @param int    $object_id Item ID.
	 *
	 * @return bool
	 */
	public static function locked_by_other( $source, $object_id ): bool {
		global $wpdb;

		$name = self::lock_name( $source, $object_id );

		if ( isset( self::$held[ $name ] ) ) {
			return false;
		}

		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return '' !== $value && (int) $value >= time();
	}

	/**
	 * Size and modification time of a file. 0 and 0 when the file does not exist.
	 *
	 * @param string $path File path.
	 * @param bool   $hash Also the content hash, for the rows of the file (see source_changed()).
	 *
	 * @return Fingerprint
	 */
	public static function fingerprint( $path, $hash = false ) {
		if ( '' === wrio_file_path( $path ) ) {
			return [
				'size'  => 0,
				'mtime' => 0,
			];
		}

		clearstatcache( true, $path );

		if ( ! is_file( $path ) ) {
			return [
				'size'  => 0,
				'mtime' => 0,
			];
		}

		$fingerprint = [
			'size'  => (int) filesize( $path ),
			'mtime' => (int) filemtime( $path ),
		];

		if ( $hash ) {
			$fingerprint['hash'] = self::content_hash( $path );
			// content_hash() reads only the ends of a larger file: replaced_inside() checks its middle.
			$fingerprint['full_hash'] = $fingerprint['size'] > self::HASHED_BYTES ? (string) md5_file( $path ) : '';
		}

		return $fingerprint;
	}

	/**
	 * Whether a file has other content than its row although its size and its content_hash() are the same: the
	 * change is in the middle of a file over 128 KiB. It reads the whole file, so only a step that would overwrite
	 * the file calls it, such as a restore. A row without the full hash (saved before 2.1) cannot tell.
	 *
	 * @param array  $row  The row.
	 * @param string $path Source file path.
	 *
	 * @phpstan-param Row $row
	 *
	 * @return bool
	 */
	public static function replaced_inside( array $row, string $path ): bool {
		$full = (string) ( $row['source_full_hash'] ?? '' );

		return '' !== $full && is_file( $path ) && md5_file( $path ) !== $full;
	}

	/**
	 * Hash of the content of a file: its first and last 64 KiB. Every check of a row with the same size reads it,
	 * so it must not read whole files.
	 *
	 * @param string $path File path.
	 *
	 * @return string 32 hex characters, or '' when the file cannot be read.
	 */
	public static function content_hash( string $path ): string {
		try {
			$file = new \SplFileObject( $path, 'rb' );
		} catch ( \RuntimeException $exception ) {
			return '';
		}

		// A change of the same size only between those parts of a larger file: see replaced_inside().
		$head = (string) $file->fread( self::HASHED_BYTES / 2 );
		$tail = 0 === $file->fseek( -self::HASHED_BYTES / 2, SEEK_END ) ? (string) $file->fread( self::HASHED_BYTES / 2 ) : '';

		return md5( $head . $tail );
	}

	/**
	 * Hash of a path relative to the WordPress root, so that a site move keeps it.
	 *
	 * @param string $path File path.
	 *
	 * @return non-empty-string
	 */
	public static function path_hash( $path ) {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( ABSPATH );

		return md5( 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path );
	}

	/**
	 * Copy the fresh ok row of another item with the same file. When the copy cannot be saved, the other item's
	 * row is the result.
	 *
	 * @param array  $item   The item.
	 * @param string $size   Size name.
	 * @param Output $output optimized, webp or avif.
	 * @param string $path   Source file path.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return Row|null The copy, the other item's row, or null without one.
	 */
	public static function share( array $item, $size, $output, $path ) {
		global $wpdb;

		$table = Schema::files_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE path_hash = %s AND format = %s AND status = %s AND NOT ( source = %s AND object_id = %d )", self::path_hash( $path ), $output, self::OK, $item['source'], $item['object_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.

		foreach ( (array) $rows as $row ) {
			if ( self::OK !== self::state( $row, $path ) ) {
				continue;
			}

			$copy = array_intersect_key( $row, array_flip( [ 'original_size', 'final_size', 'source_size', 'source_mtime', 'source_hash', 'source_full_hash', 'mime_type', 'level', 'request_id' ] ) );

			// A failed write: no copy, so no history row of one (transition() logged the failure). The result is
			// still the one of these bytes: sending them again would compress them twice.
			if ( ! self::transition( $item, $size, $output, $path, 'processed', array_merge( $copy, [ 'status' => self::OK ] ) ) ) {
				return $row;
			}

			self::log(
				[
					'action'    => 'share',
					'source'    => $item['source'],
					'object_id' => $item['object_id'],
					'size_name' => $size,
					'status'    => self::OK,
					'detail'    => $row['source'] . ':' . $row['object_id'],
				]
			);

			return self::rows_of( $item, $size, [ $output ] )[ $output ] ?? null;
		}

		return null;
	}

	/**
	 * Rows of one size of an item, by output.
	 *
	 * @param array    $item    The item.
	 * @param string   $size    Size name.
	 * @param Output[] $outputs Outputs.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return array<Output, Row|null>
	 */
	private static function rows_of( array $item, $size, array $outputs ) {
		$rows   = self::rows( $item['source'], $item['object_id'] );
		$result = [];

		foreach ( $outputs as $output ) {
			$result[ $output ] = $rows[ $size . '|' . $output ] ?? null;
		}

		return $result;
	}

	/**
	 * The retry columns of a failed row.
	 *
	 * @param positive-int $failures  Failures in a row, this one included.
	 * @param bool         $permanent Whether the file itself is the problem.
	 * @param string       $code      Failure code.
	 *
	 * @return array{attempts: int<1, 255>, next_attempt_at: string|null}
	 */
	private static function retry( $failures, $permanent, $code ) {
		$waits = ! $permanent && ! in_array( $code, self::PLAN_CODES, true ) && $failures < self::MAX_ATTEMPTS;
		// A file that the time limit or the step budget cut is due for the next step, so a bulk run does not end
		// before it. It still counts as an attempt, so a file that never fits stops after MAX_ATTEMPTS.
		$delay = 'time_limit' === $code ? 0 : self::RETRY_DELAYS[ min( $failures, count( self::RETRY_DELAYS ) ) - 1 ];

		return [
			'attempts'        => min( 255, $failures ),
			'next_attempt_at' => $waits ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : null,
		];
	}

	/**
	 * The outcome of a failure code.
	 *
	 * @param string $code Failure code.
	 *
	 * @return Outcome
	 */
	private static function failure( $code ) {
		return [
			'status'    => 'missing_file' === $code ? self::MISSING : self::FAILED,
			'code'      => $code,
			'permanent' => in_array( $code, self::PERMANENT_CODES, true ),
		];
	}

	/**
	 * Make the failed and missing rows due for a retry now, and log one event.
	 *
	 * @param 'plan_changed'|'errors_reset' $event  plan_changed or errors_reset.
	 * @param string[]|null                 $codes  Only these codes. Null: the non-permanent, non-plan rows whose retry
	 *                                              time is NULL or whose code is a start code (is_start_code()).
	 * @param Source|null                   $source Only this source.
	 * @param int[]                         $absent Attachments whose missing rows stay.
	 *
	 * @return int Number of rows.
	 */
	private static function forget_failures( $event, $codes, $source = null, array $absent = [] ) {
		global $wpdb;

		$table = Schema::files_table();
		// A missing file is checked again too: it can be back.
		$where = $wpdb->prepare( 'status IN (%s, %s) AND permanent = 0', self::FAILED, self::MISSING );

		if ( null !== $codes ) {
			$where .= " AND code IN ('" . implode( "','", array_map( 'esc_sql', $codes ) ) . "')";
		} else {
			// A plan limit waits for a plan change (plan_changed()), not for a new bulk run.
			$where .= " AND code NOT IN ('" . implode( "','", self::PLAN_CODES ) . "')";
			// A failure of the file with a retry time waits for it: Queries::due() picks it then.
			$where .= " AND ( next_attempt_at IS NULL OR code IN ('" . implode( "','", self::start_codes() ) . "') OR code LIKE 'http\\_5%' )";
		}

		if ( null !== $source ) {
			$where .= $wpdb->prepare( ' AND source = %s', $source );
		}

		if ( ! empty( $absent ) ) {
			$where .= " AND NOT ( source = 'attachment' AND status = '" . self::MISSING . "' AND object_id IN (" . implode( ',', array_map( 'intval', $absent ) ) . ') )';
		}

		// Due now, not deleted: a deleted row of a thumbnail would leave its item done (the main file decides), and the
		// bulk queries would never pick it again. A due failure makes the item pending (Queries::due()).
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = 0, next_attempt_at = %s, updated_at = %s WHERE {$where}", self::now(), self::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

		if ( $count > 0 ) {
			self::log(
				[
					'action' => 'cleanup',
					'source' => (string) $source,
					'status' => self::FAILED,
					'code'   => $event,
					'detail' => (string) $count,
				]
			);
		}

		return $count;
	}

	/**
	 * Tell the cached counts that work appeared or went without a queue write (WRIO_Image_Query::clear_cache()).
	 *
	 * @return void
	 */
	private static function changed() {
		/**
		 * Fires after state rows were deleted or marked pending.
		 */
		do_action( 'wrio_storage_changed' );
	}

	/**
	 * Insert or update a row in one statement, on the unique key of the file output.
	 *
	 * @param array $row     All columns but id.
	 * @param bool  $replace Update the row that has the same key. False keeps it.
	 *
	 * @phpstan-param Columns $row
	 *
	 * @return int|bool Rows affected, false on error.
	 */
	private static function upsert( array $row, $replace = true ) {
		global $wpdb;

		$row['updated_at'] = self::now();
		$table             = Schema::files_table();
		$columns           = array_keys( $row );
		$values            = [];

		foreach ( $row as $value ) {
			if ( null === $value ) {
				$values[] = 'NULL';
			} elseif ( is_int( $value ) ) {
				$values[] = $wpdb->prepare( '%d', $value );
			} else {
				$values[] = $wpdb->prepare( '%s', $value );
			}
		}

		$updates = implode( ', ', array_map( fn( $column ) => "{$column} = VALUES({$column})", $columns ) );
		$insert  = $replace ? 'INSERT' : 'INSERT IGNORE';
		$sql     = "{$insert} INTO {$table} (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')' . ( $replace ? " ON DUPLICATE KEY UPDATE {$updates}" : '' );

		return self::query_with_repair(
			function () use ( $wpdb, $sql ) {
				return $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each value is prepared above.
			}
		);
	}

	/**
	 * Run a query, and create the tables and run it again when they do not exist (for example on a site of a
	 * network that nobody opened in the admin since the update).
	 *
	 * @param callable(): (int|bool) $query The query.
	 *
	 * @return int|bool The result of the query.
	 */
	private static function query_with_repair( callable $query ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$result   = $query();

		if ( false === $result && false !== stripos( (string) $wpdb->last_error, "doesn't exist" ) ) {
			Schema::install();
			$result = $query();
		}

		$wpdb->suppress_errors( $suppress );

		return $result;
	}

	/**
	 * The worst status of some outputs, for one history row.
	 *
	 * @param array<'ok'|'skipped'|'failed'|'missing'> $statuses Statuses.
	 *
	 * @return 'ok'|'skipped'|'failed'|'missing'|''
	 */
	private static function worst( array $statuses ) {
		foreach ( [ self::FAILED, self::MISSING, self::SKIPPED, self::OK ] as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}

		return '';
	}

	/**
	 * The history action of a delete event.
	 *
	 * @param ForgetEvent|PendingEvent $event Event.
	 *
	 * @return 'restore'|'delete'|'edit'|'replace'|'cleanup'
	 */
	private static function action_of( $event ) {
		$actions = [
			'restored'     => 'restore',
			'deleted'      => 'delete',
			'edited'       => 'edit',
			'replaced'     => 'replace',
			'size_removed' => 'edit',
		];

		return $actions[ $event ] ?? 'cleanup';
	}

	/**
	 * Option name of an item lock.
	 *
	 * @param Source $source    Source.
	 * @param int    $object_id Item ID.
	 *
	 * @return non-empty-string
	 */
	private static function lock_name( $source, $object_id ) {
		return 'wrio_lock_' . $source . '_' . (int) $object_id;
	}

	/**
	 * One ID for the history rows of this request.
	 *
	 * @return string
	 */
	public static function run_id() {
		if ( null === self::$run_id ) {
			self::$run_id = wp_generate_uuid4();
		}

		return self::$run_id;
	}

	/**
	 * What started this request: cli, cron, ajax, rest or web.
	 *
	 * @return 'cli'|'cron'|'ajax'|'rest'|'web'
	 */
	private static function started_by() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : 'web';
	}

	/**
	 * The current time in UTC, in the MySQL format.
	 *
	 * @return string
	 */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
