<?php

/**
 * Factory class for creating format converter instances.
 *
 * Provides a centralized way to create format converters and detect enabled formats.
 *
 * @author Alexander Teshabaev <sasha.tesh@gmail.com>
 */
class WRIO_Format_Converter_Factory {

	/**
	 * Create a format converter instance based on the specified format.
	 *
	 * @param RIO_Process_Queue[] $models  Queue models to process.
	 * @param string              $format  Format name ('webp' or 'avif').
	 *
	 * @return WRIO_Format_Converter_Api Format converter instance.
	 */
	public static function create( $models, $format ) {
		switch ( $format ) {
			case 'avif':
				return new WRIO_Format_Converter_AVIF( $models );
			case 'webp':
			default:
				return new WRIO_Format_Converter_WebP( $models );
		}
	}

	/**
	 * Get array of enabled conversion formats.
	 *
	 * @return string[] Array of enabled format names ('webp', 'avif').
	 */
	public static function get_enabled_formats() {
		$formats = [];

		if ( self::is_avif_enabled() ) {
			$formats[] = 'avif';
		}

		if ( self::is_webp_enabled() ) {
			$formats[] = 'webp';
		}

		return $formats;
	}

	/**
	 * Check if WebP conversion is enabled.
	 *
	 * @return bool True if WebP conversion is enabled.
	 */
	public static function is_webp_enabled() {
		$option = WRIO_Plugin::app()->getPopulateOption( 'convert_webp_format', false );

		return $option === true || $option === 1 || $option === '1';
	}

	/**
	 * Check if AVIF conversion is enabled.
	 *
	 * @return bool True if AVIF conversion is enabled and user has premium license.
	 */
	public static function is_avif_enabled() {
		if ( ! wrio_is_license_activate() ) {
			return false;
		}

		$option = WRIO_Plugin::app()->getPopulateOption( 'convert_avif_format', false );

		return $option === true || $option === 1 || $option === '1';
	}

	/**
	 * Check if any format conversion is enabled.
	 *
	 * @return bool True if WebP or AVIF conversion is enabled.
	 */
	public static function is_format_conversion_enabled() {
		return self::is_webp_enabled() || self::is_avif_enabled();
	}
}
