<?php
/**
 * Tables of the optimization state and its history.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\Storage;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, upgrades and drops the tables of one site.
 *
 * - wrio_files: the current state of each file and output (one row per source, object_id, size_name and format).
 * - wrio_log: one row per processing attempt or event. It is history: nothing reads it to decide what to do.
 *
 * The old rio_process_queue table is not changed, so an older plugin version keeps working after a rollback.
 */
class Schema {

	/**
	 * Version of the table definitions. Raise it when sql() changes; dbDelta() then adds the new columns and keys.
	 */
	const VERSION = 3;

	/**
	 * Option with the installed VERSION of the current site.
	 */
	const OPTION = 'wrio_storage_db_version';

	/**
	 * The state table.
	 *
	 * @return string
	 */
	public static function files_table() {
		global $wpdb;

		return $wpdb->prefix . 'wrio_files';
	}

	/**
	 * The history table.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'wrio_log';
	}

	/**
	 * Create or upgrade the tables when the installed version is not VERSION.
	 *
	 * It runs on every load, not only in the admin: after an update, cron or the front end can process images
	 * before anybody opens the admin. The option is autoloaded, so the check costs no query.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::VERSION !== (int) get_option( self::OPTION, 0 ) ) {
			self::install();
		}
	}

	/**
	 * Create or upgrade the tables.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::sql() );
		update_option( self::OPTION, self::VERSION, true );
	}

	/**
	 * Drop the tables of the current site.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin tables.
		}

		delete_option( self::OPTION );
	}

	/**
	 * The tables, for drop() and the wpmu_drop_tables filter.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return [ self::files_table(), self::log_table() ];
	}

	/**
	 * Add the tables of a deleted site to the tables that WordPress drops.
	 *
	 * @param string[] $tables Tables of the site.
	 *
	 * @return string[]
	 */
	public static function drop_site_tables( $tables ) {
		return array_merge( $tables, self::tables() );
	}

	/**
	 * Table definitions in the dbDelta() format: one column per line, two spaces after PRIMARY KEY, no comments.
	 *
	 * @return string[]
	 */
	public static function sql() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$files           = self::files_table();
		$log             = self::log_table();

		return [
			"CREATE TABLE {$files} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source varchar(20) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
size_name varchar(100) NOT NULL,
format varchar(10) NOT NULL,
status varchar(10) NOT NULL,
path_hash char(32) NOT NULL DEFAULT '',
original_size int(10) unsigned NOT NULL DEFAULT 0,
final_size int(10) unsigned NOT NULL DEFAULT 0,
source_size int(10) unsigned NOT NULL DEFAULT 0,
source_mtime int(10) unsigned NOT NULL DEFAULT 0,
source_hash char(32) NOT NULL DEFAULT '',
source_full_hash char(32) NOT NULL DEFAULT '',
mime_type varchar(50) NOT NULL DEFAULT '',
level varchar(20) NOT NULL DEFAULT '',
backed_up tinyint(1) unsigned NOT NULL DEFAULT 0,
code varchar(50) NOT NULL DEFAULT '',
permanent tinyint(1) unsigned NOT NULL DEFAULT 0,
attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
next_attempt_at datetime NULL DEFAULT NULL,
request_id varchar(64) NOT NULL DEFAULT '',
legacy tinyint(1) unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY file_output (source,object_id,size_name,format),
KEY source_status (source,format,status),
KEY path_hash (path_hash)
) {$charset_collate};",
			"CREATE TABLE {$log} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
run_id varchar(36) NOT NULL DEFAULT '',
started_by varchar(20) NOT NULL DEFAULT '',
action varchar(10) NOT NULL DEFAULT '',
source varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
size_name varchar(100) NOT NULL DEFAULT '',
status varchar(10) NOT NULL DEFAULT '',
original_size int(10) unsigned NOT NULL DEFAULT 0,
outputs text NULL,
code varchar(50) NOT NULL DEFAULT '',
detail text NULL,
request_id varchar(64) NOT NULL DEFAULT '',
duration_ms int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY object (source,object_id),
KEY run_id (run_id)
) {$charset_collate};",
		];
	}
}
