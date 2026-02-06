<?php
/**
 * Adds hooks to the main plugin settings page.
 *
 * @version       1.0
 */

use WRIO\WEBP\HTML\Delivery as WEBP_Delivery;
use WRIO\WEBP\Server;

/**
 * Used to save webp/avif conversion options.
 *
 * @since 1.0.4
 */
add_action(
	'wrio/settings_page/berfore_form_save',
	function () {
		// Get AVIF option - if user tries to enable without premium, force disable
		$avif_enabled = WRIO_Plugin::app()->request->post(
			WRIO_Plugin::app()->getPrefix() . 'convert_avif_format',
			false
		);

		if ( $avif_enabled && ! wrio_is_license_activate() ) {
			WRIO_Plugin::app()->updatePopulateOption( 'convert_avif_format', false );
		}

		// Check if any conversion is enabled (WebP or AVIF)
		$webp_enabled = WRIO_Plugin::app()->request->post(
			WRIO_Plugin::app()->getPrefix() . 'convert_webp_format',
			false
		);

		// Only process delivery mode if any conversion is enabled
		if ( ! $webp_enabled && ! $avif_enabled ) {
			return;
		}

		$allow_redirection_mode = Server::is_apache() && Server::server_use_htaccess();
		$delivery_mode          = WRIO_Plugin::app()->request->post( 'wrio_webp_delivery_mode', WEBP_Delivery::PICTURE_DELIVERY_MODE );

		if ( WEBP_Delivery::REDIRECT_DELIVERY_MODE === $delivery_mode && ! $allow_redirection_mode ) {
			$delivery_mode = WEBP_Delivery::PICTURE_DELIVERY_MODE;
		}

		WRIO_Plugin::app()->updatePopulateOption( 'webp_delivery_mode', $delivery_mode );
	}
);

/**
 * This hook prints options for delivering webp images.
 *
 * @since 1.0.4
 */
add_action(
	'wrio/settings_page/conver_webp_options',
	function () {
		$allow_redirection_mode = Server::is_apache() && Server::server_use_htaccess();
		$delivery_mode          = WRIO_Plugin::app()->getPopulateOption( 'webp_delivery_mode', WEBP_Delivery::PICTURE_DELIVERY_MODE );

		$server = 'unknown';

		if ( Server::is_apache() ) {
			$server = 'apache';
		} elseif ( Server::is_nginx() ) {
			$server = 'nginx';
		} elseif ( Server::is_iss() ) {
			$server = 'iss';
		}

		// Help
		$docs_url = WRIO_Plugin::app()->get_support()->get_tracking_page_url( 'what-is-webp-format-and-how-webp-images-can-speed-up-your-wordpress-website', 'settings-page' );

		$view = \WRIO_Views::get_instance( WRIOP_PLUGIN_DIR );

		$view->print_template(
			'part-settings-page-webp-options',
			[
				'server'                 => $server,
				'delivery_mode'          => $delivery_mode,
				'allow_redirection_mode' => $allow_redirection_mode,
				'docs_url'               => $docs_url,
			]
		);
	}
);
