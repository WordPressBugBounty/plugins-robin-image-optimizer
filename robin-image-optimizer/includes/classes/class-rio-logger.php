<?php
/**
 * File logger.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buffers log lines and appends them to wp-content/logs/wbcr_image_optimizer-<secret key>/app.log, when the Error Log
 * setting is on.
 *
 * Line format: "17-03-2021 13:44:23 [site.com][info] Message". WRIO_App::get_log_data() parses it.
 */
class WRIO_Logger {

	/**
	 * Buffered lines are written when the buffer holds this many lines, and on shutdown.
	 */
	const FLUSH_INTERVAL = 1000;

	/**
	 * When app.log reaches this size, it is copied to app0.log ... app10.log and emptied.
	 */
	const ROTATE_SIZE = 512000;

	/**
	 * Number of rotated copies to keep.
	 */
	const ROTATE_LIMIT = 10;

	/**
	 * Lines not yet written to the file.
	 *
	 * @var string[]
	 */
	private $lines = [];

	/**
	 * Registers the shutdown flush.
	 */
	public function __construct() {
		add_action( 'shutdown', [ $this, 'shutdown_flush' ], 9999, 0 );
	}

	/**
	 * Log a message at the info level.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public function info( $message ) {
		$this->add( 'info', $message );
	}

	/**
	 * Log a message at the error level.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public function error( $message ) {
		$this->add( 'error', $message );
	}

	/**
	 * Log a message at the warning level.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public function warning( $message ) {
		$this->add( 'warning', $message );
	}

	/**
	 * Log a message at the debug level.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public function debug( $message ) {
		$this->add( 'debug', $message );
	}

	/**
	 * Logs the memory limit, the memory in use and the peak memory.
	 *
	 * @return void
	 */
	public function memory_usage() {
		$this->info(
			sprintf(
				'Memory: %s (avail) / %sM (used) / %sM (peak)',
				ini_get( 'memory_limit' ),
				number_format( memory_get_usage( true ) / ( 1024 * 1024 ), 2 ),
				number_format( memory_get_peak_usage( true ) / ( 1024 * 1024 ), 2 )
			)
		);
	}

	/**
	 * Buffers one line.
	 *
	 * @param string $level   info, error, warning or debug.
	 * @param string $message Message.
	 *
	 * @return void
	 */
	private function add( $level, $message ) {
		// The Error Log setting turns all file logging on and off; its frontend option adds lines to it.
		if ( ! WRIO_Plugin::app()->getPopulateOption( 'error_log', false ) ) {
			return;
		}

		$host = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';

		$this->lines[] = sprintf( '%s [%s][%s] %s', date_i18n( 'd-m-Y H:i:s' ), $host, $level, $message );

		if ( count( $this->lines ) >= self::FLUSH_INTERVAL ) {
			$this->flush();
		}
	}

	/**
	 * Appends the buffered lines to the log file.
	 *
	 * @return bool False when nothing was written.
	 */
	public function flush() {
		if ( empty( $this->lines ) ) {
			return false;
		}

		$content     = PHP_EOL . implode( PHP_EOL, $this->lines );
		$this->lines = [];

		// No log file: the folder is a link that could not be removed.
		if ( '' === $this->get_dir() ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== @file_put_contents( $this->get_dir(), $content, FILE_APPEND );
	}

	/**
	 * Closes the request's block of lines with a separator and writes it.
	 *
	 * @return void
	 */
	public function shutdown_flush() {
		if ( ! empty( $this->lines ) ) {
			$this->lines[] = '-------------------------------';
		}

		$this->flush();
	}

	/**
	 * Path of app.log. Rotates the file first when it is too large.
	 *
	 * The name is kept from the Factory logger, which callers still use.
	 *
	 * @return string An empty string when the log folder is a link that could not be removed.
	 */
	public function get_dir() {
		$base_dir = $this->get_base_dir();

		// A linked folder that could not be removed: no file, so nothing is read or written behind the link.
		if ( is_link( untrailingslashit( $base_dir ) ) ) {
			return '';
		}

		$file = $base_dir . 'app.log';

		if ( file_exists( $file ) && filesize( $file ) >= self::ROTATE_SIZE ) {
			for ( $i = self::ROTATE_LIMIT; $i >= 0; $i-- ) {
				$next = 0 !== $i ? $base_dir . 'app' . ( $i - 1 ) . '.log' : $file;

				if ( file_exists( $next ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@copy( $next, $base_dir . 'app' . $i . '.log' );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $file, '' );
		}

		return $file;
	}

	/**
	 * Log directory, with a trailing slash. Creates it, with deny rules, when missing.
	 *
	 * The folder name has a secret key of the site, so its URL cannot be guessed where the server ignores the deny
	 * rules (nginx). The logs of the old folder, without the key, move into it.
	 *
	 * @return string
	 */
	public function get_base_dir() {
		// ponytail: derived from the salts, not stored, so two requests always get the same folder. New salts start a
		// new folder; uninstall removes all of them.
		$key  = substr( wp_hash( 'wrio_log_dir', 'secure_auth' ), 0, 16 );
		$logs = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'logs/' );
		$path = $logs . 'wbcr_image_optimizer-' . $key . '/';

		$legacy = $logs . 'wbcr_image_optimizer';

		// The target of a link is not a folder of the plugin: remove the link, do not read or write behind it.
		foreach ( [ untrailingslashit( $path ), $legacy ] as $dir ) {
			if ( is_link( $dir ) ) {
				wp_delete_file( $dir );
			}
		}

		// Nothing is merged behind a linked logs folder or a link that could not be removed: the folders there are not
		// known to be of the plugin.
		$foreign = is_link( untrailingslashit( $logs ) ) || is_link( $legacy ) || is_link( untrailingslashit( $path ) );

		// The keyed folder is made first, so the old folder is not renamed to it.
		if ( $foreign ) {
			wp_mkdir_p( $path );
		}

		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_dir( $legacy ) || ! @rename( $legacy, untrailingslashit( $path ) ) ) {
				wp_mkdir_p( $path );
			}
		}

		if ( ! $foreign && is_dir( $legacy ) ) {
			// One request merges at a time. Another request could remove the old folder while a moved log still has to
			// go back into it. A request that does not get the lock leaves the old folder for a later call.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
			$lock = @fopen( $path . '.merge.lock', 'c' );
			if ( $lock && flock( $lock, LOCK_EX | LOCK_NB ) ) {
				$this->merge_legacy_dir( $legacy . '/', $path );
				flock( $lock, LOCK_UN );
			}
			if ( $lock ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $lock );
			}
		}

		wrio_protect_dir( $path );

		return $path;
	}

	/**
	 * Moves the logs of the old folder, without the key, into the keyed folder and removes the old folder.
	 *
	 * Used when both folders exist. The old lines go before the lines of the keyed file with the same name.
	 *
	 * @param string $legacy Old folder, with a trailing slash.
	 * @param string $path   Keyed folder, with a trailing slash.
	 *
	 * @return void
	 */
	private function merge_legacy_dir( $legacy, $path ) {
		foreach ( (array) glob( $legacy . '*.log' ) as $file ) {
			$file   = (string) $file;
			$target = $path . basename( $file );
			$moved  = $target . '.merge';

			// Move first: an old file that cannot leave its folder is never merged, so the next call cannot merge it again.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @rename( $file, $moved ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( file_exists( $target ) && false !== @file_put_contents( $target, file_get_contents( $moved ) . file_get_contents( $target ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $moved );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
				@rename( $moved, file_exists( $target ) ? $file : $target );
			}
		}

		// A file that could not move keeps the folder and its deny rules.
		if ( array_diff( (array) scandir( $legacy ), [ '.', '..', '.htaccess', 'index.php', 'index.html' ] ) ) {
			wrio_protect_dir( $legacy );

			return;
		}

		// index.html is the fallback page of the Factory logger before 2.1.
		foreach ( [ '.htaccess', 'index.php', 'index.html' ] as $name ) {
			if ( file_exists( $legacy . $name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $legacy . $name );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $legacy );
	}

	/**
	 * Paths of app.log and its rotated copies.
	 *
	 * @return string[]
	 */
	public function get_all() {
		$dir   = $this->get_base_dir();
		$files = is_link( untrailingslashit( $dir ) ) ? [] : glob( $dir . '*.log' );

		return false === $files ? [] : $files;
	}

	/**
	 * Size in bytes of all log files.
	 *
	 * @return int
	 */
	public function get_total_size() {
		$bytes = 0;

		foreach ( $this->get_all() as $file ) {
			$bytes += (int) filesize( $file );
		}

		return $bytes;
	}

	/**
	 * Deletes all log files.
	 *
	 * @return bool False when a file could not be deleted.
	 */
	public function clean_up() {
		$deleted = true;

		foreach ( $this->get_all() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			$deleted = unlink( $file ) && $deleted;
		}

		return $deleted;
	}

	/**
	 * Sends a ZIP with the log files and a system-info.txt, then exits.
	 *
	 * @return void Returns only when the archive cannot be built.
	 */
	public function download_export() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->error( 'App does not have \ZipArchive class available. It is not possible to prepare export' );

			return;
		}

		$zip_path = wp_tempnam( 'wrio-log-export' );
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
			$this->error( sprintf( 'Failed to create ZIP archive in path %s. Skipping export...', $zip_path ) );

			return;
		}

		foreach ( $this->get_all() as $file ) {
			$zip->addFile( $file, wp_basename( $file ) );
		}

		$zip->addFromString( 'system-info.txt', $this->get_system_info() );
		$zip->close();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = (string) file_get_contents( $zip_path );
		wp_delete_file( $zip_path );

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=plugin_log_export-' . gmdate( 'c' ) . '.zip' );
		header( 'Content-Length: ' . strlen( $content ) );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary ZIP.
		exit;
	}

	/**
	 * Versions, server details, active plugins and PHP extensions for the export.
	 *
	 * @return string
	 */
	private function get_system_info() {
		global $wp_version;

		$server = function ( $key ) {
			return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '*empty*';
		};

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active      = [];

		foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
			if ( is_string( $plugin ) && isset( $all_plugins[ $plugin ] ) ) {
				$active[] = sprintf( '%s (%s)', $all_plugins[ $plugin ]['Name'], $all_plugins[ $plugin ]['Version'] );
			}
		}

		$lines = [
			'Plugin version: ' . WRIO_PLUGIN_VERSION,
			'WordPress Version: ' . $wp_version,
			'PHP Version: ' . PHP_VERSION,
			'Locale: ' . get_locale(),
			'HTTP Accept: ' . $server( 'HTTP_ACCEPT' ),
			'HTTP User Agent: ' . $server( 'HTTP_USER_AGENT' ),
			'Server software: ' . $server( 'SERVER_SOFTWARE' ),
			'',
			'Active plugins:',
			implode( PHP_EOL, $active ),
			'',
			'Active extensions: ',
			implode( ', ', get_loaded_extensions() ),
			'',
			'Generated at: ' . gmdate( 'c' ),
		];

		return implode( PHP_EOL, $lines );
	}
}
