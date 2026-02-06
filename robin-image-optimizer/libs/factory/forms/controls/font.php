<?php

	/**
	 * Dropdown List Control
	 *
	 * Main options:
	 *  name            => a name of the control
	 *  value           => a value to show in the control
	 *  default         => a default value of the control if the "value" option is not specified
	 *  items           => a callback to return items or an array of items to select
	 *
	 * @package core
	 * @since 1.0.0
	 */
	// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wbcr_FactoryForms600_FontControl' ) ) {

	class Wbcr_FactoryForms600_FontControl extends Wbcr_FactoryForms600_ComplexControl {

		public $type = 'font';

		public function __construct( $options, $form, $provider = null ) {
			parent::__construct( $options, $form, $provider );

			$option_font_size = [
				'name'    => $this->options['name'] . '__size',
				'units'   => $this->options['units'],
				'default' => isset( $this->options['default'] )
					? $this->options['default']['size']
					: null,
			];

			$option_font_family = [
				'name'    => $this->options['name'] . '__family',
				'data'    => $this->getFonts(),
				'default' => isset( $this->options['default'] )
					? $this->options['default']['family']
					: null,
			];

			$optionFontColor = [
				'name'         => $this->options['name'] . '__color',
				'default'      => isset( $this->options['default'] )
					? $this->options['default']['color']
					: null,
				'pickerTarget' => '.factory-control-' . $this->options['name'] . ' .factory-picker-target',
			];

			$this->size   = new Wbcr_FactoryForms600_IntegerControl( $option_font_size, $form, $provider );
			$this->family = new Wbcr_FactoryForms600_DropdownControl( $option_font_family, $form, $provider );
			$this->color  = new Wbcr_FactoryForms600_ColorControl( $optionFontColor, $form, $provider );

			$this->innerControls = [ $this->family, $this->size, $this->color ];
		}

		public function getFonts() {

			$fonts = $this->getDefaultFonts();

			$fonts = apply_filters( 'wbcr_factory_forms_600_fonts', $fonts );
			$fonts = apply_filters( 'wbcr_factory_forms_600_fonts-' . $this->options['name'], $fonts );

			return $fonts;
		}

		public function getDefaultFonts() {

			$fonts = [

				[ 'inherit', '(' . __( 'Use default website font', 'robin-image-optimizer' ) . ')' ],
				[
					'group',
					__( 'Sans Serif:', 'robin-image-optimizer' ),
					[
						[ 'Arial, "Helvetica Neue", Helvetica, sans-serif', 'Arial' ],
						[ '"Arial Black", "Arial Bold", Gadget, sans-serif', 'Arial Black' ],
						[ '"Arial Narrow", Arial, sans-serif', 'Arial Narrow' ],
						[
							'"Arial Rounded MT Bold", "Helvetica Rounded", Arial, sans-serif',
							'Arial Rounded MT Bold',
						],
						[
							'"Avant Garde", Avantgarde, "Century Gothic", CenturyGothic, "AppleGothic", sans-serif',
							'Avant Garde',
						],
						[ 'Calibri, Candara, Segoe, "Segoe UI", Optima, Arial, sans-serif', 'Calibri' ],
						[ 'Candara, Calibri, Segoe, "Segoe UI", Optima, Arial, sans-serif', 'Candara' ],
						[ '"Century Gothic", CenturyGothic, AppleGothic, sans-serif', 'Century Gothic' ],
						[
							'"Franklin Gothic Medium", "Franklin Gothic", "ITC Franklin Gothic", Arial, sans-serif',
							'Franklin Gothic Medium',
						],
						[ 'Futura, "Trebuchet MS", Arial, sans-serif', 'Futura' ],
						[ 'Geneva, Tahoma, Verdana, sans-serif', 'Geneva' ],
						[ '"Gill Sans", "Gill Sans MT", Calibri, sans-serif', 'Gill Sans' ],
						[ '"Helvetica Neue", Helvetica, Arial, sans-serif', 'Helvetica' ],
						[
							'Impact, Haettenschweiler, "Franklin Gothic Bold", Charcoal, "Helvetica Inserat", "Bitstream Vera Sans Bold", "Arial Black", sans serif',
							'Impact',
						],
						[
							'"Lucida Grande", "Lucida Sans Unicode", "Lucida Sans", Geneva, Verdana, sans-serif',
							'Lucida Grande',
						],
						[ 'Optima, Segoe, "Segoe UI", Candara, Calibri, Arial, sans-serif', 'Optima' ],
						[
							'"Segoe UI", Frutiger, "Frutiger Linotype", "Dejavu Sans", "Helvetica Neue", Arial, sans-serif',
							'Segoe UI',
						],
						[
							'Montserrat, "Segoe UI", "Helvetica Neue", Arial, sans-serif',
							'Montserrat',
						],
						[ 'Tahoma, Verdana, Segoe, sans-serif', 'Tahoma' ],
						[
							'"Trebuchet MS", "Lucida Grande", "Lucida Sans Unicode", "Lucida Sans", Tahoma, sans-serif',
							'Trebuchet MS',
						],
						[ 'Verdana, Geneva, sans-serif', 'Verdana' ],
					],
				],
				[
					'group',
					__( 'Serif:', 'robin-image-optimizer' ),
					[
						[
							'Baskerville, "Baskerville Old Face", "Hoefler Text", Garamond, "Times New Roman", serif',
							'Baskerville',
						],
						[ '"Big Caslon", "Book Antiqua", "Palatino Linotype", Georgia, serif', 'Big Caslon' ],
						[
							'"Bodoni MT", Didot, "Didot LT STD", "Hoefler Text", Garamond, "Times New Roman", serif',
							'Bodoni MT',
						],
						[
							'"Book Antiqua", Palatino, "Palatino Linotype", "Palatino LT STD", Georgia, serif',
							'Book Antiqua',
						],
						[
							'"Calisto MT", "Bookman Old Style", Bookman, "Goudy Old Style", Garamond, "Hoefler Text", "Bitstream Charter", Georgia, serif',
							'Calisto MT',
						],
						[ 'Cambria, Georgia, serif', 'Cambria' ],
						[ 'Didot, "Didot LT STD", "Hoefler Text", Garamond, "Times New Roman", serif', 'Didot' ],
						[
							'Garamond, Baskerville, "Baskerville Old Face", "Hoefler Text", "Times New Roman", serif',
							'Garamond',
						],
						[ 'Georgia, Times, "Times New Roman", serif', 'Georgia' ],
						[
							'"Goudy Old Style", Garamond, "Big Caslon", "Times New Roman", serif',
							'Goudy Old Style',
						],
						[
							'"Hoefler Text", "Baskerville old face", Garamond, "Times New Roman", serif',
							'Hoefler Text',
						],
						[ '"Lucida Bright", Georgia, serif', 'Lucida Bright' ],
						[
							'Palatino, "Palatino Linotype", "Palatino LT STD", "Book Antiqua", Georgia, serif',
							'Palatino',
						],
						[
							'Perpetua, Baskerville, "Big Caslon", "Palatino Linotype", Palatino, "URW Palladio L", "Nimbus Roman No9 L", serif',
							'Perpetua',
						],
						[
							'Rockwell, "Courier Bold", Courier, Georgia, Times, "Times New Roman", serif',
							'Rockwell',
						],
						[ '"Rockwell Extra Bold", "Rockwell Bold", monospace', 'Rockwell Extra Bold' ],
						[
							'TimesNewRoman, "Times New Roman", Times, Baskerville, Georgia, serif',
							'Times New Roman',
						],
					],
				],
				[
					'group',
					__( 'Monospaced:', 'robin-image-optimizer' ),
					[
						[ '"Andale Mono", AndaleMono, monospace', 'Andale Mono' ],
						[ 'Consolas, monaco, monospace', 'Consolas' ],
						[
							'"Courier New", Courier, "Lucida Sans Typewriter", "Lucida Typewriter", monospace',
							'Courier New',
						],
						[
							'"Lucida Console", "Lucida Sans Typewriter", Monaco, "Bitstream Vera Sans Mono", monospace',
							'Lucida Console',
						],
						[
							'"Lucida Sans Typewriter", "Lucida Console", Monaco, "Bitstream Vera Sans Mono", monospace',
							'Lucida Sans Typewriter',
						],
						[ 'Monaco, Consolas, "Lucida Console", monospace', 'Monaco' ],
					],
				],

			];

			return $fonts;
		}

		/**
		 * Removes \" in the font family value.
		 *
		 * @since 3.1.0
		 * @return mixed[]
		 */
		public function getValuesToSave() {
			$values = parent::getValuesToSave();

			$family_key            = sanitize_key( $this->options['name'] ) . '__family';
			$values[ $family_key ] = sanitize_text_field( $values[ $family_key ] );

			return $values;
		}

		public function beforeControlsHtml() {
		}

		public function afterControlsHtml() {
		}

		/**
		 * Shows the html markup of the control.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function html() {
			?>
				<div <?php $this->attrs(); ?>>
					<div class="factory-control-row">
					<?php $this->beforeControlsHtml(); ?>

						<div class="factory-family-wrap">
						<?php $this->family->html(); ?>
						</div>
						<div class="factory-size-wrap">
						<?php $this->size->html(); ?>
						</div>
						<div class="factory-color-wrap">
						<?php $this->color->html(); ?>
						</div>

					<?php $this->afterControlsHtml(); ?>
					</div>
					<div class="factory-picker-target"></div>
				</div>
			<?php
		}
	}
}

