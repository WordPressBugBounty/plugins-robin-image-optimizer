<?php
/**
 * Background queue for NextGEN gallery optimization.
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
class WRIO_Nextgen_Processing extends WRIO_Processing {

	/**
	 * Queues the unoptimized NextGEN images.
	 *
	 * @return int Count of pushed queue
	 */
	public function push_items() {
		$attachment_ids = [];
		if ( 'nextgen' === $this->scope ) {
			$nextgen_gallery = WRIO_Nextgen_Gallery::get_instance();
			$attachment_ids  = $nextgen_gallery->getUnoptimizedImages();
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$this->push_to_queue( $attachment_id );
		}

		return $this->count_queue();
	}

	/**
	 * Метод оптимизирует изображения при выполнении задачи
	 *
	 * @param int $image The NextGEN image ID.
	 *
	 * @return int|false The item when it must stay in the queue.
	 */
	protected function task( $image ) {
		// The lock of a process that died on this item stays until it expires: keep the item for a later run,
		// which takes the lock over and counts the crash.
		if ( $image && \WRIO\Storage\Store::locked_by_other( 'nextgen', (int) $image ) ) {
			return $image;
		}

		if ( $image ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Start optimize attachment: %s', $image ) );

			if ( 'nextgen' === $this->scope ) {
				$nextgen_gallery = WRIO_Nextgen_Gallery::get_instance();
				$nextgen_gallery->optimizeNextgenImage( $image );
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'End optimize attachment: %s', $image ) );
		}

		return false;
	}
}
