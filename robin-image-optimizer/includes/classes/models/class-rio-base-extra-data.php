<?php

/**
 * Class RIO_Base_Extra_Data is a base DTO model for `extra_data` property in RIO_Process_Queue.
 *
 * @see RIO_Process_Queue::$extra_data for further information
 */
class RIO_Base_Extra_Data extends RIO_Base_Object {

	/**
	 * @var string Instance of current class.
	 */
	protected $class;

	/**
	 * What failed: path, backup, optimization, request, skipped, ...
	 *
	 * @var string|null
	 */
	protected $error = null;

	/**
	 * Message for the user.
	 *
	 * @var string|null
	 */
	protected $error_msg = null;

	/**
	 * Problem code of the v2 API, for example corrupt_image.
	 *
	 * @var string|null
	 */
	protected $error_code = null;

	/**
	 * True when the same file always fails again. A bulk run does not send it again.
	 *
	 * @var bool|null
	 */
	protected $permanent = null;

	/**
	 * Set a property through its setter.
	 *
	 * Rows from older plugin versions can hold fields that no longer exist. They are ignored.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	public function __set( $name, $value ) {
		$setter = 'set_' . $name;

		if ( method_exists( $this, $setter ) ) {
			$this->$setter( $value );
		}
	}

	/**
	 * Record a failure.
	 *
	 * @param string          $kind  What failed: path, backup, optimization, ...
	 * @param WP_Error|string $error The error, or its message.
	 *
	 * @return void
	 */
	public function set_failure( $kind, $error ) {
		$this->error = $kind;

		if ( ! is_wp_error( $error ) ) {
			$this->error_msg = (string) $error;

			return;
		}

		$data             = (array) $error->get_error_data();
		$this->error_msg  = $error->get_error_message();
		$this->error_code = isset( $data['code'] ) ? (string) $data['code'] : (string) $error->get_error_code();
		$this->permanent  = empty( $data['permanent'] ) ? null : true;
	}

	/**
	 * Forget an earlier failure.
	 *
	 * @return void
	 */
	public function clear_failure() {
		$this->error      = null;
		$this->error_msg  = null;
		$this->error_code = null;
		$this->permanent  = null;
	}

	/**
	 * What failed.
	 *
	 * @return string|null
	 */
	public function get_error() {
		return $this->error;
	}

	/**
	 * Set what failed.
	 *
	 * @param string|null $error What failed.
	 *
	 * @return void
	 */
	public function set_error( $error ) {
		$this->error = $error;
	}

	/**
	 * Message for the user.
	 *
	 * @return string|null
	 */
	public function get_error_msg() {
		return $this->error_msg;
	}

	/**
	 * Set the message for the user.
	 *
	 * @param string|null $error_msg Message for the user.
	 *
	 * @return void
	 */
	public function set_error_msg( $error_msg ) {
		$this->error_msg = $error_msg;
	}

	/**
	 * Problem code of the v2 API.
	 *
	 * @return string|null
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Set the problem code.
	 *
	 * @param string|null $error_code Problem code.
	 *
	 * @return void
	 */
	public function set_error_code( $error_code ) {
		$this->error_code = $error_code;
	}

	/**
	 * Whether the same file always fails again.
	 *
	 * @return bool
	 */
	public function is_permanent() {
		return (bool) $this->permanent;
	}

	/**
	 * Set whether the same file always fails again.
	 *
	 * @param bool|null $permanent True when the same file always fails again.
	 *
	 * @return void
	 */
	public function set_permanent( $permanent ) {
		$this->permanent = $permanent ? true : null;
	}

	/**
	 * Magic override of to string method to convert
	 *
	 * @return bool|false|mixed|string
	 */
	public function __toString() {
		$props = get_object_vars( $this );
		// если свойство не установлено, то не сохраняем его
		foreach ( $props as $prop_name => $prop_value ) {
			if ( is_null( $prop_value ) ) {
				unset( $props[ $prop_name ] );
			}
		}
		$props['class'] = get_called_class();

		return wp_json_encode( $props );
	}

	/**
	 * Get class
	 *
	 * @return  string
	 */
	public function get_class() {
		return $this->class;
	}

	/**
	 * Set class
	 *
	 * @param  string $class_name  Имя класса
	 *
	 * @return  void
	 */
	public function set_class( $class_name ) {
		$this->class = $class_name;
	}
}
