<?php
/**
 * Checks over HTTP that the site serves the converted files.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\WEBP;

use WRIO\WEBP\HTML\Delivery;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies the bundled test image and its WebP/AVIF files to a new folder in the
 * uploads folder and gives the requests to make. The admin's browser makes them,
 * so they pass the same CDN, cache and firewall as a visitor's. The answers come
 * back to finish(), which classifies them and deletes the files. When the browser
 * cannot read the answers, the site requests the files itself.
 * A result separates what the responses prove (facts) from what can cause it (causes).
 * The last report is saved with its time: it shows what the site served then.
 *
 * @phpstan-type Request array{format: string, url: string, accept: string}
 * @phpstan-type Observed array{accept: string, status: int, redirected: bool, type: string, vary: string|null, format: string, cache: string}
 * @phpstan-type Check array{format: string, status: 'pass'|'warning'|'fail'|'error', code: string, summary: string, facts: list<string>, causes: list<string>, requests: list<Observed>}
 * @phpstan-type Report array{mode: string, via: 'browser'|'server', time: int, results: list<Check>}
 */
class Delivery_Test {

	/**
	 * Prefix of the test folders in the uploads folder.
	 */
	const FOLDER = 'wrio-delivery-test-';

	/**
	 * Option with the last report.
	 */
	const OPTION = 'wrio_delivery_test';

	/**
	 * Accept headers of current browsers that support the format.
	 */
	const ACCEPT = [
		'webp' => 'image/webp,image/png,image/svg+xml,image/*;q=0.8,video/*;q=0.8,*/*;q=0.5',
		'avif' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
	];

	/**
	 * Accept header of a browser without WebP and AVIF.
	 */
	const ACCEPT_NONE = 'image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5';

	/**
	 * User agent of the site's own requests: firewalls block the WordPress one.
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

	/**
	 * Headers that show a cache answered the request.
	 */
	const CACHE_HEADERS = [ 'age', 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache' ];

	/**
	 * Copy the test files and give the requests to make for each enabled format.
	 *
	 * @return array<string, mixed>|\WP_Error
	 * @phpstan-return array{id: string, requests: list<Request>}|\WP_Error
	 */
	public static function start() {
		$formats = self::formats();
		if ( ! $formats ) {
			return new \WP_Error( 'wrio_no_formats', __( 'Turn on WebP or AVIF conversion first.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		self::delete_stale();
		// A run that fails must not leave an older result on show.
		delete_option( self::OPTION );

		// A new folder per run: an answer that a cache keeps for an earlier URL cannot match.
		$id     = strtolower( wp_generate_password( 12, false ) );
		$dir    = self::dir( $id );
		$source = WRIOP_PLUGIN_DIR . '/assets/img/';
		$copied = wp_mkdir_p( $dir );
		foreach ( self::files() as $file ) {
			$copied = $copied && copy( $source . $file, $dir . '/' . $file );
		}
		if ( ! $copied ) {
			self::delete( $id );

			return new \WP_Error( 'wrio_test_files', __( 'The test files could not be copied to the uploads folder.', 'robin-image-optimizer' ), [ 'status' => 500 ] );
		}

		// finish() classifies with these, even when the settings change in between.
		$run = [
			'mode'     => Delivery::get_delivery_mode(),
			'formats'  => $formats,
			'requests' => self::plan( $id ),
		];
		set_transient( self::FOLDER . $id, $run, HOUR_IN_SECONDS );

		return [
			'id'       => $id,
			'requests' => $run['requests'],
		];
	}

	/**
	 * Classify the answers, delete the test files and give the report.
	 *
	 * @param string                          $id       start() id.
	 * @param list<array<string, mixed>>|null $observed What the browser got for each request of the plan, in order. Null: the site requests the files itself.
	 *
	 * @return array<string, mixed>|\WP_Error
	 * @phpstan-return Report|\WP_Error
	 */
	public static function finish( $id, $observed ) {
		$run = preg_match( '/^[a-z0-9]{12}$/', $id ) ? get_transient( self::FOLDER . $id ) : false;
		if ( ! is_array( $run ) || ! is_dir( self::dir( $id ) ) ) {
			return new \WP_Error( 'wrio_test_expired', __( 'The test files are gone. Run the test again.', 'robin-image-optimizer' ), [ 'status' => 404 ] );
		}
		/**
		 * The run start() saved.
		 *
		 * @var array{mode: string, formats: list<string>, requests: list<Request>} $run
		 */
		$plan = $run['requests'];
		$mode = $run['mode'];
		// A run that an older version planned can lack the requests of a format: its result would be a false fail.
		if ( array_diff( $run['formats'], array_column( $plan, 'format' ) ) ) {
			self::delete( $id );

			return new \WP_Error( 'wrio_test_outdated', __( 'The test started before an update. Run the test again.', 'robin-image-optimizer' ), [ 'status' => 409 ] );
		}
		$via = 'browser';
		if ( is_array( $observed ) && count( $observed ) === count( $plan ) ) {
			$responses = array_map( [ __CLASS__, 'normalize' ], $observed, $plan );
		} else {
			$via       = 'server';
			$responses = array_map(
				static function ( $request ) {
					return self::fetch( $request['url'], $request['accept'] );
				},
				$plan
			);
		}
		// A request without Accept, as some clients and caches send: the browser always sends one.
		$bare = Delivery::REDIRECT_DELIVERY_MODE === $mode && $plan ? self::fetch( $plan[0]['url'], '' ) : null;
		self::delete( $id );

		$results = [];
		foreach ( $run['formats'] as $format ) {
			$own = [];
			foreach ( $plan as $index => $request ) {
				if ( $format === $request['format'] ) {
					$own[] = $responses[ $index ];
				}
			}
			$results[] = Delivery::REDIRECT_DELIVERY_MODE === $mode
				? self::classify_redirect( $format, $own, $via, $bare )
				: self::classify_direct( $format, $own[0], $via );
		}

		$report = [
			'mode'    => $mode,
			'via'     => $via,
			'time'    => time(),
			'results' => $results,
		];
		// A settings change during the run removed the saved report: this one tested the old settings.
		if ( self::is_current( $mode, $run['formats'] ) ) {
			update_option( self::OPTION, $report, false );
			// A save between the check and the write deleted the report before this write added it again.
			if ( ! self::is_current( $mode, $run['formats'] ) ) {
				delete_option( self::OPTION );
			}
		}

		return $report;
	}

	/**
	 * Whether the delivery settings in the database are still the ones of a run. The values that this request
	 * cached at its start are dropped first: another request can save new ones during the run.
	 *
	 * @param string   $mode    Delivery mode of the run.
	 * @param string[] $formats Formats of the run.
	 *
	 * @return bool
	 * @phpstan-impure
	 */
	protected static function is_current( $mode, array $formats ) {
		$plugin  = \WRIO_Plugin::app();
		$network = get_current_network_id();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $network . ':notoptions', 'site-options' );
		foreach ( \WRIO_Settings::DELIVERY_KEYS as $key ) {
			wp_cache_delete( $plugin->getOptionName( $key ), 'options' );
			wp_cache_delete( $network . ':' . $plugin->getOptionName( $key ), 'site-options' );
		}

		return Delivery::get_delivery_mode() === $mode && self::formats() === $formats;
	}

	/**
	 * Formats of the report: the enabled ones. AVIF is enabled only with a license.
	 *
	 * @return list<string>
	 */
	protected static function formats() {
		return \WRIO_Formats::get_enabled_formats();
	}

	/**
	 * Requests for each format. Redirect mode: the JPEG URL with the format,
	 * without it, then with it again. Other modes: the converted file.
	 *
	 * @param string $id start() id.
	 *
	 * @return list<array<string, string>>
	 * @phpstan-return list<Request>
	 */
	protected static function plan( $id ) {
		$uploads  = wp_upload_dir();
		$url      = trailingslashit( $uploads['baseurl'] ) . self::FOLDER . $id . '/test.jpg';
		$redirect = Delivery::REDIRECT_DELIVERY_MODE === Delivery::get_delivery_mode();
		$requests = [];
		foreach ( self::formats() as $format ) {
			$accepts = $redirect ? [ self::ACCEPT[ $format ], self::ACCEPT_NONE, self::ACCEPT[ $format ] ] : [ self::ACCEPT[ $format ] ];
			foreach ( $accepts as $accept ) {
				$requests[] = [
					'format' => $format,
					'url'    => $redirect ? $url : $url . '.' . $format,
					'accept' => $accept,
				];
			}
		}

		return $requests;
	}

	/**
	 * Test files.
	 *
	 * @return list<string>
	 */
	protected static function files() {
		return [ 'test.jpg', 'test.jpg.webp', 'test.jpg.avif' ];
	}

	/**
	 * Folder of a test run.
	 *
	 * @param string $id start() id.
	 *
	 * @return string
	 */
	protected static function dir( $id ) {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::FOLDER . $id;
	}

	/**
	 * Delete the files and the folder of a test run.
	 *
	 * @param string $id start() id.
	 *
	 * @return void
	 */
	protected static function delete( $id ) {
		delete_transient( self::FOLDER . $id );
		$dir = self::dir( $id );
		// start() makes a real folder: the files behind a link are not of the test.
		if ( is_link( $dir ) ) {
			return;
		}
		foreach ( self::files() as $file ) {
			wp_delete_file( $dir . '/' . $file );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty folder the test made.
		}
	}

	/**
	 * Delete the folders of runs that did not finish, for example a closed tab.
	 * Runs on start() and on the daily clean-up cron.
	 *
	 * @return void
	 */
	public static function delete_stale() {
		$uploads = wp_upload_dir();
		foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . self::FOLDER . '*', GLOB_ONLYDIR ) as $dir ) {
			$id = substr( basename( (string) $dir ), strlen( self::FOLDER ) );
			if ( preg_match( '/^[a-z0-9]{12}$/', $id ) && filemtime( (string) $dir ) < time() - HOUR_IN_SECONDS ) {
				self::delete( $id );
			}
		}
	}

	/**
	 * What the browser reported for one request, in the fetch() shape.
	 *
	 * @param mixed                 $seen    Browser answer: status, redirected, type, vary (null when unreadable), cache, head (base64 start of the body).
	 * @param array<string, string> $request Plan request.
	 *
	 * @return array<string, mixed>
	 * @phpstan-param Request $request
	 * @phpstan-return Observed
	 */
	public static function normalize( $seen, $request ) {
		$seen = is_array( $seen ) ? $seen : [];
		$text = static function ( $key ) use ( $seen ) {
			return isset( $seen[ $key ] ) && is_scalar( $seen[ $key ] ) ? substr( sanitize_text_field( (string) $seen[ $key ] ), 0, 200 ) : '';
		};
		$head = base64_decode( $text( 'head' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- start of the image body, sent by the browser.

		return [
			'accept'     => $request['accept'],
			'status'     => isset( $seen['status'] ) ? (int) $seen['status'] : 0,
			'redirected' => ! empty( $seen['redirected'] ),
			'type'       => $text( 'type' ),
			// Null: a cross-origin answer that does not expose its Vary header.
			'vary'       => array_key_exists( 'vary', $seen ) && null === $seen['vary'] ? null : $text( 'vary' ),
			'format'     => self::detect( false === $head ? '' : $head ),
			'cache'      => $text( 'cache' ),
		];
	}

	/**
	 * Request a URL on this site without following redirects and keep what the test needs.
	 *
	 * @param string $url    URL.
	 * @param string $accept Accept header.
	 *
	 * @return array<string, mixed>|\WP_Error
	 * @phpstan-return Observed|\WP_Error
	 */
	public static function fetch( $url, $accept ) {
		// An empty value sends no Accept header: cURL then drops its default one.
		$headers = [
			'Accept'        => $accept,
			'Cache-Control' => 'no-cache',
			'Pragma'        => 'no-cache',
		];
		// A staging site behind basic auth answers 401 without it. Uploads on a CDN never get the credentials.
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) && is_string( $_SERVER['PHP_AUTH_USER'] ) && is_string( $_SERVER['PHP_AUTH_PW'] ) && self::is_site_url( $url ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the credentials of this request, sent back to this site.
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => self::USER_AGENT,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'headers'     => $headers,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$cache = '';
		foreach ( self::CACHE_HEADERS as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( '' !== $value && [] !== $value ) {
				$cache = $name . ': ' . implode( ', ', (array) $value );
				break;
			}
		}

		return [
			'accept'     => $accept,
			'status'     => (int) wp_remote_retrieve_response_code( $response ),
			'redirected' => false,
			'type'       => implode( ', ', (array) wp_remote_retrieve_header( $response, 'content-type' ) ),
			'vary'       => implode( ', ', (array) wp_remote_retrieve_header( $response, 'vary' ) ),
			'format'     => self::detect( wp_remote_retrieve_body( $response ) ),
			'cache'      => $cache,
		];
	}

	/**
	 * Whether a URL has the scheme, host and port of the site.
	 *
	 * @param string $url URL.
	 *
	 * @return bool
	 */
	public static function is_site_url( $url ) {
		$origin = static function ( $address ) {
			$parts = wp_parse_url( $address );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return '';
			}
			$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
			$port   = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

			return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
		};
		$target = $origin( $url );

		return '' !== $target && in_array( $target, [ $origin( home_url() ), $origin( site_url() ) ], true );
	}

	/**
	 * Whether a Vary header names Accept as one of its tokens (Accept-Encoding does not count).
	 *
	 * @param string $vary Vary header.
	 *
	 * @return bool
	 */
	public static function varies_on_accept( $vary ) {
		$tokens = array_map( 'trim', explode( ',', strtolower( $vary ) ) );

		return in_array( 'accept', $tokens, true ) || in_array( '*', $tokens, true );
	}

	/**
	 * Image format from the magic bytes.
	 *
	 * @param string $bytes File content or its start.
	 *
	 * @return string jpeg, png, webp, avif or unknown.
	 */
	public static function detect( $bytes ) {
		if ( 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 ) ) {
			return 'jpeg';
		}
		if ( 0 === strncmp( $bytes, "\x89PNG", 4 ) ) {
			return 'png';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'webp';
		}
		// "ftyp" alone is any ISO media file (MP4, HEIC): AVIF needs an avif/avis brand in that box.
		if ( strlen( $bytes ) >= 16 && 'ftyp' === substr( $bytes, 4, 4 ) ) {
			$box    = unpack( 'N', substr( $bytes, 0, 4 ) );
			$size   = is_array( $box ) ? (int) $box[1] : 0;
			$brands = str_split( substr( $bytes, 8, 4 ) . substr( $bytes, 16, max( 0, $size - 16 ) ), 4 );
			if ( array_intersect( [ 'avif', 'avis' ], $brands ) ) {
				return 'avif';
			}
		}

		return 'unknown';
	}

	/**
	 * Redirect mode: the JPEG URL requested with the format, without it, then with it again.
	 *
	 * @param string                                     $format    webp or avif.
	 * @param array<int, array<string, mixed>|\WP_Error> $responses Three answers.
	 * @param string                                     $via       browser or server.
	 * @param array<string, mixed>|\WP_Error|null        $bare      The site's answer to a request without Accept. Null or an error: not checked.
	 *
	 * @phpstan-param list<Observed|\WP_Error> $responses
	 * @phpstan-param Observed|\WP_Error|null $bare
	 * @phpstan-return Check
	 */
	public static function classify_redirect( $format, array $responses, $via = 'browser', $bare = null ) {
		$failed = self::request_problem( $format, $responses, $via );
		if ( $failed ) {
			return $failed;
		}
		/**
		 * All three requests answered 200.
		 *
		 * @var list<Observed> $responses
		 */
		list( $first, $plain, $again ) = $responses;
		$label                         = self::label( $format );
		$cache                         = self::cache_fact( $responses );

		foreach ( $responses as $response ) {
			if ( ! in_array( $response['format'], [ 'jpeg', 'webp', 'avif' ], true ) ) {
				return self::check(
					$format,
					'fail',
					'unexpected_bytes',
					array_merge( [ __( 'The image URL returned content that is not an image.', 'robin-image-optimizer' ) ], $cache ),
					[ __( 'A security plugin, a firewall or a server error page answered instead of the image.', 'robin-image-optimizer' ) ],
					$responses
				);
			}
		}

		if ( 'jpeg' !== $plain['format'] ) {
			return self::check(
				$format,
				'fail',
				'served_to_unsupported',
				array_merge(
					/* translators: %s: WebP or AVIF. */
					[ sprintf( __( 'The image URL returned %s to a request that does not accept it. Old browsers get an image they cannot show.', 'robin-image-optimizer' ), self::label( $plain['format'] ) ) ],
					$cache
				),
				[
					__( 'A page cache or CDN ignores the Vary: Accept header and serves the copy it stored first.', 'robin-image-optimizer' ),
					__( 'A server rule picks the format from the browser name instead of the Accept header.', 'robin-image-optimizer' ),
				],
				$responses
			);
		}

		if ( $format !== $first['format'] && $format !== $again['format'] ) {
			return self::check(
				$format,
				'fail',
				'not_served',
				array_merge(
					/* translators: 1: WebP or AVIF, 2: format the server returned. */
					[ sprintf( __( 'The image URL returned %2$s to a request that accepts %1$s.', 'robin-image-optimizer' ), $label, self::label( $first['format'] ) ) ],
					$cache
				),
				[
					__( 'The server does not run the .htaccess rules, for example nginx in front of Apache or AllowOverride None.', 'robin-image-optimizer' ),
					__( 'A page cache or CDN serves a copy of the original image.', 'robin-image-optimizer' ),
				],
				$responses
			);
		}

		if ( $first['format'] !== $again['format'] ) {
			return self::check(
				$format,
				'fail',
				'inconsistent',
				array_merge(
					/* translators: %s: WebP or AVIF. */
					[ sprintf( __( 'Two identical requests that accept %s got different formats.', 'robin-image-optimizer' ), $label ) ],
					$cache
				),
				[ __( 'A page cache or CDN stores one format for every browser.', 'robin-image-optimizer' ) ],
				$responses
			);
		}

		// A cross-origin answer hides Vary unless the host exposes it: hidden and missing look the same.
		if ( null === $first['vary'] || null === $plain['vary'] ) {
			return self::check(
				$format,
				'warning',
				'vary_unknown',
				array_merge(
					[
						/* translators: %s: WebP or AVIF. */
						sprintf( __( 'The server returned %s and the original to the right requests.', 'robin-image-optimizer' ), $label ),
						__( 'The uploads domain does not let the browser read the Vary header, so it was not checked.', 'robin-image-optimizer' ),
					],
					$cache
				),
				[ __( 'Without Vary: Accept, a page cache or CDN can store one format and serve it to every browser.', 'robin-image-optimizer' ) ],
				$responses
			);
		}

		if ( ! self::varies_on_accept( $first['vary'] ) || ! self::varies_on_accept( $plain['vary'] ) ) {
			return self::check(
				$format,
				'warning',
				'missing_vary',
				array_merge(
					[
						/* translators: %s: WebP or AVIF. */
						sprintf( __( 'The server returned %s and the original to the right requests.', 'robin-image-optimizer' ), $label ),
						__( 'The responses have no Vary: Accept header.', 'robin-image-optimizer' ),
					],
					$cache
				),
				[ __( 'Without Vary: Accept, a page cache or CDN can store one format and serve it to every browser.', 'robin-image-optimizer' ) ],
				$responses
			);
		}

		// mod_rewrite adds Vary: Accept only when the request has the header.
		if ( is_array( $bare ) && 200 === $bare['status'] && null !== $bare['vary'] && ! self::varies_on_accept( $bare['vary'] ) ) {
			return self::check(
				$format,
				'warning',
				'missing_vary_without_accept',
				array_merge(
					[
						/* translators: %s: WebP or AVIF. */
						sprintf( __( 'The server returned %s and the original to the right requests, with Vary: Accept.', 'robin-image-optimizer' ), $label ),
						__( 'A request without an Accept header got the image without Vary: Accept.', 'robin-image-optimizer' ),
					],
					$cache
				),
				[
					__( 'The server has no mod_headers module. Without it, only requests with an Accept header get Vary: Accept.', 'robin-image-optimizer' ),
					__( 'A page cache or CDN can store that answer and serve it to every browser.', 'robin-image-optimizer' ),
				],
				array_merge( $responses, [ $bare ] )
			);
		}

		return self::check(
			$format,
			'pass',
			'served',
			array_merge(
				/* translators: %s: WebP or AVIF. */
				[ sprintf( __( 'The server returned %s and the original to the right requests, with Vary: Accept.', 'robin-image-optimizer' ), $label ) ],
				$cache
			),
			[],
			$responses
		);
	}

	/**
	 * Picture tag and Image URLs modes: the page links the converted file, so request it directly.
	 *
	 * @param string                         $format   webp or avif.
	 * @param array<string, mixed>|\WP_Error $response The answer.
	 * @param string                         $via      browser or server.
	 *
	 * @phpstan-param Observed|\WP_Error $response
	 * @phpstan-return Check
	 */
	public static function classify_direct( $format, $response, $via = 'browser' ) {
		$failed = self::request_problem( $format, [ $response ], $via );
		if ( $failed ) {
			return $failed;
		}
		/**
		 * The request answered 200.
		 *
		 * @var Observed $response
		 */
		$label = self::label( $format );

		if ( $format !== $response['format'] ) {
			return self::check(
				$format,
				'fail',
				'unexpected_bytes',
				/* translators: %s: WebP or AVIF. */
				[ sprintf( __( 'The %s file URL returned content that is not that format.', 'robin-image-optimizer' ), $label ) ],
				[ __( 'A security plugin, a firewall or a server error page answered instead of the file.', 'robin-image-optimizer' ) ],
				[ $response ]
			);
		}

		// The media type without its parameters: "image/webp-invalid" is not "image/webp".
		if ( strtolower( trim( explode( ';', $response['type'] )[0] ) ) !== 'image/' . $format ) {
			return self::check(
				$format,
				'fail',
				'wrong_content_type',
				/* translators: 1: WebP or AVIF, 2: Content-Type header. */
				[ sprintf( __( 'The %1$s file is served with Content-Type "%2$s".', 'robin-image-optimizer' ), $label, $response['type'] ) ],
				/* translators: %s: file extension. */
				[ sprintf( __( 'The server has no MIME type for .%s files. Browsers can refuse to show the image.', 'robin-image-optimizer' ), $format ) ],
				[ $response ]
			);
		}

		return self::check(
			$format,
			'pass',
			'served',
			[
				/* translators: %s: WebP or AVIF. */
				sprintf( __( 'The %s file is served with the right Content-Type.', 'robin-image-optimizer' ), $label ),
				__( 'This checks the file, not the page markup that links it.', 'robin-image-optimizer' ),
			],
			[],
			[ $response ]
		);
	}

	/**
	 * A request that failed, redirected or did not answer 200.
	 * A failed or refused request from the site to itself says nothing about delivery.
	 *
	 * @param string                                     $format    webp or avif.
	 * @param array<int, array<string, mixed>|\WP_Error> $responses The answers.
	 * @param string                                     $via       browser or server.
	 *
	 * @phpstan-param list<Observed|\WP_Error> $responses
	 * @phpstan-return Check|null
	 */
	protected static function request_problem( $format, array $responses, $via ) {
		$observed = array_values(
			array_filter(
				$responses,
				static function ( $response ) {
					return ! is_wp_error( $response );
				}
			)
		);

		foreach ( $responses as $response ) {
			if ( is_wp_error( $response ) ) {
				return self::check(
					$format,
					'error',
					'request_failed',
					/* translators: %s: error message. */
					[ sprintf( __( 'The site could not request its own image: %s', 'robin-image-optimizer' ), $response->get_error_message() ) ],
					[ __( 'The host blocks requests from the site to itself. This does not show a delivery problem.', 'robin-image-optimizer' ) ],
					$observed
				);
			}
			// Hosts and firewalls often refuse the site's own requests: that is not what visitors get.
			if ( 'server' === $via && in_array( $response['status'], [ 401, 403 ], true ) ) {
				return self::check(
					$format,
					'error',
					'request_refused',
					/* translators: %d: HTTP status code. */
					[ sprintf( __( 'The site requested its own image and got HTTP %d.', 'robin-image-optimizer' ), $response['status'] ) ],
					[ __( 'A firewall or a password protection refuses requests from the site to itself. This does not show a delivery problem.', 'robin-image-optimizer' ) ],
					$observed
				);
			}
			if ( $response['redirected'] || 200 !== $response['status'] ) {
				return self::check(
					$format,
					'fail',
					'http_status',
					[
						$response['redirected']
							? __( 'The test image URL redirected to another URL.', 'robin-image-optimizer' )
							/* translators: %d: HTTP status code. */
							: sprintf( __( 'The test image URL returned HTTP %d.', 'robin-image-optimizer' ), $response['status'] ),
					],
					[
						__( 'The server blocks or redirects image requests in the uploads folder.', 'robin-image-optimizer' ),
						__( 'The server does not serve this file type.', 'robin-image-optimizer' ),
					],
					$observed
				);
			}
		}

		return null;
	}

	/**
	 * A fact line when a response came from a cache.
	 *
	 * @param list<array<string, mixed>> $responses The answers.
	 *
	 * @phpstan-param list<Observed> $responses
	 *
	 * @return list<string>
	 */
	protected static function cache_fact( array $responses ) {
		foreach ( $responses as $response ) {
			if ( '' !== $response['cache'] ) {
				/* translators: %s: response header, for example "x-cache: HIT". */
				return [ sprintf( __( 'A response carries a cache header (%s).', 'robin-image-optimizer' ), $response['cache'] ) ];
			}
		}

		return [];
	}

	/**
	 * Short text for the result, next to the button.
	 *
	 * @param string $format webp or avif.
	 * @param string $code   Reason code.
	 *
	 * @return string
	 */
	protected static function summary( $format, $code ) {
		$texts = [
			/* translators: %s: WebP or AVIF. */
			'served'                      => __( '%s served', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'missing_vary'                => __( '%s served, no Vary header', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'missing_vary_without_accept' => __( '%s served, no Vary without Accept', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'vary_unknown'                => __( '%s served, Vary not checked', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'not_served'                  => __( '%s not served', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'served_to_unsupported'       => __( '%s sent to browsers without support', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'inconsistent'                => __( '%s served only sometimes', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'unexpected_bytes'            => __( '%s: the server sent another file', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'wrong_content_type'          => __( '%s: wrong Content-Type', 'robin-image-optimizer' ),
			/* translators: %s: WebP or AVIF. */
			'http_status'                 => __( '%s: test image blocked', 'robin-image-optimizer' ),
		];

		return isset( $texts[ $code ] )
			? sprintf( $texts[ $code ], self::label( $format ) )
			/* translators: %s: WebP or AVIF. */
			: sprintf( __( '%s not tested', 'robin-image-optimizer' ), self::label( $format ) );
	}

	/**
	 * Name of a format for the texts.
	 *
	 * @param string $format jpeg, webp, avif, png or unknown.
	 *
	 * @return string
	 */
	protected static function label( $format ) {
		$names = [
			'webp' => 'WebP',
			'avif' => 'AVIF',
			'jpeg' => 'JPEG',
			'png'  => 'PNG',
		];

		return isset( $names[ $format ] ) ? $names[ $format ] : __( 'an unknown format', 'robin-image-optimizer' );
	}

	/**
	 * One result.
	 *
	 * @param string   $format   webp or avif.
	 * @param string   $status   pass, warning, fail or error.
	 * @param string   $code     Reason code.
	 * @param string[] $facts    What the responses prove.
	 * @param string[] $causes   What can cause it.
	 * @param array[]  $requests What each request returned.
	 *
	 * @phpstan-param 'pass'|'warning'|'fail'|'error' $status
	 * @phpstan-param list<string> $facts
	 * @phpstan-param list<string> $causes
	 * @phpstan-param list<Observed> $requests
	 * @phpstan-return Check
	 */
	protected static function check( $format, $status, $code, array $facts, array $causes, array $requests ) {
		return [
			'format'   => $format,
			'status'   => $status,
			'code'     => $code,
			'summary'  => self::summary( $format, $code ),
			'facts'    => $facts,
			'causes'   => $causes,
			'requests' => $requests,
		];
	}
}
