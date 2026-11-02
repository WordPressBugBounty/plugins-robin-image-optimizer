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
		// processUnoptimizedImages() chooses the action and selects its batch in one query.
		return $this->execute_optimization( $batch_size );
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

		// Nothing to select: the run ends, also when a count still sees an image that no query selects.
		if ( '' === $result['work'] ) {
			return [
				'action'         => self::ACTION_COMPLETE,
				'remain'         => 0,
				'end'            => true,
				'statistic'      => $result['statistic'],
				'last_optimized' => [],
			];
		}

		$actions = [
			'attachment' => self::ACTION_OPTIMIZE,
			'webp'       => self::ACTION_CONVERT_WEBP,
			'avif'       => self::ACTION_CONVERT_AVIF,
		];

		// The step handled the images it selected. The result counted all the work left.
		$remaining   = (int) $result['remain'];
		$is_complete = $remaining <= 0;

		return [
			'action'         => $actions[ $result['work'] ],
			'remain'         => $remaining,
			'end'            => $is_complete,
			'held'           => ! empty( $result['held'] ),
			'statistic'      => $result['statistic'],
			'last_optimized' => $result['last_optimized'],
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
		$unconverted_images = WRIO_Image_Query::get_instance()->get_unconverted_ids( 'avif' === $format ? 'avif' : 'webp', 1 );

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

		if ( \WRIO\Storage\Backfill::reads() ) {
			return WRIO_Image_Query::get_instance()->count_unoptimized( true, [ 'attachment' ] );
		}

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
				)" . WRIO_Image_Query::get_instance()->get_exclusion_clause();

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

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Plugin table, allowed MIME types and fixed conditions only.
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

		if ( \WRIO\Storage\Backfill::reads() ) {
			return WRIO_Image_Query::get_instance()->get_unoptimized_ids( $limit, 0, true, [ 'attachment' ] );
		}

		$db_table            = RIO_Process_Queue::table_name();
		$formats             = wrio_get_allowed_formats( true );
		$allowed_formats_sql = is_array( $formats ) ? implode( ', ', $formats ) : $formats;
		$order_by            = WRIO_Image_Query::get_instance()->get_order_by();

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
				)" . WRIO_Image_Query::get_instance()->get_exclusion_clause();

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

		$sql .= " ORDER BY {$order_by} LIMIT %d";
		$sql  = $wpdb->prepare( $sql, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Plugin table, allowed MIME types and a fixed sort order; the limit is a placeholder.

		return array_map( 'absint', $wpdb->get_col( $sql ) ?? [] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
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
		if ( $this->get_attachment_unoptimized_count() > 0 ) {
			return false;
		}

		if ( $this->is_webp_enabled() ) {
			$webp_unconverted = WRIO_Image_Statistic::get_unconverted_count( 'webp' );
			if ( $webp_unconverted > 0 ) {
				return false;
			}
		}

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
		return WRIO_Formats::is_webp_enabled();
	}

	/**
	 * Check if AVIF conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_avif_enabled() {
		return WRIO_Formats::is_avif_enabled();
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
