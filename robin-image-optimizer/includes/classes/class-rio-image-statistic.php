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
	 *     original: int,
	 *     optimized: int,
	 *     converted: int,
	 *     optimized_percent: int|float,
	 *     percent_line: float,
	 *     webp_percent_line: float,
	 *     unoptimized: int,
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
	 *     quota_limit?: int|string
	 * }
	 * @see WRIO_Image_Statistic::load()
	 */
	protected $statistic;

	/**
	 * Constructor.
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	public function __construct() {
		$this->statistic = $this->load();
	}

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
	 *     optimized_percent: float,
	 *     percent_line: float,
	 *     webp_percent_line: float,
	 *     unoptimized: int,
	 *     unconverted: int,
	 *     optimized_size: int|string,
	 *     webp_optimized_size: int|string,
	 *     original_size: int|string,
	 *     save_size_percent: float,
	 *     error: int,
	 *     webp_error: int,
	 *     quota_limit?: int|string
	 * }
	 */
	public function get() {
		return $this->statistic;
	}

	/**
	 * Добавляет новые данные к текущей статистике
	 * К текущим числам добавляются новые
	 *
	 * @param string $field Поле, к которому добавляем значение
	 * @param int    $value добавляемое значение
	 */
	public function addToField( $field, $value ) {
		if ( isset( $this->statistic[ $field ] ) ) {
			$this->statistic[ $field ] = $this->statistic[ $field ] + $value;
		}
	}

	/**
	 * Вычитает данные из текущей статистики
	 * Из текущего числа вычитается
	 *
	 * @param string $field Поле, из которого вычитается значение
	 * @param int    $value вычитаемое значение
	 */
	public function deductFromField( $field, $value ) {
		$value = (int) $value;
		if ( isset( $this->statistic[ $field ] ) ) {
			$this->statistic[ $field ] = $this->statistic[ $field ] - $value;
			if ( $this->statistic[ $field ] < 0 ) {
				$this->statistic[ $field ] = 0;
			}
		}
	}

	/**
	 * Сохранение статистики
	 */
	public function save() {
		WRIO_Plugin::app()->updateOption( 'original_size', $this->statistic['original_size'] );
		WRIO_Plugin::app()->updateOption( 'optimized_size', $this->statistic['optimized_size'] );
		WRIO_Plugin::app()->updateOption( 'webp_optimized_size', $this->statistic['webp_optimized_size'] );
		WRIO_Plugin::app()->updateOption( 'avif_optimized_size', $this->statistic['avif_optimized_size'] );
	}

	/**
	 * Loading statistics and calculating some parameters.
	 *
	 * @return array{
	 *     original: int|string,
	 *     optimized: int|string,
	 *     converted: int,
	 *     optimized_percent: float,
	 *     percent_line: float,
	 *     webp_percent_line: float,
	 *     unoptimized: int,
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
	 *     quota_limit?: int|string
	 * }
	 */
	public function load() {
		$original_size       = WRIO_Plugin::app()->getOption( 'original_size', 0 );
		$optimized_size      = WRIO_Plugin::app()->getOption( 'optimized_size', 0 );
		$webp_optimized_size = WRIO_Plugin::app()->getOption( 'webp_optimized_size', 0 );
		$avif_optimized_size = WRIO_Plugin::app()->getOption( 'avif_optimized_size', 0 );

		$image_query          = WRIO_Image_Query::get_instance();
		$total_images         = $image_query->count_total_attachments( false ); // false = don't exclude WPML dupes for total display
		$optimized_count      = $image_query->count_optimized();
		$error_count          = $image_query->count_error(); // Count images with ANY error (attachment, webp, or avif)
		$webp_optimized_count = RIO_Process_Queue::count_by_type_status( 'webp', 'success' );
		$webp_error_count     = (int) RIO_Process_Queue::count_by_type_status( 'webp', 'error' );
		$avif_optimized_count = RIO_Process_Queue::count_by_type_status( 'avif', 'success' );
		$avif_error_count     = (int) RIO_Process_Queue::count_by_type_status( 'avif', 'error' );

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

		$percent_diff      = 0;
		$percent_diff_line = 100;
		if ( $optimized_size && $original_size ) {
			$percent_diff      = round( ( $original_size - $optimized_size ) * 100 / $original_size, 1 );
			$percent_diff_line = round( $optimized_size * 100 / $original_size, 0 );
		}

		$optimized_images_percent = 0;
		if ( $total_images > 0 ) {
			$optimized_images_percent = floor( $optimized_count * 100 / $total_images );
		}

		$processor = WIO_OptimizationTools::getImageProcessor();

		$data = [
			'original'            => $total_images,
			'optimized'           => $optimized_count,
			'converted'           => $converted_count,
			'optimized_percent'   => $optimized_images_percent,
			'percent_line'        => $percent_diff_line,
			'webp_percent_line'   => $webp_percent_diff_line,
			'unoptimized'         => $unoptimized_count,
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

		if ( $processor->has_quota_limit() ) {
			$data['quota_limit'] = $processor->get_quota_limit();
		}

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
		$db_table            = RIO_Process_Queue::table_name();
		$allowed_formats_sql = wrio_get_allowed_formats( true );

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		// Count only attachments that finished base optimization successfully and
		// still do not have a queue record for the requested conversion format.
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
				AND posts.ID NOT IN(
					SELECT object_id
					FROM {$db_table} AS rio
					WHERE rio.item_type = %s
					GROUP BY object_id
				)",
			$format
		);

		$total_images = $wpdb->get_var( $sql );

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
		global $wpdb;
		$db_table            = RIO_Process_Queue::table_name();
		$allowed_formats_sql = wrio_get_allowed_formats( true );

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		// Select only attachments that finished base optimization successfully and
		// still do not have a queue record for the requested conversion format.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT posts.ID
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
				AND posts.ID NOT IN(
					SELECT object_id
					FROM {$db_table} AS rio
					WHERE rio.item_type = %s
					GROUP BY object_id
				)",
			$format
		);

		$images = $wpdb->get_col( $sql );

		return is_array( $images ) ? $images : [];
	}

	/**
	 * Count of converted images
	 *
	 * @return int
	 *
	 * @since  1.5.3
	 */
	public static function get_converted_count( $format = 'webp' ) {
		global $wpdb;
		$db_table            = RIO_Process_Queue::table_name();
		$allowed_formats_sql = wrio_get_allowed_formats( true );

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT count(posts.ID)
			FROM {$wpdb->posts} AS posts
			WHERE  posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$allowed_formats_sql} )
				AND posts.ID IN(SELECT object_id FROM {$db_table} AS rio WHERE rio.item_type = %s GROUP BY object_id)",
			$format
		);

		$total_images = $wpdb->get_var( $sql );

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

		$sql = $wpdb->prepare(
			"SELECT object_id FROM {$db_table}
				WHERE result_status IN (%s, %s)
				ORDER BY id DESC
				LIMIT %d;",
			RIO_Process_Queue::STATUS_SUCCESS,
			RIO_Process_Queue::STATUS_ERROR,
			$limit
		);

		$optimized_images_logs = $wpdb->get_results( $sql, ARRAY_A );

		$optimized_attachment_ids = [];
		foreach ( $optimized_images_logs as $log ) {
			$optimized_attachment_ids[] = $log['object_id'];
		}
		$optimized_attachment_ids = array_unique( $optimized_attachment_ids );

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
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since  1.3.9
	 */
	public function get_last_optimized_image( $attachment_id ) {
		$info = WRIO_Media_Library::get_instance()->calculateMediaLibraryParams( $attachment_id );

		$best_optimized_size = ! empty( $info['optimized_size'] ) ? $info['optimized_size'] : 0;
		if ( ! empty( $info['webp_size'] ) ) {
			$best_optimized_size = min( $best_optimized_size, $info['webp_size'] );
		}

		if ( ! empty( $info['avif_size'] ) ) {
			$best_optimized_size = min( $best_optimized_size, $info['avif_size'] );
		}

		$original_size       = ! empty( $info['original_size'] ) ? $info['original_size'] : 0;
		$best_optimized_size = min( $best_optimized_size, $original_size );

		$log = [
			'id'               => $attachment_id,
			'file_name'        => $info['original_name'],
			'url'              => $info['edit_url'],
			'thumbnail_url'    => $info['original_url'],
			'original_size'    => size_format( $original_size, 2 ),
			'optimized_size'   => size_format( $best_optimized_size, 2 ),
			'thumbnails_count' => ! empty( $info['thumbnails_optimized'] ) ? $info['thumbnails_optimized'] : 0,
			'total_saving'     => ! empty( $info['diff_percent_all'] ) ? $info['diff_percent_all'] . '%' : '0%',
		];

		// Check errors.
		if ( ! empty( $info['error_msg'] ) ) {
			$log['type']      = 'error';
			$log['error_msg'] = $info['error_msg'];
		}

		return [ $log ];
	}

	/**
	 * @param int    $object_id
	 * @param string $format Format type: 'webp' or 'avif'. Default 'webp'.
	 *
	 * @since  1.3.9
	 */
	public function get_last_converted_image( $object_id, $format = 'webp' ) {
		global $wpdb;

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		$items    = [];
		$db_table = RIO_Process_Queue::table_name();
		$sql      = $wpdb->prepare(
			"SELECT * FROM {$db_table}
					WHERE object_id = %d AND item_type = %s AND result_status IN (%s, %s)
					ORDER BY original_size DESC
					LIMIT 1;",
			(int) $object_id,
			$format,
			RIO_Process_Queue::STATUS_SUCCESS,
			RIO_Process_Queue::STATUS_ERROR
		);

		$model = $wpdb->get_row( $sql, ARRAY_A );

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

		// Get URLs and file name based on item type
		if ( in_array( $item_type, [ 'webp', 'avif' ], true ) && $extra_data instanceof RIOP_WebP_Extra_Data ) {
			// For webp/avif, use source_src from extra_data
			$original_url                     = $extra_data->get_source_src();
			$formatted_data['original_url']   = $original_url;
			$formatted_data['file_name']      = wp_basename( $original_url );
			$formatted_data['thumbnail_url']  = $original_url;
			$formatted_data['converted_from'] = $extra_data->get_converted_from_size();

			// Set the appropriate size field
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

			if ( ! empty( $attachment_meta ) ) {
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
			$formatted_data['total_saving'] = round( $total_saving, 2 ) . '%';
		}

		// Handle errors
		if ( RIO_Process_Queue::STATUS_ERROR === $queue_model->get_result_status() ) {
			$error_message = null;

			if ( ! empty( $extra_data ) && method_exists( $extra_data, 'get_error_msg' ) ) {
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

		if ( ! empty( $attachment_meta ) ) {
			$image_url     = trailingslashit( $upload_dir['baseurl'] ) . $attachment_meta['file'];
			$thumbnail_url = $image_url;

			if ( isset( $attachment_meta['sizes']['thumbnail'] ) ) {
				$image_basename = wp_basename( $image_url );
				$thumbnail_url  = str_replace( $image_basename, $attachment_meta['sizes']['thumbnail']['file'], $image_url );
			}

			// Get the extension from the item type (webp or avif)
			$converted_extension = '.' . $queue_model->item_type;

			// Determine the field name based on format type
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
					$formated_data['original_saving'] = round( $original_saving ) . '%';
				}

				$formated_data['thumbnails_count'] = $extra_data->get_thumbnails_count();
			}

			if ( $queue_model->get_original_size() ) {
				$total_saving                  = ( $queue_model->get_original_size() - $queue_model->get_final_size() ) * 100 / $queue_model->get_original_size();
				$formated_data['total_saving'] = round( $total_saving, 2 ) . '%';
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

		// We collect information about errors
		if ( RIO_Process_Queue::STATUS_ERROR === $queue_model->get_result_status() ) {
			$error_message = null;

			if ( ! empty( $extra_data ) && method_exists( $extra_data, 'get_error_msg' ) ) {
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
	public function getOptimizedPercent() {
		if ( isset( $this->statistic['optimized_percent'] ) ) {
			return $this->statistic['optimized_percent'];
		}

		return 0;
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
		$sql = $wpdb->prepare(
			"SELECT * FROM {$db_table}
			WHERE object_id = %d
			AND item_type = %s
			AND result_status = %s
			ORDER BY original_size DESC",
			$object_id,
			$format,
			RIO_Process_Queue::STATUS_SUCCESS
		);

		$models = $wpdb->get_results( $sql, ARRAY_A );

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
}
