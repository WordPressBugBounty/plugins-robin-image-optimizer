<?php

namespace WRIO\WEBP;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Post;
use WRIO\WEBP\HTML\Delivery;

/**
 * Class Listener listens to new events via hooks.
 *
 * For example, once attachment optimized and if WebP option enabled it will kicked and converted.
 *
 * Same applies for custom folder and NextGen plugin.
 *
 * @version       1.0
 */
class Listener {

	/**
	 * Default type.
	 */
	const DEFAULT_TYPE = 'webp';

	/**
	 * @var null|\RIO_Process_Queue[] Saved queue items.
	 */
	private $_saved_models = null;
	/**
	 * @var string|null Format to convert to (webp, avif). Set during convert_webp().
	 */
	private $_current_format = null;

	/**
	 * WRIO_Webp constructor.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Init the object.
	 */
	public function init() {
		// Always register the hook - the convert_webp method will check format.
		// This allows single-image conversion (e.g., clicking "Convert to WebP" button)
		// to work even when global conversion is disabled.
		add_action( 'wbcr/riop/queue_item_saved', [ $this, 'convert_webp' ], 10, 3 );

		add_action( 'wbcr/rio/attachment_restored', [ $this, 'process_attachment_restore' ] );
		add_action( 'wbcr/rio/cf_image_restored', [ $this, 'process_attachment_restore' ] );
		add_action( 'wbcr/rio/nextgen_image_restored', [ $this, 'process_attachment_restore' ] );
	}

	public function convert_webp( $model, $quota = false, $format = null ) {
		/**
		 * @var \RIO_Process_Queue $model
		 */
		// Skip if already a format conversion item (prevent recursion)
		if ( in_array( $model->get_item_type(), [ 'webp', 'avif' ] ) ) {
			return;
		}

		// Use provided format, or fall back to all enabled formats
		if ( $format === null ) {
			$formats = \WRIO_Format_Converter_Factory::get_enabled_formats();

			// If no formats are enabled, exit early
			if ( empty( $formats ) ) {
				return;
			}

			// Process each enabled format
			foreach ( $formats as $format_type ) {
				$this->_current_format = $format_type;
				if ( $format_type !== 'original' ) {
					$this->process_queue_item( $model );

					if ( ! empty( $this->_saved_models ) ) {
						$converter = \WRIO_Format_Converter_Factory::create( $this->_saved_models, $format_type );
						$converter->process_image_queue( $quota );
						$this->_saved_models = []; // Clear for next format
					}
				}
			}
			return;
		}

		// Store format for use in save() method
		$this->_current_format = $format;

		if ( $format === 'original' ) {
			return;
		}

		$this->process_queue_item( $model );

		if ( ! empty( $this->_saved_models ) ) {
			$converter = \WRIO_Format_Converter_Factory::create( $this->_saved_models, $format );
			$converter->process_image_queue( $quota );
		}

		$this->_saved_models   = null;
		$this->_current_format = null;
	}

	/**
	 * Process attachment restore.
	 *
	 * @param \RIO_Process_Queue|null $model
	 *
	 * @return bool
	 */
	public function process_attachment_restore( $model ) {
		if ( ! $model instanceof \RIO_Process_Queue ) {
			\WRIO_Plugin::app()->logger->warning( 'process_attachment_restore called with invalid model (null or wrong type)' );
			return false;
		}

		// Look for both webp and avif items
		foreach ( [ 'webp', 'avif' ] as $item_type ) {
			$item_params = [
				'object_id' => $model->get_object_id(),
				'item_type' => $item_type,
			];

			if ( 'cf_image' == $model->get_item_type() ) {
				unset( $item_params['object_id'] ); // для custom folders не нужен номер объекта
				/**
				 * @var $extra_data \WRIO_CF_Image_Extra_Data
				 */
				$extra_data               = $model->get_extra_data();
				$item_params['item_hash'] = hash( 'sha256', $extra_data->get_image_url() );
			}

			$delete_items = \RIO_Process_Queue::find_all( $item_params );

			if ( empty( $delete_items ) ) {
				continue;
			}

			foreach ( $delete_items as $item ) {
				/**
				 * @var $extra \RIOP_WebP_Extra_Data
				 */
				$extra = $item->get_extra_data();

				if ( empty( $extra ) ) {
					\WRIO_Plugin::app()->logger->warning( sprintf( 'Failed to clean-up queue item #%s as it is missing extra data', $item->get_id() ) );
					continue;
				}

				$converted_path = $extra->get_converted_path();
				if ( ! empty( $converted_path ) ) {
					if ( @unlink( $converted_path ) ) {
						\WRIO_Plugin::app()->logger->info( sprintf( 'Unlinked %s from disk, ready to delete item #%s from DB', $converted_path, $item->get_id() ) );
					} else {
						\WRIO_Plugin::app()->logger->error( sprintf( 'Failed to unlink %s from disk', $converted_path ) );
					}
				}

				if ( $item->delete() ) {
					\WRIO_Plugin::app()->logger->info( sprintf( 'Deleted #%s as attachment #%s was recovered', $item->get_id(), $item->get_object_id() ) );
				} else {
					\WRIO_Plugin::app()->logger->error( sprintf( 'Failed to delete queue item #%s as delete() method failed', $item->get_id() ) );
				}
			}
		}

		return true;
	}

	/**
	 * Process new queue item.
	 *
	 * @param \RIO_Process_Queue|null $model Model to process.
	 *
	 * @return bool
	 */
	public function process_queue_item( $model ) {
		if ( ! ( $model instanceof \RIO_Process_Queue ) ) {
			\WRIO_Plugin::app()->logger->info( 'Model must be instance of RIO_Process_Queue to be process by %s' . __FUNCTION__ );

			return false;
		}

		/*
		if ( ! $model->is_optimized() ) {
			\WRIO_Plugin::app()->logger->info( sprintf( 'Skipping to process attachment #%s as it is not optimized', $model->get_id() ) );

			return false;
		}*/

		switch ( $model->get_item_type() ) {
			case 'attachment':
				$this->process_attachment( $model );
				break;
			case 'cf_image':
				$this->process_custom_folder( $model );
				break;
			case 'nextgen':
				$this->process_nextgen( $model );
				break;
		}

		return true;
	}

	/**
	 * Process attachment.
	 *
	 * Finds attachment by id, gets its src and srcset and saves them on the database.
	 *
	 * After this, images are processed by Cron or manually via admin GUI.
	 *
	 * @param \RIO_Process_Queue $model Attachment model to process.
	 *
	 * @return bool
	 */
	public function process_attachment( $model ) {

		\WRIO_Plugin::app()->logger->info( sprintf( 'Start WebP conversion process for attachment #%s', $model->get_id() ) );

		$attachment = get_post( $model->get_object_id() );

		if ( empty( $attachment ) ) {
			\WRIO_Plugin::app()->logger->warning( sprintf( 'WebP conversion: No attachment found by #%s', $model->get_object_id() ) );

			return false;
		}

		$allowed_mimes = wrio_get_allowed_formats();

		if ( ! in_array( $attachment->post_mime_type, $allowed_mimes ) ) {
			\WRIO_Plugin::app()->logger->warning( sprintf( 'WebP conversion: Attachment #%s with MIME type %s cannot be processed as only these are allowed: %s', $attachment->ID, $attachment->post_mime_type, implode( ', ', $allowed_mimes ) ) );

			return false;
		}

		$attachment_meta = static::get_attachment_data( $attachment );

		if ( empty( $attachment_meta ) ) {
			\WRIO_Plugin::app()->logger->warning( sprintf( 'WebP conversion: Unable to get attachment #%s meta such as height, abs. path, URL, etc. Skipping WebP processing...', $attachment->ID ) );

			return false;
		}

		/**
		 * @var $data array
		 */
		foreach ( $attachment_meta as $hash => $data ) {

			\WRIO_Plugin::app()->logger->info( sprintf( 'WebP conversion: Ready to save hash "%s" (extra data: %s) as it does not exist yet', $hash, wp_json_encode( $data ) ) );

			$source_path = isset( $data['absolute_path'] ) ? $data['absolute_path'] : null;

			if ( empty( $source_path ) || ! file_exists( $source_path ) ) {
				\WRIO_Plugin::app()->logger->error( sprintf( "WebP conversion: Image is not found.\r\nSource path: %s", $source_path ) );
				continue;
			}

			$source_src = $data['url'];

			// Include format in hash so WebP and AVIF conversions are tracked separately
			$format     = $this->_current_format ?? 'webp';
			$hash_seed  = $source_src . '|' . $format;
			$item_hash  = hash( 'sha256', $hash_seed );
			$webp_queue = \RIO_Process_Queue::find_by_hash( $item_hash );

			if ( $webp_queue instanceof \RIO_Process_Queue ) {
				// Reset existing record for re-conversion
					\WRIO_Plugin::app()->logger->warning( sprintf( "WebP conversion: Skipped because the webp image already exists.\r\nSource scr: %s", $source_src ) );
				$webp_queue->result_status = \RIO_Process_Queue::STATUS_PROCESSING;
				$webp_queue->final_size    = 0;
				$webp_queue->save();
				$this->_saved_models[] = $webp_queue;
				continue;
			}

			$extra_data = new \RIOP_WebP_Extra_Data(
				[
					'convert_from'        => 'attachment',
					'converted_from_size' => $data['size'],
					'source_src'          => $source_src,
					'source_path'         => $source_path,
				]
			);

			$saved = $this->save(
				[
					'item_hash'          => $hash_seed, // Include format in hash seed
					'object_id'          => $attachment->ID,
					'original_mime_type' => $attachment->post_mime_type,
				],
				$extra_data
			);

			if ( $saved instanceof \RIO_Process_Queue ) {
				$this->_saved_models[] = $saved;
			}
		}

		$count_models = is_array( $this->_saved_models ) ? count( $this->_saved_models ) : 0;
		\WRIO_Plugin::app()->logger->info( sprintf( 'End WebP conversion process for attachment #%s. Saved models: %d', $model->get_id(), $count_models ) );

		return true;
	}

	/**
	 * Process custom folder.
	 *
	 * @param \RIO_Process_Queue $model Model to process.
	 *
	 * @return bool
	 */
	public function process_custom_folder( $model ) {

		\WRIO_Plugin::app()->logger->info( sprintf( 'Start WebP conversion process for Custom folder item #%s', $model->get_id() ) );

		/**
		 * @var $model_extra_data \WRIO_CF_Image_Extra_Data
		 */
		$model_extra_data = $model->get_extra_data();

		// Include format in hash so WebP and AVIF conversions are tracked separately
		$format    = $this->_current_format ?? 'webp';
		$hash_seed = $model_extra_data->get_image_url() . '|' . $format;

		$webp_exists = \RIO_Process_Queue::find_by_hash( hash( 'sha256', $hash_seed ) );

		if ( $webp_exists ) {
			// Reset existing record for re-conversion
			$webp_exists->result_status = \RIO_Process_Queue::STATUS_PROCESSING;
			$webp_exists->final_size    = 0;
			$webp_exists->save();
			$this->_saved_models[] = $webp_exists;
		} else {
			$extra_data = new \RIOP_WebP_Extra_Data(
				[
					'convert_from' => 'cf_image',
					'source_src'   => $model_extra_data->get_image_url(),
					'source_path'  => $model_extra_data->get_image_absolute_path(),
				]
			);

			$saved = $this->save(
				[
					'item_hash'             => $hash_seed,
					'item_hash_alternative' => $model_extra_data->get_image_relative_path(),
					'original_mime_type'    => $model->get_original_mime_type(),
				],
				$extra_data
			);

			if ( $saved instanceof \RIO_Process_Queue ) {
				$this->_saved_models[] = $saved;
			}
		}

		$count_models = is_array( $this->_saved_models ) ? count( $this->_saved_models ) : 0;
		\WRIO_Plugin::app()->logger->info( sprintf( 'End WebP conversion process for Custom folder #%s. Saved models: %d', $model->get_id(), $count_models ) );

		return true;
	}

	/**
	 * Process NextGen plugin images.
	 *
	 * @link https://wordpress.org/plugins/nextgen-gallery/ Plugin link.
	 *
	 * @param \RIO_Process_Queue $model Model to process.
	 *
	 * @return bool
	 */
	public function process_nextgen( $model ) {

		\WRIO_Plugin::app()->logger->info( sprintf( 'Start WebP conversion process for NextGen item #%s', $model->get_id() ) );

		/**
		 * @var $model_extra_data \WRIO_Nextgen_Extra_Data
		 */
		$model_extra_data = $model->get_extra_data();

		if ( ! ( $model_extra_data instanceof \WRIO_Nextgen_Extra_Data ) ) {
			return false;
		}

		// Include format in hash so WebP and AVIF conversions are tracked separately
		$format    = $this->_current_format ?? 'webp';
		$hash_seed = $model_extra_data->get_image_url() . '|' . $format;

		$webp_exists = \RIO_Process_Queue::find_by_hash( hash( 'sha256', $hash_seed ) );

		if ( $webp_exists ) {
			// Reset existing record for re-conversion
			$webp_exists->result_status = \RIO_Process_Queue::STATUS_PROCESSING;
			$webp_exists->final_size    = 0;
			$webp_exists->save();
			$this->_saved_models[] = $webp_exists;
		} else {
			// Original
			$extra_data = new \RIOP_WebP_Extra_Data(
				[
					'convert_from'        => 'nextgen',
					'converted_from_size' => null,
					'source_src'          => $model_extra_data->get_image_url(),
					'source_path'         => $model_extra_data->get_image_absolute_path(),
				]
			);

			$original_saved = $this->save(
				[
					'item_hash'          => $hash_seed,
					'original_mime_type' => $model->get_original_mime_type(),
					'object_id'          => $model->get_object_id(),
				],
				$extra_data
			);

			if ( $original_saved instanceof \RIO_Process_Queue ) {
				$this->_saved_models[] = $original_saved;
			}
		}

		// Thumbnail
		$thumbnail_hash_seed   = $model_extra_data->get_image_thumbnail_url() . '|' . $format;
		$thumbnail_webp_exists = \RIO_Process_Queue::find_by_hash( hash( 'sha256', $thumbnail_hash_seed ) );

		if ( $thumbnail_webp_exists ) {
			// Reset existing record for re-conversion
			$thumbnail_webp_exists->result_status = \RIO_Process_Queue::STATUS_PROCESSING;
			$thumbnail_webp_exists->final_size    = 0;
			$thumbnail_webp_exists->save();
			$this->_saved_models[] = $thumbnail_webp_exists;
		} else {
			$extra_data_thumbnail = new \RIOP_WebP_Extra_Data(
				[
					'convert_from'        => 'nextgen',
					'converted_from_size' => null,
					'source_src'          => $model_extra_data->get_image_thumbnail_url(),
					'source_path'         => $model_extra_data->get_image_thumbnail_absolute_path(),
				]
			);

			$thumbmail_saved = $this->save(
				[
					'item_hash'          => $thumbnail_hash_seed,
					'original_mime_type' => $model->get_original_mime_type(),
				],
				$extra_data_thumbnail
			);

			if ( $thumbmail_saved instanceof \RIO_Process_Queue ) {
				$this->_saved_models[] = $thumbmail_saved;
			}
		}

		$count_models = is_array( $this->_saved_models ) ? count( $this->_saved_models ) : 0;
		\WRIO_Plugin::app()->logger->info( sprintf( 'End WebP conversion process for NextGen #%s. Saved models: %d', $model->get_id(), $count_models ) );

		return true;
	}

	/**
	 * Get attachment data such as height, width, absolute path and URL.
	 *
	 * @param WP_Post|int $attachment Attachment to get data for.
	 *
	 * @return array {
	 * Associative array of attachment data and its thumbnails, where key is sha256 hash or attachment URL.
	 *
	 * @type string $size Size of an image, e.g. medium.
	 * @type int $height Height in pixels.
	 * @type int $width Width in pixels.
	 * @type string $mime MIME type, e.g. image/jpeg.
	 * @type string $absolute_path Absolute path to thumbnail.
	 * @type string $url URL path to thumbnail.
	 * }
	 */
	public static function get_attachment_data( $attachment ) {
		$attachment = get_post( $attachment );
		$hashmap    = [];

		if ( empty( $attachment ) ) {
			return $hashmap;
		}

		$dirs = wp_upload_dir();

		if ( isset( $dirs['error'] ) && $dirs['error'] !== false ) {
			return $hashmap;
		}

		$attachment_meta = wp_get_attachment_metadata( $attachment->ID );

		// Fallback to get attachment meta it can be empty when WordPress failed to create it or invocation
		// of method was produced too soon
		if ( empty( $attachment_meta ) ) {
			$exploded_url = explode( 'wp-content/uploads/', $attachment->guid, 2 );

			if ( isset( $exploded_url[1] ) ) {
				$exploded_relative_path = trim( $exploded_url[1] );
				$path_from_url          = trailingslashit( $dirs['basedir'] ) . $exploded_relative_path;

				// Need to remove this filter, as it would start recursion
				remove_filter( 'wp_generate_attachment_metadata', 'WRIO_Media_Library::optimize_after_upload' );

				$attachment_meta = wp_generate_attachment_metadata( $attachment->ID, $path_from_url );

				add_filter( 'wp_generate_attachment_metadata', 'WRIO_Media_Library::optimize_after_upload', 10, 2 );
			}
		}
		if ( empty( $attachment_meta ) ) {
			\WRIO_Plugin::app()->logger->error( sprintf( 'Attachment #%d metadata is empty. Webp image can not be converted.', $attachment->ID ) );

			return $hashmap;
		}

		if ( isset( $dirs['basedir'] ) && isset( $attachment_meta['file'] ) ) {

			$original = [
				'size'          => 'original',
				'height'        => $attachment_meta['height'],
				'width'         => $attachment_meta['width'],
				'absolute_path' => wp_normalize_path( trailingslashit( $dirs['basedir'] ) . $attachment_meta['file'] ),
				'url'           => \WRIO_Url::normalize( trailingslashit( $dirs['baseurl'] ) . $attachment_meta['file'] ),
			];

			$hashmap[ hash( 'sha256', $original['url'] ) ] = $original;

			if ( ! empty( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) {
				foreach ( $attachment_meta['sizes'] as $size => $size_data ) {
					// [2019, 01, somename.jpg]
					$exploded = explode( '/', $attachment_meta['file'] );

					// [2019, 01]
					array_pop( $exploded );

					// [2019, 01, someothername.jpg]
					$exploded[] = $size_data['file'];

					$new_file = implode( '/', $exploded );

					$url           = \WRIO_Url::normalize( trailingslashit( $dirs['baseurl'] ) . $new_file );
					$absolute_path = wp_normalize_path( trailingslashit( $dirs['basedir'] ) . $new_file );
					$hashed_url    = hash( 'sha256', $url );

					$hashmap[ $hashed_url ] = [
						'size'          => $size,
						'height'        => $size_data['height'],
						'width'         => $size_data['width'],
						'mime'          => $size_data['mime-type'],
						'absolute_path' => $absolute_path,
						'url'           => $url,
					];
				}
			}
		}

		return $hashmap;
	}

	/**
	 * Add new image to be converted to WebP.
	 *
	 * @param array                 $props List of properties to be set on the model.
	 * @param \RIOP_WebP_Extra_Data $extra_data List of extra data params
	 *
	 * @return false|\RIO_Process_Queue
	 */
	public function save( $props, $extra_data ) {

		$model = new \RIO_Process_Queue();

		if ( isset( $props['item_hash'] ) ) {
			$model->set_item_hash( $props['item_hash'] );
		}

		if ( isset( $props['item_hash_alternative'] ) ) {
			$model->set_item_hash_alternative( $props['item_hash_alternative'] );
		}

		if ( isset( $props['object_id'] ) ) {
			$model->object_id = $props['object_id'];
		}

		if ( isset( $props['original_mime_type'] ) ) {
			$model->original_mime_type = $props['original_mime_type'];
		}

		// Use the format stored during convert_webp(), or fall back to global setting
		$format = $this->_current_format ?? 'webp';

		$model->item_type        = $format; // 'webp' or 'avif'
		$model->result_status    = \RIO_Process_Queue::STATUS_PROCESSING;
		$model->processing_level = \WRIO_Plugin::app()->getPopulateOption( 'image_optimization_level', \RIO_Process_Queue::LEVEL_NORMAL );
		$model->is_backed_up     = false;
		$model->original_size    = @filesize( $extra_data->get_source_path() );
		$model->final_size       = 0; // to be known
		$model->final_mime_type  = ( $format === 'avif' ) ? 'image/avif' : 'image/webp';
		$model->extra_data       = $extra_data;

		$is_saved = $model->save();

		if ( $is_saved ) {
			\WRIO_Plugin::app()->logger->info( sprintf( 'Saved item with src "%s" and hash "%s" successfully', $extra_data->get_source_src(), $model->get_item_hash() ) );

			return $model;
		}

		\WRIO_Plugin::app()->logger->info( sprintf( 'Failed to save item with src "%s" and hash "%s" as save() method failed, check SQL for errors', $extra_data->get_source_src(), $model->get_item_hash() ) );

		return false;
	}
}
