<?php
/**
 * Statistics of the custom folder images.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы со статистическими данными по оптимизации изображений
 *
 * @version       1.0
 *
 * @phpstan-import-type LogRow from WRIO_Image_Statistic
 */
class WRIO_Image_Statistic_Folders extends WRIO_Image_Statistic {

	/**
	 * The source of the state rows of the items.
	 */
	const SOURCE = 'cf_image';

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
	 * @return array<string, mixed>
	 */
	public function load() {
		if ( \WRIO\Storage\Backfill::reads() ) {
			// A skipped file is done: it counts as optimized, as for the attachments.
			$sizes         = \WRIO\Storage\Queries::sum_sizes( 'cf_image', 'optimized' );
			$error_count   = $this->count_where( \WRIO\Storage\Queries::error( 'cf_image', 'q.id' ) );
			$skipped_count = 0;
		} else {
			$sizes         = RIO_Process_Queue::sum_sizes( 'cf_image' );
			$error_count   = RIO_Process_Queue::count_by_type_status( 'cf_image', 'error' );
			$skipped_count = RIO_Process_Queue::count_by_type_status( 'cf_image', 'skip' );
		}

		$original_size   = $sizes['original_size'];
		$optimized_size  = $sizes['final_size'];
		$total_images    = $this->getTotalCount();
		$optimized_count = $this->getOptimizedCount();

		if ( ! $total_images ) {
			$total_images = 0;
		}
		if ( ! $error_count ) {
			$error_count = 0;
		}
		if ( ! $skipped_count ) {
			$skipped_count = 0;
		}
		if ( ! $optimized_count ) {
			$optimized_count = 0;
		}
		// unoptimized count: all - optimized - error - skip. With the state rows it is counted, not subtracted: a file
		// can be done and have an error (a failed format) at the same time.
		$unoptimized_count                        = \WRIO\Storage\Backfill::reads()
			? $this->count_where( \WRIO\Storage\Queries::untouched( 'cf_image', 'q.id' ) )
			: max( 0, $total_images - $optimized_count - $error_count - $skipped_count );
		list( $percent_diff, $percent_diff_line ) = static::size_percents( $original_size, $optimized_size );
		if ( $total_images ) {
			$optimized_images_percent = round( $optimized_count * 100 / $total_images );
		} else {
			$optimized_images_percent = 0;
		}

		return [
			'original'          => $total_images,
			'optimized'         => $optimized_count,
			'optimized_percent' => $optimized_images_percent,
			'percent_line'      => $percent_diff_line,
			'unoptimized'       => $unoptimized_count,
			'optimized_size'    => $optimized_size,
			'original_size'     => $original_size,
			'save_size_percent' => $percent_diff,
			'error'             => $error_count,
		];
	}

	/**
	 * Общее кол-во изображений
	 *
	 * @return int
	 */
	public function getTotalCount() {
		$cf             = WRIO_Custom_Folders::get_instance();
		$current_folder = apply_filters( 'wriop_cf_current_folder', false );
		if ( $current_folder ) {
			// если нужна конкретная папка
			$folder = $cf->getFolder( $current_folder );
			if ( ! $folder ) {
				return 0;
			}

			return $folder->get( 'files_count' );
		}
		$folders      = $cf->getFolders();
		$total_images = 0;
		if ( ! $folders ) {
			return $total_images;
		}
		foreach ( $folders as $folder ) {
			$total_images += $folder->get( 'files_count' );
		}

		return $total_images;
	}

	/**
	 * Number of optimized files.
	 *
	 * @return int
	 */
	public function getOptimizedCount() {
		if ( \WRIO\Storage\Backfill::reads() ) {
			return $this->count_where( \WRIO\Storage\Queries::done( 'optimized', 'cf_image', 'q.id' ) );
		}

		$current_folder = apply_filters( 'wriop_cf_current_folder', false );
		if ( $current_folder ) {
			global $wpdb;
			$db_table        = RIO_Process_Queue::table_name();
			$sql_optimized   = $wpdb->prepare( "SELECT COUNT(*) FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'success';", $current_folder ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
			$optimized_count = $wpdb->get_var( $sql_optimized ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		} else {
			$optimized_count = RIO_Process_Queue::count_by_type_status( 'cf_image', 'success' );
		}

		return (int) $optimized_count;
	}

	/**
	 * Кол-во неоптимизированных изображений
	 *
	 * @return int
	 */
	public function getUnoptimizedCount() {
		global $wpdb;

		if ( \WRIO\Storage\Backfill::reads() ) {
			return $this->count_where( \WRIO\Storage\Queries::work( 'cf_image', 'q.id' ) );
		}

		$db_table       = RIO_Process_Queue::table_name();
		$current_folder = apply_filters( 'wriop_cf_current_folder', false );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
		if ( $current_folder ) {
			$sql_unoptimized = $wpdb->prepare(
				"
			SELECT COUNT(*)
			FROM {$db_table}
			WHERE item_type = 'cf_image'
			AND item_hash_alternative = %s
			AND result_status IN (%s,%s);",
				$current_folder,
				RIO_Process_Queue::STATUS_UNOPTIMIZED,
				RIO_Process_Queue::STATUS_PROCESSING
			);
		} else {
			$sql_unoptimized = $wpdb->prepare(
				"
			SELECT COUNT(*)
			FROM {$db_table} WHERE
			item_type = 'cf_image' AND result_status IN (%s,%s);",
				RIO_Process_Queue::STATUS_UNOPTIMIZED,
				RIO_Process_Queue::STATUS_PROCESSING
			);
		}
		// phpcs:enable

		$unoptimized = $wpdb->get_var( $sql_unoptimized ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return $unoptimized;
	}

	/**
	 * Возвращает неоптимизированные изображения
	 *
	 * @param int $limit   Ограничение выборки.
	 *
	 * @return RIO_Process_Queue[]|array
	 */
	public function getUnoptimized( $limit = 10 ) {
		// переделать
		global $wpdb;

		$unoptimized_items = [];
		$db_table          = RIO_Process_Queue::table_name();
		$current_folder    = apply_filters( 'wriop_cf_current_folder', false );

		if ( \WRIO\Storage\Backfill::reads() ) {
			// A file that waits for a retry is not picked, so a bulk run ends. A done file with a due WebP is.
			$sql_unoptimized = $this->rows_where( 'q.*', \WRIO\Storage\Queries::work( 'cf_image', 'q.id' ) ) . ' ORDER BY q.id LIMIT ' . absint( $limit );
		} elseif ( $current_folder ) {
			$sql_unoptimized = $wpdb->prepare( "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'unoptimized' LIMIT %d", $current_folder, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
		} else {
			$sql_unoptimized = "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND result_status = 'unoptimized' LIMIT " . intval( $limit );
		}

		$result = $wpdb->get_results( $sql_unoptimized ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above, or fixed SQL with an integer limit.

		if ( ! empty( $result ) ) {
			foreach ( $result as $key => $data ) {
				$unoptimized_items[ $key ] = new RIO_Process_Queue( $data );
			}
		}

		return $unoptimized_items;
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

		$items    = [];
		$db_table = RIO_Process_Queue::table_name();

		if ( \WRIO\Storage\Backfill::reads() ) {
			$logged = array_column( static::last_logged( 'cf_image', (int) $limit ), null, 'object_id' );

			if ( empty( $logged ) ) {
				return [];
			}

			$ids  = implode( ',', array_map( 'intval', array_keys( $logged ) ) );
			$rows = $wpdb->get_results( "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND id IN ( {$ids} ) ORDER BY FIELD( id, {$ids} )", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, integer IDs.

			foreach ( (array) $rows as $row ) {
				$items[] = static::with_log( $this->format_for_log( new RIO_Process_Queue( $row ) ), $logged[ $row['id'] ] );
			}

			return $items;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
		$sql = $wpdb->prepare(
			"SELECT *
					FROM {$db_table} as t1 
					WHERE t1.item_type = 'cf_image' 
					AND t1.result_status 
					IN (%s, %s)
					ORDER BY id DESC
					LIMIT %d;",
			RIO_Process_Queue::STATUS_SUCCESS,
			RIO_Process_Queue::STATUS_ERROR,
			$limit
		);
		// phpcs:enable

		$optimized_images = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		foreach ( $optimized_images as $row ) {
			$items[] = $this->format_for_log( new RIO_Process_Queue( $row ) );
		}

		return $items;
	}

	/**
	 * Get the last optimized image record for a specific model.
	 *
	 * @param RIO_Process_Queue|int $model  Queue model, or the ID of a queue row (the bulk step passes one).
	 * @param LogRow|null           $logged Its latest history row from last_logged(). Null: read it.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since  1.1
	 */
	public function get_last_optimized_image( $model, $logged = null ) {
		$item = $this->format_for_log( $model );

		if ( \WRIO\Storage\Backfill::reads() ) {
			// The bulk step passes the ID of the queue row, the log list a queue row.
			$id   = is_object( $model ) ? (int) $model->get_id() : (int) $model;
			$item = static::with_log( $item, $logged ?? static::last_logged( 'cf_image', 1, $id )[0] ?? null );
		}

		return [ $item ];
	}

	/**
	 * SQL of the queue rows of the custom-folder files (one per file), in the current folder when one is set.
	 *
	 * @param string $select    Columns of "q".
	 * @param string $condition SQL condition on "q", without placeholders.
	 *
	 * @return string
	 */
	protected function rows_where( $select, $condition ) {
		global $wpdb;

		$sql    = 'SELECT ' . $select . ' FROM ' . RIO_Process_Queue::table_name() . " q WHERE q.item_type = 'cf_image' AND " . $condition;
		$folder = apply_filters( 'wriop_cf_current_folder', false );

		return $folder ? $wpdb->prepare( $sql . ' AND q.item_hash_alternative = %s', $folder ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the condition has no placeholders.
	}

	/**
	 * Number of custom-folder files that match a condition.
	 *
	 * @param string $condition SQL condition on "q", without placeholders.
	 *
	 * @return int
	 */
	protected function count_where( $condition ) {
		global $wpdb;

		return (int) $wpdb->get_var( $this->rows_where( 'COUNT(*)', $condition ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared in rows_where().
	}

	/**
	 * Formats one queue row for the bulk log.
	 *
	 * @since  1.0.4
	 *
	 * @param int|RIO_Process_Queue $queue_model Queue row or image ID.
	 */
	protected function format_for_log( $queue_model ) {
		if ( $queue_model instanceof RIO_Process_Queue ) {
			$cf_image = new WRIO_Folder_Image( $queue_model->id, $queue_model );
		} else {
			// todo: Temporarily fix
			$cf_image = new WRIO_Folder_Image( $queue_model );
		}

		$optimization_data = $cf_image->getOptimizationData();

		$main_file    = $cf_image->get( 'path' );
		$total_saving = 0;
		$main_saving  = 0;

		if ( $optimization_data->original_size ) {
			$total_saving = ( $optimization_data->original_size - $optimization_data->final_size ) * 100 / $optimization_data->original_size;
			$main_saving  = $total_saving;
		}

		$image_url     = $cf_image->get( 'url' );
		$thumbnail_url = $image_url;

		$formated_data = [
			'id'               => $optimization_data->id,
			'url'              => $image_url,
			'original_url'     => $image_url,
			'thumbnail_url'    => $thumbnail_url,
			'file_name'        => wp_basename( $main_file ),
			'original_size'    => size_format( $optimization_data->original_size, 2 ),
			'optimized_size'   => size_format( $optimization_data->final_size, 2 ),
			'original_saving'  => static::percent_label( $main_saving ),
			'thumbnails_count' => 0,
			'type'             => 'success',
			'total_saving'     => static::percent_label( $total_saving ),
		];

		/**
		 * Extra data of the optimization row.
		 *
		 * @var WRIO_CF_Image_Extra_Data $extra_data
		 */
		$extra_data = $optimization_data->get_extra_data();

		if ( ! empty( $extra_data ) ) {
			$webp_size                  = \WRIO\V2\Format_Rows::final_size( home_url( wp_normalize_path( $extra_data->get_file_path() ) ) );
			$formated_data['webp_size'] = $webp_size ? size_format( $webp_size, 2 ) : '-';

			$error = $extra_data->get_error();

			if ( RIO_Process_Queue::STATUS_ERROR === $optimization_data->result_status || ! empty( $error ) ) {
				$formated_data['type'] = 'error';

				$error_message = $extra_data->get_error_msg();

				$formated_data['error_msg'] = ! empty( $error_message ) ? $error_message : esc_html__( 'Unknown error', 'robin-image-optimizer' );
			}
		}

		return $formated_data;
	}

	/**
	 * What the failures list shows of a custom-folder file.
	 *
	 * @param int $id ID of the file's queue row.
	 *
	 * @return array{id: int, file_name: string, url: string, thumbnail_url: string, original_url: string}
	 */
	protected function failure_item( int $id ): array {
		$image = new WRIO_Folder_Image( $id );
		$url   = (string) $image->get( 'url' );

		return [
			'id'            => $id,
			'file_name'     => wp_basename( (string) $image->get( 'path' ) ),
			'url'           => $url,
			'thumbnail_url' => $url,
			'original_url'  => $url,
		];
	}
}
