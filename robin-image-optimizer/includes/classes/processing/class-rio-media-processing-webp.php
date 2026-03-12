<?php

use WBCR\Factory_Processing_759\WP_Background_Process;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс для работы оптимизации в фоне
 *
 * @version       1.0
 */
class WRIO_Media_Processing_Webp extends WRIO_Processing {

	/**
	 * @var string
	 */
	protected $action = 'convert_process';

	/**
	 * @var string Format type (webp or avif)
	 */
	protected $format = 'webp';

	/**
	 * Constructor
	 *
	 * @param string $scope Processing scope
	 */
	public function __construct( $scope ) {
		parent::__construct( $scope );

		// Extract format from scope (e.g., 'media-library_webp' -> 'webp')
		if ( $this->scope && strpos( $this->scope, '_' ) !== false ) {
			$parts            = explode( '_', $this->scope );
			$extracted_format = end( $parts );
			if ( in_array( $extracted_format, [ 'webp', 'avif' ], true ) ) {
				$this->format = $extracted_format;
			}
		}
	}

	/**
	 * @return int Count of pushed queue
	 */
	public function push_items() {
		$attachment_ids = [];
		if ( strpos( $this->scope, 'media-library_' ) === 0 ) {
			$media_library  = WRIO_Media_Library::get_instance();
			$attachment_ids = $media_library->getUnconvertedImages( $this->format );
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
	 * @return bool
	 */
	protected function task( $image ) {
		if ( $image ) {
			WRIO_Plugin::app()->logger->info( sprintf( 'Start convert attachment #%s to %s', $image, $this->format ) );
			$media_library = WRIO_Media_Library::get_instance();

			try {
				if ( strpos( $this->scope, 'media-library_' ) === 0 ) {
					$media_library->webpConvertAttachment( $image, $this->format );
				}
			} catch ( Throwable $throwable ) {
				$wio_attachment = $media_library->getAttachment( $image );
				$wio_attachment->mark_conversion_failure( $throwable, $this->format, sprintf( '%s-conversion-background', $this->format ) );
			}

			WRIO_Plugin::app()->logger->info( sprintf( 'End convert attachment #%s to %s', $image, $this->format ) );
		}

		return false;
	}

	/**
	 * Fire after complete handle
	 *
	 * @return void
	 */
	protected function handle_after_complete() {
		WRIO_Plugin::app()->updatePopulateOption( "{$this->scope}_process_running", false );

		WRIO_Plugin::app()->logger->info(
			sprintf( '%s conversion background process completed for scope: %s', strtoupper( $this->format ), $this->scope )
		);
	}
}
