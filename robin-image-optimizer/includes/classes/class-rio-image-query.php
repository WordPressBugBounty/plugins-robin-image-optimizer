<?php
/**
 * Image Query class.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WRIO_Image_Query
 */
class WRIO_Image_Query {

	/**
	 * The single instance of the class.
	 *
	 * @var WRIO_Image_Query|null
	 */
	protected static $instance = null;

	/**
	 * Cache for allowed formats SQL string
	 *
	 * @var string
	 */
	protected $allowed_formats_sql;

	/**
	 * Cache for required conversion types
	 *
	 * @var string[]|null
	 */
	protected $required_types = null;

	/**
	 * Cache group for all query results
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wrio_image_query';

	/**
	 * Constructor
	 */
	public function __construct() {
		$formats                   = wrio_get_allowed_formats( true );
		$this->allowed_formats_sql = is_array( $formats ) ? implode( ', ', $formats ) : $formats;
	}

	/**
	 * Register cache invalidation hooks.
	 *
	 * Call this once during plugin initialization to automatically
	 * clear query caches when images are optimized, restored, or deleted.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'wbcr/riop/queue_item_saved', [ __CLASS__, 'clear_cache' ], 100 );
		add_action( 'wbcr/rio/attachment_restored', [ __CLASS__, 'clear_cache' ], 100 );
		add_action( 'delete_attachment', [ __CLASS__, 'clear_cache' ], 100 );
	}

	/**
	 * Get singleton instance
	 *
	 * @return WRIO_Image_Query
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build list of required conversion types based on enabled formats.
	 * Computed fresh each call to avoid stale data.
	 *
	 * @since 1.5.0
	 *
	 * @return string[] Array of required item_types
	 */
	protected function build_required_types() {
		$types = [ 'attachment' ]; // Basic optimization always required

		if ( class_exists( 'WRIO_Format_Converter_Factory' ) ) {
			if ( WRIO_Format_Converter_Factory::is_webp_enabled() ) {
				$types[] = 'webp';
			}
			if ( WRIO_Format_Converter_Factory::is_avif_enabled() ) {
				$types[] = 'avif';
			}
		}

		return $types;
	}

	/**
	 * Get required conversion types.
	 * Lazy loads on first access.
	 *
	 * @since 1.5.0
	 *
	 * @return string[]
	 */
	public function get_required_types() {
		if ( null === $this->required_types ) {
			$this->required_types = $this->build_required_types();
		}

		return $this->required_types;
	}

	/**
	 * Get sanitized optimization order.
	 * Prevents SQL injection by validating order parameter.
	 *
	 * @since 1.5.0
	 *
	 * @return string 'ASC' or 'DESC'
	 */
	protected function get_optimize_order() {
		$order = WRIO_Plugin::app()->getOption( 'image_optimization_order', 'asc' );

		// Whitelist validation - only allow 'DESC', all others default to 'ASC'
		return strtolower( $order ) === 'desc' ? 'DESC' : 'ASC';
	}

	/**
	 * Get WPML exclusion clause for filtering translation duplicates.
	 *
	 * @since 1.5.0
	 *
	 * @return string SQL clause or empty string if WPML not active
	 */
	protected function get_wpml_exclusion_clause() {
		global $wpdb;

		if ( ! defined( 'WPML_PLUGIN_FILE' ) ) {
			return '';
		}

		return " AND NOT EXISTS (
			SELECT trnsl.element_id
			FROM {$wpdb->prefix}icl_translations AS trnsl
			WHERE trnsl.element_id = posts.ID
				AND trnsl.element_type = 'post_attachment'
				AND trnsl.source_language_code IS NOT NULL
		)";
	}

	/**
	 * Append pagination to SQL query.
	 *
	 * @since 1.5.0
	 *
	 * @param string   $sql    SQL query.
	 * @param int|null $limit  Number of results to return.
	 * @param int      $offset Number of results to skip.
	 *
	 * @return string SQL with pagination appended
	 */
	protected function append_pagination( $sql, $limit = null, $offset = 0 ) {
		if ( $limit ) {
			$sql .= sprintf( ' LIMIT %d, %d', absint( $offset ), absint( $limit ) );
		}

		return $sql;
	}

	/**
	 * Get cached count or compute and cache result.
	 *
	 * @since 1.5.0
	 *
	 * @param string   $cache_key Cache key (will be namespaced).
	 * @param callable $callback  Callback that returns the count.
	 *
	 * @return int
	 */
	protected function get_cached_count( $cache_key, $callback ) {
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) call_user_func( $callback );

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Build base attachment query with common conditions.
	 *
	 * @since 1.5.0
	 *
	 * @param string $select_clause The SELECT portion (e.g., 'DISTINCT posts.ID').
	 *
	 * @return string
	 */
	protected function get_base_query( $select_clause ) {
		global $wpdb;

		return "SELECT {$select_clause}
			FROM {$wpdb->posts} posts
			WHERE posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$this->allowed_formats_sql} )";
	}

	/**
	 * Build optimization status EXISTS clause.
	 *
	 * @since 1.5.0
	 *
	 * @param bool $negate    Use NOT EXISTS instead of EXISTS.
	 * @param bool $all_types Check all required types are complete.
	 *
	 * @return string SQL clause
	 */
	protected function get_optimization_exists_clause( $negate = false, $all_types = true ) {
		$db_table = RIO_Process_Queue::table_name();
		$exists   = $negate ? 'NOT EXISTS' : 'EXISTS';
		$types    = $this->get_required_types();

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$clause = "{$exists} (
			SELECT 1
			FROM {$db_table} rio
			WHERE rio.object_id = posts.ID
				AND rio.item_type IN ( {$placeholders} )
				AND rio.result_status = 'success'";

		if ( $all_types ) {
			$clause .= '
			GROUP BY rio.object_id
			HAVING COUNT(DISTINCT rio.item_type) = ' . count( $types );
		}

		$clause .= '
		)';

		return $clause;
	}

	/**
	 * Build error status EXISTS clause.
	 *
	 * @since 1.5.0
	 *
	 * @return string SQL clause
	 */
	protected function get_error_exists_clause() {
		$db_table = RIO_Process_Queue::table_name();

		return "EXISTS (
			SELECT 1
			FROM {$db_table} rio
			WHERE rio.object_id = posts.ID
				AND rio.result_status = 'error'
		)";
	}

	/**
	 * Build ORDER BY clause for unoptimized images, pushing error items to the end.
	 *
	 * @since 1.5.0
	 *
	 * @return string SQL clause
	 */
	protected function get_unoptimized_order_clause() {
		return ' ORDER BY CASE WHEN ' . $this->get_error_exists_clause() . ' THEN 1 ELSE 0 END ASC, posts.ID ' . $this->get_optimize_order();
	}

	/**
	 * Get IDs of fully optimized images.
	 *
	 * An image is optimized if it has successful conversions for ALL required types.
	 *
	 * @since 1.5.0
	 *
	 * @param int|null $limit  Number of results to return. NULL for no limit.
	 * @param int      $offset Number of results to skip.
	 *
	 * @return int[] Array of attachment IDs
	 */
	public function get_optimized_ids( $limit = null, $offset = 0 ) {
		global $wpdb;

		$sql  = $this->get_base_query( 'DISTINCT posts.ID' );
		$sql .= ' AND ' . $this->get_optimization_exists_clause( false, true );
		$sql .= ' ORDER BY posts.ID ' . $this->get_optimize_order();
		$sql  = $this->append_pagination( $sql, $limit, $offset );

		$sql = $wpdb->prepare( $sql, $this->get_required_types() );

		$result = $wpdb->get_col( $sql );

		return array_map( 'absint', $result ?? [] );
	}

	/**
	 * Get IDs of unoptimized images.
	 *
	 * Unoptimized images are those missing ANY required conversion with success status.
	 * Includes: never queued, partial, failed, and processing images.
	 *
	 * @since 1.5.0
	 *
	 * @param int|null $limit              Number of results to return. NULL for no limit.
	 * @param int      $offset             Number of results to skip.
	 * @param bool     $exclude_wpml_dupes Whether to exclude WPML translation duplicates.
	 *
	 * @return int[] Array of attachment IDs
	 */
	public function get_unoptimized_ids( $limit = null, $offset = 0, $exclude_wpml_dupes = true ) {
		global $wpdb;

		$sql  = $this->get_base_query( 'DISTINCT posts.ID' );
		$sql .= ' AND ' . $this->get_optimization_exists_clause( true, true );

		if ( $exclude_wpml_dupes ) {
			$sql .= $this->get_wpml_exclusion_clause();
		}

		$sql .= $this->get_unoptimized_order_clause();
		$sql  = $this->append_pagination( $sql, $limit, $offset );

		$sql = $wpdb->prepare( $sql, $this->get_required_types() );

		$result = $wpdb->get_col( $sql );

		return array_map( 'absint', $result ?? [] );
	}

	/**
	 * Get IDs of images with optimization errors.
	 *
	 * @since 1.5.0
	 *
	 * @param int|null $limit  Number of results to return. NULL for no limit.
	 * @param int      $offset Number of results to skip.
	 *
	 * @return int[] Array of attachment IDs
	 */
	public function get_error_ids( $limit = null, $offset = 0 ) {
		global $wpdb;

		$sql  = $this->get_base_query( 'DISTINCT posts.ID' );
		$sql .= ' AND ' . $this->get_error_exists_clause();
		$sql .= ' ORDER BY posts.ID ASC';
		$sql  = $this->append_pagination( $sql, $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with safe methods, no user input.
		$result = $wpdb->get_col( $sql );

		return array_map( 'absint', $result ?? [] );
	}

	/**
	 * Count fully optimized images.
	 *
	 * @since 1.5.0
	 *
	 * @return int
	 */
	public function count_optimized() {
		return $this->get_cached_count(
			'count_optimized',
			function () {
				global $wpdb;

				$sql  = $this->get_base_query( 'COUNT(DISTINCT posts.ID)' );
				$sql .= ' AND ' . $this->get_optimization_exists_clause( false, true );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with safe methods and prepared with types.
				$sql = $wpdb->prepare( $sql, $this->get_required_types() );

				return (int) $wpdb->get_var( $sql );
			}
		);
	}

	/**
	 * Count unoptimized images.
	 *
	 * @since 1.5.0
	 *
	 * @param bool $exclude_wpml_dupes Whether to exclude WPML translation duplicates.
	 *
	 * @return int
	 */
	public function count_unoptimized( $exclude_wpml_dupes = true ) {
		$cache_suffix = $exclude_wpml_dupes ? '1' : '0';
		$cache_key    = "count_unoptimized_{$cache_suffix}";

		return $this->get_cached_count(
			$cache_key,
			function () use ( $exclude_wpml_dupes ) {
				global $wpdb;

				$sql  = $this->get_base_query( 'COUNT(DISTINCT posts.ID)' );
				$sql .= ' AND ' . $this->get_optimization_exists_clause( true, true );

				if ( $exclude_wpml_dupes ) {
					$sql .= $this->get_wpml_exclusion_clause();
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with safe methods and prepared with types.
				$sql = $wpdb->prepare( $sql, $this->get_required_types() );

				return (int) $wpdb->get_var( $sql );
			}
		);
	}

	/**
	 * Count images with optimization errors.
	 *
	 * @since 1.5.0
	 *
	 * @return int
	 */
	public function count_error() {
		return $this->get_cached_count(
			'count_error',
			function () {
				global $wpdb;

				$sql  = $this->get_base_query( 'COUNT(DISTINCT posts.ID)' );
				$sql .= ' AND ' . $this->get_error_exists_clause();

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with safe methods, no user input.
				return (int) $wpdb->get_var( $sql );
			}
		);
	}

	/**
	 * Count total attachment images with allowed formats.
	 *
	 * @since 1.5.0
	 *
	 * @param bool $exclude_wpml_dupes Whether to exclude WPML translation duplicates.
	 *
	 * @return int
	 */
	public function count_total_attachments( $exclude_wpml_dupes = true ) {
		$cache_suffix = $exclude_wpml_dupes ? '1' : '0';
		$cache_key    = "count_total_attachments_{$cache_suffix}";

		return $this->get_cached_count(
			$cache_key,
			function () use ( $exclude_wpml_dupes ) {
				global $wpdb;

				$sql = $this->get_base_query( 'COUNT(DISTINCT posts.ID)' );

				if ( $exclude_wpml_dupes ) {
					$sql .= $this->get_wpml_exclusion_clause();
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with safe methods, no user input.
				return (int) $wpdb->get_var( $sql );
			}
		);
	}

	/**
	 * Clear all query caches.
	 *
	 * Call after image optimization, restoration, or deletion.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function clear_cache() {
		$keys = [
			'count_optimized',
			'count_unoptimized_0',
			'count_unoptimized_1',
			'count_error',
			'count_total_attachments_0',
			'count_total_attachments_1',
		];

		foreach ( $keys as $key ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
		}
	}

	/**
	 * Refresh instance data after settings change.
	 *
	 * Use this if WebP/AVIF settings are changed mid-request.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function refresh() {
		$this->required_types      = null;
		$formats                   = wrio_get_allowed_formats( true );
		$this->allowed_formats_sql = is_array( $formats ) ? implode( ', ', $formats ) : $formats;
		self::clear_cache();
	}
}
