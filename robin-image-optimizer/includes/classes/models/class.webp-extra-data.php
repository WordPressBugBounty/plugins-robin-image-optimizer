<?php
/**
 * WebP extra data of an attachment.
 *
 * @package Robin_Image_Optimizer
 */

/**
 * Class RIOP_WebP_Extra_Data.
 *
 * @property string $source_src
 */
class RIOP_WebP_Extra_Data extends RIO_Attachment_Extra_Data {
	/**
	 * Type of the object that the image is converted from.
	 *
	 * @var null|string E.g. attachment, nextgen, etc
	 */
	protected $convert_from = null;

	/**
	 * Size name that the image is converted from.
	 *
	 * @var null|string|int
	 */
	protected $converted_from_size = null;

	/**
	 * Source URL of the image.
	 *
	 * @var string|null Image source src.
	 */
	protected $source_src = null;

	/**
	 * Absolute path of the source image.
	 *
	 * @var string|null Image absolute path.
	 */
	protected $source_path = null;

	/**
	 * URL of the converted WebP image.
	 *
	 * @var string|null Converted WebP image src.
	 */
	protected $converted_src = null;

	/**
	 * Absolute path of the converted WebP image.
	 *
	 * @var string|null Converted WebP absolute path.
	 */
	protected $converted_path = null;

	/**
	 * ID of the attachment post.
	 *
	 * @var int|null Post ID.
	 */
	protected $post_id = null;

	/**
	 * Number of thumbnails.
	 *
	 * @var int|null thumbnails count.
	 */
	protected $thumbnails_count = null;

	/**
	 * Sets the source URL.
	 *
	 * @param string $source_src The source URL.
	 *
	 * @return void
	 */
	public function set_source_src( $source_src ) {
		$this->source_src = trim( $source_src );
	}

	/**
	 * Get source property.
	 *
	 * @param bool $decoded Whether to decode src or not.
	 *
	 * @return string
	 */
	public function get_source_src( $decoded = true ) {
		$src = $this->source_src;

		if ( $decoded ) {
			return urldecode( $src );
		}

		return $src;
	}

	/**
	 * Gets the absolute path of the source image.
	 *
	 * @return null|string
	 */
	public function get_source_path() {
		return $this->source_path;
	}

	/**
	 * Sets the absolute path of the source image.
	 *
	 * @param null|string $source_path The absolute path.
	 *
	 * @return void
	 */
	public function set_source_path( $source_path ) {
		$this->source_path = $source_path;
	}

	/**
	 * Gets the type of the object that the image is converted from.
	 *
	 * @return null|string
	 */
	public function get_convert_from() {
		return $this->convert_from;
	}

	/**
	 * Sets the type of the object that the image is converted from.
	 *
	 * @param null|string $convert_from The type of the object.
	 *
	 * @return void
	 */
	public function set_convert_from( $convert_from ) {
		$this->convert_from = $convert_from;
	}

	/**
	 * Gets the size name that the image is converted from.
	 *
	 * @return int|null|string
	 */
	public function get_converted_from_size() {
		return $this->converted_from_size;
	}

	/**
	 * Sets the size name that the image is converted from.
	 *
	 * @param int|null|string $converted_from_size The size name.
	 *
	 * @return void
	 */
	public function set_converted_from_size( $converted_from_size ) {
		$this->converted_from_size = $converted_from_size;
	}

	/**
	 * Gets the absolute path of the converted WebP image.
	 *
	 * @return string|null
	 */
	public function get_converted_path() {
		return $this->converted_path;
	}

	/**
	 * Sets the absolute path of the converted WebP image.
	 *
	 * @param string $converted_path The absolute path.
	 *
	 * @return void
	 */
	public function set_converted_path( $converted_path ) {
		$this->converted_path = $converted_path;
	}

	/**
	 * Gets the URL of the converted WebP image.
	 *
	 * @return string|null
	 */
	public function get_converted_src() {
		return $this->converted_src;
	}

	/**
	 * Sets the URL of the converted WebP image.
	 *
	 * @param string $converted_src The URL.
	 *
	 * @return void
	 */
	public function set_converted_src( $converted_src ) {
		$this->converted_src = $converted_src;
	}

	/**
	 * Gets the attachment ID.
	 *
	 * @return int|null
	 */
	public function get_post_id() {
		return $this->post_id;
	}

	/**
	 * Sets the attachment ID.
	 *
	 * @param int $post_id The attachment ID.
	 *
	 * @return RIOP_WebP_Extra_Data
	 */
	public function set_post_id( $post_id ) {
		$this->post_id = $post_id;

		return $this;
	}

	/**
	 * Get the size of the main file before the conversion.
	 *
	 * @return string
	 */
	public function get_original_main_size() {
		return '';
	}

	/**
	 * Gets the number of thumbnails.
	 *
	 * @return int|null
	 */
	public function get_thumbnails_count() {
		return $this->thumbnails_count;
	}
}
