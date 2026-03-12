<?php

namespace WRIO\WEBP\HTML;

// Exit if accessed directly
use WRIO_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO\WEBP\HTML\Delivery converts and replace JPEG & PNG images within HTML doc.
 *
 * Images converted via third-party service, saved locally and then replaced based on parsed DOM <img>, or other elements.
 *
 * @link          https://css-tricks.com/using-webp-images/
 * @link          https://dev.opera.com/articles/responsive-images/#different-image-types-use-case
 * @link          https://ru.wordpress.org/plugins/webp-express/
 * @link          https://github.com/rosell-dk/
 * @version       1.0
 */
class Delivery {

	/**
	 * Legacy constant for no delivery mode.
	 *
	 * @var string
	 */
	const NONE_DELIVERY_MODE     = 'none';
	const DEFAULT_DELIVERY_MODE  = 'picture';
	const PICTURE_DELIVERY_MODE  = 'picture';
	const URL_DELIVERY_MODE      = 'url';
	const REDIRECT_DELIVERY_MODE = 'redirect';

	/**
	 * WRIO_Webp constructor.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initiate the class.
	 */
	public function init() {

		if ( ! static::should_use_converted_images() ) {
			return;
		}

		if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
			\WRIO_Plugin::app()->logger->info( sprintf( 'WebP/AVIF option enabled and browser "%s" is supported, ready to process buffer', isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '*undefined*' ) );
			\WRIO_Plugin::app()->logger->info( sprintf( 'WebP/AVIF delivery mode: %s', static::get_delivery_mode() ) );
		}

		/*
		TODO:
			Which hook should we use, and should we make it optional?
			- Cache enabler uses 'template_redirect'
			- ShortPixes uses 'init'

			We go with template_redirect now, because it is the "innermost".
			This lowers the risk of problems with plugins used rewriting URLs to point to CDN.
			(We need to process the output *before* the other plugin has rewritten the URLs,
			if the "Only for webps that exists" feature is enabled)
		*/

		if ( static::is_delivery_mode_enabled() ) {
			// Use template_redirect for frontend output buffering (fires during page render)
			// This is more reliable than 'init' as it only fires on frontend requests
			add_action( 'template_redirect', [ $this, 'process_buffer' ], 1 );
		}

		if ( static::is_picture_delivery_mode() ) {
			add_action( 'wp_head', [ $this, 'add_picture_fill_js' ] );
		}
	}

	/**
	 * Check whether any format conversion is enabled (WebP or AVIF).
	 *
	 * @return bool
	 */
	public static function should_use_converted_images() {
		return \WRIO_Format_Converter_Factory::is_format_conversion_enabled();
	}

	/**
	 * @return bool
	 * @since  1.0.4
	 */
	public static function is_redirect_delivery_mode() {
		return self::REDIRECT_DELIVERY_MODE === static::get_delivery_mode();
	}

	/**
	 * @return bool
	 * @since  1.0.4
	 */
	public static function is_picture_delivery_mode() {
		return self::PICTURE_DELIVERY_MODE === static::get_delivery_mode();
	}

	/**
	 * @return bool
	 * @since  1.0.4
	 */
	public static function is_url_delivery_mode() {
		return self::URL_DELIVERY_MODE === static::get_delivery_mode();
	}

	/**
	 * Check whether any delivery mode is enabled that modifies HTML output.
	 *
	 * @return bool
	 */
	public static function is_delivery_mode_enabled() {
		$delivery_mode = static::get_delivery_mode();

		return in_array(
			$delivery_mode,
			[
				self::PICTURE_DELIVERY_MODE,
				self::URL_DELIVERY_MODE,
				self::NONE_DELIVERY_MODE,
			],
			true
		);
	}

	/**
	 * @return string
	 * @since  1.0.4
	 */
	public static function get_delivery_mode() {
		$delivery_mode = \WRIO_Plugin::app()->getPopulateOption( 'webp_delivery_mode' );

		if ( ! empty( $delivery_mode ) ) {
			return $delivery_mode;
		}

		return self::DEFAULT_DELIVERY_MODE;
	}

	/**
	 * @since  1.0.4
	 */
	public function add_picture_fill_js() {
		// Don't do anything with the RSS feed.
		// - and no need for PictureJs in the admin
		if ( is_feed() || is_admin() ) {
			return;
		}

		echo '<script>' . 'document.createElement( "picture" );' . 'if(!window.HTMLPictureElement && document.addEventListener) {' . 'window.addEventListener("DOMContentLoaded", function() {' . 'var s = document.createElement("script");' . 's.src = "' . WRIOP_PLUGIN_URL . '/assets/js/picturefill.min.js' . '";' . 'document.body.appendChild(s);' . '});' . '}' . '</script>';
	}

	/**
	 * Process HTML template buffer.
	 */
	public function process_buffer() {
		// template_redirect only fires on frontend, so no need to check is_admin() or AJAX
		ob_start( [ $this, 'process_alter_html' ] );
	}

	/**
	 * Process tags to replace those elements which match converted to WebP within buffer.
	 *
	 * @param string $content HTML buffer.
	 *
	 * @return string
	 */
	public function process_alter_html( $content ) {
		$raw_content = $content;

		// Don't do anything with the RSS feed.
		if ( is_feed() || empty( $content ) || ! is_null( json_decode( $content ) ) ) {
			// WRIO_Plugin::app()->logger->info( "Buffer content is empty, skipping processing" );
			return $content;
		}
		if ( static::is_picture_delivery_mode() ) {
			if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
				// for AMP pages the <picture> tag is not allowed
				return $content;
			}

			require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-html-picture-tags.php';
			$content = Picture_Tags::replace( $content );
		} elseif ( static::is_url_delivery_mode() ) {
			if ( ! is_admin() ) {
				require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-html-image-urls-replacer.php';
				$content = Urls_Replacer::replace( $content );
			}
		}

		// If the search and replacement are completed with an error, then return the raw content.
		// If this is not prevented, in case of an error the user will receive a white screen.
		if ( empty( $content ) ) {
			if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
				\WRIO_Plugin::app()->logger->warning( sprintf( 'Failed search and replace urls. Empty result received (%s).', base64_encode( $content ) ) );
			}

			return $raw_content;
		}

		return $content;
	}

	/**
	 *  Get url for webp
	 *  returns second argument if no webp
	 *
	 * @param string $source_url (ie http://example.com/wp-content/image.jpg)
	 * @param string $return_value_on_fail
	 *
	 * @return string
	 */
	/**
	 * Get URL for converted format (WebP or AVIF).
	 *
	 * Checks for available converted formats in order of preference (AVIF first, then WebP).
	 *
	 * @param string $source_url           Original image URL.
	 * @param string $return_value_on_fail Value to return if conversion not found.
	 *
	 * @return string Converted image URL or fallback value.
	 * @since 1.9.0
	 */
	public static function get_converted_url( $source_url, $return_value_on_fail ) {
		$enabled_formats = \WRIO_Format_Converter_Factory::get_enabled_formats();

		if ( empty( $enabled_formats ) || ! static::is_support_format( $source_url ) ) {
			if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
				\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image lookup. Original image format is not supported for conversion, or converted image delivery is disabled.\r\nSource url: %s", $source_url ) );
			}

			return $return_value_on_fail;
		}

		if ( ! preg_match( '#^https?://#', $source_url ) ) {
			$source_url = wrio_rel_to_abs_url( $source_url );
		}

		$is_wpmedia_url = static::is_wpmedia_url( $source_url );

		// If the image is stored on a remote server, need to skip it
		if ( static::is_external_url( $source_url ) && ! $is_wpmedia_url ) {
			if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
				\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image lookup. Image is hosted on a remote server.\r\nSource url: %s", $source_url ) );
			}

			return $return_value_on_fail;
		}

		if ( $is_wpmedia_url ) {
			$upload_dir = wp_get_upload_dir();

			$source_parts = wp_parse_url( $source_url );
			$base_parts   = wp_parse_url( $upload_dir['baseurl'] );

			// If URL parsing fails, bail
			if ( empty( $source_parts['path'] ) || empty( $base_parts['path'] ) ) {
				return $return_value_on_fail;
			}

			// Must be same host to treat it as local upload (ignore scheme)
			$source_host = $source_parts['host'] ?? '';
			$base_host   = $base_parts['host'] ?? '';

			if ( $source_host && $base_host && strtolower( $source_host ) !== strtolower( $base_host ) ) {
				return $return_value_on_fail;
			}

			// Path must start with uploads base path
			$base_path   = rtrim( $base_parts['path'], '/' );          // eg /app/uploads
			$source_path = $source_parts['path'];                      // eg /app/uploads/2025/12/demo.png

			if ( strpos( $source_path, $base_path . '/' ) !== 0 ) {
				return $return_value_on_fail;
			}

			// Convert URL path to filesystem path
			$relative  = ltrim( substr( $source_path, strlen( $base_path ) ), '/' );
			$file_path = trailingslashit( $upload_dir['basedir'] ) . $relative;
		} else {
			$file_path = wrio_url_to_abs_path( $source_url );
		}

		// If you could not find original image, skip it. Perhaps an error
		// in absolute path formation to the directory where the
		// image is stored.
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
				\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image lookup. Unable to find the original image on disk.\r\nRelative path: (%s)\r\nSource url: (%s)", $file_path, $source_url ) );
			}

			return $return_value_on_fail;
		}

		// Check AVIF first if enabled, then WebP.
		foreach ( $enabled_formats as $format ) {
			$extension           = '.' . strtolower( $format );
			$converted_file_path = $file_path . $extension;

			if ( file_exists( $converted_file_path ) ) {
				return $source_url . $extension;
			}
		}

		if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
			\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image delivery. No converted file was found for the original image.\r\nSource url: %s\r\nChecked formats: %s", $source_url, implode( ', ', $enabled_formats ) ) );
		}

		return $return_value_on_fail;
	}

	/**
	 * Get WebP URL (backward compatibility wrapper).
	 *
	 * @param string $source_url           Original image URL.
	 * @param string $return_value_on_fail Value to return if WebP not found.
	 *
	 * @return string WebP image URL or fallback value.
	 * @deprecated Use get_converted_url() instead.
	 */
	public static function get_webp_url( $source_url, $return_value_on_fail ) {
		return static::get_converted_url( $source_url, $return_value_on_fail );
	}

	/**
	 * @param string $source_url
	 *
	 * @return bool
	 * @since  1.4.0
	 */
	protected static function is_wpmedia_url( $source_url ) {
		$upload_dir = wp_get_upload_dir();

		if ( isset( $upload_dir['error'] ) && $upload_dir['error'] !== false ) {
			return false;
		}

		// Normalize both URLs to https for comparison.
		$source_url_normalized = set_url_scheme( $source_url, 'https' );
		$baseurl_normalized    = set_url_scheme( $upload_dir['baseurl'], 'https' );

		return false !== strpos( $source_url_normalized, $baseurl_normalized );
	}

	/**
	 * @param string $source_url
	 *
	 * @return bool
	 * @since  1.4.0
	 */
	protected static function is_support_format( $source_url ) {
		// Match .jpg, .jpeg, or .png at end of URL (before optional query string)
		if ( ! preg_match( '#\.(jpe?g|png)($|\?)#i', $source_url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $source_url
	 *
	 * @return bool
	 * @since  1.4.0
	 */
	protected static function is_external_url( $source_url ) {
		if ( strpos( $source_url, get_site_url() ) === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether browser supports WebP or not.
	 *
	 * @return bool
	 */
	protected static function is_supported_browser() {
		if ( isset( $_SERVER['HTTP_ACCEPT'] ) && strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false || isset( $_SERVER['HTTP_USER_AGENT'] ) && strpos( $_SERVER['HTTP_USER_AGENT'], ' Chrome/' ) !== false ) {
			return true;
		}

		return false;
	}
}
