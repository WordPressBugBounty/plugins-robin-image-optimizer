<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с кастомными папками.
 *
 * @version       1.0
 */
class WRIO_Folder {

	/**
	 * @var string Folder path.
	 */
	protected $path = '';

	/**
	 * @var string SHA256 folder's path hash.
	 */
	protected $uid = '';

	/**
	 * @var int Number of files in current folder.
	 */
	protected $files_count = 0;

	/**
	 * @var int Number of optimized files in current folder.
	 */
	protected $optimized_count = 0;

	/**
	 * @var int Number of errors in current folder.
	 */
	protected $errors_count = 0;

	/**
	 * Added by a super admin: the folder can be outside the uploads of the site.
	 *
	 * @var bool
	 */
	protected $network = false;

	/**
	 * WRIO_Folder constructor.
	 *
	 * @param array $params List of params set on the model.
	 */
	public function __construct( $params = [] ) {
		// нужна проверка пути
		foreach ( $params as $key => $value ) {
			$this->set( $key, $value );
		}
		if ( ! $this->uid ) {
			$this->uid = hash( 'sha256', $this->path );
		}
	}

	/**
	 * Get specified object's property.
	 *
	 * @param string $property_name Property name.
	 *
	 * @return bool
	 */
	public function get( $property_name ) {
		if ( isset( $this->$property_name ) ) {
			return $this->$property_name;
		}

		return false;
	}

	/**
	 * Set specified object's property.
	 *
	 * @param string $property_name Property name.
	 * @param mixed  $value Value to set.
	 */
	public function set( $property_name, $value ) {
		if ( isset( $this->$property_name ) ) {
			$this->$property_name = $value;
		}
	}

	/**
	 * Convert object to associative array form.
	 *
	 * @return array
	 */
	public function toArray() {
		return [
			'path'            => $this->path,
			'files_count'     => $this->files_count,
			'uid'             => $this->uid,
			'optimized_count' => $this->optimized_count,
			'errors_count'    => $this->errors_count,
			'network'         => $this->network,
		];
	}

	/**
	 * Обходит каталог и добавляет в индекс файлы
	 *
	 * @param mixed $offset отступ от начала
	 * @param mixed $max_process_elements кол-во элементов за итерацию
	 *
	 * @return int Кол-во элементов, обработанных при индексировании
	 */
	public function indexing( $offset = 0, $max_process_elements = 100 ) {
		global $wpdb;

		$iterator = $this->getRecursiveIterator();
		$allowed  = $this->getAllowedFilesExt();
		$files    = [];
		$db_table = RIO_Process_Queue::table_name();

		foreach ( $iterator as $file ) {
			$ext = substr( $file, strrpos( strtolower( $file ), '.' ) + 1 ); // получаем расширение файла
			if ( in_array( strtolower( $ext ), $allowed ) ) {
				// сделать путь относительно корня
				$files[] = $this->real_path_to_relative( $file->getPathname() );
			}
		}

		$files = array_slice( $files, $offset, $max_process_elements );
		foreach ( $files as $file ) {
			$file = wp_normalize_path( $file );
			// $file_path = str_replace( $this->path, '', $file );
			$file_uid = hash( 'sha256', str_replace( wp_normalize_path( ABSPATH ), '', $file ) );
			$sql      = $wpdb->prepare( "SELECT * FROM {$db_table} WHERE item_hash_alternative = %s AND item_hash = %s;", $this->uid, $file_uid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
			$row      = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

			if ( empty( $row ) ) {
				// если файла нет в индексе - добавляем
				$extra_data        = new WRIO_CF_Image_Extra_Data(
					[
						'file_path'            => $file,
						'folder_relative_path' => $this->path,
					]
				);
				$optimization_data = new RIO_Process_Queue(
					[
						'item_type'             => 'cf_image',
						'item_hash'             => $file_uid, // хэш пути к файлу
						'item_hash_alternative' => $this->uid, // хэш директории будет сделан сеттером
						'original_size'         => 0,
						'final_size'            => 0,
						'original_mime_type'    => '',
						'final_mime_type'       => '',
						'result_status'         => 'unoptimized',
						'processing_level'      => '',
						'extra_data'            => $extra_data,
					]
				);
				$optimization_data->save();
			} else {
				// A file that changed after its result (a new size) is pending, so the next bulk run picks it.
				$path    = wp_normalize_path( untrailingslashit( WRIO_Custom_Folders::get_storage_root() ) . $file );
				$changed = [];
				foreach ( \WRIO\Storage\Store::rows( 'cf_image', (int) $row->id ) as $state ) {
					if ( in_array( $state['status'], [ \WRIO\Storage\Store::OK, \WRIO\Storage\Store::SKIPPED ], true ) && \WRIO\Storage\Store::source_changed( $state, $path ) ) {
						$changed[] = (string) $state['size_name'];
					}
				}
				\WRIO\Storage\Store::mark_pending( 'cf_image', (int) $row->id, 'replaced', array_values( array_unique( $changed ) ) );
			}
		}

		return count( $files ); // сколько элементов обработано при индексировании.
	}

	/**
	 * Removes the queue rows of the files that are no longer in the folder, one batch of rows.
	 *
	 * Batches go by row ID, not by offset: a deleted row would shift an offset past the next row.
	 *
	 * @param int $after_id             Check the rows after this ID.
	 * @param int $max_process_elements Rows per batch.
	 *
	 * @return int The last row ID of a full batch, to pass as $after_id; 0 when no row is left.
	 */
	public function sync_index( $after_id = 0, $max_process_elements = 100 ) {
		global $wpdb;
		$db_table = RIO_Process_Queue::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from the model.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$db_table} WHERE item_hash_alternative = %s AND id > %d ORDER BY id LIMIT %d", $this->uid, (int) $after_id, (int) $max_process_elements ) );
		$deleted = 0;

		foreach ( $rows as $row ) {
			$cf_image = new WRIO_Folder_Image( $row->id, $row );
			if ( ! $cf_image->isFileExists() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
				$wpdb->delete( $db_table, [ 'id' => (int) $row->id ], [ '%d' ] );
				++$deleted;
			}
		}

		if ( $deleted ) {
			$this->reCountOptimizedFiles();
		}

		return count( $rows ) < $max_process_elements ? 0 : (int) end( $rows )->id;
	}

	/**
	 * Get allowed list of extensions.
	 *
	 * @return array
	 */
	public function getAllowedFilesExt() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public API.
		$allowed_formats = explode( ',', WRIO_Plugin::app()->getPopulateOption( 'allowed_formats', 'image/jpeg,image/png,image/gif' ) );
		$allowed         = [];
		foreach ( $allowed_formats as $format ) {
			if ( $format == 'image/jpeg' ) {
				$allowed[] = 'jpg';
				$allowed[] = 'jpeg';
			} elseif ( $format == 'image/png' ) {
				$allowed[] = 'png';
			}
		}

		// $allowed = [ 'jpg', 'jpeg', 'png' ];

		return $allowed;
	}

	/**
	 * Count number of files in a folder.
	 *
	 * @return int
	 */
	public function countFiles() {
		$iterator = $this->getRecursiveIterator();
		$allowed  = $this->getAllowedFilesExt();
		$count    = 0;
		foreach ( $iterator as $file ) {
			$ext = substr( $file, strrpos( strtolower( $file ), '.' ) + 1 ); // получаем расширение файла
			if ( in_array( strtolower( $ext ), $allowed ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count number of indexes files.
	 *
	 * @return null|string
	 */
	public function countIndexedFiles() {
		global $wpdb;
		$db_table    = RIO_Process_Queue::table_name();
		$sql_files   = $wpdb->prepare( "SELECT COUNT(*) FROM {$db_table} WHERE item_type = %s AND item_hash_alternative = %s;", 'cf_image', $this->uid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
		$files_count = $wpdb->get_var( $sql_files ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return $files_count;
	}

	public function reCountFiles() {
		$this->files_count = $this->countFiles();

		return $this->files_count;
	}

	/**
	 * Recount optimized files.
	 *
	 * @return int|null|string
	 */
	public function reCountOptimizedFiles() {
		global $wpdb;
		$db_table              = RIO_Process_Queue::table_name();
		$sql                   = "SELECT COUNT(*) FROM {$db_table} WHERE item_type = %s AND result_status = %s AND item_hash_alternative = %s;";
		$sql_prepared          = $wpdb->prepare( $sql, 'cf_image', RIO_Process_Queue::STATUS_SUCCESS, $this->uid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Plugin table name, values are placeholders.
		$optimized_count       = $wpdb->get_var( $sql_prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$this->optimized_count = $optimized_count;

		return $this->optimized_count;
	}

	/**
	 * Remove the folder and its rows. The statistics are computed from the rows.
	 */
	public function remove() {
		global $wpdb;
		$db_table = RIO_Process_Queue::table_name();

		// The state and log rows of its images go too: the images get new IDs when the folder is added again. The
		// backups stay: the files on disk stay optimized, and a folder added again finds its backups by their path.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE item_type = 'cf_image' AND item_hash_alternative = %s", $db_table, $this->uid ) ) );

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			foreach ( [ \WRIO\Storage\Schema::files_table(), \WRIO\Storage\Schema::log_table() ] as $table ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE source = 'cf_image' AND object_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( [ $table ], $chunk ) ) );
			}
		}

		$wpdb->delete(
			$db_table,
			[
				'item_hash_alternative' => $this->uid,
				'item_type'             => 'cf_image',
			],
			[ '%s', '%s' ]
		);
	}

	/**
	 * Get absolute path from relative.
	 *
	 * False when the folder is outside the root of get_root().
	 *
	 * @return false|string
	 */
	public function real_path() {
		$path = WRIO_Custom_Folders::validate_path( trailingslashit( WRIO_Custom_Folders::get_storage_root() ) . ltrim( $this->path, '/' ), false, $this->get_root() );

		return is_wp_error( $path ) ? false : $path;
	}

	/**
	 * Root of the path checks of this folder and its files.
	 *
	 * A folder that a super admin added keeps the storage root. Cron and WP-CLI run without a user,
	 * so get_root_path() would refuse its files when it is outside the uploads. The uploads of the other
	 * sites stay out anyway unless a super admin acts: validate_path() checks the user of each action.
	 *
	 * @return string Root for WRIO_Custom_Folders::validate_path(). Empty for the default root.
	 */
	public function get_root(): string {
		return $this->network ? WRIO_Custom_Folders::get_storage_root() : '';
	}

	/**
	 * Returns the directory path relative to the site root
	 * Input: /home/user/test/wp.com/wp-content/uploads/custom-folder/
	 * Output: /wp-content/uploads/custom-folder/
	 *
	 * @param string $path Путь к директории. Может быть абсолютным.
	 *
	 * Same root as WRIO_Custom_Folders::get_storage_root().
	 *
	 * @return string $relative_path относительный путь
	 */
	public function real_path_to_relative( $path ) {
		$base_path     = untrailingslashit( WRIO_Custom_Folders::get_storage_root() );
		$relative_path = str_replace( $base_path, '', $path );

		return $relative_path;
	}

	/**
	 * Get iterator to go scan files in directory.
	 *
	 * Files and folders that resolve outside the root, through a symlink for example, are skipped.
	 *
	 * @return RecursiveIteratorIterator
	 */
	public function getRecursiveIterator() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public API.
		$path = $this->real_path();
		if ( false === $path ) {
			return new RecursiveIteratorIterator( new RecursiveArrayIterator( [] ) );
		}

		$root     = $this->get_root();
		$iterator = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			static function ( $file ) use ( $root ) {
				return $file instanceof SplFileInfo && ! is_wp_error( WRIO_Custom_Folders::validate_path( $file->getPathname(), false, $root ) );
			}
		);

		return new RecursiveIteratorIterator( $iterator );
	}
}
