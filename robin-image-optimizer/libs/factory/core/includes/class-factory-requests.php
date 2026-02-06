<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 *
 * @package       factory-core
*/

class Wbcr_Factory600_Request {

	/**
	 * @param string|null $param
	 * @param bool|string $sanitize true/false or sanitize function name
	 * @param bool        $default
	 * @param string      $method_name
	 *
	 * @return array|bool|mixed
	 */
	private function getBody( $param, $sanitize = false, $default = false, $method_name = 'REQUEST' ) {
		if ( empty( $param ) ) {
			return null;
		}

		$sanitize_function_name = 'sanitize_text_field';

		switch ( strtoupper( $method_name ) ) {
			case 'GET':
				$method = $_GET;
				break;
			case 'POST':
				$method = $_POST;
				break;
			case 'REQUEST':
				$method = $_REQUEST;
				break;
		}

		if ( is_string( $sanitize ) && $sanitize !== $sanitize_function_name ) {
			$sanitize_function_name = $sanitize;
		}

		if ( isset( $method[ $param ] ) ) {
			if ( is_array( $method[ $param ] ) ) {
				return ! empty( $sanitize ) ? $this->recursiveArrayMap( $sanitize_function_name, $method[ $param ] ) : $method[ $param ];
			} else {
				return ! empty( $sanitize ) ? call_user_func( $sanitize_function_name, $method[ $param ] ) : $method[ $param ];
			}
		}

		return $default;
	}

	/**
	 * Recursive sanitation for an array
	 *
	 * @param string $function_name
	 * @param        $array
	 *
	 * @return mixed
	 */
	public function recursiveArrayMap( $function_name, $array ) {
		foreach ( $array as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = $this->recursiveArrayMap( $function_name, $value );
			} else {
				if ( ! function_exists( $function_name ) ) {
					throw new Exception( 'Function ' . $function_name . 'is undefined.' );
				}

				$value = $function_name( $value );
			}
		}

		return $array;
	}

	/**
	 * @param string|null $param
	 * @param mixed $default see method getBody
	 * @param bool        $sanitize
	 *
	 * @return mixed|null
	 */
	public function request( $param, $default = false, $sanitize = false ) {
		return $this->getBody( $param, $sanitize, $default );
	}

	/**
	 * @param string|null $param
	 * @param mixed $default see method getBody
	 * @param bool        $sanitize
	 *
	 * @return mixed|null
	 */
	public function get( $param, $default = false, $sanitize = false ) {
		return $this->getBody( $param, $sanitize, $default, 'get' );
	}

	/**
	 * @param string|null $param
	 * @param mixed $default see method getBody
	 * @param bool        $sanitize
	 *
	 * @return mixed|null
	 */
	public function post( $param, $default = false, $sanitize = false ) {
		return $this->getBody( $param, $sanitize, $default, 'post' );
	}
}
