<?php
/**
 * The WebP and AVIF settings.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WebP and AVIF settings: which formats the plugin makes and delivers.
 */
class WRIO_Formats {

	/**
	 * Get array of enabled conversion formats.
	 *
	 * @return list<'webp'|'avif'> Array of enabled format names ('webp', 'avif').
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

		return in_array( $option, [ true, 1, '1' ], true );
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

		return in_array( $option, [ true, 1, '1' ], true );
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
