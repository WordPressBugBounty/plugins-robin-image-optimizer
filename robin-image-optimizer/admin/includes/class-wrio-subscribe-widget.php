<?php
/**
 * Subscribe Widget Class
 *
 * Newsletter subscription: ajax endpoint and subscribed flag. The React app renders the form.
 *
 * @package    Robin_Image_Optimizer
 * @subpackage Admin\Includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_Subscribe_Widget
 */
class WRIO_Subscribe_Widget {

	/**
	 * ThemeIsle subscription API endpoint
	 */
	const API_URL = 'https://api.themeisle.com/tracking/subscribe';

	/**
	 * Option name to track subscription status
	 */
	const OPTION_SUBSCRIBED = 'wrio_user_subscribed';

	/**
	 * Plugin slug for API
	 *
	 * @var string
	 */
	private $plugin_name = 'wbcr_image_optimizer';

	/**
	 * Constructor
	 */

	/**
	 * Check if user has already subscribed
	 *
	 * @return bool
	 */
	public function is_subscribed() {
		return (bool) get_option( self::OPTION_SUBSCRIBED, false );
	}

	/**
	 * Subscribe an address to the newsletter. Shared by admin-ajax and REST.
	 *
	 * @param string $email Email address.
	 *
	 * @return array{message: string}|WP_Error
	 */
	public function subscribe( $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wrio_bad_request', __( 'Please enter a valid email address.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		$body = wp_json_encode(
			[
				'slug'  => $this->plugin_name,
				'site'  => home_url(),
				'email' => $email,
			]
		);

		if ( false === $body ) {
			return new WP_Error( 'wrio_subscribe_failed', __( 'Failed to encode request body.', 'robin-image-optimizer' ), [ 'status' => 500 ] );
		}

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Cache-Control' => 'no-cache',
					'Accept'        => 'application/json, */*;q=0.1',
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wrio_subscribe_failed', $response->get_error_message(), [ 'status' => 502 ] );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $data ) && isset( $data['code'] ) ) {
			update_option( self::OPTION_SUBSCRIBED, 1 );

			return [ 'message' => __( 'Subscribed successfully!', 'robin-image-optimizer' ) ];
		}

		return new WP_Error( 'wrio_subscribe_failed', __( 'Subscription failed. Please try again.', 'robin-image-optimizer' ), [ 'status' => 502 ] );
	}
}
