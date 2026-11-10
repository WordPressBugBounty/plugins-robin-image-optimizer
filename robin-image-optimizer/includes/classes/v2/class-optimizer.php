<?php
/**
 * Optimizer of the v2 pipeline.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\V2;

use WP_Error;
use WRIO\Storage\Store;
use WRIO_Plugin;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizes and converts image files with the v2 API.
 *
 * One request per file asks for every output: "optimized" when the file is to be optimized,
 * and each format. The requests run in parallel. Then the results go to disk: the optimized
 * file replaces the file, and each format is saved as <file>.<format> (image.jpg.webp),
 * where WebP/AVIF delivery looks for it.
 *
 * @phpstan-import-type OutputResult from Client
 * @phpstan-import-type Item from Store
 * @phpstan-import-type Source from Store
 * @phpstan-import-type Output from Store
 * @phpstan-import-type Format from Store
 * @phpstan-import-type State from Store
 * @phpstan-type FileSpec array{path: string, url: string, optimize: bool, object_id?: int|null}
 * @phpstan-import-type Status from Store
 * @phpstan-import-type Row from Store
 * @phpstan-type StoredResult array{status: Status, reason?: string, stored: true, original_size: int, final_size: int, error?: array{code: string}}
 * @phpstan-type FileResult array<Output, OutputResult|StoredResult>
 * @phpstan-type Summary array{error: WP_Error|null, original_size: int, optimized_size: int, optimized_files: int, original_main_size: int|null}
 */
class Optimizer {

	/**
	 * Optimization levels of the plugin and their v2 names. The plugin stores "aggresive" with a typo.
	 */
	const LEVELS = [
		'normal'     => 'normal',
		'aggresive'  => 'aggressive',
		'aggressive' => 'aggressive',
		'ultra'      => 'ultra',
		'googlepage' => 'googlepage',
	];

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Formats of the current run. With the enabled ones, the formats that delivery can serve.
	 *
	 * @var Format[]
	 */
	private array $formats = [];

	/**
	 * Build an optimizer.
	 *
	 * @param Client|null $client API client. The default uses the site settings.
	 */
	public function __construct( $client = null ) {
		$this->client = $client ? $client : Client::from_settings();
	}

	/**
	 * Optimize an item's files, convert them to the formats, and save the state rows and the format rows.
	 *
	 * Every item type (attachment, cf_image, nextgen) uses this. The item only writes its own row from the summary.
	 * Outputs that are already done (see WRIO\Storage\Store::plan()) are not requested again: a file is never
	 * compressed twice.
	 *
	 * @param Source                  $item_type    attachment, cf_image or nextgen.
	 * @param int                     $object_id    Item ID: the attachment, the cf_image queue row or the NextGEN image.
	 * @param array<string, FileSpec> $files        Files by size name. "original" is the main file.
	 * @param string|int|null         $level        See run().
	 * @param array<string, int>      $sizes_before Sizes before an earlier step, such as the resize of the main file. Other files are measured here.
	 * @param bool                    $backed_up    Whether the item has a backup.
	 *
	 * @return Summary The error "item_locked" means that another process optimizes the item now. original_main_size
	 *                 is the main file's size before its first optimization, when it was optimized earlier.
	 */
	public function optimize_item( $item_type, $object_id, array $files, $level = null, array $sizes_before = [], $backed_up = false ) {
		// The lock covers the crash count too: taking over the lock of a process that died counts its crash.
		if ( ! Store::lock( $item_type, (int) $object_id ) ) {
			return self::empty_summary( Client::error( 'item_locked', __( 'The image is being optimized by another process.', 'robin-image-optimizer' ) ) );
		}

		try {
			return $this->optimize_locked( $item_type, (int) $object_id, $files, $level, $sizes_before, $backed_up );
		} finally {
			Store::unlock( $item_type, (int) $object_id );
		}
	}

	/**
	 * Save the "crashed" failure of an item whose optimization stopped PHP MAX_CRASHES times (see Store::crashed()).
	 *
	 * @param array  $item The item.
	 * @param string $path Main file path.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return WP_Error The error for the item's own row.
	 */
	public static function crashed( array $item, $path ) {
		$code = Store::crashed( $item, $path );

		return Client::error( $code, wrio_error_message( 'failed', $code ) );
	}

	/**
	 * A summary without results.
	 *
	 * @param WP_Error|null $error The error.
	 *
	 * @return Summary
	 */
	private static function empty_summary( $error = null ) {
		return [
			'error'              => $error,
			'original_size'      => 0,
			'optimized_size'     => 0,
			'optimized_files'    => 0,
			'original_main_size' => null,
		];
	}

	/**
	 * The work of optimize_item(), under the item lock.
	 *
	 * @param Source                  $item_type    attachment, cf_image or nextgen.
	 * @param int                     $object_id    Item ID.
	 * @param array<string, FileSpec> $files        Files by size name.
	 * @param string|int|null         $level        See run().
	 * @param array<string, int>      $sizes_before Sizes before an earlier step.
	 * @param bool                    $backed_up    Whether the item has a backup.
	 *
	 * @return Summary
	 */
	private function optimize_locked( $item_type, $object_id, array $files, $level, array $sizes_before, $backed_up ) {
		foreach ( $files as $size => $file ) {
			$sizes_before[ $size ] = $sizes_before[ $size ] ?? wrio_get_file_size( $file['path'] );
		}

		// A process died on this item (a time limit, the memory limit): retry without the formats, then fail it until
		// its next retry.
		$crashes = Store::crashes( $item_type, (int) $object_id );
		$formats = $crashes ? [] : \WRIO_Formats::get_enabled_formats();
		$item    = [
			'source'    => $item_type,
			'object_id' => (int) $object_id,
			'level'     => (string) self::stored_level( $level ),
			'backed_up' => (bool) $backed_up,
		];
		if ( $crashes >= Store::MAX_CRASHES ) {
			return self::empty_summary( self::crashed( $item, $files['original']['path'] ?? '' ) );
		}

		$summary = self::empty_summary();
		$results = $this->process( $item, $files, $formats, $level, $sizes_before, 'optimize' );

		if ( is_wp_error( $results ) ) {
			$summary['error'] = $results;

			return $summary;
		}

		Format_Rows::save( $item_type, (int) $object_id, $files, $results, $formats );

		$summary['error'] = self::file_error( $results['original'] ?? null );

		foreach ( $files as $size => $file ) {
			// A file that failed keeps its content and does not count.
			if ( ! $file['optimize'] || self::file_error( $results[ $size ] ?? null ) ) {
				continue;
			}

			$optimized = is_array( $results[ $size ] ) ? ( $results[ $size ]['optimized'] ?? [] ) : [];

			if ( ! empty( $optimized['stored'] ) ) {
				// Optimized earlier: the row has the sizes. The file on disk is the optimized one.
				$summary['original_size']  += (int) ( $optimized['original_size'] ?? 0 );
				$summary['optimized_size'] += (int) ( $optimized['final_size'] ?? 0 );

				if ( 'original' === $size ) {
					$summary['original_main_size'] = (int) ( $optimized['original_size'] ?? 0 );
				}
			} else {
				// A file that the server could not make smaller counts with its own size.
				$summary['original_size']  += $sizes_before[ $size ];
				$summary['optimized_size'] += min( $sizes_before[ $size ], wrio_get_file_size( $file['path'] ) );
			}

			++$summary['optimized_files'];
		}

		return $summary;
	}

	/**
	 * Convert an item's files to formats, without optimizing them, and save the state rows and the format rows.
	 *
	 * @param Source                  $item_type attachment, cf_image or nextgen.
	 * @param int                     $object_id Item ID.
	 * @param array<string, FileSpec> $files     Files by size name.
	 * @param Format[]                $formats   webp, avif.
	 *
	 * @return WP_Error|null The error of the whole request (the API, the item lock), when no row is written. Else the
	 *                       error of the main file, or null.
	 */
	public function convert_item( $item_type, $object_id, array $files, array $formats ) {
		foreach ( $files as $size => $file ) {
			$files[ $size ]['optimize'] = false;
		}

		$item    = [
			'source'    => $item_type,
			'object_id' => (int) $object_id,
		];
		$results = $this->process( $item, $files, $formats, null, [], 'convert' );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		Format_Rows::save( $item_type, (int) $object_id, $files, $results, $formats );

		return is_wp_error( $results['original'] ?? null ) ? $results['original'] : null;
	}

	/**
	 * Whether an error of optimize_item() means that another process optimizes the item now, or that its state
	 * could not be read. Nothing was written: the item is tried again later.
	 *
	 * @param WP_Error|null $error The error.
	 *
	 * @return bool
	 */
	public static function is_locked( $error ) {
		return $error instanceof WP_Error && in_array( $error->get_error_code(), [ 'wrio_v2_item_locked', 'wrio_v2_state_unavailable' ], true );
	}

	/**
	 * Request the outputs of an item that are not done, under the item lock, and add the done outputs to the results.
	 *
	 * @param array                   $item         Item: source, object_id, level, backed_up.
	 * @param array<string, FileSpec> $files        Files by size name.
	 * @param Format[]                $formats      Formats.
	 * @param string|int|null         $level        See run().
	 * @param array<string, int>      $sizes_before Sizes of the files before the optimization.
	 * @param 'optimize'|'convert'    $action       optimize or convert, for the history.
	 *
	 * @phpstan-param Item $item
	 *
	 * @return array<string, FileResult|WP_Error>|WP_Error Results by size name, or the error "item_locked" or "state_unavailable".
	 */
	private function process( array $item, array $files, array $formats, $level, array $sizes_before, $action ) {
		if ( ! Store::lock( $item['source'], $item['object_id'] ) ) {
			return Client::error( 'item_locked', __( 'The image is being optimized by another process.', 'robin-image-optimizer' ) );
		}

		$this->formats = $formats;

		try {
			$wanted = [];

			foreach ( $files as $size => $file ) {
				$wanted[ $size ] = array_merge( $file['optimize'] ? [ 'optimized' ] : [], $formats );
			}

			$plan = Store::plan( $item, $files, array_filter( $wanted ), 'optimize' === $action );

			// The old rows could not be copied: a file they say is optimized would be compressed twice.
			if ( null === $plan ) {
				return Client::error( 'state_unavailable', __( 'The optimization state of the image could not be saved. It is tried again later.', 'robin-image-optimizer' ) );
			}

			// The uploads can take minutes. A process that lost its lock to another one stops before writing.
			if ( ! empty( $plan['request'] ) && ! Store::renew( $item['source'], $item['object_id'] ) ) {
				return Client::error( 'item_locked', __( 'The image is being optimized by another process.', 'robin-image-optimizer' ) );
			}

			$results = $this->request(
				$files,
				$plan['request'],
				$level,
				[
					'item'         => $item,
					'sizes_before' => $sizes_before,
					'action'       => $action,
				]
			);

			if ( is_wp_error( $results ) ) {
				return $results;
			}

			foreach ( $plan['done'] as $size => $rows ) {
				$results[ $size ] = $results[ $size ] ?? [];

				// Some outputs were done and the request for the others failed: keep the done ones (an optimization,
				// or a WebP when AVIF failed). An error of a requested optimization stays the error of the file.
				if ( is_wp_error( $results[ $size ] ) && ! in_array( 'optimized', $plan['request'][ $size ] ?? [], true ) ) {
					$results[ $size ] = array_fill_keys( $plan['request'][ $size ] ?? [], self::failed( $results[ $size ] ) );
				}

				foreach ( $rows as $output => $row ) {
					if ( Store::MISSING === $row['status'] ) {
						// translators: %s is the file path.
						$results[ $size ] = Client::error( 'missing_file', sprintf( __( 'The file %s does not exist.', 'robin-image-optimizer' ), $files[ $size ]['path'] ), [ 'permanent' => true ] );
						break;
					}

					// apply() rechecked a done WebP or AVIF against the new results, and dropped it.
					if ( is_array( $results[ $size ] ) && ! isset( $results[ $size ][ $output ] ) ) {
						$results[ $size ][ $output ] = [
							'status'        => (string) $row['status'],
							'reason'        => (string) $row['code'],
							'stored'        => true,
							'original_size' => (int) $row['original_size'],
							'final_size'    => (int) $row['final_size'],
						];

						// A failure that waits for its retry (see Store::plan()) keeps its code for file_error().
						if ( Store::FAILED === $row['status'] ) {
							$results[ $size ][ $output ]['error'] = [ 'code' => (string) $row['code'] ];
						}
					}
				}
			}

			return $results;
		} finally {
			Store::unlock( $item['source'], $item['object_id'] );
		}
	}

	/**
	 * Whether a new optimization of a done item starts from its original (a restore).
	 *
	 * At another level it does. Otherwise a changed or failed file of a done item is processed as it is: a restore
	 * would put old files back and send the done files again.
	 *
	 * @param Source          $source       attachment, cf_image or nextgen.
	 * @param int             $object_id    Item ID.
	 * @param string|int|null $level        Requested level. Empty: the setting, no new level.
	 * @param string|int|null $stored_level Level of the queue row.
	 *
	 * @return bool
	 */
	public static function restores_first( $source, $object_id, $level, $stored_level ) {
		if ( '' !== (string) $level && (string) self::stored_level( $level ) !== (string) $stored_level ) {
			return true;
		}

		return ! Store::has_work( (string) $source, (int) $object_id );
	}

	/**
	 * Whether an item can be optimized at another level: its main file is not an optimized file without a backup.
	 * A skipped main file was never compressed, so it is its own original.
	 *
	 * @param Source                  $source    attachment, cf_image or nextgen.
	 * @param int                     $object_id Item ID.
	 * @param array<string, Row>|null $rows      The item's rows when they are read already.
	 *
	 * @return bool
	 */
	public static function has_original( $source, $object_id, $rows = null ) {
		$main = ( $rows ?? Store::rows( (string) $source, (int) $object_id ) )['original|optimized'] ?? null;

		return ! $main || Store::OK !== $main['status'] || (int) $main['backed_up'];
	}

	/**
	 * The error of a new level for an item whose main file is optimized and has no backup (backups were off, or
	 * cleared). The restore cannot put the original back, and a new level of the optimized file would compress it
	 * twice. Null when the level can change.
	 *
	 * @param Source          $source       attachment, cf_image or nextgen.
	 * @param int             $object_id    Item ID.
	 * @param string|int|null $level        Requested level. Empty: the setting, no new level.
	 * @param string|int|null $stored_level Level of the queue row.
	 *
	 * @return WP_Error|null
	 */
	public static function no_original( $source, $object_id, $level, $stored_level ) {
		if ( '' === (string) $level || (string) self::stored_level( $level ) === (string) $stored_level || self::has_original( $source, $object_id ) ) {
			return null;
		}

		return new WP_Error( 'wrio_no_original', __( 'The original of this image is not kept, so it cannot be optimized at another level.', 'robin-image-optimizer' ) );
	}

	/**
	 * The level to store in a queue row: the setting when empty, and the quality number for "custom".
	 *
	 * @param string|int|null $level Plugin level, or empty for the setting.
	 *
	 * @return string|int
	 */
	public static function stored_level( $level = null ) {
		if ( null === $level || '' === $level ) {
			$level = WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level', 'normal' );
		}

		// A filter can return anything: the default level then.
		if ( ! is_string( $level ) && ! is_int( $level ) ) {
			$level = 'normal';
		}

		if ( 'custom' === $level ) {
			return (int) WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level_custom', wrio_get_default( 'image_optimization_level_custom' ) );
		}

		return $level;
	}

	/**
	 * The error of a file's "optimized" output, or null.
	 *
	 * @param FileResult|WP_Error|null $result Result of the file from run(). Null: the file got no result.
	 *
	 * @return WP_Error|null
	 */
	public static function file_error( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$output = $result['optimized'] ?? [ 'status' => 'failed' ];

		if ( 'failed' !== $output['status'] ) {
			return null;
		}

		$detail = (string) ( $output['error']['detail'] ?? '' );

		return Client::error(
			(string) ( $output['error']['code'] ?? 'processing_failed' ),
			'' !== $detail ? $detail : __( 'The optimization server could not optimize the image.', 'robin-image-optimizer' ),
			[ 'retryable' => true ]
		);
	}

	/**
	 * Optimize and convert files.
	 *
	 * @param array<string, FileSpec> $files   Files by size name ("original", "thumbnail", ...).
	 * @param Format[]                $formats Formats to make: webp, avif.
	 * @param string|int|null         $level   Plugin level (normal, aggresive, ultra, googlepage, custom) or a quality from 1 to 100. Null uses the settings.
	 *
	 * @return array<string, FileResult|WP_Error> Results by size name. A file with nothing to request has no result.
	 */
	public function run( array $files, array $formats, $level = null ) {
		$this->formats = $formats;
		$outputs       = [];

		foreach ( $files as $size => $file ) {
			$outputs[ $size ] = array_merge( $file['optimize'] ? [ 'optimized' ] : [], $formats );
		}

		$results = $this->request( $files, array_filter( $outputs ), $level );

		// Without a record nothing is locked: the request never returns the lock error.
		return is_wp_error( $results ) ? [] : $results;
	}

	/**
	 * Send one request per file and write the results to disk. With a record, also save the state rows.
	 *
	 * @param array<string, FileSpec> $files   Files by size name.
	 * @param array<string, Output[]> $outputs Outputs to request, by size name.
	 * @param string|int|null         $level   See run().
	 * @param array|null              $record  item, sizes_before and action, to save the state rows.
	 *
	 * @phpstan-param array{item: Item, sizes_before: array<string, int>, action: 'optimize'|'convert'}|null $record
	 *
	 * @return array<string, FileResult|WP_Error>|WP_Error Results by size name, or the error "item_locked" when the
	 *                                                    lock was lost: the files written before the loss keep
	 *                                                    their files and rows, and this file and the later ones
	 *                                                    are not written.
	 */
	private function request( array $files, array $outputs, $level, $record = null ) {
		$options  = $this->options( $level );
		$requests = [];
		$skipped  = [];

		foreach ( $outputs as $size => $wanted ) {
			$path = $files[ $size ]['path'];

			// GIF images are not converted (see wrio_get_conversion_formats()). The skipped rows make the formats done.
			$skipped[ $size ]  = 'image/gif' === wp_check_filetype( $path )['type'] ? array_intersect( $wanted, [ 'webp', 'avif' ] ) : [];
			$requests[ $size ] = array_merge(
				$options,
				[
					'path'    => $path,
					'outputs' => array_values( array_diff( $wanted, $skipped[ $size ] ) ),
				]
			);
		}

		if ( empty( $requests ) ) {
			return [];
		}

		$sent        = array_filter( $requests, fn( $request ) => ! empty( $request['outputs'] ) );
		$limits      = $sent && 0 === Client::backoff_seconds() ? $this->client->limits() : null;
		$concurrency = is_array( $limits ) ? $limits['max_concurrency'] : count( $requests );
		$results     = [];

		// Save each batch before new uploads use the time left to download its results.
		foreach ( array_chunk( $requests, $concurrency, true ) as $batch ) {
			$sent     = array_filter( $batch, fn( $request ) => ! empty( $request['outputs'] ) );
			$started  = microtime( true );
			$answers  = is_wp_error( $limits ) ? array_fill_keys( array_keys( $sent ), $limits ) : ( $sent ? $this->client->optimize( $sent ) : [] );
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

			foreach ( $batch as $size => $request ) {
				if ( ! isset( $sent[ $size ] ) ) {
					$answers[ $size ] = [
						'original'   => [
							'size' => (int) wrio_get_file_size( $request['path'] ),
							'type' => 'image/gif',
						],
						'outputs'    => [],
						'expires_at' => null,
					];
				}

				$answer = $answers[ $size ] ?? Client::error( 'no_answer', __( 'The optimization server did not answer for this file.', 'robin-image-optimizer' ), [ 'retryable' => true ] );

				// The uploads and downloads can outlast the lock. Before this file's files and rows change, the lock must
				// still be this process's: after an expiry, another process owns the item and its temporary files.
				if ( $record && ! Store::renew( $record['item']['source'], $record['item']['object_id'] ) ) {
					return Client::error( 'item_locked', __( 'The image is being optimized by another process.', 'robin-image-optimizer' ) );
				}

				if ( is_wp_error( $answer ) ) {
					if ( $record ) {
						Store::save_error( $record['item'], (string) $size, $request['path'], $request['outputs'], $answer, $record['action'] );
					}

					$results[ $size ] = $answer;
					continue;
				}

				$context = $record ? [
					'item'    => $record['item'],
					'size'    => (string) $size,
					'context' => [
						'original_size' => (int) ( $record['sizes_before'][ $size ] ?? $answer['original']['size'] ),
						'mime_type'     => (string) $answer['original']['type'],
						'request_id'    => (string) ( $answer['request_id'] ?? '' ),
						'duration_ms'   => $duration,
						'action'        => $record['action'],
					],
				] : null;

				$answer['outputs'] += array_fill_keys(
					$skipped[ $size ],
					[
						'status' => 'skipped',
						'reason' => 'gif',
					]
				);

				$results[ $size ] = $this->apply( $request['path'], $answer['outputs'], $context );
			}
		}

		return $results;
	}

	/**
	 * Write the "ok" outputs of one file to disk, and save its state rows.
	 *
	 * The order makes a crash safe. The format files go first: they do not change the source, so after a crash
	 * they are only converted again. Then the rows are saved with the fingerprint of the new source, and only then
	 * does the optimized file replace the source. After a crash between the last two steps, the fingerprint is not
	 * the file on disk: the row is stale, and the file is optimized again from the true original, not twice.
	 *
	 * @param string                      $path    Source file path.
	 * @param array<Output, OutputResult> $outputs Output results.
	 * @param array|null                  $record  item, size and context for WRIO\Storage\Store::save_file().
	 *
	 * @phpstan-param array{item: Item, size: string, context: array{original_size: int, mime_type: string, request_id: string, duration_ms: int, action: 'optimize'|'convert', states?: array<Output, State>}}|null $record
	 *
	 * @return array<Output, OutputResult|StoredResult> The results, and the results that another item saved for the
	 *         same file. A result file that cannot be saved becomes "failed".
	 */
	private function apply( $path, array $outputs, $record = null ) {
		$staged = [];
		$shared = [];

		// Another item with the same file (a WPML copy) saved a result for it while this request ran: that result
		// stays. This one can be of the bytes that the other item already compressed.
		foreach ( array_keys( $outputs ) as $output ) {
			$row = $record ? Store::share( $record['item'], $record['size'], (string) $output, $path ) : null;

			if ( $row ) {
				$shared[ $output ] = [
					'status'        => (string) $row['status'],
					'stored'        => true,
					'original_size' => (int) $row['original_size'],
					'final_size'    => (int) $row['final_size'],
				];
			}
		}

		list( $outputs, $losers ) = $this->keep_smaller( $path, array_diff_key( $outputs, $shared ) );

		foreach ( $outputs as $output => $result ) {
			$target = 'optimized' === $output ? $path : $path . '.' . $output;

			// A format file from an earlier conversion is not smaller than this file any more. Do not deliver it.
			// The files that keep_smaller() dropped wait for their rival (see below).
			if ( 'skipped' === $result['status'] && 'optimized' !== $output && ! isset( $losers[ $output ] ) && file_exists( $target ) ) {
				wp_delete_file( $target );
			}

			if ( 'ok' !== $result['status'] ) {
				continue;
			}

			$temporary = $this->stage( $result, $target );

			if ( is_wp_error( $temporary ) ) {
				$outputs[ $output ] = self::failed( $temporary );
			} else {
				$staged[ $output ] = $temporary;
			}
		}

		self::spare( $outputs, $losers );

		// A failed download leaves other files to beat: the downloaded formats are judged again against the files
		// that stay, for example an AVIF against the old WebP when the new WebP could not be downloaded.
		list( $judged, $late ) = $this->keep_smaller( $path, $outputs );

		foreach ( array_diff_key( $late, $losers ) as $output => $rival ) {
			if ( isset( $staged[ $output ] ) ) {
				wp_delete_file( $staged[ $output ] );
				unset( $staged[ $output ] );
			}

			$outputs[ $output ] = $judged[ $output ] ?? $outputs[ $output ];
			$losers[ $output ]  = $rival;
		}

		$sizes = array_map( 'wrio_get_file_size', $staged );

		// The states before the format files are committed: a committed file makes a stale format row look fresh.
		if ( $record ) {
			$record['context']['states'] = Store::states( $record['item'], $record['size'], $path, array_keys( $outputs ) );
		}

		foreach ( $staged as $output => $temporary ) {
			if ( 'optimized' !== $output && ! self::commit( $temporary, $path . '.' . $output ) ) {
				$outputs[ $output ] = self::failed( self::save_error( $path . '.' . $output ) );
				unset( $staged[ $output ] );
			}
		}

		self::spare( $outputs, $losers );

		foreach ( array_keys( $losers ) as $output ) {
			if ( file_exists( $path . '.' . $output ) ) {
				wp_delete_file( $path . '.' . $output );
			}
		}

		$rechecked = array_filter( $outputs, fn( $result ) => ! empty( $result['recheck'] ) );
		$source    = Store::fingerprint( $staged['optimized'] ?? $path, true );

		$saved = $record ? Store::save_file( $record['item'], $record['size'], $path, array_map( [ Store::class, 'outcome' ], array_diff_key( $outputs, $rechecked ) ), $source, $record['context'], $sizes ) : [];

		if ( $record ) {
			// keep_smaller() rechecks formats only.
			foreach ( array_keys( $rechecked ) as $output ) {
				if ( 'optimized' !== $output ) {
					Store::drop_format( $record['item'], $record['size'], $output );
				}
			}
		}

		if ( ! isset( $staged['optimized'] ) ) {
			return $shared + $outputs;
		}

		// Without its state row, the compressed file would look like an original and be compressed again.
		if ( $record && empty( $saved['optimized'] ) ) {
			wp_delete_file( $staged['optimized'] );
			$outputs['optimized'] = self::failed( self::save_error( $path ) );

			return $shared + $outputs;
		}

		// A WebP or AVIF of an earlier attempt is of the same image: it stays done after the rename.
		if ( $record ) {
			// The dropped formats too: their skipped rows describe the new file.
			Store::carry( $record['item'], $record['size'], $path, $source, array_keys( array_diff_key( $outputs, $rechecked ) ) );
		}

		if ( ! self::commit( $staged['optimized'], $path ) ) {
			$outputs['optimized'] = self::failed( self::save_error( $path ) );

			if ( $record ) {
				Store::save_file( $record['item'], $record['size'], $path, [ 'optimized' => Store::outcome( $outputs['optimized'] ) ], Store::fingerprint( $path ), $record['context'] );
			}

			return $shared + $outputs;
		}

		$written = Store::fingerprint( $path, true );

		if ( $record && $written !== $source ) {
			Store::touch( $record['item'], $record['size'], $written );
		}

		return $shared + $outputs;
	}

	/**
	 * Drop a WebP or AVIF that is not smaller than the file a browser would get without it, and an optimized file
	 * that is not smaller than the source.
	 *
	 * The server compares each output with the uploaded file only, so at a high compression level its WebP can be
	 * larger than its optimized JPEG. A WebP must beat the optimized file (or the source, when it is not optimized).
	 * An AVIF must beat the WebP too: a browser that reads AVIF reads WebP. The rival is the file a browser gets: a
	 * new result, or the file on disk of a format that delivery serves. A new result is judged on the size the
	 * server reported, before any download, so a dropped one is never downloaded.
	 *
	 * The files on disk are judged too: an AVIF of an earlier run against a new WebP, a WebP against a new
	 * optimized file. A dropped file of a format that this run did not request is returned as "skipped:
	 * not_smaller" with "recheck", so that its rows change too.
	 *
	 * @param string                      $path    Source file path.
	 * @param array<Output, OutputResult> $outputs Output results.
	 *
	 * @return array{0: array<Output, OutputResult>, 1: array<Format, Output|null>} The results, and the formats
	 *                                                                             whose file goes, with the new
	 *                                                                             output it lost to (null: a file
	 *                                                                             on disk or the source).
	 */
	private function keep_smaller( string $path, array $outputs ): array {
		/**
		 * The part of the size that a WebP or AVIF must save to be kept: 0.05 keeps a file at least 5% smaller than
		 * the one it replaces. A negative value keeps every file.
		 *
		 * @param float $margin Margin, from 0 to 1.
		 */
		$margin    = (float) apply_filters( 'wrio_format_min_saving', 0.05 );
		$optimized = $outputs['optimized'] ?? null;
		$new       = $optimized && 'ok' === $optimized['status'] && ! empty( $optimized['size'] );
		$source    = (int) wrio_get_file_size( $path );

		// An optimized file that is not smaller never replaces the source, whatever the server says.
		if ( $new && $source > 0 && (int) $optimized['size'] >= $source ) {
			$outputs['optimized'] = [
				'status' => 'skipped',
				'reason' => 'not_smaller',
			];
			$new                  = false;
		}

		$rival  = $new ? (int) $optimized['size'] : $source;
		$from   = $new ? 'optimized' : null;
		$losers = [];

		if ( $margin < 0 || $rival <= 0 ) {
			return [ $outputs, $losers ];
		}

		$served = array_merge( $this->formats, \WRIO_Formats::get_enabled_formats() );

		foreach ( [ 'webp', 'avif' ] as $format ) {
			$result = $outputs[ $format ] ?? null;
			$file   = $path . '.' . $format;

			if ( $result && 'ok' === $result['status'] && ! empty( $result['size'] ) ) {
				if ( self::saves( (int) $result['size'], $rival, $margin ) ) {
					$rival = (int) $result['size'];
					$from  = $format;
					continue;
				}

				$outputs[ $format ] = array_filter(
					[
						'status' => 'skipped',
						'reason' => 'not_smaller',
						'rival'  => $from,
					]
				);
				$losers[ $format ]  = $from;
				continue;
			}

			// A skipped result deletes its file. Without a new file, the old one is what delivery serves, when it
			// serves the format at all.
			if ( ( $result && 'skipped' === $result['status'] ) || ! in_array( $format, $served, true ) || ! is_file( $file ) ) {
				continue;
			}

			$size = (int) wrio_get_file_size( $file );

			if ( self::saves( $size, $rival, $margin ) ) {
				$rival = $size;
				$from  = null;
				continue;
			}

			$losers[ $format ] = $from;

			// A failed result keeps its failure: the format is sent again. A format that this run did not request
			// is done: its rows say that its file is gone.
			if ( ! $result ) {
				$outputs[ $format ] = array_filter(
					[
						'status'  => 'skipped',
						'reason'  => 'not_smaller',
						'rival'   => $from,
						'recheck' => true,
					]
				);
			}
		}

		return [ $outputs, $losers ];
	}

	/**
	 * Keep the files that lost to a rival that is not saved: a dropped earlier file is not dropped, and a dropped
	 * new result gets the rival's failure, so that it is sent again with it.
	 *
	 * @param array<Output, OutputResult> $outputs Output results.
	 * @param array<Format, Output|null>  $losers  Formats whose file goes, with the output it lost to.
	 *
	 * @return void
	 */
	private static function spare( array &$outputs, array &$losers ): void {
		foreach ( $losers as $output => $rival ) {
			if ( null === $rival || 'ok' === $outputs[ $rival ]['status'] ) {
				continue;
			}

			if ( ! empty( $outputs[ $output ]['recheck'] ) ) {
				unset( $outputs[ $output ] );
			} elseif ( isset( $outputs[ $output ]['rival'] ) ) {
				$outputs[ $output ] = $outputs[ $rival ];
			}

			unset( $losers[ $output ] );
		}
	}

	/**
	 * Whether a file saves the margin over its rival. At 5%, a 950-byte file saves it over 1,000 bytes.
	 *
	 * @param int   $size   File size.
	 * @param int   $rival  Size of the file it replaces.
	 * @param float $margin Margin, from 0 to 1.
	 *
	 * @return bool
	 */
	private static function saves( int $size, int $rival, float $margin ): bool {
		return $size < $rival && $size <= $rival * ( 1 - $margin ) + 0.000001;
	}

	/**
	 * Download a result file next to its target, and check it.
	 *
	 * Its size and image type must be the ones the server reported. Then an error page from a proxy never replaces an image.
	 *
	 * @param array  $result An "ok" output result.
	 * @param string $target File the result is for.
	 *
	 * @phpstan-param OutputResult $result
	 *
	 * @return string|WP_Error The temporary file.
	 */
	private function stage( array $result, $target ) {
		$contents = $this->client->download( (string) ( $result['url'] ?? '' ) );

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		// Next to the target, on the same file system: a rename then keeps the modification time of the fingerprint.
		$temporary = $target . '.wrio-tmp';

		// A symlink left in its place would take the write to the file it points to.
		if ( is_link( $temporary ) ) {
			wp_delete_file( $temporary );
		}

		if ( false === file_put_contents( $temporary, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return self::save_error( $target );
		}

		$type = (string) wp_get_image_mime( $temporary );

		if ( (int) ( $result['size'] ?? -1 ) !== strlen( $contents ) || (string) ( $result['type'] ?? '' ) !== $type ) {
			wp_delete_file( $temporary );

			return Client::error(
				'invalid_download',
				sprintf(
					// translators: 1: size in bytes, 2: image type such as image/webp.
					__( 'The downloaded file is not the optimized image (size in bytes: %1$d, type: "%2$s").', 'robin-image-optimizer' ),
					strlen( $contents ),
					$type
				),
				[ 'retryable' => true ]
			);
		}

		return $temporary;
	}

	/**
	 * Move a temporary file over its target, with the permissions of the target. rename() replaces a link at the
	 * target, and does not write through it. WIO_Backup uses it too.
	 *
	 * @param string $temporary Temporary file.
	 * @param string $target    Target.
	 *
	 * @return bool
	 */
	public static function commit( $temporary, $target ) {
		// rename() warns on these, and fails.
		if ( is_dir( $target ) || ! wp_is_writable( dirname( $target ) ) ) {
			wp_delete_file( $temporary );

			return false;
		}

		if ( file_exists( $target ) ) {
			$permissions = fileperms( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms

			if ( false === $permissions || ! chmod( $temporary, $permissions & 0777 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
				wp_delete_file( $temporary );

				return false;
			}
		}

		if ( rename( $temporary, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}

		wp_delete_file( $temporary );

		return false;
	}

	/**
	 * The error of a file that could not be written.
	 *
	 * @param string $target File path.
	 *
	 * @return WP_Error
	 */
	private static function save_error( $target ) {
		// translators: %s is the file path.
		return Client::error( 'save_failed', sprintf( __( 'The optimized file could not be saved to %s.', 'robin-image-optimizer' ), $target ) );
	}

	/**
	 * A failed output result from an error.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return OutputResult
	 */
	private static function failed( WP_Error $error ) {
		$data = $error->get_error_data();

		return [
			'status' => 'failed',
			'error'  => [
				'code'   => is_array( $data ) && is_scalar( $data['code'] ?? null ) ? (string) $data['code'] : (string) $error->get_error_code(),
				'detail' => $error->get_error_message(),
			],
		];
	}

	/**
	 * Request options from a level and the settings.
	 *
	 * @param string|int|null $level See run().
	 *
	 * @return array{level: string, quality?: int, keep_metadata: bool}
	 */
	public function options( $level ) {
		$level   = self::stored_level( $level );
		$options = is_numeric( $level )
			? [
				'level'   => 'custom',
				'quality' => max( 1, min( 100, (int) $level ) ),
			]
			: [ 'level' => self::LEVELS[ $level ] ?? 'normal' ];

		// The option is named save_exif_data, but the settings page shows it as "Strip EXIF data".
		$options['keep_metadata'] = ! WRIO_Plugin::app()->getPopulateOption( 'save_exif_data', wrio_get_default( 'save_exif_data' ) );

		return $options;
	}
}
