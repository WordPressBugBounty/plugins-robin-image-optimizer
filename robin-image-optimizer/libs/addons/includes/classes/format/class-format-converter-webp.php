<?php

/**
 * WebP format converter implementation.
 *
 * Converts images to WebP format using the Robin Image Optimizer API.
 *
 * @author Alexander Teshabaev <sasha.tesh@gmail.com>
 */
class WRIO_Format_Converter_WebP extends WRIO_Format_Converter_Api {

	/**
	 * Get the format name.
	 *
	 * @return string
	 */
	protected function get_format_name() {
		return 'webp';
	}

	/**
	 * Get the file extension for WebP format.
	 *
	 * @return string
	 */
	protected function get_file_extension() {
		return '.webp';
	}

	/**
	 * Get the MIME type for WebP format.
	 *
	 * @return string
	 */
	protected function get_mime_type() {
		return 'image/webp';
	}

	/**
	 * Get API query parameters for WebP conversion.
	 *
	 * @param bool $quota Whether to include quota-related parameters.
	 *
	 * @return array Query parameters for the API request.
	 */
	protected function get_api_query_params( $quota ) {
		$params = [ 'format' => 'webp' ];

		if ( $quota ) {
			$params['type'] = 'webp';  // Only add 'type' for quota check
		}

		return $params;
	}

	/**
	 * Check if WebP format is available for free tier users.
	 *
	 * @return bool True - WebP is available for free users.
	 */
	protected function is_free_tier_supported() {
		return true;
	}

	/**
	 * Static wrapper for get_save_path for backward compatibility.
	 *
	 * @param \RIO_Process_Queue $queue_model
	 *
	 * @return string
	 * @throws Exception
	 */
	public static function get_save_path_static( $queue_model ) {
		$extra_data = $queue_model->get_extra_data();

		if ( empty( $extra_data ) ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Unable to get extra data for queue item #%s', $queue_model->get_id() ) );

			return null;
		}

		$path = dirname( $extra_data->get_source_path() );

		if ( ! file_exists( $path ) ) {
			$message = sprintf( 'Failed to create directory %s with mode %s recursively', $path, 0755 );
			WRIO_Plugin::app()->logger->error( $message );
			throw new \Exception( $message );
		}

		return trailingslashit( $path ) . trim( wp_basename( $extra_data->get_source_path() ) ) . '.webp';
	}
}
