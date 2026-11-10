<?php
/**
 * Removes the plugin data when the plugin is deleted.
 *
 * @package Robin_Image_Optimizer
 */

// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

// remove plugin options
global $wpdb;

require_once __DIR__ . '/includes/functions.php';

// The ThemeIsle SDK names its options after the plugin folder (WRIO_Plugin::get_sdk_namespace()).
$wrio_sdk_name = str_replace( '-', '_', strtolower( basename( __DIR__ ) ) );

// The settings (wbcr_io_), the wrio_ options (batches, storage state, locks, license data), the wrio_ transients, the
// options of the old Factory framework (wbcr_image_optimizer_) and of the ThemeIsle SDK (the plugin slug).
$wrio_like = array_map(
	static function ( string $prefix ) use ( $wpdb ): string {
		return $wpdb->esc_like( $prefix ) . '%';
	},
	[ 'wbcr_io_', 'wrio_', '_transient_wrio_', '_transient_timeout_wrio_', '_site_transient_wrio_', '_site_transient_timeout_wrio_', 'wbcr_image_optimizer_', $wrio_sdk_name . '_' ]
);
$wrio_or   = str_repeat( ' OR %1$s LIKE %%s', count( $wrio_like ) );

// Removes the "Robin Image Optimizer Webp" block from an .htaccess file, for Free and Pro.
$wrio_clear_htaccess = static function ( string $file ): void {
	$rules = is_file( $file ) && is_writable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	$clean = preg_replace( '/^# BEGIN Robin Image Optimizer Webp\R.*?^# END Robin Image Optimizer Webp(?:\R|$)/ms', '', $rules );

	if ( is_string( $clean ) && $clean !== $rules ) {
		file_put_contents( $file, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
};

// Deletes the WebP and AVIF files of the current site that Robin made. A file is Robin's when its source path still
// matches the hash of a recorded conversion; any other file stays. NextGEN images keep theirs: their paths are in the
// NextGEN tables.
$wrio_delete_converted = static function () use ( $wpdb ): void {
	$files = $wpdb->prefix . 'wrio_files';
	$queue = $wpdb->prefix . 'rio_process_queue';

	if ( $files !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $files ) ) ) ) {
		return;
	}

	// Without the queue table, the custom folder images have no path: their files stay. A join of a missing table fails.
	$query   = $queue === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue ) ) )
		? "q.extra_data FROM {$files} f LEFT JOIN {$queue} q ON f.source = 'cf_image' AND q.id = f.object_id AND q.item_type = 'cf_image' WHERE f.source IN ( 'attachment', 'cf_image' )"
		: "NULL AS extra_data FROM {$files} f WHERE f.source = 'attachment'";
	$rows    = $wpdb->get_results( "SELECT f.source, f.object_id, f.size_name, f.format, f.path_hash, {$query} AND f.status = 'ok' AND f.format IN ( 'webp', 'avif' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables and fixed values.
	$uploads = trailingslashit( wp_upload_dir()['basedir'] );
	$root    = wp_normalize_path( ABSPATH );

	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	// The folder that holds the files of each source (WRIO_Custom_Folders::get_storage_root() for custom folders).
	$bases = [
		'attachment' => $uploads,
		'cf_image'   => is_main_site() ? get_home_path() : $uploads,
	];

	foreach ( (array) $rows as $row ) {
		$path = '';

		if ( 'attachment' === $row->source ) {
			$file = (string) get_post_meta( (int) $row->object_id, '_wp_attached_file', true );
			$meta = (array) get_post_meta( (int) $row->object_id, '_wp_attachment_metadata', true );
			$size = 'original' === $row->size_name ? basename( $file ) : (string) ( $meta['sizes'][ $row->size_name ]['file'] ?? '' );
			$path = '' === $file || '' === $size ? '' : $uploads . ltrim( trailingslashit( dirname( $file ) ), './' ) . $size;
		} else {
			$data = json_decode( (string) $row->extra_data, true );
			// The root of WRIO_Custom_Folders::get_storage_root().
			$path = empty( $data['file_path'] ) ? '' : untrailingslashit( $bases['cf_image'] ) . $data['file_path'];
		}

		$path = wp_normalize_path( $path );

		if ( '' === wrio_file_path( $path ) || md5( 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path ) !== $row->path_hash ) {
			continue;
		}

		// A folder replaced by a symlink can point outside the folder that Robin wrote to: that file is not Robin's.
		$real = realpath( $path . '.' . $row->format );
		$base = realpath( $bases[ $row->source ] );
		if ( false === $real || false === $base || 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
			continue;
		}

		wp_delete_file( $real );
	}
};

foreach ( is_multisite() ? $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ) : [ 0 ] as $wrio_blog_id ) {
	if ( $wrio_blog_id ) {
		switch_to_blog( (int) $wrio_blog_id );
	}

	// Read before the settings are deleted. A network activation keeps the setting in the network options.
	$wrio_delete_files = get_option( 'wbcr_io_delete_converted_on_uninstall' ) || ( is_multisite() && get_site_option( 'wbcr_io_delete_converted_on_uninstall' ) );

	// Every wrio/cron/* and wrio_* event, and the events the ThemeIsle SDK schedules for the plugin, with any arguments.
	foreach ( (array) _get_cron_array() as $wrio_events ) {
		foreach ( array_keys( (array) $wrio_events ) as $wrio_hook ) {
			if ( 0 === strpos( $wrio_hook, 'wrio/' ) || 0 === strpos( $wrio_hook, 'wrio_' ) || 0 === strpos( $wrio_hook, 'robin_image_optimizer_' ) ) {
				wp_unschedule_hook( $wrio_hook );
			}
		}
	}

	// Persistent caches can hold these transients without any database row. Read the license before deleting settings.
	$wrio_license     = get_option( 'wbcr_io_license', [] );
	$wrio_sdk_license = (array) get_option( $wrio_sdk_name . '_license_data', [] );
	$wrio_keys        = [ '', $wrio_license['license']['secret_key'] ?? '', $wrio_sdk_license['key'] ?? '' ];
	if ( wrio_is_clearfy_license_activate() ) {
		$wrio_keys[] = wrio_get_license_key();
	}
	$wrio_api = defined( 'WRIO_API_V2_URL' ) ? WRIO_API_V2_URL : 'https://dashboard.robinoptimizer.com/v2/';
	foreach ( array_unique( $wrio_keys ) as $wrio_key ) {
		delete_transient( 'wrio_v2_limits_' . md5( trailingslashit( $wrio_api ) . '|' . home_url() . '|' . $wrio_key ) );
	}
	delete_transient( 'wrio_v2_backoff_until' );
	foreach ( [ 'media_library', 'media_library_webp', 'media_library_avif', 'custom_folders', 'nextgen' ] as $wrio_scope ) {
		delete_site_transient( 'wrio_optimize_process_' . $wrio_scope . '_process_lock' );
	}
	// The count keys end with a hash of the "Exclude paths" patterns (WRIO_Image_Query::cache_key()). An object
	// cache without group flushes keeps them until they expire (1 hour).
	if ( wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'wrio_image_query' );
	}

	$wrio_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = 'robin_image_optimizer_logger_flag'" . sprintf( $wrio_or, 'option_name' ), $wrio_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- core table, one placeholder per prefix.
	foreach ( $wrio_options as $wrio_option ) {
		if ( 0 === strpos( $wrio_option, '_transient_' ) && 0 !== strpos( $wrio_option, '_transient_timeout_' ) ) {
			delete_transient( substr( $wrio_option, strlen( '_transient_' ) ) );
		} elseif ( 0 === strpos( $wrio_option, '_site_transient_' ) && 0 !== strpos( $wrio_option, '_site_transient_timeout_' ) ) {
			delete_site_transient( substr( $wrio_option, strlen( '_site_transient_' ) ) );
		}
		delete_option( $wrio_option );
	}
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'wio_%' OR meta_key IN ( '_wrio_no_resize', '_wrio_exclude', '_wrio_skip' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table.
	// The size for the "Largest first" order, the Media Library bulk action queue and the client-side upload marks.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wrio_filesize', '_wrio_bulk_queued', '_wrio_client_side_upload', '_wrio_client_side_sizes' )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table.
	if ( $wrio_delete_files ) {
		$wrio_delete_converted();
	}

	// The queue table and the state and history tables (WRIO\Storage\Schema).
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rio_process_queue, {$wpdb->prefix}wrio_files, {$wpdb->prefix}wrio_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin tables.

	// A site admin's settings save writes the WebP block into the uploads .htaccess of that site.
	$wrio_clear_htaccess( trailingslashit( wp_upload_dir()['basedir'] ) . '.htaccess' );
	$wrio_uploads = wp_upload_dir();
	if ( empty( $wrio_uploads['error'] ) ) {
		// The delivery test folders of runs that never finished (WRIO\WEBP\Delivery_Test::FOLDER).
		$wrio_test_folders = array_map( 'basename', (array) glob( trailingslashit( $wrio_uploads['basedir'] ) . 'wrio-delivery-test-*', GLOB_ONLYDIR ) );
		foreach ( array_merge( [ 'wrio', 'wrio-webp-uploads' ], $wrio_test_folders ) as $wrio_folder ) {
			wrio_rmdir( trailingslashit( $wrio_uploads['basedir'] ) . $wrio_folder );
		}
		wp_delete_file( trailingslashit( $wrio_uploads['basedir'] ) . 'wio.log' );
	}

	if ( $wrio_blog_id ) {
		restore_current_blog();
	}
}

if ( is_multisite() ) {
	$wrio_network_options = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, meta_key FROM {$wpdb->sitemeta} WHERE 1 = 0" . sprintf( $wrio_or, 'meta_key' ), $wrio_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- core table, one placeholder per prefix.
	foreach ( $wrio_network_options as $wrio_option ) {
		delete_network_option( (int) $wrio_option->site_id, $wrio_option->meta_key );
	}
}

if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}

$wrio_clear_htaccess( get_home_path() . '.htaccess' );
$wrio_clear_htaccess( trailingslashit( WP_CONTENT_DIR ) . '.htaccess' );

// The plugin is loaded when it is deactivated in this request (wp plugin uninstall --deactivate): its logger must
// not write the log folder again at shutdown.
if ( class_exists( 'WRIO_Plugin' ) ) {
	remove_action( 'shutdown', [ WRIO_Plugin::app()->logger, 'shutdown_flush' ], 9999 );
}

// The log folders of WRIO_Logger (the old one, and the one with the random key) and of the Factory logger before 2.1.
$wrio_log_dirs = array_merge( (array) glob( trailingslashit( WP_CONTENT_DIR ) . 'logs/wbcr_image_optimizer*', GLOB_ONLYDIR ), [ trailingslashit( WP_CONTENT_DIR ) . 'logs/robin-image-optimizer' ] );

// Behind a linked logs folder, a folder is of the plugin only when it has the deny rules of wrio_protect_dir(): the
// logger writes them into each folder with a key, also the folders of older salts.
if ( is_link( trailingslashit( WP_CONTENT_DIR ) . 'logs' ) ) {
	$wrio_log_dirs = array_filter(
		$wrio_log_dirs,
		static function ( $dir ) {
			$rules = is_file( (string) $dir . '/.htaccess' ) ? file( (string) $dir . '/.htaccess' ) : false;

			return is_array( $rules ) && isset( $rules[0] ) && 0 === strpos( $rules[0], '# Robin Image Optimizer: deny web access' );
		}
	);
}

foreach ( $wrio_log_dirs as $wrio_log_dir ) {
	wrio_rmdir( (string) $wrio_log_dir );
}
