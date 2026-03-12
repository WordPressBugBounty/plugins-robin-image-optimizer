<?php
/**
 * Optimization Orchestrator class.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WRIO_Optimization_Orchestrator
 */
class WRIO_Optimization_Orchestrator {

	/**
	 * The single instance of the class.
	 *
	 * @var WRIO_Optimization_Orchestrator|null
	 */
	private static $instance = null;

	/**
	 * Action: Optimize an image.
	 */
	const ACTION_OPTIMIZE = 'optimize';

	/**
	 * Action: Convert to WebP format.
	 */
	const ACTION_CONVERT_WEBP = 'convert_webp';

	/**
	 * Action: Convert to AVIF format.
	 */
	const ACTION_CONVERT_AVIF = 'convert_avif';

	/**
	 * Action: All work is complete.
	 */
	const ACTION_COMPLETE = 'complete';

	/**
	 * Action: No action needed (nothing to do).
	 */
	const ACTION_NONE = 'none';

	/**
	 * Get singleton instance.
	 *
	 * @return WRIO_Optimization_Orchestrator
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the next action needed.
	 *
	 * Determines what action should happen next based on:
	 * 1. Unoptimized images (standard optimization)
	 * 2. Unconverted WebP images (if WebP is enabled)
	 * 3. Unconverted AVIF images (if AVIF is enabled)
	 *
	 * @param int $batch_size Number of items to process.
	 *
	 * @return array{
	 *     action: string,
	 *     attachment_id: int|null,
	 *     remaining: int,
	 *     format: string|null
	 * }
	 */
	public function get_next_action( $batch_size = 1 ) {
		// Step 1: Check for images needing ATTACHMENT optimization specifically.
		// This prevents infinite loops when images have 'attachment' success
		// but are missing 'webp' or 'avif' - those should fall through to
		// the conversion steps below, not trigger re-optimization.
		$attachment_unoptimized = $this->get_attachment_unoptimized_count();

		if ( $attachment_unoptimized > 0 ) {
			$unoptimized_ids = $this->get_attachment_unoptimized_ids( $batch_size );
			$attachment_id   = ! empty( $unoptimized_ids ) ? $unoptimized_ids[0] : null;

			return [
				'action'        => self::ACTION_OPTIMIZE,
				'attachment_id' => $attachment_id,
				'remaining'     => $attachment_unoptimized,
				'format'        => null,
			];
		}

		// Step 2: Check for unconverted WebP (if enabled)
		if ( $this->is_webp_enabled() ) {
			$webp_unconverted = WRIO_Image_Statistic::get_unconverted_count( 'webp' );

			if ( $webp_unconverted > 0 ) {
				$attachment_id = $this->get_next_unconverted_id( 'webp' );

				return [
					'action'        => self::ACTION_CONVERT_WEBP,
					'attachment_id' => $attachment_id,
					'remaining'     => $webp_unconverted,
					'format'        => 'webp',
				];
			}
		}

		// Step 3: Check for unconverted AVIF (if enabled)
		if ( $this->is_avif_enabled() ) {
			$avif_unconverted = WRIO_Image_Statistic::get_unconverted_count( 'avif' );

			if ( $avif_unconverted > 0 ) {
				$attachment_id = $this->get_next_unconverted_id( 'avif' );

				return [
					'action'        => self::ACTION_CONVERT_AVIF,
					'attachment_id' => $attachment_id,
					'remaining'     => $avif_unconverted,
					'format'        => 'avif',
				];
			}
		}

		// All work is complete
		return [
			'action'        => self::ACTION_COMPLETE,
			'attachment_id' => null,
			'remaining'     => 0,
			'format'        => null,
		];
	}

	/**
	 * Execute the next action.
	 *
	 * @param int $batch_size Number of items to process.
	 *
	 * @return array<string, mixed> Result with statistics and last_optimized.
	 */
	public function execute_next_action( $batch_size = 1 ) {
		$next = $this->get_next_action( $batch_size );

		switch ( $next['action'] ) {
			case self::ACTION_OPTIMIZE:
				return $this->execute_optimization( $batch_size );

			case self::ACTION_CONVERT_WEBP:
			case self::ACTION_CONVERT_AVIF:
				if ( null === $next['attachment_id'] || null === $next['format'] ) {
					return $this->get_completion_result();
				}
				return $this->execute_conversion( $next['attachment_id'], $next['format'] );

			case self::ACTION_COMPLETE:
				return $this->get_completion_result();

			default:
				return $this->get_completion_result();
		}
	}

	/**
	 * Execute standard optimization.
	 *
	 * @param int $batch_size Number of items to process.
	 *
	 * @return array<string, mixed>
	 */
	private function execute_optimization( $batch_size ) {
		$media_library = WRIO_Media_Library::get_instance();
		$result        = $media_library->processUnoptimizedImages( $batch_size );

		if ( is_wp_error( $result ) ) {
			return [ 'error' => $result->get_error_message() ];
		}

		// Get the attachment ID from the last optimized image for WebP/AVIF conversion
		$attachment_id = null;
		if ( ! empty( $result['last_optimized'] ) && is_array( $result['last_optimized'] ) ) {
			$first_item = reset( $result['last_optimized'] );
			if ( ! empty( $first_item['id'] ) ) {
				$queue_record = new RIO_Process_Queue( [ 'id' => $first_item['id'] ] );
				$queue_record->load();
				$attachment_id = $queue_record->get_object_id();
			}
		}

		// After optimization, convert to WebP/AVIF if enabled
		if ( $attachment_id ) {
			if ( $this->is_webp_enabled() ) {
				$media_library->webpConvertAttachment( $attachment_id, 'webp' );
			}

			if ( $this->is_avif_enabled() ) {
				$media_library->webpConvertAttachment( $attachment_id, 'avif' );
			}

			// Refresh statistics and last_optimized after conversions
			$image_statistics         = WRIO_Image_Statistic::get_instance();
			$result['statistic']      = $image_statistics->load();
			$result['last_optimized'] = $image_statistics->get_last_optimized_image( $attachment_id );
		}

		$remaining   = $this->get_total_remaining();
		$is_complete = $remaining <= 0;

		return [
			'action'         => self::ACTION_OPTIMIZE,
			'remain'         => $remaining,
			'end'            => $is_complete,
			'statistic'      => $result['statistic'],
			'last_optimized' => $result['last_optimized'],
		];
	}

	/**
	 * Execute WebP/AVIF conversion.
	 *
	 * @param int    $attachment_id Attachment ID to convert.
	 * @param string $format        Format to convert to ('webp' or 'avif').
	 *
	 * @return array<string, mixed>
	 */
	private function execute_conversion( $attachment_id, $format ) {
		if ( ! $attachment_id ) {
			return $this->get_completion_result();
		}

		$media_library = WRIO_Media_Library::get_instance();
		$media_library->webpConvertAttachment( $attachment_id, $format );

		$image_statistics = WRIO_Image_Statistic::get_instance();
		$remaining        = WRIO_Image_Statistic::get_unconverted_count( $format );

		return [
			'action'         => 'avif' === $format ? self::ACTION_CONVERT_AVIF : self::ACTION_CONVERT_WEBP,
			'format'         => $format,
			'remain'         => $remaining,
			'end'            => $this->is_complete(),
			'statistic'      => $image_statistics->load(),
			'last_optimized' => $image_statistics->get_last_optimized_image( $attachment_id ),
		];
	}

	/**
	 * Get completion result.
	 *
	 * @return array<string, mixed>
	 */
	private function get_completion_result() {
		$image_statistics = WRIO_Image_Statistic::get_instance();

		return [
			'action'         => self::ACTION_COMPLETE,
			'remain'         => 0,
			'end'            => true,
			'statistic'      => $image_statistics->load(),
			'last_optimized' => [],
		];
	}

	/**
	 * Get next unconverted attachment ID for a format.
	 *
	 * @param string $format Format type: 'webp' or 'avif'.
	 *
	 * @return int|null Attachment ID or null if none found.
	 */
	public function get_next_unconverted_id( $format ) {
		$unconverted_images = WRIO_Image_Statistic::get_unconverted_images( $format );

		if ( ! empty( $unconverted_images ) ) {
			return (int) $unconverted_images[0];
		}

		return null;
	}

	/**
	 * Count images that need 'attachment' optimization specifically.
	 *
	 * This is different from get_unoptimized_count() which counts images
	 * missing ANY required type (attachment, webp, avif). This method
	 * only checks for the 'attachment' type to prevent infinite loops
	 * when images have attachment success but are missing webp/avif.
	 *
	 * Attachments with a terminal attachment-level error are excluded from
	 * the pending count. Bulk processing resets those errors explicitly on
	 * the first request when the user wants to retry failed items.
	 *
	 * @return int
	 */
	private function get_attachment_unoptimized_count() {
		global $wpdb;
		$db_table            = RIO_Process_Queue::table_name();
		$formats             = wrio_get_allowed_formats( true );
		$allowed_formats_sql = is_array( $formats ) ? implode( ', ', $formats ) : $formats;

		$sql = "SELECT COUNT(DISTINCT posts.ID)
			FROM {$wpdb->posts} AS posts
			WHERE posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$allowed_formats_sql} )
				AND posts.ID NOT IN (
					SELECT object_id FROM {$db_table} AS rio
					WHERE rio.item_type = 'attachment'
					AND rio.result_status = 'success'
					GROUP BY object_id
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$db_table} AS rio
					WHERE rio.object_id = posts.ID
					AND rio.item_type = 'attachment'
					AND rio.result_status = 'error'
				)";

		// Add WPML exclusion if needed
		if ( defined( 'WPML_PLUGIN_FILE' ) ) {
			$sql = str_replace(
				'WHERE posts.post_type =',
				"WHERE NOT EXISTS (
					SELECT trnsl.element_id
					FROM {$wpdb->prefix}icl_translations AS trnsl
					WHERE trnsl.element_id = posts.ID
						AND trnsl.element_type = 'post_attachment'
						AND trnsl.source_language_code IS NOT NULL
				) AND posts.post_type =",
				$sql
			);
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get IDs of images that need 'attachment' optimization specifically.
	 *
	 * @param int $limit Number of IDs to return.
	 *
	 * @return int[] Array of attachment IDs.
	 */
	private function get_attachment_unoptimized_ids( $limit = 1 ) {
		global $wpdb;
		$db_table            = RIO_Process_Queue::table_name();
		$formats             = wrio_get_allowed_formats( true );
		$allowed_formats_sql = is_array( $formats ) ? implode( ', ', $formats ) : $formats;
		$order               = WRIO_Plugin::app()->getOption( 'image_optimization_order', 'asc' );
		$order               = strtolower( $order ) === 'desc' ? 'DESC' : 'ASC';

		$sql = "SELECT DISTINCT posts.ID
			FROM {$wpdb->posts} AS posts
			WHERE posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$allowed_formats_sql} )
				AND posts.ID NOT IN (
					SELECT object_id FROM {$db_table} AS rio
					WHERE rio.item_type = 'attachment'
					AND rio.result_status = 'success'
					GROUP BY object_id
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$db_table} AS rio
					WHERE rio.object_id = posts.ID
					AND rio.item_type = 'attachment'
					AND rio.result_status = 'error'
				)";

		// Add WPML exclusion if needed
		if ( defined( 'WPML_PLUGIN_FILE' ) ) {
			$sql = str_replace(
				'WHERE posts.post_type =',
				"WHERE NOT EXISTS (
					SELECT trnsl.element_id
					FROM {$wpdb->prefix}icl_translations AS trnsl
					WHERE trnsl.element_id = posts.ID
						AND trnsl.element_type = 'post_attachment'
						AND trnsl.source_language_code IS NOT NULL
				) AND posts.post_type =",
				$sql
			);
		}

		$sql .= " ORDER BY posts.ID {$order} LIMIT %d";
		$sql  = $wpdb->prepare( $sql, $limit );

		return array_map( 'absint', $wpdb->get_col( $sql ) ?? [] );
	}

	/**
	 * Check if all work is complete.
	 *
	 * Returns true only when:
	 * - No unoptimized images remain (attachment type)
	 * - No unconverted WebP (if WebP enabled)
	 * - No unconverted AVIF (if AVIF enabled)
	 *
	 * @return bool
	 */
	public function is_complete() {
		// Check attachment optimization specifically
		if ( $this->get_attachment_unoptimized_count() > 0 ) {
			return false;
		}

		// Check WebP conversion
		if ( $this->is_webp_enabled() ) {
			$webp_unconverted = WRIO_Image_Statistic::get_unconverted_count( 'webp' );
			if ( $webp_unconverted > 0 ) {
				return false;
			}
		}

		// Check AVIF conversion
		if ( $this->is_avif_enabled() ) {
			$avif_unconverted = WRIO_Image_Statistic::get_unconverted_count( 'avif' );
			if ( $avif_unconverted > 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if WebP conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_webp_enabled() {
		if ( ! class_exists( 'WRIO_Format_Converter_Factory' ) ) {
			return false;
		}

		return WRIO_Format_Converter_Factory::is_webp_enabled();
	}

	/**
	 * Check if AVIF conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_avif_enabled() {
		if ( ! class_exists( 'WRIO_Format_Converter_Factory' ) ) {
			return false;
		}

		return WRIO_Format_Converter_Factory::is_avif_enabled();
	}

	/**
	 * Get total remaining work count.
	 *
	 * Returns the total number of items still requiring processing,
	 * including optimization and format conversions.
	 *
	 * @return int
	 */
	public function get_total_remaining() {
		$total = $this->get_attachment_unoptimized_count();

		if ( $this->is_webp_enabled() ) {
			$total += WRIO_Image_Statistic::get_unconverted_count( 'webp' );
		}

		if ( $this->is_avif_enabled() ) {
			$total += WRIO_Image_Statistic::get_unconverted_count( 'avif' );
		}

		return $total;
	}
}
