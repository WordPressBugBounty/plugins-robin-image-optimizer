<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с WordPress attachment.
 *
 * @version       1.0
 */
class WIO_Attachment {

	/**
	 * @var int
	 */
	private $id;


	/**
	 * @var array meta-данные
	 */
	private $attachment_meta;

	/**
	 * @var array массив с данными о папке uploads
	 */
	private $wp_upload_dir;

	/**
	 * @var string
	 */
	private $url;

	/**
	 * @var string
	 */
	private $path;

	/**
	 * @var RIO_Process_Queue
	 */
	private $optimization_data;

	/**
	 * Инициализация аттачмента
	 *
	 * @param int         $attachment_id   Номер аттачмента из медиабиблиотеки
	 * @param array|false $attachment_meta метаданные аттачмента. Ключи массива аналогичны функции wp_get_attachment_metadata
	 */
	public function __construct( $attachment_id, $attachment_meta = false ) {
		$this->id              = $attachment_id;
		$this->wp_upload_dir   = wp_upload_dir();
		$this->attachment_meta = $attachment_meta;

		if ( ! $attachment_meta ) {
			// some meta can be missing due to: https://wordpress.stackexchange.com/q/330174/149161
			// False when the attachment has no metadata: keep the empty array of the property type.
			$meta                  = wp_get_attachment_metadata( $this->id );
			$this->attachment_meta = is_array( $meta ) ? $meta : [];
		}

		$this->set_paths();
	}

	/**
	 * @return bool
	 * @since  1.3.9
	 */
	public function isset_attachment_meta() {
		return $this->attachment_meta && isset( $this->attachment_meta['file'] );
	}

	/**
	 * @since  1.3.9
	 */
	public function set_paths() {
		if ( ! $this->isset_attachment_meta() ) {
			return;
		}

		$this->url  = trailingslashit( $this->wp_upload_dir['baseurl'] ) . $this->attachment_meta['file'];
		$this->path = wp_normalize_path( trailingslashit( $this->wp_upload_dir['basedir'] ) . $this->attachment_meta['file'] );
	}

	/**
	 * Актуализирует мета данные аттачмента и загружает актуальные мета данные и данные по оптимизации из базы.
	 */
	public function reload( $attachment_meta = [] ) {
		if ( empty( $attachment_meta ) ) {
			$attachment_meta = wp_get_attachment_metadata( $this->id );
		}
		$this->attachment_meta   = $attachment_meta;
		$this->optimization_data = new RIO_Process_Queue(
			[
				'object_id'   => $this->id,
				'object_name' => '',
				'item_type'   => 'attachment',
			]
		);
		$this->optimization_data->load();
		$this->set_paths();
	}

	/**
	 * Fallback to get attachment meta it can be empty when WordPress failed to create it or invocation
	 * of method was produced too soon.
	 *
	 * @return bool
	 * @since  1.3.9
	 */
	public function regenerate_metadata() {
		if ( $this->isset_attachment_meta() ) {
			return true;
		}

		WRIO_Plugin::app()->logger->info( sprintf( 'Try regenerate metadata for attachment #%d', $this->id ) );

		if ( ! wrio_load_media_functions() ) {
			WRIO_Plugin::app()->logger->error( 'Failed regenerate attachment meta data. WordPress media functions are unavailable.' );

			return false;
		}

		// Need to remove this filter, as it would start recursion
		remove_filter( 'wp_generate_attachment_metadata', 'WRIO_Media_Library::optimize_after_upload' );

		$file_path = get_attached_file( $this->id );

		if ( empty( $file_path ) ) {
			$attachment = get_post( $this->id );

			if ( empty( $attachment ) || 'attachment' !== $attachment->post_type ) {
				return false;
			}

			$file_path = wrio_url_to_abs_path( $attachment->guid );
		}

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Failed regenerate attachment meta data. Attachment file (%s) doesn\'t exists!', $file_path ) );

			return false;
		}

		$attachment_meta = wp_generate_attachment_metadata( $this->id, $file_path );

		if ( empty( $attachment_meta ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed regenerate meta data for attachment file (%s).', $file_path ) );

			return false;
		}

		WRIO_Plugin::app()->logger->debug( sprintf( 'Generated metadata: %s', var_export( $attachment_meta, true ) ) );

		wp_update_attachment_metadata( $this->id, $attachment_meta );

		$this->reload( $attachment_meta );

		add_filter( 'wp_generate_attachment_metadata', 'WRIO_Media_Library::optimize_after_upload', 10, 3 );

		WRIO_Plugin::app()->logger->info( sprintf( 'Finish regenerate metadata for attachment #%d!', $this->id ) );

		return true;
	}

	/**
	 * Добавляем сообщение в лог файл.
	 *
	 * @param string $message Текст сообщения об ошибке.
	 */
	public function writeLog( $message ) {

		$char = "\t-> ";
		$nl   = PHP_EOL;

		$error  = sprintf( 'Error to optimize attachment (ID: #%s). Message: "%s"', $this->id, trim( $message ) ) . $nl;
		$error .= $char . sprintf( 'Attachment optimized? %s', ( $this->isOptimized() ? 'Yes' : 'No' ) ) . $nl;
		$error .= $char . sprintf( 'Should be resized? %s', ( $this->needs_resize() ? 'Yes' : 'No' ) ) . $nl;
		$error .= $char . sprintf( 'Original size: %sx%s', $this->attachment_meta['width'], $this->attachment_meta['height'] ) . $nl;
		$error .= $char . sprintf( 'Relative path: %s', $this->attachment_meta['file'] ) . $nl;
		$error .= $char . sprintf( 'Server used: %s', wrio_is_license_activate() ? 'premium' : 'free' ) . $nl;

		if ( ! empty( $this->attachment_meta['sizes'] ) ) {
			$error .= $char . ' Additional sizes:' . $nl;
			foreach ( $this->attachment_meta['sizes'] as $size_type => $size_info ) {
				$error .= "\t" . $char . sprintf( 'Type: %s, size: %sx%s, MIME type: %s', $size_type, $size_info['width'], $size_info['height'], $size_info['mime-type'] ) . $nl;
			}
		}

		WRIO_Plugin::app()->logger->error( $error );
	}

	/**
	 * Возвращает объект с информацией об оптимизации
	 *
	 * @return RIO_Process_Queue
	 */
	public function getOptimizationData() {
		$this->optimization_data = new RIO_Process_Queue(
			[
				'object_id'   => $this->id,
				'object_name' => '',
				'item_type'   => 'attachment',
			]
		);
		$this->optimization_data->load();

		return $this->optimization_data;
	}

	/**
	 * Возвращает объект с информацией о конвертации в указанный формат
	 *
	 * @param string $format Format to get conversion data for ('webp' or 'avif')
	 *
	 * @return RIO_Process_Queue
	 */
	public function getConversionData( $format = 'webp' ) {
		$optimization_data = new RIO_Process_Queue(
			[
				'object_id'   => $this->id,
				'object_name' => '',
				'item_type'   => $format,
			]
		);
		$optimization_data->load();

		return $optimization_data;
	}

	/**
	 * Enabled formats that the attachment has no row for yet.
	 *
	 * @return string[]
	 */
	public function get_missing_formats() {
		return array_values(
			array_filter(
				WRIO_Formats::get_enabled_formats(),
				function ( $format ) {
					return ! $this->getConversionData( $format )->get_id();
				}
			)
		);
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
	 * Optimize the attachment: the main file and the allowed sizes. Also convert every size to the enabled formats.
	 *
	 * One v2 request per file returns all its outputs, and the requests run in parallel.
	 *
	 * @param string $optimization_level Level: normal, aggresive, ultra, googlepage or custom. Empty uses the settings.
	 *
	 * @return array{original_size: int, optimized_size: int}
	 */
	public function optimize( $optimization_level = '' ) {
		$optimize_results   = [
			'original_size'  => 0,
			'optimized_size' => 0,
		];
		$optimization_level = \WRIO\V2\Optimizer::stored_level( $optimization_level );

		// The browser still sends the sizes: the finalize request schedules the job.
		if ( WRIO_Media_Library::waits_for_sizes( (int) $this->id ) ) {
			return $optimize_results;
		}

		// The lock covers the backup and the resize too: the resize updates the metadata, and the metadata hooks
		// must not take the plugin's own changes for an edit. Another process optimizes the image now.
		if ( ! \WRIO\Storage\Store::lock( 'attachment', (int) $this->id ) ) {
			return $optimize_results;
		}

		$optimization_data = $this->getOptimizationData();

		try {
			// Before any work: the metadata regeneration, the backup or the resize can be what stops PHP.
			if ( \WRIO\Storage\Store::crashes( 'attachment', (int) $this->id ) >= \WRIO\Storage\Store::MAX_CRASHES ) {
				$item = [
					'source'    => 'attachment',
					'object_id' => (int) $this->id,
				];
				$this->save_failure( $optimization_level, 'optimization', \WRIO\V2\Optimizer::crashed( $item, (string) get_attached_file( (int) $this->id ) ) );

				return $optimize_results;
			}

			// The path may be empty because no metadata has been created for the image.
			// We should try to create image metadata again.
			if ( ! $this->isset_attachment_meta() ) {
				WRIO_Plugin::app()->logger->warning( sprintf( 'Attachment #%d doesn\'t have metadata.', $this->id ) );

				$this->regenerate_metadata();
			}

			if ( empty( $this->path ) || ! file_exists( $this->path ) ) {
				$error_message = __( 'Attachment cannot be optimized.', 'robin-image-optimizer' ) . ' ' . ( empty( $this->path )
					// translators: %d is the attachment ID
					? sprintf( __( 'Attachment #%d doesn\'t have metadata, the image may be damaged.', 'robin-image-optimizer' ), $this->id )
					// translators: %s is the file path
					: sprintf( __( 'File "(%s)" doesn\'t exist', 'robin-image-optimizer' ), $this->path ) );

				$this->save_failure( $optimization_level, 'path', $error_message );

				return $optimize_results;
			}

			$is_image_backuped = $this->backup();

			if ( is_wp_error( $is_image_backuped ) ) {
				$this->writeLog( $is_image_backuped->get_error_message() );
				$this->save_failure( $optimization_level, 'backup', $is_image_backuped->get_error_message() );

				return $optimize_results;
			}

			// Record the backup before the resize changes the file. A run that stops before any state row (a fatal
			// error, a rate limit) leaves this record: the retry keeps the backup (see Store::keeps_backup()).
			if ( true === $is_image_backuped && ! $optimization_data->get_is_backed_up() ) {
				$new_row = $optimization_data->get_id() ? [] : [
					'result_status'      => RIO_Process_Queue::STATUS_UNOPTIMIZED,
					'processing_level'   => $optimization_level,
					'original_size'      => 0,
					'final_size'         => 0,
					'original_mime_type' => '',
					'final_mime_type'    => '',
				];
				$optimization_data->configure( array_merge( $new_row, [ 'is_backed_up' => true ] ) );

				if ( ! $optimization_data->save() ) {
					$this->save_failure( $optimization_level, 'backup', 'Failed to record the backup' );

					return $optimize_results;
				}
			}

			$original_main_size = $this->get_file_size( $this->path );
			// After a process died on this image, the retry skips the resize and the formats (see Store::crashes()).
			$crashed = \WRIO\Storage\Store::crashes( 'attachment', (int) $this->id ) > 0;

			// A damaged image is not resized: the editor would save a whole new image, and the optimizer could not
			// see the damage any more.
			if ( ! $crashed && $this->needs_resize() && \WRIO\V2\Client::is_intact( (string) $this->path ) ) {
				$this->resize();
			}

			$summary = ( new \WRIO\V2\Optimizer() )->optimize_item(
				'attachment',
				(int) $this->id,
				$this->get_files( ! $crashed && ! empty( WRIO_Formats::get_enabled_formats() ) ),
				$optimization_level,
				[ 'original' => $original_main_size ],
				true === $is_image_backuped
			);

			// Another process owns the item now: its result stays.
			if ( \WRIO\V2\Optimizer::is_locked( $summary['error'] ) ) {
				return $optimize_results;
			}

			if ( $summary['error'] ) {
				$this->writeLog( $summary['error']->get_error_message() );
				$this->save_failure( $optimization_level, 'optimization', $summary['error'], [ 'is_backed_up' => $is_image_backuped ] );

				return $optimize_results;
			}

			$mime_type = (string) wp_get_image_mime( $this->path );

			$optimization_data->configure(
				[
					'result_status'      => RIO_Process_Queue::STATUS_SUCCESS,
					'processing_level'   => $optimization_level,
					'is_backed_up'       => $is_image_backuped,
					'original_size'      => $summary['original_size'],
					'final_size'         => $summary['optimized_size'],
					'original_mime_type' => $mime_type,
					'final_mime_type'    => $mime_type,
					'extra_data'         => new RIO_Attachment_Extra_Data(
						[
							'thumbnails_count'   => $summary['optimized_files'] - 1,
							// The file is already compressed when the queue row is rebuilt from the state rows.
							'original_main_size' => $summary['original_main_size'] ?? $original_main_size,
						]
					),
				]
			);
			$optimization_data->save();

			return [
				'original_size'  => $summary['original_size'],
				'optimized_size' => $summary['optimized_size'],
			];
		} catch ( Throwable $throwable ) {
			$this->mark_and_log_failure( $throwable, 'optimization', $optimization_level );
		} finally {
			\WRIO\Storage\Store::unlock( 'attachment', (int) $this->id );
			WRIO_Cron::schedule_retry( (int) $this->id );
		}

		return $optimize_results;
	}

	/**
	 * Save an error row for the attachment.
	 *
	 * @param string|int                     $processing_level Stored level.
	 * @param 'path'|'backup'|'optimization' $kind What failed: path, backup, optimization.
	 * @param WP_Error|string                $error            The error, or its message.
	 * @param array<string, mixed>           $attributes       More row attributes.
	 *
	 * @return void
	 */
	private function save_failure( $processing_level, $kind, $error, array $attributes = [] ) {
		$extra_data = new RIO_Attachment_Extra_Data();
		$extra_data->set_failure( $kind, $error );

		$optimization_data = $this->getOptimizationData();
		$optimization_data->configure(
			array_merge(
				[
					'result_status'      => RIO_Process_Queue::STATUS_ERROR,
					'processing_level'   => $processing_level,
					'original_size'      => 0,
					'final_size'         => 0,
					'original_mime_type' => '',
					'final_mime_type'    => '',
					'extra_data'         => $extra_data,
				],
				$attributes
			)
		);
		$optimization_data->save();

		if ( 'optimization' === $kind ) {
			$code = $error instanceof WP_Error ? \WRIO\Storage\Store::error_outcome( $error )['code'] : '';
		} else {
			$code = 'path' === $kind ? ( empty( $this->path ) ? 'no_metadata' : 'missing_file' ) : $kind . '_failed';
			// The optimizer saved the state rows of an optimization failure. Other failures happen before it.
			\WRIO\Storage\Store::fail( $this->state_item(), (string) $this->path, $code );
		}

		WRIO_Plugin::app()->logger->error( sprintf( 'Attachment #%d (%s) not optimized, %s failed: %s. What to do: %s', $this->id, $this->path, $kind, rtrim( (string) $extra_data->get_error_msg(), '.' ), wrio_error_hint( 'failed', $code ) ) );
	}

	/**
	 * Convert every size of the attachment to these formats, without optimizing it.
	 *
	 * @param array<'webp'|'avif'> $formats webp, avif.
	 *
	 * @return void
	 */
	public function convert( array $formats ) {
		if ( empty( $this->path ) || empty( $formats ) ) {
			return;
		}

		$files = $this->get_files();

		// Without its main file, the formats of the item are saved as missing (Store::plan()): a bulk run does not
		// select it again. The other sizes are not converted.
		if ( ! file_exists( $this->path ) ) {
			$files = array_intersect_key( $files, [ 'original' => true ] );
		}

		( new \WRIO\V2\Optimizer() )->convert_item( 'attachment', (int) $this->id, $files, $formats );
	}

	/**
	 * Files of the attachment by size name: the main file ("original") and its sizes.
	 *
	 * The main file and the allowed sizes are optimized. With $all_sizes, the other sizes are
	 * returned too, to be converted only. Sizes that share a file are returned once.
	 *
	 * @param bool $all_sizes Also return the sizes that are not optimized.
	 * @param bool $existing  Only files that are on disk. False also returns the sizes whose file is gone, for
	 *                        example to delete their converted files.
	 *
	 * @return array<string, array{path: string, url: string, optimize: bool, object_id: int}>
	 */
	public function get_files( $all_sizes = true, $existing = true ) {
		if ( empty( $this->path ) ) {
			return [];
		}

		$allowed = (array) $this->getAllowedSizes();
		// The first size of each file, and whether any size of the file is optimized.
		$sizes    = [ $this->path => 'original' ];
		$optimize = [ 'original' => true ];

		foreach ( array_keys( (array) ( $this->attachment_meta['sizes'] ?? [] ) ) as $size ) {
			$size      = (string) $size;
			$path      = (string) $this->getImageSizePath( $size );
			$optimized = in_array( $size, $allowed, true );

			if ( '' === $path || ( ! $optimized && ! $all_sizes ) || ( $existing && ! is_file( $path ) ) || ! wrio_should_optimize( (int) $this->id, $size ) ) {
				continue;
			}

			$first              = $sizes[ $path ] ?? $size;
			$sizes[ $path ]     = $first;
			$optimize[ $first ] = ( $optimize[ $first ] ?? false ) || $optimized;
		}

		$files = [];

		foreach ( $sizes as $path => $size ) {
			$files[ $size ] = [
				'path'      => (string) $path,
				'url'       => (string) WRIO_Url::normalize( 'original' === $size ? $this->url : (string) $this->getImageSizeUrl( $size ) ),
				'optimize'  => $optimize[ $size ],
				'object_id' => (int) $this->id,
			];
		}

		return $files;
	}

	/**
	 * Log and persist unexpected optimization failures.
	 *
	 * @param Throwable  $throwable          Exception or error that was thrown.
	 * @param string     $context            Processing context.
	 * @param string|int $processing_level   Current processing level.
	 *
	 * @return void
	 */
	public function mark_and_log_failure( $throwable, $context = 'optimization', $processing_level = '' ) {
		$error_message = sprintf(
			'Unexpected %1$s failure for attachment #%2$d: %3$s in %4$s:%5$d',
			$context,
			$this->id,
			$throwable->getMessage(),
			$throwable->getFile(),
			$throwable->getLine()
		);

		WRIO_Plugin::app()->logger->error( $error_message );
		\WRIO\Storage\Store::fail( $this->state_item(), (string) $this->path, 'unexpected_error' );

		$optimization_data = $this->getOptimizationData();
		$optimization_data->mark_as_error(
			$error_message,
			[
				'processing_level'   => $processing_level,
				'original_size'      => 0,
				'final_size'         => 0,
				'original_mime_type' => '',
				'final_mime_type'    => '',
			]
		);
	}

	/**
	 * Log and persist unexpected format conversion failures without rewriting attachment optimization state.
	 *
	 * @param Throwable $throwable Exception or error that was thrown.
	 * @param string    $format    Target format.
	 * @param string    $context   Processing context.
	 *
	 * @return void
	 */
	public function mark_conversion_failure( $throwable, $format, $context = 'conversion' ) {
		$error_message = sprintf(
			'Unexpected %1$s failure for attachment #%2$d (%3$s): %4$s in %5$s:%6$d',
			$context,
			$this->id,
			$format,
			$throwable->getMessage(),
			$throwable->getFile(),
			$throwable->getLine()
		);

		WRIO_Plugin::app()->logger->error( $error_message );

		$conversion_data = $this->getConversionData( $format );
		if ( ! $conversion_data->get_id() ) {
			return;
		}

		$conversion_data->mark_as_error( $error_message );
	}

	/**
	 * Метод проверяет, оптимизирован ли аттачмент
	 *
	 * @return bool
	 */
	public function isOptimized() {
		$optimization_data = $this->getOptimizationData();
		if ( $optimization_data->is_optimized() ) {
			return true;
		}

		return false;
	}

	/**
	 * Возвращает все размеры аттачмента, которые нужно оптимизировать
	 *
	 * @return array|false
	 */
	public function getAllowedSizes() {
		$allowed_sizes = WRIO_Plugin::app()->getPopulateOption( 'allowed_sizes_thumbnail', 'thumbnail,medium' );

		if ( ! $allowed_sizes ) {
			return false;
		}

		$allowed_sizes = explode( ',', $allowed_sizes );

		return $allowed_sizes;
	}

	/**
	 * Возвращает свойство аттачмента
	 *
	 * @param string $property имя свойства
	 *
	 * @return mixed
	 */
	public function get( $property ) {
		if ( isset( $this->$property ) ) {
			return $this->$property;
		}

		return false;
	}

	/**
	 * Возвращает URL изображения по указанному размеру
	 *
	 * @param string $size - размер изображения(thumbnail,medium,large...)
	 *
	 * @return string|null
	 */
	public function getImageSizeUrl( $size = 'thumbnail' ) {
		if ( ! isset( $this->attachment_meta['sizes'][ $size ] ) ) {
			return null;
		}

		$file = $this->attachment_meta['sizes'][ $size ]['file'];
		$url  = str_replace( wp_basename( $this->url ), $file, $this->url );

		return $url;
	}

	/**
	 * Возвращает путь к изображению по указанному размеру.
	 *
	 * @param string $size Размер изображения (thumbnail, medium, large ...)
	 *
	 * @return string Путь до изображения.
	 */
	public function getImageSizePath( $size = 'thumbnail' ) {
		if ( ! isset( $this->attachment_meta['sizes'][ $size ] ) ) {
			return null;
		}

		$file = $this->attachment_meta['sizes'][ $size ]['file'];
		$path = str_replace( wp_basename( $this->path ), $file, $this->path );

		return $path;
	}

	/**
	 * Проверка необходимости делать изменение размера.
	 *
	 * @return bool
	 */
	protected function needs_resize() {
		$resize_large_images = WRIO_Plugin::app()->getPopulateOption( 'resize_larger', false );

		if ( ! $resize_large_images || WRIO_Media_Library::keeps_dimensions( (int) $this->id ) ) {
			return false;
		}

		$resize_larger_w = (int) WRIO_Plugin::app()->getPopulateOption( 'resize_larger_w', 1600 );
		$resize_larger_h = (int) WRIO_Plugin::app()->getPopulateOption( 'resize_larger_h', 1600 );

		if ( ! $resize_larger_w && ! $resize_larger_h ) {
			return false;
		}

		// если ширина и высота установлены и > 0
		if ( $this->attachment_meta['width'] >= $this->attachment_meta['height'] ) {
			$larger_side        = $this->attachment_meta['width'];
			$resize_larger_side = $resize_larger_w;
		} else {
			$larger_side        = $this->attachment_meta['height'];
			$resize_larger_side = $resize_larger_h;
		}
		// если ширина 0, то рисайзим по высоте
		if ( ! $resize_larger_w ) {
			$resize_larger_side = $resize_larger_h;
			$larger_side        = $this->attachment_meta['height'];
		}
		// если высота 0, то рисайзим по ширине
		if ( ! $resize_larger_h ) {
			$resize_larger_side = $resize_larger_w;
			$larger_side        = $this->attachment_meta['width'];
		}
		// если большая сторона картинки меньше, чем задано в настройках, то не рисайзим.
		if ( $larger_side <= $resize_larger_side ) {
			return false;
		}

		return true;
	}

	/**
	 * Возвращает метаданные аттачмента
	 *
	 * @return array
	 */
	public function getMetaData() {
		return $this->attachment_meta;
	}

	/**
	 * Изменяет размер изображения до заданного в настройках размера.
	 *
	 * @return bool
	 */
	protected function resize() {
		$resize_larger_h = (int) WRIO_Plugin::app()->getPopulateOption( 'resize_larger_h', 1600 );
		$resize_larger_w = (int) WRIO_Plugin::app()->getPopulateOption( 'resize_larger_w', 1600 );

		$image = wp_get_image_editor( $this->path );

		if ( is_wp_error( $image ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to get image edit via wp_get_image_editor(), error: "%s"', $image->get_error_message() ) );

			return false;
		}

		$current_size = $image->get_size();
		$new_width    = 0;
		$new_height   = 0;

		// если обе стороны заданы
		if ( $resize_larger_h && $resize_larger_w ) {
			// определяем большую сторону и по ней маштабируем
			if ( $current_size['width'] >= $current_size['height'] ) {
				$new_width  = $resize_larger_w;
				$new_height = round( $current_size['height'] * $new_width / $current_size['width'] );
			} else {
				$new_height = $resize_larger_h;
				$new_width  = round( $current_size['width'] * $new_height / $current_size['height'] );
			}
		} else {
			// если задана одна из сторон
			if ( ! $resize_larger_w ) {
				// если ширина 0, то рисайзим по высоте
				$new_height = $resize_larger_h;
				$new_width  = round( $current_size['width'] * $new_height / $current_size['height'] );
			}
			if ( ! $resize_larger_h ) {
				// если высота 0, то рисайзим по ширине
				$new_width  = $resize_larger_w;
				$new_height = round( $current_size['height'] * $new_width / $current_size['width'] );
			}
		}

		$nl           = PHP_EOL;
		$log_message  = sprintf( "\tResize from: %sx%s to %sx%s", $current_size['width'], $current_size['height'], $new_width, $new_height ) . $nl;
		$log_message .= sprintf( "\tLarger resize from %sx%s", $resize_larger_w, $resize_larger_h ) . $nl;
		$log_message .= sprintf( "\tAbsolute path: %s", $this->path ) . $nl;

		$resize_result = $image->resize( $new_width, $new_height, false );

		if ( is_wp_error( $resize_result ) ) {
			$this->writeLog( sprintf( 'Resize error: %s. Details: %s', $resize_result->get_error_messages(), $log_message ) );

			return false;
		}

		$save_result = $image->save( $this->path );

		if ( is_wp_error( $save_result ) ) {
			$this->writeLog( sprintf( 'Failed to save resized error in db: %s, Details: %s', $save_result->get_error_messages(), $log_message ) );

			return false;
		}

		$this->attachment_meta['width']      = $new_width;
		$this->attachment_meta['height']     = $new_height;
		$this->attachment_meta['old_width']  = $current_size['width'];
		$this->attachment_meta['old_height'] = $current_size['height'];

		wp_update_attachment_metadata( $this->id, $this->attachment_meta );

		return true;
	}

	/**
	 * Делает резервную копию
	 *
	 * @return bool|WP_Error False when backups are off.
	 */
	protected function backup() {
		$backup = WIO_Backup::get_instance();

		return $backup->backupAttachment( $this );
	}

	/**
	 * Восстанавливает файлы из резервной копии
	 *
	 * @return bool|WP_Error The error "wrio_busy" when another process optimizes the image now.
	 */
	public function restore() {
		// The restore updates the metadata between the main file and the sizes: under the lock, the metadata
		// hooks do not take it for a replaced file (which would delete the backups of the sizes).
		if ( ! \WRIO\Storage\Store::lock( 'attachment', (int) $this->id ) ) {
			// Another process optimizes the image now. Restoring at the same time would mix the files of both.
			return new WP_Error( 'wrio_busy', __( 'The image is being optimized. Try again in a few minutes.', 'robin-image-optimizer' ) );
		}

		try {
			$backup   = WIO_Backup::get_instance();
			$restored = $backup->restoreAttachment( $this );

			// The converted files belong to the optimized files. A new optimization converts again.
			if ( ! is_wp_error( $restored ) ) {
				\WRIO\V2\Format_Rows::delete( $this->get_files() );
				\WRIO\Storage\Store::restored( 'attachment', (int) $this->id, $this->get_files() );
			}
		} finally {
			\WRIO\Storage\Store::unlock( 'attachment', (int) $this->id );
		}

		return $restored;
	}

	/**
	 * The attachment as an item of the state rows.
	 *
	 * @return array{source: 'attachment', object_id: int}
	 */
	private function state_item() {
		return [
			'source'    => 'attachment',
			'object_id' => (int) $this->id,
		];
	}
}
