<?php
/**
 * Helper functions for the base models.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper for configuring model objects.
 */
class RIO_Base_Helper {
	/**
	 * Configure passed object.
	 *
	 * @param object               $object Object class to configure.
	 * @param array<string, mixed> $config Key => value list of props to be set on object.
	 *
	 * @return mixed
	 */
	public static function configure( $object, $config ) {
		foreach ( $config as $name => $value ) {
			$object->$name = $value;
		}

		return $object;
	}
}
