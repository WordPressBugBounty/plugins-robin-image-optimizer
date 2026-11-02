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
class WRIO_Image_Statistic_Nextgen extends WRIO_Image_Statistic {

	/**
	 * The source of the state rows of the items.
	 */
	const SOURCE = 'nextgen';

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    static
	 */
	protected static $_instance;

	/**
	 * Загрузка статистики и расчёт некоторых параметров
	 *
	 * @return array
	 */
	public function load() {
		if ( \WRIO\Storage\Backfill::reads() ) {
			return $this->load_state();
		}

		$sizes          = RIO_Process_Queue::sum_sizes( 'nextgen' );
		$original_size  = $sizes['original_size'];
		$optimized_size = $sizes['final_size'];
		global $wpdb;
		$io_db_table = RIO_Process_Queue::table_name();

		$sql_unoptimized = "SELECT COUNT(*)
			FROM {$wpdb->prefix}ngg_pictures as t1 LEFT JOIN {$io_db_table} as t2 
			ON t2.item_type = 'nextgen' and t1.pid = t2.object_id
			WHERE t2.result_status Is Null or ( t2.result_status != 'success' and t2.result_status != 'error' )";

		$error_count  = RIO_Process_Queue::count_by_type_status( 'nextgen', 'error' );
		$total_images = $this->getTotalCount();
		if ( ! $total_images ) {
			$total_images = 0;
		}
		$unoptimized                              = $wpdb->get_var( $sql_unoptimized ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SQL with table names only.
		list( $percent_diff, $percent_diff_line ) = static::size_percents( $original_size, $optimized_size );
		$optimized_exists_images_count            = $total_images - $unoptimized - $error_count; // оптимизированные картинки, которые сейчас есть в медиабиблиотеке
		if ( $total_images ) {
			$optimized_images_percent = round( $optimized_exists_images_count * 100 / $total_images );
		} else {
			$optimized_images_percent = 0;
		}

		return [
			'original'          => $total_images,
			'optimized'         => $optimized_exists_images_count,
			'optimized_percent' => $optimized_images_percent,
			'percent_line'      => $percent_diff_line,
			'unoptimized'       => $unoptimized,
			'optimized_size'    => $optimized_size,
			'original_size'     => $original_size,
			'save_size_percent' => $percent_diff,
			'error'             => $error_count,
		];
	}

	/**
	 * The statistics from the state rows. A skipped image is done: it counts as optimized, as for the attachments.
	 *
	 * @return array<string, int|float>
	 */
	protected function load_state() {
		$sizes          = \WRIO\Storage\Queries::sum_sizes( 'nextgen', 'optimized' );
		$total_images   = (int) $this->getTotalCount();
		$optimized      = $this->count_where( \WRIO\Storage\Queries::done( 'optimized', 'nextgen', 't1.pid' ) );
		$error_count    = $this->count_where( \WRIO\Storage\Queries::error( 'nextgen', 't1.pid' ) );
		$original_size  = $sizes['original_size'];
		$optimized_size = $sizes['final_size'];

		list( $percent_diff, $percent_diff_line ) = static::size_percents( $original_size, $optimized_size );

		return [
			'original'          => $total_images,
			'optimized'         => $optimized,
			'optimized_percent' => $total_images ? round( $optimized * 100 / $total_images ) : 0,
			'percent_line'      => $percent_diff_line,
			// Counted, not subtracted: an image can be done and have an error (a failed format) at the same time.
			'unoptimized'       => $this->count_where( \WRIO\Storage\Queries::untouched( 'nextgen', 't1.pid' ) ),
			'optimized_size'    => $optimized_size,
			'original_size'     => $original_size,
			'save_size_percent' => $percent_diff,
			'error'             => $error_count,
		];
	}

	/**
	 * Number of NextGEN images ("t1") that match a condition.
	 *
	 * @param string $condition SQL condition on "t1", without placeholders.
	 *
	 * @return int
	 */
	protected function count_where( $condition ) {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ngg_pictures t1 WHERE {$condition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the condition has no user input.
	}

	/**
	 * Общее кол-во изображений nextgen
	 */
	public function getTotalCount() {
		global $wpdb;
		$sql_total    = "SELECT COUNT(*) FROM {$wpdb->prefix}ngg_pictures";
		$total_images = $wpdb->get_var( $sql_total ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SQL with table names only.

		return $total_images;
	}

	/**
	 * Кол-во неоптимизированных изображений
	 */
	public function getUnoptimizedCount() {
		global $wpdb;

		if ( \WRIO\Storage\Backfill::reads() ) {
			return $this->count_where( \WRIO\Storage\Queries::work( 'nextgen', 't1.pid' ) );
		}
		$io_db_table     = RIO_Process_Queue::table_name();
		$sql_unoptimized = "SELECT COUNT(*)
			FROM {$wpdb->prefix}ngg_pictures as t1 LEFT JOIN {$io_db_table} as t2 
			ON t2.item_type = 'nextgen' and t1.pid = t2.object_id
			WHERE t2.result_status Is Null or ( t2.result_status != 'success' and t2.result_status != 'error' )";
		$total_images    = $wpdb->get_var( $sql_unoptimized ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SQL with table names only.

		return $total_images;
	}

	/**
	 * Возвращает неоптимизированные изображения
	 *
	 * @param string $limit ограничение выборки
	 *
	 * @return array
	 */
	public function getUnoptimized( $limit = 10 ) {
		global $wpdb;

		if ( \WRIO\Storage\Backfill::reads() ) {
			// An image that waits for a retry is not picked, so a bulk run ends. A done image with a due thumbnail is.
			return $wpdb->get_results( "SELECT t1.* FROM {$wpdb->prefix}ngg_pictures t1 WHERE " . \WRIO\Storage\Queries::work( 'nextgen', 't1.pid' ) . ' ORDER BY t1.pid LIMIT ' . absint( $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		}
		$io_db_table     = RIO_Process_Queue::table_name();
		$sql_unoptimized = "SELECT *
			FROM {$wpdb->prefix}ngg_pictures as t1 LEFT JOIN {$io_db_table} as t2 
			ON t2.item_type = 'nextgen' and t1.pid = t2.object_id
			WHERE t2.result_status Is Null or ( t2.result_status != 'success' and t2.result_status != 'error' )
			LIMIT " . intval( $limit );
		$unoptimized     = $wpdb->get_results( $sql_unoptimized ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SQL with table names, the limit is an integer.

		return $unoptimized;
	}

	/**
	 * Returns the result of the last optimized images.
	 *
	 * @param int $limit Limit.
	 *
	 * @return array<int, array<string, mixed>> Log entries: id, file_name, url, thumbnail_url, sizes, savings, type, error_msg.
	 */
	public function get_last_optimized_images( $limit = 100 ) {
		global $wpdb;
		$logs     = [];
		$db_table = RIO_Process_Queue::table_name();
		$logged   = [];

		if ( \WRIO\Storage\Backfill::reads() ) {
			$logged = array_column( static::last_logged( 'nextgen', (int) $limit ), null, 'object_id' );

			if ( empty( $logged ) ) {
				return [];
			}

			$ids = implode( ',', array_map( 'intval', array_keys( $logged ) ) );
			// An optimization without a restore adds a queue row (createOptimizationData()): the latest one is the image's.
			$sql = "SELECT t1.*,t2.filename as file_name, t3.path as gallery_path
					FROM {$db_table} as t1
					LEFT JOIN {$wpdb->prefix}ngg_pictures as t2 ON t1.object_id = t2.pid
					LEFT JOIN {$wpdb->prefix}ngg_gallery as t3 ON t2.galleryid = t3.gid
					WHERE t1.item_type = 'nextgen' AND t1.object_id IN ( {$ids} )
						AND t1.id IN ( SELECT MAX( id ) FROM {$db_table} WHERE item_type = 'nextgen' AND object_id IN ( {$ids} ) GROUP BY object_id )
					ORDER BY FIELD( t1.object_id, {$ids} )";
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin and NextGEN tables.
			$sql = $wpdb->prepare(
				"SELECT t1.*,t2.filename as file_name, t3.path as gallery_path
					FROM {$db_table} as t1
					LEFT JOIN {$wpdb->prefix}ngg_pictures as t2 ON t1.object_id = t2.pid
					LEFT JOIN {$wpdb->prefix}ngg_gallery as t3 ON t2.galleryid = t3.gid
					WHERE t1.item_type = 'nextgen' AND t1.result_status IN (%s, %s)
					ORDER BY id DESC
					LIMIT %d ;",
				RIO_Process_Queue::STATUS_SUCCESS,
				RIO_Process_Queue::STATUS_ERROR,
				$limit
			);
			// phpcs:enable
		}

		$optimized_images = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above, or integer IDs.

		if ( empty( $optimized_images ) ) {
			return [];
		}

		foreach ( $optimized_images as $row ) {
			$log = [];

			$optimization_data = new RIO_Process_Queue( $row );

			/**
			 * @var WRIO_Nextgen_Extra_Data $extra_data
			 */
			$extra_data = $optimization_data->get_extra_data();

			$original_main_size = 0;

			if ( ! empty( $extra_data ) ) {
				$original_main_size = $extra_data->get_original_main_size();
				$webp_size          = \WRIO\V2\Format_Rows::final_size( site_url( trailingslashit( $row->gallery_path ) . $row->file_name ) );
				$log['webp_size']   = $webp_size ? size_format( $webp_size, 2 ) : '-';

				$error = $extra_data->get_error();

				if ( $row->result_status == RIO_Process_Queue::STATUS_ERROR || ! empty( $error ) ) {
					$log['type'] = 'error';

					$error_message = $extra_data->get_error_msg();

					$log['error_msg'] = ! empty( $error_message ) ? $error_message : esc_html__( 'Unknown error', 'robin-image-optimizer' );
				}
			}

			$main_file                = trailingslashit( ABSPATH ) . trailingslashit( $row->gallery_path ) . $row->file_name;
			$main_file_optimized_size = $main_saving = $total_saving = 0;

			if ( file_exists( $main_file ) ) {
				$main_file_optimized_size = filesize( $main_file );
			}

			if ( $original_main_size ) {
				$main_saving = ( $original_main_size - $main_file_optimized_size ) * 100 / $original_main_size;
			}

			if ( $row->original_size ) {
				$total_saving = ( $row->original_size - $row->final_size ) * 100 / $row->original_size;
			}

			$image_url     = site_url( trailingslashit( $row->gallery_path ) . $row->file_name );
			$thumbnail_url = site_url( trailingslashit( $row->gallery_path ) . 'thumbs/thumbs-' . $row->file_name );

			$entry = array_merge(
				$log,
				[
					'id'               => $row->id,
					'url'              => $image_url,
					'thumbnail_url'    => $thumbnail_url,
					'file_name'        => preg_replace( '/^.+[\\\\\\/]/', '', $main_file ),
					'original_size'    => size_format( $row->original_size, 2 ),
					'optimized_size'   => size_format( $row->final_size, 2 ),
					'original_saving'  => static::percent_label( $main_saving ),
					'thumbnails_count' => 1,
					'total_saving'     => static::percent_label( $total_saving ),
				]
			);

			$logs[] = isset( $logged[ $row->object_id ] ) ? static::with_log( $entry, $logged[ $row->object_id ] ) : $entry;
		}

		return $logs;
	}

	/**
	 * What the failures list shows of a NextGEN image.
	 *
	 * @param int $id Picture ID.
	 *
	 * @return array{id: int, file_name: string, url: string, thumbnail_url: string, original_url: string}
	 */
	protected function failure_item( int $id ): array {
		global $wpdb;

		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT p.filename, g.path FROM {$wpdb->prefix}ngg_pictures p LEFT JOIN {$wpdb->prefix}ngg_gallery g ON p.galleryid = g.gid WHERE p.pid = %d", $id ) );
		$file = $row ? (string) $row->filename : '';
		$dir  = $row ? trailingslashit( (string) $row->path ) : '';
		$url  = $row ? site_url( $dir . $file ) : '';

		return [
			'id'            => $id,
			'file_name'     => $file,
			'url'           => $url,
			'thumbnail_url' => $row ? site_url( $dir . 'thumbs/thumbs-' . $file ) : '',
			'original_url'  => $url,
		];
	}
}
