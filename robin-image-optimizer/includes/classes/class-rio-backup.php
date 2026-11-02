<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с резервным копированием изображений.
 *
 * @version       1.0
 */
class WIO_Backup {

	const BACKUP_DIR_NAME = 'wio_backup';
	const TEMP_DIR_NAME   = 'temp';

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    object
	 */
	protected static $_instance;

	/**
	 * @var array Данные о папке uploads, возвращаемые функцией wp_upload_dir()
	 */
	protected $wp_upload_dir;

	/**
	 * What the last is_safe_pair() found: the device and inode of the file and of its backup (false: no file, or no
	 * check yet), and the real folder of the file ('' when it has none).
	 *
	 * @var array{0: int, 1: int}|false
	 */
	protected $checked_source = false;

	/**
	 * See $checked_source.
	 *
	 * @var array{0: int, 1: int}|false
	 */
	protected $checked_backup = false;

	/**
	 * See $checked_source.
	 *
	 * @var string
	 */
	protected $checked_folder = '';

	/**
	 * The real folder of the backup that the last is_safe_pair() checked ('' when it refused the pair).
	 *
	 * @var string
	 */
	protected $checked_backup_folder = '';

	/**
	 * @var string Путь к папке с резервными копиями изображений
	 */
	private $backup_dir;

	/**
	 * @since  1.3.0
	 * @var string
	 */
	private $blog_backup_dir;

	/**
	 * Инициализация бекапа
	 */
	public function __construct() {
		$this->wp_upload_dir = wp_upload_dir();
	}

	/**
	 * @since  1.3.0
	 *
	 * @return object|\static object Main instance.
	 */
	public static function get_instance() {
		if ( ! isset( static::$_instance ) ) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}

	/**
	 * Проверка возможности записи в папку uploads.
	 *
	 * @return bool
	 */
	public function isUploadWritable() {
		$upload_dir = $this->wp_upload_dir['basedir'];

		if ( is_dir( $upload_dir ) && wp_is_writable( $upload_dir ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Проверка возможности записи в папку бекап.
	 *
	 * @return bool
	 */
	public function isBackupWritable() {

		$backup_dir = $this->getBackupDir();

		if ( is_wp_error( $backup_dir ) || ! wp_is_writable( $backup_dir ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Путь к папке с бекапами
	 *
	 * @return string|WP_Error
	 */
	public function getBackupDir() {
		if ( $this->backup_dir ) {
			return $this->backup_dir;
		}

		$backup_dir = wp_normalize_path( trailingslashit( $this->wp_upload_dir['basedir'] ) . self::BACKUP_DIR_NAME );

		if ( ! is_dir( $backup_dir ) ) {
			$backup_dir = $this->mkdir( $backup_dir );

			if ( is_wp_error( $backup_dir ) ) {
				return $backup_dir;
			}
		}

		wrio_protect_dir( $backup_dir );

		$backup_dir = apply_filters( 'wbcr/rio/backup/backup_dir', trailingslashit( $backup_dir ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- public filter name, renaming it breaks existing filters.
		if ( ! is_dir( $backup_dir ) ) {
			$backup_dir = $this->mkdir( $backup_dir );
			if ( is_wp_error( $backup_dir ) ) {
				return $backup_dir;
			}
		}
		wrio_protect_dir( $backup_dir );
		$this->backup_dir = trailingslashit( $backup_dir );

		return $this->backup_dir;
	}

	/**
	 * Путь к папке с бекапами блога.
	 *
	 * Используется в мультисайт режиме.
	 *
	 * @return string|WP_Error
	 */
	public function getBlogBackupDir() {
		if ( $this->blog_backup_dir ) {
			return $this->blog_backup_dir;
		}

		$wp_upload_dir = wp_upload_dir();
		$backup_dir    = wp_normalize_path( trailingslashit( $wp_upload_dir['basedir'] ) . self::BACKUP_DIR_NAME );

		if ( ! is_dir( $backup_dir ) ) {
			$backup_dir = $this->mkdir( $backup_dir );

			if ( is_wp_error( $backup_dir ) ) {
				return $backup_dir;
			}
		}

		wrio_protect_dir( $backup_dir );

		$this->blog_backup_dir = trailingslashit( $backup_dir );

		return $this->blog_backup_dir;
	}

	/**
	 * Очищает папку с резервными копиями
	 *
	 * @return bool
	 */
	public function removeBackupDir() {
		$backup_dir = $this->getBackupDir();

		return wrio_rmdir( $backup_dir );
	}

	/**
	 * Очищает папку с резервными копиями блога
	 * Используется в мультисайт режиме
	 *
	 * @return bool
	 */
	public function removeBlogBackupDir() {
		$backup_dir = $this->getBlogBackupDir();

		return wrio_rmdir( $backup_dir );
	}

	/**
	 * Получает путь к папке с резервными копиями
	 *
	 * @param array $attachment_meta   метаданные аттачмента
	 *
	 * @return string|WP_Error The folder, or the error when it cannot be created.
	 */
	public function getAttachmentBackupDir( $attachment_meta ) {
		$backup_dir = $this->getBackupDir();

		// Get all subfolders in which the image is stored.
		// This is necessary to create an alternate subfolders
		// in directory where they are stored in backups.
		$subfolders = dirname( $attachment_meta['file'] );

		$backup_dir .= $subfolders;

		if ( ! is_dir( $backup_dir ) ) {
			$backup_dir = $this->mkdir( $backup_dir );

			if ( is_wp_error( $backup_dir ) ) {
				return $backup_dir;
			}
		}

		return trailingslashit( $backup_dir );
	}

	/**
	 * Делаем резервную копию аттачмента
	 *
	 * @param WIO_Attachment $wio_attachment   аттачмент
	 *
	 * @return bool|WP_Error
	 */
	public function backupAttachment( WIO_Attachment $wio_attachment ) {
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );

		if ( ! $backup_origin_images ) {
			return false; // если бекап не требуется
		}

		$backup_dir = $this->getAttachmentBackupDir( $wio_attachment->get( 'attachment_meta' ) );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$recorded = (bool) $wio_attachment->getOptimizationData()->get_is_backed_up();
		$full     = $this->backupAttachmentSize( $wio_attachment, '', $recorded );

		if ( is_wp_error( $full ) ) {
			return $full;
		}

		// False when no file has a backup, for example an empty image without sizes.
		$backed_up     = true === $full;
		$allowed_sizes = $wio_attachment->getAllowedSizes();

		if ( ! empty( $allowed_sizes ) ) {
			foreach ( (array) $allowed_sizes as $image_size ) {
				$size_backup = $this->backupAttachmentSize( $wio_attachment, $image_size, $recorded );

				if ( is_wp_error( $size_backup ) ) {
					return $size_backup;
				}

				$backed_up = $backed_up || true === $size_backup;
			}
		}

		return $backed_up;
	}

	/**
	 * Восстанавливаем аттачмент из резервной копии
	 *
	 * @param WIO_Attachment $wio_attachment   аттачмент
	 *
	 * @return bool|WP_Error
	 */
	public function restoreAttachment( WIO_Attachment $wio_attachment ) {
		$backup_dir = $this->getAttachmentBackupDir( $wio_attachment->get( 'attachment_meta' ) );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$main = $this->find_backup( $wio_attachment );

		if ( is_wp_error( $main ) ) {
			// An image optimized without a backup has nothing to put back: the restore only removes its converted
			// files, and the file stays optimized. A backup that should be there and is not is a failure.
			if ( 'backup_missing' !== $main->get_error_code() || $this->is_backed_up( $wio_attachment ) ) {
				return $main;
			}

			$main = false;
		}

		$copies = array_filter( [ $main ] );
		$meta   = $wio_attachment->get( 'attachment_meta' );
		$sizes  = [];

		// Every size with a backup, also one that is no longer in the thumbnail setting. Sizes can share one file.
		foreach ( array_keys( (array) ( $meta['sizes'] ?? [] ) ) as $image_size ) {
			$sizes[ (string) $wio_attachment->getImageSizePath( $image_size ) ][] = (string) $image_size;
		}

		// The sizes that were optimized with a backup. Their backup must be there: without it, the restore would put
		// back the main image and leave the size optimized. The flag is the item's, so a size that was only
		// converted (a WebP or AVIF row) has it too, without a backup of its own. A row copied from an old version
		// (legacy) has the flag on every size, but the old version backed up only the sizes of its thumbnail
		// setting: a missing backup of such a size does not stop the restore.
		$backed_up = [];

		foreach ( \WRIO\Storage\Store::rows( 'attachment', (int) $wio_attachment->get( 'id' ) ) as $row ) {
			if ( 1 === (int) $row['backed_up'] && ! (int) $row['legacy'] && 'optimized' === $row['format'] && \WRIO\Storage\Store::OK === $row['status'] ) {
				$backed_up[ (string) $row['size_name'] ] = true;
			}
		}

		// Every backup is checked before the first copy, so a refused restore changes nothing.
		foreach ( $sizes as $path => $names ) {
			$result = $this->find_backup( $wio_attachment, $names[0] );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result ) {
				$copies[] = $result;
			} elseif ( '' !== $path && array_intersect_key( $backed_up, array_flip( $names ) ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Unable to restore attachment %s: the backup of size %s is missing.', $wio_attachment->get( 'id' ), $names[0] ) );

				// translators: %s: Image file name.
				return new WP_Error( 'backup_missing', sprintf( __( 'The backup for %s is missing.', 'robin-image-optimizer' ), wp_basename( $path ) ) );
			}
		}

		// A file replaced after its optimization (by FTP, for example) is a new original: the old backup does not go
		// over it, and it goes with the others. Store::restored() then forgets its rows.
		// By the path: sizes can share one file, with a row under each of their names. One stale row is enough.
		$rows    = \WRIO\Storage\Store::rows( 'attachment', (int) $wio_attachment->get( 'id' ) );
		$dropped = [];

		foreach ( $copies as $key => list( $backup_file, $file ) ) {
			$hash  = \WRIO\Storage\Store::path_hash( $file );
			$stale = array_filter( $rows, fn( $row ) => 'optimized' === $row['format'] && $hash === $row['path_hash'] && \WRIO\Storage\Store::STALE === \WRIO\Storage\Store::state( $row, $file ) );

			if ( ! empty( $stale ) ) {
				WRIO_Plugin::app()->logger->info( sprintf( 'Kept %s: it was replaced after the optimization. Its old backup %s is deleted.', $file, $backup_file ) );
				$dropped[] = [ $backup_file, $file ];
				unset( $copies[ $key ] );
			}
		}

		$copied = $this->copy_backups( $copies );

		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		$attachment_meta = wp_get_attachment_metadata( $wio_attachment->get( 'id' ) );

		if ( is_array( $attachment_meta ) ) {
			$dimensions = wp_getimagesize( $wio_attachment->get( 'path' ) );
			$new_meta   = $attachment_meta;

			if ( $dimensions ) {
				$new_meta['width']  = $dimensions[0];
				$new_meta['height'] = $dimensions[1];
			} elseif ( isset( $attachment_meta['old_width'], $attachment_meta['old_height'] ) ) {
				$new_meta['width']  = $attachment_meta['old_width'];
				$new_meta['height'] = $attachment_meta['old_height'];
			}

			unset( $new_meta['old_width'], $new_meta['old_height'] );

			if ( $new_meta !== $attachment_meta ) {
				wp_update_attachment_metadata( $wio_attachment->get( 'id' ), $new_meta );
			}
		}

		// The backups go only when every file is back, so a failed restore can be tried again.
		foreach ( array_merge( $copies, $dropped ) as list( $backup_file ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- every file is back, a cleanup failure is only logged.
			if ( ! @unlink( $backup_file ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to delete backup file %s as unlink() failed', $backup_file ) );
			}
		}

		return true;
	}

	/**
	 * Whether the optimization of the main file made a backup, by its queue row or its state rows.
	 *
	 * A replaced main file loses its backup on purpose, and its thumbnails keep theirs. An edited one has none yet.
	 *
	 * @param WIO_Attachment $wio_attachment Attachment.
	 *
	 * @return bool
	 */
	protected function is_backed_up( WIO_Attachment $wio_attachment ): bool {
		$rows = \WRIO\Storage\Store::rows( 'attachment', (int) $wio_attachment->get( 'id' ) );
		$main = array_filter( $rows, fn( $row ) => 'original' === $row['size_name'] );

		// The image editor saved the main file under a new name: the edited file was never backed up, it is a new
		// original. The backup of the file before the edit stays for the editor's "Restore image".
		if ( isset( $rows['original|optimized'] ) && \WRIO\Storage\Store::PENDING === $rows['original|optimized']['status'] && 'edited' === $rows['original|optimized']['code'] ) {
			return false;
		}

		return (bool) $wio_attachment->getOptimizationData()->get_is_backed_up() || in_array( 1, array_map( 'intval', wp_list_pluck( $main, 'backed_up' ) ), true );
	}

	/**
	 * Copy the backups over the files. When a copy fails, the files copied before get their content back.
	 *
	 * @param array $copies Each backup file and the file it restores. With what a check found for them, as
	 *                      find_backup() returns them; without it (NextGEN), nothing is checked.
	 *
	 * @phpstan-param array<int, array{0: string, 1: string, 2?: array{0: int, 1: int}|false, 3?: array{0: int, 1: int}|false, 4?: string}> $copies
	 *
	 * @return true|WP_Error
	 */
	protected function copy_backups( array $copies ) {
		$copied = [];
		$error  = null;

		// An empty backup is not an image: nothing is copied, and the current files and the backups stay.
		foreach ( $copies as list( $backup_file, $file ) ) {
			if ( ! $this->is_usable_backup( $backup_file ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Unable to restore %s, the backup %s is empty.', $file, $backup_file ) );

				// translators: %s: Image file name.
				return new WP_Error( 'restore_copy_failed', sprintf( __( 'Could not restore %s from its backup.', 'robin-image-optimizer' ), wp_basename( $file ) ) );
			}
		}

		foreach ( $copies as $copy ) {
			list( $backup_file, $file, $backup_id, $file_id, $folder ) = array_replace( [ '', '', null, null, '' ], $copy );
			// The current file, to put back after a failure. Only the file that the check found.
			$keep = is_file( $file ) ? $backup_file . '.restore' : '';

			if ( '' !== $keep && ! $this->copy_checked( $file, $keep, $file_id ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to copy %s to %s as copy() failed', $file, $keep ) );
				$error = $file;
				break;
			}

			$copied[] = [ $file, $keep, $folder ];

			if ( ! $this->replace_file( $backup_file, $file, $backup_id, $folder ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to swap %s with %s as copy() failed', $backup_file, $file ) );
				$error = $file;
				break;
			}
		}

		foreach ( $copied as list( $file, $keep, $folder ) ) {
			if ( null !== $error ) {
				if ( '' === $keep ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- the file was not there before the restore.
					@unlink( $file );
				} elseif ( ! $this->replace_file( $keep, $file, null, $folder ) ) {
					WRIO_Plugin::app()->logger->error( sprintf( 'Failed to put back %s from %s as copy() failed', $file, $keep ) );
				}
			}

			if ( '' !== $keep ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- a temporary copy.
				@unlink( $keep );
			}
		}

		if ( null !== $error ) {
			// translators: %s: Image file name.
			return new WP_Error( 'restore_copy_failed', sprintf( __( 'Could not restore %s from its backup.', 'robin-image-optimizer' ), wp_basename( $error ) ) );
		}

		foreach ( $copies as list( $backup_file ) ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Restored file: %s.', $backup_file ) );
		}

		return true;
	}

	/**
	 * Создает временное изображение с уникальным именем.
	 *
	 * Необходимо для провайдеров, который кешируют изображения по имени файла,
	 * чтобы сбросить кеш, нужно отдать провайдеру изображение с другим именем.
	 *
	 * @since  1.1.2
	 *
	 * @param string $file_path   путь к изображению
	 *
	 * @return array|WP_Error
	 */
	public function createTempAttachment( $file_path ) {
		if ( $this->isBackupWritable() ) {

			$temp_dir     = $this->getBackupDir() . self::TEMP_DIR_NAME . '/';
			$temp_dir_url = trailingslashit( $this->wp_upload_dir['baseurl'] ) . self::BACKUP_DIR_NAME . '/' . self::TEMP_DIR_NAME . '/';

			if ( ! is_dir( $temp_dir ) ) {
				$temp_dir = $this->mkdir( $temp_dir );

				if ( is_wp_error( $temp_dir ) ) {
					return $temp_dir;
				}
			}

			$temp_file_id   = uniqid();
			$file_name      = pathinfo( $file_path, PATHINFO_FILENAME );
			$file_extension = pathinfo( $file_path, PATHINFO_EXTENSION );
			$new_file_name  = $temp_file_id . '_' . md5( $file_name ) . '.' . $file_extension;

			$temp_file_path = $temp_dir . $new_file_name;
			$temp_file_url  = $temp_dir_url . $new_file_name;

			if ( is_file( $file_path ) ) {
				if ( ! @copy( $file_path, $temp_file_path ) ) {
					WRIO_Plugin::app()->logger->error( sprintf( 'Failed to swap original file %s with %s as copy() failed.', $temp_file_path, $file_path ) );

					return new WP_Error( 'copy_file_to_temp_dir_error', __( 'Could not copy the file to the temporary directory', 'robin-image-optimizer' ) );
				}
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'Creation of temporary attachment (%s) successfully completed!', $file_path ) );

			return [
				'id'         => $temp_file_id,
				'image_path' => $temp_file_path,
				'image_url'  => $temp_file_url,
			];
		}

		return new WP_Error( 'backup_writable_error', __( 'It is not possible to create a temporary file, the backup folder is not writable.', 'robin-image-optimizer' ) );
	}

	/**
	 * Резервное копирование файла аттачмента.
	 *
	 * @param WIO_Attachment $wio_attachment   аттачмент.
	 * @param string         $image_size       Размер(thumbnail, medium ... ).
	 * @param bool           $recorded         The queue row of the attachment says that it is backed up.
	 *
	 * @return bool|WP_Error The error "backup_copy_failed" when the copy is not complete.
	 */
	protected function backupAttachmentSize( WIO_Attachment $wio_attachment, $image_size = '', $recorded = false ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- existing name.
		if ( $image_size ) {
			$original_file = $wio_attachment->getImageSizePath( $image_size );
		} else {
			$original_file = $wio_attachment->get( 'path' );
		}

		$backup_dir = $this->getAttachmentBackupDir( $wio_attachment->get( 'attachment_meta' ) );

		// проверить запись в папку
		if ( is_wp_error( $backup_dir ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to create backup dir, error: %s', $backup_dir->get_error_message() ) );

			return $backup_dir;
		}

		if ( ! $original_file ) {
			// бывает такое, что размера превьюшки нет в базе данных.
			// это не считается ошибкой, поэтому сразу пропускаем
			return false;
		}

		// A link to nothing is refused, not skipped as a missing size: it can point out of the uploads.
		if ( is_link( $original_file ) && ! file_exists( $original_file ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Refused %s: it is a link to a missing file.', $original_file ) );

			// translators: %s: Image file name.
			return new WP_Error( 'backup_copy_failed', sprintf( __( 'Could not back up %s.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
		}

		// A size without its file is skipped, as a size without metadata.
		if ( $image_size && ! is_file( $original_file ) ) {
			return false;
		}

		$backup_file = $backup_dir . wp_basename( $original_file );

		if ( ! $this->is_safe_pair( $original_file, $backup_file ) ) {
			// translators: %s: Image file name.
			return new WP_Error( 'backup_copy_failed', sprintf( __( 'Could not back up %s.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
		}

		// An empty file has nothing to keep: the optimizer rejects it as a damaged image, which is a permanent
		// failure, not a backup failure. A backup from an earlier run stays and still counts.
		if ( 0 === (int) @filesize( $original_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is 0.
			return $this->is_usable_backup( $backup_file );
		}

		// The backup is of the original. A new optimization of the item (for a regenerated size, or after a run that
		// stopped after the resize) must not replace it with the changed file.
		if ( \WRIO\Storage\Store::keeps_backup( 'attachment', (int) $wio_attachment->get( 'id' ), $image_size ? $image_size : 'original', $original_file, $backup_file, $recorded ) ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Kept the existing backup %s.', $backup_file ) );

			// False for an optimized file without a backup: it is not backed up.
			return $this->is_usable_backup( $backup_file );
		}

		return $this->backup_file( $original_file, $backup_file, $this->checked_source, $this->checked_backup_folder );
	}

	/**
	 * Make a complete backup without exposing a partial copy at the destination.
	 *
	 * @param string           $original_file Source file.
	 * @param string           $backup_file   Backup destination.
	 * @param array|false|null $checked       The device and inode that a check found for the source, false when it
	 *                                        found no file (refused), or null for no check. The copy is from this
	 *                                        file only: a link swapped in after the check is refused.
	 * @param string           $folder        The real folder of the backup at the check, or '' for no check: a
	 *                                        folder swapped for a link after the check is refused.
	 *
	 * @phpstan-param array{0: int, 1: int}|false|null $checked
	 *
	 * @return true|WP_Error
	 */
	protected function backup_file( string $original_file, string $backup_file, $checked = null, string $folder = '' ) {
		// Copy to a temporary file and rename it: the backup path never holds a partial copy.
		$temp_file = @tempnam( dirname( $backup_file ), 'rio-backup-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is returned.
		$source    = self::open( $original_file, 'rb' );

		try {
			// The file that the handle opened, and the one the check found: the same inode.
			$opened      = $source ? $source->fstat() : false;
			$same        = $opened && ( null === $checked || self::file_id( $opened ) === $checked );
			$source_size = $opened ? (int) $opened['size'] : 0;

			// The temporary file must be in the checked folder. Optimizer::commit() renames it over the backup path.
			if ( ! $temp_file || ! $source || ! $same || 0 === $source_size || ( '' !== $folder && realpath( dirname( $temp_file ) ) !== $folder ) || ! self::copy_from_handle( $source, $temp_file ) || filesize( $temp_file ) !== $source_size || ! \WRIO\V2\Optimizer::commit( $temp_file, $backup_file ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to copy %s to %s.', $original_file, $backup_file ) );

				// translators: %s: Image file name.
				return new WP_Error( 'backup_copy_failed', sprintf( __( 'Could not back up %s.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
			}
		} finally {
			$source = null;

			if ( $temp_file && is_file( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
		}

		return true;
	}

	/**
	 * Open a file, or null when it cannot be opened.
	 *
	 * @param string $file File.
	 * @param string $mode fopen() mode.
	 *
	 * @return SplFileObject|null
	 */
	protected static function open( string $file, string $mode ) {
		if ( 'rb' === $mode && ! is_readable( $file ) ) {
			return null;
		}

		try {
			return new SplFileObject( $file, $mode );
		} catch ( RuntimeException | LogicException $e ) {
			return null;
		}
	}

	/**
	 * Copy an open file to a path.
	 *
	 * @param SplFileObject $source      Open source file.
	 * @param string        $destination Destination file.
	 *
	 * @return bool
	 */
	protected static function copy_from_handle( SplFileObject $source, string $destination ): bool {
		$target = self::open( $destination, 'wb' );

		if ( ! $target ) {
			return false;
		}

		while ( ! $source->eof() ) {
			$chunk = $source->fread( MB_IN_BYTES );

			if ( false === $chunk || strlen( $chunk ) !== $target->fwrite( $chunk ) ) {
				return false;
			}
		}

		$flushed = $target->fflush();

		// A write through a handle leaves the stat cache of the path as it was: filesize() would see the empty file.
		clearstatcache( true, $destination );

		return $flushed;
	}

	/**
	 * Copy a file through one open handle, and only when it is the file that a check found.
	 *
	 * @param string           $source      Source file.
	 * @param string           $destination Destination file.
	 * @param array|false|null $checked     The device and inode that the check found, false when it found no file
	 *                                      (refused), or null for no check.
	 *
	 * @phpstan-param array{0: int, 1: int}|false|null $checked
	 *
	 * @return bool
	 */
	protected function copy_checked( string $source, string $destination, $checked = null ): bool {
		$handle = self::open( $source, 'rb' );

		return $handle && ( null === $checked || self::file_id( $handle->fstat() ) === $checked ) && self::copy_from_handle( $handle, $destination );
	}

	/**
	 * Replace a file with a copy of another one. The copy goes to a temporary file in the same folder, then rename()
	 * puts it at the path: rename() replaces a link there, and does not write through it.
	 *
	 * @param string           $source    Source file.
	 * @param string           $file      File to replace.
	 * @param array|false|null $source_id What a check found for the source (see copy_checked()).
	 * @param string           $folder    The real folder of the file at the check, or '' for no check: a folder
	 *                                    swapped for a link after the check is refused.
	 *
	 * @phpstan-param array{0: int, 1: int}|false|null $source_id
	 *
	 * @return bool
	 */
	protected function replace_file( string $source, string $file, $source_id = null, string $folder = '' ): bool {
		// REST, cron and WP-CLI restores do not load the admin file functions.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// wp_tempnam() adds no separator after the folder.
		$temp_file = wp_tempnam( 'rio-restore', trailingslashit( dirname( $file ) ) );

		// Optimizer::commit() gives the copy the permissions of the current file and renames it over the path.
		if ( $temp_file && $this->copy_checked( $source, $temp_file, $source_id ) && ( '' === $folder || realpath( dirname( $temp_file ) ) === $folder ) && \WRIO\V2\Optimizer::commit( $temp_file, $file ) ) {
			return true;
		}

		if ( $temp_file && is_file( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		return false;
	}

	/**
	 * The backup of the main file or of a size. Nothing is copied yet.
	 *
	 * @param WIO_Attachment $wio_attachment   аттачмент
	 * @param string|null    $image_size       Размер(thumbnail, medium ... )
	 *
	 * @return array{0: string, 1: string, 2: array{0: int, 1: int}|false, 3: array{0: int, 1: int}|false, 4: string}|false|WP_Error
	 *         The backup file, the file it restores, and what the check found for them (see $checked_source), or
	 *         false for a size without a backup.
	 */
	protected function find_backup( WIO_Attachment $wio_attachment, $image_size = null ) {

		if ( ! empty( $image_size ) ) {
			$original_file = $wio_attachment->getImageSizePath( $image_size );
		} else {
			$original_file = $wio_attachment->get( 'path' );
		}

		$backup_dir = $this->getAttachmentBackupDir( $wio_attachment->get( 'attachment_meta' ) );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		if ( empty( $original_file ) ) {
			return $image_size ? false : new WP_Error( 'backup_missing', __( 'The original image path is missing.', 'robin-image-optimizer' ) );
		}

		$backup_file = $backup_dir . wp_basename( $original_file );

		if ( ! is_file( $backup_file ) ) {
			if ( $image_size ) {
				// A size that was not optimized, or was added after the backup, keeps its current file.
				WRIO_Plugin::app()->logger->info( sprintf( 'Skipped restore of size %s, there is no backup file: %s', $image_size, $backup_file ) );

				return false;
			}

			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to restore from a backup. There is no file, attachment id: %s, backup file: %s', $wio_attachment->get( 'id' ), $backup_file ) );

			// translators: %s: Image file name.
			return new WP_Error( 'backup_missing', sprintf( __( 'The backup for %s is missing.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
		}

		// After the check above: an image without a backup copies nothing, even when its folder is gone.
		if ( ! $this->is_safe_pair( $original_file, $backup_file ) ) {
			// translators: %s: Image file name.
			return new WP_Error( 'restore_copy_failed', sprintf( __( 'Could not restore %s from its backup.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
		}

		return [ $backup_file, $original_file, $this->checked_backup, $this->checked_source, $this->checked_folder ];
	}

	/**
	 * Get the backup file path for an attachment size if it exists.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $size          The image size (e.g., 'original', 'thumbnail', 'medium').
	 *
	 * @return string|null The backup file path if it exists, null otherwise.
	 * @since  1.0.0
	 */
	public function getAttachmentBackupPath( $attachment_id, $size = 'original' ) {
		$attachment_meta = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $attachment_meta ) || ! isset( $attachment_meta['file'] ) ) {
			return null;
		}

		$backup_dir = $this->getAttachmentBackupDir( $attachment_meta );

		if ( is_wp_error( $backup_dir ) ) {
			return null;
		}

		if ( 'original' === $size ) {
			$filename = wp_basename( $attachment_meta['file'] );
		} elseif ( isset( $attachment_meta['sizes'][ $size ]['file'] ) ) {
			$filename = $attachment_meta['sizes'][ $size ]['file'];
		} else {
			return null;
		}

		$backup_path = $backup_dir . $filename;

		return file_exists( $backup_path ) ? $backup_path : null;
	}

	/**
	 * Удаляем резервные копии аттачмента
	 *
	 * @param int $attachment_id   аттачмент id
	 *
	 * @return bool|WP_Error
	 */
	public function removeAttachmentBackup( $attachment_id ) {
		$attachment_meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $attachment_meta['file'] ) ) {
			return new WP_Error( 'missing_metadata', __( 'The attachment metadata has no file.', 'robin-image-optimizer' ) );
		}
		$backup_dir = $this->getAttachmentBackupDir( $attachment_meta );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$files = [ wp_basename( $attachment_meta['file'] ) ];

		if ( isset( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) {
			$files = array_merge( $files, wp_list_pluck( $attachment_meta['sizes'], 'file' ) );
		}

		// Sizes can share one file. A size that was not optimized (not in the thumbnail setting) has no backup.
		foreach ( array_unique( $files ) as $file ) {
			$backup_file = $backup_dir . $file;

			if ( ! is_file( $backup_file ) ) {
				continue;
			}

			wp_delete_file( $backup_file );

			if ( file_exists( $backup_file ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to delete backup file %s of attachment #%s', $backup_file, $attachment_id ) );
			}
		}

		return true;
	}

	/**
	 * alternateStorage
	 *
	 * @param array $servers
	 *
	 * @return array
	 */
	public static function alternateStorage( $servers ) {

		return $servers;
	}

	/**
	 * @since  1.3.0
	 *
	 * @param string $dir
	 *
	 * @return string|WP_Error
	 */
	protected function mkdir( $dir ) {
		WRIO_Plugin::app()->logger->info( sprintf( 'Try to create backup directory. Backup dir: (%s)', $dir ) );

		if ( ! wp_mkdir_p( $dir ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to create backup directory (%s) as mkdir() failed', $dir ) );

			return new WP_Error( 'mkdir_failed', sprintf( 'Unable to create backup folder (%s) as mkdir() failed.', $dir ) );
		}

		return $dir;
	}

	/**
	 * @since  1.3.0
	 *
	 * @param string $backup_file
	 * @param string $original_file
	 *
	 * @return bool
	 */
	protected function restore_file( $backup_file, $original_file ) {
		if ( $this->is_usable_backup( $backup_file ) ) {
			if ( ! @copy( $backup_file, $original_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is logged and returned.
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to swap original file (%s) with %s as copy() failed', $backup_file, $original_file ) );

				return false;
			}

			if ( ! @unlink( $backup_file ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to delete backup file (%s) as unlink() failed', $backup_file ) );

				return false;
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'Restored file: %s.', $backup_file ) );

			return true;
		}

		WRIO_Plugin::app()->logger->error( sprintf( 'Unable to restore from a backup. There is no file, or it is empty (%s).', $backup_file ) );

		return false;
	}

	/**
	 * Whether the file is in the uploads folder and its backup in the backup folder, after symbolic links. A link out of
	 * these folders would make the backup read, or the restore write, a file of another place on the server.
	 *
	 * @param string $original_file The file of the attachment.
	 * @param string $backup_file   Its backup.
	 *
	 * @return bool
	 */
	protected function is_safe_pair( string $original_file, string $backup_file ): bool {
		$real                        = self::real_path_inside( $original_file, wp_upload_dir()['basedir'] );
		$this->checked_source        = false !== $real && file_exists( $real ) ? self::file_id( stat( $real ) ) : false;
		$this->checked_folder        = false === $real ? '' : (string) realpath( dirname( $real ) );
		$this->checked_backup        = false;
		$this->checked_backup_folder = '';

		if ( false === $real ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Refused %s: it is outside the uploads folder.', $original_file ) );

			return false;
		}

		if ( ! $this->is_safe_backup( $backup_file ) ) {
			return false;
		}

		$this->checked_backup        = file_exists( $backup_file ) ? self::file_id( stat( $backup_file ) ) : false;
		$this->checked_backup_folder = (string) realpath( dirname( $backup_file ) );

		return true;
	}

	/**
	 * The device and inode of a file, from stat() or fstat().
	 *
	 * @param array<int|string, int>|false $stat Result of stat() or fstat().
	 *
	 * @return array{0: int, 1: int}|false False for no file.
	 */
	protected static function file_id( $stat ) {
		return $stat ? [ (int) $stat['dev'], (int) $stat['ino'] ] : false;
	}

	/**
	 * Whether a backup is in the backup folder, after symbolic links. The default folder (uploads/wio_backup) must
	 * also be in the uploads folder; a folder that the "wbcr/rio/backup/backup_dir" filter sets can be anywhere.
	 *
	 * @param string $backup_file Backup file.
	 *
	 * @return bool
	 */
	protected function is_safe_backup( string $backup_file ): bool {
		$backup_dir = $this->getBackupDir();
		$uploads    = $this->wp_upload_dir['basedir'];
		$default    = trailingslashit( wp_normalize_path( trailingslashit( $uploads ) . self::BACKUP_DIR_NAME ) );
		$safe       = ! is_wp_error( $backup_dir )
			&& ( trailingslashit( wp_normalize_path( $backup_dir ) ) !== $default || self::is_inside( $backup_dir, $uploads ) )
			&& self::is_inside( $backup_file, $backup_dir );

		if ( ! $safe ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Refused the backup %s: it is outside the backup folder, or the backup folder is outside the uploads folder.', $backup_file ) );
		}

		return $safe;
	}

	/**
	 * Whether a path is in a folder, after symbolic links. A path that does not exist yet counts by its folder; a link
	 * to nothing does not count.
	 *
	 * @param string $path Path.
	 * @param string $dir  Folder.
	 *
	 * @return bool
	 */
	protected static function is_inside( string $path, string $dir ): bool {
		return false !== self::real_path_inside( $path, $dir );
	}

	/**
	 * The real path of a path in a folder, after symbolic links, or false when it is not in the folder. A path that
	 * does not exist yet counts by its folder; a link to nothing does not count.
	 *
	 * @param string $path Path.
	 * @param string $dir  Folder.
	 *
	 * @return string|false
	 */
	protected static function real_path_inside( string $path, string $dir ) {
		clearstatcache( true, $path );

		$root   = realpath( $dir );
		$exists = file_exists( $path ) || is_link( $path );
		$real   = $exists ? realpath( $path ) : realpath( dirname( $path ) );

		if ( false === $root || false === $real || 0 !== strpos( trailingslashit( wp_normalize_path( $real ) ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return false;
		}

		return $exists ? $real : trailingslashit( $real ) . wp_basename( $path );
	}

	/**
	 * Whether a backup can be restored: it is a file and it is not empty.
	 *
	 * @param string $backup_file Backup file.
	 *
	 * @return bool
	 */
	protected function is_usable_backup( $backup_file ): bool {
		clearstatcache( true, $backup_file );

		return is_file( $backup_file ) && 0 < (int) @filesize( $backup_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is not usable.
	}
}
