<?php
/**
 * License data class
 *
 * Parses and exposes license data from both Freemius (wbcr_io_license) and
 * ThemeIsle SDK ({namespace}_license_data) storage formats.
 *
 * @package    Robin_Image_Optimizer
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_License
 *
 * Handles license data parsing and validation for both Freemius and ThemeIsle SDK licenses.
 */
class WRIO_License {

	/**
	 * License source: Freemius
	 */
	const SOURCE_FREEMIUS = 'freemius';

	/**
	 * License source: ThemeIsle SDK
	 */
	const SOURCE_SDK = 'sdk';

	/**
	 * Raw license data from database
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * License-specific data (normalized)
	 *
	 * @var array<string, mixed>
	 */
	private $license_data;

	/**
	 * License source (freemius or sdk)
	 *
	 * @var string|null
	 */
	private $source = null;

	/**
	 * Constructor
	 *
	 * Loads license data from the database, detecting the source automatically.
	 */
	public function __construct() {
		$this->detect_and_load_license();
	}

	/**
	 * Detect license source and load data
	 *
	 * Checks SDK option first, then falls back to Freemius storage.
	 *
	 * @return void
	 */
	private function detect_and_load_license() {
		// Get SDK namespace using existing helper
		$namespace  = WRIO_Plugin::get_sdk_namespace();
		$sdk_option = $namespace . '_license_data';

		// Check SDK option first
		// SDK stores data as stdClass object, convert to array for consistent access
		$sdk_data = get_option( $sdk_option, null );
		if ( ! empty( $sdk_data ) ) {
			// Force convert to array (handles both object and array input)
			$sdk_data = (array) $sdk_data;

			if ( isset( $sdk_data['license'] ) && 'valid' === $sdk_data['license'] ) {
				$this->source       = self::SOURCE_SDK;
				$this->data         = $sdk_data;
				$this->license_data = $this->normalize_sdk_data( $sdk_data );
				return;
			}
		}

		// Fallback to Freemius storage
		$freemius_data = get_option( 'wbcr_io_license', [] );
		if ( ! empty( $freemius_data['license']['secret_key'] ) ) {
			$this->source       = self::SOURCE_FREEMIUS;
			$this->data         = $freemius_data;
			$this->license_data = $freemius_data['license'] ?? [];
			return;
		}

		// No license found
		$this->source       = null;
		$this->data         = [];
		$this->license_data = [];
	}

	/**
	 * Normalize SDK license data to match internal format
	 *
	 * Translates SDK field names to the Freemius-style format used internally.
	 *
	 * @param array<string, mixed> $data The SDK license data (already converted to array).
	 * @return array<string, mixed> Normalized license data.
	 */
	private function normalize_sdk_data( array $data ) {
		return [
			'secret_key'        => $data['key'] ?? null,
			'expiration'        => $data['expires'] ?? null,
			'plan_title'        => 'Premium',
			'plan_id'           => $data['price_id'] ?? 0,
			'activated'         => 1,
			'is_cancelled'      => in_array( $data['is_expired'] ?? '', [ 'yes', true, 1, '1' ], true ),
			'is_block_features' => false,
			'billing_cycle'     => null, // SDK doesn't track subscription status
			'download_id'       => $data['download_id'] ?? null,
		];
	}

	/**
	 * Get the license source
	 *
	 * @return string|null 'freemius', 'sdk', or null if no license.
	 */
	public function get_source() {
		return $this->source;
	}

	/**
	 * Get masked license key showing first 8 and last 4 characters
	 *
	 * Example: sk_abc12****wxyz
	 *
	 * @return string
	 */
	public function get_masked_key() {
		$key = $this->get_key();
		if ( empty( $key ) ) {
			return '';
		}

		$length = strlen( $key );
		if ( $length <= 12 ) {
			return substr( $key, 0, 8 ) . '****';
		}

		return substr( $key, 0, 8 ) . '****' . substr( $key, -4 );
	}

	/**
	 * Get unified expiration display string
	 *
	 * Returns "Lifetime", "Renews on X" (for subscriptions), or "Expires on X".
	 *
	 * @return string
	 */
	public function get_expiration_display() {
		if ( $this->is_lifetime() ) {
			return __( 'Lifetime', 'robin-image-optimizer' );
		}

		$expiration = $this->license_data['expiration'] ?? null;
		if ( empty( $expiration ) ) {
			return '';
		}

		$date          = date_i18n( get_option( 'date_format' ), strtotime( $expiration ) );
		$billing_cycle = $this->get_billing_cycle();

		// Only Freemius has billing_cycle for subscriptions
		if ( $billing_cycle ) {
			/* translators: %s is the renewal date */
			return sprintf( __( 'Renews on %s', 'robin-image-optimizer' ), $date );
		}

		/* translators: %s is the expiration date */
		return sprintf( __( 'Expires on %s', 'robin-image-optimizer' ), $date );
	}

	/**
	 * Get CSS status class for license display
	 *
	 * @return string 'status-valid', 'status-warning', or 'status-expired'
	 */
	public function get_status_class() {
		if ( $this->is_expired() ) {
			return 'status-expired';
		}

		// Warning if expiring within 30 days
		$days = $this->get_expiration_time( 'days' );
		if ( 999 !== $days && 30 >= $days ) {
			return 'status-warning';
		}

		return 'status-valid';
	}

	/**
	 * Check if license data exists
	 *
	 * @return bool
	 */
	public function has_license() {
		return ! empty( $this->license_data ) && ! empty( $this->get_key() );
	}

	/**
	 * Get license key (secret_key)
	 *
	 * @return string|null
	 */
	public function get_key() {
		return isset( $this->license_data['secret_key'] ) ? $this->license_data['secret_key'] : null;
	}

	/**
	 * Get hidden license key with masked middle portion
	 *
	 * Example: sk_abc***xyz
	 *
	 * @return string
	 */
	public function get_hidden_key() {
		$key = $this->get_key();
		if ( empty( $key ) ) {
			return '';
		}

		$length = strlen( $key );
		if ( $length <= 12 ) {
			return substr( $key, 0, 4 ) . '******';
		}

		return substr_replace( $key, '******', 15, 6 );
	}

	/**
	 * Get expiration time in various formats.
	 *
	 * @param string $format Return format: 'time' (raw), 'days' (remaining days), 'date' (Y-m-d).
	 * @return mixed
	 */
	public function get_expiration_time( $format = 'time' ) {
		$expiration = isset( $this->license_data['expiration'] ) ? $this->license_data['expiration'] : null;

		if ( 'days' === $format ) {
			if ( $this->is_lifetime() ) {
				return 999;
			}

			if ( empty( $expiration ) ) {
				return 0;
			}

			$remaining = strtotime( $expiration ) - time();
			return max( 0, floor( $remaining / 86400 ) );
		}

		if ( 'date' === $format ) {
			if ( empty( $expiration ) ) {
				return '';
			}
			return gmdate( 'Y-m-d', strtotime( $expiration ) );
		}

		return $expiration;
	}

	/**
	 * Get sites quota (number of allowed sites)
	 *
	 * @return int|null Null means unlimited
	 */
	public function get_sites_quota() {
		return isset( $this->license_data['quota'] ) ? $this->license_data['quota'] : null;
	}

	/**
	 * Get count of currently active sites
	 *
	 * @return int
	 */
	public function get_count_active_sites() {
		return isset( $this->license_data['activated'] ) ? (int) $this->license_data['activated'] : 0;
	}

	/**
	 * Check if this is a lifetime license (no expiration)
	 *
	 * @return bool
	 */
	public function is_lifetime() {
		return empty( $this->license_data['expiration'] );
	}

	/**
	 * Check if license is valid (not expired)
	 *
	 * @return bool
	 */
	public function is_valid() {
		return ! $this->is_expired();
	}

	/**
	 * Check if license has expired
	 *
	 * @return bool
	 */
	public function is_expired() {
		if ( $this->is_lifetime() ) {
			return false;
		}

		$expiration = $this->license_data['expiration'];
		if ( empty( $expiration ) ) {
			return true;
		}

		return strtotime( $expiration ) < time();
	}

	/**
	 * Check if license is cancelled
	 *
	 * @return bool
	 */
	public function is_cancelled() {
		return ! empty( $this->license_data['is_cancelled'] );
	}

	/**
	 * Check if license is active (not cancelled)
	 *
	 * @return bool
	 */
	public function is_active() {
		return ! $this->is_cancelled();
	}

	/**
	 * Check if features are enabled
	 *
	 * Features may be blocked after expiration depending on license settings.
	 *
	 * @return bool
	 */
	public function is_features_enabled() {
		if ( ! $this->is_active() ) {
			return false;
		}

		$is_block_features = isset( $this->license_data['is_block_features'] ) ? $this->license_data['is_block_features'] : true;

		return ! $is_block_features || ! $this->is_expired();
	}

	/**
	 * Get plan title
	 *
	 * @return string|null
	 */
	public function get_plan() {
		return isset( $this->license_data['plan_title'] ) ? $this->license_data['plan_title'] : null;
	}

	/**
	 * Get plan ID
	 *
	 * @return int|null
	 */
	public function get_plan_id() {
		return isset( $this->license_data['plan_id'] ) ? (int) $this->license_data['plan_id'] : null;
	}

	/**
	 * Get billing cycle
	 *
	 * @return int|null 1 = monthly, 12 = yearly, null = lifetime/one-time
	 */
	public function get_billing_cycle() {
		return isset( $this->license_data['billing_cycle'] ) ? $this->license_data['billing_cycle'] : null;
	}

	/**
	 * Check if quota is unlimited
	 *
	 * @return bool
	 */
	public function is_unlimited() {
		return is_null( $this->get_sites_quota() );
	}

	/**
	 * Check if this is a single-site license
	 *
	 * @return bool
	 */
	public function is_single_site() {
		$quota = $this->get_sites_quota();
		return is_numeric( $quota ) && 1 === $quota;
	}

	/**
	 * Get license ID
	 *
	 * @return int|null
	 */
	public function get_id() {
		return isset( $this->license_data['id'] ) ? (int) $this->license_data['id'] : null;
	}

	/**
	 * Get user ID associated with license
	 *
	 * @return int|null
	 */
	public function get_user_id() {
		return isset( $this->license_data['user_id'] ) ? (int) $this->license_data['user_id'] : null;
	}

	/**
	 * Get raw license data array
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->license_data;
	}

	/**
	 * Get site data from license
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_data() {
		return isset( $this->data['site'] ) ? $this->data['site'] : [];
	}

	/**
	 * Get user data from license
	 *
	 * @return array<string, mixed>
	 */
	public function get_user_data() {
		return isset( $this->data['user'] ) ? $this->data['user'] : [];
	}
}
