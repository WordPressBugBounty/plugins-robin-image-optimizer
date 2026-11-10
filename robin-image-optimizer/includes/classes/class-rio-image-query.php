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
 *
 * @phpstan-type ItemType 'attachment'|'webp'|'avif'
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
	 * @var list<ItemType>|null
	 */
	protected $required_types = null;

	/**
	 * Cache group for all query results
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wrio_image_query';

	/**
	 * Post meta with the size in bytes of the main file, for the "Largest first" order.
	 */
	const FILESIZE_META = '_wrio_filesize';

	/**
	 * Cron hook that stores FILESIZE_META for the images saved before it existed.
	 */
	const FILESIZE_BACKFILL_HOOK = 'wrio_filesize_backfill';

	/**
	 * Option with the last attachment ID of the backfill, or "done".
	 */
	const FILESIZE_BACKFILL_OPTION = 'wrio_filesize_backfill';

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
		add_action( 'wrio_storage_changed', [ __CLASS__, 'clear_cache' ], 100 );
		add_filter( 'wp_update_attachment_metadata', [ __CLASS__, 'store_filesize' ], 10, 2 );
		add_action( self::FILESIZE_BACKFILL_HOOK, [ __CLASS__, 'backfill_filesizes' ] );
	}

	/**
	 * Keep FILESIZE_META when WordPress saves the metadata of an attachment (upload, edit, regeneration).
	 *
	 * The serialized metadata cannot be sorted in SQL: its top-level "filesize" can come before or after the sizes,
	 * which have one too.
	 *
	 * @param mixed $data          Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 *
	 * @return mixed The metadata, unchanged.
	 */
	public static function store_filesize( $data, $attachment_id ) {
		if ( is_array( $data ) ) {
			$size = (int) ( $data['filesize'] ?? 0 );

			if ( $size <= 0 ) {
				$file = wrio_file_path( get_attached_file( (int) $attachment_id ) );
				$size = $file && is_file( $file ) ? (int) filesize( $file ) : 0;
			}

			update_post_meta( (int) $attachment_id, self::FILESIZE_META, $size );
		}

		return $data;
	}

	/**
	 * Start the backfill of FILESIZE_META, once.
	 *
	 * @return void
	 */
	public static function schedule_filesize_backfill() {
		if ( 'done' !== get_option( self::FILESIZE_BACKFILL_OPTION ) && ! wp_next_scheduled( self::FILESIZE_BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time(), self::FILESIZE_BACKFILL_HOOK );
		}
	}

	/**
	 * Run the backfill again, for example after an activation: images uploaded while the plugin was off have no
	 * FILESIZE_META.
	 *
	 * @return void
	 */
	public static function restart_filesize_backfill() {
		delete_option( self::FILESIZE_BACKFILL_OPTION );
	}

	/**
	 * Store FILESIZE_META for the next 500 image attachments without it, then come back until all have it.
	 *
	 * Every image, not only the formats allowed now: a format allowed later is sorted by size too.
	 *
	 * @return void
	 */
	public static function backfill_filesizes() {
		global $wpdb;

		$after = (int) get_option( self::FILESIZE_BACKFILL_OPTION, 0 );
		$ids   = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT 500", 'image/%', $after ) ) );

		foreach ( $ids as $id ) {
			if ( '' === get_post_meta( $id, self::FILESIZE_META, true ) ) {
				self::store_filesize( wp_get_attachment_metadata( $id ), $id );
			}
		}

		if ( count( $ids ) < 500 ) {
			update_option( self::FILESIZE_BACKFILL_OPTION, 'done', false );

			return;
		}

		update_option( self::FILESIZE_BACKFILL_OPTION, (int) end( $ids ), false );
		wp_schedule_single_event( time(), self::FILESIZE_BACKFILL_HOOK );
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
	 * @return non-empty-list<ItemType> Array of required item_types
	 */
	protected function build_required_types() {
		$types = [ 'attachment' ]; // Basic optimization always required

		if ( WRIO_Formats::is_webp_enabled() ) {
			$types[] = 'webp';
		}
		if ( WRIO_Formats::is_avif_enabled() ) {
			$types[] = 'avif';
		}

		return $types;
	}

	/**
	 * Get required conversion types.
	 * Lazy loads on first access.
	 *
	 * @since 1.5.0
	 *
	 * @return list<ItemType>
	 */
	public function get_required_types() {
		if ( null === $this->required_types ) {
			$this->required_types = $this->build_required_types();
		}

		return $this->required_types;
	}

	/**
	 * ORDER BY expression of the optimization order setting: old first (asc), new first (desc) or largest first.
	 *
	 * Largest first sorts by the size of the main file (FILESIZE_META). An image without it sorts last until
	 * backfill_filesizes() gets to it. The ID breaks ties.
	 *
	 * @return string SQL, without user input.
	 */
	public function get_order_by() {
		global $wpdb;

		$order = strtolower( (string) WRIO_Plugin::app()->getPopulateOption( 'image_optimization_order', 'asc' ) );

		if ( 'largest' === $order ) {
			self::schedule_filesize_backfill();

			$size = "( SELECT CAST( pm.meta_value AS UNSIGNED ) FROM {$wpdb->postmeta} pm WHERE pm.post_id = posts.ID AND pm.meta_key = '" . self::FILESIZE_META . "' LIMIT 1 )";

			return "{$size} DESC, posts.ID ASC";
		}

		return 'desc' === $order ? 'posts.ID DESC' : 'posts.ID ASC';
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
		// A waiting upload joins the counts when its wait ends, and nothing clears the cache then.
		if ( self::has_waiting_upload() ) {
			return (int) call_user_func( $callback );
		}

		$cache_key = self::cache_key( $cache_key );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) call_user_func( $callback );

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Post meta that marks an image that a rule outside SQL excludes: its value is the reason.
	 */
	const SKIP_META = '_wrio_skip';

	/**
	 * Build base attachment query with common conditions.
	 *
	 * @since 1.5.0
	 *
	 * @param string $select_clause The SELECT portion (e.g., 'DISTINCT posts.ID').
	 * @param bool   $convert       Only the formats that are converted to WebP and AVIF (no GIF).
	 *
	 * @return string
	 */
	protected function get_base_query( $select_clause, bool $convert = false ) {
		global $wpdb;

		$formats = $convert ? wrio_get_conversion_formats( true ) : $this->allowed_formats_sql;

		return "SELECT {$select_clause}
			FROM {$wpdb->posts} posts
			WHERE posts.post_type = 'attachment'
				AND posts.post_status = 'inherit'
				AND posts.post_mime_type IN ( {$formats} )" . $this->get_exclusion_clause();
	}

	/**
	 * Whether a client-side upload waits for its sizes now (see WRIO_Media_Library::waits_for_sizes()).
	 *
	 * @return bool
	 */
	private static function has_waiting_upload(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the check decides whether a cache can be used; the condition is prepared.
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id WHERE " . self::waiting_condition() . ' LIMIT 1' );
	}

	/**
	 * The SQL condition of a client-side upload that waits for its sizes, as WRIO_Media_Library::waits_for_sizes()
	 * It reads the aliases pm (postmeta) and posts.
	 *
	 * @return string
	 */
	private static function waiting_condition(): string {
		global $wpdb;

		return $wpdb->prepare( "pm.meta_key = %s AND pm.meta_value = 'waiting' AND posts.post_date_gmt > %s", WRIO_Media_Library::CLIENT_SIDE_UPLOAD_META, gmdate( 'Y-m-d H:i:s', time() - WRIO_Media_Library::CLIENT_SIDE_WAIT ) );
	}

	/**
	 * SQL to leave out the excluded images: the "Exclude from optimization" option of the image, or a file path
	 * that matches an "Exclude paths" pattern.
	 *
	 * A pattern matches a part of the file path relative to the uploads folder. "*" is any text, "?" is one
	 * character. For example "2019/*" or "logo".
	 *
	 * @return string
	 */
	public function get_exclusion_clause() {
		global $wpdb;

		$paths = '';
		foreach ( self::get_excluded_patterns() as $pattern ) {
			$like   = '%' . strtr( $wpdb->esc_like( $pattern ), '*?', '%_' ) . '%';
			$paths .= $wpdb->prepare( " OR ( pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s )", $like );
		}

		// A client-side upload that waits for its sizes.
		$waiting = ' OR ( ' . self::waiting_condition() . ' )';

		return " AND NOT EXISTS (
			SELECT 1
			FROM {$wpdb->postmeta} pm
			WHERE pm.post_id = posts.ID
				AND ( ( pm.meta_key = '_wrio_exclude' AND pm.meta_value = '1' ) OR pm.meta_key = '" . self::SKIP_META . "'{$paths}{$waiting} )
		)";
	}

	/**
	 * Why bulk and automatic optimization skip an image: '' when they do not.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string image, path or filter.
	 */
	public function exclusion_reason( $attachment_id ) {
		if ( '1' === (string) get_post_meta( $attachment_id, '_wrio_exclude', true ) ) {
			return 'image';
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		foreach ( self::get_excluded_patterns() as $pattern ) {
			// As the SQL LIKE of get_exclusion_clause(): case-insensitive, and only * and ? are special.
			$regex = '#' . strtr(
				preg_quote( $pattern, '#' ),
				[
					'\\*' => '.*',
					'\\?' => '.',
				]
			) . '#i';

			if ( preg_match( $regex, $file ) ) {
				return 'path';
			}
		}

		// Asked now, not read from the mark: the filter can have changed since a run marked the image.
		$reason = wrio_skip_reason( $attachment_id );

		if ( '' === $reason && '' !== (string) get_post_meta( $attachment_id, self::SKIP_META, true ) ) {
			delete_post_meta( $attachment_id, self::SKIP_META );
			self::clear_cache();
		}

		return $reason;
	}

	/**
	 * Forget every mark of the wrio_should_optimize filter, so the next selection asks it again.
	 *
	 * @return void
	 */
	public static function forget_skips() {
		delete_post_meta_by_key( self::SKIP_META );
		self::clear_cache();
	}

	/**
	 * The "Exclude paths" patterns.
	 *
	 * @return string[]
	 */
	public static function get_excluded_patterns() {
		$patterns = self::patterns_to_string( WRIO_Plugin::app()->getPopulateOption( 'excluded_paths', '' ) );

		return array_values( array_filter( array_map( 'trim', explode( "\n", $patterns ) ), fn( $pattern ) => '' !== $pattern ) );
	}

	/**
	 * The "Exclude paths" option as text.
	 *
	 * The option read turns a stored "true" or "false" into a boolean, and "1" or "0" into an integer.
	 *
	 * @param mixed $value The option value.
	 *
	 * @return string
	 */
	public static function patterns_to_string( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The image is excluded from the bulk and automatic optimization (see get_exclusion_clause()).
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	public function is_excluded( $attachment_id ) {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} posts WHERE posts.ID = %d", $attachment_id ) . $this->get_exclusion_clause();

		if ( null === $wpdb->get_var( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
			return true;
		}

		// The rules SQL cannot read (file size, the wrio_should_optimize filter): the mark takes the image out of the
		// counts and the next selections.
		$reason = wrio_skip_reason( $attachment_id );

		if ( '' === $reason ) {
			return false;
		}

		update_post_meta( $attachment_id, self::SKIP_META, $reason );
		self::clear_cache();

		return true;
	}

	/**
	 * Build optimization status EXISTS clause.
	 *
	 * A skipped item counts as done. For example, v2 skips a WebP that is not smaller than the image,
	 * and a bulk run must not convert that image again and again.
	 *
	 * @since 1.5.0
	 *
	 * @param bool            $negate    Use NOT EXISTS instead of EXISTS.
	 * @param bool            $all_types Check all required types are complete.
	 * @param ItemType[]|null $types   Item types to check. Null checks the required types.
	 *
	 * @return string SQL clause
	 */
	protected function get_optimization_exists_clause( $negate = false, $all_types = true, $types = null ) {
		$types     = $types ?? $this->get_required_types();
		$gif_types = array_values( array_diff( $types, [ 'webp', 'avif' ] ) );

		if ( $gif_types === $types ) {
			return $this->get_types_clause( $negate, $all_types, $types );
		}

		// GIF images are not converted (wrio_get_conversion_formats()): only their optimization counts.
		$convert = wrio_get_conversion_formats( true );
		$gif     = empty( $gif_types ) ? '1 = 0' : $this->get_types_clause( $negate, $all_types, $gif_types );

		return "( ( posts.post_mime_type IN ( {$convert} ) AND " . $this->get_types_clause( $negate, $all_types, $types ) . " ) OR ( posts.post_mime_type NOT IN ( {$convert} ) AND {$gif} ) )";
	}

	/**
	 * The optimization clause of item types, for all the images.
	 *
	 * @param bool       $negate    Use NOT EXISTS instead of EXISTS.
	 * @param bool       $all_types Check all types are complete.
	 * @param ItemType[] $types    Item types to check.
	 *
	 * @return string SQL clause
	 */
	protected function get_types_clause( bool $negate, bool $all_types, array $types ): string {
		global $wpdb;

		if ( \WRIO\Storage\Backfill::reads() ) {
			return $this->get_state_clause( $negate, $all_types, $types );
		}

		$db_table = RIO_Process_Queue::table_name();
		$exists   = $negate ? 'NOT EXISTS' : 'EXISTS';

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$clause = "{$exists} (
			SELECT 1
			FROM {$db_table} rio
			WHERE rio.object_id = posts.ID
				AND rio.item_type IN ( {$placeholders} )
				AND rio.result_status IN ( 'success', 'skip' )
				AND " . RIO_Process_Queue::not_nextgen_format_sql( 'rio' );

		if ( $all_types ) {
			$clause .= '
			GROUP BY rio.object_id
			HAVING COUNT(DISTINCT rio.item_type) = ' . count( $types );
		}

		$clause .= '
		)' . ( $negate ? $this->get_permanent_error_clause() : '' );

		// New attempts already have state rows while the old queue is still being copied.
		if ( $negate ) {
			$clause .= ' AND NOT ' . \WRIO\Storage\Queries::waiting( 'optimized' );
		}

		return $wpdb->prepare( $clause, $types ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
	}

	/**
	 * The optimization clause on the state rows (WRIO\Storage\Queries).
	 *
	 * Negated, it is "something to process now": the optimization is pending, or the optimization is done and
	 * a format is pending. An image that waits for a retry is not in it, so a bulk run can end.
	 *
	 * @param bool       $negate    Images with something to process, instead of the done images.
	 * @param bool       $all_types Every type must be done. False: one type is enough.
	 * @param ItemType[] $types    Item types: attachment, webp, avif.
	 *
	 * @return string
	 */
	protected function get_state_clause( $negate, $all_types, array $types ) {
		$queries = \WRIO\Storage\Queries::class;
		$formats = array_values( array_filter( $types, fn( $type ) => 'attachment' !== $type ) );

		if ( ! $negate ) {
			return '( ' . implode( $all_types ? ' AND ' : ' OR ', array_map( [ $queries, 'done' ], $types ) ) . ' )';
		}

		// A changed size (regenerated, edited, replaced) makes the item pending, also when its main file is done.
		$clause = in_array( 'attachment', $types, true ) ? '( ' . $queries::pending( 'optimized' ) . ' ) OR ' . $queries::changed() : '';

		if ( ! empty( $formats ) ) {
			$convert = $queries::done( 'optimized' ) . ' AND ( ' . implode( ' OR ', array_map( [ $this, 'get_format_pending_clause' ], $formats ) ) . ' )';
			$clause  = '' === $clause ? $convert : "( {$clause} ) OR ( {$convert} )";
		}

		// A thumbnail whose optimization failed makes the item pending when its retry is due, also when the main
		// file is done.
		if ( in_array( 'attachment', $types, true ) ) {
			$clause = "( {$clause} ) OR " . $queries::due( 'optimized' );
		}

		return "( {$clause} )";
	}

	/**
	 * Build error status EXISTS clause.
	 *
	 * @since 1.5.0
	 *
	 * @return string SQL clause
	 */
	protected function get_error_exists_clause() {
		if ( \WRIO\Storage\Backfill::reads() ) {
			return \WRIO\Storage\Queries::error();
		}

		$db_table = RIO_Process_Queue::table_name();

		return "EXISTS (
			SELECT 1
			FROM {$db_table} rio
			WHERE rio.object_id = posts.ID
				AND rio.result_status = 'error'
				AND " . RIO_Process_Queue::not_nextgen_format_sql( 'rio' ) . '
		)';
	}

	/**
	 * SQL to add to "not optimized": not an image with a permanent error.
	 *
	 * A damaged, unsupported or too large file keeps its error row through a reset. A bulk run must not send it
	 * again; the Optimize button of the media column still does. The %% are for a later prepare().
	 *
	 * @return string
	 */
	protected function get_permanent_error_clause() {
		$db_table = RIO_Process_Queue::table_name();

		return " AND NOT EXISTS (
			SELECT 1
			FROM {$db_table} rio
			WHERE rio.object_id = posts.ID
				AND rio.item_type = 'attachment'
				AND rio.result_status = 'error'
				AND rio.extra_data LIKE '%%\"permanent\":true%%'
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
		return ' ORDER BY CASE WHEN ' . $this->get_error_exists_clause() . ' THEN 1 ELSE 0 END ASC, ' . $this->get_order_by();
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
		$sql .= ' ORDER BY ' . $this->get_order_by();
		$sql  = $this->append_pagination( $sql, $limit, $offset );

		$result = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.

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
	 * @param int|null        $limit              Number of results to return. NULL for no limit.
	 * @param int             $offset             Number of results to skip.
	 * @param bool            $exclude_wpml_dupes Whether to exclude WPML translation duplicates.
	 * @param ItemType[]|null $types       Item types that must be complete. Null uses the required types,
	 *                                     [ 'attachment' ] finds the images that are not optimized yet.
	 * @param bool            $in_run      Only the images in the upload dates of the bulk run.
	 *
	 * @return int[] Array of attachment IDs
	 */
	public function get_unoptimized_ids( $limit = null, $offset = 0, $exclude_wpml_dupes = true, $types = null, bool $in_run = false ) {
		global $wpdb;

		$result = $wpdb->get_col( $this->get_unoptimized_ids_sql( 'DISTINCT posts.ID', $limit, $offset, $exclude_wpml_dupes, $types, $in_run ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.

		return array_map( 'absint', $result ?? [] );
	}

	/**
	 * The SQL of get_unoptimized_ids().
	 *
	 * @param string          $select             Select clause.
	 * @param int|null        $limit              Number of results to return. NULL for no limit.
	 * @param int             $offset             Number of results to skip.
	 * @param bool            $exclude_wpml_dupes Whether to exclude WPML translation duplicates.
	 * @param ItemType[]|null $types              Item types that must be complete. Null uses the required types.
	 * @param bool            $in_run             Only the images in the upload dates of the bulk run.
	 *
	 * @return string
	 */
	protected function get_unoptimized_ids_sql( string $select, $limit, $offset, $exclude_wpml_dupes, $types, bool $in_run = false ): string {
		$types = $types ?? $this->get_required_types();
		$sql   = $this->get_base_query( $select );
		$sql  .= ' AND ' . $this->get_optimization_exists_clause( true, true, $types );

		if ( $exclude_wpml_dupes ) {
			$sql .= $this->get_wpml_exclusion_clause();
		}

		if ( $in_run ) {
			$sql .= $this->get_run_dates_clause();
		}

		$sql .= $this->get_unoptimized_order_clause();

		return $this->append_pagination( $sql, $limit, $offset );
	}

	/**
	 * The next batch of a bulk step in one query: images that are not optimized come first, then optimized
	 * images that miss a format, in the order of $formats.
	 *
	 * @param int                $limit   Number of images.
	 * @param array<int, string> $formats Enabled formats: webp, avif.
	 *
	 * @return array{0: ItemType|'', 1: int[]} The work (attachment optimizes, a format converts) and its IDs, or '' and no IDs.
	 */
	public function get_next_batch( int $limit, array $formats ): array {
		global $wpdb;

		$works = [ 'attachment' ];
		$parts = [ '(' . $this->get_unoptimized_ids_sql( "DISTINCT posts.ID, 'attachment' AS wrio_work", $limit, 0, true, [ 'attachment' ], true ) . ')' ];

		foreach ( $formats as $format ) {
			if ( 'webp' !== $format && 'avif' !== $format ) {
				continue;
			}

			$works[] = $format;
			$parts[] = '(' . $this->get_unconverted_ids_sql( "DISTINCT posts.ID, '{$format}' AS wrio_work", $format, $limit, true ) . ')';
		}

		$rows = $wpdb->get_results( implode( ' UNION ALL ', $parts ), ARRAY_A ) ?? []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.

		foreach ( $works as $work ) {
			$ids = [];

			foreach ( $rows as $row ) {
				if ( $work === $row['wrio_work'] ) {
					$ids[] = absint( $row['ID'] );
				}
			}

			if ( ! empty( $ids ) ) {
				return [ $work, $ids ];
			}
		}

		return [ '', [] ];
	}

	/**
	 * Count the work of get_next_batch() that is left, with the same rules. Used only while upload dates limit the run:
	 * the counts of the statistic cover the whole library.
	 *
	 * @param array<int, string> $formats Enabled formats: webp, avif.
	 *
	 * @return int Images to optimize plus the formats to convert.
	 */
	public function count_run_work( array $formats ): int {
		global $wpdb;

		$parts = [ $this->get_unoptimized_ids_sql( 'DISTINCT posts.ID', null, 0, true, [ 'attachment' ], true ) ];

		foreach ( $formats as $format ) {
			if ( 'webp' !== $format && 'avif' !== $format ) {
				continue;
			}

			$parts[] = $this->get_unconverted_ids_sql( 'DISTINCT posts.ID', $format, null, true );
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ( (' . implode( ') UNION ALL (', $parts ) . ') ) AS wrio_work' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
	}

	/**
	 * Populate option with the upload dates that limit the bulk run: from and to, Y-m-d or ''.
	 */
	const RUN_DATES_OPTION = 'bulk_date_range';

	/**
	 * Limit the bulk run to the images uploaded from one date to another, both included. An empty date sets no limit
	 * on its side. A new Start sets the dates again.
	 *
	 * @param mixed $from First upload date, Y-m-d.
	 * @param mixed $to   Last upload date, Y-m-d.
	 *
	 * @return void
	 */
	public static function set_run_dates( $from, $to ): void {
		WRIO_Plugin::app()->updatePopulateOption( self::RUN_DATES_OPTION, self::valid_dates( $from, $to ) );
	}

	/**
	 * Upload dates as the bulk run reads them: a date that is not a valid Y-m-d is ''.
	 *
	 * @param mixed $from First upload date, Y-m-d.
	 * @param mixed $to   Last upload date, Y-m-d.
	 *
	 * @return array{from: string, to: string} Y-m-d or ''.
	 */
	public static function valid_dates( $from, $to ): array {
		$valid = static function ( $date ): string {
			$date = is_string( $date ) ? $date : '';

			return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $date : '';
		};

		return [
			'from' => $valid( $from ),
			'to'   => $valid( $to ),
		];
	}

	/**
	 * The upload dates of the bulk run, for the bulk page: Resume after a reload must send them again.
	 *
	 * @return array{from: string, to: string} Y-m-d or ''.
	 */
	public static function get_run_dates(): array {
		$dates = WRIO_Plugin::app()->getPopulateOption( self::RUN_DATES_OPTION, [] );
		$dates = is_array( $dates ) ? $dates : [];

		return [
			'from' => isset( $dates['from'] ) && is_string( $dates['from'] ) ? $dates['from'] : '',
			'to'   => isset( $dates['to'] ) && is_string( $dates['to'] ) ? $dates['to'] : '',
		];
	}

	/**
	 * Whether upload dates limit the bulk run.
	 *
	 * @return bool
	 */
	public function has_run_dates(): bool {
		return '' !== $this->get_run_dates_clause();
	}

	/**
	 * SQL that keeps the images in the upload dates of the bulk run, or ''.
	 *
	 * @param array{from: string, to: string}|null $dates Upload dates from valid_dates(). Null: the dates of the run.
	 *
	 * @return string
	 */
	protected function get_run_dates_clause( ?array $dates = null ): string {
		global $wpdb;

		$dates = $dates ?? self::get_run_dates();
		$sql   = '';

		if ( '' !== $dates['from'] ) {
			$sql .= $wpdb->prepare( ' AND posts.post_date >= %s', $dates['from'] . ' 00:00:00' );
		}

		if ( '' !== $dates['to'] ) {
			$sql .= $wpdb->prepare( ' AND posts.post_date <= %s', $dates['to'] . ' 23:59:59' );
		}

		return $sql;
	}

	/**
	 * The main file of each image without a result or failure on the state rows, in one query.
	 *
	 * @return array<int, string> Attachment ID => its _wp_attached_file meta, '' without one.
	 */
	public function get_untouched_files(): array {
		global $wpdb;

		// ponytail: about 600 bytes per untouched image while the query result is held. Page it again if a library of
		// millions of new images needs it: a page per query was 21 queries for 10,000 images.
		$select = "posts.ID, ( SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = posts.ID AND pm.meta_key = '_wp_attached_file' LIMIT 1 ) AS file";
		$sql    = $this->get_base_query( $select ) . ' AND ' . \WRIO\Storage\Queries::untouched( 'attachment', 'posts.ID' );
		$files  = [];

		foreach ( $wpdb->get_results( $sql, ARRAY_A ) ?? [] as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
			$files[ absint( $row['ID'] ) ] = (string) $row['file'];
		}

		return $files;
	}

	/**
	 * Get IDs of the images of the allowed formats, without WPML copies, in ascending ID order.
	 *
	 * @param int                                  $after_id Only IDs greater than this one.
	 * @param int                                  $limit    Number of results to return.
	 * @param array{from: string, to: string}|null $dates    Only images uploaded in these dates, from valid_dates(). Null: all.
	 *
	 * @return int[] Array of attachment IDs
	 */
	public function get_ids_after( int $after_id, int $limit, ?array $dates = null ): array {
		global $wpdb;

		// The date clause is prepared already: its "%" must not reach the prepare() below.
		$in_dates = null === $dates ? '' : str_replace( '%', '%%', $this->get_run_dates_clause( $dates ) );
		$sql      = $this->get_base_query( 'posts.ID' ) . ' AND posts.ID > %d' . $this->get_wpml_exclusion_clause() . $in_dates . ' ORDER BY posts.ID ASC LIMIT %d';

		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $after_id, $limit ) ) ?? [] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared here.
	}

	/**
	 * Get IDs of optimized images that have no row of a format yet.
	 *
	 * A row of any status counts: an image whose conversion failed is not picked again in the same run.
	 *
	 * @param 'webp'|'avif' $format webp or avif.
	 * @param int|null      $limit  Number of results to return. NULL for no limit.
	 *
	 * @return int[] Array of attachment IDs
	 */
	public function get_unconverted_ids( $format, $limit = null ) {
		global $wpdb;

		return array_map( 'absint', $wpdb->get_col( $this->get_unconverted_ids_sql( 'DISTINCT posts.ID', $format, $limit ) ) ?? [] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
	}

	/**
	 * The SQL of get_unconverted_ids().
	 *
	 * @param string        $select Select clause.
	 * @param 'webp'|'avif' $format webp or avif.
	 * @param int|null      $limit  Number of results to return. NULL for no limit.
	 * @param bool          $in_run Only the images in the upload dates of the bulk run.
	 *
	 * @return string
	 */
	protected function get_unconverted_ids_sql( string $select, $format, $limit, bool $in_run = false ): string {
		global $wpdb;

		$dates = $in_run ? $this->get_run_dates_clause() : '';

		if ( \WRIO\Storage\Backfill::reads() ) {
			$sql = $this->get_base_query( $select, true ) . ' AND ' . $this->get_unconverted_clause( $format ) . $dates . ' ORDER BY ' . $this->get_order_by();

			return $this->append_pagination( $sql, $limit, 0 );
		}

		$db_table = RIO_Process_Queue::table_name();
		$sql      = $this->get_base_query( $select, true ) . "
				AND EXISTS ( SELECT 1 FROM {$db_table} rio WHERE rio.object_id = posts.ID AND rio.item_type = 'attachment' AND rio.result_status = 'success' )
				AND NOT EXISTS ( SELECT 1 FROM {$db_table} rio WHERE rio.object_id = posts.ID AND rio.item_type = %s AND " . RIO_Process_Queue::not_nextgen_format_sql( 'rio' ) . ' )' . str_replace( '%', '%%', $dates ) . '
			ORDER BY ' . $this->get_order_by();

		return (string) $wpdb->prepare( $this->append_pagination( $sql, $limit, 0 ), $format ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared here.
	}

	/**
	 * State clause of an optimized image with a format to convert now.
	 *
	 * @param string $format webp or avif. Another value counts webp.
	 *
	 * @return string
	 */
	protected function get_unconverted_clause( $format ) {
		$format = 'avif' === $format ? 'avif' : 'webp';

		return \WRIO\Storage\Queries::done( 'optimized' ) . ' AND ' . $this->get_format_pending_clause( $format );
	}

	/**
	 * State clause of a format to convert now: the main file's conversion is pending, or the conversion of a
	 * file failed and its retry is due.
	 *
	 * @param 'webp'|'avif' $format webp or avif.
	 *
	 * @return string
	 */
	public function get_format_pending_clause( $format ) {
		return '( ( ' . \WRIO\Storage\Queries::pending( $format ) . ' ) OR ' . \WRIO\Storage\Queries::due( $format ) . ' )';
	}

	/**
	 * Count optimized images with a format to convert now, on the state rows.
	 *
	 * @param string $format webp or avif. Another value counts webp.
	 *
	 * @return int
	 */
	public function count_unconverted( $format ) {
		global $wpdb;

		return (int) $wpdb->get_var( $this->get_base_query( 'COUNT(DISTINCT posts.ID)', true ) . ' AND ' . $this->get_unconverted_clause( $format ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
	}

	/**
	 * Count images to compress: the optimization of a file is not done, and no file of the image failed. A failed
	 * image counts as an error. An optimized image that only misses a format counts in count_needs_conversion().
	 *
	 * @return int
	 */
	public function count_uncompressed() {
		global $wpdb;

		$queries = \WRIO\Storage\Queries::class;
		// Only the optimized rows: a pending WebP or AVIF row is a conversion, not a new compression.
		$clause = \WRIO\Storage\Backfill::reads() ? '( NOT ' . $queries::done( 'optimized' ) . ' OR ' . $queries::changed( 'attachment', 'posts.ID', 'optimized' ) . ' )' : $this->get_optimization_exists_clause( true, true, [ 'attachment' ] );

		return (int) $wpdb->get_var( $this->get_base_query( 'COUNT(DISTINCT posts.ID)' ) . " AND {$clause} AND NOT " . $this->get_error_exists_clause() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the clauses are prepared.
	}

	/**
	 * Count compressed images: the optimization of every file is done, and no file of the image failed.
	 *
	 * @return int
	 */
	public function count_compressed() {
		global $wpdb;

		$queries = \WRIO\Storage\Queries::class;
		$clause  = \WRIO\Storage\Backfill::reads() ? $queries::done( 'optimized' ) . ' AND NOT ' . $queries::changed( 'attachment', 'posts.ID', 'optimized' ) : $this->get_optimization_exists_clause( false, true, [ 'attachment' ] );

		return (int) $wpdb->get_var( $this->get_base_query( 'COUNT(DISTINCT posts.ID)' ) . " AND {$clause} AND NOT " . $this->get_error_exists_clause() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the clauses are prepared.
	}

	/**
	 * Count optimized images with a format to convert now and no failed file, on the state rows. A failed image
	 * counts as an error only.
	 *
	 * @param 'webp'|'avif' $format webp or avif.
	 *
	 * @return int
	 */
	public function count_needs_conversion( $format ) {
		global $wpdb;

		return (int) $wpdb->get_var( $this->get_base_query( 'COUNT(DISTINCT posts.ID)', true ) . ' AND ' . $this->get_unconverted_clause( $format ) . ' AND NOT ' . $this->get_error_exists_clause() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
	}

	/**
	 * Count images with a failure that a new bulk run tries again, on the state rows.
	 *
	 * @return int
	 */
	public function count_retryable_error() {
		global $wpdb;

		return (int) $wpdb->get_var( $this->get_base_query( 'COUNT(DISTINCT posts.ID)' ) . ' AND ' . \WRIO\Storage\Queries::retryable() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
	}

	/**
	 * Count images whose main file has a done format, on the state rows.
	 *
	 * @param 'webp'|'avif' $format    webp or avif.
	 * @param bool          $made_only Count only the images whose file was made, not the skipped ones.
	 *
	 * @return int
	 */
	public function count_converted( $format, $made_only = false ) {
		global $wpdb;

		$output = 'avif' === $format ? 'avif' : 'webp';
		$clause = $made_only ? \WRIO\Storage\Queries::succeeded( $output ) : \WRIO\Storage\Queries::done( $output );

		return (int) $wpdb->get_var( $this->get_base_query( 'COUNT(DISTINCT posts.ID)', true ) . ' AND ' . $clause ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
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

				return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
			}
		);
	}

	/**
	 * Count unoptimized images.
	 *
	 * @since 1.5.0
	 *
	 * @param bool            $exclude_wpml_dupes Whether to exclude WPML translation duplicates.
	 * @param ItemType[]|null $types            Item types that must be complete. Null uses the required types.
	 *
	 * @return int
	 */
	public function count_unoptimized( $exclude_wpml_dupes = true, $types = null ) {
		$cache_suffix = $exclude_wpml_dupes ? '1' : '0';
		$cache_key    = null === $types ? "count_unoptimized_{$cache_suffix}" : "count_unoptimized_{$cache_suffix}_" . implode( '_', $types );
		$count        = function () use ( $exclude_wpml_dupes, $types ) {
			global $wpdb;

			$sql  = $this->get_base_query( 'COUNT(DISTINCT posts.ID)' );
			$sql .= ' AND ' . $this->get_optimization_exists_clause( true, true, $types );

			if ( $exclude_wpml_dupes ) {
				$sql .= $this->get_wpml_exclusion_clause();
			}

			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clauses are prepared.
		};

		// The state rows change without a write when a retry time passes: a cached count would hide due retries.
		if ( \WRIO\Storage\Backfill::reads() ) {
			return (int) $count();
		}

		return $this->get_cached_count( $cache_key, $count );
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
			'count_unoptimized_0_attachment',
			'count_unoptimized_1_attachment',
			'count_error',
			'count_total_attachments_0',
			'count_total_attachments_1',
		];

		foreach ( $keys as $key ) {
			wp_cache_delete( self::cache_key( $key ), self::CACHE_GROUP );
		}
	}

	/**
	 * The cache key of a count, for the current "Exclude paths" patterns.
	 *
	 * The patterns can be a network option: a change on one site makes the counts of every site new keys.
	 *
	 * @param string $key Count name.
	 *
	 * @return string
	 */
	protected static function cache_key( $key ) {
		return $key . '_' . substr( md5( implode( "\n", self::get_excluded_patterns() ) ), 0, 8 );
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
