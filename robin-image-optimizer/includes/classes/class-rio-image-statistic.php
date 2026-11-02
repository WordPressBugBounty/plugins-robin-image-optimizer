<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы со статистическими данными по оптимизации изображений
 *
 * @version       1.0
 */
class WRIO_Image_Statistic {

	/**
	 * The source of the state rows of the items.
	 */
	const SOURCE = 'attachment';

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    static
	 */
	protected static $_instance;

	/**
	 * The statistic data.
	 *
	 * @var array{
	 *     original: int|string,
	 *     optimized: int|string,
	 *     converted: int,
	 *     webp_made: int,
	 *     optimized_percent: float,
	 *     percent_line: float,
	 *     webp_percent_line: float,
	 *     unoptimized: int,
	 *     uncompressed: int,
	 *     compressed: int,
	 *     compressed_percent: float,
	 *     needs_webp: int,
	 *     needs_avif: int,
	 *     retryable_error: int,
	 *     unconverted: int,
	 *     optimized_size: int|string,
	 *     webp_optimized_size: int|string,
	 *     avif_optimized_size: int|string,
	 *     original_size: int|string,
	 *     save_size_percent: float,
	 *     error: int,
	 *     webp_error: int,
	 *     avif_converted: int,
	 *     avif_unconverted: int,
	 *     avif_percent_line: float,
	 *     avif_error: int,
	 * }|null
	 * @see WRIO_Image_Statistic::load()
	 */
	protected $statistic = null;

	/**
	 * The main instance.
	 *
	 * @return static Returns the instance of the class.
	 * @since  1.3.0
	 */
	public static function get_instance() {
		if ( ! isset( static::$_instance ) ) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}

	/**
	 * Read file size reliably within the current request.
	 * PHP caches stat() results; we clear cache for this path.
	 *
	 * @param mixed $file_path The file path.
	 *
	 * @return int
	 */
	protected function get_file_size( $file_path ) {
		return wrio_get_file_size( $file_path );
	}

	/**
	 * Get the statistic data.
	 *
	 * @return  array{
	 *     original: int|string,
	 *     optimized: int|string,
	 *     converted: int,
	 *     webp_made: int,
	 *     optimized_percent: float,
	 *     percent_line: float,
	 *     webp_percent_line: float,
	 *     unoptimized: int,
	 *     uncompressed: int,
	 *     compressed: int,
	 *     compressed_percent: float,
	 *     needs_webp: int,
	 *     needs_avif: int,
	 *     retryable_error: int,
	 *     unconverted: int,
	 *     optimized_size: int|string,
	 *     webp_optimized_size: int|string,
	 *     original_size: int|string,
	 *     save_size_percent: float,
	 *     error: int,
	 *     webp_error: int,
	 * }
	 */
	public function get() {
		// Loaded on the first read: a bulk step calls load() itself and must not count everything twice.
		if ( null === $this->statistic ) {
			$this->statistic = $this->load();
		}

		return $this->statistic;
	}

	/**
	 * Saved space in percent, and the optimized size as a percent of the original, for the progress line.
	 *
	 * @param int $original_size  Total original size.
	 * @param int $optimized_size Total optimized size.
	 *
	 * @return array{0: float, 1: float}
	 */
	protected static function size_percents( $original_size, $optimized_size ) {
		if ( ! $original_size || ! $optimized_size ) {
			return [ 0, 100 ];
		}

		return [
			round( ( $original_size - $optimized_size ) * 100 / $original_size, 2 ),
			round( $optimized_size * 100 / $original_size, 0 ),
		];
	}

	/**
	 * A percent in the site language, with the precision of the dashboard: at most 2 decimals, no trailing zeros.
	 *
	 * @param float|int $value Percent.
	 *
	 * @return string For example "14,22%" on a German site.
	 */
	public static function percent_label( $value ) {
		// round() first, as size_percents(): 14.995 is 15, not 14.99. %F ignores the locale, so the point is always
		// ".". Trimming zeros turns "14.20" into "14.2" and "50.00" into "50.".
		$fixed = rtrim( sprintf( '%.2F', round( (float) $value, 2 ) ), '0' );

		return number_format_i18n( (float) $fixed, strlen( $fixed ) - (int) strpos( $fixed, '.' ) - 1 ) . '%';
	}

	/**
	 * Loading statistics and calculating some parameters.
	 *
	 * @return array{
	 *     original: int|string,
	 *     optimized: int|string,
	 *     converted: int,
	 *     webp_made: int,
	 *     optimized_percent: float,
	 *     percent_line: float,
	 *     webp_percent_line: float,
	 *     unoptimized: int,
	 *     uncompressed: int,
	 *     compressed: int,
	 *     compressed_percent: float,
	 *     needs_webp: int,
	 *     needs_avif: int,
	 *     retryable_error: int,
	 *     unconverted: int,
	 *     optimized_size: int|string,
	 *     webp_optimized_size: int|string,
	 *     original_size: int|string,
	 *     save_size_percent: float,
	 *     error: int,
	 *     webp_error: int,
	 *     avif_converted: int,
	 *     avif_unconverted: int,
	 *     avif_percent_line: float,
	 *     avif_error: int,
	 *     avif_optimized_size: int|string,
	 * }
	 */
	public function load() {
		if ( \WRIO\Storage\Backfill::reads() ) {
			$sizes               = \WRIO\Storage\Queries::sum_sizes( 'attachment', 'optimized' );
			$webp_optimized_size = \WRIO\Storage\Queries::sum_sizes( 'attachment', 'webp' )['final_size'];
			$avif_optimized_size = \WRIO\Storage\Queries::sum_sizes( 'attachment', 'avif' )['final_size'];
			$webp_error_count    = \WRIO\Storage\Queries::count( 'attachment', 'webp', \WRIO\Storage\Store::FAILED );
			$avif_error_count    = \WRIO\Storage\Queries::count( 'attachment', 'avif', \WRIO\Storage\Store::FAILED );
		} else {
			$sizes               = RIO_Process_Queue::sum_sizes( 'attachment' );
			$webp_optimized_size = RIO_Process_Queue::sum_sizes( 'webp', 'attachment' )['final_size'];
			$avif_optimized_size = RIO_Process_Queue::sum_sizes( 'avif', 'attachment' )['final_size'];
			$webp_error_count    = (int) RIO_Process_Queue::count_by_type_status( 'webp', 'error' );
			$avif_error_count    = (int) RIO_Process_Queue::count_by_type_status( 'avif', 'error' );
		}

		$original_size  = $sizes['original_size'];
		$optimized_size = $sizes['final_size'];

		$image_query     = WRIO_Image_Query::get_instance();
		$total_images    = $image_query->count_total_attachments( false ); // false = don't exclude WPML dupes for total display
		$optimized_count = $image_query->count_optimized();
		$error_count     = $image_query->count_error(); // Count images with ANY error (attachment, webp, or avif)

		if ( ! $total_images ) {
			$total_images = 0;
		}
		if ( ! $error_count ) {
			$error_count = 0;
		}
		if ( ! $optimized_count ) {
			$optimized_count = 0;
		}
		// Unoptimized = total - optimized - errors (ensures mutual exclusivity)
		$unoptimized_count = max( 0, $total_images - $optimized_count - $error_count );

		// WebP stats
		$unconverted_count = static::get_unconverted_count( 'webp' );
		if ( $unconverted_count < 0 ) {
			$unconverted_count = 0;
		}
		$converted_count = static::get_converted_count( 'webp' );
		if ( $converted_count < 0 ) {
			$converted_count = 0;
		}

		$total_count            = $converted_count + $unconverted_count;
		$webp_percent_diff_line = 0;

		if ( $total_count ) {
			$webp_percent_diff_line = round( $converted_count / $total_count * 100, 1 );
		}

		// AVIF stats
		$avif_unconverted_count = static::get_unconverted_count( 'avif' );
		if ( $avif_unconverted_count < 0 ) {
			$avif_unconverted_count = 0;
		}
		$avif_converted_count = static::get_converted_count( 'avif' );
		if ( $avif_converted_count < 0 ) {
			$avif_converted_count = 0;
		}

		$avif_total_count       = $avif_converted_count + $avif_unconverted_count;
		$avif_percent_diff_line = 0;

		if ( $avif_total_count ) {
			$avif_percent_diff_line = round( $avif_converted_count / $avif_total_count * 100, 1 );
		}

		list( $percent_diff, $percent_diff_line ) = static::size_percents( $original_size, $optimized_size );

		$optimized_images_percent = 0;
		if ( $total_images > 0 ) {
			$optimized_images_percent = floor( $optimized_count * 100 / $total_images );
		}

		// The bulk page shows compression apart from the formats: enabling a format must not reset its gauge.
		$uncompressed_count = $image_query->count_uncompressed();
		$compressed_count   = $image_query->count_compressed();
		$compressed_percent = $total_images > 0 ? floor( $compressed_count * 100 / $total_images ) : 0;

		// What a bulk run still does, without the failed images: they count as errors only. The old queue rows
		// have no permanent flag and no state of a format: before the copy ends, its counts stand in.
		$reads            = \WRIO\Storage\Backfill::reads();
		$needs_webp       = $reads ? $image_query->count_needs_conversion( 'webp' ) : $unconverted_count;
		$needs_avif       = $reads ? $image_query->count_needs_conversion( 'avif' ) : $avif_unconverted_count;
		$retryable_errors = $reads ? $image_query->count_retryable_error() : $error_count;

		$data = [
			'original'            => $total_images,
			'optimized'           => $optimized_count,
			'converted'           => $converted_count,
			// WebP files that were made: the converted count includes the skipped ones (not smaller).
			'webp_made'           => static::get_converted_count( 'webp', true ),
			'optimized_percent'   => $optimized_images_percent,
			'percent_line'        => $percent_diff_line,
			'webp_percent_line'   => $webp_percent_diff_line,
			'unoptimized'         => $unoptimized_count,
			'uncompressed'        => $uncompressed_count,
			'compressed'          => $compressed_count,
			'compressed_percent'  => $compressed_percent,
			'needs_webp'          => $needs_webp,
			'needs_avif'          => $needs_avif,
			'retryable_error'     => $retryable_errors,
			'unconverted'         => $unconverted_count,
			'optimized_size'      => $optimized_size,
			'webp_optimized_size' => $webp_optimized_size,
			'original_size'       => $original_size,
			'save_size_percent'   => $percent_diff,
			'error'               => $error_count,
			'webp_error'          => $webp_error_count,
			// AVIF stats
			'avif_converted'      => $avif_converted_count,
			'avif_unconverted'    => $avif_unconverted_count,
			'avif_percent_line'   => $avif_percent_diff_line,
			'avif_error'          => $avif_error_count,
			'avif_optimized_size' => $avif_optimized_size,
		];

		return $data;
	}

	/**
	 * Count of non-optimized images
	 * Учитывает базовую оптимизацию и конвертацию форматов (WebP/AVIF)
	 * Accounts for basic optimization and format conversion (WebP/AVIF)
	 *
	 * An image is "unoptimized" if it's missing ANY required conversion:
	 * - Basic optimization (attachment) is always required
	 * - WebP conversion is required if WebP is enabled
	 * - AVIF conversion is required if AVIF is enabled
	 *
	 * @return int
	 * @since  1.3.6
	 */
	public static function get_unoptimized_count() {
		return WRIO_Image_Query::get_instance()->count_unoptimized();
	}

	/**
	 * Count of non-converted images
	 *
	 * @param string $format Target format: 'webp' or 'avif'. Default 'webp'.
	 *
	 * @return int
	 *
	 * @since  1.5.3
	 */
	public static function get_unconverted_count( $format = 'webp' ) {
		global $wpdb;

		if ( \WRIO\Storage\Backfill::reads() ) {
			return WRIO_Image_Query::get_instance()->count_unconverted( $format );
		}

		$db_table            = RIO_Process_Queue::table_name();
		$allowed_formats_sql = wrio_get_conversion_formats( true );

		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		// Count only attachments that finished base optimization successfully and
		// still do not have a queue record for the requested conversion format.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table, fixed MIME types and condition.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT count(posts.ID)
			FROM {$wpdb->posts} AS posts
			WHERE  posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$allowed_formats_sql} )
				AND posts.ID IN(
					SELECT object_id
					FROM {$db_table} AS rio
					WHERE rio.item_type = 'attachment'
						AND rio.result_status = 'success'
					GROUP BY object_id
				)
				AND NOT EXISTS(
					SELECT 1
					FROM {$db_table} AS rio
					WHERE rio.object_id = posts.ID
						AND rio.item_type = %s
						AND " . RIO_Process_Queue::not_nextgen_format_sql( 'rio' ) . '
				)',
			$format
		) . WRIO_Image_Query::get_instance()->get_exclusion_clause();
		// phpcs:enable

		$total_images = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return (int) $total_images;
	}

	/**
	 * non-converted images
	 *
	 * @param string $format Target format: 'webp' or 'avif'. Default 'webp'.
	 *
	 * @return array
	 *
	 * @since  1.5.3
	 */
	public static function get_unconverted_images( $format = 'webp' ) {
		return WRIO_Image_Query::get_instance()->get_unconverted_ids( in_array( $format, [ 'webp', 'avif' ], true ) ? $format : 'webp' );
	}

	/**
	 * Count of converted images
	 *
	 * @param string $format    webp or avif.
	 * @param bool   $made_only Count only the images whose file was made, not the skipped ones.
	 *
	 * @return int
	 *
	 * @since  1.5.3
	 */
	public static function get_converted_count( $format = 'webp', $made_only = false ) {
		global $wpdb;

		if ( \WRIO\Storage\Backfill::reads() ) {
			return WRIO_Image_Query::get_instance()->count_converted( 'avif' === $format ? 'avif' : 'webp', $made_only );
		}

		$db_table            = RIO_Process_Queue::table_name();
		$allowed_formats_sql = wrio_get_conversion_formats( true );

		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table and fixed MIME types and condition.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT count(posts.ID)
			FROM {$wpdb->posts} AS posts
			WHERE  posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$allowed_formats_sql} )
				AND posts.ID IN(SELECT object_id FROM {$db_table} AS rio WHERE rio.item_type = %s AND " . RIO_Process_Queue::not_nextgen_format_sql( 'rio' ) . ( $made_only ? " AND rio.result_status = 'success'" : '' ) . ' GROUP BY object_id)',
			$format
		) . WRIO_Image_Query::get_instance()->get_exclusion_clause();
		// phpcs:enable

		$total_images = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return (int) $total_images;
	}

	/**
	 * Возвращает результат последних оптимизаций изображений
	 *
	 * @param int $limit By default - 100. If limit=0, then no limit
	 *
	 * @return array {
	 *     Параметры
	 * @type string $id id
	 * @type string $file_name Имя файла
	 * @type string $url URL
	 * @type string $thumbnail_url URL превьюшки
	 * @type string $optimized_size Размер после оптимизации
	 * @type string $thumbnails_count Сколько превьюшек оптимизировано
	 * @type string $total_saving Процент оптимизации главного файла и превьюшек
	 * }
	 */
	public function get_last_optimized_images( $limit = 100 ) {
		global $wpdb;

		$db_table = RIO_Process_Queue::table_name();

		$limit = max( 0, (int) $limit );

		if ( \WRIO\Storage\Backfill::reads() ) {
			$items = [];
			$rows  = static::last_logged( 'attachment', $limit );

			WRIO_Media_Library::get_instance()->prime_items( array_column( $rows, 'object_id' ) );

			foreach ( $rows as $row ) {
				$items[] = $this->get_last_optimized_image( (int) $row['object_id'], $row )[0];
			}

			return $items;
		}

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
			"SELECT object_id FROM {$db_table}
				WHERE result_status IN (%s, %s)
				AND object_id IS NOT NULL
				ORDER BY id DESC
				LIMIT %d;",
			RIO_Process_Queue::STATUS_SUCCESS,
			RIO_Process_Queue::STATUS_ERROR,
			$limit
		);

		$optimized_images_logs = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		$optimized_attachment_ids = [];
		foreach ( $optimized_images_logs as $log ) {
			$optimized_attachment_ids[] = $log['object_id'];
		}
		$optimized_attachment_ids = array_unique( $optimized_attachment_ids );

		WRIO_Media_Library::get_instance()->prime_items( $optimized_attachment_ids );

		$optimized_attachment = [];
		foreach ( $optimized_attachment_ids as $attachment_id ) {
			$log_data = $this->get_last_optimized_image( $attachment_id );
			if ( ! empty( $log_data ) ) {
				$optimized_attachment[] = $log_data[0];
			}
		}

		return $optimized_attachment;
	}

	/**
	 * Get the last optimized image record for a specific attachment.
	 * Uses the same data source as get_last_optimized_images() for consistency.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $logged        Its latest history row from last_logged(). Null: read it.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since  1.3.9
	 */
	public function get_last_optimized_image( $attachment_id, $logged = null ) {
		$info = WRIO_Media_Library::get_instance()->calculateMediaLibraryParams( $attachment_id );

		// Each pair is "before, after" of the same files, as the overall saving of calculateMediaLibraryParams()
		// measures them: every optimized file (the main file alone for old rows without a total), or the main
		// file and its WebP or AVIF copy. The log shows the pair that saves the most, so the two sizes and the
		// saving agree. An image without an optimization row (only converted, or restored and then converted)
		// starts from the size of its file on disk. Without it, the log shows 0 B.
		$main_size = (int) ( $info['is_optimized'] ? $info['original_main_size'] : $info['attachment_file_size'] );
		$pairs     = [ [ $main_size, $main_size ] ];

		if ( $info['is_optimized'] && ! empty( $info['original_size'] ) && ! empty( $info['final_size'] ) ) {
			$pairs = [ [ (int) $info['original_size'], (int) $info['final_size'] ] ];
		} elseif ( $info['is_optimized'] && $info['attachment_file_size'] ) {
			$pairs = [ [ $main_size, (int) $info['attachment_file_size'] ] ];
		}

		foreach ( [ $info['webp_size'], $info['avif_size'] ] as $format_size ) {
			$pairs[] = [ $main_size, (int) $format_size ];
		}

		list( $original_size, $optimized_size ) = $pairs[0];
		$saving                                 = 0.0;

		// On a tie the first pair stays: the WebP copy of a main file that did not shrink does not hide the total.
		foreach ( $pairs as $index => list( $before, $after ) ) {
			if ( $before <= 0 || $after <= 0 ) {
				continue;
			}

			$after   = min( $before, $after );
			$percent = round( ( $before - $after ) * 100 / $before, 2 );

			if ( 0 === $index || $percent > $saving ) {
				list( $original_size, $optimized_size, $saving ) = [ $before, $after, $percent ];
			}
		}

		$log = [
			'id'               => $attachment_id,
			'file_name'        => $info['original_name'],
			'url'              => $info['edit_url'],
			'thumbnail_url'    => $info['original_url'],
			'original_size'    => size_format( $original_size, 2 ),
			'optimized_size'   => size_format( $optimized_size, 2 ),
			'thumbnails_count' => ! empty( $info['thumbnails_optimized'] ) ? $info['thumbnails_optimized'] : 0,
			'total_saving'     => static::percent_label( $saving ),
		];

		if ( ! empty( $info['error_msg'] ) ) {
			$log['type']      = 'error';
			$log['error_msg'] = $info['error_msg'];
		}

		if ( \WRIO\Storage\Backfill::reads() ) {
			$log = static::with_log( $log, $logged ?? static::last_logged( 'attachment', 1, (int) $attachment_id )[0] ?? null );
		}

		return [ $log ];
	}

	/**
	 * The latest optimize, convert or import history row of each item of a source, newest first. The copy of the
	 * old rows writes an import row per item (Backfill), so the list is not empty after the update.
	 *
	 * @param string $source    attachment, cf_image or nextgen.
	 * @param int    $limit     Number of items.
	 * @param int    $object_id Only this item. 0: every item.
	 *
	 * @phpstan-param 'attachment'|'cf_image'|'nextgen' $source
	 *
	 * @return array<int, array<string, mixed>> History rows, with "details": the server explanation of each failed file, by size.
	 */
	protected static function last_logged( $source, $limit, $object_id = 0 ) {
		global $wpdb;

		$log  = \WRIO\Storage\Schema::log_table();
		$item = $object_id ? $wpdb->prepare( 'AND object_id = %d', $object_id ) : 'AND object_id > 0';

		// A deleted item keeps its history: it must not take a place in the list.
		$item .= ' AND ' . \WRIO\Storage\Queries::exists( $source );

		// A restore after the last attempt: the item is not optimized any more, so it leaves the list before the limit.
		$last = "MAX( CASE WHEN action IN ('optimize', 'convert', 'import') THEN id END )";
		$sql  = "SELECT l.* FROM {$log} l INNER JOIN ( SELECT {$last} AS last_id FROM {$log} WHERE source = %s AND action IN ('optimize', 'convert', 'import', 'restore') {$item} GROUP BY object_id HAVING last_id > COALESCE( MAX( CASE WHEN action = 'restore' THEN id END ), 0 ) ORDER BY last_id DESC LIMIT %d ) m ON m.last_id = l.id ORDER BY l.id DESC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $source, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- plugin table, $item is prepared.

		// One history row is one file. The item's latest attempt is every file of the same run: a failed main file
		// must not hide behind a thumbnail that worked. One query for the runs of every item.
		$runs  = array_values( array_filter( array_unique( array_column( $rows, 'run_id' ) ) ) );
		$files = [];

		if ( ! empty( $runs ) ) {
			$ids  = implode( ',', array_map( 'intval', array_column( $rows, 'object_id' ) ) );
			$in   = implode( ',', array_fill( 0, count( $runs ), '%s' ) );
			$sql  = "SELECT object_id, run_id, size_name, status, code, detail, outputs FROM {$log} WHERE source = %s AND object_id IN ({$ids}) AND run_id IN ({$in}) AND action IN ('optimize', 'convert', 'import') ORDER BY id";
			$runs = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $source ], $runs ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- plugin table, integer IDs, placeholders.

			// The latest row of each file of the run: an earlier result of the same file is not this attempt's.
			foreach ( $runs as $file ) {
				$files[ $file['object_id'] . '|' . $file['run_id'] ][ $file['size_name'] ] = $file;
			}
		}

		// The rows whose run has a result of the main file.
		$covers_main = [];

		foreach ( $rows as $index => $row ) {
			$covers_main[ $index ] = 'original' === $row['size_name'];

			if ( '' === (string) $row['run_id'] ) {
				continue;
			}

			$covers_main[ $index ] = $covers_main[ $index ] || isset( $files[ $row['object_id'] . '|' . $row['run_id'] ]['original'] );

			$outputs = [];
			// The server's explanation of each failed file, by size (see Store::save_error()).
			$details = '' !== (string) $row['detail'] ? [ (string) $row['size_name'] => (string) $row['detail'] ] : [];

			foreach ( $files[ $row['object_id'] . '|' . $row['run_id'] ] ?? [] as $file ) {
				foreach ( (array) json_decode( (string) $file['outputs'], true ) as $output => $result ) {
					$outputs[] = array_merge(
						(array) $result,
						[
							'format' => (string) $output,
							'size'   => (string) $file['size_name'],
						]
					);
				}

				if ( '' === (string) $row['code'] && '' !== (string) $file['code'] ) {
					$row['code']   = $file['code'];
					$row['status'] = $file['status'];
				}

				if ( '' !== (string) $file['detail'] ) {
					$details[ (string) $file['size_name'] ] = (string) $file['detail'];
				}

				// The row's own failure is the main file's when the run has one.
				if ( 'original' === $file['size_name'] && '' !== (string) $file['detail'] ) {
					$row['detail'] = $file['detail'];
				}
			}

			$row['outputs'] = (string) wp_json_encode( $outputs );
			$row['details'] = $details;
			$rows[ $index ] = $row;
		}

		// A later run of other sizes alone does not undo a failure of the main file: its state row decides.
		// One query for every item.
		$state = \WRIO\Storage\Store::rows_of_items( $source, array_map( 'intval', array_column( $rows, 'object_id' ) ) );

		foreach ( $rows as $index => $row ) {
			$main = $state[ (int) $row['object_id'] ]['original|optimized'] ?? null;

			if ( $covers_main[ $index ] || null === $main || ! in_array( $main['status'], [ \WRIO\Storage\Store::FAILED, \WRIO\Storage\Store::MISSING ], true ) ) {
				continue;
			}

			// The state row has no server detail: the main file's explanation comes from its history, as in the Media
			// Library (Store::message()). The other files of the run keep their own.
			$detail  = \WRIO\Storage\Store::FAILED === $main['status'] ? \WRIO\Storage\Store::last_detail( $source, (int) $row['object_id'] ) : '';
			$details = array_merge( $row['details'] ?? ( '' !== (string) $row['detail'] ? [ (string) $row['size_name'] => (string) $row['detail'] ] : [] ), [ 'original' => $detail ] );

			$rows[ $index ] = array_merge(
				$row,
				[
					'status'  => $main['status'],
					'code'    => $main['code'],
					'detail'  => $detail,
					'details' => array_filter( $details, static fn( $detail ) => '' !== $detail ),
				]
			);
		}

		return $rows;
	}

	/**
	 * A log entry with the result of its history row: the entry is an error when an output failed in that attempt.
	 *
	 * @param array<string, mixed>      $entry Log entry.
	 * @param array<string, mixed>|null $row   History row (see last_logged()). Null: the item has no history, the entry stays.
	 *
	 * @return array<string, mixed>
	 */
	protected static function with_log( array $entry, $row ) {
		if ( null === $row ) {
			return $entry;
		}

		// A missing file has no code.
		$failures = '' !== $row['code'] || \WRIO\Storage\Store::MISSING === $row['status'] ? [ [ $row['status'], $row['code'], [] ] ] : [];

		// The server's explanation, for a code the plugin does not know. The row's own failure has the row's; an
		// output has the one of its own file (last_logged() keeps them by size).
		$details     = $row['details'] ?? null;
		$explanation = static function ( $detail ) {
			return '' !== (string) $detail ? [ 'fallback' => (string) $detail ] : [];
		};
		$fallbacks   = $failures ? [ $explanation( $row['detail'] ?? '' ) ] : [];

		foreach ( (array) json_decode( (string) $row['outputs'], true ) as $output => $result ) {
			if ( in_array( $result['status'] ?? '', [ \WRIO\Storage\Store::FAILED, \WRIO\Storage\Store::MISSING ], true ) ) {
				$failures[]  = [ $result['status'], (string) ( $result['code'] ?? '' ), [ 'format' => (string) ( $result['format'] ?? $output ) ] ];
				$fallbacks[] = $explanation( is_array( $details ) ? ( $details[ (string) ( $result['size'] ?? $row['size_name'] ?? '' ) ] ?? '' ) : ( $row['detail'] ?? '' ) );
			}
		}

		$messages = [];

		foreach ( $failures as $index => list( $status, $code, $context ) ) {
			$messages[] = wrio_error_message( $status, $code, \WRIO\Storage\Store::message_context( $context + $fallbacks[ $index ] ) );
		}

		$messages = array_values( array_unique( $messages ) );

		unset( $entry['error_msg'], $entry['hint'], $entry['docs'] );
		$entry['type'] = empty( $messages ) ? 'success' : 'error';

		if ( ! empty( $messages ) ) {
			$entry['error_msg'] = implode( ' ', $messages );
			// What to do about the first failure.
			$entry['hint'] = wrio_error_hint( $failures[0][0], $failures[0][1] );
			$entry['docs'] = wrio_error_docs_url( $failures[0][0], $failures[0][1] );
		}

		return $entry;
	}

	/**
	 * @param int    $object_id
	 * @param string $format Format type: 'webp' or 'avif'. Default 'webp'.
	 *
	 * @since  1.3.9
	 */
	public function get_last_converted_image( $object_id, $format = 'webp' ) {
		global $wpdb;

		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table and a fixed condition.
		$items    = [];
		$db_table = RIO_Process_Queue::table_name();
		$sql      = $wpdb->prepare(
			"SELECT * FROM {$db_table}
					WHERE object_id = %d AND item_type = %s AND result_status IN (%s, %s) AND " . RIO_Process_Queue::not_nextgen_format_sql() . '
					ORDER BY original_size DESC
					LIMIT 1;',
			(int) $object_id,
			$format,
			RIO_Process_Queue::STATUS_SUCCESS,
			RIO_Process_Queue::STATUS_ERROR
		);
		// phpcs:enable

		$model = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( ! empty( $model ) ) {
			$items[] = $this->format_webp_for_log( new RIO_Process_Queue( $model ) );
		}

		return $items;
	}

	/**
	 * Format a queue record for the optimization log display.
	 * Works universally for attachment, webp, and avif item types.
	 *
	 * @param RIO_Process_Queue $queue_model Queue model instance.
	 *
	 * @return array<string, mixed>
	 * @throws \Exception If invalid model provided.
	 * @since  1.3.9
	 */
	protected function format_for_log( $queue_model ) {
		if ( ! $queue_model instanceof RIO_Process_Queue ) {
			throw new Exception( 'Variable $queue_model must be an instance of RIO_Process_Queue!' );
		}

		$extra_data    = $queue_model->get_extra_data();
		$object_id     = $queue_model->get_object_id();
		$item_type     = $queue_model->item_type;
		$original_size = $queue_model->get_original_size();
		$final_size    = min( $original_size, $queue_model->get_final_size() );

		$formatted_data = [
			'id'                  => $queue_model->get_id(),
			'attachment_id'       => $object_id,
			'item_type'           => $item_type,
			'url'                 => admin_url( sprintf( 'post.php?post=%d&action=edit', $object_id ) ),
			'original_url'        => null,
			'thumbnail_url'       => null,
			'file_name'           => null,
			'original_size'       => size_format( $original_size, 2 ),
			'original_size_bytes' => $original_size,
			'optimized_size'      => size_format( $final_size, 2 ),
			'type'                => 'success',
			'webp_size'           => null,
			'avif_size'           => null,
			'original_saving'     => 0,
			'thumbnails_count'    => 0,
			'total_saving'        => 0,
			'final_size_bytes'    => $final_size,
			'converted_from'      => null,
		];

		if ( in_array( $item_type, [ 'webp', 'avif' ], true ) && $extra_data instanceof RIOP_WebP_Extra_Data ) {
			// For webp/avif, use source_src from extra_data
			$original_url                     = $extra_data->get_source_src();
			$formatted_data['original_url']   = $original_url;
			$formatted_data['file_name']      = wp_basename( $original_url );
			$formatted_data['thumbnail_url']  = $original_url;
			$formatted_data['converted_from'] = $extra_data->get_converted_from_size();

			if ( 'avif' === $item_type ) {
				$formatted_data['avif_size'] = size_format( $final_size, 2 );
			} else {
				$formatted_data['webp_size'] = size_format( $final_size, 2 );
			}

			if ( $extra_data->get_thumbnails_count() ) {
				$formatted_data['thumbnails_count'] = $extra_data->get_thumbnails_count();
			}
		} else {
			// For attachment type, use WordPress attachment metadata
			$upload_dir      = wp_upload_dir();
			$attachment_meta = wp_get_attachment_metadata( $object_id );

			if ( ! empty( $attachment_meta['file'] ) ) {
				$image_url                       = trailingslashit( $upload_dir['baseurl'] ) . $attachment_meta['file'];
				$formatted_data['original_url']  = $image_url;
				$formatted_data['file_name']     = wp_basename( $attachment_meta['file'] );
				$formatted_data['thumbnail_url'] = $image_url;

				if ( isset( $attachment_meta['sizes']['thumbnail'] ) ) {
					$image_basename                  = wp_basename( $image_url );
					$formatted_data['thumbnail_url'] = str_replace( $image_basename, $attachment_meta['sizes']['thumbnail']['file'], $image_url );
				}

				if ( ! empty( $extra_data ) && method_exists( $extra_data, 'get_thumbnails_count' ) ) {
					$formatted_data['thumbnails_count'] = $extra_data->get_thumbnails_count();
				}
			} else {
				// Fallback to post guid
				$attachment = get_post( $object_id );
				if ( ! empty( $attachment ) ) {
					$formatted_data['original_url']  = $attachment->guid;
					$formatted_data['thumbnail_url'] = $attachment->guid;
					$formatted_data['file_name']     = wp_basename( $attachment->guid );
				}
			}
		}

		// Calculate total saving directly from the row's original_size and final_size
		if ( is_numeric( $original_size ) && $original_size > 0 && is_numeric( $final_size ) ) {
			$total_saving                   = ( $original_size - $final_size ) * 100 / $original_size;
			$total_saving                   = max( 0, min( $total_saving, 100 ) );
			$formatted_data['total_saving'] = static::percent_label( $total_saving );
		}

		if ( RIO_Process_Queue::STATUS_ERROR === $queue_model->get_result_status() ) {
			$error_message = null;

			if ( ! empty( $extra_data ) ) {
				$error_message = $extra_data->get_error_msg();
			}

			$formatted_data['type']      = 'error';
			$formatted_data['error_msg'] = ! empty( $error_message ) ? $error_message : __( 'Unknown error', 'robin-image-optimizer' );
		}

		return $formatted_data;
	}

	/**
	 * Format WebP/AVIF record for log display.
	 *
	 * @param RIO_Process_Queue $queue_model Queue model instance.
	 *
	 * @return array<string, mixed>
	 * @throws \Exception If invalid model provided.
	 * @since  1.5.3
	 */
	protected function format_webp_for_log( $queue_model ) {
		if ( ! $queue_model instanceof RIO_Process_Queue ) {
			throw new Exception( 'Variable $queue_model must be an instance of RIO_Process_Queue!' );
		}

		/**
		 * @var RIO_Attachment_Extra_Data $extra_data
		 */
		$extra_data = $queue_model->get_extra_data();

		$default_formated_data = [
			'id'               => $queue_model->get_id(),
			'attachment_id'    => $queue_model->get_object_id(),
			'item_type'        => $queue_model->item_type,
			'url'              => admin_url( sprintf( 'post.php?post=%d&action=edit', $queue_model->get_object_id() ) ),
			'original_url'     => null,
			'thumbnail_url'    => null,
			'file_name'        => null,
			'original_size'    => 0,
			'optimized_size'   => 0,
			'type'             => 'success',
			'webp_size'        => null,
			'avif_size'        => null,
			'original_saving'  => 0,
			'thumbnails_count' => 0,
			'total_saving'     => 0,
		];

		$upload_dir = wp_upload_dir();

		$attachment_meta = wp_get_attachment_metadata( $queue_model->get_object_id() );
		$formated_data   = [];

		if ( ! empty( $attachment_meta['file'] ) ) {
			$image_url     = trailingslashit( $upload_dir['baseurl'] ) . $attachment_meta['file'];
			$thumbnail_url = $image_url;

			if ( isset( $attachment_meta['sizes']['thumbnail'] ) ) {
				$image_basename = wp_basename( $image_url );
				$thumbnail_url  = str_replace( $image_basename, $attachment_meta['sizes']['thumbnail']['file'], $image_url );
			}

			// Get the extension from the item type (webp or avif)
			$converted_extension = '.' . $queue_model->item_type;

			$size_field_name = 'avif' === $queue_model->item_type ? 'avif_size' : 'webp_size';

			$formated_data = wp_parse_args(
				[
					'original_url'   => $image_url . $converted_extension,
					'thumbnail_url'  => $thumbnail_url,
					'file_name'      => wp_basename( $attachment_meta['file'] ) . $converted_extension,
					'original_size'  => size_format( $queue_model->get_original_size(), 2 ),
					'optimized_size' => '-',
					$size_field_name => size_format( $queue_model->get_final_size(), 2 ),
				],
				$default_formated_data
			);

			$main_file = trailingslashit( $upload_dir['basedir'] ) . $attachment_meta['file'];

			// An extra data may be empty after a failed migration or an unknown error.
			if ( ! empty( $extra_data ) ) {
				$original_main_size = $extra_data->get_original_main_size();

				if ( $original_main_size ) {
					$current_main_size                = $this->get_file_size( $main_file );
					$original_saving                  = ( $original_main_size - $current_main_size ) * 100 / $original_main_size;
					$formated_data['original_saving'] = static::percent_label( $original_saving );
				}

				$formated_data['thumbnails_count'] = $extra_data->get_thumbnails_count();
			}

			if ( $queue_model->get_original_size() ) {
				$total_saving                  = ( $queue_model->get_original_size() - $queue_model->get_final_size() ) * 100 / $queue_model->get_original_size();
				$formated_data['total_saving'] = static::percent_label( $total_saving );
			}
		} else {
			$attachment = get_post( $queue_model->get_object_id() );

			if ( ! empty( $attachment ) ) {
				$formated_data = [
					'original_url'  => $attachment->guid,
					'thumbnail_url' => $attachment->guid,
					'file_name'     => wp_basename( $attachment->guid ),
				];
			}

			$formated_data = wp_parse_args( $formated_data, $default_formated_data );
		}

		if ( RIO_Process_Queue::STATUS_ERROR === $queue_model->get_result_status() ) {
			$error_message = null;

			if ( ! empty( $extra_data ) ) {
				$error_message = $extra_data->get_error_msg();
			}

			$formated_data['type']      = 'error';
			$formated_data['error_msg'] = ! empty( $error_message ) ? $error_message : __( 'Unknown error', 'robin-image-optimizer' );

			return $formated_data;
		}

		return $formated_data;
	}

	/**
	 * Возвращает общий процент оптимизированных изображений
	 *
	 * @return int общий процент оптимизации
	 */
	public function getOptimizedPercent() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public name kept for callers.
		return (int) $this->get()['optimized_percent'];
	}

	/**
	 * Пересчёт размера файла в байтах на человекопонятный вид
	 *
	 * Пример: вводим 67894 байт, получаем 67.8 KB
	 * Пример: вводим 6789477 байт, получаем 6.7 MB
	 *
	 * @param int $size размер файла в байтах
	 *
	 * @return string
	 */
	public function convertToReadableSize( $size ) {
		return wrio_convert_bytes( $size );
	}

	/**
	 * Get the main/full size conversion record for an attachment
	 *
	 * @param int    $object_id Attachment ID
	 * @param string $format    'webp' or 'avif'
	 *
	 * @return RIO_Process_Queue|null
	 */
	protected function get_conversion_record( $object_id, $format ) {
		global $wpdb;

		$db_table = RIO_Process_Queue::table_name();

		// Query for conversion records with this attachment ID and format
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table and a fixed condition.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$db_table}
			WHERE object_id = %d
			AND item_type = %s
			AND result_status = %s
			AND " . RIO_Process_Queue::not_nextgen_format_sql() . '
			ORDER BY original_size DESC',
			$object_id,
			$format,
			RIO_Process_Queue::STATUS_SUCCESS
		);
		// phpcs:enable

		$models = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( empty( $models ) ) {
			return null;
		}

		// Find the record with converted_from_size = 'original' in extra_data
		foreach ( $models as $model ) {
			if ( ! empty( $model['extra_data'] ) ) {
				$extra_data = json_decode( $model['extra_data'], true );
				if ( isset( $extra_data['converted_from_size'] ) && $extra_data['converted_from_size'] === 'original' ) {
					return new RIO_Process_Queue( $model );
				}
			}
		}

		// Fallback: return the largest one (highest original_size) which is likely the main image
		return new RIO_Process_Queue( $models[0] );
	}

	/**
	 * The failed and missing outputs grouped by reason: first the ones the user must fix, then the ones that wait for
	 * an automatic retry (see retrying()), each by its number of items. The items of a group load when it opens
	 * (see get_failure_page()): two queries, whatever the number of failures.
	 *
	 * Empty before the copy of the old rows ends (Backfill::reads()): the state rows can miss the old errors, and
	 * the log list still shows them.
	 *
	 * @return array{total: int, needs_you: int, retrying: int, next_retry: string|null, groups: array<int, array<string, mixed>>}
	 */
	public function get_failures(): array {
		global $wpdb;

		$result = [
			'total'      => 0,
			'needs_you'  => 0,
			'retrying'   => 0,
			'next_retry' => null,
			'groups'     => [],
		];

		if ( ! \WRIO\Storage\Backfill::reads() ) {
			return $result;
		}

		$table    = \WRIO\Storage\Schema::files_table();
		$where    = $this->failures_where();
		$retrying = $this->retrying();
		// A missing file has one message, whatever its code. The codes of wrio_error_title() that name a format get a group per format.
		$code   = $wpdb->prepare( "IF( status = %s, '', code )", \WRIO\Storage\Store::MISSING );
		$format = $wpdb->prepare( "IF( status = %s AND code IN ( 'same_format', 'animated', 'too_large_for_format', 'not_in_plan' ), format, '' )", \WRIO\Storage\Store::FAILED );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table, the conditions are prepared.
		$totals = $wpdb->get_row( "SELECT COUNT( DISTINCT object_id ) AS total, COUNT( DISTINCT IF( {$retrying}, NULL, object_id ) ) AS needs_you, COUNT( DISTINCT IF( {$retrying}, object_id, NULL ) ) AS retrying, MIN( next_attempt_at ) AS next_retry FROM {$table} WHERE {$where}", ARRAY_A );
		$groups = $wpdb->get_results( "SELECT status, {$code} AS group_code, {$format} AS group_format, {$retrying} AS retrying, COUNT( DISTINCT object_id ) AS items FROM {$table} WHERE {$where} GROUP BY status, group_code, group_format, retrying ORDER BY retrying, items DESC, MAX( updated_at ) DESC", ARRAY_A );
		// phpcs:enable

		$result['total']      = (int) ( $totals['total'] ?? 0 );
		$result['needs_you']  = (int) ( $totals['needs_you'] ?? 0 );
		$result['retrying']   = (int) ( $totals['retrying'] ?? 0 );
		$result['next_retry'] = self::local_time( $totals['next_retry'] ?? null );

		foreach ( $groups as $group ) {
			$code  = (string) $group['group_code'];
			$wait  = (bool) $group['retrying'];
			$title = wrio_error_title( (string) $group['status'], $code, (string) $group['group_format'] );

			// The same problem, still retried, is the next group: the title tells them apart.
			if ( ! $wait && self::transient( $code ) ) {
				// translators: %s is the name of a problem, such as Optimization server unreachable.
				$title = sprintf( __( '%s (retries stopped)', 'robin-image-optimizer' ), $title );
			}

			$result['groups'][] = [
				'key'      => implode( '|', [ $group['status'], $code, $group['group_format'], $group['retrying'] ] ),
				'code'     => $code,
				'title'    => $title,
				'hint'     => wrio_error_hint( (string) $group['status'], $code ),
				'docs'     => wrio_error_docs_url( (string) $group['status'], $code ),
				'retrying' => $wait,
				'items'    => [],
				'total'    => (int) $group['items'],
				'page'     => 0,
				'next'     => '',
				'per_page' => 0,
			];
		}

		return $result;
	}

	/**
	 * A page of the items of one failures group, the latest failure first, and the number of items of the group.
	 *
	 * The pages use a cursor, not an offset: a bulk run adds and removes failures while the user reads the list.
	 *
	 * @param string $key      The key of the group: status|code|format|retrying (see get_failures()).
	 * @param string $after    The cursor of the last item of the previous page ('updated_at|id', see next), or '' for the first page.
	 * @param int    $per_page Items per page.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, next: string, per_page: int}
	 */
	public function get_failure_page( string $key, string $after = '', int $per_page = 20 ): array {
		global $wpdb;

		list( $status, $code, $format, $retrying ) = array_pad( explode( '|', $key, 4 ), 4, '' );

		$table  = \WRIO\Storage\Schema::files_table();
		$where  = $this->failures_where() . $wpdb->prepare( ' AND status = %s', $status );
		$where .= \WRIO\Storage\Store::MISSING === $status ? '' : $wpdb->prepare( ' AND code = %s', $code );
		$where .= '' === $format ? '' : $wpdb->prepare( ' AND format = %s', $format );
		$where .= ' AND ' . ( '1' === $retrying ? '' : 'NOT ' ) . $this->retrying();

		$having = '';
		if ( '' !== $after ) {
			list( $updated_at, $id ) = array_pad( explode( '|', $after, 2 ), 2, '0' );

			$having = $wpdb->prepare( 'HAVING last_at < %s OR ( last_at = %s AND last_id < %d )', $updated_at, $updated_at, (int) $id );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table, $where and $having are prepared.
		$total = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT object_id ) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, MAX( updated_at ) AS last_at, MAX( id ) AS last_id FROM {$table} WHERE {$where} GROUP BY object_id {$having} ORDER BY last_at DESC, last_id DESC LIMIT %d", $per_page ), ARRAY_A );
		// phpcs:enable

		$last = end( $rows );

		return [
			'items'    => array_map( [ $this, 'failure_item' ], array_map( 'intval', array_column( $rows, 'object_id' ) ) ),
			'total'    => $total,
			'next'     => $last ? $last['last_at'] . '|' . $last['last_id'] : '',
			'per_page' => $per_page,
		];
	}

	/**
	 * SQL condition: a failure of the connection or of the server that waits for its automatic retry. The user has
	 * nothing to do for it. When the retries stop, the user must act, like for every other failure.
	 *
	 * @return string
	 */
	protected function retrying(): string {
		global $wpdb;

		$codes = \WRIO\Storage\Store::TRANSIENT_CODES;

		return '( ' . $wpdb->prepare( 'status = %s AND next_attempt_at IS NOT NULL AND code IN ( ' . implode( ', ', array_fill( 0, count( $codes ), '%s' ) ) . ' )', \WRIO\Storage\Store::FAILED, ...$codes ) . ' )'; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per code.
	}

	/**
	 * A UTC time of the state rows in the date and time format of the site, or "now" when it is already past.
	 *
	 * @param string|null $utc Y-m-d H:i:s.
	 *
	 * @return string|null
	 */
	private static function local_time( $utc ) {
		if ( null === $utc ) {
			return null;
		}

		// A retry that is due waits for the next optimization run, not for a time already past.
		if ( strtotime( $utc . ' UTC' ) <= time() ) {
			return __( 'now', 'robin-image-optimizer' );
		}

		return get_date_from_gmt( (string) $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Whether a code is a failure of the connection or of the server, which the automatic retries fix.
	 *
	 * @param string $code Problem code.
	 *
	 * @return bool
	 */
	protected static function transient( string $code ): bool {
		return in_array( $code, \WRIO\Storage\Store::TRANSIENT_CODES, true );
	}

	/**
	 * SQL condition: the failed and missing outputs of the items of this list that still exist and are not in the trash.
	 *
	 * The media library counts the images the Error count of the page counts (WRIO_Image_Query::count_error()):
	 * attachments with an allowed format that are not excluded.
	 *
	 * @return string
	 */
	protected function failures_where(): string {
		global $wpdb;

		/**
		 * A quoted list for SQL.
		 *
		 * @var string $formats
		 */
		$formats = wrio_get_allowed_formats( true );
		$items   = 'attachment' === static::SOURCE
			? "object_id IN ( SELECT posts.ID FROM {$wpdb->posts} posts WHERE posts.post_type = 'attachment' AND posts.post_status = 'inherit' AND posts.post_mime_type IN ( {$formats} )" . WRIO_Image_Query::get_instance()->get_exclusion_clause() . ' )'
			: \WRIO\Storage\Queries::exists( static::SOURCE );

		return $wpdb->prepare( 'source = %s AND status IN (%s, %s)', static::SOURCE, \WRIO\Storage\Store::FAILED, \WRIO\Storage\Store::MISSING ) . ' AND ' . $items;
	}

	/**
	 * What the failures list shows of an item.
	 *
	 * @param int $id Attachment ID.
	 *
	 * @return array{id: int, file_name: string, url: string, thumbnail_url: string, original_url: string}
	 */
	protected function failure_item( int $id ): array {
		$file = get_attached_file( $id );

		return [
			'id'            => $id,
			'file_name'     => $file ? wp_basename( $file ) : get_the_title( $id ),
			'url'           => (string) get_edit_post_link( $id, 'raw' ),
			'thumbnail_url' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'original_url'  => (string) wp_get_attachment_url( $id ),
		];
	}
}
