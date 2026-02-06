<?php
/**
 * Support URLs class
 *
 * Manages plugin support, pricing, and documentation URLs.
 * This is a lightweight replacement for the Factory Support entity.
 *
 * @package    Robin_Image_Optimizer
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_Support
 *
 * Handles support-related URLs for the plugin.
 */
class WRIO_Support {

	/**
	 * Plugin name for tracking
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * Base site URL
	 *
	 * @var string
	 */
	protected $site_url;

	/**
	 * Features page slug
	 *
	 * @var string
	 */
	protected $features_page_slug = 'premium-features';

	/**
	 * Pricing page slug
	 *
	 * @var string
	 */
	protected $pricing_page_slug = 'pricing';

	/**
	 * Support page slug
	 *
	 * @var string
	 */
	protected $support_page_slug = 'support';

	/**
	 * Documentation page slug
	 *
	 * @var string
	 */
	protected $docs_page_slug = 'docs';

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $data Configuration data including 'url' and optional 'pages_map'.
	 */
	public function __construct( array $data = [] ) {
		$this->site_url    = isset( $data['url'] ) ? $data['url'] : 'https://developer.flavflavor.dev';
		$this->plugin_name = isset( $data['plugin_name'] ) ? $data['plugin_name'] : 'robin-image-optimizer';

		// Allow custom page slug mapping
		if ( isset( $data['pages_map'] ) && is_array( $data['pages_map'] ) ) {
			foreach ( $data['pages_map'] as $key => $slug ) {
				$attr = $key . '_page_slug';
				if ( property_exists( $this, $attr ) ) {
					$this->{$attr} = $slug;
				}
			}
		}
	}

	/**
	 * Get site URL
	 *
	 * @param bool        $track       Whether to include tracking parameters.
	 * @param string|null $utm_content UTM content parameter.
	 * @return string
	 */
	public function get_site_url( $track = false, $utm_content = null ) {
		$url = $this->site_url;

		/**
		 * Filter the base site URL
		 *
		 * @param string $url The base URL.
		 */
		$url = apply_filters( 'wrio_support_site_url', $url );

		if ( $track ) {
			return $this->get_tracking_page_url( '', $utm_content );
		}

		return $url;
	}

	/**
	 * Get features page URL
	 *
	 * @param bool        $track       Whether to include tracking parameters.
	 * @param string|null $utm_content UTM content parameter.
	 * @return string
	 */
	public function get_features_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->features_page_slug, $utm_content );
		}

		$url = trailingslashit( $this->site_url ) . $this->features_page_slug;

		/**
		 * Filter the features page URL
		 *
		 * @param string $url The features URL.
		 */
		return apply_filters( 'wrio_support_features_url', $url );
	}

	/**
	 * Get pricing page URL
	 *
	 * @param bool   $track       Whether to include tracking parameters.
	 * @param string $utm_campaign UTM content parameter.
	 * @return string
	 */
	public function get_pricing_url( $track = false, $utm_campaign = '' ) {
		$url = rtrim( $this->site_url, '/' ) . '/upgrade';
		$url = tsdk_translate_link( tsdk_utmify( $url, $utm_campaign ) );

		/**
		 * Filter the pricing page URL
		 *
		 * @param string $url The pricing URL.
		 */
		return apply_filters( 'wrio_support_pricing_url', esc_url( $url ) );
	}

	/**
	 * Get contacts/support page URL
	 *
	 * @param bool        $track       Whether to include tracking parameters.
	 * @param string|null $utm_content UTM content parameter.
	 * @return string
	 */
	public function get_contacts_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->support_page_slug, $utm_content );
		}

		$url = trailingslashit( $this->site_url ) . $this->support_page_slug;

		/**
		 * Filter the contacts/support page URL
		 *
		 * @param string $url The contacts URL.
		 */
		return apply_filters( 'wrio_support_contacts_url', $url );
	}

	/**
	 * Get documentation page URL
	 *
	 * @param bool        $track       Whether to include tracking parameters.
	 * @param string|null $utm_content UTM content parameter.
	 * @return string
	 */
	public function get_docs_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->docs_page_slug, $utm_content );
		}

		$url = trailingslashit( $this->site_url ) . $this->docs_page_slug;

		/**
		 * Filter the documentation page URL
		 *
		 * @param string $url The docs URL.
		 */
		return apply_filters( 'wrio_support_docs_url', $url );
	}

	/**
	 * Build URL with UTM tracking parameters
	 *
	 * @param string|null $page        Page slug to append.
	 * @param string|null $utm_content UTM content parameter.
	 * @param string      $utm_source  UTM source parameter.
	 * @return string
	 */
	public function get_tracking_page_url( $page = null, $utm_content = null, $utm_source = 'wordpress.org' ) {
		$args = [
			'utm_source' => $utm_source,
		];

		if ( ! empty( $this->plugin_name ) ) {
			$args['utm_campaign'] = $this->plugin_name;
		}

		if ( ! empty( $utm_content ) ) {
			$args['utm_content'] = $utm_content;
		}

		$base_url = $this->site_url;
		if ( ! empty( $page ) ) {
			$base_url = trailingslashit( $base_url ) . $page . '/';
		}

		$raw_url = add_query_arg( $args, $base_url );

		return esc_url( $raw_url );
	}
}
