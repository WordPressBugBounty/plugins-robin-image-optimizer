<?php

/**
 * Class WRIO_WebP_Api - Backward compatibility wrapper for format conversion.
 *
 * This class now delegates to the new unified format converter system.
 *
 * @author Alexander Teshabaev <sasha.tesh@gmail.com>
 * @deprecated Use WRIO_Format_Converter_Factory instead.
 */
class WRIO_WebP_Api {

	/**
	 * @var WRIO_Format_Converter_Api Format converter instance.
	 */
	private $_format_converter;

	/**
	 * WRIO_WebP_Api constructor.
	 *
	 * @param RIO_Process_Queue[] $model Item to be converted to WebP.
	 */
	public function __construct( $model ) {
		// Delegate to new unified format converter (always use WebP for backward compatibility)
		$this->_format_converter = WRIO_Format_Converter_Factory::create( $model, 'webp' );
	}

	/**
	 * Process image queue based on provided attachment ID.
	 *
	 * @param bool $quota decr quota?.
	 *
	 * @return bool true on success execution, false on failure or missing item in queue.
	 */
	public function process_image_queue( $quota = false ) {
		return $this->_format_converter->process_image_queue( $quota );
	}

	/**
	 * Request API
	 *
	 * @param RIO_Process_Queue $model Queue model.
	 * @param bool              $quota decr quota?.
	 *
	 * @return array|bool|WP_Error
	 */
	public function request( $model, $quota = false ) {
		return $this->_format_converter->request( $model, $quota );
	}

	/**
	 * Process response from API.
	 *
	 * @param array|WP_Error|false $response
	 *
	 * @return bool True means response image was successfully saved, false on failure.
	 */
	public function can_save( $response ) {
		return $this->_format_converter->can_save( $response );
	}

	/**
	 * Save file from response.
	 *
	 * @param array|WP_Error|false $response
	 * @param RIO_Process_Queue    $queue_model
	 *
	 * @return bool
	 */
	public function save_file( $response, $queue_model ) {
		return $this->_format_converter->save_file( $response, $queue_model );
	}

	/**
	 * Update processing item data to finish its cycle.
	 *
	 * @param RIO_Process_Queue $queue_model Queue model to be update.
	 *
	 * @return bool
	 */
	public function update( $queue_model ) {
		return $this->_format_converter->update( $queue_model );
	}

	/**
	 * Get complete save url.
	 *
	 * @param RIO_Process_Queue $queue_model Instance of queue item.
	 *
	 * @return string
	 */
	public function get_save_url( $queue_model ) {
		return $this->_format_converter->get_save_url( $queue_model );
	}

	/**
	 * Get absolute save path.
	 *
	 * @param \RIO_Process_Queue $queue_model
	 *
	 * @return string
	 * @throws Exception on failure to create missing directory
	 */
	public static function get_save_path( $queue_model ) {
		return WRIO_Format_Converter_WebP::get_save_path_static( $queue_model );
	}
}
