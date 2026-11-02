<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с custom folder image.
 *
 * @version       1.0
 */
class WRIO_Folder_Image {

	/**
	 * @var int номер картинки в таблице
	 */
	private $id;

	/**
	 * @var string путь к картинке относительно папки
	 */
	private $path;

	/**
	 * @var string УРЛ
	 */
	private $url;

	/**
	 * @var string уникальный идентификатор директории
	 */
	private $folder_uid;

	/**
	 * @var RIO_Process_Queue данные по оптимизации
	 */
	private $optimization_data;

	/**
	 * Инициализация картинки из custom folder
	 *
	 * @param int                                    $image_id   номер картинки в таблице folders.
	 * @param array|false|RIO_Process_Queue|stdClass $image_data метаданные картинки, or its queue row.
	 */
	public function __construct( $image_id, $image_data = false ) {
		$this->id = $image_id;

		if ( $image_data instanceof RIO_Process_Queue ) {
			$this->optimization_data = $image_data;
		} else {
			$this->optimization_data = $this->createOptimizationData();

			if ( $image_data ) {
				$this->optimization_data->configure( (array) $image_data );
			} else {
				$this->loadOptimizationData();
			}
		}

		/**
		 * Extra data of the queue row, null when the row was deleted.
		 *
		 * @var WRIO_CF_Image_Extra_Data|null $extra_data
		 */
		$extra_data = $this->optimization_data->get_extra_data();
		// A deleted queue row has no extra data, so the image has an empty path. WRIO_Custom_Folders::optimizeImage() skips it.
		$file_path = $extra_data instanceof WRIO_CF_Image_Extra_Data ? (string) $extra_data->get_file_path() : '';
		// The same root as real_path_to_relative() in class.folder.php.
		$base_path        = WRIO_Custom_Folders::get_storage_root();
		$this->path       = '' === $file_path ? '' : wp_normalize_path( untrailingslashit( $base_path ) . $file_path );
		$this->url        = '' === $file_path ? '' : home_url( wp_normalize_path( $file_path ) );
		$this->folder_uid = $this->optimization_data->get_item_hash_alternative();
	}

	/**
	 * Возвращает свойство аттачмента
	 *
	 * @param string $property   имя свойства
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
				'id'        => $this->id,
				'item_type' => 'cf_image',
			]
		);
	}


	protected function loadOptimizationData() {
		global $wpdb;

		if ( empty( $this->optimization_data ) ) {
			$this->optimization_data = $this->createOptimizationData();
		}

		$table_name = RIO_Process_Queue::table_name();
		$sql        = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
			"SELECT * FROM {$table_name} WHERE id = %d AND item_type = %s LIMIT 1;",
			[
				$this->id,
				'cf_image',
			]
		);

		$row = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( ! empty( $row ) ) {
			$this->optimization_data->configure( $row );
		}
	}

	/**
	 * Проверка на оптимизацию изображения
	 *
	 * @return bool
	 */
	public function isOptimized() {
		$optimization_data = $this->getOptimizationData();
		if ( empty( $optimization_data ) ) {
			return false;
		}
		if ( $optimization_data->is_optimized() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether file exists or not.
	 *
	 * @return bool
	 */
	public function isFileExists() {
		if ( file_exists( $this->path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Optimize the image under its lock.
	 *
	 * The lock covers the backup too: a process that dies during the backup counts a crash, as in the optimizer.
	 *
	 * @param string $optimization_level Level of optimization.
	 *
	 * @return array<string, int>
	 */
	public function optimize( $optimization_level = '' ) {
		// Null: another process optimizes the image now, and writes its row.
		return \WRIO\Storage\Store::with_lock( 'cf_image', (int) $this->id, fn() => $this->optimize_locked( $optimization_level ) ) ?? [
			'errors_count'    => 0,
			'original_size'   => 0,
			'optimized_size'  => 0,
			'optimized_count' => 0,
		];
	}

	/**
	 * Save a failure that stops the optimization in the queue row: the column shows it, not an older reason.
	 *
	 * @param WP_Error $error The failure.
	 *
	 * @return void
	 */
	private function save_failure( WP_Error $error ) {
		$extra_data = $this->getOptimizationData()->get_extra_data();
		$extra_data = $extra_data instanceof WRIO_CF_Image_Extra_Data ? $extra_data : new WRIO_CF_Image_Extra_Data();
		$extra_data->set_failure( 'optimization', $error );
		$this->getOptimizationData()->configure(
			[
				'result_status' => RIO_Process_Queue::STATUS_ERROR,
				'extra_data'    => $extra_data,
			]
		);
		$this->getOptimizationData()->save();
	}

	/**
	 * The work of optimize().
	 *
	 * @param string $optimization_level Level of optimization.
	 *
	 * @return array<string, int>
	 */
	private function optimize_locked( $optimization_level ) {
		// A missing file goes on to the optimizer, which saves it as missing_file.
		$path = WRIO_Custom_Folders::validate_path( $this->path, true, $this->get_root() );

		if ( is_wp_error( $path ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Skipping optimization of custom folder image %s: %s', $this->path, $path->get_error_message() ) );
			\WRIO\Storage\Store::fail(
				[
					'source'    => 'cf_image',
					'object_id' => (int) $this->id,
				],
				$this->path,
				'path_denied'
			);

			$this->save_failure( $path );

			return [
				'errors_count'    => 1,
				'original_size'   => 0,
				'optimized_size'  => 0,
				'optimized_count' => 0,
			];
		}

		// After MAX_CRASHES the optimizer saves the "crashed" failure: skip the backup, which can be what stops PHP.
		$is_image_backuped = \WRIO\Storage\Store::crashes( 'cf_image', (int) $this->id ) >= \WRIO\Storage\Store::MAX_CRASHES ? false : $this->backup();

		if ( is_wp_error( $is_image_backuped ) ) {

			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to backup with message: %s. Skipping optimization of custom folder', $is_image_backuped->get_error_message() ) );
			\WRIO\Storage\Store::fail(
				[
					'source'    => 'cf_image',
					'object_id' => (int) $this->id,
				],
				$this->path,
				'backup_failed'
			);
			$this->save_failure( $is_image_backuped );

			return [
				'errors_count'    => 1,
				'original_size'   => 0,
				'optimized_size'  => 0,
				'optimized_count' => 0,
			];
		}
		$optimization_level = \WRIO\V2\Optimizer::stored_level( $optimization_level );
		$optimization_data  = $this->getOptimizationData();
		$summary            = ( new \WRIO\V2\Optimizer() )->optimize_item( 'cf_image', (int) $this->id, $this->get_files(), $optimization_level, [], true === $is_image_backuped );

		// Another process optimizes the image now, and writes its row.
		if ( \WRIO\V2\Optimizer::is_locked( $summary['error'] ) ) {
			return [
				'errors_count'    => 0,
				'original_size'   => 0,
				'optimized_size'  => 0,
				'optimized_count' => 0,
			];
		}

		$extra_data = $optimization_data->get_extra_data();
		$extra_data = $extra_data instanceof WRIO_CF_Image_Extra_Data ? $extra_data : new WRIO_CF_Image_Extra_Data();
		$extra_data->clear_failure();

		if ( $summary['error'] ) {
			$extra_data->set_failure( 'optimization', $summary['error'] );
		}

		$mime_type = (string) wp_get_image_mime( $this->path );

		$optimization_data->configure(
			[
				'result_status'      => $summary['error'] ? RIO_Process_Queue::STATUS_ERROR : RIO_Process_Queue::STATUS_SUCCESS,
				'processing_level'   => $optimization_level,
				'is_backed_up'       => $is_image_backuped,
				'original_size'      => $summary['original_size'],
				'final_size'         => $summary['optimized_size'],
				'original_mime_type' => $mime_type,
				'final_mime_type'    => $mime_type,
				'extra_data'         => $extra_data,
			]
		);
		$optimization_data->save();

		return [
			'errors_count'    => $summary['error'] ? 1 : 0,
			'original_size'   => $summary['original_size'],
			'optimized_size'  => $summary['optimized_size'],
			'optimized_count' => $summary['error'] ? 0 : 1,
		];
	}

	/**
	 * The file of the image, in the format of WRIO\V2\Optimizer::run().
	 *
	 * @return array<string, array{path: string, url: string, optimize: bool}>
	 */
	public function get_files() {
		return [
			'original' => [
				'path'     => $this->path,
				'url'      => $this->url,
				'optimize' => true,
			],
		];
	}

	/**
	 * Root of the path checks of this image: the root of its folder.
	 *
	 * @return string Root for WRIO_Custom_Folders::validate_path(). Empty for the default root.
	 */
	public function get_root(): string {
		$folders = WRIO_Custom_Folders::get_instance();
		$folder  = $folders instanceof WRIO_Custom_Folders ? $folders->getFolder( (string) $this->folder_uid ) : false;

		return $folder instanceof WRIO_Folder ? $folder->get_root() : '';
	}

	/**
	 * Делает резервную копию изображения
	 */
	public function backup() {
		$backup = WRIOP_Backup::get_instance();

		return $backup->backupCFImage( $this );
	}

	/**
	 * Восстанавливает из резервной копии
	 */
	public function restore() {
		$backup   = WRIOP_Backup::get_instance();
		$restored = $backup->restoreCFImage( $this );

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		if ( true !== $restored ) {
			// translators: %s: Image file name.
			return new WP_Error( 'restore_failed', sprintf( __( 'Could not restore %s from its backup.', 'robin-image-optimizer' ), wp_basename( $this->path ) ) );
		}

		\WRIO\V2\Format_Rows::delete( $this->get_files() );
		\WRIO\Storage\Store::restored( 'cf_image', (int) $this->id, $this->get_files() );

		// The backup is used and deleted: the Settings backup count must not count the image.
		$optimization_data = $this->getOptimizationData();
		$optimization_data->set_result_status( 'unoptimized' );
		$optimization_data->set_is_backed_up( false );
		$optimization_data->save();

		/**
		 * Хук срабатывает после восстановления cf_image
		 *
		 * @since 1.2.0
		 *
		 * @param RIO_Process_Queue $optimization_data
		 */
		do_action( 'wbcr/rio/cf_image_restored', $this->optimization_data );

		return true;
	}
}
