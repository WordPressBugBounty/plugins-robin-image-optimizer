<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RIO_Queue_Model is a database communication model for "prefix_rio_process_queue" table.
 *
 * @property int|null                 $id                     Primary key.
 * @property null|string              $server_id              Server id which was used for processing.
 * @property null|int                 $object_id              Object id. Usually, this would be foreign key to another table.
 * @property null|string              $object_name            Object name. Usually, this can be table name where to take object id.
 * @property null|string              $item_type              Type of item. E.g. attachment, webp, etc.
 * @property null|string              $item_hash              Unique item hash. Serves purpose of search.
 * @property null|string              $item_hash_alternative  Unique alternative hash. Serves purpose of search.
 * @property null|string              $result_status          Status result.
 * @property null|string              $processing_level
 * @property null|bool                $is_backed_up           Whether item is backed-up or not. = false;
 * @property null|int                 $original_size          Original file size in bytes.
 * @property null|int                 $final_size             Final file size in bytes after it was optimized or converted.
 * @property null|string              $original_mime_type     Original MIME TYPE. e.g. image/jpeg.
 * @property null|string              $final_mime_type        Final mime type. e.g. image/webp.
 * @property null|RIO_Base_Extra_Data $extra_data             Extra data to be saved. e.g. JSON response from API.
 * @property null|int                 $created_at             UNIX timestamp when item was saved.
 *
 * @phpstan-type QueueStatus 'success'|'error'|'skip'|'processing'|'unoptimized'
 * @phpstan-type QueueItemType 'attachment'|'webp'|'avif'|'cf_image'|'nextgen'
 * @phpstan-type QueueLevel 'normal'|'aggresive'|'ultra'|'custom'
 */
class RIO_Process_Queue extends RIO_Base_Active_Record {

	/**
	 * When new status is added, it should be added accordingly to get_statuses() method.
	 *
	 * @see get_statuses() for further information.
	 */
	const STATUS_SUCCESS     = 'success';         // On success to convert or optimize.
	const STATUS_ERROR       = 'error';             // On failure to convert or optimize.
	const STATUS_SKIP        = 'skip';               // Skip.
	const STATUS_PROCESSING  = 'processing';   // When conversion or optimization is in progress.
	const STATUS_UNOPTIMIZED = 'unoptimized'; // Когда картинка не оптимизирована

	/**
	 * When new level is added, it should be added accordingly to get_levels() method.
	 *
	 * @see get_levels() for further information.
	 */
	const LEVEL_NORMAL    = 'normal';
	const LEVEL_AGGRESIVE = 'aggresive'; // todo: need to fix typo in word aggressive
	const LEVEL_ULTRA     = 'ultra';
	const LEVEL_CUSTOM    = 'custom';

	/**
	 * @var null|int Primary key.
	 */
	protected $id = null;

	/**
	 * @var null|string Server id which was used for processing.
	 */
	protected $server_id = null;

	/**
	 * Object id. Usually, this would be foreign key to another table.
	 *
	 * @var int|numeric-string|null
	 */
	protected $object_id = null;

	/**
	 * @var null|string Object name. Usually, this can be table name where to take object id.
	 */
	protected $object_name = null;

	/**
	 * Type of item: attachment, webp, avif, cf_image or nextgen. A loaded row holds the column.
	 *
	 * @var string|null
	 */
	protected $item_type = null;

	/**
	 * @var null|string Unique item hash. Serves purpose of search.
	 */
	protected $item_hash = null;

	/**
	 * @var null|string Unique alternative hash. Serves purpose of search.
	 */
	protected $item_hash_alternative = null;

	/**
	 * Status result: one of the STATUS_* values. A loaded row holds the column.
	 *
	 * @var string|null
	 */
	protected $result_status = null;

	/**
	 * A level, or a quality from 1 to 100 for the custom level.
	 *
	 * @var string|int|null
	 */
	protected $processing_level = null;

	/**
	 * Whether item is backed-up or not. A loaded row holds the column: '0' or '1'.
	 *
	 * @var bool|'0'|'1'|null
	 */
	protected $is_backed_up = false;

	/**
	 * Original file size in bytes.
	 *
	 * @var int|numeric-string|null
	 */
	protected $original_size = null;

	/**
	 * Final file size in bytes after it was optimized or converted.
	 *
	 * @var int|numeric-string|null
	 */
	protected $final_size = null;

	/**
	 * @var null|string Original MIME TYPE. e.g. image/jpeg.
	 */
	protected $original_mime_type = null;

	/**
	 * @var null|string Final mime type. e.g. image/webp.
	 */
	protected $final_mime_type = null;

	/**
	 * @var null|RIO_Base_Extra_Data Extra data to be saved. e.g. JSON response from API.
	 */
	protected $extra_data = null;

	/**
	 * UNIX timestamp when item was saved.
	 *
	 * @var int|numeric-string|null
	 */
	protected $created_at = null;

	/**
	 * @var RIO_Base_Extra_Data Keeps instance of extra data to leave state of $extra_data untouched.
	 */
	private static $_extra_data;

	/**
	 * Rows preloaded by prime().
	 *
	 * @var array<string, object|null> Keyed by "object_id:item_type" and consumed on first load().
	 */
	protected static $primed_rows = [];

	/**
	 * {@inheritdoc}
	 */
	public function init() {
		parent::init();

		// Support both webp and avif with unified extra data model
		if ( in_array( $this->item_type, [ 'webp', 'avif' ] ) ) {
			static::$_extra_data = new RIOP_WebP_Extra_Data(); // Works for both formats
		} else {
			static::$_extra_data = new RIO_Base_Extra_Data();
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'rio_process_queue';
	}

	/**
	 * It is used to check the migration and create a schema in the database.
	 * Gets the level of activity performed in the database.
	 *
	 * @return int
	 * @since  1.3.8
	 */
	public static function get_db_version() {
		$name    = WRIO_Plugin::app()->getOptionName( 'db_version' );
		$version = get_option( $name, false );

		if ( false === $version && is_multisite() && is_main_site() ) {
			$version = (int) get_site_option( $name, 0 );

			if ( $version ) {
				update_option( $name, $version );
			}
		}

		return (int) $version;
	}

	/**
	 * It is used to check the migration and create a schema in the database.
	 * Updates the level of activity performed in the database.
	 *
	 * @return void
	 * @since  1.3.8
	 */
	public static function update_db_version( $version ) {
		update_option( WRIO_Plugin::app()->getOptionName( 'db_version' ), (int) $version );
	}

	/**
	 * Find db item by hash.
	 *
	 * @param string $hash Hash to search for.
	 *
	 * @return null|$this
	 */
	public static function find_by_hash( $hash ) {
		global $wpdb;

		if ( empty( $hash ) ) {
			return null;
		}

		$table_name = static::table_name();
		$sql        = "SELECT * FROM {$table_name} WHERE `item_hash` = %s";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Plugin table name, the hash is a placeholder.

		if ( empty( $row ) ) {
			return null;
		}

		return new RIO_Process_Queue( $row );
	}

	/**
	 * Find all by specified condition.
	 *
	 * @param array  $condition Key => value condition to be used to search.
	 * @param string $order
	 * @param string $limit
	 *
	 * @return null|self[]
	 */
	public static function find_all( array $condition ) {

		global $wpdb;

		$table = static::table_name();

		$where  = [];
		$values = [];

		foreach ( $condition as $key => $value ) {
			if ( is_numeric( $value ) || is_string( $value ) ) {
				$where[]  = '`' . esc_sql( $key ) . '` = %s';
				$values[] = $value;
			}
		}

		$sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Plugin table name and escaped column names, values are placeholders.

		if ( empty( $rows ) ) {
			return null;
		}

		foreach ( $rows as $key => $row ) {
			$rows[ $key ] = new RIO_Process_Queue( $row );
		}

		return $rows;
	}

	/**
	 * Get count var by specified type and status.
	 *
	 * Notice: when type is undefined, it would return false.
	 *
	 * @param QueueItemType $type   Type of file.
	 * @param string        $status Status conversion or optimization.
	 *
	 * @return false|numeric-string|null False for an unknown status. The number of WebP images, or else the
	 *                                    number of rows.
	 */
	public static function count_by_type_status( $type, $status ) {

		if ( ! in_array( $status, static::get_statuses(), true ) ) {
			return false;
		}

		global $wpdb;

		$table_name = static::table_name();
		// WebP has one row per file: count the images in SQL, not their IDs in PHP.
		$count = 'webp' === $type ? 'COUNT(DISTINCT object_id)' : 'COUNT(*)';
		$sql   = "SELECT {$count} FROM {$table_name} WHERE `item_type` = %s AND `result_status` = %s";

		return $wpdb->get_var( $wpdb->prepare( $sql, [ $type, $status ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- plugin table and a fixed COUNT, prepared here.
	}

	/**
	 * SQL condition that leaves out the format rows of NextGEN images.
	 *
	 * Earlier versions stored the NextGEN image ID as their object_id, so they match an attachment with that ID.
	 *
	 * @param string $alias Table alias, or empty.
	 *
	 * @return non-empty-string
	 */
	public static function not_nextgen_format_sql( string $alias = '' ): string {
		$column = '' === $alias ? 'extra_data' : $alias . '.extra_data';

		return "IFNULL( LOCATE( '\"convert_from\":\"nextgen\"', {$column} ), 0 ) = 0";
	}

	/**
	 * Total original and final sizes of the successful rows of an item type.
	 *
	 * The statistics read these sums, so they always match the rows.
	 *
	 * @param QueueItemType                        $item_type    attachment, cf_image, nextgen, webp or avif.
	 * @param ''|'attachment'|'cf_image'|'nextgen' $convert_from For webp and avif: count only the rows of this item type. Empty counts all.
	 *
	 * @return array{original_size: int, final_size: int}
	 */
	public static function sum_sizes( $item_type, $convert_from = '' ) {
		global $wpdb;

		$table = static::table_name();
		$sql   = $wpdb->prepare( "SELECT COALESCE( SUM( original_size ), 0 ) AS original_size, COALESCE( SUM( final_size ), 0 ) AS final_size FROM {$table} WHERE item_type = %s AND result_status = %s", $item_type, self::STATUS_SUCCESS ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' !== $convert_from ) {
			$sql .= $wpdb->prepare( ' AND extra_data LIKE %s', '%' . $wpdb->esc_like( '"convert_from":"' . $convert_from . '"' ) . '%' );
		}

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

		return [
			'original_size' => (int) ( $row['original_size'] ?? 0 ),
			'final_size'    => (int) ( $row['final_size'] ?? 0 ),
		];
	}

	/**
	 * Make the error rows of an item type ready for a new bulk run.
	 *
	 * Rows with a permanent error stay: the same file always fails again (corrupt, too large, unsupported).
	 * A manual optimization of the image still sends it.
	 *
	 * @param QueueItemType    $item_type  attachment, cf_image, nextgen, webp or avif.
	 * @param QueueStatus|null $new_status Status to set, or null to delete the rows.
	 *
	 * @return void
	 */
	public static function reset_errors( $item_type, $new_status = null ) {
		global $wpdb;

		$table = static::table_name();
		$where = $wpdb->prepare(
			'item_type = %s AND result_status = %s AND ( extra_data IS NULL OR extra_data NOT LIKE %s )',
			$item_type,
			self::STATUS_ERROR,
			'%' . $wpdb->esc_like( '"permanent":true' ) . '%'
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above, $table is the plugin table.
		if ( null === $new_status ) {
			$wpdb->query( "DELETE FROM {$table} WHERE {$where}" );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET result_status = %s WHERE {$where}", $new_status ) );
		}
		// phpcs:enable
	}

	/**
	 * Make the attachments with a retryable error of a format ready to convert again.
	 *
	 * Each file has its own format row. The conversion query picks an attachment without any row of the format, so
	 * the success rows of its other sizes go too: with only them left, the failed size would never convert again.
	 * An attachment with a permanent format error keeps its rows.
	 *
	 * @param 'webp'|'avif' $item_type webp or avif.
	 *
	 * @return void
	 */
	public static function reset_format_errors( $item_type ) {
		global $wpdb;

		$table = static::table_name();

		// Old NextGEN rows carry the NextGEN image ID: they are not the attachment with that ID.
		$not_nextgen = static::not_nextgen_format_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is the plugin table and a fixed condition.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$table} WHERE item_type = %s AND result_status = %s AND object_id IS NOT NULL AND {$not_nextgen}", $item_type, self::STATUS_ERROR ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is the plugin table and a fixed condition.
		$kept = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$table} WHERE item_type = %s AND result_status = %s AND extra_data LIKE %s AND {$not_nextgen}", $item_type, self::STATUS_ERROR, '%' . $wpdb->esc_like( '"permanent":true' ) . '%' ) );
		$ids  = array_map( 'intval', array_diff( $ids, $kept ) );

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer IDs and a fixed condition.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE item_type = %s AND object_id IN (" . implode( ',', $chunk ) . ") AND {$not_nextgen}", $item_type ) );
		}

		// Rows without an item ID (custom folders, NextGEN) are one file each.
		static::reset_errors( $item_type );
	}

	/**
	 * Get available types.
	 *
	 * @return list<QueueStatus>
	 */
	public static function get_statuses() {
		return [
			self::STATUS_SUCCESS,
			self::STATUS_ERROR,
			self::STATUS_PROCESSING,
			self::STATUS_UNOPTIMIZED,
			self::STATUS_SKIP,
		];
	}

	/**
	 * Get available levels.
	 *
	 * @return list<QueueLevel>
	 */
	public static function get_levels() {
		return [ self::LEVEL_NORMAL, self::LEVEL_AGGRESIVE, self::LEVEL_CUSTOM, self::LEVEL_ULTRA ];
	}

	/**
	 * Preloads queue rows for many objects in a single query.
	 *
	 * @param array<int|string> $object_ids Object ids to preload.
	 * @param array<int|string> $item_types Item types to preload.
	 *
	 * @return void
	 */
	public static function prime( array $object_ids, array $item_types ) {
		global $wpdb;

		$object_ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		$item_types = array_values( array_unique( array_filter( array_map( 'strval', $item_types ) ) ) );

		if ( empty( $object_ids ) || empty( $item_types ) ) {
			return;
		}

		foreach ( $object_ids as $object_id ) {
			foreach ( $item_types as $item_type ) {
				static::$primed_rows[ static::primed_row_key( $object_id, $item_type ) ] = null;
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rio_process_queue WHERE object_id IN (" . implode( ',', array_fill( 0, count( $object_ids ), '%d' ) ) . ') AND item_type IN (' . implode( ',', array_fill( 0, count( $item_types ), '%s' ) ) . ') AND ' . static::not_nextgen_format_sql() . ';', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed condition.
				array_merge( $object_ids, $item_types )
			)
		);

		$rows = (array) $rows;
		usort( $rows, [ static::class, 'main_file_first' ] );

		foreach ( $rows as $row ) {
			$key = static::primed_row_key( $row->object_id, $row->item_type );

			if ( empty( static::$primed_rows[ $key ] ) ) {
				static::$primed_rows[ $key ] = $row;
			}
		}
	}

	/**
	 * Order of the rows of prime(): the WebP or AVIF row of an item's main file first, then the oldest row. An item
	 * has one row per file, and a read of "the" row of a format must not get a thumbnail's row.
	 *
	 * @param \stdClass $a A row.
	 * @param \stdClass $b A row.
	 *
	 * @return int
	 */
	protected static function main_file_first( $a, $b ) {
		$main  = '"converted_from_size":"original"';
		$order = function ( $row ) use ( $main ) {
			return [ false === strpos( (string) ( $row->extra_data ?? '' ), $main ), (int) $row->id ];
		};

		return $order( $a ) <=> $order( $b );
	}

	/**
	 * Builds the $primed_rows key for an object/type pair.
	 *
	 * Returns an empty string for anything that is not a scalar — object_id and item_type
	 * are not always set to one on the write paths — so the caller falls back to the database.
	 *
	 * @param mixed $object_id Object id.
	 * @param mixed $item_type Item type.
	 *
	 * @return string
	 */
	protected static function primed_row_key( $object_id, $item_type ) {
		if ( ! is_scalar( $object_id ) || ! is_scalar( $item_type ) ) {
			return '';
		}

		return $object_id . ':' . $item_type;
	}

	/**
	 * Drops the preloaded row for one object/type pair, so a write is never shadowed by it.
	 *
	 * @param mixed $object_id Object id the write touched.
	 * @param mixed $item_type Item type the write touched.
	 *
	 * @return void
	 */
	protected static function invalidate_primed_row( $object_id, $item_type ) {
		$key = static::primed_row_key( $object_id, $item_type );

		if ( '' === $key ) {
			return;
		}

		unset( static::$primed_rows[ $key ] );
	}

	/**
	 * Drops every row left over from prime().
	 *
	 * @return void
	 */
	public static function flush_primed_rows() {
		static::$primed_rows = [];
	}

	/**
	 * Обновление свойств объекта из базы данных
	 *
	 * @return void
	 */
	public function load() {
		global $wpdb;

		$primed_key = static::primed_row_key( $this->object_id, $this->item_type );

		if ( '' !== $primed_key ) {
			// prime() orders the rows of an item: the WebP or AVIF row of the main file, not of a thumbnail.
			if ( ! array_key_exists( $primed_key, static::$primed_rows ) ) {
				static::prime( [ (int) $this->object_id ], [ (string) $this->item_type ] );
			}

			$row = static::$primed_rows[ $primed_key ] ?? null;
			unset( static::$primed_rows[ $primed_key ] );

			if ( ! empty( $row ) ) {
				$this->configure( (array) $row );
			}

			return;
		}

		$table_name = static::table_name();
		$sql        = $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE object_id = %d AND item_type = %s AND " . static::not_nextgen_format_sql() . ' LIMIT 1;', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- plugin table and a fixed condition.
			[
				$this->object_id,
				$this->item_type,
			]
		);

		$row = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( ! empty( $row ) ) {
			$this->configure( $row );
		}
	}

	/**
	 * Проверяет, оптимизированно ли изображение
	 *
	 * @return bool
	 */
	public function is_optimized() {
		if ( $this->result_status == self::STATUS_SUCCESS ) {
			return true;
		}

		return false;
	}

	/**
	 * Проверяет, пропущено ли изображение
	 * Если изображение пропущено, то оно больше не участвует в оптимизации
	 *
	 * @return bool
	 */
	public function is_skipped() {
		if ( $this->result_status == self::STATUS_SKIP ) {
			return true;
		}

		return false;
	}

	/**
	 * Подготовка данных для сохранения в базе
	 *
	 * @return array свойства модели в виде массива. Подготовлены для сохранения в базе данных
	 */
	public function prepare_data_to_save() {
		if ( ! $this->created_at ) {
			$this->created_at = time();
		}

		if ( $this->extra_data instanceof RIO_Base_Extra_Data ) {
			$this->extra_data = (string) $this->extra_data;
		}

		$data = (array) $this;

		// @todo: remove later to usage of private attributes inside model
		foreach ( $data as $key => $value ) {
			$clean_key          = trim( $key, " \t\n\r\0\x0B*" );
			$data[ $clean_key ] = $value;
			unset( $data[ $key ] );
		}

		unset( $data['id'] );

		return $data;
	}

	/**
	 * Save item.
	 *
	 * @return bool
	 */
	public function save() {
		global $wpdb;
		$table_name = static::table_name();
		$data       = $this->prepare_data_to_save();

		static::invalidate_primed_row( $this->object_id, $this->item_type );

		$is_success = false;

		// если установлен id - значит данные уже есть в базе
		if ( $this->id ) {

			// если данные есть в базе, то обновляем их
			$result = $wpdb->update( $table_name, $data, [ 'id' => $this->id ] );

			WRIO_Plugin::app()->logger->debug( sprintf( 'Updated queue item #%s, attributes values: %s', $this->id, wp_json_encode( $data ) ) );

			$is_success = ( false !== $result );
		} elseif ( ! self::find_by_hash( $this->get_item_hash() ) ) {
				// если данных нет в базе, то вставляем новую запись в таблицу
				$count = $wpdb->insert( $table_name, $data );

			if ( $count !== false && $count > 0 ) {
				$lastId = $wpdb->insert_id;

				if ( ! empty( $lastId ) ) {
					$this->id = $lastId;

					$is_success = true;

					WRIO_Plugin::app()->logger->debug( sprintf( 'New queue created #%s, attributes values: %s', $this->id, wp_json_encode( $data ) ) );
				}
			}
		}

		// The database error tells support why a converted file has no row.
		if ( ! $is_success && '' !== $wpdb->last_error ) {
			WRIO_Plugin::app()->logger->error( sprintf( 'Failed to save the %s row of item #%s in %s: %s', $this->item_type, $this->object_id, $table_name, $wpdb->last_error ) );
		}

		/**
		 * Filter whether to execute hook or not.
		 *
		 * @param $execute_hook bool
		 */
		$execute_hook = apply_filters( 'wbcr/riop/queue_item_save_execute_hook', true );

		if ( $is_success && $execute_hook ) {
			/**
			 * Fires after queue item was saved or updated successfully.
			 *
			 * @param RIO_Process_Queue $this
			 * @param bool              $quota Deduct from the quota?
			 */
			do_action( 'wbcr/riop/queue_item_saved', $this, false );
		}

		return $is_success;
	}

	/**
	 * Удаление информации об оптимизации из таблицы в базе данных
	 *
	 * @return bool
	 */
	public function delete() {
		global $wpdb;
		$db_table = static::table_name();

		static::invalidate_primed_row( $this->object_id, $this->item_type );

		$rows_affected = $wpdb->delete( $db_table, [ 'id' => $this->id ] );

		WRIO_Plugin::app()->logger->debug( sprintf( 'Deleted queue item #%s', $this->id ) );

		return $rows_affected !== false;
	}

	/**
	 * Возвращает extra_data
	 *
	 * @return RIO_Base_Extra_Data|null
	 */
	public function get_extra_data() {

		if ( empty( $this->extra_data ) ) {
			return null;
		}

		if ( is_string( $this->extra_data ) ) {
			$extra_data = (array) json_decode( $this->extra_data );

			if ( ! empty( $extra_data ) ) {
				$class = isset( $extra_data['class'] ) ? $extra_data['class'] : null;

				if ( ! empty( $class ) && class_exists( $class ) ) {
					$this->extra_data = new $class( $extra_data );

					return $this->extra_data;
				}
			}
		}

		if ( is_object( $this->extra_data ) ) {
			return $this->extra_data;
		}

		return null;
	}

	/**
	 * Устанавливает значение для массива метаданных
	 *
	 * @param object|string $extra_data The extra data.
	 *
	 * @return void
	 */
	public function set_extra_data( $extra_data ) {
		$this->extra_data = $extra_data;
	}

	/**
	 * Mark the current queue item as failed and persist its error details.
	 *
	 * @param string               $error_message Failure message to persist.
	 * @param array<string, mixed> $attributes    Additional queue attributes to overwrite before saving.
	 *
	 * @return bool
	 */
	public function mark_as_error( $error_message, $attributes = [] ) {
		$extra_data = $this->get_extra_data();

		if ( ! $extra_data instanceof RIO_Attachment_Extra_Data ) {
			$extra_data = in_array( $this->get_item_type(), [ 'webp', 'avif' ], true )
				? new RIOP_WebP_Extra_Data()
				: new RIO_Attachment_Extra_Data();
		}

		$extra_data->set_error( 'exception' );
		$extra_data->set_error_msg( $error_message );

		$this->set_result_status( self::STATUS_ERROR );

		if ( ! empty( $attributes ) ) {
			$this->configure( $attributes );
		}

		$this->set_extra_data( $extra_data );

		return $this->save();
	}

	/**
	 * Set item hash.
	 *
	 * @param string $text String to be hashed.
	 *
	 * @return void
	 */
	public function set_item_hash( $text ) {
		if ( ! empty( $text ) ) {
			$this->item_hash = static::generate_item_hash( $text );
		}
	}

	/**
	 * Set the id.
	 *
	 * @param int|null $id The id.
	 *
	 * @return void
	 */
	public function set_id( $id ) {
		if ( is_numeric( $id ) ) {
			$this->id = (int) $id;
		}
	}

	/**
	 * The id.
	 *
	 * @return int|null
	 */
	public function get_id() {

		if ( is_numeric( $this->id ) ) {
			return (int) $this->id;
		}

		return $this->id;
	}

	/**
	 * Set the server id.
	 *
	 * @param null|string $server_id The server id.
	 *
	 * @return void
	 */
	public function set_server_id( $server_id ) {
		$this->server_id = $server_id;
	}

	/**
	 * The server id.
	 *
	 * @return string|null
	 */
	public function get_server_id() {
		return $this->server_id;
	}

	/**
	 * Set the object id.
	 *
	 * @param int|null $object_id The object id.
	 *
	 * @return void
	 */
	public function set_object_id( $object_id ) {
		$this->object_id = $object_id;
	}

	public function get_object_id() {
		return $this->object_id;
	}

	/**
	 * Set the object name.
	 *
	 * @param null|string $object_name The object name.
	 *
	 * @return void
	 */
	public function set_object_name( $object_name ) {
		$this->object_name = $object_name;
	}

	/**
	 * The object name.
	 *
	 * @return string|null
	 */
	public function get_object_name() {
		return $this->object_name;
	}

	/**
	 * Set the item type.
	 *
	 * @param QueueItemType|null $item_type The item type.
	 *
	 * @return void
	 */
	public function set_item_type( $item_type ) {
		$this->item_type = $item_type;
	}

	/**
	 * The item type.
	 *
	 * @return string|null The column of a loaded row: attachment, webp, avif, cf_image or nextgen.
	 */
	public function get_item_type() {
		return $this->item_type;
	}

	/**
	 * Set the item hash alternative.
	 *
	 * @param null|string $text Text to be hashed.
	 *
	 * @return void
	 */
	public function set_item_hash_alternative( $text ) {
		if ( ! empty( $text ) ) {
			$this->item_hash_alternative = static::generate_item_alternative_hash( $text );
		}
	}

	public function get_item_hash_alternative() {
		return $this->item_hash_alternative;
	}

	/**
	 * The item hash.
	 *
	 * @return null|string
	 */
	public function get_item_hash() {
		return $this->item_hash;
	}

	/**
	 * Set the result status.
	 *
	 * @param QueueStatus|null $result_status The result status.
	 *
	 * @return void
	 */
	public function set_result_status( $result_status ) {
		$this->result_status = $result_status;
	}

	/**
	 * The result status.
	 *
	 * @return string|null The column of a loaded row: one of the STATUS_* values.
	 */
	public function get_result_status() {
		return $this->result_status;
	}

	/**
	 * Set the processing level.
	 *
	 * @param string|int|null $processing_level The processing level.
	 *
	 * @return void
	 */
	public function set_processing_level( $processing_level ) {
		$this->processing_level = $processing_level;
	}

	/**
	 * The processing level.
	 *
	 * @return string|int|null A level, or a quality from 1 to 100 for the custom level.
	 */
	public function get_processing_level() {
		return $this->processing_level;
	}

	/**
	 * Set the is backed up.
	 *
	 * @param bool|null $is_backed_up The is backed up.
	 *
	 * @return void
	 */
	public function set_is_backed_up( $is_backed_up ) {
		$this->is_backed_up = $is_backed_up;
	}

	/**
	 * The is backed up.
	 *
	 * @return bool|'0'|'1'|null A loaded row holds the column: '0' or '1'.
	 */
	public function get_is_backed_up() {
		return $this->is_backed_up;
	}

	/**
	 * Set the original size.
	 *
	 * @param int|null $original_size The original size.
	 *
	 * @return void
	 */
	public function set_original_size( $original_size ) {
		$this->original_size = $original_size;
	}

	public function get_original_size() {
		return $this->original_size;
	}

	/**
	 * Set the final size.
	 *
	 * @param int|null $final_size The final size.
	 *
	 * @return void
	 */
	public function set_final_size( $final_size ) {
		$this->final_size = $final_size;
	}

	public function get_final_size() {
		return $this->final_size;
	}

	/**
	 * Set the original mime type.
	 *
	 * @param null|string $original_mime_type The original mime type.
	 *
	 * @return void
	 */
	public function set_original_mime_type( $original_mime_type ) {
		$this->original_mime_type = $original_mime_type;
	}

	/**
	 * The original mime type.
	 *
	 * @return string|null
	 */
	public function get_original_mime_type() {
		return $this->original_mime_type;
	}

	/**
	 * Set the final mime type.
	 *
	 * @param null|string $final_mime_type The final mime type.
	 *
	 * @return void
	 */
	public function set_final_mime_type( $final_mime_type ) {
		$this->final_mime_type = $final_mime_type;
	}

	/**
	 * The final mime type.
	 *
	 * @return string|null
	 */
	public function get_final_mime_type() {
		return $this->final_mime_type;
	}

	/**
	 * Set the created at.
	 *
	 * @param int|null $created_at The created at.
	 *
	 * @return void
	 */
	public function set_created_at( $created_at ) {
		$this->created_at = $created_at;
	}

	/**
	 * get_created_at
	 *
	 * @return int|numeric-string|null Null before the row is saved.
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * Generate item hash.
	 *
	 * @param string $text Text to be hashed.
	 *
	 * @return string
	 */
	public static function generate_item_hash( $text ) {
		return hash( 'sha256', $text );
	}

	/**
	 * Generate item alternative hash.
	 *
	 * @param string $text Text to be hashed.
	 *
	 * @return string
	 */
	public static function generate_item_alternative_hash( $text ) {
		return hash( 'sha256', $text );
	}

	/**
	 * Get table schema.
	 *
	 * @return string
	 */
	public static function get_table_schema() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = self::table_name();
		$sql             = "CREATE TABLE IF NOT EXISTS {$table_name} (
			  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `server_id` varchar(60) DEFAULT NULL,
			  `object_id` bigint(20) UNSIGNED NULL,
			  `object_name` varchar(255) NULL,
			  `item_type` varchar(60) NOT NULL,
			  `item_hash` CHAR(64) NULL COMMENT 'sha256 size',
			  `item_hash_alternative` CHAR(64) NULL COMMENT 'sha256 size',
			  `result_status` varchar(60) NOT NULL,
			  `processing_level` varchar(60) NOT NULL,
			  `is_backed_up` tinyint(1) NOT NULL DEFAULT '0',
			  `original_size` int(11) UNSIGNED NOT NULL,
			  `final_size` int(11) UNSIGNED NOT NULL,
			  `original_mime_type` varchar(60) NOT NULL,
			  `final_mime_type` varchar(60) NOT NULL,
			  `extra_data` TEXT NULL DEFAULT NULL,
			  `created_at` bigint(20) NOT NULL,
			  PRIMARY KEY (`id`)
			) $charset_collate;";

		return $sql;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_table_indexes() {
		$table_name                  = static::table_name();
		$sql_index_type_status       = "ALTER TABLE {$table_name} ADD INDEX `index-type-status` (`item_type`, `result_status`);";
		$sql_index_type_status_level = "ALTER TABLE {$table_name} ADD INDEX `index-type-status-level` (`item_type`, `result_status`, `processing_level`);";
		$sql_index_hash              = "ALTER TABLE {$table_name} ADD UNIQUE `index-hash` (`item_hash`);";
		$sql_index_hash_alternative  = "ALTER TABLE {$table_name} ADD INDEX `index-hash-alternative` (`item_hash_alternative`);";
		$sql_index_type_attachments  = "ALTER TABLE {$table_name} ADD INDEX `index-type-attachments` (`object_id`, `item_type`);";

		return [
			$sql_index_type_status,
			$sql_index_type_status_level,
			$sql_index_hash,
			$sql_index_hash_alternative,
			$sql_index_type_attachments,
		];
	}

	/**
	 * Whether a table of the current site exists.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 * @phpstan-impure
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		// SHOW TABLES does not list temporary tables.
		$suppress = $wpdb->suppress_errors();
		$result   = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
		$wpdb->suppress_errors( $suppress );

		return false !== $result;
	}

	/**
	 * Create the tables of the current site that do not exist, also when their version option says they do.
	 *
	 * @return string The first table that still does not exist, or an empty string.
	 */
	public static function repair_tables() {
		$tables = [
			static::table_name()                => static function () {
				static::update_db_version( 0 );
				static::try_create_plugin_tables();
			},
			\WRIO\Storage\Schema::files_table() => [ '\WRIO\Storage\Schema', 'install' ],
		];

		foreach ( $tables as $table => $create ) {
			if ( static::table_exists( $table ) ) {
				continue;
			}

			$create();

			if ( ! static::table_exists( $table ) ) {
				return $table;
			}
		}

		return '';
	}

	/**
	 * Try to create a plugin table in database
	 *
	 * @throws \Exception
	 * @since  1.3.6
	 */
	public static function try_create_plugin_tables() {
		global $wpdb;

		try {
			if ( ! self::has_table_schema() ) {
				return;
			}

			if ( ! static::get_db_version() ) {
				// The table can outlive its version option: its indexes exist already.
				if ( static::table_exists( static::table_name() ) ) {
					static::update_db_version( 1 );

					return;
				}

				$sql = static::get_table_schema();
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table schema.

				if ( static::has_table_indexes() ) {
					$indexes = static::get_table_indexes();

					foreach ( $indexes as $index ) {
						$wpdb->query( $index ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed index definitions.
					}
				}

				static::update_db_version( 1 );
				static::fix_table_collation();
			}
		} catch ( \Exception $e ) {
			WRIO_Plugin::app()->logger->error( sprintf( "Failed create %s table in database.\r\nSQL: %s", static::table_name(), static::get_table_schema() ) );
		}
	}

	/**
	 * RIO-126: Fix collation for plugin table, if WordPress tables are created in a different encoding.
	 * In some cases, plugin table can be utf8mb4_unicode_520_ci, and Wodpress table can be
	 * utf8mb4_unicode_ci. This leads to problems when performing comparisons between two tables.
	 *
	 * @since  1.3.6
	 */
	public static function fix_table_collation() {
		global $wpdb;

		$wp_post_meta_collation  = null;
		$process_queue_collation = null;
		$wrio_table              = static::table_name();

		$result = $wpdb->get_results( 'SHOW TABLE STATUS' );

		if ( ! empty( $result ) ) {
			foreach ( (array) $result as $table ) {
				if ( $wpdb->postmeta === $table->Name ) {
					$wp_post_meta_collation = $table->Collation;
				} elseif ( $wpdb->prefix . 'rio_process_queue' === $table->Name ) {
					$process_queue_collation = $table->Collation;
				}
			}

			if ( ! empty( $wp_post_meta_collation ) && ! empty( $process_queue_collation ) ) {

				[$wp_post_meta_charset]  = explode( '_', $wp_post_meta_collation );
				[$process_queue_charset] = explode( '_', $process_queue_collation );

				if ( ( $wp_post_meta_collation !== $process_queue_collation ) && $wp_post_meta_charset === $process_queue_charset ) {
					$wpdb->query( "ALTER TABLE {$wrio_table} CONVERT TO CHARACTER SET {$wp_post_meta_charset} COLLATE {$wp_post_meta_collation}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name, charset and collation read from SHOW TABLE STATUS.

					WRIO_Plugin::app()->logger->info( sprintf( "Successfully fix collation for plugin table.\r\nWP COLLATION: %s | PLUGIN COLLATION: %s", $wp_post_meta_collation, $process_queue_collation ) );
				}
			}
		}
	}
}
