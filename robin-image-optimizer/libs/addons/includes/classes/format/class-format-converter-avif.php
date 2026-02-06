<?php

/**
 * AVIF format converter implementation.
 *
 * Converts images to AVIF format using the Robin Image Optimizer API.
 * AVIF provides superior compression compared to WebP but requires premium license.
 *
 * @author Alexander Teshabaev <sasha.tesh@gmail.com>
 */
class WRIO_Format_Converter_AVIF extends WRIO_Format_Converter_Api {

	/**
	 * Get the format name.
	 *
	 * @return string
	 */
	protected function get_format_name() {
		return 'avif';
	}

	/**
	 * Get the file extension for AVIF format.
	 *
	 * @return string
	 */
	protected function get_file_extension() {
		return '.avif';
	}

	/**
	 * Get the MIME type for AVIF format.
	 *
	 * @return string
	 */
	protected function get_mime_type() {
		return 'image/avif';
	}

	/**
	 * Get API query parameters for AVIF conversion.
	 *
	 * @param bool $quota Whether to include quota-related parameters.
	 *
	 * @return array Query parameters for the API request.
	 */
	protected function get_api_query_params( $quota ) {
		// AVIF does not use 'type' parameter per requirements
		return [ 'format' => 'avif' ];
	}

	/**
	 * Check if AVIF format is available for free tier users.
	 *
	 * @return bool False - AVIF requires premium license.
	 */
	protected function is_free_tier_supported() {
		return false;
	}
}
