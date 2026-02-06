<?php
	/**
	 * Separator Markup
	 *
	 * @package factory-forms
	 * @since 1.0.0
	 */

	// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wbcr_FactoryForms600_Separator' ) ) {
	class Wbcr_FactoryForms600_Separator extends Wbcr_FactoryForms600_CustomElement {

		public $type = 'separator';

		/**
		 * Shows the html markup of the element.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function html() {
			?>
				<div <?php $this->attrs(); ?>></div>
			<?php
		}
	}
}
