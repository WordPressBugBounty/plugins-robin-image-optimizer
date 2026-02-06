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
class WRIO_Image_Statistic_Folders extends WRIO_Image_Statistic {

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    static
	 */
	protected static $_instance;

	/**
	 * Сохранение статистики
	 */
	public function save() {
		WRIO_Plugin::app()->updateOption( 'folders_original_size', $this->statistic['original_size'] );
		WRIO_Plugin::app()->updateOption( 'folders_optimized_size', $this->statistic['optimized_size'] );
	}

	/**
	 * Загрузка статистики и расчёт некоторых параметров
	 *
	 * @return array
	 */
	public function load() {
		$original_size   = WRIO_Plugin::app()->getOption( 'folders_original_size', 0 );
		$optimized_size  = WRIO_Plugin::app()->getOption( 'folders_optimized_size', 0 );
		$total_images    = $this->getTotalCount();
		$error_count     = RIO_Process_Queue::count_by_type_status( 'cf_image', 'error' );
		$skipped_count   = RIO_Process_Queue::count_by_type_status( 'cf_image', 'skip' );
		$optimized_count = $this->getOptimizedCount();

		if ( ! $total_images ) {
			$total_images = 0;
			if ( $original_size || $optimized_size ) {
				// если нет картинок, то и размеров не должно быть
				$original_size  = $this->statistic['original_size'] = 0;
				$optimized_size = $this->statistic['optimized_size'] = 0;
				$this->save();
			}
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
		// unoptimized count: all - optimized - error - skip
		$unoptimized_count = $total_images - $optimized_count - $error_count - $skipped_count;
		if ( $optimized_size and $original_size ) {
			$percent_diff      = round( ( $original_size - $optimized_size ) * 100 / $original_size, 1 );
			$percent_diff_line = round( $optimized_size * 100 / $original_size, 0 );
		} else {
			$percent_diff      = 0;
			$percent_diff_line = 100;
		}
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

	public function getOptimizedCount() {
		$current_folder = apply_filters( 'wriop_cf_current_folder', false );
		if ( $current_folder ) {
			global $wpdb;
			$db_table        = RIO_Process_Queue::table_name();
			$sql_optimized   = $wpdb->prepare( "SELECT COUNT(*) FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'success';", $current_folder );
			$optimized_count = $wpdb->get_var( $sql_optimized );
		} else {
			$optimized_count = RIO_Process_Queue::count_by_type_status( 'cf_image', 'success' );
		}

		return $optimized_count;
	}

	/**
	 * Кол-во неоптимизированных изображений
	 */
	public function getUnoptimizedCount() {
		global $wpdb;

		$db_table       = RIO_Process_Queue::table_name();
		$current_folder = apply_filters( 'wriop_cf_current_folder', false );

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

		$unoptimized = $wpdb->get_var( $sql_unoptimized );

		return $unoptimized;
	}

	/**
	 * Возвращает неоптимизированные изображения
	 *
	 * @param int $limit   ограничение выборки
	 *
	 * @return RIO_Process_Queue[]|array
	 */
	public function getUnoptimized( $limit = 10 ) {
		// переделать
		global $wpdb;

		$unoptimized_items = [];
		$db_table          = RIO_Process_Queue::table_name();
		$current_folder    = apply_filters( 'wriop_cf_current_folder', false );

		if ( $current_folder ) {
			$sql_unoptimized = $wpdb->prepare( "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'unoptimized' LIMIT %d", $current_folder, $limit );
		} else {
			$sql_unoptimized = "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND result_status = 'unoptimized' LIMIT " . intval( $limit );
		}

		$result = $wpdb->get_results( $sql_unoptimized );

		if ( ! empty( $result ) ) {
			foreach ( $result as $key => $data ) {
				$unoptimized_items[ $key ] = new RIO_Process_Queue( $data );
			}
		}

		return $unoptimized_items;
	}

	public function getDeferredUnoptimized( $limit = 10 ) {
		global $wpdb;

		$db_table       = RIO_Process_Queue::table_name();
		$current_folder = apply_filters( 'wriop_cf_current_folder', false );
		if ( $current_folder ) {
			$sql_unoptimized = $wpdb->prepare( "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'processing' LIMIT %d", $current_folder, $limit );
		} else {
			$sql_unoptimized = "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' and result_status = 'processing' LIMIT " . intval( $limit );
		}
		$unoptimized = $wpdb->get_results( $sql_unoptimized );

		return $unoptimized;
	}

	/**
	 * Returns the result of the last optimized images.
	 *
	 * @param int $limit Limit.
	 *
	 * @return array<int, array{
	 *     id: int|string,
	 *     file_name: string,
	 *     url: string,
	 *     thumbnail_url: string,
	 *     original_size: string,
	 *     optimized_size: string,
	 *     webp_size?: string,
	 *     original_saving: string,
	 *     thumbnails_count: int,
	 *     type: string,
	 *     total_saving: string,
	 *     error_msg?: string
	 * }>
	 */
	public function get_last_optimized_images( $limit = 100 ) {
		global $wpdb;

		$items    = [];
		$db_table = RIO_Process_Queue::table_name();

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

		$optimized_images = $wpdb->get_results( $sql, ARRAY_A );

		foreach ( $optimized_images as $row ) {
			$items[] = $this->format_for_log( new RIO_Process_Queue( $row ) );
		}

		return $items;
	}

	/**
	 * Get the last optimized image record for a specific model.
	 *
	 * @param RIO_Process_Queue $model Queue model instance.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since  1.1
	 */
	public function get_last_optimized_image( $model ) {
		$items   = [];
		$items[] = $this->format_for_log( $model );

		return $items;
	}

	/**
	 * @since  1.0.4
	 *
	 * @param int|RIO_Process_Queue $queue_model
	 */
	protected function format_for_log( $queue_model ) {
		if ( $queue_model instanceof RIO_Process_Queue ) {
			$cf_image = new WRIO_Folder_Image( $queue_model->id, $queue_model );
		} else {
			// todo: Temporarily fix
			$cf_image = new WRIO_Folder_Image( $queue_model );
		}

		$optimization_data = $cf_image->getOptimizationData();

		$main_file   = $cf_image->get( 'path' );
		$main_saving = $total_saving = 0;

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
			'original_saving'  => round( $main_saving ) . '%',
			'thumbnails_count' => 0,
			'type'             => 'success',
			'total_saving'     => round( $total_saving ) . '%',
		];

		/**
		 * @var WRIO_CF_Image_Extra_Data $extra_data
		 */
		$extra_data = $optimization_data->get_extra_data();

		if ( ! empty( $extra_data ) ) {
			$webp_size = $extra_data->get_webp_main_size();
			if ( $webp_size ) {
				$webp_size = size_format( $webp_size, 2 );
			} else {
				$webp_size = '-';
			}

			$formated_data['webp_size'] = $webp_size;

			$error = $extra_data->get_error();

			if ( $optimization_data->result_status === RIO_Process_Queue::STATUS_ERROR || ! empty( $error ) ) {
				$formated_data['type'] = 'error';

				$error_message = $extra_data->get_error_msg();

				$formated_data['error_msg'] = ! empty( $error_message ) ? $error_message : esc_html__( 'Unknown error', 'robin-image-optimizer' );
			}
		}

		return $formated_data;
	}
}
