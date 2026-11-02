<?php
/**
 * SQL conditions on the state table, for the media library queries.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\Storage;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditions on an item from its state rows. Each one is complete SQL, without placeholders.
 *
 * The item is an attachment ("posts.ID") by default. $source and $id name another one: a custom-folder file
 * ('cf_image', the ID of its queue row) or a NextGEN image ('nextgen', "pid"). They are SQL, never user input.
 *
 * The main file ("original") decides the state of the item, as the queue row of the item did:
 * - done: its output is ok or skipped.
 * - waiting: its output failed or is missing, and no retry is due yet (permanent, exhausted, waiting for a plan
 *   change, or a retry time in the future). A bulk run does not pick it, and does not count it as remaining.
 * - pending: neither done nor waiting. A bulk run picks it.
 *
 * @phpstan-import-type Source from Store
 * @phpstan-import-type Output from Store
 */
class Queries {

	/**
	 * The item's output is done.
	 *
	 * @param 'attachment'|Output $output optimized, webp or avif. attachment is optimized.
	 * @param Source              $source attachment, cf_image or nextgen.
	 * @param string              $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function done( $output, $source = 'attachment', $id = 'posts.ID' ) {
		return 'EXISTS ( ' . self::rows( $output, $source, $id ) . " AND f.status IN ('ok', 'skipped') )";
	}

	/**
	 * The item's output was made: done and not skipped.
	 *
	 * @param 'attachment'|Output $output optimized, webp or avif. attachment is optimized.
	 * @param Source              $source attachment, cf_image or nextgen.
	 * @param string              $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function succeeded( $output, $source = 'attachment', $id = 'posts.ID' ) {
		return 'EXISTS ( ' . self::rows( $output, $source, $id ) . " AND f.status = 'ok' )";
	}

	/**
	 * The item's output failed, and no automatic retry is due.
	 *
	 * @param 'attachment'|Output $output optimized, webp or avif. attachment is optimized.
	 * @param Source              $source attachment, cf_image or nextgen.
	 * @param string              $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function waiting( $output, $source = 'attachment', $id = 'posts.ID' ) {
		return 'EXISTS ( ' . self::rows( $output, $source, $id ) . " AND f.status IN ('failed', 'missing') AND NOT ( f.permanent = 0 AND f.next_attempt_at IS NOT NULL AND f.next_attempt_at <= UTC_TIMESTAMP() ) )";
	}

	/**
	 * The item's output is to be processed now.
	 *
	 * @param 'attachment'|Output $output optimized, webp or avif. attachment is optimized.
	 * @param Source              $source attachment, cf_image or nextgen.
	 * @param string              $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function pending( $output, $source = 'attachment', $id = 'posts.ID' ) {
		return 'NOT ' . self::done( $output, $source, $id ) . ' AND NOT ' . self::waiting( $output, $source, $id );
	}

	/**
	 * Some output of some file of the item failed or is missing.
	 *
	 * @param Source $source attachment, cf_image or nextgen.
	 * @param string $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function error( $source = 'attachment', $id = 'posts.ID' ) {
		$table = Schema::files_table();

		return "EXISTS ( SELECT 1 FROM {$table} f WHERE f.source = '{$source}' AND f.object_id = {$id} AND f.status IN ('failed', 'missing') )";
	}

	/**
	 * Sums of the sizes of an output of a source, over the done rows.
	 *
	 * A skipped optimized file counts with its own size on both sides, as in the queue rows.
	 *
	 * @param Source              $source attachment, cf_image or nextgen.
	 * @param 'attachment'|Output $output optimized, webp or avif. attachment is optimized.
	 *
	 * @return array{original_size: int, final_size: int}
	 */
	public static function sum_sizes( $source, $output ) {
		global $wpdb;

		$table    = Schema::files_table();
		$statuses = 'optimized' === $output ? "'ok', 'skipped'" : "'ok'";
		$exists   = self::exists( $source );
		// Only the items that still exist: a removed folder or NextGEN image keeps its rows until the daily clean-up.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE( SUM( original_size ), 0 ) AS original_size, COALESCE( SUM( final_size ), 0 ) AS final_size FROM {$table} WHERE source = %s AND format = %s AND status IN ( {$statuses} ) AND {$exists}", $source, self::output( $output ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table, fixed statuses, fixed SQL.

		return [
			'original_size' => (int) ( $row['original_size'] ?? 0 ),
			'final_size'    => (int) ( $row['final_size'] ?? 0 ),
		];
	}

	/**
	 * SQL condition: the object_id of a row is an item that still exists.
	 *
	 * @param string $source attachment, cf_image or nextgen. Any other value matches every row.
	 *
	 * @return string
	 */
	public static function exists( $source ) {
		global $wpdb;

		$items = [
			'attachment' => "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'",
			'cf_image'   => 'SELECT id FROM ' . \RIO_Process_Queue::table_name() . " WHERE item_type = 'cf_image'",
			'nextgen'    => "SELECT pid FROM {$wpdb->prefix}ngg_pictures",
		];

		return isset( $items[ $source ] ) ? "object_id IN ( {$items[ $source ]} )" : '1 = 1';
	}

	/**
	 * Some file of the item changed and waits to be processed again (see Store::mark_pending()).
	 *
	 * @param Source                   $source attachment, cf_image or nextgen.
	 * @param string                   $id     SQL of the item ID.
	 * @param 'attachment'|Output|null $output Only rows of this output. Null: any output.
	 *
	 * @return string
	 */
	public static function changed( $source = 'attachment', $id = 'posts.ID', $output = null ) {
		$table  = Schema::files_table();
		$format = null === $output ? '' : " AND f.format = '" . self::output( $output ) . "'";

		return "EXISTS ( SELECT 1 FROM {$table} f WHERE f.source = '{$source}' AND f.object_id = {$id}{$format} AND f.status = 'pending' )";
	}

	/**
	 * Some file of the item failed in a way that a new bulk run tries again: not permanent, and not a limit of the
	 * plan, which waits for a plan change (see Store::forget_failures()).
	 *
	 * @param Source $source attachment, cf_image or nextgen.
	 * @param string $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function retryable( $source = 'attachment', $id = 'posts.ID' ) {
		$table = Schema::files_table();
		$plan  = "'" . implode( "','", Store::PLAN_CODES ) . "'";

		return "EXISTS ( SELECT 1 FROM {$table} f WHERE f.source = '{$source}' AND f.object_id = {$id} AND f.status IN ('failed', 'missing') AND f.permanent = 0 AND f.code NOT IN ( {$plan} ) )";
	}

	/**
	 * Number of rows of an output with a status.
	 *
	 * @param Source                            $source attachment, cf_image or nextgen.
	 * @param 'attachment'|Output               $output optimized, webp or avif. attachment is optimized.
	 * @param 'ok'|'skipped'|'failed'|'missing' $status ok, skipped, failed or missing.
	 *
	 * @return int
	 */
	public static function count( $source, $output, $status ) {
		global $wpdb;

		$table = Schema::files_table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND format = %s AND status = %s", $source, self::output( $output ), $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
	}

	/**
	 * A failure of the output on some file of the item is due for its retry.
	 *
	 * The main file decides most states, but a thumbnail whose conversion failed must be tried again too, also
	 * when the main file's conversion is done.
	 *
	 * @param 'attachment'|Output|null $output optimized, webp or avif. Null: any output.
	 * @param Source                   $source attachment, cf_image or nextgen.
	 * @param string                   $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function due( $output, $source = 'attachment', $id = 'posts.ID' ) {
		$table  = Schema::files_table();
		$format = null === $output ? '' : "AND f.format = '" . self::output( $output ) . "'";

		return "EXISTS ( SELECT 1 FROM {$table} f WHERE f.source = '{$source}' AND f.object_id = {$id} {$format} AND f.status IN ('failed', 'missing') AND f.permanent = 0 AND f.next_attempt_at IS NOT NULL AND f.next_attempt_at <= UTC_TIMESTAMP() )";
	}

	/**
	 * Neither done nor failed: the item was not processed yet.
	 *
	 * @param Source $source attachment, cf_image or nextgen.
	 * @param string $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function untouched( $source, $id ) {
		return 'NOT ' . self::done( 'optimized', $source, $id ) . ' AND NOT ' . self::error( $source, $id );
	}

	/**
	 * Something of the item is to be processed now: its main file, a changed file, or any output whose retry is due.
	 * For the sources without a conversion step (custom folders, NextGEN): one run does every output.
	 *
	 * @param 'cf_image'|'nextgen' $source cf_image or nextgen.
	 * @param string               $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function work( $source, $id ) {
		return '( ( ' . self::pending( 'optimized', $source, $id ) . ' ) OR ' . self::changed( $source, $id ) . ' OR ' . self::due( null, $source, $id ) . ' OR ' . self::missing_format( $source, $id ) . ' )';
	}

	/**
	 * The item's main file is done, and a format that is turned on has no row: it was turned on after the
	 * optimization. A failed format has a row, and its retry is in due().
	 *
	 * @param 'cf_image'|'nextgen' $source cf_image or nextgen.
	 * @param string               $id     SQL of the item ID.
	 *
	 * @return string
	 */
	public static function missing_format( $source, $id ) {
		$missing = array_map( fn( $format ) => 'NOT EXISTS ( ' . self::rows( $format, $source, $id ) . ' )', \WRIO_Formats::get_enabled_formats() );

		return empty( $missing ) ? '0 = 1' : '( ' . self::done( 'optimized', $source, $id ) . ' AND ( ' . implode( ' OR ', $missing ) . ' ) )';
	}

	/**
	 * The output of an item type of the queue table: "attachment" is the optimized file.
	 *
	 * @param 'attachment'|Output $type attachment, optimized, webp or avif.
	 *
	 * @return Output
	 */
	public static function output( $type ) {
		return in_array( $type, [ 'webp', 'avif' ], true ) ? $type : 'optimized';
	}

	/**
	 * Rows of the main file of the item for one output.
	 *
	 * @param 'attachment'|Output $output optimized, webp or avif. attachment is optimized.
	 * @param Source              $source attachment, cf_image or nextgen.
	 * @param string              $id     SQL of the item ID.
	 *
	 * @return string
	 */
	private static function rows( $output, $source, $id ) {
		$table  = Schema::files_table();
		$output = self::output( $output );

		return "SELECT 1 FROM {$table} f WHERE f.source = '{$source}' AND f.object_id = {$id} AND f.size_name = 'original' AND f.format = '{$output}'";
	}
}
