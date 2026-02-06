<?php

	/**
	 * Url Control
	 *
	 * Main options:
	 *
	 * @see FactoryForms600_TextboxControl
	 *
	 * @package factory-forms
	 * @since 1.0.0
	 */

	// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wbcr_FactoryForms600_UrlControl' ) ) {

	class Wbcr_FactoryForms600_UrlControl extends Wbcr_FactoryForms600_TextboxControl {

		public $type = 'url';

		/**
		 * Adding 'http://' to the url if it was missed.
		 *
		 * @since 1.0.0
		 * @return string
		 */
		public function getSubmitValue( $name, $sub_name ) {
			$value = parent::getSubmitValue( $name, $sub_name );
			if ( ! empty( $value ) && substr( $value, 0, 4 ) != 'http' ) {
				$value = 'http://' . $value;
			}

			return $value;
		}
	}
}
