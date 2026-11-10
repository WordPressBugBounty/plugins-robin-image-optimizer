<?php
/**
 * WordPress events of the state machine, and the daily clean-up.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\Storage;

use WIO_Attachment;
use WIO_Backup;
use RIO_Process_Queue;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns WordPress actions into state machine events.
 *
 * - The attachment metadata changes (regenerated thumbnails, an edit, a replaced file): the rows of each changed
 *   size become pending, and the next run processes those files once. Rows of a removed size go. A replaced main file makes its backup wrong, so it goes too.
 *   The plugin's own metadata updates (resize, restore) happen under the item lock and are ignored.
 * - An attachment is deleted: its converted files, its backup and its rows go, without the restore of before.
 *   Files that another attachment (a WPML copy) also uses stay.
 * - The license or the plan changes: the failures that depend on the plan are pending again.
 * - Every day: the history keeps 90 days and 100,000 rows at most, expired locks and rows of deleted items go.
 */
class Events {

	/**
	 * Files from before an edit that another attachment used at the delete: path => folder and file name. WordPress
	 * must not delete them with the attachment.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static $shared_files = [];

	/**
	 * Daily clean-up cron hook.
	 */
	const CLEANUP_HOOK = 'wrio_storage_cleanup';

	/**
	 * Days of history.
	 */
	const LOG_DAYS = 90;

	/**
	 * Rows of history at most.
	 */
	const LOG_ROWS = 100000;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_update_attachment_metadata', [ __CLASS__, 'metadata_updated' ], 10, 2 );
		// In every request: an attachment deleted with WP-CLI or from the front end leaves no files behind either.
		add_action( 'delete_attachment', [ __CLASS__, 'attachment_deleted' ] );
		add_filter( 'wp_delete_file', [ __CLASS__, 'keep_shared_file' ] );
		add_action( 'wrio_license_activated', [ __CLASS__, 'plan_changed' ] );
		add_action( 'wrio_license_activated', [ \WRIO\V2\Client::class, 'forget_stop' ] );
		add_action( 'wrio_license_deactivated', [ __CLASS__, 'plan_changed' ] );
		add_action( 'wrio_v2_plan_changed', [ __CLASS__, 'plan_changed' ] );
		add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanup' ] );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Mark the rows of changed sizes pending, add rows for new sizes, and forget removed sizes, with new attachment
	 * metadata.
	 *
	 * The filter runs before WordPress saves the metadata, so the saved metadata still lists the old files.
	 *
	 * @param array<string, mixed>|false $metadata      New metadata.
	 * @param int                        $attachment_id Attachment ID.
	 *
	 * @return array<string, mixed>|false The metadata, unchanged.
	 */
	public static function metadata_updated( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		// The plugin's own update (resize, restore), or nothing is stored for the attachment.
		if ( ! is_array( $metadata ) || Store::holds_lock( 'attachment', $attachment_id ) ) {
			return $metadata;
		}

		$rows = Store::rows( 'attachment', $attachment_id );

		if ( empty( $rows ) ) {
			return $metadata;
		}

		$before = new WIO_Attachment( $attachment_id );
		// With the sizes whose file is not on disk (offloaded): a size is removed only when the metadata drops it.
		$after   = ( new WIO_Attachment( $attachment_id, $metadata ) )->get_files( true, false );
		$changes = [];
		$paths   = [];
		$lost    = [];

		foreach ( $rows as $row ) {
			$size = (string) $row['size_name'];

			if ( isset( $changes[ $size ] ) ) {
				continue;
			}

			// From the saved metadata, also when the file is already gone.
			$old            = (string) ( 'original' === $size ? $before->get( 'path' ) : $before->getImageSizePath( $size ) );
			$new            = $after[ $size ]['path'] ?? '';
			$paths[ $size ] = $old;

			// "Restore image" undid an edit: the row describes the file at its path again.
			if ( '' !== $new && Store::undo_edit( $row, $new ) ) {
				continue;
			}

			if ( '' === $new ) {
				$changes[ $size ] = 'size_removed';
			} elseif ( '' !== $old && $old !== $new ) {
				$changes[ $size ] = 'edited';
			} elseif ( Store::PENDING === $row['status'] && Store::is_edit( $row ) ) {
				// An edit that is not processed yet, saved again: its rows keep the fingerprint of the file before
				// the edit for undo_edit(), so the other content is no replacement.
				continue;
			} elseif ( Store::source_changed( $row, $new ) ) {
				// The source has other content. A deleted converted file is not a replaced source.
				$changes[ $size ] = 'replaced';
			} elseif ( Store::OK === $row['status'] && 'optimized' !== $row['format'] && is_file( $new ) && ! file_exists( $new . '.' . $row['format'] ) ) {
				// Only with the source on disk: an offloaded image and its formats stay done.
				$lost[ $size ][] = (string) $row['format'];
			}
		}

		// A converted file was deleted, for example by a cleanup plugin: only that format is converted again.
		foreach ( array_diff_key( $lost, $changes ) as $size => $formats ) {
			Store::mark_pending( 'attachment', $attachment_id, 'format_lost', [ (string) $size ], $formats );
		}

		// A new size (a theme registered one, thumbnails regenerated for missing sizes) has no row yet.
		$known = array_flip( wp_list_pluck( $rows, 'size_name' ) );

		foreach ( array_diff_key( $after, $known ) as $size => $file ) {
			// The file of a removed size under a new name (sizes that share a file): its done rows move over.
			$renamed = array_search( $file['path'], array_intersect_key( $paths, array_filter( $changes, fn( $event ) => 'size_removed' === $event ) ), true );

			if ( false !== $renamed ) {
				$moved = array_values( array_filter( $rows, fn( $row ) => (string) $renamed === (string) $row['size_name'] ) );
				Store::rename_size( 'attachment', $attachment_id, (string) $renamed, (string) $size );
				unset( $changes[ $renamed ] );

				// The regeneration also rewrote the shared file: the rows under the new name are of the old content.
				if ( ! empty( $moved ) && Store::source_changed( $moved[0], $file['path'] ) ) {
					$changes[ $size ] = 'replaced';
					$paths[ $size ]   = $file['path'];
				}
				continue;
			}

			if ( ! is_file( $file['path'] ) ) {
				continue;
			}

			$outputs = array_merge( $file['optimize'] ? [ 'optimized' ] : [], \WRIO_Formats::get_enabled_formats() );

			if ( ! empty( $outputs ) ) {
				Store::add_pending( 'attachment', $attachment_id, (string) $size, $file['path'], $outputs );
			}
		}

		foreach ( $changes as $size => $event ) {
			if ( 'size_removed' === $event ) {
				Store::forget( 'attachment', $attachment_id, $event, [ (string) $size ] );
			} else {
				Store::mark_pending( 'attachment', $attachment_id, $event, [ (string) $size ] );
			}

			// A removed size whose file is gone has no use for its converted files. Kept files can still be linked.
			$old = $paths[ $size ];

			if ( 'size_removed' === $event && '' !== $old && ! file_exists( $old ) ) {
				self::delete_formats( $old );
			}
		}

		// The backups of the replaced files: a restore would put the old images back. The backups of the files that
		// did not change stay, or their originals would be lost.
		$replaced = array_keys( array_filter( $changes, fn( $event ) => 'replaced' === $event ) );
		$backup   = WIO_Backup::get_instance();

		if ( ! empty( $replaced ) ) {
			$directory = $backup->getAttachmentBackupDir( $before->getMetaData() );

			if ( ! is_wp_error( $directory ) ) {
				foreach ( $replaced as $size ) {
					$file = $directory . wp_basename( $paths[ $size ] );

					if ( is_file( $file ) ) {
						$backup->delete_backup( $file );
					}
				}
			}

			Store::backups_deleted( 'attachment', $attachment_id, array_map( 'strval', $replaced ) );
		}

		// The queue row has one backup flag: a restore of the main file needs its backup.
		if ( in_array( 'original', $replaced, true ) ) {
			$queue_row = ( new WIO_Attachment( $attachment_id ) )->getOptimizationData();

			if ( $queue_row->get_id() ) {
				$queue_row->set_is_backed_up( false );
				$queue_row->save();
			}
		}

		return $metadata;
	}

	/**
	 * Delete the converted files, the backup and the rows of a deleted attachment. WordPress deletes its files next.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public static function attachment_deleted( $attachment_id ) {
		$attachment = new WIO_Attachment( (int) $attachment_id );
		$queue_row  = $attachment->getOptimizationData();
		$rows       = Store::rows( 'attachment', (int) $attachment_id );
		$backup     = WIO_Backup::get_instance();

		$copies = self::copies( (int) $attachment_id );

		// Another attachment (a WPML copy) has the same files: their converted files, backups and done rows are its
		// too. Without the rows, a copy that was never processed would compress the optimized files again.
		if ( ! empty( $copies ) ) {
			Store::hand_over( 'attachment', (int) $attachment_id, $copies );
		} else {
			// Also the sizes whose file is already gone: their converted files can still be there.
			\WRIO\V2\Format_Rows::delete( $attachment->get_files( true, false ) );

			// Also a backup on disk without a flag: an earlier copy of the shared file left it to this attachment.
			$on_disk = is_file( (string) $backup->getAttachmentBackupPath( (int) $attachment_id ) );

			if ( $on_disk || $queue_row->get_is_backed_up() || in_array( 1, array_map( 'intval', wp_list_pluck( $rows, 'backed_up' ) ), true ) ) {
				$backup->removeAttachmentBackup( (int) $attachment_id );
			}
		}

		// Also with a copy of the edited file: a copy from before the edit uses the older files.
		self::delete_pre_edit_files( (int) $attachment_id, $backup );
		self::hand_over_pre_edit( (int) $attachment_id, $queue_row );

		if ( $queue_row->get_id() ) {
			$queue_row->delete();
		}

		Store::forget( 'attachment', (int) $attachment_id, 'deleted' );
	}

	/**
	 * Delete the converted files and the backups of the files from before an image edit. WordPress keeps those files
	 * in _wp_attachment_backup_sizes and deletes them with the attachment; the metadata has only the edited files.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param object|null $backup        WIO_Backup::get_instance().
	 *
	 * @return void
	 */
	private static function delete_pre_edit_files( int $attachment_id, $backup ) {
		$sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$file  = wrio_file_path( get_attached_file( $attachment_id ) );

		if ( ! is_array( $sizes ) || ! $file ) {
			return;
		}

		$dir        = trailingslashit( dirname( $file ) );
		$url        = trailingslashit( dirname( (string) wp_get_attachment_url( $attachment_id ) ) );
		$meta       = wp_get_attachment_metadata( $attachment_id );
		$backup_dir = $backup instanceof WIO_Backup && is_array( $meta ) ? $backup->getAttachmentBackupDir( $meta ) : '';
		$files      = [];

		foreach ( $sizes as $key => $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}

			// A name that PHP refuses (a null byte) has no file to delete.
			$name = wp_basename( wrio_file_path( $size['file'] ) );

			if ( '' === $name ) {
				continue;
			}

			// Another attachment still uses the file, for example a copy made before the edit. WordPress deletes the
			// files from before an edit without this check.
			if ( self::is_used_by_another( $attachment_id, $dir, $name ) ) {
				self::$shared_files[ self::path_key( $dir . $name ) ] = [ $dir, $name ];
				continue;
			}

			$files[ (string) $key ] = [
				'path'     => $dir . $name,
				'url'      => $url . $name,
				'optimize' => false,
			];

			if ( $backup instanceof WIO_Backup && is_string( $backup_dir ) && '' !== $backup_dir && is_file( $backup_dir . $name ) ) {
				$backup->delete_backup( $backup_dir . $name );
			}
		}

		\WRIO\V2\Format_Rows::delete( $files );
	}

	/**
	 * Keep a file from before an edit that another attachment uses (see delete_pre_edit_files()).
	 *
	 * @param string $file File that WordPress deletes.
	 *
	 * @return string The file, or an empty string to keep it.
	 */
	public static function keep_shared_file( $file ) {
		$shared = self::$shared_files[ self::path_key( (string) $file ) ] ?? null;

		// Asked again: the request can delete the other attachment too (a bulk delete). The meta of the attachment
		// that WordPress deletes now is already gone.
		return $shared && self::is_used_by_another( 0, $shared[0], $shared[1] ) ? '' : $file;
	}

	/**
	 * A path to compare: WordPress builds the path of a file from before an edit with path_join(), which gives
	 * "<uploads>/./file.jpg" without year and month folders.
	 *
	 * @param string $path File path.
	 *
	 * @return string
	 */
	private static function path_key( string $path ): string {
		return str_replace( '/./', '/', wp_normalize_path( $path ) );
	}

	/**
	 * Give the queue row of an edited attachment to the attachments that use its main file from before the edit (a
	 * copy made before the edit). That file is the optimized one and its backup stays, so the copy can restore it.
	 *
	 * @param int               $attachment_id Attachment ID.
	 * @param RIO_Process_Queue $queue_row     Queue row of the deleted attachment.
	 *
	 * @return void
	 */
	private static function hand_over_pre_edit( int $attachment_id, RIO_Process_Queue $queue_row ): void {
		$sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$file  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! $queue_row->get_id() || ! is_array( $sizes ) || ! is_array( $sizes['full-orig'] ?? null ) || empty( $sizes['full-orig']['file'] ) || '' === $file ) {
			return;
		}

		$dir      = dirname( $file );
		$original = ( '.' === $dir ? '' : $dir . '/' ) . wp_basename( (string) $sizes['full-orig']['file'] );

		foreach ( self::copies( $attachment_id, $original ) as $copy ) {
			$row = ( new WIO_Attachment( $copy ) )->getOptimizationData();

			if ( $row->get_id() ) {
				continue;
			}

			$row->configure(
				[
					'result_status'      => $queue_row->get_result_status(),
					'processing_level'   => $queue_row->get_processing_level(),
					'is_backed_up'       => $queue_row->get_is_backed_up(),
					'original_size'      => $queue_row->get_original_size(),
					'final_size'         => $queue_row->get_final_size(),
					'original_mime_type' => $queue_row->get_original_mime_type(),
					'final_mime_type'    => $queue_row->get_final_mime_type(),
					'extra_data'         => $queue_row->get_extra_data(),
				]
			);
			$row->save();
			// The state rows too: with Backfill::reads(), the bulk page and the statistics count from them.
			Backfill::item( 'attachment', $copy );
		}
	}

	/**
	 * Whether another attachment has the file as its main file, a size or a file from before an edit. A file with the
	 * same name in another folder also counts: then the file stays, which is the safe side.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $dir           Folder of the file, with a trailing slash.
	 * @param string $name          File name.
	 *
	 * @return bool
	 */
	private static function is_used_by_another( int $attachment_id, string $dir, string $name ): bool {
		global $wpdb;

		$uploads  = trailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) );
		$relative = ltrim( str_replace( $uploads, '', wp_normalize_path( $dir . $name ) ), '/' );
		$like     = '%' . $wpdb->esc_like( '"' . $name . '"' ) . '%';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} WHERE post_id <> %d AND ( ( meta_key = '_wp_attached_file' AND meta_value = %s ) OR ( meta_key IN ( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) AND meta_value LIKE %s ) ) LIMIT 1",
				$attachment_id,
				$relative,
				$like
			)
		);
	}

	/**
	 * The other attachments that use the same main file.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $file          Main file relative to the uploads folder. Null is the attachment's file.
	 *
	 * @return int[]
	 */
	private static function copies( int $attachment_id, ?string $file = null ): array {
		global $wpdb;

		$file = $file ?? (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		return '' === $file ? [] : array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d", $file, $attachment_id ) ) );
	}

	/**
	 * Make the failures that depend on the plan pending again (license or plan change).
	 *
	 * @return void
	 */
	public static function plan_changed(): void {
		Store::plan_changed();
	}

	/**
	 * Delete the converted files of a source file.
	 *
	 * @param string $path Source file path.
	 *
	 * @return void
	 */
	private static function delete_formats( string $path ): void {
		foreach ( [ 'webp', 'avif' ] as $format ) {
			if ( file_exists( $path . '.' . $format ) ) {
				wp_delete_file( $path . '.' . $format );
			}
		}
	}

	/**
	 * The daily clean-up.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		// An older version wrote the old table after the copy ended (a rollback, then this version again).
		Backfill::catch_up();

		Store::prune_log( self::LOG_DAYS, self::LOG_ROWS );
		Store::delete_expired_locks();
		Store::delete_orphans();
	}
}
