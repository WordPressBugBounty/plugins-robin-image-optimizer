<?php
/**
 * What to do about each error code, the docs page that explains it, and the diagnostics report for support.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public page that lists every error with its recommended action (Codeinwp/docs-site).
 */
const WRIO_ERRORS_DOCS_URL = 'https://docs.themeisle.com/robin-image-optimizer/troubleshooting-optimization-errors';

/**
 * The anchor of an error code on the errors page: missing_file for a missing file, http_request_failed for http_0,
 * http_error for every other http_<status> code, the code's own section when it has a hint, and the support section
 * otherwise.
 *
 * @param string $status Row status: failed, skipped or missing.
 * @param string $code   Error code.
 *
 * @return string
 */
function wrio_error_anchor( $status, $code ) {
	if ( 'missing' === $status ) {
		return 'missing_file';
	}

	if ( 'http_0' === $code ) {
		return 'http_request_failed';
	}

	// Only http_<status> codes: http_request_failed (no answer) has its own section.
	$anchor = preg_match( '/^http_\d+$/', $code ) ? 'http_error' : sanitize_key( $code );

	// The errors page has a section for each code with a hint. An unknown code, for example a new one from the
	// server, gets the support section: its hint is the support text.
	return isset( wrio_error_hints()[ $anchor ] ) ? $anchor : 'before-you-contact-support';
}

/**
 * The "Learn more" link of an error code.
 *
 * @param string $status Row status: failed, skipped or missing.
 * @param string $code   Error code.
 *
 * @return string
 */
function wrio_error_docs_url( $status, $code ) {
	return WRIO_ERRORS_DOCS_URL . '#' . wrio_error_anchor( $status, $code );
}

/**
 * What the user can do about an error code, in plain words. The errors page says more.
 *
 * @param string $status Row status: failed, skipped or missing.
 * @param string $code   Error code.
 *
 * @return string
 */
function wrio_error_hint( $status, $code ) {
	return wrio_error_hints()[ wrio_error_anchor( $status, $code ) ] ?? __( 'If it happens again, click "Copy diagnostics" and send the text to support.', 'robin-image-optimizer' );
}

/**
 * The hint of each error code that the errors page explains, by its anchor.
 *
 * @return array<string, string>
 */
function wrio_error_hints() {
	$fix     = __( 'Open the image in an image editor, save it again, and upload it again. Or replace the image.', 'robin-image-optimizer' );
	$wait    = __( 'Nothing to do: Robin tries again later on its own.', 'robin-image-optimizer' );
	$support = __( 'If it happens again, click "Copy diagnostics" and send the text to support.', 'robin-image-optimizer' );
	$hints   = [
		'missing_file'           => __( 'The file is no longer on the server. Upload the image again, or delete the attachment.', 'robin-image-optimizer' ),
		'corrupt_image'          => $fix,
		'no_metadata'            => $fix,
		'unsupported_media_type' => __( 'Convert the file to JPEG, PNG, GIF or WebP, then upload it again.', 'robin-image-optimizer' ),
		'file_too_large'         => __( 'Resize the image before the upload, turn on "Resizing large images", or upgrade your plan.', 'robin-image-optimizer' ),
		'image_too_large'        => __( 'Resize the image before the upload, turn on "Resizing large images", or upgrade your plan.', 'robin-image-optimizer' ),
		'not_in_plan'            => __( 'Upgrade your plan, or turn this format off in the settings.', 'robin-image-optimizer' ),
		'invalid_license'        => __( 'Open the License page and check that the key is active. Activate it again if needed.', 'robin-image-optimizer' ),
		'rate_limited'           => $wait,
		'server_busy'            => $wait,
		'internal_error'         => $wait . ' ' . $support,
		'invalid_response'       => $wait . ' ' . $support,
		'no_answer'              => $wait . ' ' . $support,
		'processing_failed'      => $wait . ' ' . $support,
		'http_request_failed'    => __( 'Your server cannot reach the optimization server. Ask your host to allow requests to dashboard.robinoptimizer.com.', 'robin-image-optimizer' ),
		'http_error'             => $wait . ' ' . $support,
		'download_failed'        => $wait,
		'invalid_download'       => $wait,
		'time_limit'             => __( 'Ask your host to raise the PHP max_execution_time (60 seconds or more), or lower "Images per iteration".', 'robin-image-optimizer' ),
		'memory_limit'           => __( 'Ask your host to raise the PHP memory_limit (256 MB or more), or resize the image before the upload.', 'robin-image-optimizer' ),
		'crashed'                => __( 'Ask your host to raise the PHP memory_limit and max_execution_time, then click Optimize on the image.', 'robin-image-optimizer' ),
		'backup_failed'          => __( 'Make the uploads folder writable for the web server, or turn off "Backup images".', 'robin-image-optimizer' ),
		'save_failed'            => __( 'Make the uploads folder writable for the web server.', 'robin-image-optimizer' ),
		'path_denied'            => __( 'On a network, a super admin must optimize this file.', 'robin-image-optimizer' ),
		'unexpected_error'       => __( 'Turn on the error log in the settings, try again, and send the log to support.', 'robin-image-optimizer' ),
		'legacy_error'           => __( 'Click Optimize on the image to try again with this version.', 'robin-image-optimizer' ),
		'not_smaller'            => __( 'Nothing to do: the original file is already small, so Robin kept it.', 'robin-image-optimizer' ),
		'same_format'            => __( 'Nothing to do: the file is already in this format.', 'robin-image-optimizer' ),
		'animated'               => __( 'Nothing to do: animated images keep their format.', 'robin-image-optimizer' ),
		'gif'                    => __( 'Nothing to do: GIF images keep their format.', 'robin-image-optimizer' ),
		'too_large_for_format'   => __( 'Resize the image if you need this format for it.', 'robin-image-optimizer' ),
	];

	return $hints;
}

/**
 * A text report for support: versions, PHP limits, cron and loopback status, folders, delivery and the last
 * errors. It holds no license key, token or password.
 *
 * @return string
 */
function wrio_diagnostics() {
	global $wpdb;

	$uploads  = wp_upload_dir();
	$loopback = get_option( 'wrio_loopback_test' );
	$error    = get_option( \WRIO\V2\Client::LAST_ERROR_OPTION );
	$server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	$lines    = [
		'Robin Image Optimizer ' . WRIO_PLUGIN_VERSION . ( wrio_is_license_activate() ? ' (Pro)' : ' (Free)' ),
		'Site: ' . home_url(),
		'WordPress: ' . get_bloginfo( 'version' ) . ( is_multisite() ? ' (multisite)' : '' ),
		'PHP: ' . PHP_VERSION,
		'Server: ' . ( '' !== $server ? $server : 'unknown' ),
		'memory_limit: ' . ini_get( 'memory_limit' ) . ' (WP_MAX_MEMORY_LIMIT: ' . ( defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '-' ) . ')',
		'max_execution_time: ' . ini_get( 'max_execution_time' ),
		'Image editors: ' . implode( ', ', array_filter( [ extension_loaded( 'gd' ) ? 'GD' : '', extension_loaded( 'imagick' ) ? 'Imagick' : '' ] ) ),
		'WP-Cron: ' . ( wrio_cron_runs() ? 'runs' : 'events are late' ) . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' (DISABLE_WP_CRON)' : '' ),
		'WP-Cron test event: ' . ( get_option( 'wrio_cron_probe' ) ? 'ran ' . gmdate( 'Y-m-d H:i', (int) get_option( 'wrio_cron_probe' ) ) . ' UTC' : 'not run' ) . ( wp_next_scheduled( 'wrio/cron/probe' ) ? ', one waits since ' . gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( 'wrio/cron/probe' ) ) . ' UTC' : '' ),
		'Loopback test: ' . ( is_array( $loopback ) ? ( $loopback['ok'] ? 'ok' : 'failed: ' . $loopback['error'] ) . ' (' . gmdate( 'Y-m-d H:i', (int) $loopback['time'] ) . ' UTC)' : 'not run' ),
		'Uploads: ' . $uploads['basedir'] . ( wp_is_writable( $uploads['basedir'] ) ? ' (writable)' : ' (not writable)' ),
		'Formats: ' . implode( ', ', array_merge( WRIO_Formats::get_enabled_formats(), [ 'delivery ' ] ) ) . WRIO_Plugin::app()->getPopulateOption( 'webp_delivery_mode', 'picture' ),
		'Last API error: ' . ( is_array( $error ) ? sprintf( '%s HTTP %d, request %s, %s', $error['code'] ?? '', (int) ( $error['status'] ?? 0 ), $error['request_id'] ?? '-', gmdate( 'Y-m-d H:i', (int) ( $error['time'] ?? 0 ) ) ) : 'none' ),
	];

	// Every setting with its value: none of them is a secret (the license key is not a setting).
	$lines[] = 'Settings:';
	foreach ( WRIO_Settings::get() as $name => $value ) {
		$lines[] = '  ' . $name . ': ' . ( is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a readable value, not debug output.
	}

	if ( \WRIO\Storage\Backfill::reads() ) {
		$table = \WRIO\Storage\Schema::files_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- plugin table.
		$rows = $wpdb->get_results( "SELECT source, object_id, size_name, format, status, code, attempts, updated_at FROM {$table} WHERE status IN ( 'failed', 'missing' ) ORDER BY updated_at DESC LIMIT 20", ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- plugin table.
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ( 'failed', 'missing' )" );
		$lines[] = sprintf( 'Last errors (%d of %d failed files):', count( $rows ), $total );
		foreach ( $rows as $row ) {
			$lines[] = sprintf( '  %s %s #%d %s/%s: %s, attempts %d', $row['updated_at'], $row['status'], $row['object_id'], $row['size_name'], $row['format'], $row['code'], $row['attempts'] );
		}
	}

	return implode( "\n", $lines );
}
