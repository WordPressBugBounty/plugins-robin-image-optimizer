<?php
/**
 * Background queue for Media Library optimization.
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
class WRIO_Media_Processing extends WRIO_Processing {

	/**
	 * @param array $attachment_ids
	 *
	 * @return int Count of pushed queue
	 */
	public function push_items( $attachment_ids = [] ) {
		if ( empty( $attachment_ids ) && $this->scope === 'media-library' ) {
			$media_library  = WRIO_Media_Library::get_instance();
			$attachment_ids = $media_library->getUnoptimizedImages();
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$this->push_to_queue( $attachment_id );
		}

		return $this->count_queue();
	}

	/**
	 * Метод оптимизирует изображения при выполнении задачи
	 *
	 * @param int $image
	 *
	 * @return int|false The item when it must stay in the queue.
	 */
	protected function task( $image ) {
		// The lock of a process that died on this item stays until it expires: keep the item for a later run,
		// which takes the lock over and counts the crash.
		if ( $image && \WRIO\Storage\Store::locked_by_other( 'attachment', (int) $image ) ) {
			return $image;
		}

		if ( $image ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Start optimize attachment: %s', $image ) );
			$media_library = WRIO_Media_Library::get_instance();
			$locked        = true;

			try {
				// The API asked the site to wait (a 429 or any 5xx): cron optimizes the image after the wait.
				// An image excluded after it was queued is skipped.
				if ( 'media-library' === $this->scope && ! WRIO_Media_Library::skips_queued( (int) $image ) && ! WRIO_Cron::after_backoff( $image ) ) {
					\WRIO\V2\Client::use_queued_trigger( $image );

					// A foreground Start can optimize the image after it was queued. optimizeAttachment() would then
					// restore the done image and send its files again. The lock covers the check and the work.
					$locked = \WRIO\Storage\Store::with_lock(
						'attachment',
						(int) $image,
						function () use ( $media_library, $image ) {
							if ( ! ( new WIO_Attachment( (int) $image ) )->isOptimized() || \WRIO\Storage\Store::has_work( 'attachment', (int) $image ) ) {
								$media_library->optimizeAttachment( $image );
							}

							return true;
						}
					);

					if ( null !== $locked && ! WRIO_Cron::after_backoff( $image ) ) {
						delete_post_meta( $image, \WRIO\V2\Client::TRIGGER_META );
					}
				}
			} catch ( Throwable $throwable ) {
				$wio_attachment = $media_library->getAttachment( $image );
				$wio_attachment->mark_and_log_failure( $throwable, 'background-processing' );
			}

			// The next item, or another queue in this request, is bulk unless it kept a trigger.
			\WRIO\V2\Client::$trigger = 'bulk';

			WRIO_Plugin::app()->logger->info( sprintf( 'End optimize attachment: %s', $image ) );

			// Another process took the image between the checks: a later run of the queue sees it done.
			if ( null === $locked ) {
				return $image;
			}
		}

		return false;
	}
}
