<?php
/**
 * Template loader of the plugin views.
 *
 * @package Robin_Image_Optimizer
 */

/**
 * Class that handles templates.
 *
 * @version       1.0
 */
class WRIO_Views {

	/**
	 * The single instance of the class.
	 *
	 * @since  1.3.0
	 * @access protected
	 * @var    array<string, self>
	 */
	protected static $_instance = [];

	/**
	 * Plugin directory path.
	 *
	 * @since  1.3.0
	 * @var string
	 */
	protected $plugin_dir;

	/**
	 * WRIO_Views constructor.
	 *
	 * @param string $plugin_dir Plugin directory path.
	 */
	public function __construct( $plugin_dir ) {
		$this->plugin_dir = $plugin_dir;
	}

	/**
	 * Get the instance for a plugin directory.
	 *
	 * @since  1.3.6 - add instace id
	 * @since  1.3.0
	 *
	 * @param string $plugin_dir Plugin directory path.
	 *
	 * @return object|\WRIO_Views object Main instance.
	 */
	public static function get_instance( $plugin_dir ) {
		$instance_id = md5( $plugin_dir );

		if ( ! isset( self::$_instance[ $instance_id ] ) ) {
			self::$_instance[ $instance_id ] = new self( $plugin_dir );
		}

		return self::$_instance[ $instance_id ];
	}

	/**
	 * Get a template contents.
	 *
	 * @since  1.3.0
	 *
	 * @param string      $template   The template name.
	 * @param mixed       $data       Some data to pass to the template.
	 * @param object|null $page The page.
	 *
	 * @return bool|string       The page contents. False if the template doesn't exist.
	 */
	public function get_template( $template, $data = [], $page = null ) {
		$template = str_replace( '_', '-', $template );
		$path     = $this->plugin_dir . '/views/' . $template . '.php';

		if ( ! file_exists( $path ) ) {
			return false;
		}

		ob_start();
		include $path;
		$contents = ob_get_clean();

		return trim( (string) $contents );
	}

	/**
	 * Print a template.
	 *
	 * @access public
	 *
	 * @since  1.3.0
	 *
	 * @param string      $template   The template name.
	 * @param mixed       $data       Some data to pass to the template.
	 * @param object|null $page The page.
	 *
	 * @return void
	 */
	public function print_template( $template, $data = [], $page = null ) {
		echo $this->get_template( $template, $data, $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes its own output.
	}
}
