<?php

/**
 * Abstract class for format conversion API.
 *
 * This base class provides a unified interface for converting images to different formats (WebP, AVIF, etc.)
 * Subclasses must implement format-specific methods.
 *
 * @author Alexander Teshabaev <sasha.tesh@gmail.com>
 */
abstract class WRIO_Format_Converter_Api {

	/**
	 * @var string API url.
	 */
	protected $_api_url = 'https://dashboard.robinoptimizer.com/';

	/**
	 * @var RIO_Process_Queue[]|null Queue models to be processed.
	 */
	protected $_models = null;

	/**
	 * @var null|int UNIX epoch when last request was processed.
	 */
	protected $_last_request_tick = null;

	/**
	 * Get the format name for this converter (e.g., 'webp', 'avif').
	 *
	 * @return string
	 */
	abstract protected function get_format_name();

	/**
	 * Get the file extension for this format (e.g., '.webp', '.avif').
	 *
	 * @return string
	 */
	abstract protected function get_file_extension();

	/**
	 * Get the MIME type for this format (e.g., 'image/webp', 'image/avif').
	 *
	 * @return string
	 */
	abstract protected function get_mime_type();

	/**
	 * Get API query parameters for this format.
	 *
	 * @param bool $quota Whether to include quota-related parameters.
	 *
	 * @return array Query parameters for the API request.
	 */
	abstract protected function get_api_query_params( $quota );

	/**
	 * Check if this format is available for free tier users.
	 *
	 * @return bool True if free users can use this format, false if premium required.
	 */
	abstract protected function is_free_tier_supported();

	/**
	 * WRIO_Format_Converter_Api constructor.
	 *
	 * @param RIO_Process_Queue[] $models Items to be converted.
	 */
	public function __construct( $models ) {
		$this->_models = $models;
	}

	/**
	 * Process image queue.
	 *
	 * When attachment has multiple thumbnails, all of them would be converted one after another.
	 *
	 * @param bool $quota Decrement quota?
	 *
	 * @return bool True on success execution, false on failure or missing item in queue.
	 */
	public function process_image_queue( $quota = false ) {
		$thumb_count = count( $this->_models ) - 1;

		foreach ( $this->_models as $model ) {
			try {
				/**
				 * The data.
				 *
				 * @var RIOP_WebP_Extra_Data|null $extra_data The extra data.
				 */
				$extra_data = $model->get_extra_data();

				if ( null === $extra_data ) {
					continue;
				}

				$response = $this->request( $model, $quota );

				if ( $this->can_save( $response ) && $this->save_file( $response, $model ) ) {
					$extra_data->set_thumbnails_count( $thumb_count );
					$model->set_extra_data( $extra_data );

					$this->update( $model );
				}
			} catch ( Throwable $throwable ) {
				WRIO_Plugin::app()->logger->error(
					sprintf(
						'%1$s conversion failed for queue item #%2$d with unexpected error: %3$s in %4$s:%5$d',
						ucfirst( $this->get_format_name() ),
						$model->get_id(),
						$throwable->getMessage(),
						$throwable->getFile(),
						$throwable->getLine()
					)
				);

				$model->mark_as_error( $throwable->getMessage() );
			}
		}

		return true;
	}

	/**
	 * Request API to convert image.
	 *
	 * @param RIO_Process_Queue $model Queue model.
	 * @param bool              $quota Decrement quota?
	 *
	 * @return array|bool|WP_Error
	 */
	public function request( $model, $quota = false ) {

		if ( $this->_last_request_tick === null ) {
			$this->_last_request_tick = time();
		} else {
			if ( is_int( $this->_last_request_tick ) && ( time() - $this->_last_request_tick ) < 1 ) {
				// Need to have some rest before calling REST :D to comply with API request limit
				sleep( 2 );
			}

			$this->_last_request_tick = time();
		}

		$is_premium = wrio_is_license_activate();

		// Check if this format requires premium license
		if ( ! $is_premium && ! $this->is_free_tier_supported() ) {
			WRIO_Plugin::app()->logger->warning( sprintf( 'To use %s compression you need a premium license', $this->get_format_name() ) );

			return false;
		}

		// Premium users need a valid license key
		if ( $is_premium && ! wrio_get_license_key() ) {
			WRIO_Plugin::app()->logger->error( 'Unable to get license to make proper request to the API' );

			return false;
		}

		$transient_string = md5( WRIO_Plugin::app()->getPrefix() . '_processing_image' . $model->get_item_hash() );

		$transient_value = get_transient( $transient_string );

		if ( is_numeric( $transient_value ) && (int) $transient_value === 1 ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Skipping to wp_remote_get() as transient "%s" already exist. Usually it means that no request was returned yet', $transient_string ) );

			return false;
		}

		set_transient( $transient_string, 1 );

		try {
			$url = $this->_api_url . ( $is_premium ? 'v1/image/convert' : 'v1/free/image/convert' );

			/**
			 * @var RIOP_WebP_Extra_Data $extra_data
			 */
			$extra_data = $model->get_extra_data();

			$multipart_boundary = '--------------------------' . microtime( true );

			// Get format-specific parameters
			$format_params = $this->get_api_query_params( $quota );

			// Build multipart body with form fields FIRST
			$body = '';

			// Add format parameters as form fields
			foreach ( $format_params as $name => $value ) {
				$body .= '--' . $multipart_boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
				$body .= $value . "\r\n";
			}

			// Add image URL if available (use encoded version to preserve special characters)
			$source_url = $extra_data->get_source_src( false );
			if ( ! empty( $source_url ) ) {
				$body .= '--' . $multipart_boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="image_url"' . "\r\n\r\n";
				$body .= wrio_encode_image_url( $source_url ) . "\r\n";
			}

			// Then add the file
			// Check if backup exists and use it for conversion (works for original and thumbnails)
			$source_file_path = $extra_data->get_source_path();
			$backup_enabled   = \WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', false );

			if ( $backup_enabled ) {
				$backup      = \WIO_Backup::get_instance();
				$size_name   = $extra_data->get_converted_from_size(); // 'original', 'thumbnail', 'medium', etc.
				$backup_path = $backup->getAttachmentBackupPath( $model->get_object_id(), $size_name );

				if ( ! empty( $backup_path ) && file_exists( $backup_path ) ) {
					\WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Using backup file for %s: %s', strtoupper( $this->get_format_name() ), $size_name, $backup_path ) );
					$source_file_path = $backup_path;
				} else {
					\WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: No backup found for %s, using current file: %s', strtoupper( $this->get_format_name() ), $size_name, $source_file_path ) );
				}
			}

			if ( empty( $source_file_path ) || ! file_exists( $source_file_path ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( '%s conversion: Source file is missing, unable to build request payload. Path: %s', strtoupper( $this->get_format_name() ), empty( $source_file_path ) ? '*empty path*' : $source_file_path ) );

				return new WP_Error( 'http_request_failed', 'Source image file is missing.' );
			}

			$file_contents = file_get_contents( $source_file_path );

			if ( false === $file_contents ) {
				WRIO_Plugin::app()->logger->error( sprintf( '%s conversion: Failed to read source file contents from %s.', strtoupper( $this->get_format_name() ), $source_file_path ) );

				return new WP_Error( 'http_request_failed', 'Failed to read the source image file.' );
			}

			$body .= '--' . $multipart_boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $source_file_path ) . '"' . "\r\n";
			$body .= 'Content-Type: ' . $model->get_original_mime_type() . "\r\n\r\n";
			$body .= $file_contents . "\r\n";

			$body .= '--' . $multipart_boundary . "--\r\n";

			if ( $is_premium ) {
				$headers = [
					// should be base64 encoded, otherwise API would fail authentication
					'Authorization'    => 'Bearer ' . base64_encode( wrio_get_license_key() ),
					'PluginId'         => wrio_get_freemius_plugin_id(),
					'X-License-Source' => wrio_get_license_source(),
					'X-Site-Url'       => home_url(),
					'Content-Type'     => 'multipart/form-data; boundary=' . $multipart_boundary,
				];
			} else {
				$headers = [
					'Authorization' => 'Bearer ' . base64_encode( home_url() ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $multipart_boundary,
					'X-Site-Url'    => home_url(),
				];
			}

			return wp_remote_post(
				$url,
				[
					'timeout' => 60,
					'headers' => $headers,
					'body'    => $body,
				]
			);
		} finally {
			delete_transient( $transient_string );
		}
	}

	/**
	 * Check if response can be saved.
	 *
	 * @param array|WP_Error|false $response
	 *
	 * @return bool True means response image was successfully saved, false on failure.
	 */
	public function can_save( $response ) {
		WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Checks to save a %s by response.', ucfirst( $this->get_format_name() ), $this->get_format_name() ) );

		if ( is_wp_error( $response ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Error response from API. Code: %s, error: %s', $response->get_error_code(), $response->get_error_message() ) );

			return false;
		}

		if ( false === $response ) {
			WRIO_Plugin::app()->logger->error( 'Unknown response returned from API or it was not requested, failing to process response' );

			return false;
		}

		// Check for premium API response (binary with content-disposition header)
		$content_disposition = wp_remote_retrieve_header( $response, 'content-disposition' );

		if ( 0 === strpos( $content_disposition, 'attachment;' ) ) {

			$body = wp_remote_retrieve_body( $response );

			if ( empty( $body ) ) {
				WRIO_Plugin::app()->logger->error( 'Response returned content-disposition header as "attachment;", but empty body returned, failing to proceed' );

				return false;
			}

			WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Image can be saved (premium format).', ucfirst( $this->get_format_name() ) ) );

			return true;
		}

		// Check for free API response (JSON with download URL)
		$response_text = wp_remote_retrieve_body( $response );

		if ( ! empty( $response_text ) ) {
			$response_json = json_decode( $response_text );

			if ( ! empty( $response_json ) ) {
				// Check for successful free API response
				if (
					isset( $response_json->status ) && 'ok' === $response_json->status
					&& isset( $response_json->response->dest ) && ! empty( $response_json->response->dest )
				) {
					WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Image can be saved (free format with URL).', ucfirst( $this->get_format_name() ) ) );

					return true;
				}

				// Handle errors
				if ( isset( $response_json->error ) && ! empty( $response_json->error ) ) {
					WRIO_Plugin::app()->logger->error( sprintf( 'Unable to convert attachment as API returned error: "%s"', wp_json_encode( $response_json ) ) );
				}

				if ( isset( $response_json->status ) && 401 === (int) $response_json->status ) {
					WRIO_Plugin::app()->logger->error( sprintf( 'Error response from API. Code: %s, error: %s', $response_json->message, $response_json->code ) );
				}
			}
		}

		return false;
	}

	/**
	 * Save file from response.
	 *
	 * It is assumed that it was checked by can_save() method.
	 *
	 * @param array|WP_Error|false $response
	 * @param RIO_Process_Queue    $queue_model
	 *
	 * @return bool
	 * @see can_save() for further information.
	 */
	public function save_file( $response, $queue_model ) {
		try {
			$save_path = $this->get_save_path( $queue_model );
		} catch ( Throwable $exception ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to process response failed to get save path: "%s"', $exception->getMessage() ) );

			return false;
		}

		WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Try to save %s image in %s.', ucfirst( $this->get_format_name() ), $this->get_format_name(), $save_path ) );

		// Check if this is a free API response (JSON with download URL)
		$response_text = wp_remote_retrieve_body( $response );
		$response_json = json_decode( $response_text );

		if (
			! empty( $response_json ) && isset( $response_json->status ) && 'ok' === $response_json->status
			&& isset( $response_json->response->dest ) && ! empty( $response_json->response->dest )
		) {
			// Free API: Download image from the provided URL
			$download_url = $response_json->response->dest;
			WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Downloading from free API URL: %s', ucfirst( $this->get_format_name() ), $download_url ) );

			$download_response = wp_remote_get( $download_url, [ 'timeout' => 60 ] );

			if ( is_wp_error( $download_response ) ) {
				WRIO_Plugin::app()->logger->error( sprintf( 'Failed to download converted image from %s: %s', $download_url, $download_response->get_error_message() ) );

				return false;
			}

			$body = wp_remote_retrieve_body( $download_response );
		} else {
			// Premium API: Image data is directly in the response body
			$body = $response_text;
		}

		$file_saved = @file_put_contents( $save_path, $body );

		if ( ! $file_saved ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to save file "%s" with file_put_contents()', $save_path ) );

			return false;
		}

		WRIO_Plugin::app()->logger->info( sprintf( '%s conversion: Image saved successfully!', ucfirst( $this->get_format_name() ) ) );

		return true;
	}

	/**
	 * Update processing item data to finish its cycle.
	 *
	 * @param RIO_Process_Queue $queue_model Queue model to be update.
	 *
	 * @return bool
	 */
	public function update( $queue_model ) {

		try {
			$save_path = $this->get_save_path( $queue_model );
		} catch ( \Exception $exception ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to update queue model #%s as of exception: %s', $queue_model->get_id(), $exception->getMessage() ) );

			return false;
		}

		$queue_model->result_status = RIO_Process_Queue::STATUS_SUCCESS;
		$queue_model->final_size    = wrio_get_file_size( $save_path );

		$image_statistics = WRIO_Image_Statistic::get_instance();
		wp_suspend_cache_addition( true ); // Stop caching
		$stat_field = $this->get_format_name() . '_optimized_size';
		$image_statistics->addToField( $stat_field, $queue_model->final_size );
		$image_statistics->save();
		wp_suspend_cache_addition(); // Resume caching

		/**
		 * @var RIOP_WebP_Extra_Data $updated_extra_data
		 */
		$updated_extra_data = $queue_model->get_extra_data();
		$updated_extra_data->set_converted_src( $this->get_save_url( $queue_model ) );
		$updated_extra_data->set_converted_path( $save_path );

		$queue_model->extra_data = $updated_extra_data;

		/**
		 * Hook fires after successful format conversion
		 *
		 * @param RIO_Process_Queue $queue_model
		 * @param string            $format Format name ('webp', 'avif', etc.)
		 *
		 * @since 1.2.0
		 */
		do_action( 'wbcr/rio/format_conversion_success', $queue_model, $this->get_format_name() );

		// Backward compatibility hook for WebP
		if ( $this->get_format_name() === 'webp' ) {
			do_action( 'wbcr/rio/webp_success', $queue_model );
		}

		return $queue_model->save();
	}

	/**
	 * Get complete save url.
	 *
	 * @param RIO_Process_Queue $queue_model Instance of queue item.
	 *
	 * @return string
	 */
	public function get_save_url( $queue_model ) {
		/**
		 * @var $extra_data RIOP_WebP_Extra_Data
		 */
		$extra_data = $queue_model->get_extra_data();

		if ( empty( $extra_data ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to get extra data for queue item #%s', $queue_model->get_id() ) );

			return null;
		}

		$origin_file_name    = wp_basename( $extra_data->get_source_src() );
		$converted_file_name = trim( wp_basename( $extra_data->get_source_path() ) ) . $this->get_file_extension();

		return str_replace( $origin_file_name, $converted_file_name, $extra_data->get_source_src() );
	}

	/**
	 * Get absolute save path.
	 *
	 * @param \RIO_Process_Queue $queue_model
	 *
	 * @return string
	 * @throws Exception on failure to create missing directory
	 */
	public function get_save_path( $queue_model ) {
		/**
		 * @var $extra_data RIOP_WebP_Extra_Data
		 */
		$extra_data = $queue_model->get_extra_data();

		if ( empty( $extra_data ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to get extra data for queue item #%s', $queue_model->get_id() ) );

			return null;
		}

		$path = dirname( $extra_data->get_source_path() );

		// Create DIR when does not exist
		if ( ! file_exists( $path ) ) {
			$message = sprintf( 'Failed to create directory %s with mode %s recursively', $path, 0755 );
			WRIO_Plugin::app()->logger->error( $message );
			throw new \Exception( $message );
		}

		return trailingslashit( $path ) . trim( wp_basename( $extra_data->get_source_path() ) ) . $this->get_file_extension();
	}
}
