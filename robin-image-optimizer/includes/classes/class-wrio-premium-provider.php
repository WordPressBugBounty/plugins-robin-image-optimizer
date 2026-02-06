<?php
/**
 * Premium Provider class
 *
 * Manages premium state via composition with WRIO_License.
 * This is a lightweight replacement for the Freemius Premium Provider.
 *
 * @package    Robin_Image_Optimizer
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_Premium_Provider
 *
 * Handles premium license state and operations.
 */
class WRIO_Premium_Provider {

	/**
	 * License instance
	 *
	 * @var WRIO_License
	 */
	private $license;

	/**
	 * Whether a license is activated
	 *
	 * @var bool
	 */
	private $is_activated = false;

	/**
	 * Constructor
	 *
	 * Initializes the license instance.
	 */
	public function __construct() {
		$this->license      = new WRIO_License();
		$this->is_activated = $this->license->has_license();
	}

	/**
	 * Check if a license key is activated (exists)
	 *
	 * @return bool
	 */
	public function is_activate() {
		return $this->is_activated;
	}

	/**
	 * Check if the license is active (activated AND valid/not expired)
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( ! $this->is_activated ) {
			return false;
		}

		return $this->license->is_valid();
	}

	/**
	 * Get the plan name/title
	 *
	 * @return string|null
	 */
	public function get_plan() {
		if ( ! $this->is_activated ) {
			return null;
		}

		return $this->license->get_plan();
	}

	/**
	 * Get the plan ID
	 *
	 * @return int|null
	 */
	public function get_plan_id() {
		if ( ! $this->is_activated ) {
			return null;
		}

		return $this->license->get_plan_id();
	}

	/**
	 * Get billing cycle
	 *
	 * @return int|null 1 = monthly, 12 = yearly, null = lifetime
	 */
	public function get_billing_cycle() {
		if ( ! $this->is_activated ) {
			return null;
		}

		return $this->license->get_billing_cycle();
	}

	/**
	 * Get the license instance
	 *
	 * @return WRIO_License
	 */
	public function get_license() {
		return $this->license;
	}

	/**
	 * Check if there is an active paid subscription (auto-renewal)
	 *
	 * @return bool
	 */
	public function has_paid_subscription() {
		if ( ! $this->is_activated ) {
			return false;
		}

		return ! empty( $this->license->get_billing_cycle() );
	}

	/**
	 * Activate a license key
	 *
	 * Routes to appropriate activation method based on key prefix:
	 * - sk_ prefix: Freemius license (stored in wbcr_io_license)
	 * - No prefix: ThemeIsle SDK license (processed via SDK filter)
	 *
	 * @param string $key The license key to activate.
	 * @return bool True on success.
	 * @throws Exception If activation fails.
	 */
	public function activate( $key ) {
		$key = trim( $key );

		if ( empty( $key ) ) {
			throw new Exception( esc_html__( 'License key is empty.', 'robin-image-optimizer' ) );
		}

		// Detect license type by prefix
		$is_freemius = strpos( $key, 'sk_' ) === 0;

		if ( $is_freemius ) {
			return $this->activate_freemius( $key );
		}

		return $this->activate_sdk( $key );
	}

	/**
	 * Activate a Freemius license key via API
	 *
	 * Makes a direct API call to Freemius activation endpoint.
	 *
	 * @param string $key The Freemius license key (sk_ prefixed).
	 * @return bool True on success.
	 * @throws Exception If activation fails.
	 */
	private function activate_freemius( $key ) {
		$plugin_id = $this->get_setting( 'plugin_id' );

		// Generate unique site identifier (32 chars)
		$uid = $this->get_or_create_site_uid();

		// Build API endpoint
		$api_url = sprintf(
			'https://api.freemius.com/v1/plugins/%s/activate.json',
			$plugin_id
		);

		// Prepare request body
		$body = [
			'license_key' => $key,
			'uid'         => $uid,
			'url'         => get_home_url(),
			'title'       => get_bloginfo( 'name' ),
			'version'     => WRIO_Plugin::app()->getPluginVersion(),
		];

		// Add user info for new license activations
		$current_user = wp_get_current_user();
		if ( $current_user->ID ) {
			$body['user_email'] = $current_user->user_email;
			$body['first_name'] = $current_user->first_name ? $current_user->first_name : $current_user->display_name;
			$body['last_name']  = $current_user->last_name ? $current_user->last_name : '';
		} else {
			$body['user_email'] = get_option( 'admin_email' );
		}

		/**
		 * Filter request body before API call
		 *
		 * @param array<string, mixed> $body The request body.
		 * @param string               $key  The license key.
		 */
		$body = apply_filters( 'wrio_freemius_activate_request', $body, $key );

		$body_json = wp_json_encode( $body );
		if ( false === $body_json ) {
			throw new Exception( esc_html__( 'Failed to encode request body.', 'robin-image-optimizer' ) );
		}

		// Make API request
		$response = wp_remote_post(
			$api_url,
			[
				'timeout'   => 30,
				'headers'   => [
					'Content-Type' => 'application/json',
				],
				'body'      => $body_json,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception(
				esc_html(
					sprintf(
					/* translators: %s: error message */
						__( 'API request failed: %s', 'robin-image-optimizer' ),
						$response->get_error_message()
					)
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// Check for API errors
		if ( 200 !== $response_code || isset( $data['error'] ) ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'License activation failed.', 'robin-image-optimizer' );

			throw new Exception( esc_html( $error_message ) );
		}

		// Validate response has required fields
		if ( empty( $data['install_id'] ) ) {
			throw new Exception( esc_html__( 'Invalid API response: missing install_id.', 'robin-image-optimizer' ) );
		}

		// Build license data from API response
		$license_data = $this->build_license_data_from_response( $data, $key, $uid );

		/**
		 * Filter license data before saving
		 *
		 * @param array  $license_data The license data to save.
		 * @param string $key          The license key being activated.
		 * @param array  $data         The raw API response.
		 */
		$license_data = apply_filters( 'wrio_license_activate_data', $license_data, $key, $data );

		update_option( 'wbcr_io_license', $license_data );

		// Reinitialize license
		$this->license      = new WRIO_License();
		$this->is_activated = true;

		/**
		 * Action fired after license is activated
		 *
		 * @param string $key          The activated license key.
		 * @param array  $license_data The stored license data.
		 * @param array  $data         The raw API response.
		 */
		do_action( 'wrio_license_activated', $key, $license_data, $data );

		return true;
	}

	/**
	 * Get or create a unique site identifier for Freemius
	 *
	 * @return string 32-character unique identifier.
	 */
	private function get_or_create_site_uid() {
		$uid = get_option( 'wrio_freemius_uid' );

		if ( empty( $uid ) ) {
			// Generate a 32-char unique identifier
			$uid = wp_generate_uuid4();
			$uid = str_replace( '-', '', $uid );
			update_option( 'wrio_freemius_uid', $uid );
		}

		return $uid;
	}

	/**
	 * Build license data array from Freemius API response
	 *
	 * @param array<string, mixed> $data The API response data.
	 * @param string               $key  The license key.
	 * @param string               $uid  The site UID.
	 * @return array<string, mixed> License data for storage.
	 */
	private function build_license_data_from_response( $data, $key, $uid ) {
		$install = isset( $data['install'] ) ? $data['install'] : $data;
		$user    = isset( $data['user'] ) ? $data['user'] : [];
		$license = isset( $data['license'] ) ? $data['license'] : [];

		return [
			'license' => [
				'id'                => isset( $license['id'] ) ? (int) $license['id'] : 0,
				'secret_key'        => $key,
				'plan_id'           => isset( $license['plan_id'] ) ? (int) $license['plan_id'] : 0,
				'plan_title'        => isset( $license['plan_title'] ) ? $license['plan_title'] : 'Premium',
				'expiration'        => isset( $license['expiration'] ) ? $license['expiration'] : null,
				'quota'             => isset( $license['quota'] ) ? (int) $license['quota'] : null,
				'activated'         => 1,
				'activated_local'   => 1,
				'is_cancelled'      => isset( $license['is_cancelled'] ) ? (bool) $license['is_cancelled'] : false,
				'is_block_features' => isset( $license['is_block_features'] ) ? (bool) $license['is_block_features'] : false,
				'billing_cycle'     => isset( $license['billing_cycle'] ) ? (int) $license['billing_cycle'] : null,
				'created'           => current_time( 'mysql' ),
				'updated'           => current_time( 'mysql' ),
			],
			'site'    => [
				'id'                => isset( $install['id'] ) ? (int) $install['id'] : ( isset( $data['install_id'] ) ? (int) $data['install_id'] : 0 ),
				'uid'               => $uid,
				'url'               => get_home_url(),
				'public_key'        => isset( $install['public_key'] ) ? $install['public_key'] : '',
				'secret_key'        => isset( $install['secret_key'] ) ? $install['secret_key'] : '',
				'install_api_token' => isset( $data['install_api_token'] ) ? $data['install_api_token'] : '',
			],
			'user'    => [
				'id'         => isset( $user['id'] ) ? (int) $user['id'] : 0,
				'email'      => isset( $user['email'] ) ? $user['email'] : get_option( 'admin_email' ),
				'public_key' => isset( $user['public_key'] ) ? $user['public_key'] : '',
			],
		];
	}

	/**
	 * Activate a ThemeIsle SDK license key
	 *
	 * Uses the SDK's do_license_process() via the registered filter.
	 * The SDK handles all API calls and stores license data automatically.
	 *
	 * @param string $key The SDK license key (unprefixed).
	 * @return bool True on success.
	 * @throws Exception If activation fails.
	 */
	private function activate_sdk( $key ) {
		$namespace = WRIO_Plugin::get_sdk_namespace();

		// Use SDK filter - it handles API calls via do_license_process()
		// SDK returns: true on success, WP_Error on failure
		$response = apply_filters(
			'themeisle_sdk_license_process_' . $namespace,
			$key,
			'activate'
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		if ( true !== $response ) {
			throw new Exception( esc_html__( 'License activation failed.', 'robin-image-optimizer' ) );
		}

		// Reinitialize license (SDK already stored the data)
		$this->license      = new WRIO_License();
		$this->is_activated = true;

		/**
		 * Action fired after license is activated
		 *
		 * @param string $key  The activated license key.
		 * @param array  $data Additional data.
		 */
		do_action( 'wrio_license_activated', $key, [] );

		return true;
	}

	/**
	 * Deactivate the current license
	 *
	 * Clears the appropriate option based on license source.
	 *
	 * @return bool True on success.
	 */
	public function deactivate() {
		if ( ! $this->is_activated ) {
			return true;
		}

		$old_key = $this->license->get_key();
		$source  = $this->license->get_source();

		/**
		 * Action fired before license is deactivated
		 *
		 * @param string|null $key The license key being deactivated.
		 */
		do_action( 'wrio_license_before_deactivate', $old_key );

		// Clear the appropriate option based on source
		if ( WRIO_License::SOURCE_SDK === $source ) {
			$namespace = WRIO_Plugin::get_sdk_namespace();

			// Use SDK filter - it handles API calls and deletes options
			apply_filters(
				'themeisle_sdk_license_process_' . $namespace,
				$old_key,
				'deactivate'
			);

			// Ensure cleanup (SDK may have already deleted these)
			delete_option( $namespace . '_license_data' );
			delete_transient( $namespace . '_license_data' );
		} else {
			delete_option( 'wbcr_io_license' );
		}

		// Reinitialize license
		$this->license      = new WRIO_License();
		$this->is_activated = false;

		/**
		 * Action fired after license is deactivated
		 *
		 * @param string|null $key The deactivated license key.
		 */
		do_action( 'wrio_license_deactivated', $old_key );

		return true;
	}

	/**
	 * Cancel paid subscription
	 *
	 * In a full implementation, this would call the payment provider API.
	 * For now, it just removes the billing cycle from local data.
	 *
	 * @return bool True on success.
	 */
	public function cancel_paid_subscription() {
		if ( ! $this->is_activated || ! $this->has_paid_subscription() ) {
			return false;
		}

		$license_data = get_option( 'wbcr_io_license', [] );

		if ( isset( $license_data['license']['billing_cycle'] ) ) {
			$license_data['license']['billing_cycle'] = null;
			update_option( 'wbcr_io_license', $license_data );
		}

		// Refresh license
		$this->license = new WRIO_License();

		/**
		 * Action fired after subscription is cancelled
		 *
		 * @param WRIO_License $license The license instance.
		 */
		do_action( 'wrio_subscription_cancelled', $this->license );

		return true;
	}

	/**
	 * Get a setting value (for compatibility with Freemius provider)
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	/**
	 * Get plugin setting
	 *
	 * @param string|null $key           The setting key.
	 * @param mixed       $default_value The default value if key not found.
	 * @return mixed The setting value or default.
	 */
	public function get_setting( $key, $default_value = null ) {
		$settings = [
			'plugin_id'  => '3464',
			'public_key' => 'pk_cafff5a51bd5fcf09c6bde806956d',
			'slug'       => 'robin-image-optimizer',
		];

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default_value;
	}

	/**
	 * Get upgrade price (stub for Factory framework compatibility)
	 *
	 * @return int
	 */
	public function get_price() {
		return 0;
	}

	/**
	 * Check if premium package is installed (stub for Factory framework compatibility)
	 *
	 * @return bool
	 */
	public function is_install_package() {
		return false;
	}

	/**
	 * Get package data (stub for Factory framework compatibility)
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_package_data() {
		return null;
	}

	/**
	 * Get package download URL (stub for Factory framework compatibility)
	 *
	 * @return string
	 */
	public function get_package_download_url() {
		return '';
	}

	/**
	 * Get downloadable package info (stub for Factory framework compatibility)
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_downloadable_package_info() {
		return null;
	}

	/**
	 * Sync license (stub for Factory framework compatibility)
	 *
	 * No-op since sync functionality was removed.
	 *
	 * @return bool
	 */
	public function sync() {
		return true;
	}
}
