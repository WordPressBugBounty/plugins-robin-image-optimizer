<?php
/**
 * Background queue for custom folder optimization.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы оптимизации в фоне
 *
 * @version       1.0
 */
class WRIO_Folder_Processing extends WRIO_Processing {

	/**
	 * Pushes the unoptimized custom folder images to the queue.
	 *
	 * @return int Count of pushed queue
	 */
	public function push_items() {
		$attachment_ids = [];
		if ( 'custom-folders' === $this->scope ) {
			$cf             = WRIO_Custom_Folders::get_instance();
			$attachment_ids = $cf->getUnoptimizedImages();
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$this->push_to_queue( $attachment_id );
		}

		return $this->count_queue();
	}

	/**
	 * Метод оптимизирует изображения при выполнении задачи
	 *
	 * @param int $image The custom folder image ID.
	 *
	 * @return int|false The item when it must stay in the queue.
	 */
	protected function task( $image ) {
		// The lock of a process that died on this item stays until it expires: keep the item for a later run,
		// which takes the lock over and counts the crash.
		if ( $image && \WRIO\Storage\Store::locked_by_other( 'cf_image', (int) $image ) ) {
			return $image;
		}

		if ( $image ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Start optimize custom folder image: %s', $image ) );

			if ( 'custom-folders' === $this->scope ) {
				$cf = WRIO_Custom_Folders::get_instance();
				$cf->optimizeImage( $image );
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'End optimize custom folder image: %s', $image ) );
		}

		return false;
	}
}
