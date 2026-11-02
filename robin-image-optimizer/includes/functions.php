<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WRIO\Paths\phpUri;

/**
 * Checks if the current request is a WP REST API request.
 *
 * Case #1: After WP_REST_Request initialisation
 * Case #2: Support "plain" permalink settings
 * Case #3: URL Path begins with wp-json/ (your REST prefix)
 *          Also supports WP installations in subfolders
 *
 * @since  1.3.6
 * @return boolean
 */
function wrio_doing_rest_api() {
	$prefix     = rest_get_url_prefix();
	$rest_route = WRIO_Plugin::app()->request->get( 'rest_route', null );
	// (#2) The route of ?rest_route= has no prefix, for example /wp/v2/media.
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) // (#1)
		|| ! empty( $rest_route ) ) {
		return true;
	}

	// (#3)
	$rest_url    = wp_parse_url( site_url( $prefix ) );
	$current_url = wp_parse_url( add_query_arg( [] ) );

	return strpos( $current_url['path'] ?? '/', $rest_url['path'], 0 ) === 0;
}

/**
 * @return bool
 * @since  1.3.6
 */
function wrio_doing_ajax() {
	if ( function_exists( 'wp_doing_ajax' ) ) {
		return wp_doing_ajax();
	}

	return defined( 'DOING_AJAX' ) && DOING_AJAX;
}

/**
 * @return bool
 * @since  1.3.6
 */
function wrio_doing_cron() {
	if ( function_exists( 'wp_doing_cron' ) ) {
		return wp_doing_cron();
	}

	return defined( 'DOING_CRON' ) && DOING_CRON;
}

/**
 * Check if the PHP execution-time limit counts wall-clock time.
 *
 * Most Linux builds count only CPU time. The time that a request waits for an HTTP call or sleep() does not count,
 * and remote conversion uses little CPU time, so it does not reach the limit there.
 * Windows counts wall-clock time. Linux ZTS builds count wall-clock time since PHP 8.1.18 and 8.2.5 (ZEND_MAX_EXECUTION_TIMERS,
 * on by default for ZTS). On macOS only Apple Silicon builds do, since PHP 8.2.21 and 8.3.9. Every macOS build counts
 * as wall-clock here: a wrong guess only makes the steps shorter.
 *
 * @return bool
 */
function wrio_is_time_limit_wall_clock() {
	$wall_clock = in_array( PHP_OS_FAMILY, [ 'Windows', 'Darwin' ], true ) || ( 'Linux' === PHP_OS_FAMILY && PHP_ZTS && PHP_VERSION_ID >= 80205 );

	/**
	 * Filters whether the PHP execution-time limit counts wall-clock time.
	 *
	 * @param bool $wall_clock True if the time that a request waits counts toward max_execution_time.
	 */
	return (bool) apply_filters( 'wrio_time_limit_is_wall_clock', $wall_clock );
}

/**
 * Get the seconds left before the PHP execution-time limit stops the request, or before the time budget of the
 * request ends (see wrio_set_time_budget()).
 *
 * The PHP limit counts from the start of the request.
 *
 * @return float Seconds left. INF if there is no budget and the PHP limit is missing or counts only CPU time.
 */
function wrio_get_time_left() {
	global $wrio_time_budget;

	$limit  = (int) ini_get( 'max_execution_time' );
	$budget = null === $wrio_time_budget ? INF : $wrio_time_budget - microtime( true );

	if ( $limit <= 0 || ! wrio_is_time_limit_wall_clock() ) {
		return $budget;
	}

	return min( $budget, wrio_get_php_time_left( $limit ) );
}

/**
 * Get the seconds left of a PHP execution-time limit that counts wall-clock time from the start of the request.
 *
 * @param int $limit The max_execution_time value.
 *
 * @return float Seconds left.
 */
function wrio_get_php_time_left( $limit ) {
	$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

	return $limit - ( microtime( true ) - $started );
}

/**
 * Give the request a wall-clock time budget, counted from now. Then the checks of wrio_has_time_left() and the
 * HTTP timeouts of wrio_get_http_timeout() also end before it.
 *
 * The PHP limit counts only CPU time on most hosts, and a request that waits for the network uses almost none: without
 * a budget, a step of the bulk page could wait for minutes on a network that does not answer. The web server or a
 * proxy in front of it (often 60 seconds) stops such a request first.
 *
 * @param int|float|null $seconds Budget in seconds. Null: no budget.
 *
 * @return void
 */
function wrio_set_time_budget( $seconds ) {
	global $wrio_time_budget;

	$limit = (int) ini_get( 'max_execution_time' );

	// wrio_is_time_limit_wall_clock() cannot detect every build whose limit counts wall-clock time: a budget that ends
	// after the limit would let the limit stop a sleep() or an HTTP call with a fatal error.
	if ( null !== $seconds && $limit > 0 ) {
		$seconds = min( $seconds, wrio_get_php_time_left( $limit ) );
	}

	$wrio_time_budget = null === $seconds ? null : microtime( true ) + $seconds;
}

/**
 * Check that the request can run for some more seconds before the PHP execution-time limit.
 *
 * The default reserve is 10 seconds, and at most a third of the limit, so a request with a low limit can still
 * start a step. A number of seconds that the caller needs is not reduced.
 *
 * @param int|null $seconds Seconds that the next step needs. Null for the default reserve.
 *
 * @return bool True if enough time is left before the PHP limit and the time budget.
 */
function wrio_has_time_left( $seconds = null ) {
	$needed = null === $seconds ? min( 10, intdiv( (int) ini_get( 'max_execution_time' ), 3 ) ) : (int) $seconds;

	return wrio_get_time_left() >= $needed;
}

/**
 * Get an HTTP timeout that ends before the PHP execution-time limit.
 *
 * Then a slow call fails with a timeout error instead of a fatal error.
 *
 * @param int $timeout Timeout in seconds when enough time is left.
 *
 * @return int<0, max> Timeout in seconds. 0 if less than 1 second is left for the call. Then do not send the call.
 */
function wrio_get_http_timeout( $timeout ) {
	// Keep 3 seconds to save the result after the call.
	return max( 0, (int) min( $timeout, floor( wrio_get_time_left() - 3 ) ) );
}

/**
 * Convert full URL paths to absolute paths.
 *
 * @param string $url abs url https://site.com/wp-conent/uploads/10/05/image.jpeg
 *
 * @return string|null abs path var/site.com/www/wp-conent/uploads/10/05/image.jpeg, if failure null
 * @since  1.4.0
 */
function wrio_url_to_abs_path( $url ) {
	if ( empty( $url ) ) {
		return null;
	}

	if ( strpos( $url, '?' ) !== false ) {
		$url_parts = explode( '?', $url );

		if ( 2 == sizeof( $url_parts ) ) {
			$url = $url_parts[0];
		}
	}

	$url = rtrim( $url, '/' );

	// todo: if the external site, then it will not work
	return str_replace( get_site_url(), untrailingslashit( wp_normalize_path( ABSPATH ) ), $url );
}

/**
 * Convert relative urls to absolute
 *
 * @param string $url relative url /wp-conent/uploads/10/05/image.jpeg
 *
 * @return string abs url https://site.com/wp-conent/uploads/10/05/image.jpeg
 * @since  1.4.0
 */
function wrio_rel_to_abs_url( $url ) {
	require_once WRIO_PLUGIN_DIR . '/libs/class-rio-relative-to-abs-uri.php';

	return WRIO\Paths\phpUri::parse( get_site_url() )->join( $url );
}

/**
 * Converts relative urls to absolute paths
 *
 * @param string $url relative url /wp-conent/uploads/10/05/image.jpeg
 *
 * @return string abs path var/site.com/www/wp-conent/uploads/10/05/image.jpeg
 * @since  1.4.0
 */
function wrio_rel_url_to_abs_path( $url ) {
	$abs_url = wrio_rel_to_abs_url( $url );

	return wrio_url_to_abs_path( $abs_url );
}

/**
 * @param      $string
 * @param bool $capitalize_first_character
 *
 * @return mixed|string
 * @since  1.1
 */
function wrio_dashes_to_camel_case( $string, $capitalize_first_character = false ) {

	$str = str_replace( '-', '_', ucwords( $string, '-' ) );

	if ( ! $capitalize_first_character ) {
		$str = lcfirst( $str );
	}

	return $str;
}

/**
 * Alternative php functions basename. Our function works with сyrillic file names.
 *
 * @param string $str file path
 *
 * @return string|string[]|null
 * @since  1.3.0
 */
/*
function wrio_basename( $str ) {
	return preg_replace( '/^.+[\\\\\\/]/', '', $str );
}*/

/**
 * @return bool
 * @since  1.3.0
 */
function wrio_is_active_nextgen_gallery() {
	// The add-ons call this on front-end requests too, where WordPress does not load the plugin functions.
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( 'nextgen-gallery/nggallery.php' );
}

/**
 * Blocks web access to a plugin folder with an `.htaccess` deny rule and an `index.php`.
 *
 * Writes each file when it is missing. Replaces an `.htaccess` only when the plugin wrote it:
 * it starts with the marker, or it is the old `deny from all` file of the logger.
 * nginx does not read `.htaccess`; it needs a server rule.
 *
 * @param string $dir Folder path.
 *
 * @return void
 */
function wrio_protect_dir( string $dir ): void {
	$dir = trailingslashit( $dir );

	if ( ! is_dir( $dir ) ) {
		return;
	}

	$marker = '# Robin Image Optimizer: deny web access';
	$rules  = $marker . "\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	$current = file_exists( $dir . '.htaccess' ) ? (string) @file_get_contents( $dir . '.htaccess' ) : null;

	if ( null === $current || ( $rules !== $current && ( 0 === strpos( $current, $marker ) || 'deny from all' === strtolower( trim( $current ) ) ) ) ) {
		@file_put_contents( $dir . '.htaccess', $rules );
	}

	if ( ! file_exists( $dir . 'index.php' ) ) {
		@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
	}
	// phpcs:enable
}

/**
 * @param string $dir
 *
 * @return bool
 * @since  1.1
 */
function wrio_rmdir( $dir ) {
	if ( is_dir( $dir ) ) {
		$scn = scandir( $dir );

		foreach ( $scn as $files ) {
			if ( $files !== '.' ) {
				if ( $files !== '..' ) {
					if ( ! is_dir( $dir . '/' . $files ) ) {
						@unlink( $dir . '/' . $files );
					} else {
						wrio_rmdir( $dir . '/' . $files );
						if ( is_dir( $dir . '/' . $files ) ) {
							@rmdir( $dir . '/' . $files );
						}
					}
				}
			}
		}
		@rmdir( $dir );

		return ! is_dir( $dir );
	}

	return false;
}

/**
 * Пересчёт размера файла в байтах на человекопонятный вид
 *
 * Пример: вводим 67894 байт, получаем 67.8 KB
 * Пример: вводим 6789477 байт, получаем 6.7 MB
 *
 * @param int $size размер файла в байтах
 *
 * @return string
 */
function wrio_convert_bytes( $size ) {
	if ( ! $size ) {
		return 0;
	}
	$base   = log( $size ) / log( 1024 );
	$suffix = [ '', 'KB', 'MB', 'GB', 'TB' ];
	$f_base = intval( floor( $base ) );

	return round( pow( 1024, $base - floor( $base ) ), 2 ) . ' ' . $suffix[ $f_base ];
}

/**
 * Генерирует хеш строку
 *
 * @param int $length
 *
 * @return string
 */
function wrio_generate_random_string( $length = 10 ) {
	$characters       = '0123456789abcdefghiklmnopqrstuvwxyz';
	$charactersLength = strlen( $characters );
	$randomString     = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$randomString .= $characters[ rand( 0, $charactersLength - 1 ) ];
	}

	return $randomString;
}

/**
 * in priorities checks its license.
 *
 * @return bool
 * @since  1.3.0
 */
function wrio_is_license_activate() {
	return wrio_is_clearfy_license_activate() || ( WRIO_Plugin::app()->premium->is_activate() && WRIO_Plugin::app()->premium->is_active() );
}

/**
 *
 * @return bool
 * @since  1.3.0
 */
function wrio_is_clearfy_license_activate() {
	if ( class_exists( 'WCL_Plugin' ) ) {
		if ( version_compare( WCL_PLUGIN_VERSION, '1.6.3', '>=' ) ) {
			if ( WCL_Plugin::app()->premium->is_activate() ) {
				$plan_id = WCL_Plugin::app()->premium->get_license()->get_plan_id();

				return '4710' === $plan_id || '3530' === $plan_id;
			}
		} else {
			$current_license = WCL_Licensing::instance()->getStorage()->getLicense();

			if ( ! empty( $current_license ) && ! empty( $current_license->id ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * checks its license in priorities.
 *
 * @return bool
 * @since  1.3.0
 */
function wrio_is_license_active() {
	if ( wrio_is_clearfy_license_activate() ) {
		if ( version_compare( WCL_PLUGIN_VERSION, '1.6.3', '>=' ) ) {
			return WCL_Plugin::app()->premium->is_active();
		} else {
			return WCL_Licensing::instance()->isLicenseValid();
		}
	}

	return WRIO_Plugin::app()->premium->is_activate() && WRIO_Plugin::app()->premium->is_active();
}

/**
 * return it key.
 *
 * @return string|null
 * @since  1.3.0
 */
function wrio_get_license_key() {
	if ( ! wrio_is_license_activate() ) {
		return null;
	}

	if ( wrio_is_clearfy_license_activate() ) {
		if ( version_compare( WCL_PLUGIN_VERSION, '1.6.3', '>=' ) ) {
			return WCL_Plugin::app()->premium->get_license()->get_key();
		} else {
			return WCL_Licensing::instance()->getStorage()->getLicense()->secret_key;
		}
	}

	return WRIO_Plugin::app()->premium->get_license()->get_key();
	/*
		if ( WRIO_Plugin::app()->premium->is_activate() ) {
		return WRIO_Plugin::app()->premium->get_license()->get_key();
	} else {
		if ( wrio_is_clearfy_license_activate() ) {
			if ( version_compare( WCL_PLUGIN_VERSION, '1.6.3', '>=' ) ) {
				return WCL_Plugin::app()->premium->get_license()->get_key();
			} else {
				return WCL_Licensing::instance()->getStorage()->getLicense()->secret_key;
			}
		} else {
			return null;
		}
	}
	*/
}

/**
 * Get the license source (freemius or sdk).
 *
 * @return string|null 'freemius', 'sdk', or null if no license.
 * @since  1.6.0
 */
function wrio_get_license_source() {
	if ( ! wrio_is_license_activate() ) {
		return null;
	}

	return WRIO_Plugin::app()->premium->get_license()->get_source();
}

/**
 * @return number|null
 * @since  1.3.0
 */
function wrio_get_freemius_plugin_id() {
	if ( wrio_is_clearfy_license_activate() ) {
		return WCL_Plugin::app()->getPluginInfoAttr( 'freemius_plugin_id' );
	}

	return WRIO_Plugin::app()->premium->get_setting( 'plugin_id' );
}

/**
 * Make sure the WordPress media functions are loaded.
 *
 * The wp_generate_attachment_metadata() function lives in wp-admin/includes/image.php,
 * which is not loaded on cron, REST or front-end requests.
 *
 * @return bool True when wp_generate_attachment_metadata() can be called.
 */
function wrio_load_media_functions() {
	if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
		return true;
	}

	$image_file = ABSPATH . 'wp-admin/includes/image.php';

	if ( ! file_exists( $image_file ) ) {
		return false;
	}

	require_once $image_file;

	return function_exists( 'wp_generate_attachment_metadata' );
}

/**
 * Get size information for all currently-registered image sizes.
 *
 * @return array $sizes Data for all currently-registered image sizes.
 * @uses   get_intermediate_image_sizes()
 * @global $_wp_additional_image_sizes
 */
function wrio_get_image_sizes() {
	global $_wp_additional_image_sizes;

	$sizes = [];

	foreach ( get_intermediate_image_sizes() as $_size ) {
		if ( in_array( $_size, [ 'thumbnail', 'medium', 'medium_large', 'large' ] ) ) {
			$sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
			$sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
			$sizes[ $_size ]['crop']   = (bool) get_option( "{$_size}_crop" );
		} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
			$sizes[ $_size ] = [
				'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
				'height' => $_wp_additional_image_sizes[ $_size ]['height'],
				'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
			];
		}
	}

	return $sizes;
}

/**
 * Check whether there are some migrations left to be processed.
 *
 * @return bool
 * @throws Exception
 * @since  1.3.0
 */
function wbcr_rio_has_meta_to_migrate() {

	$db_version = RIO_Process_Queue::get_db_version();

	if ( 2 === $db_version ) {
		return false;
	}

	// Low number to limit resources consumption
	$attachments = wbcr_rio_get_meta_to_migrate( 5 );

	if ( isset( $attachments->posts ) && count( $attachments->posts ) > 0 ) {
		return true;
	}

	if ( 1 === $db_version ) {
		RIO_Process_Queue::update_db_version( 2 );
	}

	return false;
}

/**
 * Get list of meta to migrate.
 *
 * @param int $limit Attachment limit per page.
 *
 * @return WP_Query
 * @since  1.3.0
 */
function wbcr_rio_get_meta_to_migrate( $limit = 0 ) {
	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => [ 'image/jpeg', 'image/gif', 'image/png' ],
		'posts_per_page' => - 1,
		'meta_query'     => [
			[
				'key'     => 'wio_optimized',
				'compare' => 'EXISTS',
			],
		],
	];

	if ( $limit ) {
		$args['posts_per_page'] = $limit;
	}

	return new WP_Query( $args );
}

/**
 * @return string
 * @since  1.3.0
 */
function wrio_get_meta_migration_notice_text() {
	$nonce = wp_create_nonce( 'wrio-meta-migrations' );

	return sprintf(
		// translators: %1$s is the opening anchor tag, %2$s is the closing anchor tag.
		__( 'The database schema has changed. %1$sUpgrade now%2$s to the latest version.', 'robin-image-optimizer' ),
		'<a href="#" id="wbcr-wio-meta-migration-action" class="button button-default" data-nonce="' . esc_attr( $nonce ) . '">',
		'</a>'
	);
}

/**
 * The app and WP-CLI names refer to the same background queue.
 *
 * @param string $scope Scope name.
 * @return string
 */
function wrio_processing_scope( string $scope ): string {
	return 'nextgen-gallery' === $scope ? 'nextgen' : $scope;
}

/**
 * @param string $scope
 *
 * @return WRIO_Folder_Processing|WRIO_Media_Processing|WRIO_Media_Processing_Webp|WRIO_Media_Processing_Avif|WRIO_Nextgen_Processing|null
 */
function wrio_get_processing_class( $scope ) {
	$scope  = wrio_processing_scope( $scope );
	$object = null;
	switch ( $scope ) {
		case 'media-library':
			if ( class_exists( 'WRIO_Media_Processing' ) ) {
				$object = new WRIO_Media_Processing( $scope );
			}
			break;
		case 'media-library_webp':
			if ( class_exists( 'WRIO_Media_Processing_Webp' ) ) {
				$object = new WRIO_Media_Processing_Webp( $scope );
			}
			break;
		case 'media-library_avif':
			if ( class_exists( 'WRIO_Media_Processing_Avif' ) ) {
				$object = new WRIO_Media_Processing_Avif( $scope );
			}
			break;
		case 'custom-folders':
			if ( class_exists( 'WRIO_Folder_Processing' ) ) {
				$object = new WRIO_Folder_Processing( $scope );
			}
			break;
		case 'nextgen':
			if ( class_exists( 'WRIO_Nextgen_Processing' ) ) {
				$object = new WRIO_Nextgen_Processing( 'nextgen' );
			}
			break;
	}

	return $object;
}

/**
 * Construct every background queue, so that its hooks exist: the admin-ajax loopback (maybe_handle()) and the
 * WP-Cron health check (handle_cron_healthcheck()). These requests construct no queue of their own.
 *
 * @return void
 */
function wrio_register_processing() {
	foreach ( [ 'media-library', 'media-library_webp', 'media-library_avif', 'custom-folders', 'nextgen' ] as $scope ) {
		wrio_get_processing_class( $scope );
	}
}

/**
 * Runs the waiting work now, in this request, without a loopback request: every background queue, then the
 * scheduled run. For a system cron job: `wp robin queue run` or `do_action( 'wrio/queue/run' )`.
 *
 * @return array<string, bool> Scope => whether nothing waits in it now.
 */
function wrio_run_queue() {
	$done = [];

	foreach ( [ 'media-library', 'media-library_webp', 'media-library_avif', 'custom-folders', 'nextgen' ] as $scope ) {
		$processing = wrio_get_processing_class( $scope );

		if ( $processing ) {
			$done[ $scope ] = $processing->run_now();
		}
	}

	$scope = WRIO_Plugin::app()->getPopulateOption( 'cron_running', false );

	if ( is_string( $scope ) && '' !== $scope ) {
		$cron   = new WRIO_Cron();
		$remain = PHP_INT_MAX;

		// One step after another while each step leaves less work; a step without progress (images that wait for
		// their retry, a backoff) ends the loop.
		do {
			$result = $cron->run_step();
			$before = $remain;
			$remain = is_array( $result ) && isset( $result['remain'] ) ? (int) $result['remain'] : 0;
		} while ( $remain > 0 && $remain < $before );

		remove_action( 'wrio/cron/optimization_process', [ $cron, 'optimization_process' ] );
		remove_filter( 'cron_schedules', [ $cron, 'intervals' ], 100 );

		$done[ $scope ] = ! WRIO_Plugin::app()->getPopulateOption( 'cron_running', false );
	}

	return $done;
}

/**
 * Tests that the site can send a request to its own admin-ajax.php, as Background optimization does. Saves the
 * result and its time in the option wrio_loopback_test.
 *
 * @return true|WP_Error
 */
function wrio_test_loopback() {
	$token = wp_generate_password( 24, false );
	// One key per test: two saves at the same time do not overwrite each other's token.
	set_transient( 'wrio_loopback_token_' . $token, 1, MINUTE_IN_SECONDS );

	// The same URL as the queue's loopback request (WRIO_Processing::dispatch()).
	$url      = apply_filters( 'wrio_optimize_process_media_library_query_url', admin_url( 'admin-ajax.php' ) );
	$response = wp_remote_post(
		$url,
		[
			'timeout'   => 10,
			'body'      => [
				'action' => 'wrio_loopback_test',
				'token'  => $token,
			],
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		]
	);

	delete_transient( 'wrio_loopback_token_' . $token );

	if ( is_wp_error( $response ) ) {
		$reason = $response->get_error_message();
	} elseif ( trim( wp_remote_retrieve_body( $response ) ) !== $token ) {
		// translators: %d is an HTTP status code such as 401.
		$reason = sprintf( __( 'the request answered with HTTP status %d', 'robin-image-optimizer' ), (int) wp_remote_retrieve_response_code( $response ) );
	} else {
		$reason = '';
	}

	update_option(
		'wrio_loopback_test',
		[
			'ok'    => '' === $reason,
			'time'  => time(),
			'error' => $reason,
		],
		false
	);

	if ( '' === $reason ) {
		return true;
	}

	return new WP_Error(
		'robin_loopback_failed',
		sprintf(
			// translators: 1: the reason, 2: the WP-CLI command.
			__( 'Background optimization needs a request from the site to itself, and that request failed: %1$s. A security plugin, HTTP authentication or the firewall of the host can block it. Keep Scheduled optimization, or let a system cron job run %2$s.', 'robin-image-optimizer' ),
			$reason,
			'wp robin queue run'
		)
	);
}

/**
 * The health test of Background optimization: the loopback request, then WP-Cron, which restarts a queue that the
 * loopback request cannot. Saves the result in the option wrio_loopback_test, and plans the same test again
 * in a month (the event does nothing when Background optimization is off).
 *
 * @return true|WP_Error
 */
function wrio_test_background() {
	// The WP-Cron test: a one-time event that WP-Cron must run. It is asynchronous, so a system cron job that runs
	// every few minutes passes. While the event waits for more than an hour, wrio_cron_runs() below fails.
	if ( ! wp_next_scheduled( WRIO_Cron::PROBE_HOOK ) ) {
		wp_schedule_single_event( time(), WRIO_Cron::PROBE_HOOK );
	}

	$result = wrio_test_loopback();

	// Only a late event fails the test: a system cron job that runs WP-Cron with DISABLE_WP_CRON passes.
	if ( true === $result && ! wrio_cron_runs() ) {
		$result = new WP_Error(
			'robin_cron_late',
			sprintf(
				// translators: 1: wp-cron.php, 2: the WP-CLI command.
				__( 'Background optimization needs WP-Cron to restart a stopped queue, and the WP-Cron events of this site are more than an hour late. Keep Scheduled optimization, or let a system cron job call %1$s or run %2$s.', 'robin-image-optimizer' ),
				'wp-cron.php',
				'wp robin queue run'
			)
		);

		update_option(
			'wrio_loopback_test',
			[
				'ok'    => false,
				'time'  => time(),
				'error' => 'WP-Cron events are late',
			],
			false
		);
	}

	wp_clear_scheduled_hook( 'wrio/cron/background_test' );
	wp_schedule_single_event( time() + MONTH_IN_SECONDS, 'wrio/cron/background_test' );

	return $result;
}

/**
 * Answers the request of wrio_test_loopback().
 *
 * @return void
 */
function wrio_loopback_test_handler() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the one-time token is the check; the request has no user.
	$sent = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

	// The token is the key: a value of another test cannot match it. Only letters and digits, as generated.
	if ( ! preg_match( '/^[A-Za-z0-9]{24}$/', $sent ) || ! get_transient( 'wrio_loopback_token_' . $sent ) ) {
		wp_die( '', '', 403 );
	}

	echo esc_html( $sent );
	wp_die();
}

/**
 * The default of a setting, from WRIO_Settings::schema(): the one source for the settings page, the optimizer and
 * the abilities. Use it as the default of getPopulateOption() for a setting of the schema.
 *
 * @param string $name Option name without the prefix.
 *
 * @return mixed The typed default, null for an unknown option.
 */
function wrio_get_default( $name ) {
	static $defaults = null;

	if ( null === $defaults ) {
		if ( ! class_exists( 'WRIO_Settings' ) ) {
			require_once WRIO_PLUGIN_DIR . '/admin/class-rio-settings.php';
		}
		$defaults = array_map( fn( $field ) => $field['default'], WRIO_Settings::schema() );
	}

	return $defaults[ $name ] ?? null;
}

/**
 * Whether Robin optimizes an image or one of its sizes: the wrio_should_optimize filter. The rules SQL reads (the
 * image's own option, the paths) are in WRIO_Image_Query::get_exclusion_clause().
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Image size: original for the main file.
 *
 * @return bool
 */
function wrio_should_optimize( $attachment_id, $size = 'original' ) {
	return '' === wrio_skip_reason( $attachment_id, $size );
}

/**
 * Why wrio_should_optimize() skips an image or size: filter or ''.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Image size: original for the main file.
 *
 * @return string
 */
function wrio_skip_reason( $attachment_id, $size = 'original' ) {
	/**
	 * Filters whether Robin optimizes an image or one of its sizes.
	 *
	 * @param bool   $optimize      False skips it.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Image size: original for the main file.
	 */
	return apply_filters( 'wrio_should_optimize', true, (int) $attachment_id, (string) $size ) ? '' : 'filter';
}

/**
 * Writes a ">" in a quoted <img> attribute value as "&gt;". The browser shows the same text, but wptexturize() (10)
 * takes the ">" of alt="a > b" for the end of the tag and turns the quotes after it into curly quotes: the image loses
 * its src.
 *
 * @param string $content Post content.
 *
 * @return string
 */
function wrio_escape_img_attribute_gt( $content ) {
	if ( false === stripos( $content, '<img' ) ) {
		return $content;
	}

	// The tag processor reads the tags as a browser does: an unquoted alt=Bob's opens no quote, and the text of a
	// <script> holds no tags. set_attribute() escapes the value, so the ">" of a text attribute becomes "&gt;". For a
	// URL (src, srcset) it runs esc_url(), which drops a ">": write it as "%3E", as the browser sends it.
	$tags = new WP_HTML_Tag_Processor( $content );
	while ( $tags->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
		foreach ( $tags->get_attribute_names_with_prefix( '' ) ?? [] as $name ) {
			$value = $tags->get_attribute( $name );
			if ( is_string( $value ) && false !== strpos( $value, '>' ) ) {
				$tags->set_attribute( $name, in_array( $name, wp_kses_uri_attributes(), true ) ? str_replace( '>', '%3E', $value ) : $value );
			}
		}
	}

	return $tags->get_updated_html();
}

/**
 * Writes the fetchpriority and loading attributes of each <img> as WordPress reads them (lowercase name and value, no
 * spaces), before wp_filter_content_tags() (12). WordPress does not read `fetchpriority = "low"` or
 * `LOADING="lazy"`, and then adds fetchpriority="high" itself in front of them.
 *
 * @param string $content Post content.
 *
 * @return string
 */
function wrio_normalize_priority_attributes( $content ) {
	if ( false === stripos( $content, '<img' ) ) {
		return $content;
	}

	$tags     = new WP_HTML_Tag_Processor( $content );
	$priority = false;

	while ( $tags->next_tag( [ 'tag_name' => 'img' ] ) ) {
		foreach ( [ 'fetchpriority', 'loading' ] as $name ) {
			$value = $tags->get_attribute( $name );

			// The lowercase name and value: WordPress reads both case-sensitively, so "Lazy" is not lazy to it. The
			// values are keywords (lazy, eager, high, low, auto), like HTML reads them.
			if ( is_string( $value ) ) {
				$tags->set_attribute( $name, strtolower( trim( $value ) ) );
				$priority = $priority || 'fetchpriority' === $name;
			}
		}
	}

	// Content that sets a priority itself gets no fetchpriority="high" from WordPress, also not on a later image, as
	// wrio_add_fetchpriority() adds none to it.
	if ( $priority ) {
		wp_high_priority_element_flag( false );
	}

	return $tags->get_updated_html();
}

/**
 * Attribute that marks the fetchpriority="high" Robin added, until the page buffer reads the whole page.
 */
const WRIO_PRIORITY_MARKER = 'data-wrio-priority';

/**
 * Adds fetchpriority="high" to the first eligible image of the page, when the setting fetchpriority_first_image is
 * on. The page is the main query: its posts in the order the loop shows them. The first eligible image is the first
 * <img> that is not lazy. The page gets no value when one of its images already has one, or when WordPress already
 * gave one, such as to the featured image. Picture delivery keeps the attribute on the <img> it wraps.
 *
 * Each post of the loop runs this filter. A post without an eligible image (text only, or lazy images only) leaves
 * the choice to the next post; the filter goes away once the page has its value.
 *
 * @param string $content Post content.
 *
 * @return string
 */
function wrio_add_fetchpriority( $content ) {
	// ponytail: the first image of the main query is a guess of the LCP image; the setting is off by default.
	if ( is_admin() || is_feed() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// Only the posts of the main query: a related-posts loop inside it shares in_the_loop() and is_main_query().
	if ( (int) get_the_ID() !== (int) ( $GLOBALS['wp_query']->post->ID ?? 0 ) ) {
		return $content;
	}

	// WordPress keeps one high value per page with this flag: false when it gave one, such as to a featured image.
	if ( ! wp_high_priority_element_flag() || wrio_page_has_fetchpriority( $content ) ) {
		remove_filter( 'the_content', 'wrio_add_fetchpriority', 20 );

		return $content;
	}

	// The tag processor reads the attributes as a browser does: a ">" in a quoted alt does not end the tag, and
	// spaces or capitals in loading = "Lazy" do not hide the value.
	$images = new WP_HTML_Tag_Processor( $content );
	while ( $images->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
		if ( 'lazy' !== strtolower( trim( (string) $images->get_attribute( 'loading' ) ) ) ) {
			$images->set_attribute( 'fetchpriority', 'high' );
			// wrio_resolve_priority() reads the whole page: it removes this value when another image has one.
			$images->set_attribute( WRIO_PRIORITY_MARKER, '1' );
			// WordPress then gives no high value to a large image in a later post.
			wp_high_priority_element_flag( false );
			remove_filter( 'the_content', 'wrio_add_fetchpriority', 20 );

			return $images->get_updated_html();
		}
	}

	return $content;
}

/**
 * Content followed by the stored content of the synced patterns it uses: get_the_content() keeps only their
 * <!-- wp:block {"ref":…} /--> reference. Read from the database, not rendered, so no block or shortcode runs twice.
 *
 * @param string $content Post content.
 * @param int    $depth   Nesting level: a pattern can use another one.
 *
 * @return string
 */
function wrio_with_synced_patterns( $content, $depth = 0 ) {
	// ponytail: shortcodes and dynamic blocks of later posts are not rendered; their attributes stay unseen.
	if ( $depth > 2 || ! preg_match_all( '/<!--\s+wp:block\s+(\{.*?\})\s+\/?-->/', $content, $matches ) ) {
		return $content;
	}

	foreach ( $matches[1] as $attributes ) {
		$ref     = (array) json_decode( $attributes, true );
		$pattern = isset( $ref['ref'] ) ? get_post( (int) $ref['ref'] ) : null;
		// As render_block_core_block(): a password-protected pattern shows nothing.
		if ( $pattern && 'wp_block' === $pattern->post_type && 'publish' === $pattern->post_status && empty( $pattern->post_password ) ) {
			$content .= wrio_with_synced_patterns( $pattern->post_content, $depth + 1 );
		}
	}

	return $content;
}

/**
 * Starts one output buffer for the page, so wrio_resolve_priority() sees the HTML that the content filters do not:
 * shortcodes, dynamic blocks and patterns of later posts, page builders and templates. Front end only.
 *
 * @return void
 */
function wrio_start_priority_buffer() {
	if ( is_admin() || is_feed() || wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request() ) {
		return;
	}

	ob_start(
		static function ( $html ) {
			return wrio_is_html_response( headers_list() ) ? wrio_resolve_priority( (string) $html ) : $html;
		}
	);
}

/**
 * Whether the response is HTML: no Content-Type header yet means the default text/html of PHP.
 *
 * @param string[] $headers Headers sent, as headers_list() gives them.
 *
 * @return bool
 */
function wrio_is_html_response( array $headers ) {
	foreach ( $headers as $header ) {
		if ( 0 === stripos( $header, 'content-type:' ) ) {
			return false !== stripos( $header, 'html' );
		}
	}

	return true;
}

/**
 * Keeps Robin's fetchpriority="high" only when no other image of the page has a value, and removes the marker that
 * wrio_add_fetchpriority() wrote. Other markup stays as it is.
 *
 * @param string $html The page.
 *
 * @return string
 */
function wrio_resolve_priority( $html ) {
	if ( false === strpos( $html, WRIO_PRIORITY_MARKER ) ) {
		return $html;
	}

	$other = false;
	$page  = new WP_HTML_Tag_Processor( $html );
	while ( ! $other && $page->next_tag() ) {
		$other = in_array( $page->get_tag(), [ 'IMG', 'SOURCE', 'PICTURE' ], true )
			&& null !== $page->get_attribute( 'fetchpriority' )
			&& null === $page->get_attribute( WRIO_PRIORITY_MARKER );
	}

	// The marker as wrio_add_fetchpriority() wrote it: the page keeps its bytes.
	if ( ! $other ) {
		return str_replace( ' ' . WRIO_PRIORITY_MARKER . '="1"', '', $html );
	}

	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
		if ( null !== $tags->get_attribute( WRIO_PRIORITY_MARKER ) ) {
			$tags->remove_attribute( WRIO_PRIORITY_MARKER );
			$tags->remove_attribute( 'fetchpriority' );
		}
	}

	return $tags->get_updated_html();
}

/**
 * Whether an image of the page already has a fetchpriority value: in this content, or in a post of the main query.
 * Each post as the page shows it: an archive teaser stops at "more", and a password-locked post uses its password
 * form.
 *
 * @param string $content Content of the current post, after the earlier filters.
 *
 * @return bool
 */
function wrio_page_has_fetchpriority( $content ) {
	// ponytail: reads every post of the loop again for each post without an eligible image; a page has few posts.
	$html = $content;
	foreach ( isset( $GLOBALS['wp_query']->posts ) ? (array) $GLOBALS['wp_query']->posts : [] as $post ) {
		$html .= wrio_with_synced_patterns( get_the_content( null, false, $post ) );
	}

	$page = new WP_HTML_Tag_Processor( $html );
	while ( $page->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
		if ( null !== $page->get_attribute( 'fetchpriority' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the current request came through Cloudflare (it adds the CF-Ray header).
 *
 * @return bool
 */
function wrio_behind_cloudflare() {
	return ! empty( $_SERVER['HTTP_CF_RAY'] );
}

/**
 * Whether WP-Cron runs: false when an event is more than an hour late, as WordPress Site Health tests it.
 *
 * @return bool
 */
function wrio_cron_runs() {
	$crons = _get_cron_array();

	if ( empty( $crons ) ) {
		return true;
	}

	return min( array_keys( $crons ) ) > time() - HOUR_IN_SECONDS;
}

/**
 * @param bool $for_sql
 *
 * @return string|array<string>
 */
function wrio_get_allowed_formats( $for_sql = false ) {
	$allowed_formats     = explode( ',', WRIO_Plugin::app()->getPopulateOption( 'allowed_formats', 'image/jpeg,image/png,image/gif' ) );
	$allowed_formats_sql = [];
	foreach ( $allowed_formats as $k => $format ) {
		$format = esc_sql( $format );

		$allowed_formats_sql[ $k ] = "'{$format}'";
	}
	$allowed_formats_sql = implode( ',', $allowed_formats_sql );

	return $for_sql ? $allowed_formats_sql : $allowed_formats;
}

/**
 * The allowed formats that are converted to WebP and AVIF: all but GIF.
 *
 * Delivery serves the converted files of JPEG and PNG images only, and a converted animated GIF loses its animation.
 *
 * @param bool $for_sql True for a quoted list to use in SQL.
 *
 * @return ($for_sql is true ? string : list<string>)
 */
function wrio_get_conversion_formats( bool $for_sql = false ) {
	$formats = array_values( array_diff( (array) wrio_get_allowed_formats(), [ 'image/gif' ] ) );

	if ( ! $for_sql ) {
		return $formats;
	}

	return empty( $formats ) ? "''" : "'" . implode( "','", array_map( 'esc_sql', $formats ) ) . "'";
}

/**
 * Get the currently enabled image conversion formats.
 *
 * @return string[] Array of enabled format names ('webp', 'avif'). Empty if none enabled.
 * @since 1.9.0
 */
function wrio_get_conversion_format() {
	return WRIO_Formats::get_enabled_formats();
}

/**
 * Check if format conversion is currently enabled.
 *
 * @return bool True if WebP or AVIF conversion is enabled, false otherwise.
 * @since 1.9.0
 */
function wrio_is_format_conversion_enabled() {
	return WRIO_Formats::is_format_conversion_enabled();
}

/**
 * Check if AVIF format is available (requires premium license).
 *
 * @return bool True if AVIF format is available, false otherwise.
 * @since 1.9.0
 */
function wrio_is_avif_available() {
	return wrio_is_license_activate();
}

/**
 * Read file size reliably within the current request.
 * PHP caches stat() results; we clear cache for this path.
 *
 * @param mixed $file_path The file path.
 *
 * @return int
 */
function wrio_get_file_size( $file_path ) {
	if ( empty( $file_path ) || ! is_string( $file_path ) ) {
		return 0;
	}

	clearstatcache( true, $file_path );
	return file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;
}

/**
 * Whether the current user closed the AVIF upsell banner.
 *
 * @return bool
 */
function wrio_is_avif_banner_dismissed() {
	return (bool) get_user_meta( get_current_user_id(), 'wrio_avif_banner_dismissed', true );
}

/**
 * Flushes the page cache of every known caching plugin that is active, so visitors get the
 * new image URLs after a settings change or a migration. A site can run more than one cache.
 *
 * @return void
 */
function wrio_flush_page_cache() {
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		if ( is_multisite() ) {
			wp_cache_clear_cache( get_current_blog_id() );
		} else {
			wp_cache_clear_cache();
		}
	}

	if ( has_action( 'cachify_flush_cache' ) ) {
		do_action( 'cachify_flush_cache' );
	}

	if ( function_exists( 'w3tc_pgcache_flush' ) ) {
		w3tc_pgcache_flush();
	}

	if ( function_exists( 'wp_fast_cache_bulk_delete_all' ) ) {
		wp_fast_cache_bulk_delete_all();
	}

	if ( class_exists( '\WpFastestCache' ) ) {
		$wpfc = new \WpFastestCache();
		$wpfc->deleteCache();
	}

	if ( class_exists( 'c_ws_plugin__qcache_purging_routines' ) ) {
		\c_ws_plugin__qcache_purging_routines::purge_cache_dir();
	}

	if ( class_exists( 'zencache' ) ) {
		\zencache::clear();
	}

	if ( class_exists( 'comet_cache' ) ) {
		\comet_cache::clear();
	}

	if ( class_exists( '\WCL_Cache_Helpers' ) ) {
		\WCL_Cache_Helpers::deleteCache();
	}

	if ( class_exists( '\WpeCommon' ) ) {
		\WpeCommon::purge_varnish_cache();
	}

	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'wpo_cache_flush' ) ) {
		wpo_cache_flush();
	}

	// LiteSpeed Cache, Cache Enabler, Breeze and Super Page Cache purge through their own actions.
	do_action( 'litespeed_purge_all' );
	do_action( 'cache_enabler_clear_complete_cache' );
	do_action( 'breeze_clear_all_cache' );
	do_action( 'swcfpc_purge_cache' );

	// WP Super Cache without its wp_cache_clear_cache() (old versions).
	if ( ! function_exists( 'wp_cache_clear_cache' ) && file_exists( WP_CONTENT_DIR . '/wp-cache-config.php' ) && function_exists( 'prune_super_cache' ) ) {
		global $cache_path;
		if ( is_multisite() && function_exists( 'get_supercache_dir' ) ) {
			prune_super_cache( get_supercache_dir( get_current_blog_id() ), true );
			prune_super_cache( $cache_path . 'blogs/', true );
		} else {
			prune_super_cache( $cache_path . 'supercache/', true );
			prune_super_cache( $cache_path, true );
		}
	}
}

/**
 * The message for a file output state, translated when it is shown.
 *
 * The state rows store only a status and a code, so the message follows the language of the site and of the user.
 *
 * @param string $status  ok, skipped, failed or missing.
 * @param string $code    Problem or skip code, for example corrupt_image or not_smaller.
 * @param array  $context format (webp, avif), path, limit (bytes), megapixels, next_attempt_at (UTC), attempts,
 *                        and fallback: the message for an unknown code instead of the generic one.
 *
 * @phpstan-param array{format?: string, path?: string, limit?: int, megapixels?: int|float, next_attempt_at?: string|null, attempts?: int, fallback?: string} $context
 *
 * @return string Empty for an ok output.
 */
function wrio_error_message( $status, $code, array $context = [] ) {
	$format = strtoupper( (string) ( $context['format'] ?? '' ) );
	$format = '' === $format || 'OPTIMIZED' === $format ? __( 'The optimized file', 'robin-image-optimizer' ) : $format;

	if ( 'ok' === $status ) {
		return '';
	}

	if ( 'missing' === $status ) {
		if ( empty( $context['path'] ) ) {
			return __( 'The file no longer exists.', 'robin-image-optimizer' );
		}

		// translators: %s is the file path.
		return sprintf( __( 'The file %s no longer exists.', 'robin-image-optimizer' ), (string) $context['path'] );
	}

	$messages = [
		// Skipped outputs.
		// translators: %s is WEBP, AVIF or "The optimized file".
		'not_smaller'            => sprintf( __( '%s was not smaller than the original, so the original was kept.', 'robin-image-optimizer' ), $format ),
		// translators: %s is WEBP or AVIF.
		'same_format'            => sprintf( __( 'The file is already %s.', 'robin-image-optimizer' ), $format ),
		// translators: %s is WEBP or AVIF.
		'animated'               => sprintf( __( 'Animated images are not converted to %s.', 'robin-image-optimizer' ), $format ),
		// translators: %s is WEBP or AVIF.
		'gif'                    => sprintf( __( 'GIF images are not converted to %s.', 'robin-image-optimizer' ), $format ),
		// translators: %s is WEBP or AVIF.
		'too_large_for_format'   => sprintf( __( 'The image is too large for %s.', 'robin-image-optimizer' ), $format ),
		// Failures.
		// translators: %s is WEBP or AVIF.
		'not_in_plan'            => sprintf( __( 'Your plan does not include %s.', 'robin-image-optimizer' ), $format ),
		'invalid_license'        => __( 'The optimization server does not know your license key. Check the license on the License page.', 'robin-image-optimizer' ),
		'file_too_large'         => isset( $context['limit'] )
			// translators: %s is a file size such as 10 MB.
			? sprintf( __( 'The file is larger than %s, the limit of your plan.', 'robin-image-optimizer' ), (string) size_format( (int) $context['limit'] ) )
			: __( 'The file is larger than the limit of your plan.', 'robin-image-optimizer' ),
		'image_too_large'        => isset( $context['megapixels'] )
			// translators: %s is a number of megapixels.
			? sprintf( __( 'The image has more than %s megapixels, the limit of your plan.', 'robin-image-optimizer' ), number_format_i18n( (float) $context['megapixels'] ) )
			: __( 'The image has more pixels than the limit of your plan.', 'robin-image-optimizer' ),
		'unsupported_media_type' => __( 'The optimization server does not support this file type.', 'robin-image-optimizer' ),
		'corrupt_image'          => __( 'The image file is damaged and cannot be read.', 'robin-image-optimizer' ),
		'rate_limited'           => __( 'Too many optimization requests. Try again in a few minutes.', 'robin-image-optimizer' ),
		'server_busy'            => __( 'The optimization server is in maintenance. Try again later.', 'robin-image-optimizer' ),
		'internal_error'         => __( 'The optimization server had an unexpected error. Try again later.', 'robin-image-optimizer' ),
		'invalid_response'       => __( 'The optimization server sent an answer that is not valid.', 'robin-image-optimizer' ),
		'no_answer'              => __( 'The optimization server did not answer for this file.', 'robin-image-optimizer' ),
		'http_request_failed'    => __( 'The optimization server cannot be reached. Try again later.', 'robin-image-optimizer' ),
		'time_limit'             => __( 'The request is near the PHP execution-time limit. Optimize the image again.', 'robin-image-optimizer' ),
		'download_failed'        => __( 'The optimized file could not be downloaded.', 'robin-image-optimizer' ),
		'invalid_download'       => __( 'The downloaded file is not the optimized image.', 'robin-image-optimizer' ),
		'no_metadata'            => __( 'The image has no metadata. It may be damaged; upload it again.', 'robin-image-optimizer' ),
		'backup_failed'          => __( 'The backup of the original could not be saved. Check that the uploads folder is writable.', 'robin-image-optimizer' ),
		'path_denied'            => __( 'The file is outside the folders that this site can optimize. On a network, ask a super admin to optimize it.', 'robin-image-optimizer' ),
		'unexpected_error'       => __( 'An unexpected error stopped the optimization. The error log has the details.', 'robin-image-optimizer' ),
		'crashed'                => __( 'The optimization of this image stopped the PHP process several times, for example at the time or memory limit.', 'robin-image-optimizer' ),
		'memory_limit'           => __( 'The optimization of this image used up the PHP memory limit.', 'robin-image-optimizer' ),
		'processing_failed'      => __( 'The optimization server could not optimize the image.', 'robin-image-optimizer' ),
		'legacy_error'           => __( 'The optimization failed in an earlier version of the plugin.', 'robin-image-optimizer' ),
		// translators: %s is the file path.
		'save_failed'            => empty( $context['path'] )
			? __( 'The optimized file could not be saved.', 'robin-image-optimizer' )
			// translators: %s is the file path.
			: sprintf( __( 'The optimized file could not be saved to %s.', 'robin-image-optimizer' ), (string) $context['path'] ),
	];

	// These codes name a format. Without one (a problem of the whole request), the server detail says more.
	$named = [ 'same_format', 'animated', 'too_large_for_format', 'not_in_plan' ];

	if ( isset( $messages[ $code ] ) && ! ( isset( $context['fallback'] ) && empty( $context['format'] ) && in_array( $code, $named, true ) ) ) {
		$message = $messages[ $code ];
	} elseif ( isset( $context['fallback'] ) ) {
		$message = (string) $context['fallback'];
	} elseif ( 'skipped' === $status ) {
		// translators: %s is a skip code.
		$message = sprintf( __( 'The server skipped this output (%s).', 'robin-image-optimizer' ), $code );
	} else {
		// translators: %s is an error code.
		$message = sprintf( __( 'The optimization failed (error code: %s).', 'robin-image-optimizer' ), '' !== $code ? $code : 'unknown' );
	}

	if ( 'failed' !== $status ) {
		return $message;
	}

	$attempts = (int) ( $context['attempts'] ?? 0 );

	if ( ! empty( $context['next_attempt_at'] ) ) {
		// translators: %s is a date and time.
		$message .= ' ' . sprintf( __( 'Next retry: %s.', 'robin-image-optimizer' ), get_date_from_gmt( (string) $context['next_attempt_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

		// The attempt that the retry will be.
		return $attempts > 0 ? $message . ' ' . wrio_attempt_count( $attempts + 1 ) : $message;
	}

	if ( $attempts >= WRIO\Storage\Store::MAX_ATTEMPTS ) {
		return $message . ' ' . __( 'Automatic retries stopped. Click Optimize to try again.', 'robin-image-optimizer' ) . ' ' . wrio_attempt_count( $attempts );
	}

	return $message;
}

/**
 * "(Attempt 2/5)": an attempt of a file and the number of automatic attempts.
 *
 * @param int $attempt The attempt. More than the limit counts as the last one.
 *
 * @return string
 */
function wrio_attempt_count( $attempt ) {
	$limit = WRIO\Storage\Store::MAX_ATTEMPTS;

	// translators: %1$d is the number of an attempt to optimize the file, %2$d is the number of automatic attempts.
	return sprintf( __( '(Attempt %1$d/%2$d)', 'robin-image-optimizer' ), min( $attempt, $limit ), $limit );
}

/**
 * The short name of a failure, for the title of its group in the failures list.
 *
 * @param string $status failed or missing.
 * @param string $code   Problem code, for example corrupt_image.
 * @param string $format webp or avif, for the codes that name a format.
 *
 * @return string
 */
function wrio_error_title( $status, $code, $format = '' ) {
	if ( 'missing' === $status ) {
		return __( 'File not found', 'robin-image-optimizer' );
	}

	$format = strtoupper( $format );
	$titles = [
		// translators: %s is WEBP or AVIF.
		'not_in_plan'            => sprintf( __( '%s is not in your plan', 'robin-image-optimizer' ), $format ),
		// translators: %s is WEBP or AVIF.
		'same_format'            => sprintf( __( 'Already %s', 'robin-image-optimizer' ), $format ),
		'animated'               => __( 'Animated image', 'robin-image-optimizer' ),
		// translators: %s is WEBP or AVIF.
		'too_large_for_format'   => sprintf( __( 'Too large for %s', 'robin-image-optimizer' ), $format ),
		'invalid_license'        => __( 'License not recognized', 'robin-image-optimizer' ),
		'file_too_large'         => __( 'File exceeds the plan size limit', 'robin-image-optimizer' ),
		'image_too_large'        => __( 'Image exceeds the plan pixel limit', 'robin-image-optimizer' ),
		'unsupported_media_type' => __( 'Unsupported file type', 'robin-image-optimizer' ),
		'corrupt_image'          => __( 'Damaged image file', 'robin-image-optimizer' ),
		'rate_limited'           => __( 'Too many requests', 'robin-image-optimizer' ),
		'server_busy'            => __( 'Optimization server in maintenance', 'robin-image-optimizer' ),
		'internal_error'         => __( 'Optimization server error', 'robin-image-optimizer' ),
		'invalid_response'       => __( 'Invalid server response', 'robin-image-optimizer' ),
		'no_answer'              => __( 'No answer from the server', 'robin-image-optimizer' ),
		'http_request_failed'    => __( 'Optimization server unreachable', 'robin-image-optimizer' ),
		'time_limit'             => __( 'PHP time limit reached', 'robin-image-optimizer' ),
		'download_failed'        => __( 'Download failed', 'robin-image-optimizer' ),
		'invalid_download'       => __( 'Invalid download', 'robin-image-optimizer' ),
		'no_metadata'            => __( 'Missing image metadata', 'robin-image-optimizer' ),
		'backup_failed'          => __( 'Backup failed', 'robin-image-optimizer' ),
		'path_denied'            => __( 'Outside the allowed folders', 'robin-image-optimizer' ),
		'unexpected_error'       => __( 'Unexpected error', 'robin-image-optimizer' ),
		'processing_failed'      => __( 'Optimization failed', 'robin-image-optimizer' ),
		'legacy_error'           => __( 'Failed in an earlier version', 'robin-image-optimizer' ),
		'save_failed'            => __( 'Optimized file not saved', 'robin-image-optimizer' ),
		'crashed'                => __( 'The PHP process stopped', 'robin-image-optimizer' ),
		'memory_limit'           => __( 'PHP memory limit reached', 'robin-image-optimizer' ),
		'missing_file'           => __( 'File not found', 'robin-image-optimizer' ),
	];

	if ( isset( $titles[ $code ] ) ) {
		return $titles[ $code ];
	}

	// An answer without a problem code: its HTTP status (see WRIO\V2\Client::request()).
	if ( 'http_0' === $code ) {
		return $titles['http_request_failed'];
	}

	if ( 0 === strpos( $code, 'http_' ) ) {
		// translators: %s is an HTTP status code such as 502.
		return sprintf( __( 'Optimization server error (HTTP %s)', 'robin-image-optimizer' ), substr( $code, 5 ) );
	}

	// translators: %s is an error code.
	return sprintf( __( 'Error %s', 'robin-image-optimizer' ), '' !== $code ? $code : 'unknown' );
}
