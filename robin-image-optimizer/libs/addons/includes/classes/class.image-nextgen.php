<?php
/**
 * NextGEN image model for the optimizer.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с nextgen image
 *
 * @version       1.0
 */
class WRIO_Image_Nextgen {

	/**
	 * Номер картинки в таблице nextgen.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Путь к картинке.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Путь к миниатюре.
	 *
	 * @var string
	 */
	private $thumbnail_path;

	/**
	 * Имя файла.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Имя файла миниатюры.
	 *
	 * @var string
	 */
	private $thumbnail_file;

	/**
	 * УРЛ.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * УРЛ миниатюры.
	 *
	 * @var string
	 */
	private $thumbnail_url;

	/**
	 * Мета данные картинки.
	 *
	 * @var array<string, mixed>|object|null
	 */
	private $meta;

	/**
	 * Мета данные галереи.
	 *
	 * @var array<string, mixed>|object|null
	 */
	private $gallery_meta;

	/**
	 * Данные по оптимизации.
	 *
	 * @var RIO_Process_Queue|null
	 */
	private $optimization_data = null;

	/**
	 * Инициализация картинки из nextgen gallery
	 *
	 * @param int            $image_id   Номер картинки в таблице nextgen.
	 * @param stdClass|false $image_data Метаданные картинки.
	 */
	public function __construct( $image_id, $image_data = false ) {
		global $wpdb;

		$this->id = $image_id;

		if ( $image_data ) {
			$this->meta = $image_data;
		} else {
			$this->meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE pid = " . intval( $image_id ) );
		}

		$this->file           = $this->meta->filename;
		$this->thumbnail_file = 'thumbs-' . $this->meta->filename;

		global $wpdb;
		$this->gallery_meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ngg_gallery WHERE gid = " . intval( $this->meta->galleryid ) );

		// A stored name or folder that PHP refuses (a null byte) gives no path: the image fails as a missing file.
		$valid                = '' !== wrio_file_path( $this->gallery_meta->path . $this->meta->filename );
		$this->path           = $valid ? wp_normalize_path( ABSPATH . trailingslashit( $this->gallery_meta->path ) . $this->meta->filename ) : '';
		$this->thumbnail_path = $valid ? wp_normalize_path( ABSPATH . trailingslashit( $this->gallery_meta->path ) . 'thumbs/' . $this->thumbnail_file ) : '';
		$this->url            = site_url( trailingslashit( $this->gallery_meta->path ) . $this->meta->filename );
		$this->thumbnail_url  = site_url( trailingslashit( $this->gallery_meta->path ) . 'thumbs/' . $this->thumbnail_file );
	}

	/**
	 * Возвращает свойство аттачмента
	 *
	 * @param string $property Имя свойства.
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
	 * Проверка на оптимизацию изображения
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
	 * Whether the image has a backup to restore, as WRIO_Media_Library::has_backup(). An image whose main file failed
	 * is not optimized, and its optimized thumbnail can still be restored.
	 *
	 * @return bool
	 */
	public function has_backup() {
		if ( $this->getOptimizationData()->get_is_backed_up() ) {
			return true;
		}

		foreach ( \WRIO\Storage\Store::rows( 'nextgen', (int) $this->id ) as $row ) {
			if ( 1 === (int) $row['backed_up'] && 'optimized' === $row['format'] && \WRIO\Storage\Store::OK === $row['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Возвращает данные по оптимизации
	 *
	 * @return RIO_Process_Queue
	 */
	public function getOptimizationData() {
		if ( empty( $this->optimization_data ) ) {
			$this->optimization_data = $this->createOptimizationData();
			$this->optimization_data->load();
		}

		return $this->optimization_data;
	}

	/**
	 * Создаёт новый объект RIO_Process_Queue
	 *
	 * @return RIO_Process_Queue
	 */
	public function createOptimizationData() {
		return new RIO_Process_Queue(
			[
				'object_id' => $this->id,
				'item_type' => 'nextgen',
			]
		);
	}

	/**
	 * Optimize the image under its lock.
	 *
	 * The lock covers the backup too: a process that dies during the backup counts a crash, as in the optimizer.
	 *
	 * @param string $optimization_level Level of optimization.
	 *
	 * @return array{errors_count: int, original_size: int, optimized_size: int, optimized_count: int}
	 */
	public function optimize( $optimization_level = '' ) {
		// Null: another process optimizes the image now, and writes its row.
		return \WRIO\Storage\Store::with_lock( 'nextgen', (int) $this->id, fn() => $this->optimize_locked( $optimization_level ) ) ?? [
			'errors_count'    => 0,
			'original_size'   => 0,
			'optimized_size'  => 0,
			'optimized_count' => 0,
		];
	}

	/**
	 * The work of optimize().
	 *
	 * @param string $optimization_level Level of optimization.
	 *
	 * @return array{errors_count: int, original_size: int, optimized_size: int, optimized_count: int}
	 */
	private function optimize_locked( $optimization_level ) {
		// After MAX_CRASHES the optimizer saves the "crashed" failure: skip the backup, which can be what stops PHP.
		$is_image_backuped = \WRIO\Storage\Store::crashes( 'nextgen', (int) $this->id ) >= \WRIO\Storage\Store::MAX_CRASHES ? false : $this->backup();

		if ( is_wp_error( $is_image_backuped ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to backup NextGEN image #%d: %s', $this->id, $is_image_backuped->get_error_message() ) );
			\WRIO\Storage\Store::fail(
				[
					'source'    => 'nextgen',
					'object_id' => (int) $this->id,
				],
				$this->path,
				'backup_failed'
			);

			return [
				'errors_count'    => 1,
				'original_size'   => 0,
				'optimized_size'  => 0,
				'optimized_count' => 0,
			];
		}

		$optimization_level = \WRIO\V2\Optimizer::stored_level( $optimization_level );
		$optimization_data  = $this->createOptimizationData();
		$extra_data         = new WRIO_Nextgen_Extra_Data();

		if ( ! file_exists( $this->path ) ) {
			$summary = [
				'error'          => \WRIO\V2\Client::error( 'missing_file', __( 'File not found', 'robin-image-optimizer' ), [ 'permanent' => true ] ),
				'original_size'  => 0,
				'optimized_size' => 0,
			];
			$extra_data->set_failure( 'file', $summary['error'] );
			\WRIO\Storage\Store::fail(
				[
					'source'    => 'nextgen',
					'object_id' => (int) $this->id,
				],
				$this->path,
				'missing_file'
			);
		} else {
			$original_main_size = wrio_get_file_size( $this->path );
			$summary            = ( new \WRIO\V2\Optimizer() )->optimize_item( 'nextgen', (int) $this->id, $this->get_files(), $optimization_level, [], true === $is_image_backuped );

			// Another process optimizes the image now, and writes its row.
			if ( \WRIO\V2\Optimizer::is_locked( $summary['error'] ) ) {
				return [
					'errors_count'    => 0,
					'original_size'   => 0,
					'optimized_size'  => 0,
					'optimized_count' => 0,
				];
			}

			if ( $summary['error'] ) {
				$extra_data->set_failure( 'optimization', $summary['error'] );
			} else {
				$extra_data->set_original_main_size( $summary['original_main_size'] ?? $original_main_size );
				$extra_data->set_optimized_main_size( wrio_get_file_size( $this->path ) );
				$extra_data->set_image_relative_path( str_replace( wp_normalize_path( untrailingslashit( ABSPATH ) ), '', $this->path ) );
			}
		}

		$mime_type = $summary['error'] ? '' : (string) wp_get_image_mime( $this->path );

		$optimization_data->configure(
			[
				'result_status'      => $summary['error'] ? RIO_Process_Queue::STATUS_ERROR : RIO_Process_Queue::STATUS_SUCCESS,
				'processing_level'   => $optimization_level,
				'is_backed_up'       => $is_image_backuped,
				'original_size'      => $summary['error'] ? 0 : $summary['original_size'],
				'final_size'         => $summary['error'] ? 0 : $summary['optimized_size'],
				'original_mime_type' => $mime_type,
				'final_mime_type'    => $mime_type,
				'extra_data'         => $extra_data,
			]
		);
		$optimization_data->save();

		return [
			'errors_count'    => $summary['error'] ? 1 : 0,
			'original_size'   => $summary['error'] ? 0 : $summary['original_size'],
			'optimized_size'  => $summary['error'] ? 0 : $summary['optimized_size'],
			'optimized_count' => $summary['error'] ? 0 : 1,
		];
	}

	/**
	 * The main file and the thumbnail, in the format of WRIO\V2\Optimizer::run().
	 *
	 * @return array<string, array{path: string, url: string, optimize: bool}>
	 */
	public function get_files() {
		$files = [
			'original' => [
				'path'     => $this->path,
				'url'      => $this->url,
				'optimize' => true,
			],
		];

		if ( is_file( $this->thumbnail_path ) ) {
			$files['thumbnail'] = [
				'path'     => $this->thumbnail_path,
				'url'      => $this->thumbnail_url,
				'optimize' => true,
			];
		}

		return $files;
	}

	/**
	 * Делает резервную копию изображения.
	 *
	 * @return bool|WP_Error
	 */
	public function backup() {
		$backup = WRIOP_Backup::get_instance();

		return $backup->backupNextgen( $this );
	}

	/**
	 * Deletes the WebP and AVIF files and rows of the image and marks its files as restored.
	 *
	 * @return void
	 */
	public function forget_conversions() {
		$files = $this->get_files();

		\WRIO\V2\Format_Rows::delete( $files );
		\WRIO\Storage\Store::restored( 'nextgen', (int) $this->id, $files );
	}

	/**
	 * Восстанавливает из резервной копии.
	 *
	 * @return true|WP_Error
	 */
	public function restore() {

		$backup   = WRIOP_Backup::get_instance();
		$restored = $backup->restoreNextgen( $this );

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		$this->forget_conversions();
		$this->update_nextgen_dimensions();

		global $wpdb;

		$io_db_table = RIO_Process_Queue::table_name();

		$wpdb->delete(
			$io_db_table,
			[
				'object_id' => $this->id,
				'item_type' => 'nextgen',
			]
		);

		/**
		 * Хук срабатывает после восстановления nextgen image
		 *
		 * @param RIO_Process_Queue|null $optimization_data
		 *
		 * @since 1.2.0
		 */
		do_action( 'wbcr/rio/nextgen_image_restored', $this->optimization_data );

		return true;
	}

	/**
	 * Writes the dimensions of the files into the NextGEN metadata: a restored file can have other dimensions than
	 * the metadata says, for example after a resize in NextGEN.
	 *
	 * @return void
	 */
	private function update_nextgen_dimensions() {
		// ponytail: NextGEN 3.50+ only, its mapper keeps the metadata format of NextGEN.
		if ( ! class_exists( '\Imagely\NGG\DataMappers\Image' ) ) {
			return;
		}

		$mapper = \Imagely\NGG\DataMappers\Image::get_instance();
		$image  = $mapper->find( (int) $this->id );

		if ( ! $image || ! is_array( $image->meta_data ) ) {
			return;
		}

		foreach ( [
			'full'      => $this->path,
			'thumbnail' => $this->thumbnail_path,
		] as $size => $path ) {
			$dimensions = is_file( $path ) ? wp_getimagesize( $path ) : false;

			if ( ! $dimensions ) {
				continue;
			}

			if ( ! is_array( $image->meta_data[ $size ] ?? null ) ) {
				$image->meta_data[ $size ] = [];
			}

			$image->meta_data[ $size ]['width']  = $dimensions[0];
			$image->meta_data[ $size ]['height'] = $dimensions[1];

			if ( 'full' === $size ) {
				$image->meta_data['width']  = $dimensions[0];
				$image->meta_data['height'] = $dimensions[1];
			}
		}

		$mapper->save( $image );
	}
}
