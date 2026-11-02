<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с кастомными папками
 *
 * @version       1.0
 */
class WRIO_Custom_Folders {

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    object
	 */
	protected static $_instance;

	/**
	 * @var WRIO_Folder[]
	 */
	private $folders = [];

	/**
	 * @var WRIO_Folder_Image[]
	 */
	private $folder_images = [];

	/**
	 * WRIO_Custom_Folders constructor.
	 */
	public function __construct() {
		$folders = WRIO_Plugin::app()->getOption( 'custom_folders', [] );

		if ( ! empty( $folders ) ) {
			foreach ( (array) $folders as $uid => $folder ) {
				$this->folders[ $uid ] = new WRIO_Folder( $folder );
			}
		}

		$this->init();
	}

	/**
	 * @return object|\WRIO_Custom_Folders object Main instance.
	 * @since  1.3.0
	 */
	public static function get_instance() {
		if ( ! isset( static::$_instance ) ) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}

	/**
	 * Object init.
	 */
	public function init() {

		// todo: убрать эти фильтры
		add_filter( 'wbcr/rio/optimize_template/optimize_ajax_action', [ $this, 'optimizeAjaxAction' ], 10, 2 );
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

		add_action( 'admin_menu', [ $this, 'add_media_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'media_page_assets' ] );
		add_action( 'wbcr/rio/optimize_template/optimized_percent', [ $this, 'optimizedPercent' ], 10, 2 );
	}

	public function add_media_page() {
		add_submenu_page(
			'upload.php',
			__( 'Other Media', 'robin-image-optimizer' ),
			__( 'Other Media', 'robin-image-optimizer' ),
			'manage_options',
			'rio-custom-media',
			[
				$this,
				'custom_media_page',
			]
		);
	}

	public function media_page_assets( $hook ) {
		if ( 'media_page_rio-custom-media' == $hook ) {
			wp_enqueue_style( 'wio-install-addons', WRIO_PLUGIN_URL . '/admin/assets/css/media.css', [], WRIO_Plugin::app()->getPluginVersion() );
			wp_enqueue_style( 'wriop-other-media', WRIOP_PLUGIN_URL . '/admin/assets/css/other-media.css', [], WRIO_Plugin::app()->getPluginVersion() );
			WRIO_App::enqueue_script( 'wio-install-addons', 'single-optimization' );
		}
	}

	public function custom_media_page() {
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		require_once WRIOP_PLUGIN_DIR . '/includes/classes/class.folders-list-table.php';

		$list_table = new WRIO_Folders_List_Table();
		$list_table->prepare_items();
		$list_table->display(); // выводит на экран весь блок с таблицей и фильтрами
	}

	/**
	 * Get folder by specified id.
	 *
	 * @param string $uid Folder sha256 hash.
	 *
	 * @return bool|WRIO_Folder
	 */
	public function getFolder( $uid ) {
		if ( isset( $this->folders[ $uid ] ) ) {
			return $this->folders[ $uid ];
		}

		return false;
	}

	/**
	 * Get all available folders.
	 *
	 * @return WRIO_Folder[]
	 */
	public function getFolders() {
		return $this->folders;
	}

	/**
	 * Root available to the current custom-folder operation.
	 *
	 * On a network, only a super admin reaches past the uploads of the current site.
	 * Background jobs run without a user, so they stay in the uploads too, except in
	 * the folders that a super admin added (WRIO_Folder::get_root()).
	 *
	 * @return string
	 */
	public static function get_root_path() {
		if ( is_multisite() && ! is_super_admin() ) {
			return wp_upload_dir()['basedir'];
		}

		return self::get_storage_root();
	}

	/**
	 * Base of the stored relative paths of folders and images.
	 *
	 * @return string
	 */
	public static function get_storage_root() {
		if ( ! is_main_site() ) {
			return wp_upload_dir()['basedir'];
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return get_home_path();
	}

	/**
	 * Resolve a path inside the custom-folder root.
	 *
	 * @param string $path          Absolute filesystem path.
	 * @param bool   $allow_missing Allow a new file under an existing directory.
	 * @param string $root          Root to check against. Default: get_root_path(). Only a super admin reaches the
	 *                              other sites' uploads, whatever the root.
	 *
	 * @return string|WP_Error Canonical path or an error.
	 */
	public static function validate_path( $path, $allow_missing = false, $root = '' ) {
		$path         = (string) $path;
		$default_root = '' === $root;
		if ( false !== strpos( $path, "\0" ) ) {
			return new WP_Error( 'path_denied', __( 'Invalid path.', 'robin-image-optimizer' ) );
		}

		clearstatcache( true );
		$suffix = '';
		while ( $allow_missing && ! file_exists( $path ) && ! is_link( $path ) ) {
			$name = basename( $path );
			if ( '' === $name || '.' === $name || '..' === $name || dirname( $path ) === $path ) {
				return new WP_Error( 'path_denied', __( 'Invalid path.', 'robin-image-optimizer' ) );
			}
			$suffix = '/' . $name . $suffix;
			$path   = dirname( $path );
		}

		$root = realpath( $default_root ? self::get_root_path() : $root );
		$path = realpath( $path );
		if ( false === $root || false === $path ) {
			return new WP_Error( 'path_denied', __( 'The specified path does not exist.', 'robin-image-optimizer' ) );
		}
		$root = wp_normalize_path( $root );
		$path = wp_normalize_path( $path ) . $suffix;
		if ( ! self::is_within( $path, $root ) ) {
			return new WP_Error( 'path_denied', __( 'The specified path is outside the allowed directory.', 'robin-image-optimizer' ) );
		}

		// Whatever the root: a folder that a super admin added has a wider one, but the admin, cron run or WP-CLI
		// command that works on it now is not a super admin.
		if ( is_multisite() && ! is_super_admin() && self::in_other_site_uploads( $path ) ) {
			return new WP_Error( 'path_denied', __( 'The uploads of the other sites are not available.', 'robin-image-optimizer' ) );
		}

		return $path;
	}

	/**
	 * Whether a canonical path is in the uploads of another site of the network.
	 *
	 * The uploads of each other site count, wherever they are: uploads/sites/<id>/, a custom upload_path, or a
	 * symlink to another folder. The main site's uploads/sites/ and wp-content/blogs.dir/ (older networks) count as a
	 * whole too, for sites that no longer exist. Paths are compared in lower case: on a case-insensitive filesystem
	 * uploads/SITES/2 is uploads/sites/2.
	 *
	 * @param string $path Canonical path.
	 *
	 * @return bool
	 */
	private static function in_other_site_uploads( $path ) {
		// ponytail: one realpath() per site on each check, so a folder created or linked later still counts. Cache the
		// resolved folders per request if networks with thousands of sites scan slowly.
		static $dirs = [];
		$site        = get_current_blog_id();
		// A new or changed site changes the list.
		$key = $site . '|' . wp_cache_get_last_changed( 'sites' );
		if ( ! isset( $dirs[ $key ] ) ) {
			$others = [];
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $id ) {
				switch_to_blog( (int) $id );
				$basedir = wp_upload_dir( null, false, true )['basedir'];
				if ( is_main_site() ) {
					$others[] = $basedir . '/sites';
				}
				restore_current_blog();
				if ( (int) $id !== $site ) {
					$others[] = $basedir;
				}
			}
			$others[]     = WP_CONTENT_DIR . '/blogs.dir';
			$dirs[ $key ] = [
				'own'    => wp_upload_dir( null, false, true )['basedir'],
				'others' => $others,
			];
		}

		// Lower case only finds the match. The own-uploads exception keeps the case: on a case-sensitive filesystem,
		// wp-content/BLOGS.DIR as a site's uploads is not wp-content/blogs.dir.
		$own = self::resolve( $dirs[ $key ]['own'] );
		foreach ( $dirs[ $key ]['others'] as $dir ) {
			$dir = self::resolve( $dir );
			// A folder that holds this site's own uploads, like the main site's uploads for a subsite, does not deny them.
			if ( self::is_within( self::fold_case( $path ), self::fold_case( $dir ) ) && ! ( self::is_within( $path, $own ) && self::is_within( $own, $dir ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lower case of a path, non-ASCII letters too (Ä is ä on a case-insensitive filesystem).
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	private static function fold_case( $path ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
	}

	/**
	 * Canonical form of a folder, like validate_path() makes of the paths it checks. A folder that does not exist
	 * yet resolves through its parent.
	 *
	 * @param string $dir Folder.
	 *
	 * @return string
	 */
	private static function resolve( $dir ) {
		$real = realpath( $dir );
		if ( false !== $real ) {
			return wp_normalize_path( $real );
		}
		$parent = realpath( dirname( $dir ) );

		return wp_normalize_path( false === $parent ? dirname( $dir ) : $parent ) . '/' . basename( $dir );
	}

	/**
	 * Whether a canonical path is the root or inside it.
	 *
	 * @param string $path Canonical path.
	 * @param string $root Canonical root.
	 *
	 * @return bool
	 */
	private static function is_within( $path, $root ) {
		return $path === $root || 0 === strpos( $path, trailingslashit( $root ) );
	}

	/**
	 * Resolve a directory relative to the custom-folder root. A single slash is the root.
	 *
	 * @param mixed $path Relative path.
	 *
	 * @return string|WP_Error Canonical directory or an error.
	 */
	public static function resolve_directory( $path ) {
		// trim() would drop a trailing null byte before validate_path() sees it.
		if ( ! is_string( $path ) || false !== strpos( $path, "\0" ) ) {
			return new WP_Error( 'invalid_path', __( 'Invalid directory path.', 'robin-image-optimizer' ) );
		}
		$path = wp_normalize_path( trim( $path ) );
		if ( '/' !== $path && preg_match( '~^(?:/|[a-zA-Z]:)~', $path ) ) {
			return new WP_Error( 'invalid_path', __( 'Use a path relative to the allowed directory.', 'robin-image-optimizer' ) );
		}

		$resolved = self::validate_path( trailingslashit( self::get_root_path() ) . ( '/' === $path ? '' : $path ) );
		if ( is_wp_error( $resolved ) ) {
			return new WP_Error( 'invalid_path', $resolved->get_error_message() );
		}
		if ( ! is_dir( $resolved ) || ! is_readable( $resolved ) ) {
			return new WP_Error( 'invalid_path', __( 'The specified directory is not readable.', 'robin-image-optimizer' ) );
		}

		return $resolved;
	}

	/**
	 * Add new folder by specified path.
	 *
	 * @param string $path Path to folder, relative to get_root_path().
	 *
	 * @return WRIO_Folder|\WP_Error
	 */
	public function addFolder( $path ) {

		if ( empty( $path ) ) {
			return new WP_Error( 'empty_path', 'Path is empty.' );
		}

		// An absolute path inside the root (the REST API takes one) is made relative to it.
		$root_path = trailingslashit( wp_normalize_path( self::get_root_path() ) );
		$absolute  = trailingslashit( wp_normalize_path( (string) $path ) );
		if ( '/' !== $root_path && 0 === strpos( $absolute, $root_path ) ) {
			$path = substr( $absolute, strlen( $root_path ) );
		}

		// Older clients send the path with a leading slash.
		$path      = ltrim( (string) $path, '/' );
		$real_path = self::resolve_directory( '' === $path ? '/' : $path );
		if ( is_wp_error( $real_path ) ) {
			return $real_path;
		}

		// Stored paths stay relative to the storage root, which holds every root of get_root_path().
		$root = realpath( self::get_storage_root() );
		$root = false === $root ? '' : wp_normalize_path( $root );
		if ( '' === $root || ! self::is_within( $real_path, $root ) ) {
			return new WP_Error( 'invalid_path', __( 'The specified path is outside the allowed directory.', 'robin-image-optimizer' ) );
		}
		$path = $real_path === $root ? '/' : ltrim( substr( $real_path, strlen( $root ) ), '/' );

		// Older records store the same directory as '/2024/05/' instead of '2024/05'.
		foreach ( $this->folders as $folder ) {
			if ( $folder->real_path() === $real_path ) {
				return new WP_Error( 'folder_already_exists', 'Folder has already been added before.' );
			}
		}

		$uid = hash( 'sha256', $path );
		if ( ! $this->getFolder( $uid ) ) {
			$folder_data = [
				'path'    => $path,
				'uid'     => $uid,
				'network' => is_multisite() && is_super_admin(),
			];
			$new_folder  = new WRIO_Folder( $folder_data );
			$new_folder->reCountFiles();
			$this->folders[ $uid ] = $new_folder;
			$this->saveFolders();

			return $new_folder;
		}

		return new WP_Error( 'folder_already_exists', 'Folder has already been added before.' );
	}

	/**
	 * Remove folder by sha256 hash.
	 *
	 * @param string $uid SHA256 folder hash id.
	 *
	 * @return bool
	 */
	public function removeFolder( $uid ) {
		if ( empty( $uid ) ) {
			return false;
		}

		$folder = $this->getFolder( $uid );

		if ( ! $folder ) {
			return false;
		}

		$folder->remove();

		unset( $this->folders[ $uid ] );

		return true;
	}

	/**
	 * Saved all folders in options.
	 */
	public function saveFolders() {
		$folders = [];
		foreach ( $this->folders as $uid => $folder ) {
			$folders[ $uid ] = $folder->toArray();
		}
		WRIO_Plugin::app()->updateOption( 'custom_folders', $folders );
	}

	/**
	 * Возвращает объект image
	 *
	 * @param int         $image_id
	 * @param array|false $image_meta
	 *
	 * @return WRIO_Folder_Image
	 */
	public function getImage( $image_id, $image_meta = false ) {
		if ( ! isset( $this->folder_images[ $image_id ] ) ) {
			$this->folder_images[ $image_id ] = new WRIO_Folder_Image( $image_id, $image_meta );
		}

		return $this->folder_images[ $image_id ];
	}

	/**
	 * Оптимизирует cf_image
	 *
	 * @param int    $image_id номер картинки в таблице nextgen
	 * @param string $level качество
	 *
	 * @return array
	 */
	public function optimizeImage( $image_id, $level = '' ) {
		$cf_image = $this->getImage( $image_id );

		// The queue row was deleted after the image was queued: the image has no path (WRIO_Folder_Image::__construct()).
		if ( '' === $cf_image->get( 'path' ) ) {
			WRIO_Plugin::app()->logger->warning( sprintf( 'Skipped custom folder image #%d: its queue row does not exist.', $image_id ) );

			return [
				'errors_count'    => 0,
				'original_size'   => 0,
				'optimized_size'  => 0,
				'optimized_count' => 0,
			];
		}

		// A bulk run (no level) on a done image: only a format that was turned on later is missing.
		if ( '' === (string) $level && $cf_image->isOptimized() && wrio_converts_only( 'cf_image', (int) $image_id, $cf_image->get_files() ) ) {
			$error = ( new \WRIO\V2\Optimizer() )->convert_item( 'cf_image', (int) $image_id, $cf_image->get_files(), WRIO_Formats::get_enabled_formats() );

			return [
				'errors_count'    => $error ? 1 : 0,
				'original_size'   => 0,
				'optimized_size'  => 0,
				'optimized_count' => 0,
				'stop'            => wrio_convert_stop_error( 'cf_image', (int) $image_id, $error ),
			];
		}

		if ( $cf_image->isOptimized() && \WRIO\V2\Optimizer::restores_first( 'cf_image', (int) $image_id, $level, $cf_image->getOptimizationData()->get_processing_level() ) ) {
			// See WRIO_Media_Library::optimizeAttachment(): a new level needs the original. At the same level, an image
			// without a backup is optimized again as it is: its unchanged files are done already.
			$restored = \WRIO\V2\Optimizer::no_original( 'cf_image', (int) $image_id, $level, $cf_image->getOptimizationData()->get_processing_level() ) ?? $cf_image->restore();

			if ( is_wp_error( $restored ) && 'no_backup' !== $restored->get_error_code() ) {
				return [
					'errors_count'    => 1,
					'original_size'   => 0,
					'optimized_size'  => 0,
					'optimized_count' => 0,
					'error'           => $restored,
				];
			}
		}

		$image_optimized_data = $cf_image->optimize( $level );

		// The folder can be removed while its images wait in the queue.
		$folder = $this->getFolder( $cf_image->get( 'folder_uid' ) );
		if ( $folder instanceof WRIO_Folder ) {
			$folder->reCountOptimizedFiles();
			$this->saveFolders();
		}

		return $image_optimized_data;
	}

	/**
	 * Обработка неоптимизированных изображений
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

		if ( empty( $this->folders ) ) {
			return new WP_Error( 'folders_not_found', __( 'You need to add a custom folder to start optimization.', 'robin-image-optimizer' ) );
		}

		$image_statistics = WRIO_Image_Statistic_Folders::get_instance();

		$folder_images = $image_statistics->getUnoptimized( $max_process_per_request ); // тут будет выборка

		// Nothing to do is the end of the run, not a failure.
		if ( empty( $folder_images ) ) {
			return [
				'remain'          => 0,
				'end'             => true,
				'optimized_count' => 0,
				'last_optimized'  => null,
				'statistic'       => $image_statistics->load(),
			];
		}

		$folder_images_count = count( $folder_images );
		$optimized_count     = 0;
		$optimized_items     = [];

		// обработка
		if ( $folder_images_count ) {
			foreach ( $folder_images as $folder_image ) {
				// Only a record that was never saved has no ID: the query reads saved rows.
				if ( null === $folder_image->id ) {
					continue;
				}

				if ( $optimized_count > 0 && ! wrio_has_time_left() ) {
					$folder_images_count = $optimized_count;
					break;
				}

				$result = $this->optimizeImage( $folder_image->id );

				if ( ! empty( $result['stop'] ) ) {
					return $result['stop'];
				}

				++$optimized_count;
				$optimized_items[ $folder_image->id ] = $folder_image;
			}
		}

		// Counted again: an image whose request failed stays to do.
		$remain = (int) $image_statistics->getUnoptimizedCount();

		// The ID, not the row: get_last_optimized_image() reads the row again, after its optimization.
		$last_optimized = array_key_last( $optimized_items );

		$responce = [
			'remain'          => $remain,
			'end'             => false,
			'optimized_count' => $optimized_count,
			'last_optimized'  => null !== $last_optimized ? $image_statistics->get_last_optimized_image( $last_optimized ) : null,
			'statistic'       => $image_statistics->load(),
		];

		return $responce;
	}

	/**
	 * Восстановление из резервной копии
	 *
	 * @param int $folder_uid Folder id.
	 * @param int $max_process_per_request кол-во аттачментов за 1 запуск
	 *
	 * @return array
	 */
	public function restoreFolderFromBackup( $folder_uid, $max_process_per_request = 5 ) {
		WRIO_Plugin::app()->updatePopulateOption( 'cron_running', false ); // останавливаем крон

		$processing = new WRIO_Folder_Processing( 'custom-folders' );
		$processing->cancel_process();
		WRIO_Plugin::app()->updatePopulateOption( 'process_running', false ); // останавливаем обработку

		global $wpdb;

		$db_table         = RIO_Process_Queue::table_name();
		$optimized_count  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'success' LIMIT 1;", $folder_uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.
		$optimized_images = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$db_table} WHERE item_type = 'cf_image' AND item_hash_alternative = %s AND result_status = 'success' LIMIT %d", $folder_uid, $max_process_per_request ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name.

		$images_count = 0;
		if ( $optimized_images ) {
			$images_count = count( $optimized_images );
		}

		// обработка
		if ( $images_count ) {
			foreach ( $optimized_images as $row ) {
				$image_id = intval( $row->id );
				$cf_image = $this->getImage( $image_id );
				if ( $cf_image->isOptimized() ) {
					$restored = $cf_image->restore();

					if ( is_wp_error( $restored ) ) {
						WRIO_Plugin::app()->logger->error( sprintf( 'Failed to restore custom folder. Object info: %s', wp_json_encode( $cf_image ) ) );
						continue;
					}

					$folder = $this->getFolder( $cf_image->get( 'folder_uid' ) );
					$folder->reCountOptimizedFiles();
				}
			}
			$this->saveFolders();
		}
		$remain = $optimized_count - $images_count;

		return [
			'remain' => $remain,
		];
	}

	/**
	 * Сбрасывает текущие ошибки оптимизации
	 * Позволяет изображениям, которые оптимизированы с ошибкой, заново пройти оптимизацию.
	 *
	 * @return void
	 */
	public function resetCurrentErrors() {
		RIO_Process_Queue::reset_errors( 'cf_image', RIO_Process_Queue::STATUS_UNOPTIMIZED );
		\WRIO\Storage\Store::errors_reset( 'cf_image' );
	}

	/**
	 * Хук возвращает ajax action для кнопки оптимизации.
	 *
	 * @param string $action
	 * @param string $type
	 *
	 * @return string
	 */
	public function optimizeAjaxAction( $action, $type ) {
		if ( $type == 'custom-folders' ) {
			return 'wriop_process_cf_images';
		}

		return $action;
	}

	/**
	 * Хук возвращает ajax action для кнопки переоптимизации.
	 *
	 * @param string $action
	 * @param string $type
	 *
	 * @return string
	 */
	public function reoptimizeAjaxAction( $action, $type ) {
		if ( $type == 'custom-folders' ) {
			return 'wio_cf_reoptimize_image';
		}

		return $action;
	}

	/**
	 * Хук возвращает ajax action для кнопки восстановления.
	 *
	 * @param string $action
	 * @param string $type
	 *
	 * @return string
	 */
	public function restoreAjaxAction( $action, $type ) {
		if ( $type == 'custom-folders' ) {
			return 'wio_cf_restore_image';
		}

		return $action;
	}

	/**
	 * The text of the Status column.
	 *
	 * @param string|null $status Result status of the queue row.
	 *
	 * @return string Empty for an unknown status.
	 */
	public static function status_label( $status ) {
		$statuses = [
			'success'     => __( 'Success', 'robin-image-optimizer' ),
			'error'       => __( 'Error', 'robin-image-optimizer' ),
			'processing'  => __( 'Processing', 'robin-image-optimizer' ),
			'unoptimized' => __( 'Unoptimized', 'robin-image-optimizer' ),
			'skip'        => __( 'Skipped', 'robin-image-optimizer' ),
		];

		return $statuses[ (string) $status ] ?? '';
	}

	/**
	 * Возвращает блок оптимизации.
	 *
	 * @param int $image_id
	 *
	 * @return string
	 */
	public function getMediaColumnContent( $image_id ) {
		$media_library = WRIO_Media_Library::get_instance();
		$params        = $this->calculateParams( $image_id );
		$status        = ( new WRIO_Folder_Image( $image_id ) )->getOptimizationData()->get_result_status();

		// The new text of the Status column of the row, for the script that replaces the column after an action.
		return $media_library->getMediaColumnTemplate( $params, 'custom-folders' )
			. '<span class="wio-row-status" hidden>' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * Просчитывает параметры блока оптимизации
	 *
	 * @param int $image_id номер изображения nextgen
	 *
	 * @return array $params
	 */
	public function calculateParams( $image_id ) {
		$cf_image             = new WRIO_Folder_Image( $image_id );
		$isOptimized          = $cf_image->isOptimized();
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
			$optimization_data = $cf_image->getOptimizationData();
			/**
			 * @var WRIO_CF_Image_Extra_Data $extra_data
			 */
			$extra_data           = $optimization_data->get_extra_data();
			$optimization_level   = $optimization_data->get_processing_level();
			$original_main_size   = $optimization_data->get_original_size();
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
		} elseif ( RIO_Process_Queue::STATUS_ERROR === $cf_image->getOptimizationData()->get_result_status() ) {
			// The last try failed, for example on a damaged file: show why.
			$extra_data = $cf_image->getOptimizationData()->get_extra_data();
			$error_msg  = $extra_data instanceof WRIO_CF_Image_Extra_Data ? (string) $extra_data->get_error_msg() : '';
		}
		$params = [
			'attachment_id'        => $image_id,
			'is_optimized'         => $isOptimized,
			'attach_dimensions'    => 0,
			'attachment_file_size' => $attachment_file_size,
			'optimized_size'       => $optimized_size,
			'original_size'        => $original_size,
			'original_main_size'   => $original_main_size,
			'thumbnails_optimized' => 0,
			'optimization_level'   => $optimization_level,
			'error_msg'            => $error_msg,
			'backuped'             => $backuped,
			'other_levels'         => \WRIO\V2\Optimizer::has_original( 'cf_image', (int) $image_id ),
			'diff_percent'         => $diff_percent,
			'diff_percent_all'     => $diff_percent_all,
			'is_skipped'           => false,
		];

		return $params;
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
		if ( 'custom-folders' == $type ) {
			$image_statistics = WRIO_Image_Statistic_Folders::get_instance();

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
		$image_statistics = WRIO_Image_Statistic_Folders::get_instance();
		$folder_images    = $image_statistics->getUnoptimized( PHP_INT_MAX ); // тут будет выборка

		$return = [];
		foreach ( $folder_images as $folder_image ) {
			$return[] = $folder_image->id;
		}

		return $return;
	}

	/**
	 * Entries of a directory under get_root_path(), minus the folders the plugin never optimizes.
	 * Shared by the admin-ajax file tree and the REST folder picker.
	 *
	 * @param string $dir          Directory relative to the root ("/" for the root itself).
	 * @param bool   $only_folders Skip files.
	 * @param bool   $only_files   Skip directories.
	 *
	 * @return array<int, array{name: string, path: string, type: string}> path is relative, no trailing slash.
	 */
	public static function browse_dir( $dir, $only_folders = true, $only_files = false ) {
		// Callers pass the paths this method returns, which carry no slash.
		$dir          = ltrim( trim( (string) $dir ), '/' );
		$dir          = '' === $dir ? '/' : $dir;
		$only_folders = '/' === $dir || $only_folders;
		$base         = self::resolve_directory( '/' );
		$selected_dir = self::resolve_directory( $dir );
		if ( is_wp_error( $base ) || is_wp_error( $selected_dir ) ) {
			return [];
		}
		$base         = trailingslashit( $base );
		$selected_dir = trailingslashit( $selected_dir );

		// The entries are canonical, so the excluded folders are too: wp-content or the uploads can be a symlink.
		$canonical = static function ( $path ) {
			$real = realpath( $path );
			return false === $real ? untrailingslashit( wp_normalize_path( $path ) ) : wp_normalize_path( $real );
		};

		$upload_dir_path = trailingslashit( $canonical( wp_upload_dir()['basedir'] ) );
		$wp_content_dir  = trailingslashit( $canonical( WP_CONTENT_DIR ) );
		$exclude_dirs    = [
			$wp_content_dir . 'backup',
			$wp_content_dir . 'backups',
			$wp_content_dir . 'cache',
			$wp_content_dir . 'lang',
			$wp_content_dir . 'langs',
			$wp_content_dir . 'languages',
			$upload_dir_path . 'wio_backup',
			$upload_dir_path . 'wrio',
			$upload_dir_path . 'wrio-webp-uploads',
		];
		foreach ( [ wrio_get_ngg_galleries_path(), wrio_get_shortpixel_path(), wrio_get_ewww_tools_path(), wrio_get_wc_logs_path() ] as $path ) {
			if ( ! empty( $path ) ) {
				$path           = untrailingslashit( wp_normalize_path( (string) $path ) );
				$exclude_dirs[] = trailingslashit( $canonical( dirname( $path ) ) ) . basename( $path );
			}
		}
		// Year folders of the uploads dir are already covered by the media library.
		$year = (int) gmdate( 'Y' ) + 1;
		for ( $i = 2003; $i <= $year; $i++ ) {
			$exclude_dirs[] = $upload_dir_path . $i;
		}

		$files = scandir( $selected_dir );
		if ( false === $files ) {
			return [];
		}
		natcasesort( $files );
		$return_dir = substr( $selected_dir, strlen( $base ) );
		$entries    = [];

		foreach ( $files as $file ) {
			if ( count( $entries ) > 200 ) {
				break; // Very large folders are cut off, like the old tree.
			}
			if ( '.' === $file || '..' === $file || in_array( $selected_dir . $file, $exclude_dirs, true ) || ! file_exists( $selected_dir . $file ) ) {
				continue;
			}
			if ( is_wp_error( self::validate_path( $selected_dir . $file ) ) ) {
				continue;
			}
			$is_dir = is_dir( $selected_dir . $file );
			if ( $is_dir && ( ! $only_files || $only_folders ) ) {
				$entries[] = [
					'name' => $file,
					'path' => $return_dir . $file,
					'type' => 'dir',
				];
			} elseif ( ! $is_dir && ( ! $only_folders || $only_files ) ) {
				$entries[] = [
					'name' => $file,
					'path' => $return_dir . $file,
					'type' => 'file',
				];
			}
		}

		return $entries;
	}

	/**
	 * Index the next batch of files of a folder; call again with the returned offset until complete.
	 *
	 * @param string $uid    Folder id.
	 * @param int    $offset Files indexed so far.
	 * @param int    $total  Total files, 0 to count them first.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scan_step( $uid, $offset, $total ) {
		$folders = self::get_instance();
		if ( ! $folders instanceof WRIO_Custom_Folders ) {
			return new WP_Error( 'wrio_not_available', __( 'Custom folders are not available.', 'robin-image-optimizer' ), [ 'status' => 501 ] );
		}
		$folder = $folders->getFolder( (string) $uid );
		if ( ! $folder instanceof WRIO_Folder ) {
			return new WP_Error( 'wrio_bad_request', __( 'Folder not found.', 'robin-image-optimizer' ), [ 'status' => 404 ] );
		}

		$offset = (int) $offset;
		$total  = (int) $total;
		if ( ! $total ) {
			$total = $folder->reCountFiles();
			$folders->saveFolders();
		}

		$batch     = 100;
		$processed = $folder->indexing( $offset, $batch );
		$offset   += $processed;

		$results = [
			'offset'   => $offset,
			'total'    => $total,
			'complete' => false,
			'percent'  => 0,
		];
		if ( $total ) {
			$results['percent'] = ( 100 - ( ( $total - $offset ) * 100 / $total ) ) / 2 + 50;
		}
		// A batch shorter than the limit is the end of the folder, whatever the
		// count said at the start. Files can go while the scan runs, and the
		// caller asks again until this reports the end.
		if ( $offset >= $total || $processed < $batch ) {
			// The scan adds and updates the files on disk; the rows of the deleted files go now.
			$after_id = 0;
			do {
				$after_id = $folder->sync_index( $after_id, $batch );
			} while ( $after_id );
			$folders->saveFolders();

			$results['percent']  = 100;
			$results['complete'] = true;
		}

		return $results;
	}
}
