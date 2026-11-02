<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с галереей nextgen
 *
 * @version       1.0
 */
class WRIO_Nextgen_Gallery {

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    object
	 */
	protected static $_instance;

	/**
	 * @var array $nextgen_images контейнер для хранения nextgen_images
	 */
	private $nextgen_images = [];

	/**
	 * Инициализация функционала nextgen галереи. Установка хуков
	 */
	public function __construct() {
		if ( ! wrio_is_active_nextgen_gallery() ) {
			return;
		}

		require_once WRIOP_PLUGIN_DIR . '/includes/classes/models/class.nextgen-extra-data.php';
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.image-nextgen.php';
		require_once WRIOP_PLUGIN_DIR . '/admin/ajax/optimization.php';

		add_filter( 'ngg_manage_images_number_of_columns', [ $this, 'addColumns' ] );

		add_action( 'wp_ajax_wio_ng_reoptimize_image', 'wbcr_riop_reoptimizeImage' );
		add_action( 'wp_ajax_wio_ng_restore_image', 'wbcr_riop_restoreImage' );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueMeadiaScripts' ], 10 );
		add_action( 'ngg_delete_picture', [ $this, 'deleteImageHook' ], 10, 2 );
		add_action( 'ngg_recovered_image', [ $this, 'recoverImageHook' ], 10, 1 );

		add_filter(
			'wbcr/rio/optimize_template/reoptimize_ajax_action',
			[
				$this,
				'reoptimizeAjaxAction',
			],
			10,
			2
		);

		add_filter( 'wbcr/rio/optimize_template/restore_ajax_action', [ $this, 'restoreAjaxAction' ], 10, 2 );
		// add_filter( 'wbcr/rio/multisite_blogs', [ $this, 'multisiteBlogs' ], 10, 2 );
		add_action( 'wbcr/rio/optimize_template/optimized_percent', [ $this, 'optimizedPercent' ], 10, 2 );
	}

	/**
	 * @return object|\WRIO_Nextgen_Gallery object Main instance.
	 * @since  1.3.0
	 */
	public static function get_instance() {
		if ( ! isset( static::$_instance ) ) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}

	/**
	 * Возвращает сайты, на которых установлен NextGen gallery
	 * Используется в мультисайт режиме
	 *
	 * @param array $blogs сайты
	 * @param string $type тип
	 *
	 * @return array сайты, на которых установлен nextgen
	 */
	/*
	public function multisiteBlogs( $blogs, $type ) {
		if ( 'nextgen' == $type ) {
			$nextgen_basename = 'nextgen-gallery/nggallery.php';
			if ( is_plugin_active_for_network( $nextgen_basename ) ) {
				return $blogs;
			} else {
				$nextgen_blogs = [];
				foreach ( $blogs as $blog ) {
					switch_to_blog( intval( $blog->blog_id ) );
					if ( is_plugin_active( $nextgen_basename ) ) {
						$nextgen_blogs[] = $blog;
					}
					restore_current_blog();
				}

				return $nextgen_blogs;
			}
		}

		return $blogs;
	}*/

	/**
	 * Хук возвращает ajax action для кнопки переоптимизации
	 */
	public function reoptimizeAjaxAction( $action, $type ) {
		if ( $type == 'nextgen' ) {
			return 'wio_ng_reoptimize_image';
		}

		return $action;
	}

	/**
	 * Хук возвращает ajax action для кнопки восстановления
	 */
	public function restoreAjaxAction( $action, $type ) {
		if ( $type == 'nextgen' ) {
			return 'wio_ng_restore_image';
		}

		return $action;
	}

	/**
	 * Добавляем стили и скрипты в медиабиблиотеку
	 */
	public function enqueueMeadiaScripts( $hook ) {
		if ( strpos( $hook, 'page_nggallery-manage-gallery' ) === false ) {
			return;
		}
		wp_enqueue_style( 'wio-install-addons', WRIO_PLUGIN_URL . '/admin/assets/css/media.css', [], WRIO_Plugin::app()->getPluginVersion() );
		WRIO_App::enqueue_script( 'wio-install-addons', 'single-optimization' );
	}

	/**
	 * Добавляет колонку оптимизации в галерею nextgen
	 */
	public function addColumns( $count ) {
		++$count;
		add_filter( 'ngg_manage_images_column_' . $count . '_header', [ $this, 'columnTitle' ] );
		add_filter( 'ngg_manage_images_column_' . $count . '_content', [ $this, 'columnContent' ], 10, 2 );

		return $count;
	}

	/**
	 * Название колонки оптимизации в галерее
	 */
	public function columnTitle() {
		return __( 'Image optimizer', 'robin-image-optimizer' );
	}

	/**
	 * Возвращает содержимое блока оптимизации
	 */
	public function columnContent( $output, $image ) {
		$output = $this->getMediaColumnContent( $image->pid );

		return $output;
	}

	/**
	 * Возвращает блок оптимизации
	 */
	public function getMediaColumnContent( $image_id ) {

		$media_library = WRIO_Media_Library::get_instance();
		$params        = $this->calculateParams( $image_id );

		return $media_library->getMediaColumnTemplate( $params, 'nextgen' );
	}

	/**
	 * Просчитывает параметры блока оптимизации
	 *
	 * @param int $image_id номер изображения nextgen
	 *
	 * @return array $params
	 */
	public function calculateParams( $image_id ) {
		$nextgen_image        = new WRIO_Image_Nextgen( $image_id );
		$isOptimized          = $nextgen_image->isOptimized();
		$diff_percent         = 0;
		$diff_percent_all     = 0;
		$original_main_size   = 0;
		$attachment_file_size = 0;
		$optimized_size       = 0;
		$original_size        = 0;
		$optimization_level   = '';
		$error_msg            = '';
		$backuped             = '';
		if ( $isOptimized ) {
			$optimization_data = $nextgen_image->getOptimizationData();
			/**
			 * @var WRIO_Nextgen_Extra_Data $extra_data
			 */
			$extra_data           = $optimization_data->get_extra_data();
			$optimization_level   = $optimization_data->get_processing_level();
			$original_main_size   = $extra_data->get_original_main_size();
			$attachment_file_size = $optimization_data->get_final_size();
			$optimized_size       = $optimization_data->get_final_size();
			$original_size        = $optimization_data->get_original_size();
			$backuped             = $optimization_data->get_is_backed_up();
			$error_msg            = $extra_data->get_error_msg();
			if ( $attachment_file_size and $original_main_size ) {
				$diff_percent = round( ( $original_main_size - $attachment_file_size ) * 100 / $original_main_size );
			}
			if ( $optimized_size and $original_size ) {
				$diff_percent_all = round( ( $original_size - $optimized_size ) * 100 / $original_size );
			}
		} else {
			// The main file failed: show why, and the restore of an optimized thumbnail.
			$extra_data = $nextgen_image->getOptimizationData()->get_extra_data();
			$error_msg  = $extra_data instanceof WRIO_Nextgen_Extra_Data ? $extra_data->get_error_msg() : '';
			$backuped   = $nextgen_image->has_backup();
		}
		$params = [
			'attachment_id'        => $image_id,
			'is_optimized'         => $isOptimized,
			'attach_dimensions'    => 0,
			'attachment_file_size' => $attachment_file_size,
			'optimized_size'       => $optimized_size,
			'original_size'        => $original_size,
			'original_main_size'   => $original_main_size,
			'thumbnails_optimized' => 1,
			'optimization_level'   => $optimization_level,
			'error_msg'            => $error_msg,
			'backuped'             => $backuped,
			'other_levels'         => \WRIO\V2\Optimizer::has_original( 'nextgen', (int) $image_id ),
			'diff_percent'         => $diff_percent,
			'diff_percent_all'     => $diff_percent_all,
			'is_skipped'           => false,
		];

		return $params;
	}

	/**
	 * Возвращает объект nextgen_image
	 *
	 * @param int   $image_id
	 * @param array $image_meta
	 *
	 * @return WRIO_Image_Nextgen
	 */
	public function getNextgenImage( $image_id, $image_meta = false ) {
		if ( ! isset( $this->nextgen_images[ $image_id ] ) ) {
			$this->nextgen_images[ $image_id ] = new WRIO_Image_Nextgen( $image_id, $image_meta );
		}

		return $this->nextgen_images[ $image_id ];
	}

	/**
	 * Оптимизирует nextgen_image
	 *
	 * @param int    $image_id номер картинки в таблице nextgen
	 * @param string $level качество
	 *
	 * @return array{stop: WP_Error|null}|array{error: WP_Error}|array{errors_count: int, original_size: int, optimized_size: int, optimized_count: int}
	 */
	public function optimizeNextgenImage( $image_id, $level = '' ) {
		$nextgen_image = $this->getNextgenImage( $image_id );

		// A bulk run (no level) on a done image: only a format that was turned on later is missing.
		if ( '' === (string) $level && $nextgen_image->isOptimized() && wrio_converts_only( 'nextgen', (int) $image_id, $nextgen_image->get_files() ) ) {
			$error = ( new \WRIO\V2\Optimizer() )->convert_item( 'nextgen', (int) $image_id, $nextgen_image->get_files(), WRIO_Formats::get_enabled_formats() );

			return [ 'stop' => wrio_convert_stop_error( 'nextgen', (int) $image_id, $error ) ];
		}

		// See WRIO_Media_Library::optimizeAttachment(): a new level needs the original.
		$no_original = $nextgen_image->isOptimized() ? \WRIO\V2\Optimizer::no_original( 'nextgen', (int) $image_id, $level, $nextgen_image->getOptimizationData()->get_processing_level() ) : null;

		if ( $no_original ) {
			return [ 'error' => $no_original ];
		}

		if ( $nextgen_image->isOptimized() && \WRIO\V2\Optimizer::restores_first( 'nextgen', (int) $image_id, $level, $nextgen_image->getOptimizationData()->get_processing_level() ) ) {
			$nextgen_image->restore();
		}

		return $nextgen_image->optimize( $level );
	}

	/**
	 * Обработка не оптимизированных изображений
	 *
	 * @param int $max_process_per_request кол-во аттачментов за 1 запуск
	 *
	 * @return array|\WP_Error
	 */
	public function processUnoptimizedImages( $max_process_per_request = 5 ) {
		$backup               = WRIOP_Backup::get_instance();
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );
		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			return new WP_Error( 'unwritable_backup_dir', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}
		if ( ! $backup->isUploadWritable() ) {
			return new WP_Error( 'unwritable_upload_dir', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}
		// An unknown license key fails every image without a file row: the run would pick the same image forever.
		$limits = \WRIO\V2\Client::from_settings()->limits();

		if ( is_wp_error( $limits ) ) {
			return $limits;
		}

		$image_statistics = WRIO_Image_Statistic_Nextgen::get_instance();

		// выборка неоптимизированных изображений
		$gallery_images       = $image_statistics->getUnoptimized( $max_process_per_request ); // тут будет выборка
		$gallery_images_count = 0;
		if ( isset( $gallery_images ) ) {
			$gallery_images_count = count( $gallery_images );
		}

		$optimized_count = 0;

		// обработка
		if ( $gallery_images_count ) {
			foreach ( array_values( $gallery_images ) as $index => $gallery_image ) {
				if ( $index > 0 && ! wrio_has_time_left() ) {
					$gallery_images_count = $index;
					break;
				}

				$result = $this->optimizeNextgenImage( $gallery_image->pid );

				if ( ! empty( $result['stop'] ) ) {
					return $result['stop'];
				}
			}
		}

		// Counted again: an image whose request failed stays to do.
		$remain   = (int) $image_statistics->getUnoptimizedCount();
		$responce = [
			'remain'          => $remain,
			'end'             => false,
			'last_optimized'  => $image_statistics->get_last_optimized_images( $max_process_per_request ),
			'statistic'       => $image_statistics->load(),
			'optimized_count' => $optimized_count,
		];

		return $responce;
	}

	/**
	 * Сбрасывает текущие ошибки оптимизации
	 * Позволяет изображениям, которые оптимизированы с ошибкой, заново пройти оптимизацию.
	 *
	 * @return void
	 */
	public function resetCurrentErrors() {
		RIO_Process_Queue::reset_errors( 'nextgen' );
		\WRIO\Storage\Store::errors_reset( 'nextgen' );
	}

	/**
	 * Хук срабатывает при восстановлении картинок через стандартный интерфейс nextgen
	 */
	public function recoverImageHook( $image ) {
		$image_id      = isset( $image->pid ) ? $image->pid : 0;
		$nextgen_image = new WRIO_Image_Nextgen( $image_id );
		if ( $nextgen_image->isOptimized() ) {
			$optimization_data = $nextgen_image->getOptimizationData();

			// restore() returns true or a WP_Error: a WP_Error is truthy.
			if ( is_wp_error( $nextgen_image->restore() ) ) {
				// NextGEN has already replaced the image; its old conversions no longer match.
				$nextgen_image->forget_conversions();
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to restore Nextgen image. Object info: %s', wp_json_encode( $nextgen_image ) ) );
			}

			$optimization_data->delete();
		}
		$this->deleteNextgenOptimizationData( $image_id );
	}

	/**
	 * Хук срабатывает при удалении картинки
	 */
	public function deleteImageHook( $image_id, $image ) {
		$nextgen_image = new WRIO_Image_Nextgen( $image_id );

		if ( $nextgen_image->isOptimized() ) {
			$restored = $nextgen_image->restore();

			if ( is_wp_error( $restored ) ) {
				// The picture is deleted; its conversions and backups must not stay behind.
				$nextgen_image->forget_conversions();
				$backup = WRIOP_Backup::get_instance();
				if ( $backup instanceof WRIOP_Backup ) {
					$backup->deleteNextgenBackups( $nextgen_image );
				}
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to restore Nextgen image. Object info: %s', wp_json_encode( $nextgen_image ) ) );
			} else {
				$nextgen_image->getOptimizationData()->delete();
			}
		}
		$this->deleteNextgenOptimizationData( $image_id );
	}

	/**
	 * Удаляет данные по оптимизации из таблицы в базе данных
	 *
	 * @param int $image_id
	 *
	 * @return void
	 */
	protected function deleteNextgenOptimizationData( $image_id = 0 ) {
		global $wpdb;

		$db_table = RIO_Process_Queue::table_name();

		$wpdb->delete(
			$db_table,
			[
				'object_id' => $image_id,
				'item_type' => 'nextgen',
			]
		);
	}

	/**
	 * Возвращает процент оптимизации
	 * Фильтр wbcr/rio/optimize_template/optimized_percent
	 *
	 * @param int    $percent процент оптимизации
	 * @param string $type тип страницы
	 *
	 * @return int процент оптимизации
	 */
	public function optimizedPercent( $percent, $type ) {
		if ( 'nextgen' == $type ) {
			$image_statistics = WRIO_Image_Statistic_Nextgen::get_instance();

			return $image_statistics->getOptimizedPercent();
		}

		return $percent;
	}

	/**
	 * Get ID's of unoptimized attachments
	 *
	 * @return array
	 */
	public function getUnoptimizedImages() {
		$image_statistics = WRIO_Image_Statistic_Nextgen::get_instance();
		$gallery_images   = $image_statistics->getUnoptimized( PHP_INT_MAX ); // тут будет выборка

		$return = [];
		foreach ( $gallery_images as $gallery_image ) {
			$return[] = $gallery_image->pid;
		}

		return $return;
	}
}
