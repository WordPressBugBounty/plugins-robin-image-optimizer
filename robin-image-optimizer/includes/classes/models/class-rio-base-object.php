<?php
/**
 * Base model with magic property access.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RIO_Base_Object is a base class that implements property feature.
 *
 * It takes advantage of __get() and __set(), see further implementation below in the class.
 *
 * When property of the class is being used and it has a getter, it will be used instead of directly accessing it.
 *
 * The same logic applies for setter.
 *
 * Example:
 *
 * ```php
 * // equivalent to $label = $object->getLabel();
 * $label = $object->label;
 * // equivalent to $object->setLabel('abc');
 * $object->label = 'abc';
 * ```
 */
class RIO_Base_Object {

	/**
	 * RIO_Base_Object constructor.
	 *
	 * @param array<string, mixed> $config name-value pairs that will be used to initialize the object properties.
	 */
	public function __construct( $config = [] ) {

		if ( ! empty( $config ) ) {
			$this->configure( $config );
		}

		$this->init();
	}

	/**
	 * Initiate model.
	 *
	 * @return void
	 */
	public function init() {
	}

	/**
	 * Configure object.
	 *
	 * @param array<string, mixed> $config name-value pairs that will be used to initialize the object properties.
	 *
	 * @return void
	 */
	public function configure( $config ) {
		RIO_Base_Helper::configure( $this, $config );
	}

	/**
	 * Returns the value of an object property.
	 *
	 * Do not call this method directly as it is a PHP magic method that
	 * will be implicitly called when executing `$value = $object->property;`.
	 *
	 * @param string $name The property name.
	 *
	 * @return mixed The property value.
	 * @throws \Exception If the property is write-only.
	 * @throws Exception If the property is not defined.
	 * @see __set()
	 */
	public function __get( $name ) {
		$getter = 'get_' . $name;
		if ( method_exists( $this, $getter ) ) {
			return $this->$getter();
		} elseif ( method_exists( $this, 'set' . $name ) ) {
			throw new \Exception( 'Getting write-only property: ' . esc_html( get_class( $this ) . '::' . $name ) );
		}
		throw new Exception( 'Getting unknown property: ' . esc_html( get_class( $this ) . '::' . $name ) );
	}

	/**
	 * Sets value of an object property.
	 *
	 * Do not call this method directly as it is a PHP magic method that
	 * will be implicitly called when executing `$object->property = $value;`.
	 *
	 * @param string $name The property name or the event name.
	 * @param mixed  $value The property value.
	 *
	 * @throws \Exception If the property is read-only.
	 * @throws \Exception If the property is not defined.
	 * @see __get()
	 */
	public function __set( $name, $value ) {
		$setter = 'set_' . $name;
		if ( method_exists( $this, $setter ) ) {
			$this->$setter( $value );
		} elseif ( method_exists( $this, 'get' . $name ) ) {
			throw new \Exception( 'Setting read-only property: ' . esc_html( get_class( $this ) . '::' . $name ) );
		} else {
			throw new \Exception( 'Setting unknown property: ' . esc_html( get_class( $this ) . '::' . $name ) );
		}
	}
}
