<?php
/**
 * Request parameter reader.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads $_REQUEST, $_GET and $_POST parameters, as WRIO_Plugin::app()->request.
 *
 * Callers verify nonces. Values are not unslashed, as with the Factory request object it replaces.
 */
class WRIO_Request {

	/**
	 * Reads a $_REQUEST parameter.
	 *
	 * @param string               $param         Parameter name.
	 * @param mixed                $default_value Returned when the parameter is missing.
	 * @param bool|callable-string $sanitize      True for sanitize_text_field(), or the name of a sanitize function.
	 *
	 * @return mixed
	 */
	public function request( $param, $default_value = false, $sanitize = false ) {
		return $this->read( $_REQUEST, $param, $default_value, $sanitize ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Reads a $_GET parameter.
	 *
	 * @param string               $param         Parameter name.
	 * @param mixed                $default_value Returned when the parameter is missing.
	 * @param bool|callable-string $sanitize      True for sanitize_text_field(), or the name of a sanitize function.
	 *
	 * @return mixed
	 */
	public function get( $param, $default_value = false, $sanitize = false ) {
		return $this->read( $_GET, $param, $default_value, $sanitize ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Reads a $_POST parameter.
	 *
	 * @param string               $param         Parameter name.
	 * @param mixed                $default_value Returned when the parameter is missing.
	 * @param bool|callable-string $sanitize      True for sanitize_text_field(), or the name of a sanitize function.
	 *
	 * @return mixed
	 */
	public function post( $param, $default_value = false, $sanitize = false ) {
		return $this->read( $_POST, $param, $default_value, $sanitize ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Reads a parameter from a superglobal.
	 *
	 * @param array<string, mixed> $source        Superglobal.
	 * @param string               $param         Parameter name.
	 * @param mixed                $default_value Returned when the parameter is missing.
	 * @param bool|callable-string $sanitize      True for sanitize_text_field(), or the name of a sanitize function.
	 *
	 * @return mixed
	 */
	private function read( $source, $param, $default_value, $sanitize ) {
		if ( empty( $param ) || ! isset( $source[ $param ] ) ) {
			return $default_value;
		}

		if ( empty( $sanitize ) ) {
			return $source[ $param ];
		}

		$callback = is_string( $sanitize ) ? $sanitize : 'sanitize_text_field';
		$value    = $source[ $param ];

		if ( is_array( $value ) ) {
			array_walk_recursive(
				$value,
				function ( &$item ) use ( $callback ) {
					$item = call_user_func( $callback, $item );
				}
			);

			return $value;
		}

		return call_user_func( $callback, $value );
	}
}
