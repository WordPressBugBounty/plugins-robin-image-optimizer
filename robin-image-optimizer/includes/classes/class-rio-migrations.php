<?php
/**
 * Upgrade migrations.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the files in /migrations when the plugin version grows.
 *
 * For example, migrations/010803.php holds class WIOUpdate010803 with an install() method, and runs when
 * the stored version is below 1.8.3 and the current one is 1.8.3 or higher. The stored version
 * is the wbcr_io_plugin_version option (site option when network active). A failed migration
 * stores its message in wbcr_io_plugin_migration_error, which stops further migrations and
 * shows a notice until an admin confirms the fix.
 */
class WRIO_Migrations {

	/**
	 * Hooks the migration check and the error notice.
	 *
	 * @return void
	 */
	public static function init() {
		// Every request, not only admin pages: WP-CLI or an auto-update can change the files with no admin visit.
		add_action( 'init', [ __CLASS__, 'maybe_migrate' ] );
		add_action( 'admin_notices', [ __CLASS__, 'error_notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'error_notice' ] );
	}

	/**
	 * Stores the first activation time and the version it was activated with.
	 *
	 * @return void
	 */
	public static function record_activation() {
		if ( ! self::get( 'plugin_activated' ) ) {
			self::update( 'plugin_activated', time() );
		}

		// Only a new site gets the current version. A site with a stored version keeps it until maybe_migrate() runs
		// the migrations of the files that replaced it, for example while the plugin was inactive.
		if ( ! self::get( 'plugin_version' ) ) {
			self::update( 'plugin_version', WRIO_PLUGIN_VERSION );
		}
	}

	/**
	 * Runs the pending migrations after a version change.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		$has_error = false !== self::get( 'plugin_migration_error' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- confirming only clears our own error flag.
		if ( $has_error && isset( $_GET['wbcr_factory_fix_migration_error'] ) && current_user_can( 'update_plugins' ) ) {
			WRIO_Plugin::app()->isNetworkActive() ? delete_site_option( 'wbcr_io_plugin_migration_error' ) : delete_option( 'wbcr_io_plugin_migration_error' );
			wp_safe_redirect( esc_url_raw( remove_query_arg( 'wbcr_factory_fix_migration_error' ) ) );
			exit;
		}

		$old_version = (string) self::get( 'plugin_version' );

		if ( $has_error || ! version_compare( $old_version, WRIO_PLUGIN_VERSION, '<' ) ) {
			return;
		}

		global $wpdb;

		// Many requests can see the new version at the same time: only one runs the migrations. MySQL frees the lock
		// when the request ends, also after a fatal error. Only 0 means another request holds it: SQLite sites
		// (Playground) return other values and run without the lock.
		$lock = self::lock_name();

		if ( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			return;
		}

		try {
			// Another request can have run the migrations after this one loaded the options.
			self::flush_cache();
			$old_version = (string) self::get( 'plugin_version' );

			if ( false !== self::get( 'plugin_migration_error' ) || ! version_compare( $old_version, WRIO_PLUGIN_VERSION, '<' ) ) {
				return;
			}

			self::run( $old_version );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Runs the migration files between the stored version and the current one, then stores the current version.
	 *
	 * @param string $old_version Stored version.
	 *
	 * @return void
	 */
	private static function run( $old_version ) {
		$old_number = self::version_number( $old_version );
		$new_number = self::version_number( WRIO_PLUGIN_VERSION );

		try {
			foreach ( (array) glob( WRIO_PLUGIN_DIR . '/migrations/[0-9]*.php' ) as $file ) {
				$number = (int) basename( (string) $file, '.php' );

				if ( $number > $old_number && $number <= $new_number ) {
					require_once $file;
					$class     = 'WIOUpdate' . basename( (string) $file, '.php' );
					$migration = new $class();

					if ( method_exists( $migration, 'install' ) ) {
						$migration->install();
					}
				}
			}

			self::update( 'plugin_version', WRIO_PLUGIN_VERSION );
		} catch ( Throwable $e ) {
			// Also PHP errors: without the stored error, every request runs the failing migration again.
			self::update( 'plugin_migration_error', $e->getMessage() );
		}
	}

	/**
	 * The name of the MySQL lock of the migrations. MySQL locks are global to the server: the name includes the
	 * database and the table prefix of the site, or of the network when the plugin is network active.
	 *
	 * @return string
	 */
	public static function lock_name() {
		global $wpdb;

		$prefix = WRIO_Plugin::app()->isNetworkActive() ? $wpdb->base_prefix : $wpdb->prefix;

		return 'wrio_migrate_' . md5( $wpdb->dbname . $prefix );
	}

	/**
	 * Removes the cached plugin version and migration error, so that the next read comes from the database.
	 *
	 * @return void
	 */
	private static function flush_cache() {
		if ( WRIO_Plugin::app()->isNetworkActive() ) {
			$network_id = get_current_network_id();

			foreach ( [ 'notoptions', 'wbcr_io_plugin_version', 'wbcr_io_plugin_migration_error' ] as $key ) {
				wp_cache_delete( $network_id . ':' . $key, 'site-options' );
			}

			return;
		}

		foreach ( [ 'alloptions', 'notoptions', 'wbcr_io_plugin_version', 'wbcr_io_plugin_migration_error' ] as $key ) {
			wp_cache_delete( $key, 'options' );
		}
	}

	/**
	 * Shows the stored migration error to admins who can update plugins.
	 *
	 * @return void
	 */
	public static function error_notice() {
		$error = self::get( 'plugin_migration_error' );

		if ( false === $error || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><b>%s</b><br>%s<br><br><a href="%s" class="button button-default">%s</a></p></div>',
			esc_html__( 'Robin image optimizer migration error', 'robin-image-optimizer' ),
			esc_html( (string) $error ),
			esc_url( add_query_arg( 'wbcr_factory_fix_migration_error', 1 ) ),
			esc_html__( 'I fixed, confirm migration', 'robin-image-optimizer' )
		);
	}

	/**
	 * 1.8.3 -> 10803, 2.0.10 -> 20010. Empty or unknown versions are 0, so all migrations run.
	 *
	 * @param string $version Version.
	 *
	 * @return int
	 */
	private static function version_number( $version ) {
		if ( ! preg_match( '/(\d+)\.(\d+)\.(\d+)/', $version, $m ) ) {
			return 0;
		}

		return (int) sprintf( '%02d%02d%02d', $m[1], $m[2], $m[3] );
	}

	/**
	 * Reads a plugin option.
	 *
	 * @param string $name Option name without the wbcr_io_ prefix.
	 *
	 * @return mixed False when missing.
	 */
	private static function get( $name ) {
		return WRIO_Plugin::app()->isNetworkActive() ? get_site_option( 'wbcr_io_' . $name, false ) : get_option( 'wbcr_io_' . $name, false );
	}

	/**
	 * Updates a plugin option.
	 *
	 * @param string $name  Option name without the wbcr_io_ prefix.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	private static function update( $name, $value ) {
		WRIO_Plugin::app()->isNetworkActive() ? update_site_option( 'wbcr_io_' . $name, $value ) : update_option( 'wbcr_io_' . $name, $value );
	}
}
