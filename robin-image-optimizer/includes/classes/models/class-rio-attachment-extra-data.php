<?php
/**
 * Extra data model for attachments.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RIO_Attachment_Extra_Data is a  DTO model for `attachment` post type used for `extra_data`
 * property in RIO_Process_Queue.
 *
 * @see RIO_Process_Queue::$extra_data for further information
 */
class RIO_Attachment_Extra_Data extends RIO_Base_Extra_Data {

	/**
	 * Number of optimized sizes, the main file excluded.
	 *
	 * @var int|null
	 */
	protected $thumbnails_count = null;

	/**
	 * Size of the main file before the optimization.
	 *
	 * @var int|null
	 */
	protected $original_main_size = null;

	/**
	 * Get the number of optimized sizes, the main file excluded.
	 *
	 * @return int|null
	 */
	public function get_thumbnails_count() {
		return $this->thumbnails_count;
	}

	/**
	 * Set the number of optimized sizes, the main file excluded.
	 *
	 * @param int|null $thumbnails_count Number of optimized sizes.
	 *
	 * @return void
	 */
	public function set_thumbnails_count( $thumbnails_count ) {
		$this->thumbnails_count = $thumbnails_count;
	}

	public function get_original_main_size() {
		return $this->original_main_size;
	}

	/**
	 * Set the size of the main file before the optimization.
	 *
	 * @param int|null $original_main_size Size in bytes.
	 *
	 * @return void
	 */
	public function set_original_main_size( $original_main_size ) {
		$this->original_main_size = $original_main_size;
	}
}
