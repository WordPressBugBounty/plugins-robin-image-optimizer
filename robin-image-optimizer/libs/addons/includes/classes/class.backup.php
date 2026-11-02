<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с резервным копированием изображений
 *
 * @version       1.0
 */
class WRIOP_Backup extends WIO_Backup {

	const CF_BACKUP_DIR_NAME      = 'custom-folders';
	const NEXTGEN_BACKUP_DIR_NAME = 'nextgen-gallery';

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    object
	 */
	protected static $_instance;

	/**
	 * Получает путь к папке с резервными копиями
	 *
	 * @param array $gallery_meta   метаданные аттачмента
	 *
	 * @return string
	 */
	public function getNextgenBackupDir( $gallery_meta ) {
		$backup_dir  = $this->getBackupDir();
		$backup_dir .= self::NEXTGEN_BACKUP_DIR_NAME . '/' . $gallery_meta->gid;

		if ( ! is_dir( $backup_dir ) ) {
			$backup_dir = $this->mkdir( $backup_dir );

			if ( is_wp_error( $backup_dir ) ) {
				return $backup_dir;
			}
		}

		return trailingslashit( $backup_dir );
	}

	/**
	 * Делаем резервную копию NextGEN
	 *
	 * @param WRIO_Image_Nextgen $nextgen_image   аттачмент
	 *
	 * @return bool|WP_Error
	 */
	public function backupNextgen( $nextgen_image ) {
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );

		if ( ! $backup_origin_images ) {
			return false; // если бекап не требуется
		}

		$original_file           = $nextgen_image->get( 'path' );
		$original_thumbnail_file = $nextgen_image->get( 'thumbnail_path' );
		$backup_dir              = $this->getNextgenBackupDir( $nextgen_image->get( 'gallery_meta' ) );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$backup_file           = $backup_dir . $nextgen_image->get( 'file' );
		$backup_thumbnail_file = $backup_dir . $nextgen_image->get( 'thumbnail_file' );
		$image_id              = (int) $nextgen_image->get( 'id' );

		// A gallery folder under the backup folder that links out of it would get the copies.
		if ( ! $this->is_safe_backup( $backup_file ) || ! $this->is_safe_backup( $backup_thumbnail_file ) ) {
			// translators: %s: Image file name.
			return new WP_Error( 'backup_copy_failed', sprintf( __( 'Could not back up %s.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
		}

		// See WRIO\Storage\Store::keeps_backup(): an optimized file keeps the backup of its original. An empty file
		// has nothing to keep: the optimizer rejects it as a damaged image, as in backupCFImage().
		if ( is_file( $original_file ) && 0 < wrio_get_file_size( $original_file ) && ! \WRIO\Storage\Store::keeps_backup( 'nextgen', $image_id, 'original', $original_file, $backup_file ) ) {
			$copied = $this->backup_file( $original_file, $backup_file );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		if ( is_file( $original_thumbnail_file ) && ! \WRIO\Storage\Store::keeps_backup( 'nextgen', $image_id, 'thumbnail', $original_thumbnail_file, $backup_thumbnail_file ) ) {
			$copied = $this->backup_file( $original_thumbnail_file, $backup_thumbnail_file );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		// An optimized file gets no backup (backups turned on after its optimization): it is not backed up.
		return $this->is_usable_backup( $backup_file ) || $this->is_usable_backup( $backup_thumbnail_file );
	}

	/**
	 * Restore NextGen images piece by piece.
	 *
	 * @param int $limit     Limit on number of NextGen image to restore. Default: 50, maximum 1000.
	 *
	 * @return array {
	 *     Result of process: how many images restored and how many remain.
	 * @type int  $processed Count of processed images.
	 * @type int  $remane    Count of remained images to be processed.
	 * @type int  $total     Count of images left to restore, with the failed ones.
	 * }
	 */
	public function restoreAllNextGen( $limit = 50 ) {
		return $this->restore_all_items(
			'nextgen',
			$limit,
			static function ( stdClass $row ) {
				return ( new WRIO_Image_Nextgen( $row->object_id ) )->restore();
			}
		);
	}

	/**
	 * Restore Custom Folders piece by piece.
	 *
	 * @param int $limit     Limit on number of Custom Folders to restore. Default: 50, maximum 1000.
	 *
	 * @return array {
	 *     Result of process: how many folders restored and how many remain.
	 * @type int  $processed Count of processed folders.
	 * @type int  $remane    Count of remained folders to be processed.
	 * @type int  $total     Count of images left to restore, with the failed ones.
	 * }
	 */
	public function restoreAllCustomFolders( $limit = 50 ) {
		$result = $this->restore_all_items(
			'cf_image',
			$limit,
			static function ( stdClass $row ) {
				// A folder image is known by the ID of its queue row.
				return ( new WRIO_Folder_Image( (int) $row->id, $row ) )->restore();
			}
		);

		if ( 0 === $result['remane'] ) {
			$custom_folders = WRIO_Custom_Folders::get_instance();
			$folders        = $custom_folders->getFolders();

			if ( ! empty( $folders ) ) {
				foreach ( $folders as $folder ) {
					$folder->reCountOptimizedFiles();
				}
				$custom_folders->saveFolders();
			}
		}

		return $result;
	}

	/**
	 * Restore the queue rows of one type that have something to restore, past the rows that fail to restore.
	 *
	 * A failed restore leaves the row unchanged, so the backup can be restored later. It is not
	 * counted in remane, so "Restore all" can finish. Total counts the same rows as remane, with
	 * the failed ones, so the progress stays between 0 and 100 %.
	 *
	 * @param string   $item_type Queue item type.
	 * @param mixed    $limit     Maximum number of rows to restore. Default: 50, maximum 1000.
	 * @param callable $restore   Restores one queue row. Returns a WP_Error on failure.
	 *
	 * @phpstan-param callable(stdClass): (bool|WP_Error) $restore
	 *
	 * @return array{processed: int, remane: int, total: int}
	 */
	private function restore_all_items( string $item_type, $limit, callable $restore ): array {
		global $wpdb;

		$limit = is_numeric( $limit ) && $limit > 0 && $limit <= 1000 ? (int) $limit : 50;

		$queue_table = RIO_Process_Queue::table_name();
		// A folder image is known by the ID of its queue row. Only an image with a backup: an image optimized without
		// one has nothing to put back. Also a failed image with a backup: its NextGEN thumbnail can be optimized. The
		// restore deletes or resets the row, so the next request does not select it again.
		$backed_up  = WRIO_Media_Library::backup_sql( $queue_table, "{$queue_table}.item_type", 'cf_image' === $item_type ? 'id' : 'object_id' );
		$restorable = "( `result_status` = %s OR `result_status` = 'error' ) AND {$backed_up}";
		$select_sql = "SELECT * FROM {$queue_table} WHERE `item_type` = %s AND {$restorable} AND `id` > %d ORDER BY `id` LIMIT %d";
		$processed  = 0;
		$failed     = 0;
		$last_id    = 0;

		while ( $processed < $limit ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $item_type, RIO_Process_Queue::STATUS_SUCCESS, $last_id, $limit - $processed ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- plugin table, the values are placeholders.

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id  = (int) $row->id;
				$restored = $restore( $row );

				if ( is_wp_error( $restored ) ) {
					++$failed;
					WRIO_Plugin::app()->logger->error( sprintf( 'Failed to restore %s ID: %s with message: %s', $item_type, $row->id, $restored->get_error_message() ) );
				} else {
					++$processed;
				}
			}
		}

		$count_sql = "SELECT COUNT(*) FROM {$queue_table} WHERE `item_type` = %s AND {$restorable}";
		$remaining = $wpdb->get_var( $wpdb->prepare( $count_sql, $item_type, RIO_Process_Queue::STATUS_SUCCESS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- plugin table, the values are placeholders.

		if ( null === $remaining ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to get remained number of %s by SQL: %s', $item_type, $count_sql ) );
		}

		return [
			'processed' => $processed,
			'remane'    => max( 0, (int) $remaining - $failed ),
			'total'     => (int) $remaining,
		];
	}

	/**
	 * Восстанавливаем из резервной копии NextGEN
	 *
	 * @param WRIO_Image_Nextgen $nextgen_image   аттачмент
	 *
	 * @return true|WP_Error
	 */
	public function restoreNextgen( $nextgen_image ) {
		$copies   = $this->nextgen_backups( $nextgen_image );
		$replaced = $this->replaced_files( 'nextgen', (int) $nextgen_image->get( 'id' ), $copies );
		$copies   = array_diff_key( $copies, $replaced );

		$rows = \WRIO\Storage\Store::rows( 'nextgen', (int) $nextgen_image->get( 'id' ) );
		// Rows copied from the old queue (legacy) know only the backup flag of the queue row.
		$legacy = empty( $rows ) || ! empty( $rows['original|optimized']['legacy'] );

		// Every backup is checked first: a missing or empty one changes no file and keeps the rows.
		foreach ( $copies as $size => list( $backup ) ) {
			if ( $this->is_usable_backup( $backup ) ) {
				continue;
			}

			$row      = $rows[ $size . '|optimized' ] ?? null;
			$recorded = $legacy ? (bool) $nextgen_image->getOptimizationData()->get_is_backed_up() : ( $row && \WRIO\Storage\Store::OK === $row['status'] && 1 === (int) $row['backed_up'] );

			// A size optimized while backups were off has nothing to put back: the other size is restored.
			if ( ! is_file( $backup ) && ! $recorded ) {
				unset( $copies[ $size ] );
				continue;
			}

			$error_msg = sprintf( 'Unable to restore from a backup. There is no file, or it is empty (%s).', $backup );
			WRIO_Plugin::app()->logger->error( sprintf( '%s, Nextgen image id: %s', $error_msg, $nextgen_image->get( 'id' ) ) );

			return new WP_Error( 'file_not_exists', $error_msg );
		}

		// An image optimized without a backup has nothing to put back, as restoreCFImage() says.
		if ( empty( $copies ) && empty( $replaced ) ) {
			return new WP_Error( 'no_backup', __( 'The original of this image is not kept, so it cannot be restored.', 'robin-image-optimizer' ) );
		}

		$restored = $this->copy_backups( array_values( $copies ) );

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		foreach ( array_merge( $copies, $replaced ) as list( $backup ) ) {
			wp_delete_file( $backup );
		}

		return true;
	}

	/**
	 * The copies whose file was replaced after its optimization (by FTP, for example), as in
	 * WIO_Backup::restoreAttachment(). Such a file is a new original: its old backup must not go over it. The
	 * restore then forgets its rows (Store::restored()), and deletes the old backup.
	 *
	 * @param string                                        $source    cf_image or nextgen.
	 * @param int                                           $object_id Item ID.
	 * @param array<array-key, array{0: string, 1: string}> $copies Backups, each with the file it restores.
	 *
	 * @phpstan-param 'cf_image'|'nextgen' $source
	 *
	 * @return array<array-key, array{0: string, 1: string}> The replaced copies, with their keys.
	 */
	private function replaced_files( string $source, int $object_id, array $copies ): array {
		$rows = \WRIO\Storage\Store::rows( $source, $object_id );

		return array_filter(
			$copies,
			static function ( $copy ) use ( $rows ) {
				$hash = \WRIO\Storage\Store::path_hash( $copy[1] );

				foreach ( $rows as $row ) {
					if ( 'optimized' !== $row['format'] || $hash !== $row['path_hash'] ) {
						continue;
					}

					$state = \WRIO\Storage\Store::state( $row, $copy[1] );

					// The full hash reads the whole file: only here, right before the backup would overwrite it.
					if ( \WRIO\Storage\Store::STALE === $state || ( \WRIO\Storage\Store::OK === $state && \WRIO\Storage\Store::replaced_inside( $row, $copy[1] ) ) ) {
						WRIO_Plugin::app()->logger->info( sprintf( 'Kept %s: it was replaced after the optimization. Its old backup %s is deleted.', $copy[1], $copy[0] ) );

						return true;
					}
				}

				return false;
			}
		);
	}

	/**
	 * Deletes the backups of a NextGEN image, for a picture that NextGEN deleted.
	 *
	 * @param WRIO_Image_Nextgen $nextgen_image NextGEN image.
	 *
	 * @return void
	 */
	public function deleteNextgenBackups( $nextgen_image ) {
		foreach ( $this->nextgen_backups( $nextgen_image ) as list( $backup ) ) {
			wp_delete_file( $backup );
		}
	}

	/**
	 * The backup of a NextGEN image and of its thumbnail, each with the file it restores.
	 *
	 * BackupNextgen() makes no thumbnail backup when the thumbnail is missing at the optimization. So the
	 * thumbnail is in when its backup file is there, or when its backup is expected and lost: the state rows
	 * have a thumbnail row (Robin optimized it), or, with only rows copied from the old queue, the thumbnail
	 * file exists now. A missing or empty backup that stays in makes the restore refuse the image.
	 *
	 * @param WRIO_Image_Nextgen $nextgen_image NextGEN image.
	 *
	 * @return array<string, array{0: string, 1: string}> By size name: original, thumbnail.
	 */
	private function nextgen_backups( $nextgen_image ) {
		$backup_dir            = $this->getNextgenBackupDir( $nextgen_image->get( 'gallery_meta' ) );
		$backup_thumbnail_file = $backup_dir . $nextgen_image->get( 'thumbnail_file' );
		$thumbnail_file        = (string) $nextgen_image->get( 'thumbnail_path' );
		$copies                = [ 'original' => [ $backup_dir . $nextgen_image->get( 'file' ), (string) $nextgen_image->get( 'path' ) ] ];

		$rows = \WRIO\Storage\Store::rows( 'nextgen', (int) $nextgen_image->get( 'id' ) );
		// Rows copied from the old queue (legacy) know nothing of the thumbnail.
		$legacy              = empty( $rows ) || ! empty( $rows['original|optimized']['legacy'] );
		$thumbnail_backed_up = isset( $rows['thumbnail|optimized'] ) || ( $legacy && is_file( $thumbnail_file ) );

		if ( file_exists( $backup_thumbnail_file ) || $thumbnail_backed_up ) {
			$copies['thumbnail'] = [ $backup_thumbnail_file, $thumbnail_file ];
		}

		return $copies;
	}

	/**
	 * Получает путь к папке с резервными копиями
	 *
	 * @param string $image_abs_path   абсолютный путь к файлу картинки
	 *
	 * @return string|WP_Error
	 */
	public function getCFBackupDir( $image_abs_path ) {
		$backup_root = $this->getBackupDir();

		// The filter of getBackupDir() can name a folder that does not exist yet.
		if ( ! is_wp_error( $backup_root ) && ! is_dir( $backup_root ) ) {
			$backup_root = $this->mkdir( $backup_root );
		}

		if ( is_wp_error( $backup_root ) ) {
			return $backup_root;
		}

		$image_abs_path = wp_normalize_path( $image_abs_path );
		$wp_abs_path    = wp_normalize_path( ABSPATH );

		// Get all subfolders in which the image is stored.
		// This is necessary to create an alternate subfolders
		// in directory where they are stored in backups.
		$subfolders = str_replace( $wp_abs_path, '', dirname( $image_abs_path ) );

		// The filtered backup root can be outside the image root: a symlink must not lead out of it.
		$backup_dir = WRIO_Custom_Folders::validate_path( trailingslashit( $backup_root ) . self::CF_BACKUP_DIR_NAME . '/' . $subfolders, true, $backup_root );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		if ( ! is_dir( $backup_dir ) ) {
			$backup_dir = $this->mkdir( $backup_dir );

			if ( is_wp_error( $backup_dir ) ) {
				return $backup_dir;
			}
		}

		return trailingslashit( $backup_dir );
	}

	/**
	 * Делаем резервную копию Custom Folder Image
	 *
	 * @param WRIO_Folder_Image $folder_image   Custom Folder Image
	 *
	 * @return bool|WP_Error
	 */
	public function backupCFImage( $folder_image ) {
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );

		if ( ! $backup_origin_images ) {
			return false; // если бекап не требуется
		}

		$original_file = WRIO_Custom_Folders::validate_path( $folder_image->get( 'path' ), true, $folder_image->get_root() );

		if ( is_wp_error( $original_file ) ) {
			return $original_file;
		}

		$backup_file = $this->get_cf_backup_file( $folder_image->get( 'path' ) );

		if ( is_wp_error( $backup_file ) ) {
			return $backup_file;
		}

		// An empty file has nothing to keep: the optimizer rejects it as a damaged image, as in
		// WIO_Backup::backupAttachmentSize(). A backup from an earlier run stays and still counts.
		if ( 0 === wrio_get_file_size( $original_file ) ) {
			return $this->is_usable_backup( $backup_file );
		}

		// See WRIO\Storage\Store::keeps_backup(): an optimized file keeps the backup of its original.
		if ( ! \WRIO\Storage\Store::keeps_backup( 'cf_image', (int) $folder_image->get( 'id' ), 'original', $original_file, $backup_file ) ) {
			$copied = $this->backup_file( $original_file, $backup_file );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		// An optimized file gets no backup (backups turned on after its optimization): it is not backed up.
		return $this->is_usable_backup( $backup_file );
	}

	/**
	 * Восстанавливаем из резервной копии Custom Folder Image
	 *
	 * @param WRIO_Folder_Image $folder_image   Custom Folder Image
	 *
	 * @return bool|WP_Error
	 */
	public function restoreCFImage( $folder_image ) {
		// The folder was renamed or moved: the backup belongs to the old path, so it stays for when the folder is back.
		if ( ! is_dir( dirname( (string) $folder_image->get( 'path' ) ) ) ) {
			return new WP_Error(
				'folder_missing',
				// translators: %s: Folder path.
				sprintf( __( 'The folder %s does not exist any more. To restore its images, put it back at this path, then restore them before you add the folder again.', 'robin-image-optimizer' ), dirname( (string) $folder_image->get( 'path' ) ) )
			);
		}

		$original_file = WRIO_Custom_Folders::validate_path( $folder_image->get( 'path' ), true, $folder_image->get_root() );

		if ( is_wp_error( $original_file ) ) {
			return $original_file;
		}

		$backup_file = $this->get_cf_backup_file( $folder_image->get( 'path' ) );

		if ( is_wp_error( $backup_file ) ) {
			return $backup_file;
		}

		if ( $this->replaced_files( 'cf_image', (int) $folder_image->get( 'id' ), [ [ $backup_file, $original_file ] ] ) ) {
			wp_delete_file( $backup_file );

			return true;
		}

		if ( ! $this->is_usable_backup( $backup_file ) ) {
			// An image optimized without a backup has nothing to put back: its file and its state stay.
			if ( ! is_file( $backup_file ) && ! $this->cf_is_backed_up( $folder_image ) ) {
				return new WP_Error( 'no_backup', __( 'The original of this image is not kept, so it cannot be restored.', 'robin-image-optimizer' ) );
			}

			$error_msg = sprintf( 'Unable to restore from a backup. There is no file, or it is empty (%s).', $backup_file );
			WRIO_Plugin::app()->logger->error( sprintf( '%s, Custom folder image id: %s', $error_msg, $folder_image->get( 'id' ) ) );

			return new WP_Error( 'file_not_exists', $error_msg );
		}

		if ( ! $this->restore_file( $backup_file, $original_file ) ) {
			// translators: %s: Image file name.
			return new WP_Error( 'restore_copy_failed', sprintf( __( 'Could not restore %s from its backup.', 'robin-image-optimizer' ), wp_basename( $original_file ) ) );
		}

		return true;
	}

	/**
	 * Whether the optimization of a custom-folder image made a backup, by its queue row or its state row.
	 *
	 * @param WRIO_Folder_Image $folder_image Custom Folder Image.
	 *
	 * @return bool
	 */
	private function cf_is_backed_up( $folder_image ): bool {
		$rows = \WRIO\Storage\Store::rows( 'cf_image', (int) $folder_image->get( 'id' ) );

		return (bool) $folder_image->getOptimizationData()->get_is_backed_up() || ! empty( $rows['original|optimized']['backed_up'] );
	}

	/**
	 * Backup file of a custom-folder image, inside the backup folder.
	 *
	 * @param string $original_file Path of the image, as stored: the backup folders follow it.
	 *
	 * @return string|WP_Error
	 */
	private function get_cf_backup_file( $original_file ) {
		$backup_dir = $this->getCFBackupDir( $original_file );

		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$backup_root = $this->getBackupDir();

		return WRIO_Custom_Folders::validate_path( $backup_dir . wp_basename( $original_file ), true, is_string( $backup_root ) ? $backup_root : $backup_dir );
	}
}
