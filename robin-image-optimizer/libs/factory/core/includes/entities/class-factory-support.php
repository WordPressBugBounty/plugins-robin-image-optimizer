<?php

namespace WBCR\Factory_600\Entities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * @since 4.1.1
 */

class Support {

	protected $plugin_name;
	protected $site_url;

	protected $features_page_slug = 'premium-features';
	protected $pricing_page_slug  = 'pricing';
	protected $support_page_slug  = 'support';
	protected $docs_page_slug     = 'docs';

	/**
	 * Plugin_Site constructor.
	 *
	 * @param array $data
	 */
	public function __construct( array $data ) {
		$this->site_url = isset( $data['url'] ) ? $data['url'] : null;

		if ( isset( $data['pages_map'] ) && is_array( $data['pages_map'] ) ) {
			foreach ( $data['pages_map'] as $key => $def_value ) {
				$attr          = $key . '_page_slug';
				$this->{$attr} = isset( $data[ $key ] ) ? $data[ $key ] : $def_value;
			}
		}
	}

	/**
	 * @return string
	 */
	public function get_site_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->site_url, $utm_content );
		}

		return $this->site_url;
	}


	/**
	 * @return string
	 */
	public function get_features_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->features_page_slug, $utm_content );
		}

		return $this->get_site_url() . '/' . $this->features_page_slug;
	}


	/**
	 * @return string
	 */
	public function get_pricing_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->pricing_page_slug, $utm_content );
		}

		return $this->get_site_url() . '/' . $this->pricing_page_slug;
	}


	/**
	 * @return string
	 */
	public function get_contacts_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->support_page_slug, $utm_content );
		}

		return $this->get_site_url() . '/' . $this->support_page_slug;
	}


	/**
	 * @return string
	 */
	public function get_docs_url( $track = false, $utm_content = null ) {
		if ( $track ) {
			return $this->get_tracking_page_url( $this->docs_page_slug, $utm_content );
		}

		return $this->get_site_url() . '/' . $this->docs_page_slug;
	}


	/**
	 * Generate URL with UTM parameters.
	 *
	 * @param string|null $page The page slug.
	 * @param string      $utm_content The UTM content parameter.
	 * @param string      $utm_source The UTM source parameter.
	 *
	 * @return string
	 */
	public function get_tracking_page_url( $page = null, $utm_content = '', $utm_source = 'wordpress.org' ) {
		$url = $this->get_site_url();
		if ( $page ) {
			$url .= '/' . $page . '/';
		}

		return tsdk_utmify( $url, $utm_content, $utm_source );
	}
}
