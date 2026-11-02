<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы с WordPress media library.
 *
 * @version       1.0
 *
 * @phpstan-import-type Row from \WRIO\Storage\Store
 * @phpstan-import-type ItemType from WRIO_Image_Query
 *
 * @phpstan-type MediaLibraryParams array{
 *     attachment_id: int,
 *     is_supported_format: bool,
 *     can_convert: bool,
 *     is_optimized: bool,
 *     attach_dimensions: string,
 *     attachment_file_size: int|false,
 *     optimized_size: int|false,
 *     original_size: int|numeric-string|null|false,
 *     final_size: int|numeric-string|null|false,
 *     original_main_size: int|numeric-string|null|false,
 *     thumbnails_optimized: int|numeric-string|null|false,
 *     optimization_level: string|int|null|false,
 *     error_msg: string|null,
 *     backuped: bool|'0'|'1'|null,
 *     other_levels: bool,
 *     diff_percent: int|float|false,
 *     diff_percent_all: int|float|false,
 *     is_skipped: bool,
 *     webp_size: int|numeric-string|null,
 *     avif_size: int|numeric-string|null,
 *     webp_percent: int|float,
 *     avif_percent: int|float,
 *     webp_level: string|int|null,
 *     avif_level: string|int|null,
 *     original_name: string,
 *     original_url: string|false,
 *     edit_url: string|null,
 *     excluded: string
 * }
 */
class WRIO_Media_Library {

	/**
	 * Post meta of a client-side upload (see mark_client_side_upload()): "waiting" until the finalize request, then
	 * "finalized" until a regeneration: a "create" event, or an "update" event with a new size name after the job ran.
	 * A repeated finalize request after the job schedules nothing.
	 */
	const CLIENT_SIDE_UPLOAD_META = '_wrio_client_side_upload';

	/**
	 * Post meta of a finalized client-side upload: the size names of its finalize request.
	 */
	const CLIENT_SIDE_SIZES_META = '_wrio_client_side_sizes';

	/**
	 * How long a client-side upload waits for its finalize request. After that, a stopped upload is optimized with
	 * the metadata it has.
	 */
	const CLIENT_SIDE_WAIT = HOUR_IN_SECONDS;

	/**
	 * The client-side uploads that this request inserted, by attachment ID. Only their "create" event waits.
	 *
	 * @var array<int, true>
	 */
	private static $client_side_uploads = [];

	/**
	 * The client-side uploads that this request finalized in Background mode, by attachment ID: queued at shutdown.
	 *
	 * @var array<int, true>
	 */
	private static $finalized_uploads = [];

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
	 * The state rows of the attachments of the list page, by attachment ID (see prime_media_column_queue_cache()).
	 *
	 * @var array<int, array<string, Row>>
	 */
	private $state_rows = [];

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
			add_filter( 'wp_generate_attachment_metadata', 'WRIO_Media_Library::optimize_after_upload', 10, 3 );
			add_action( 'rest_after_insert_attachment', 'WRIO_Media_Library::mark_client_side_upload', 10, 3 );
			add_action( 'wr2x_retina_file_added', 'WRIO_Media_Library::optimize_after_retina_2x_add', 10, 2 );
		}

		// The options of one image: on upload, and in the attachment details.
		add_action( 'post-plupload-upload-ui', [ $this, 'print_upload_options' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_upload_options_script' ] );
		// The Customizer does not fire admin_enqueue_scripts. Every media modal fires wp_enqueue_media.
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_upload_options_script' ] );
		add_action( 'add_attachment', [ $this, 'save_upload_options' ] );
		add_filter( 'attachment_fields_to_save', [ $this, 'save_attachment_options' ], 10, 2 );

		// соло оптимизация
		add_filter( 'attachment_fields_to_edit', [ $this, 'attachmentEditorFields' ], 1000, 2 );
		if ( WRIO_Plugin::app()->getPopulateOption( 'show_media_column', true ) ) {
			add_filter( 'manage_media_columns', [ $this, 'addMediaColumn' ] );
			add_action( 'manage_media_custom_column', [ $this, 'manageMediaColumn' ], 10, 2 );
			add_action( 'admin_head-upload.php', [ $this, 'prime_media_column_queue_cache' ] );
		}
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueMeadiaScripts' ], 10 );
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_modal_scripts' ] );
		add_action( 'wbcr/rio/optimize_template/optimized_percent', [ $this, 'optimizedPercent' ], 10, 2 );
	}

	/**
	 * The options of one image, by request field: the post meta key, the label and the description.
	 *
	 * @return array<string, array{meta: string, label: string, help: string}>
	 */
	public static function attachment_options() {
		return [
			'wrio_no_resize' => [
				'meta'  => '_wrio_no_resize',
				'label' => __( 'Keep original dimensions', 'robin-image-optimizer' ),
				'help'  => __( 'Do not resize this image to the maximum width and height. It is still compressed.', 'robin-image-optimizer' ),
			],
			'wrio_exclude'   => [
				'meta'  => '_wrio_exclude',
				'label' => __( 'Exclude from optimization', 'robin-image-optimizer' ),
				'help'  => __( 'Bulk and automatic optimization skip this image.', 'robin-image-optimizer' ),
			],
		];
	}

	/**
	 * The image must keep its dimensions: the resize to the maximum width and height does not apply to it.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public static function keeps_dimensions( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, '_wrio_no_resize', true );
	}

	/**
	 * Print the image options below the upload area (media-new.php and the media modal).
	 *
	 * The uploader sends them with each file (see save_upload_options()).
	 *
	 * @return void
	 */
	public function print_upload_options() {
		echo '<p class="wrio-upload-options">';
		foreach ( self::attachment_options() as $field => $option ) {
			printf(
				'<label title="%1$s"><input type="checkbox" class="wrio-upload-option" name="%2$s" value="1" /> %3$s</label> ',
				esc_attr( $option['help'] ),
				esc_attr( $field ),
				esc_html( $option['label'] )
			);
		}
		echo '</p>';
	}

	/**
	 * Add the script that sends the upload options (see print_upload_options()) to the uploader scripts.
	 *
	 * Runs before the scripts print: media-new.php prints plupload-handlers in the head.
	 *
	 * @return void
	 */
	public function enqueue_upload_options_script() {
		// The media modal renders the upload area again when a file is added: the checkboxes are new and
		// unchecked when the upload starts. So the choice is kept here, and cleared when the files are sent.
		// wp.Uploader (media modal) calls its init() for every new uploader; the uploader of media-new.php is
		// the global "uploader".
		$script = "( function ( $ ) {
	var options = {};
	$( document ).on( 'change', '.wrio-upload-option', function () {
		options[ this.name ] = this.checked ? 1 : 0;
	} );
	function bind( up ) {
		up.bind( 'BeforeUpload', function ( u ) {
			$.extend( u.settings.multipart_params, options );
		} );
		up.bind( 'UploadComplete', function ( u ) {
			// The uploader is used again for the next files.
			$.each( options, function ( name ) {
				delete u.settings.multipart_params[ name ];
			} );
			options = {};
			$( '.wrio-upload-option' ).prop( 'checked', false );
		} );
	}
	if ( window.wp && wp.Uploader ) {
		var init = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			init.apply( this, arguments );
			bind( this.uploader );
		};
	}
	$( function () {
		if ( window.uploader && window.uploader.bind ) {
			bind( window.uploader );
		}
	} );
} )( jQuery );";

		// Printed only with the uploader script it follows. Once: a page can fire both hooks.
		foreach ( [ 'wp-plupload', 'plupload-handlers' ] as $handle ) {
			$after = wp_scripts()->get_data( $handle, 'after' );
			if ( ! is_array( $after ) || ! in_array( $script, $after, true ) ) {
				wp_add_inline_script( $handle, $script );
			}
		}
	}

	/**
	 * A queued job (cron, background) skips an image that was excluded after it was queued.
	 *
	 * An explicit request (the "manual" trigger, for example the Abilities API) still runs.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public static function skips_queued( $attachment_id ) {
		if ( 'manual' === get_post_meta( $attachment_id, \WRIO\V2\Client::TRIGGER_META, true ) || ! WRIO_Image_Query::get_instance()->is_excluded( (int) $attachment_id ) ) {
			return false;
		}

		// A later run of the image, when it is included again, is bulk.
		delete_post_meta( $attachment_id, \WRIO\V2\Client::TRIGGER_META );

		return true;
	}

	/**
	 * Store the image options sent with an upload (see print_upload_options()).
	 *
	 * @param int $attachment_id The new attachment.
	 *
	 * @return void
	 */
	public function save_upload_options( $attachment_id ) {
		// The upload form sends its media-form nonce (async-upload.php checks it too).
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'media-form' ) ) {
			return;
		}

		foreach ( self::attachment_options() as $field => $option ) {
			if ( ! empty( $_POST[ $field ] ) ) {
				update_post_meta( $attachment_id, $option['meta'], 1 );
			}
		}
	}

	/**
	 * Store the image options of the attachment details (see attachmentEditorFields()).
	 *
	 * @param array<string, mixed> $post       The attachment data to save.
	 * @param array<string, mixed> $attachment The submitted attachment fields.
	 *
	 * @return array<string, mixed>
	 */
	public function save_attachment_options( $post, $attachment ) {
		// Only a form that showed the options: an unchecked box sends nothing.
		if ( empty( $attachment['wrio_options'] ) || empty( $post['ID'] ) ) {
			return $post;
		}

		foreach ( self::attachment_options() as $field => $option ) {
			if ( empty( $attachment[ $field ] ) ) {
				delete_post_meta( (int) $post['ID'], $option['meta'] );
			} else {
				update_post_meta( (int) $post['ID'], $option['meta'], 1 );
			}
		}

		// The counts leave out the excluded images.
		WRIO_Image_Query::clear_cache();

		return $post;
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
	 * Mark an upload whose sizes the browser makes (WordPress 7.1 client-side media processing).
	 *
	 * The REST request sends generate_sub_sizes=false. The browser sends the sizes in later requests, and the finalize
	 * request fires wp_generate_attachment_metadata again with the "update" context and all sizes.
	 *
	 * @param WP_Post         $attachment Inserted attachment.
	 * @param WP_REST_Request $request    REST request.
	 * @param bool            $creating   True for a new attachment.
	 *
	 * @return void
	 */
	public static function mark_client_side_upload( $attachment, $request, $creating ) {
		if ( $creating && false === $request['generate_sub_sizes'] ) {
			self::$client_side_uploads[ $attachment->ID ] = true;
			update_post_meta( $attachment->ID, self::CLIENT_SIDE_UPLOAD_META, 'waiting' );
		}
	}

	/**
	 * Whether a client-side upload still waits for the sizes that the browser sends (#245). Until the finalize request,
	 * its metadata has only the full size: nothing optimizes it, so its sizes are not lost.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public static function waits_for_sizes( int $attachment_id ): bool {
		return 'waiting' === get_post_meta( $attachment_id, self::CLIENT_SIDE_UPLOAD_META, true )
			&& (int) get_post_time( 'U', true, $attachment_id ) > time() - self::CLIENT_SIDE_WAIT;
	}

	/**
	 * Whether the metadata of a finalized client-side upload has a size that its job did not see: a size missing from
	 * the size names of the finalize request. An upload finalized before CLIENT_SIDE_SIZES_META existed has a new
	 * size when a file that Robin processes has no state row; a size that the filters skip has none either (#238).
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 *
	 * @return bool
	 */
	private static function has_new_size( int $attachment_id, array $metadata ): bool {
		$known = get_post_meta( $attachment_id, self::CLIENT_SIDE_SIZES_META, true );

		if ( is_array( $known ) ) {
			return (bool) array_diff( array_map( 'strval', array_keys( (array) ( $metadata['sizes'] ?? [] ) ) ), $known );
		}

		$rows  = array_map( fn( $key ) => (string) strstr( $key, '|', true ), array_keys( \WRIO\Storage\Store::rows( 'attachment', $attachment_id ) ) );
		$files = ( new WIO_Attachment( $attachment_id, $metadata ) )->get_files( ! empty( WRIO_Formats::get_enabled_formats() ), false );

		return (bool) array_diff( array_map( 'strval', array_keys( $files ) ), $rows );
	}

	/**
	 * Change the mark of a client-side upload from "waiting" to "finalized" in one UPDATE: of overlapping finalize
	 * requests, exactly one gets true (#238).
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool Whether this request finalized the upload.
	 */
	private static function claim_finalize( int $attachment_id ): bool {
		global $wpdb;

		// update_post_meta() reads the value first, so two requests could both change it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- an atomic compare-and-set on one row.
		$claimed = 1 === $wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => 'finalized' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			[
				'post_id'    => $attachment_id,
				'meta_key'   => self::CLIENT_SIDE_UPLOAD_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'waiting', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $attachment_id, 'post_meta' );

		return $claimed;
	}

	/**
	 * Оптимизация при загрузке в медиабиблиотеку
	 *
	 * @param array  $metadata      метаданные аттачмента.
	 * @param int    $attachment_id Номер аттачмента из медиабиблиотеки.
	 * @param string $context       "create" for new metadata, "update" for changed metadata.
	 *
	 * @return array $metadata Метаданные аттачмента
	 */
	public static function optimize_after_upload( $metadata, $attachment_id, $context = 'create' ) {
		// The browser still sends the sizes: optimize when the finalize request has all of them.
		if ( 'create' === $context && isset( self::$client_side_uploads[ $attachment_id ] ) ) {
			return $metadata;
		}

		$mark      = get_post_meta( $attachment_id, self::CLIENT_SIDE_UPLOAD_META, true );
		$finalized = false;

		if ( 'update' === $context && 'waiting' === $mark ) {
			// The size list first: a finalize request that loses the claim below, or comes later, reads it.
			update_post_meta( $attachment_id, self::CLIENT_SIDE_SIZES_META, array_map( 'strval', array_keys( (array) ( $metadata['sizes'] ?? [] ) ) ) );

			// Overlapping finalize requests all read "waiting": only the one that claims the upload schedules its job.
			if ( ! self::claim_finalize( (int) $attachment_id ) ) {
				return $metadata;
			}

			$finalized = true;
		} elseif ( 'update' === $context && 'finalized' === $mark && ! self::has_new_size( (int) $attachment_id, (array) $metadata ) ) {
			// The finalize request again, while the job waits or after it ran: the job reads the final metadata (#238).
			// wp_update_image_subsizes() (wp media regenerate --only-missing, the REST post-process route) also sends
			// "update", with new sizes: those take the usual flow below.
			return $metadata;
		} elseif ( '' !== $mark ) {
			// A stopped upload, new sizes or a regeneration of a finalized upload: the usual flow.
			delete_post_meta( $attachment_id, self::CLIENT_SIDE_UPLOAD_META );
			delete_post_meta( $attachment_id, self::CLIENT_SIDE_SIZES_META );
		}

		$backup               = WIO_Backup::get_instance();
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );
		$optimize_type        = WRIO_Plugin::app()->getPopulateOption( 'image_optimization_type', 'schedule' );

		if ( ( $backup_origin_images && ! $backup->isBackupWritable() ) || WRIO_Image_Query::get_instance()->is_excluded( (int) $attachment_id ) ) {
			return $metadata;
		}

		// A new upload inserts the attachment in the same request, or finalizes it. Regenerate Thumbnails and wp media
		// regenerate do neither.
		$upload = $finalized || did_action( 'add_attachment' );
		\WRIO\V2\Client::queue_trigger( $attachment_id, $upload ? 'upload' : 'regenerate' );

		if ( wrio_is_license_activate() && 'background' === $optimize_type ) {
			if ( $finalized ) {
				// The finalize request saves the final metadata after this filter: a worker that starts now would
				// optimize the file of the first request without its sizes (#238). Queue it at the end of the request.
				if ( ! self::$finalized_uploads ) {
					add_action( 'shutdown', [ __CLASS__, 'queue_finalized_uploads' ] );
				}
				self::$finalized_uploads[ (int) $attachment_id ] = true;
			} else {
				// Only a new upload goes before the bulk batches: a regeneration of many images would hold them back.
				self::queue_in_background( [ (int) $attachment_id ], $upload );
			}
		} else {
			WRIO_Cron::start_single( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Queue the client-side uploads that this request finalized, after the request saved their metadata.
	 *
	 * @return void
	 */
	public static function queue_finalized_uploads() {
		$ids                     = array_keys( self::$finalized_uploads );
		self::$finalized_uploads = [];

		if ( $ids ) {
			self::queue_in_background( $ids, true );
		}
	}

	/**
	 * Add attachments to the background queue and start it.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param bool  $priority       True for new uploads: before the bulk batches.
	 *
	 * @return void
	 */
	private static function queue_in_background( array $attachment_ids, bool $priority ) {
		$processing = wrio_get_processing_class( 'media-library' );

		if ( $processing && $processing->push_items( $attachment_ids ) ) {
			$processing->save( $priority )->dispatch();
		} elseif ( ! $processing ) {
			// No background queue in this request: the upload must not be lost (#243).
			foreach ( $attachment_ids as $attachment_id ) {
				WRIO_Cron::start_single( $attachment_id );
			}
		}
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
	 * @return array|WP_Error The error of a failed restore before a re-optimization.
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

		// A new level needs the original: without it, the optimized file would be compressed twice.
		$no_original = $wio_attachment->isOptimized() ? \WRIO\V2\Optimizer::no_original( 'attachment', (int) $attachment_id, $level, $optimization_data->get_processing_level() ) : null;

		if ( $no_original ) {
			return $no_original;
		}

		// A re-optimization starts from the original, except for the changed or failed files of a done image.
		if ( $wio_attachment->isOptimized() && \WRIO\V2\Optimizer::restores_first( 'attachment', (int) $attachment_id, $level, $optimization_data->get_processing_level() ) ) {
			$restored = $this->restoreAttachment( $attachment_id );

			// Optimizing the optimized files again would compress them twice.
			if ( is_wp_error( $restored ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Skipped re-optimization of attachment %d: %s', $attachment_id, $restored->get_error_message() ) );

				return $restored;
			}

			$wio_attachment->reload();
		}

		$attachment_optimized_data = $wio_attachment->optimize( $level );

		// Reload attachment data to ensure cached object has fresh optimization data
		$wio_attachment->reload();

		return $attachment_optimized_data;
	}

	/**
	 * Восстанавливает аттачмент из резервной копии и сохраняет статистику
	 *
	 * @param int $attachment_id
	 *
	 * @return bool|WP_Error
	 */
	public function restoreAttachment( $attachment_id ) {
		$wio_attachment = $this->getAttachment( $attachment_id );
		$restored       = $wio_attachment->restore();

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		$optimization_data = $wio_attachment->getOptimizationData();

		if ( $optimization_data->get_id() ) {
			$optimization_data->delete();
		}

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
	public function getUnoptimizedImages() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public method of the plugin API.
		// The background run keeps to the upload dates of the run (#246).
		return WRIO_Image_Query::get_instance()->get_unoptimized_ids( null, 0, true, null, true );
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
	 * @param int $max_process_per_request кол-во аттачментов за 1 запуск.
	 *
	 * @return array{work: ItemType|'', remain: int, end: bool, held: bool, statistic: array<string, mixed>, last_optimized: array<int, array<string, mixed>>}|\WP_Error
	 */
	public function processUnoptimizedImages( $max_process_per_request ) {
		$backup_origin_images = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );

		$backup = WIO_Backup::get_instance();

		if ( $backup_origin_images && ! $backup->isBackupWritable() ) {
			return new WP_Error( 'unwritable_backup_dir', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}

		if ( ! $backup->isUploadWritable() ) {
			return new WP_Error( 'unwritable_upload_dir', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}

		$max_process_per_request = intval( $max_process_per_request );

		// A missing table makes bulk report "complete" with 0 images.
		$missing_table = RIO_Process_Queue::repair_tables();

		if ( '' !== $missing_table ) {
			/* translators: %s: database table name. */
			return new WP_Error( 'missing_table', sprintf( __( 'The database table %s does not exist and the plugin cannot create it. Check the permissions of the database user.', 'robin-image-optimizer' ), $missing_table ) );
		}

		// An unknown license key fails every image without a file row: the run would pick the same image forever.
		$limits = \WRIO\V2\Client::from_settings()->limits();

		if ( is_wp_error( $limits ) ) {
			return $limits;
		}

		// Images that are not optimized come first. Then optimized images that miss a format, to convert only.
		// One query selects both (#248). The format is the one that the query selected, also when an old error row of it exists.
		$query                                      = WRIO_Image_Query::get_instance();
		list( $work, $unoptimized_attachments_ids ) = $query->get_next_batch( $max_process_per_request, WRIO_Formats::get_enabled_formats() );
		$convert_only                               = 'attachment' !== $work;

		$attachments_count = count( $unoptimized_attachments_ids );

		$optimized_items = [];
		$locked          = false;

		// обработка
		if ( ! empty( $attachments_count ) ) {

			foreach ( array_values( $unoptimized_attachments_ids ) as $index => $attachment_id ) {
				// The next request takes the rest: a file cut off by the PHP time limit fails and uses up a retry.
				if ( $index > 0 && ! wrio_has_time_left() ) {
					break;
				}

				// A rule that SQL cannot read (file size, the wrio_should_optimize filter) marks the image as skipped.
				// Also for a conversion: an excluded image gets no WebP or AVIF file from a bulk run.
				if ( $query->is_excluded( (int) $attachment_id ) ) {
					continue;
				}

				// A process that died on the image keeps its lock until the lock expires. The step must not count the image
				// as done: the bulk page would send the next step at once, for the same image.
				if ( \WRIO\Storage\Store::locked_by_other( 'attachment', (int) $attachment_id ) ) {
					$locked = true;
					continue;
				}

				$wio_attachment = $this->getAttachment( $attachment_id );

				try {
					if ( $convert_only ) {
						// A failed conversion fails the format, not the optimized image.
						$this->webpConvertAttachment( $attachment_id, $work );
					} else {
						$wio_attachment->optimize();
					}
					$optimized_items[] = $attachment_id;
				} catch ( Throwable $throwable ) {
					$wio_attachment->mark_and_log_failure( $throwable, 'batch-processing' );
				}
			}
		}

		$image_statistics = WRIO_Image_Statistic::get_instance();

		// Recount each kind of work after processing: one attachment can still need another format.
		// Use the same WPML and retry rules as the selections above.
		WRIO_Image_Query::clear_cache();
		// The statistic already counted the images to convert: do not count them again.
		$statistic = $image_statistics->load();

		if ( $query->has_run_dates() ) {
			// The statistic counts the whole library: the run counts only its dates.
			$remain = $query->count_run_work( WRIO_Formats::get_enabled_formats() );
		} else {
			$remain = $query->count_unoptimized( true, [ 'attachment' ] );

			foreach ( WRIO_Formats::get_enabled_formats() as $format ) {
				$remain += (int) $statistic[ 'avif' === $format ? 'avif_unconverted' : 'unconverted' ];
			}
		}

		// Take the last optimized image ID. Used to log 100 optimized images.
		$last_optimized_id = end( $optimized_items );

		// A wait that the API started in this step (a 429 or 5xx) keeps its image to do: the next query selects it
		// again. The step did not do it, so the bulk page must not count it as done.
		// A locked image is also still to do.
		$held = $locked || ( $optimized_items && \WRIO\V2\Client::backoff_seconds() > 0
			&& [] !== array_intersect( $optimized_items, $query->get_next_batch( $attachments_count, WRIO_Formats::get_enabled_formats() )[1] ) );

		$response = [
			// What the step did: attachment optimized, webp or avif converted, '' found nothing to do.
			'work'           => $work,
			// The API wait or another process's lock held back an image of this step: it is still to do.
			'held'           => $held,
			'remain'         => $remain,
			'end'            => false,
			'statistic'      => $statistic,
			'last_optimized' => $last_optimized_id ? $image_statistics->get_last_optimized_image( $last_optimized_id ) : [],
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
		$max_process_per_request = intval( $max_process_per_request );

		// Validate format
		if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
			$format = 'webp';
		}

		$unconverted_attachments_ids = WRIO_Image_Query::get_instance()->get_unconverted_ids( $format, $max_process_per_request );

		// временно
		$attachments_count = ! empty( $unconverted_attachments_ids ) ? sizeof( $unconverted_attachments_ids ) : 0;
		$total_unconverted = WRIO_Image_Statistic::get_unconverted_count( $format );
		$converted_items   = [];

		// обработка
		if ( ! empty( $attachments_count ) ) {

			foreach ( $unconverted_attachments_ids as $attachment_id ) {
				// As in processUnoptimizedImages(): the wrio_should_optimize filter skips the image.
				if ( WRIO_Image_Query::get_instance()->is_excluded( (int) $attachment_id ) ) {
					continue;
				}

				$wio_attachment = $this->getAttachment( $attachment_id );

				try {
					$wio_attachment->convert( [ $format ] );

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
		$last_converted_id = (int) end( $converted_items );

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
	 * Convert every size of an attachment to a format, without optimizing it.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $format        webp or avif. Null converts to every enabled format.
	 *
	 * @since  1.5.3
	 */
	public function webpConvertAttachment( $attachment_id, $format = null ) {
		$wio_attachment = $this->getAttachment( $attachment_id );
		$formats        = null === $format ? WRIO_Formats::get_enabled_formats() : ( in_array( $format, [ 'webp', 'avif' ], true ) ? [ $format ] : [] );

		try {
			$wio_attachment->convert( $formats );
		} catch ( Throwable $throwable ) {
			foreach ( $formats as $failed_format ) {
				$wio_attachment->mark_conversion_failure( $throwable, $failed_format, sprintf( '%s-conversion', $failed_format ) );
			}
		}
	}

	/**
	 * Сбрасывает текущие ошибки оптимизации
	 * Позволяет изображениям, которые оптимизированы с ошибкой, заново пройти оптимизацию.
	 *
	 * @return void
	 */
	public function resetCurrentErrors() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public method of the plugin API.
		global $wpdb;

		RIO_Process_Queue::reset_errors( 'attachment' );
		// The run asks the wrio_should_optimize filter again: a site can have removed or narrowed it.
		WRIO_Image_Query::forget_skips();

		// The format rows too: a retryable conversion error (not in the plan, server error) is tried again.
		foreach ( [ 'webp', 'avif' ] as $item_type ) {
			RIO_Process_Queue::reset_format_errors( $item_type );
		}

		// A file that is still missing is not counted or queued again.
		$table  = \WRIO\Storage\Schema::files_table();
		$absent = array_filter(
			array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT object_id FROM {$table} WHERE source = 'attachment' AND size_name = 'original' AND status = 'missing'" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
			function ( int $id ): bool {
				$path = ( new WIO_Attachment( $id ) )->get( 'path' );

				return empty( $path ) || ! file_exists( $path );
			}
		);

		\WRIO\Storage\Store::errors_reset( 'attachment', $absent );
		$this->record_missing_files();
	}

	/**
	 * Save the missing-file failure of each image not processed yet whose file is not on disk, as optimize() does,
	 * so the bulk counts and queue skip it. Images without metadata are left to optimize().
	 * Before the copy of the old queue ends, an optimized image can have no state rows yet: nothing is saved.
	 *
	 * @return void
	 */
	protected function record_missing_files(): void {
		if ( ! \WRIO\Storage\Backfill::reads() ) {
			return;
		}

		$basedir = trailingslashit( wp_get_upload_dir()['basedir'] );

		// One query for the whole library (#248). Only an image whose file is not at its _wp_attached_file path is
		// loaded, to read the path from its metadata as optimize() does.
		foreach ( WRIO_Image_Query::get_instance()->get_untouched_files() as $id => $file ) {
			if ( '' !== $file && file_exists( $basedir . $file ) ) {
				continue;
			}

			$attachment = new WIO_Attachment( $id );

			if ( $attachment->isset_attachment_meta() && ! file_exists( $attachment->get( 'path' ) ) ) {
				$attachment->optimize();
			}
		}
	}

	/**
	 * Восстановление из резервной копии.
	 *
	 * @param int   $max_process_per_request кол-во аттачментов за 1 запуск.
	 * @param int[] $skip                    Attachments that failed in an earlier step of the run.
	 *
	 * @return array{remain: int, total: int, failed: array<int, array{id: int, error: string}>} The failed attachments
	 *                                                                                          of this step.
	 */
	public function restoreAllFromBackup( $max_process_per_request, array $skip = [] ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- public method of the plugin API.
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

		// Only the images with a backup, also an image whose main file failed and whose thumbnails are optimized.
		$db_table = RIO_Process_Queue::table_name();
		$where    = "q.item_type = 'attachment' AND " . self::backup_sql();

		if ( ! empty( $skip ) ) {
			$where .= ' AND q.object_id NOT IN (' . implode( ',', array_map( 'intval', $skip ) ) . ')';
		}

		$optimized_count       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table} q WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables, integer IDs.
		$optimized_attachments = $wpdb->get_results( "SELECT q.* FROM {$db_table} q WHERE {$where} LIMIT " . intval( $max_process_per_request ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables, integer IDs.

		$attachments_count = 0;
		if ( $optimized_attachments ) {
			$attachments_count = count( $optimized_attachments );
		}

		$restored_count = 0;
		$failed         = [];

		// обработка
		if ( $attachments_count ) {
			foreach ( $optimized_attachments as $row ) {
				$attachment_id = intval( $row->object_id );

				$restored = $this->restoreAttachment( $attachment_id );

				// Another process optimizes the image now: it stays in the count, and a next step tries it again.
				if ( is_wp_error( $restored ) && 'wrio_busy' === $restored->get_error_code() ) {
					continue;
				}

				// A lost backup, for example. The app skips the image in the next steps, and the others go on.
				if ( is_wp_error( $restored ) ) {
					$failed[] = [
						'id'    => $attachment_id,
						'error' => $restored->get_error_message(),
					];
					continue;
				}

				++$restored_count;
			}
		}

		$remane = $optimized_count - $restored_count - count( $failed );

		if ( $remane === 0 ) {
			// Should empty original/optimized size once all backups are empty
			WRIO_Plugin::app()->updateOption( 'original_size', 0 );
			WRIO_Plugin::app()->updateOption( 'optimized_size', 0 );
		}

		return [
			'remain' => $remane,
			'total'  => $optimized_count,
			'failed' => $failed,
		];
	}

	/**
	 * SQL condition on a queue row: the image has a backup to restore. The backup of the main file, or of an
	 * optimized size (a thumbnail when the main file was replaced or failed). "Restore all" uses it, also for the
	 * custom folders and NextGEN (WRIOP_Backup::restore_all_items()). has_backup() and
	 * WRIO_Image_Nextgen::has_backup() check the same rule in PHP, so change them together.
	 *
	 * @param string $queue  SQL name of the queue row. Default: the alias q.
	 * @param string $source SQL of the state rows' source. Default: attachment.
	 * @param string $id_column Column of the queue row with the item ID of the state rows.
	 *
	 * @return string
	 */
	public static function backup_sql( $queue = 'q', $source = "'attachment'", $id_column = 'object_id' ) {
		$files_table = \WRIO\Storage\Schema::files_table();

		return "( {$queue}.is_backed_up = 1 OR EXISTS ( SELECT 1 FROM {$files_table} f WHERE f.source = {$source} AND f.object_id = {$queue}.{$id_column} AND f.backed_up = 1 AND f.format = 'optimized' AND f.status = 'ok' ) )";
	}

	/**
	 * Whether the attachment has a backup to restore, as in backup_sql(). An image whose main file failed is not
	 * optimized, and its optimized thumbnails can still be restored.
	 *
	 * @param int                    $attachment_id     Attachment ID.
	 * @param RIO_Process_Queue|null $optimization_data Its queue row when it is read already.
	 *
	 * @return bool
	 */
	public function has_backup( $attachment_id, $optimization_data = null ) {
		if ( ( $optimization_data ?? $this->getAttachment( $attachment_id )->getOptimizationData() )->get_is_backed_up() ) {
			return true;
		}

		$rows = $this->state_rows[ (int) $attachment_id ] ?? \WRIO\Storage\Store::rows( 'attachment', (int) $attachment_id );

		foreach ( $rows as $row ) {
			if ( 1 === (int) $row['backed_up'] && 'optimized' === $row['format'] && \WRIO\Storage\Store::OK === $row['status'] ) {
				return true;
			}
		}

		return false;
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
	 * @param array   $form_fields An array of attachment form fields.
	 * @param WP_Post $post        The WP_Post attachment object.
	 *
	 * @return array
	 */
	public function attachmentEditorFields( $form_fields, $post ) {
		global $pagenow;

		if ( wp_attachment_is_image( $post ) ) {
			$options = sprintf( '<input type="hidden" name="attachments[%d][wrio_options]" value="1" />', (int) $post->ID );
			foreach ( self::attachment_options() as $field => $option ) {
				$options .= sprintf(
					'<label><input type="checkbox" name="attachments[%1$d][%2$s]" value="1"%3$s /> %4$s</label><br /><span class="description">%5$s</span><br />',
					(int) $post->ID,
					esc_attr( $field ),
					checked( (bool) get_post_meta( $post->ID, $option['meta'], true ), true, false ),
					esc_html( $option['label'] ),
					esc_html( $option['help'] )
				);
			}

			$form_fields['wrio_options'] = [
				'label' => __( 'Image Optimizer options', 'robin-image-optimizer' ),
				'input' => 'html',
				'html'  => $options,
			];
		}

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
		echo $this->getMediaColumnContent( $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The column template escapes its own output.
	}

	/**
	 * Preloads queue rows for the attachments listed in the Media Library list table.
	 *
	 * The column renders three states per row, so without this each row would query the
	 * queue table three times. Core fills the query before the admin header is printed,
	 * so the ids are known here and no row has rendered yet.
	 *
	 * @return void
	 */
	public function prime_media_column_queue_cache() {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
			return;
		}

		$attachment_ids = [];

		foreach ( $wp_query->posts as $post ) {
			if ( $post instanceof WP_Post && 'attachment' === $post->post_type ) {
				$attachment_ids[] = (int) $post->ID;
			}
		}

		$this->prime_items( $attachment_ids );
	}

	/**
	 * Preloads what calculateMediaLibraryParams() reads for many attachments: their posts and meta, queue rows and
	 * state rows. Then a list of attachments costs a few queries, not a few per attachment. Only for a request that
	 * reads: the state rows are not read again after a write.
	 *
	 * @param array<int|numeric-string> $attachment_ids Attachment IDs.
	 *
	 * @return void
	 */
	public function prime_items( array $attachment_ids ) {
		$attachment_ids = array_map( 'intval', $attachment_ids );

		_prime_post_caches( $attachment_ids, false, true );
		RIO_Process_Queue::prime( $attachment_ids, [ 'attachment', 'webp', 'avif' ] );

		if ( \WRIO\Storage\Backfill::reads() ) {
			$this->state_rows = \WRIO\Storage\Store::rows_of_items( 'attachment', $attachment_ids );
		}
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
	 * @param int $attachment_id Номер аттачмента из медиабиблиотеки.
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
	 * @return MediaLibraryParams
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

		// The raw edit link: the URL goes to JSON, and the app escapes it. The display form holds &amp;, which breaks the link.
		$original_url  = wp_get_attachment_url( $attachment_id );
		$edit_url      = get_edit_post_link( $attachment_id, 'raw' );
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
		if ( null !== $extra_data ) {
			$error = $extra_data->get_error_msg();

			if ( ! empty( $error ) ) {
				$error_msg = $error;
			}
		}

		$extra_data = $webp_data->get_extra_data();
		if ( null !== $extra_data ) {
			$error = $extra_data->get_error_msg();

			if ( ! empty( $error ) ) {
				$error_msg .= ' WebP error: ' . $error;
			}
		}

		$extra_data = $avif_data->get_extra_data();
		if ( null !== $extra_data ) {
			$error = $extra_data->get_error_msg();

			if ( ! empty( $error ) ) {
				$error_msg .= ' AVIF error: ' . $error;
			}
		}

		// The state rows store a code, so the message is in the language of the user. Without a main file row (a site
		// failure such as an unknown license writes none), the queue row has the only message.
		if ( \WRIO\Storage\Backfill::reads() ) {
			$state_rows = $this->state_rows[ (int) $attachment_id ] ?? \WRIO\Storage\Store::rows( 'attachment', (int) $attachment_id );

			if ( isset( $state_rows['original|optimized'] ) ) {
				$error_msg = \WRIO\Storage\Store::message( 'attachment', (int) $attachment_id, $state_rows );
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

			if ( null !== $extra_data && ! \WRIO\Storage\Backfill::reads() ) {
				$error_msg = $extra_data->get_error_msg();
			}

			$backuped         = $optimization_data->get_is_backed_up();
			$diff_percent     = 0;
			$diff_percent_all = 0;

			if ( $attachment_file_size && $original_main_size ) {
				$diff_percent = round( ( $original_main_size - $attachment_file_size ) * 100 / $original_main_size, 2 );
			}

			// Overall saving needs aggregate sizes on both sides, not the main file alone.
			$final_size_all = $optimization_data->get_final_size();

			if ( $original_size && $final_size_all ) {
				$final_size_all   = min( $original_size, $final_size_all );
				$diff_percent_all = round( ( $original_size - $final_size_all ) * 100 / $original_size, 2 );
			} else {
				// Legacy rows without an aggregate final size: fall back to the main file.
				$diff_percent_all = $diff_percent;
			}
		} else {
			$optimized_size       = false;
			$original_size        = false;
			$original_main_size   = false;
			$thumbnails_optimized = false;
			$optimization_level   = false;
			$diff_percent         = false;
			$diff_percent_all     = false;
			$final_size_all       = false;
			$backuped             = $this->has_backup( $attachment_id, $optimization_data );
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
			'can_convert'          => in_array( $mime_type, wrio_get_conversion_formats(), true ),
			'is_optimized'         => $is_optimized,
			'attach_dimensions'    => $attach_dimensions,
			'attachment_file_size' => $attachment_file_size,
			'optimized_size'       => $optimized_size,
			'original_size'        => $original_size,
			// The aggregate size after the optimization: the main file and the optimized thumbnails.
			'final_size'           => $final_size_all,
			'original_main_size'   => $original_main_size,
			'thumbnails_optimized' => $thumbnails_optimized,
			'optimization_level'   => $optimization_level,
			'error_msg'            => $error_msg,
			'backuped'             => $backuped,
			'other_levels'         => \WRIO\V2\Optimizer::has_original( 'attachment', (int) $attachment_id, $this->state_rows[ (int) $attachment_id ] ?? null ),
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
			// Why bulk and automatic optimization skip it; the Optimize button still works.
			'excluded'             => WRIO_Image_Query::get_instance()->exclusion_reason( (int) $attachment_id ),
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
		WRIO_App::enqueue_script( 'wio-install-addons', 'single-optimization' );
	}

	/**
	 * The same styles and script for the attachment details of a media modal, which show the column too (in the
	 * post editor, for example).
	 *
	 * @return void
	 */
	public function enqueue_modal_scripts() {
		// The script posts to ajaxurl, which only wp-admin defines.
		if ( is_admin() ) {
			$this->enqueueMeadiaScripts( 'upload.php' );
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
}
