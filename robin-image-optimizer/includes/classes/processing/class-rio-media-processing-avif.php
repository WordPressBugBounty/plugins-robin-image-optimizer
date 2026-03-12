<?php

use WBCR\Factory_Processing_759\WP_Background_Process;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы AVIF конвертации в фоне
 * Class for AVIF image format conversion background processing
 *
 * @version       1.6.0
 * @since         1.6.0
 */
class WRIO_Media_Processing_Avif extends WRIO_Processing {

	/**
	 * @var string
	 */
	protected $action = 'convert_process';

	/**
	 * @var string Format type
	 */
	protected $format = 'avif';

	/**
	 * Constructor
	 *
	 * @param string $scope Processing scope
	 */
	public function __construct( $scope ) {
		parent::__construct( $scope );
	}

	/**
	 * Push items to queue
	 *
	 * @return int Number of items in queue
	 */
	public function push_items() {
		$attachment_ids = [];

		if ( $this->scope === 'media-library_avif' ) {
			$media_library  = WRIO_Media_Library::get_instance();
			$attachment_ids = $media_library->getUnconvertedImages( 'avif' );
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$this->push_to_queue( $attachment_id );
		}

		return $this->count_queue();
	}

	/**
	 * Метод конвертирует изображения в AVIF при выполнении задачи
	 * Method converts images to AVIF when executing task
	 *
	 * @param int $image Attachment ID
	 *
	 * @return bool
	 */
	protected function task( $image ) {
		if ( $image ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Start convert attachment #%s to AVIF', $image ) );
			$media_library = WRIO_Media_Library::get_instance();

			try {
				if ( 'media-library_avif' === $this->scope ) {
					$media_library->webpConvertAttachment( $image, 'avif' );
				}
			} catch ( Throwable $throwable ) {
				$wio_attachment = $media_library->getAttachment( $image );
				$wio_attachment->mark_conversion_failure( $throwable, 'avif', 'avif-conversion-background' );
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'End convert attachment #%s to AVIF', $image ) );
		}

		return false;
	}

	/**
	 * Fire after complete handle
	 * Вызывается после завершения обработки
	 *
	 * @return void
	 */
	protected function handle_after_complete() {
		WRIO_Plugin::app()->updatePopulateOption( "{$this->scope}_process_running", false );

		WRIO_Plugin::app()->logger->info(
			sprintf( 'AVIF conversion background process completed for scope: %s', $this->scope )
		);
	}
}
