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
class WRIO_CF_Image_Extra_Data extends RIO_Base_Extra_Data {

	/**
	 * @var string путь к файлу относительно папки
	 */
	protected $file_path = null;

	/**
	 * @var int оригинальный размер основного файла
	 */
	protected $original_main_size = null;

	/**
	 * @var int оптимизированный размер основного файла
	 */
	protected $optimized_main_size = null;

	/**
	 * @var string путь к папке относительно корня WP
	 */
	protected $folder_relative_path = null;

	/**
	 * get_file_path
	 *
	 * @return string
	 */
	public function get_file_path() {
		return $this->file_path;
	}

	/**
	 * set_file_path
	 *
	 * @param string $file_path
	 *
	 * @return void
	 */
	public function set_file_path( $file_path ) {
		$this->file_path = $file_path;
	}

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
	 * get_folder_relative_path
	 *
	 * @return string
	 */
	public function get_folder_relative_path() {
		return $this->folder_relative_path;
	}

	/**
	 * set_folder_relative_path
	 *
	 * @param string $folder_relative_path
	 *
	 * @return void
	 */
	public function set_folder_relative_path( $folder_relative_path ) {
		$this->folder_relative_path = $folder_relative_path;
	}

	/**
	 * Возвращает абсолютный путь к папке
	 *
	 * @return string
	 */
	public function get_folder_absolute_path() {
		$relative_path = $this->get_folder_relative_path();
		// Use get_home_path() to match how real_path_to_relative() calculates relative paths
		$base_path = is_main_site() ? get_home_path() : wp_upload_dir()['basedir'] . '/';

		return wp_normalize_path( untrailingslashit( $base_path ) . $relative_path );
	}

	/**
	 * Возвращает путь к изображению относительно корня WP
	 *
	 * @return string
	 */
	public function get_image_relative_path() {
		return wp_normalize_path( $this->get_file_path() );
	}

	/**
	 * Возвращает абсолютный путь к изображению
	 *
	 * @return string
	 */
	public function get_image_absolute_path() {
		$relative_path = $this->get_image_relative_path();
		// Use get_home_path() to match how real_path_to_relative() calculates relative paths
		$base_path = is_main_site() ? get_home_path() : wp_upload_dir()['basedir'] . '/';

		return wp_normalize_path( untrailingslashit( $base_path ) . $relative_path );
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
}
