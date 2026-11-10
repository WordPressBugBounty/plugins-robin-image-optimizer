<?php
/**
 * WebP and AVIF delivery in the HTML output.
 *
 * @package Robin_Image_Optimizer
 */

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
	 *
	 * @return void
	 */
	public function init() {

		if ( ! static::should_use_converted_images() || static::skip_for_optimole() ) {
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
		return \WRIO_Formats::is_format_conversion_enabled();
	}

	/**
	 * Check whether Robin leaves the page markup to Optimole.
	 *
	 * Optimole moves the image URLs to its CDN and serves WebP/AVIF from there,
	 * so a second rewrite by Robin can conflict with it.
	 *
	 * @return bool
	 */
	public static function skip_for_optimole(): bool {
		/**
		 * Filters whether Robin turns off its markup delivery because Optimole is active.
		 *
		 * @param bool $skip True when Optimole is active.
		 */
		return (bool) apply_filters( 'wrio_skip_delivery_for_optimole', defined( 'OPTML_VERSION' ) || class_exists( 'Optml_Main', false ) );
	}

	/**
	 * Check whether the redirect delivery mode is on.
	 *
	 * @return bool
	 * @since  1.0.4
	 */
	public static function is_redirect_delivery_mode() {
		return self::REDIRECT_DELIVERY_MODE === static::get_delivery_mode();
	}

	/**
	 * Check whether the picture delivery mode is on.
	 *
	 * @return bool
	 * @since  1.0.4
	 */
	public static function is_picture_delivery_mode() {
		return self::PICTURE_DELIVERY_MODE === static::get_delivery_mode();
	}

	/**
	 * Check whether the URL delivery mode is on.
	 *
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
	 * Get the delivery mode.
	 *
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
	 * Prints the layout rules of the picture tags in the head. Picturefill is gone: every browser since 2016 reads
	 * <picture>, and an older one still shows the original image of the <img>.
	 *
	 * @return void
	 * @since  1.0.4
	 */
	public function add_picture_fill_js() {
		// Don't do anything with the RSS feed.
		// - and no need for PictureJs in the admin
		if ( is_feed() || is_admin() ) {
			return;
		}

		// Astra and constrained block layouts size direct children. Keep their picture boxes out of the flow.
		// The wrapper rule has zero specificity, so theme rules win. Max-width and width inherit through picture>img
		// (0,0,2): a bare img rule (0,0,1) loses also when it loads later, a class rule (0,1,1) wins. Margins have
		// zero specificity to preserve theme image margins.
		// Only inherit a theme width: inheriting auto would replace the image's HTML width with its natural size.
		// Plain .entry-content stays untouched for Twenty Twenty and Twenty Twenty-One. Guard every rule for :has().
		echo '<style id="wrio-picture-layout">@supports selector(:has(a)){'
			. ':where(.entry-content[data-ast-blocks-layout],.is-layout-constrained)>:where(picture:has(>img.webpexpress-processed)){display:contents;max-width:100%}'
			. ':where(.entry-content[data-ast-blocks-layout],.is-layout-constrained)>picture>img:where(.webpexpress-processed){max-width:inherit}'
			. ':where(.entry-content[data-ast-blocks-layout],.is-layout-constrained)>:where(picture)>:where(img.webpexpress-processed){margin:inherit}'
			. ':where(.is-layout-constrained)>picture:where([data-wrio-width])>img:where(.webpexpress-processed){width:inherit}'
			. '}</style>';

		// Theme widths can change at a breakpoint. A display:contents picture keeps its computed width value.
		echo '<script>if(window.CSS && CSS.supports("selector(:has(a))")){'
			. 'var wrioPictureWidth=function(){document.querySelectorAll(".is-layout-constrained > picture:has(> img.webpexpress-processed)").forEach(function(p){p.toggleAttribute("data-wrio-width",getComputedStyle(p).width!=="auto");});};'
			. 'document.addEventListener("DOMContentLoaded",wrioPictureWidth);window.addEventListener("resize",wrioPictureWidth);'
			. '}</script>';
	}

	/**
	 * Process HTML template buffer.
	 *
	 * @return void
	 */
	public function process_buffer() {
		// PHP forbids including files from inside an output buffer handler,
		// so load the markup processor upfront.
		static::load_markup_processor();

		// template_redirect only fires on frontend, so no need to check is_admin() or AJAX
		ob_start( [ $this, 'process_alter_html' ] );
	}

	/**
	 * Load the markup processor class required by the active delivery mode.
	 *
	 * Must run outside the output buffer handler.
	 *
	 * @return bool Whether a processor for the active delivery mode is available.
	 */
	public static function load_markup_processor() {
		if ( static::is_picture_delivery_mode() ) {
			if ( ! class_exists( __NAMESPACE__ . '\Picture_Tags' ) ) {
				require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-html-picture-tags.php';
			}

			return class_exists( __NAMESPACE__ . '\Picture_Tags' );
		}

		if ( static::is_url_delivery_mode() ) {
			if ( ! class_exists( __NAMESPACE__ . '\Urls_Replacer' ) ) {
				require_once WRIOP_PLUGIN_DIR . '/includes/classes/webp/class-webp-html-image-urls-replacer.php';
			}

			return class_exists( __NAMESPACE__ . '\Urls_Replacer' );
		}

		return false;
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
			return $content;
		}
		if ( static::is_picture_delivery_mode() ) {
			if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
				// for AMP pages the <picture> tag is not allowed
				return $content;
			}

			// Loaded by process_buffer(); bail out rather than include from inside the buffer handler.
			if ( ! class_exists( __NAMESPACE__ . '\Picture_Tags', false ) ) {
				return $content;
			}

			$content = Picture_Tags::replace( $content );
		} elseif ( static::is_url_delivery_mode() ) {
			if ( ! is_admin() ) {
				if ( ! class_exists( __NAMESPACE__ . '\Urls_Replacer', false ) ) {
					return $content;
				}

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
		$enabled_formats = \WRIO_Formats::get_enabled_formats();

		foreach ( $enabled_formats as $format ) {
			$url = static::get_format_url( $source_url, $format );

			if ( null !== $url ) {
				return $url;
			}
		}

		if ( ! empty( $enabled_formats ) && null !== static::source_path( $source_url ) && \WRIO_Plugin::app()->is_keep_error_log_on_frontend() && ! static::has_failed_conversion( $source_url, $enabled_formats ) ) {
			\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image delivery. No converted file was found for the original image.\r\nSource url: %s\r\nChecked formats: %s", $source_url, implode( ', ', $enabled_formats ) ) );
		}

		return $return_value_on_fail;
	}

	/**
	 * The URL of one converted format of an image, when that format is enabled and its file exists.
	 *
	 * The answers are kept for the request: a page can show the same image many times, in each srcset.
	 *
	 * @param string $source_url Original image URL.
	 * @param string $format     webp or avif.
	 *
	 * @return string|null The URL, with the format extension after the path (before a query string).
	 */
	public static function get_format_url( $source_url, $format ): ?string {
		static $urls = [];

		$key = $format . '|' . $source_url;

		if ( ! array_key_exists( $key, $urls ) ) {
			$path         = in_array( $format, \WRIO_Formats::get_enabled_formats(), true ) ? static::source_path( $source_url ) : null;
			$absolute     = preg_match( '#^https?://#', $source_url ) ? $source_url : wrio_rel_to_abs_url( $source_url );
			$urls[ $key ] = null !== $path && static::is_file( $path . '.' . $format ) ? (string) preg_replace( '/(?=[?#]|$)/', '.' . $format, $absolute, 1 ) : null;
		}

		return $urls[ $key ];
	}

	/**
	 * The file of a local image URL.
	 *
	 * @param string $source_url Image URL.
	 *
	 * @return string|null The path, or null for an unsupported, remote or missing image.
	 */
	protected static function source_path( $source_url ): ?string {
		$source_url = (string) preg_replace( '/[?#].*/', '', $source_url );

		if ( ! static::is_support_format( $source_url ) ) {
			if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
				\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image lookup. Original image format is not supported for conversion.\r\nSource url: %s", $source_url ) );
			}

			return null;
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

			return null;
		}

		if ( $is_wpmedia_url ) {
			$upload_dir = wp_get_upload_dir();

			$source_parts = wp_parse_url( $source_url );
			$base_parts   = wp_parse_url( $upload_dir['baseurl'] );

			if ( empty( $source_parts['path'] ) || empty( $base_parts['path'] ) ) {
				return null;
			}

			// Must be same host to treat it as local upload (ignore scheme)
			$source_host = $source_parts['host'] ?? '';
			$base_host   = $base_parts['host'] ?? '';

			if ( $source_host && $base_host && strtolower( $source_host ) !== strtolower( $base_host ) ) {
				return null;
			}

			// Path must start with uploads base path
			$base_path   = rtrim( $base_parts['path'], '/' );          // eg /app/uploads
			$source_path = $source_parts['path'];                      // eg /app/uploads/2025/12/demo.png

			if ( strpos( $source_path, $base_path . '/' ) !== 0 ) {
				return null;
			}

			// Convert URL path to filesystem path
			$relative  = ltrim( substr( $source_path, strlen( $base_path ) ), '/' );
			$file_path = trailingslashit( $upload_dir['basedir'] ) . $relative;
		} else {
			$file_path = wrio_url_to_abs_path( $source_url );
		}

		// Decode the URL path once, only for the disk lookup.
		if ( ! empty( $file_path ) ) {
			$file_path = rawurldecode( $file_path );
		}

		// If you could not find original image, skip it. Perhaps an error
		// in absolute path formation to the directory where the
		// image is stored.
		if ( empty( $file_path ) || ! static::is_file( $file_path ) ) {
			if ( \WRIO_Plugin::app()->is_keep_error_log_on_frontend() ) {
				\WRIO_Plugin::app()->logger->warning( sprintf( "Skipped converted image lookup. Unable to find the original image on disk.\r\nRelative path: (%s)\r\nSource url: (%s)", $file_path, $source_url ) );
			}

			return null;
		}

		return $file_path;
	}

	/**
	 * Whether a file exists, checked once for each path in a request: one image can have many URLs on a page.
	 *
	 * @param string $path File path.
	 *
	 * @return bool
	 */
	public static function is_file( string $path ): bool {
		static $files = [];

		if ( ! isset( $files[ $path ] ) ) {
			$files[ $path ] = is_file( $path );
		}

		return $files[ $path ];
	}

	/**
	 * Check whether a terminal conversion failure is already recorded for the source URL.
	 *
	 * The conversion failure is persisted to the queue and surfaced there, so the
	 * missing-converted-file warning would only repeat known information on every request.
	 *
	 * @param string   $source_url      Original image URL.
	 * @param string[] $enabled_formats Enabled conversion formats.
	 *
	 * @return bool True when at least one enabled format has a failed conversion recorded.
	 */
	public static function has_failed_conversion( $source_url, $enabled_formats ) {
		static $failed_hash_cache = [];

		if ( empty( $enabled_formats ) ) {
			return false;
		}

		// Queue rows are seeded from the attachment metadata URL, which carries no query string or fragment.
		$normalized_url = preg_replace( '/[?#].*/', '', $source_url );

		$candidate_hashes = [];

		foreach ( $enabled_formats as $format ) {
			$candidate_hashes[] = \RIO_Process_Queue::generate_item_hash( $normalized_url . '|' . strtolower( $format ) );
		}

		// Look up only this image's candidate hashes (unique `item_hash` index) instead of
		// materializing the full failure set, which is unbounded after a conversion outage.
		$unknown_hashes = [];

		foreach ( $candidate_hashes as $item_hash ) {
			if ( ! array_key_exists( $item_hash, $failed_hash_cache ) ) {
				$unknown_hashes[] = $item_hash;
			}
		}

		if ( ! empty( $unknown_hashes ) ) {
			global $wpdb;

			$table_name   = \RIO_Process_Queue::table_name();
			$placeholders = implode( ', ', array_fill( 0, count( $unknown_hashes ), '%s' ) );

			$failed_hashes = array_fill_keys(
				(array) $wpdb->get_col(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "SELECT item_hash FROM {$table_name} WHERE result_status = %s AND item_hash IN ( {$placeholders} )", array_merge( [ \RIO_Process_Queue::STATUS_ERROR ], $unknown_hashes ) )
				),
				true
			);

			foreach ( $unknown_hashes as $item_hash ) {
				$failed_hash_cache[ $item_hash ] = isset( $failed_hashes[ $item_hash ] );
			}
		}

		foreach ( $candidate_hashes as $item_hash ) {
			if ( $failed_hash_cache[ $item_hash ] ) {
				return true;
			}
		}

		return false;
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
	 * Check whether the URL points to the uploads folder.
	 *
	 * @param string $source_url Image URL.
	 *
	 * @return bool
	 * @since  1.4.0
	 */
	protected static function is_wpmedia_url( $source_url ) {
		$upload_dir = wp_get_upload_dir();

		if ( false !== $upload_dir['error'] ) {
			return false;
		}

		// Normalize both URLs to https for comparison.
		$source_url_normalized = set_url_scheme( $source_url, 'https' );
		$baseurl_normalized    = set_url_scheme( $upload_dir['baseurl'], 'https' );

		return false !== strpos( $source_url_normalized, $baseurl_normalized );
	}

	/**
	 * Check whether the URL has a JPEG or PNG extension.
	 *
	 * @param string $source_url Image URL.
	 *
	 * @return bool
	 * @since  1.4.0
	 */
	protected static function is_support_format( $source_url ) {
		// Match the image extension before a query string or fragment.
		if ( ! preg_match( '~\.(jpe?g|png)($|[?#])~i', $source_url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether the URL points outside the site.
	 *
	 * @param string $source_url Image URL.
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
}
