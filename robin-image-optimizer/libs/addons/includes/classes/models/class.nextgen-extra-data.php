<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_Nextgen_Extra_Data is a  DTO model for `nextgen` post type used for `extra_data`
 * property in RIO_Process_Queue.
 *
 * @see    RIO_Process_Queue::$extra_data for further information
 */
class WRIO_Nextgen_Extra_Data extends RIO_Base_Extra_Data {

	/**
	 * @var int оригинальный размер основного файла
	 */
	protected $original_main_size = null;

	/**
	 * @var int оптимизированный размер основного файла
	 */
	protected $optimized_main_size = null;

	/**
	 * @var string относительный путь к изображению
	 */
	protected $image_relative_path = null;

	/**
	 * get_original_main_size
	 *
	 * @return int
	 */
	public function get_original_main_size() {
		return $this->original_main_size;
	}

	/**
	 * set_original_main_size
	 *
	 * @param int $original_main_size
	 *
	 * @return void
	 */
	public function set_original_main_size( $original_main_size ) {
		$this->original_main_size = $original_main_size;
	}

	/**
	 * get_optimized_main_size
	 *
	 * @return int
	 */
	public function get_optimized_main_size() {
		return $this->optimized_main_size;
	}

	/**
	 * set_optimized_main_size
	 *
	 * @param int $optimized_main_size
	 *
	 * @return void
	 */
	public function set_optimized_main_size( $optimized_main_size ) {
		$this->optimized_main_size = $optimized_main_size;
	}

	/**
	 * get_image_relative_path
	 *
	 * @return string
	 */
	public function get_image_relative_path() {
		return $this->image_relative_path;
	}

	/**
	 * set_image_relative_path
	 *
	 * @param string $image_relative_path
	 *
	 * @return void
	 */
	public function set_image_relative_path( $image_relative_path ) {
		$this->image_relative_path = $image_relative_path;
	}

	/**
	 * Возвращает путь к превьюшке относительно корня WP
	 *
	 * @return string
	 */
	public function get_image_thumbnail_relative_path() {
		$basename = wp_basename( $this->image_relative_path );
		$dir      = dirname( $this->image_relative_path );

		return $dir . '/thumbs/thumbs-' . $basename;
	}

	/**
	 * Возвращает абсолютный путь к изображению
	 *
	 * @return string
	 */
	public function get_image_absolute_path() {
		$relative_path = $this->get_image_relative_path();

		return wp_normalize_path( untrailingslashit( ABSPATH ) . $relative_path );
	}

	/**
	 * Возвращает абсолютный путь к превьюшке
	 *
	 * @return string
	 */
	public function get_image_thumbnail_absolute_path() {
		$relative_path = $this->get_image_thumbnail_relative_path();

		return wp_normalize_path( untrailingslashit( ABSPATH ) . $relative_path );
	}

	/**
	 * Возвращает URL изображения
	 *
	 * @return string
	 */
	public function get_image_url() {
		$relative_path = $this->get_image_relative_path();

		return home_url( $relative_path );
	}

	/**
	 * Возвращает URL изображения-превьюшки
	 *
	 * @return string
	 */
	public function get_image_thumbnail_url() {
		$relative_path = $this->get_image_thumbnail_relative_path();

		return home_url( $relative_path );
	}
}
