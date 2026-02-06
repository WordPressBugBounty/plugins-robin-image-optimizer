<?php
	/**
	 * The file contains the class of Tab Control Holder.
	 *
	 * @package core
	 * @since 1.0.0
	 */

	// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'Wbcr_FactoryForms600_AccordionHolder' ) ) {
	/**
	 * Tab Control Holder
	 *
	 * @since 1.0.0
	 */
	class Wbcr_FactoryForms600_AccordionHolder extends Wbcr_FactoryForms600_Holder {

		/**
		 * A holder type.
		 *
		 * @since 1.0.0
		 * @var string
		 */
		public $type = 'accordion';

		/**
		 * Here we should render a beginning html of the tab.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function beforeRendering() {
			?>
				<div <?php $this->attrs(); ?>>
			<?php
		}

		/**
		 * Here we should render an end html of the tab.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function afterRendering() {
			?>
				</div>
			<?php
		}
	}
}