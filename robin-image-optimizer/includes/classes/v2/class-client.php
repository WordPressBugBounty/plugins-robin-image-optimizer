<?php
/**
 * Client for the Robin v2 API.
 *
 * @package robin-image-optimizer
 */

namespace WRIO\V2;

use WP_Error;
use WP_HTTP_Requests_Response;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for the Robin v2 API.
 *
 * POST /optimize takes one image and returns each requested output (optimized, webp, avif).
 * GET /account returns the plan limits. One set of endpoints serves free and Pro: the bearer key picks the plan.
 * Errors are RFC 9457 problem+json. This class returns them as WP_Error.
 *
 * @phpstan-import-type Output from \WRIO\Storage\Store
 * @phpstan-type OutputResult array{status: 'ok'|'skipped'|'failed', url?: string, size?: int, type?: string, reason?: string, error?: array{code?: string, detail?: string}, stored?: bool, original_size?: int, final_size?: int, rival?: Output, recheck?: bool}
 * @phpstan-type OptimizeResult array{original: array{size: int, type: string}, outputs: array<Output, OutputResult>, expires_at: string|null, request_id?: string}
 * @phpstan-type File array{path: string, outputs: Output[], level: string, quality?: int, keep_metadata: bool, dry_run?: bool, client_id?: string}
 * @phpstan-type Limits array{max_file_bytes: int, max_pixels: int, max_concurrency: int<1, max>, outputs: string[], known: bool}
 * @phpstan-type Entry array{key: int|string, file: File, attempt: int, split: bool}
 */
class Client {

	const DEFAULT_URL = 'https://dashboard.robinoptimizer.com/v2/';

	/**
	 * Attempts for each file, the first attempt included.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Longest wait between two attempts, in seconds.
	 */
	const MAX_WAIT = 10;

	/**
	 * Timeout of one optimize request, in seconds. One image bounds the request time on the server.
	 */
	const TIMEOUT = 120;

	/**
	 * Limits to use when GET /account fails. These are the free plan limits.
	 */
	const DEFAULT_LIMITS = [
		'max_file_bytes'  => 10485760,
		'max_pixels'      => 40000000,
		'max_concurrency' => 3,
		'outputs'         => [ 'optimized', 'webp' ],
		// The server did not confirm these limits. Then the client does not decide which outputs are in the plan.
		'known'           => false,
	];

	/**
	 * HTTP statuses that the client sends again in the same PHP request. 0 is a transport error (timeout, no connection).
	 * A 429 and every 5xx are not here: they start a backoff for the whole site instead (see backoff_seconds()), so
	 * the bulk page shows the wait.
	 */
	const RETRYABLE_STATUSES = [ 0, 408 ];

	/**
	 * HTTP statuses that can mean the request took too long. Then AVIF, the slowest output, gets its own request:
	 * in the same PHP request for 0 and 408, in the request after the wait for 502 and 504 (see split_key()).
	 */
	const TIMEOUT_STATUSES = [ 0, 408, 502, 504 ];

	/**
	 * HTTP statuses that ask the site to stop sending for Retry-After seconds: the file did nothing wrong. Every
	 * other 5xx starts the same backoff, with the site failure code server_busy.
	 */
	const BACKOFF_STATUSES = [ 429, 503 ];

	/**
	 * Wait when a 429 or 503 has no Retry-After header, in seconds.
	 */
	const DEFAULT_BACKOFF = 60;

	/**
	 * Wait when another 5xx has no Retry-After header, in seconds: an error of the server more than a request to slow
	 * down, so the next step tries sooner.
	 */
	const SERVER_ERROR_BACKOFF = 20;

	/**
	 * Longest backoff, in seconds. A larger Retry-After (a proxy, a server bug) must not stop every optimization for
	 * days with no way to clear it.
	 */
	const MAX_BACKOFF = 3600;

	/**
	 * Transient that marks the key as unknown to the server (invalid_license), for this API, site and key.
	 */
	const STOP_TRANSIENT = 'wrio_v2_account_stop';

	/**
	 * Seconds that an unknown key sends nothing. A license activation ends the stop sooner.
	 */
	const STOP_PAUSE = 3600;

	/**
	 * Problem codes that the same file always gets again. A bulk run does not send such a file again.
	 */
	const PERMANENT_CODES = [ 'file_too_large', 'unsupported_media_type', 'image_too_large', 'corrupt_image' ];

	/**
	 * Transient that holds the end of a backoff (a UNIX time) and the API and site it is for.
	 */
	const BACKOFF_TRANSIENT = 'wrio_v2_backoff_until';

	/**
	 * Option with a hash of the last limits from GET /account.
	 */
	const PLAN_OPTION = 'wrio_v2_plan';

	/**
	 * Option with the last failed request: code, HTTP status, server detail, request ID and time.
	 */
	const LAST_ERROR_OPTION = 'wrio_v2_last_error';

	/**
	 * Post meta that keeps why an attachment was queued, until cron or the background queue sends it.
	 */
	const TRIGGER_META = '_wrio_v2_trigger';

	/**
	 * Why the plugin sends the images of this request: upload, bulk, regenerate or manual.
	 * The X-Trigger header of POST /optimize. Queue, cron and WP-CLI runs are bulk.
	 *
	 * @var string
	 */
	public static $trigger = 'bulk';

	/**
	 * API base URL, with a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * The site URL. The server identifies a free site by its host.
	 *
	 * @var string
	 */
	private $site_url;

	/**
	 * Raw license key. Empty for the free plan.
	 *
	 * @var string
	 */
	private $license_key;

	/**
	 * Build a client.
	 *
	 * @param string $base_url    API base URL, for example https://dashboard.robinoptimizer.com/v2/.
	 * @param string $site_url    The site URL. The server identifies a free site by its host.
	 * @param string $license_key Raw license key. Empty for the free plan.
	 */
	public function __construct( $base_url, $site_url, $license_key = '' ) {
		$this->base_url    = trailingslashit( $base_url );
		$this->site_url    = $site_url;
		$this->license_key = $license_key;
	}

	/**
	 * Client for this site, its license and the configured API URL.
	 *
	 * Set the WRIO_API_V2_URL constant to use another server, for example a local one.
	 *
	 * @return self
	 */
	public static function from_settings() {
		$key = wrio_is_license_activate() ? (string) wrio_get_license_key() : '';

		return new self( self::api_url(), home_url(), $key );
	}

	/**
	 * The configured API base URL: the WRIO_API_V2_URL constant, or the default.
	 *
	 * @return string
	 */
	public static function api_url() {
		return defined( 'WRIO_API_V2_URL' ) ? (string) WRIO_API_V2_URL : self::DEFAULT_URL;
	}

	/**
	 * GET /account: the plan, the license state and the limits.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function account() {
		// An unknown key sends nothing, GET /account included (bulk page loads, a limits cache miss).
		$stop = $this->stop_error();

		if ( $stop ) {
			return $stop;
		}

		$timeout = wrio_get_http_timeout( 15 );

		if ( 0 === $timeout ) {
			return self::time_limit_error();
		}

		$response = $this->http_request(
			$this->base_url . 'account',
			[
				'headers'    => $this->headers(),
				'timeout'    => $timeout,
				'user-agent' => $this->user_agent(),
			]
		);

		return $this->parse( $response );
	}

	/**
	 * POST /optimize with dry_run=1: the sizes of the outputs, without files on the server.
	 *
	 * The plan does not limit the outputs of a dry run, so a free site can measure AVIF. A dry run starts no backoff
	 * and saves no last error: its daily cap (429) must not stop the real optimization.
	 *
	 * @param string                                                   $path    File to measure.
	 * @param Output[]                                                 $outputs Outputs to measure.
	 * @param array{level: string, quality?: int, keep_metadata: bool} $options See Optimizer::options().
	 *
	 * @return array{original: int, outputs: array<string, int>}|WP_Error The sizes of the outputs with the status "ok".
	 */
	public function measure( $path, array $outputs, array $options ) {
		// An unknown key uploads nothing, the AVIF estimate included.
		$stop = $this->stop_error();

		if ( $stop ) {
			return $stop;
		}

		$timeout = wrio_get_http_timeout( self::TIMEOUT );

		// WordPress reads a timeout of 0 as no limit.
		if ( 0 === $timeout ) {
			return self::time_limit_error();
		}

		$boundary = 'wrio' . wp_generate_password( 24, false );
		$response = $this->http_request(
			$this->base_url . 'optimize',
			[
				'method'     => 'POST',
				'timeout'    => $timeout,
				'user-agent' => $this->user_agent(),
				'headers'    => array_merge( $this->headers(), [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ] ),
				'body'       => $this->multipart(
					[
						'path'    => $path,
						'outputs' => $outputs,
						'dry_run' => true,
					] + $options,
					$boundary
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_numeric( $body['original']['size'] ?? null ) || ! is_array( $body['outputs'] ?? null ) ) {
			return self::error( 'dry_run_failed', __( 'The server did not measure the image.', 'robin-image-optimizer' ), [ 'status' => (int) wp_remote_retrieve_response_code( $response ) ] );
		}

		$sizes = [];
		foreach ( $body['outputs'] as $output => $result ) {
			if ( 'ok' === ( $result['status'] ?? '' ) && is_numeric( $result['size'] ?? null ) ) {
				$sizes[ (string) $output ] = (int) $result['size'];
			}
		}

		return [
			'original' => (int) $body['original']['size'],
			'outputs'  => $sizes,
		];
	}

	/**
	 * The plan limits from the cache, when the server sent them. No request.
	 *
	 * @return Limits|null
	 */
	public function cached_limits() {
		$cached = get_transient( $this->limits_key() );

		return is_array( $cached ) && ! empty( $cached['known'] ) ? self::normalize_limits( $cached ) : null;
	}

	/**
	 * The plan limits, cached for an hour.
	 *
	 * When GET /account fails, the free plan limits apply for 5 minutes, marked as not known. The server still
	 * enforces the real limits. An unknown license key is an error: then every upload would fail too.
	 *
	 * @return Limits|WP_Error
	 */
	public function limits() {
		$cache_key = $this->limits_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return self::normalize_limits( $cached );
		}

		$account = $this->account();

		if ( is_wp_error( $account ) && 'invalid_license' === $account->get_error_data()['code'] ) {
			return $account;
		}

		// No time left to ask: the free limits for this request only. The next request asks again.
		if ( is_wp_error( $account ) && 'time_limit' === $account->get_error_data()['code'] ) {
			return self::DEFAULT_LIMITS;
		}

		if ( is_wp_error( $account ) || ! isset( $account['limits'] ) || ! is_array( $account['limits'] ) ) {
			set_transient( $cache_key, self::DEFAULT_LIMITS, 5 * MINUTE_IN_SECONDS );

			return self::DEFAULT_LIMITS;
		}

		$limits = array_merge( self::normalize_limits( $account['limits'] ), [ 'known' => true ] );
		set_transient( $cache_key, $limits, HOUR_IN_SECONDS );

		// A plan can change on the server with the same key (an upgrade). Keep a hash of the limits, and fire
		// wrio_v2_plan_changed when it changes.
		$plan = md5( (string) wp_json_encode( $limits ) );

		if ( get_option( self::PLAN_OPTION ) !== $plan ) {
			update_option( self::PLAN_OPTION, $plan, false );

			/**
			 * Fires when GET /account returns other limits than the last time.
			 *
			 * @param array $limits The new limits.
			 */
			do_action( 'wrio_v2_plan_changed', $limits );
		}

		return $limits;
	}

	/**
	 * Transient of the limits of this server, site and key.
	 *
	 * @return string
	 */
	private function limits_key() {
		return 'wrio_v2_limits_' . md5( $this->base_url . '|' . $this->site_url . '|' . $this->license_key );
	}

	/**
	 * Seconds left of a backoff that a 429 or any 5xx started. 0 means the site can send.
	 *
	 * @phpstan-impure Any answer can start a backoff.
	 *
	 * @return int
	 */
	public static function backoff_seconds() {
		$backoff = get_transient( self::BACKOFF_TRANSIENT );

		// A database copied to another site, or another API URL, does not wait for this one.
		if ( ! is_array( $backoff ) || ( $backoff['scope'] ?? '' ) !== self::backoff_scope( self::api_url(), home_url() ) ) {
			return 0;
		}

		$left = (int) ( $backoff['until'] ?? 0 ) - time();

		// A longer backoff that an older version saved is shortened in the transient too, so it ends after
		// MAX_BACKOFF and a new answer can replace it (parse() keeps the later end only).
		if ( $left > self::MAX_BACKOFF ) {
			$backoff['until'] = time() + self::MAX_BACKOFF;
			set_transient( self::BACKOFF_TRANSIENT, $backoff, self::MAX_BACKOFF );
			$left = self::MAX_BACKOFF;
		}

		return max( 0, $left );
	}

	/**
	 * The error of an unknown license key, while the server refuses this key (see STOP_PAUSE). No request.
	 *
	 * Every image would get the same 401, so a bulk run would send all of them again on every step (O-2).
	 *
	 * @return WP_Error|null Null when the key can send.
	 */
	public function stop_error() {
		$stop = get_transient( self::STOP_TRANSIENT );

		// Another key, API URL or site (a copied database) can send.
		if ( ! is_array( $stop ) || ( $stop['key'] ?? '' ) !== $this->limits_key() ) {
			return null;
		}

		return self::error( 'invalid_license', (string) ( $stop['message'] ?? '' ), [ 'status' => 401 ] );
	}

	/**
	 * Send again after a license activation: the same key can be active again.
	 *
	 * @return void
	 */
	public static function forget_stop() {
		delete_transient( self::STOP_TRANSIENT );
	}

	/**
	 * The API and site that a backoff is for.
	 *
	 * @param string $base_url API base URL.
	 * @param string $site_url Site URL.
	 *
	 * @return string
	 */
	private static function backoff_scope( $base_url, $site_url ) {
		return md5( trailingslashit( $base_url ) . '|' . $site_url );
	}

	/**
	 * Limits from the server, with the default for each missing or broken value.
	 *
	 * @param array<mixed> $limits Limits from GET /account or the cache.
	 *
	 * @return Limits
	 */
	private static function normalize_limits( array $limits ) {
		$number = function ( $name, $fallback ) use ( $limits ) {
			return isset( $limits[ $name ] ) && is_numeric( $limits[ $name ] ) && $limits[ $name ] > 0 ? (int) $limits[ $name ] : $fallback;
		};

		return [
			'max_file_bytes'  => $number( 'max_file_bytes', self::DEFAULT_LIMITS['max_file_bytes'] ),
			'max_pixels'      => $number( 'max_pixels', self::DEFAULT_LIMITS['max_pixels'] ),
			'max_concurrency' => max( 1, $number( 'max_concurrency', self::DEFAULT_LIMITS['max_concurrency'] ) ),
			'outputs'         => isset( $limits['outputs'] ) && is_array( $limits['outputs'] ) ? array_values( array_filter( $limits['outputs'], 'is_string' ) ) : self::DEFAULT_LIMITS['outputs'],
			'known'           => ! empty( $limits['known'] ),
		];
	}

	/**
	 * POST /optimize for each file, up to max_concurrency at the same time.
	 *
	 * With limits from the server, outputs that are not in the plan get "skipped: not_in_plan" without a request.
	 * A request that fails with a retryable status (0 or 408), and outputs that fail on the server, are sent again.
	 * There are MAX_ATTEMPTS attempts at most, with a backoff between them. A 429 or any 5xx starts the site backoff
	 * instead. When a request with AVIF and other outputs times out, the other outputs are sent again and AVIF is sent
	 * once in its own request: in the same PHP request after a 0 or 408, in the request after the wait after a 502 or
	 * 504.
	 *
	 * @param array<int|string, File> $files Files to optimize, by any key.
	 *
	 * @return array<int|string, OptimizeResult|WP_Error> Results with the keys of $files. A WP_Error means that no attempt got an answer.
	 */
	public function optimize( array $files ) {
		$limits = $this->stop_error() ?? ( self::backoff_seconds() > 0 ? self::backoff_error() : $this->limits() );

		// A 429 or any 5xx of GET /account started a backoff: nothing is sent either.
		if ( ! is_wp_error( $limits ) && self::backoff_seconds() > 0 ) {
			$limits = self::backoff_error();
		}

		if ( is_wp_error( $limits ) ) {
			return array_map(
				function () use ( $limits ) {
					return $limits;
				},
				$files
			);
		}

		$originals   = []; // File key => original size and type.
		$outputs     = []; // File key => output name => result.
		$expires     = []; // File key => expires_at of the last answer. Set once a request of the file got an answer.
		$errors      = []; // File key => last request error.
		$request_ids = []; // File key => X-Request-Id of the last answer.
		$queue       = []; // Request key => file key, file with the outputs still to get, attempt, and whether AVIF was split off.

		foreach ( $files as $key => $file ) {
			$size  = wrio_get_file_size( $file['path'] );
			$error = $this->check_file( $size, $file['path'], $limits );

			if ( $error ) {
				$errors[ $key ] = $error;
				continue;
			}

			$originals[ $key ] = [
				'size' => $size,
				'type' => '',
			];
			$outputs[ $key ]   = [];

			// Only limits from the server can say that an output is not in the plan.
			$skipped = $limits['known'] ? array_diff( $file['outputs'], $limits['outputs'] ) : [];

			foreach ( $skipped as $output ) {
				$outputs[ $key ][ $output ] = [
					'status' => 'skipped',
					'reason' => 'not_in_plan',
				];
			}

			$file['outputs'] = array_values( array_diff( $file['outputs'], $skipped ) );
			// X-Client-Request-Id: one per file, the same on its retries, so support can find every attempt.
			$file['client_id'] = wp_generate_uuid4();

			if ( empty( $file['outputs'] ) ) {
				// Every output is outside the plan: a result without a request.
				$expires[ $key ] = null;
			} elseif ( self::splits( $file['outputs'] ) && get_transient( self::split_key( $file['path'] ) ) ) {
				// The last request of this file timed out with a 502 or 504 before the wait: AVIF goes on its own.
				$queue += self::split(
					(string) $key,
					[
						'key'     => $key,
						'file'    => $file,
						'attempt' => 1,
						'split'   => false,
					]
				);
			} else {
				$queue[ (string) $key ] = [
					'key'     => $key,
					'file'    => $file,
					'attempt' => 1,
					'split'   => false,
				];
			}
		}

		while ( ! empty( $queue ) ) {
			$next = [];
			$wait = 0;

			// One group of max_concurrency requests at a time: a backoff (a 429 or any 5xx) or an unknown license key
			// stops the groups after it.
			foreach ( array_chunk( $queue, $limits['max_concurrency'], true ) as $chunk ) {
				$stop = $this->stop_error() ?? ( self::backoff_seconds() > 0 ? self::backoff_error() : null );

				if ( $stop ) {
					foreach ( $chunk as $entry ) {
						$errors[ $entry['key'] ] = $errors[ $entry['key'] ] ?? $stop;
					}
					continue;
				}

				foreach ( $this->send_many( array_map( fn( $entry ) => $entry['file'], $chunk ), $limits['max_concurrency'] ) as $id => $response ) {
					$entry = $queue[ $id ];
					$key   = $entry['key'];
					$data  = $this->parse( $response );

					if ( is_wp_error( $data ) ) {
						$errors[ $key ] = $data;
						$error_data     = (array) $data->get_error_data();
						\WRIO_Plugin::app()->logger->warning(
							sprintf(
								'v2 request failed: %s (X-Request-Id: %s, X-Client-Request-Id: %s, attempt %d)',
								$data->get_error_code(),
								(string) ( $error_data['request_id'] ?? '' ),
								(string) ( $entry['file']['client_id'] ?? '' ),
								$entry['attempt']
							)
						);

						foreach ( $entry['file']['outputs'] as $output ) {
							$outputs[ $key ][ $output ] = self::failed_output( $data );
						}

						// A 502 or 504 waits for the backoff: the request after the wait sends AVIF on its own.
						if ( empty( $error_data['retryable'] ) && in_array( $error_data['status'] ?? 0, self::TIMEOUT_STATUSES, true ) && self::splits( $entry['file']['outputs'] ) && ! $entry['split'] ) {
							set_transient( self::split_key( $entry['file']['path'] ), 1, DAY_IN_SECONDS );
						}

						foreach ( $this->retries( (string) $id, $entry, $data ) as $retry_id => $retry ) {
							$next[ $retry_id ] = $retry;
							$wait              = max( $wait, 2 ** ( $retry['attempt'] - 1 ) );
						}
						continue;
					}

					$originals[ $key ]   = [
						'size' => (int) $data['original']['size'],
						'type' => (string) ( $data['original']['type'] ?? '' ),
					];
					$expires[ $key ]     = isset( $data['expires_at'] ) ? (string) $data['expires_at'] : null;
					$request_ids[ $key ] = (string) ( $data['request_id'] ?? '' );
					$failed              = [];

					foreach ( $entry['file']['outputs'] as $output ) {
						$outputs[ $key ][ $output ] = self::output_result( $data['outputs'][ $output ] ?? null );

						if ( 'failed' === $outputs[ $key ][ $output ]['status'] ) {
							$failed[] = $output;
						}
					}

					if ( ! empty( $failed ) && $entry['attempt'] < self::MAX_ATTEMPTS ) {
						$next[ $id ] = array_merge(
							$entry,
							[
								'file'    => array_merge( $entry['file'], [ 'outputs' => $failed ] ),
								'attempt' => $entry['attempt'] + 1,
							]
						);
						$wait        = max( $wait, 2 ** $entry['attempt'] );
					}
				}
			}

			$queue = $next;
			$wait  = min( self::MAX_WAIT, $wait );

			// Stop when a 429 or any 5xx started a backoff, when the key is unknown, and before a wait and a request would
			// pass the PHP time limit.
			if ( empty( $queue ) || self::backoff_seconds() > 0 || $this->stop_error() || ! wrio_has_time_left( $wait + 15 ) ) {
				break;
			}

			$this->pause( $wait );
		}

		$results = [];

		// Keep the order of $files. A file whose requests never got an answer is its last error.
		foreach ( array_keys( $files ) as $key ) {
			$results[ $key ] = array_key_exists( $key, $expires ) ? [
				'original'   => $originals[ $key ],
				'outputs'    => $outputs[ $key ],
				'expires_at' => $expires[ $key ],
				'request_id' => $request_ids[ $key ] ?? '',
			] : $errors[ $key ];
		}

		return $results;
	}

	/**
	 * The requests to send again after a request error.
	 *
	 * After a timeout of a request with AVIF and other outputs, AVIF gets one request of its own, without retries.
	 * An AVIF request that was split off is not sent again after another timeout.
	 *
	 * @param string   $id    Request key.
	 * @param array    $entry The failed request.
	 * @param WP_Error $error The request error.
	 *
	 * @phpstan-param Entry $entry
	 *
	 * @return array<string, Entry> Requests by request key. Each one is a later attempt: attempt is 2 or more.
	 */
	private function retries( $id, array $entry, WP_Error $error ) {
		$data    = $error->get_error_data();
		$outputs = $entry['file']['outputs'];
		$timeout = in_array( $data['status'], self::TIMEOUT_STATUSES, true );

		if ( ! $data['retryable'] || $entry['attempt'] >= self::MAX_ATTEMPTS || ( $timeout && $entry['split'] ) ) {
			return [];
		}

		$again = array_merge( $entry, [ 'attempt' => $entry['attempt'] + 1 ] );

		if ( ! $timeout || ! self::splits( $outputs ) ) {
			return [ $id => $again ];
		}

		return self::split( $id, $again );
	}

	/**
	 * A request without AVIF and AVIF in its own request, which is not sent again after another timeout.
	 *
	 * @param string $id    Request key.
	 * @param array  $entry The request with AVIF and other outputs.
	 *
	 * @phpstan-param Entry $entry
	 *
	 * @return array<string, Entry>
	 */
	private static function split( $id, array $entry ) {
		$others                    = $entry;
		$others['file']['outputs'] = array_values( array_diff( $entry['file']['outputs'], [ 'avif' ] ) );

		return [
			$id           => $others,
			$id . '#avif' => [
				'key'     => $entry['key'],
				'file'    => array_merge( $entry['file'], [ 'outputs' => [ 'avif' ] ] ),
				'attempt' => self::MAX_ATTEMPTS,
				'split'   => true,
			],
		];
	}

	/**
	 * Whether a request with these outputs sends AVIF apart after a timeout: AVIF and at least one other output.
	 *
	 * @param Output[] $outputs Outputs of the request.
	 *
	 * @return bool
	 */
	private static function splits( array $outputs ) {
		return in_array( 'avif', $outputs, true ) && count( $outputs ) > 1;
	}

	/**
	 * The transient that makes the next request of a file send AVIF on its own, after a 502 or 504 of a request
	 * with AVIF and other outputs.
	 *
	 * @param string $path File path.
	 *
	 * @return string
	 */
	private static function split_key( $path ) {
		return 'wrio_v2_split_' . md5( home_url() . '|' . $path );
	}

	/**
	 * Download a result file.
	 *
	 * @param string $url Result URL from an "ok" output.
	 *
	 * @return string|WP_Error The file contents.
	 */
	public function download( $url ) {
		$timeout = wrio_get_http_timeout( 60 );

		if ( 0 === $timeout ) {
			return self::time_limit_error();
		}

		$response = $this->http_request(
			$url,
			[
				'timeout'    => $timeout,
				'user-agent' => $this->user_agent(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code || '' === $body ) {
			// translators: %d is the HTTP status code.
			return self::error( 'download_failed', sprintf( __( 'The optimized file could not be downloaded (HTTP %d).', 'robin-image-optimizer' ), $code ), [ 'retryable' => true ] );
		}

		return $body;
	}

	/**
	 * Send an HTTP request with one timeout for the whole redirect chain.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments, including timeout.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function http_request( $url, array $args ) {
		$deadline = microtime( true ) + $args['timeout'];
		$budget   = static function ( &$location, &$headers, &$body, &$options ) use ( $deadline ) {
			$remaining = (int) floor( $deadline - microtime( true ) );

			if ( $remaining < 1 ) {
				throw new \WpOrg\Requests\Exception( __( 'The HTTP request timed out.', 'robin-image-optimizer' ), 'timeout' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress turns this into a WP_Error.
			}

			$options['timeout']         = min( $options['timeout'], $remaining );
			$options['connect_timeout'] = min( $options['connect_timeout'], $remaining );
		};
		add_action( 'requests-requests.before_redirect', $budget, 10, 4 );

		try {
			return wp_remote_request( $url, $args );
		} finally {
			remove_action( 'requests-requests.before_redirect', $budget, 10 );
		}
	}

	/**
	 * Wait between two attempts. Tests replace it.
	 *
	 * @param int $seconds Seconds to wait.
	 *
	 * @return void
	 */
	protected function pause( $seconds ) {
		sleep( $seconds );
	}

	/**
	 * The error of a file that cannot be sent, or null.
	 *
	 * @param int    $size   File size in bytes. 0 when the file is missing.
	 * @param string $path   File path.
	 * @param array  $limits Plan limits.
	 *
	 * @phpstan-param Limits $limits
	 *
	 * @return WP_Error|null
	 */
	private function check_file( $size, $path, array $limits ) {
		if ( ! $size && ! is_file( $path ) ) {
			// translators: %s is the file path.
			return self::error( 'missing_file', sprintf( __( 'The file %s does not exist.', 'robin-image-optimizer' ), $path ), [ 'permanent' => true ] );
		}

		// The server would answer corrupt_image after the upload.
		if ( ! $size || ! self::is_intact( $path ) ) {
			return self::error( 'corrupt_image', __( 'The image file is damaged and cannot be read.', 'robin-image-optimizer' ) );
		}

		// The fallback limits of a failed GET /account are not the plan's: the server checks the file.
		if ( ! $limits['known'] ) {
			return self::memory_error( $size );
		}

		if ( $size > $limits['max_file_bytes'] ) {
			// translators: %s is the largest file size of the plan, for example "10 MB".
			return self::error( 'file_too_large', sprintf( __( 'The file is larger than %s, the limit of your plan.', 'robin-image-optimizer' ), (string) size_format( $limits['max_file_bytes'] ) ) );
		}

		// Before getimagesize(): it can read much of a large file.
		$memory = self::memory_error( $size );

		if ( $memory ) {
			return $memory;
		}

		// The server also counts the frames of an animation, so this check cannot catch every image.
		$image = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that is not an image gets its error from the server.

		if ( is_array( $image ) && $image[0] * $image[1] > $limits['max_pixels'] ) {
			// translators: %s is the largest number of megapixels of the plan, for example "40".
			return self::error( 'image_too_large', sprintf( __( 'The image has more than %s megapixels, the limit of your plan.', 'robin-image-optimizer' ), number_format_i18n( $limits['max_pixels'] / 1000000 ) ) );
		}

		return null;
	}

	/**
	 * The error of a file whose upload body needs more memory than PHP has free, or null. The body holds the file
	 * about twice (see multipart()).
	 *
	 * Checks one file before any body of its group is built, so a group of large files can pass each check and
	 * still exceed the limit together.
	 *
	 * @param int $size File size in bytes.
	 *
	 * @return WP_Error|null
	 */
	private static function memory_error( $size ) {
		wp_raise_memory_limit( 'image' );
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit > 0 && $limit - memory_get_usage() < $size * 3 ) {
			return self::error( 'memory_limit', __( 'The image needs more PHP memory than is free to send it.', 'robin-image-optimizer' ) );
		}

		return null;
	}

	/**
	 * Whether a file that starts like a JPEG, PNG, GIF or WebP image is a whole image: PHP can read its header, a
	 * JPEG or PNG file is not cut off, and a WebP file is as long as its header says. A file with the name of such an
	 * image is damaged when it does not start like one. Other files get their error from the server.
	 *
	 * @param string $path File path.
	 *
	 * @return bool
	 */
	public static function is_intact( $path ) {
		// The upload could not read it either. Checked first: fopen() warns on a file it cannot read.
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads the file in parts.

		if ( ! $handle ) {
			return false;
		}

		$head  = (string) fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$jpeg  = 0 === strpos( $head, "\xFF\xD8\xFF" );
		$png   = 0 === strpos( $head, "\x89PNG\r\n\x1A\n" );
		$webp  = 0 === strpos( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 );
		$image = $jpeg || $png || $webp || 0 === strpos( $head, 'GIF8' );

		$size = $image ? @getimagesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a damaged header is the answer.

		if ( ! $image ) {
			// A file named as one of these images is not one, for example a text file named .jpg.
			$intact = ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' ], true );
		} elseif ( ! $size ) {
			$intact = false;
		} elseif ( $jpeg ) {
			$intact = self::jpeg_ends( $handle ) && self::jpeg_decodes( $path, $size[0] * $size[1] );
		} elseif ( $webp ) {
			// The RIFF size counts the bytes after its first 8.
			$riff   = unpack( 'V', substr( $head, 4, 4 ) );
			$intact = is_array( $riff ) && (int) filesize( $path ) >= (int) $riff[1] + 8;
		} elseif ( $png ) {
			$intact = self::png_ends( $handle );
		} else {
			$intact = self::gif_ends( $handle );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $intact;
	}

	/**
	 * Whether a JPEG file has its end marker (FF D9). The markers are walked from the start: a segment is skipped by
	 * its length, because its data (an EXIF thumbnail, a comment between the scans of a progressive JPEG) can hold
	 * FF D9. In the scan data, FF 00, the restart markers and fill bytes do not end it. Data after the end marker
	 * (the video of a motion photo) does not count.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return bool
	 */
	private static function jpeg_ends( $handle ) {
		fseek( $handle, 2 );

		$start  = 2; // File offset of $buffer[0].
		$buffer = '';
		$i      = 0;

		while ( true ) {
			// Keep a marker and its length in the buffer.
			if ( strlen( $buffer ) - $i < 4 && ! feof( $handle ) ) {
				$start += $i;
				$buffer = substr( $buffer, $i ) . fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$i      = 0;
			}

			$found = strpos( $buffer, "\xFF", $i );

			if ( false === $found ) {
				if ( feof( $handle ) ) {
					return false;
				}

				$i = strlen( $buffer );
				continue;
			}

			$i    = $found;
			$next = $buffer[ $i + 1 ] ?? '';

			if ( "\xD9" === $next ) {
				return true;
			}

			// The file ends here, or the buffer needs the next bytes.
			if ( '' === $next || ( strlen( $buffer ) < $i + 4 && ! self::stands_alone( $next ) ) ) {
				if ( feof( $handle ) ) {
					return false;
				}

				continue;
			}

			if ( self::stands_alone( $next ) ) {
				++$i;
				continue;
			}

			$skip = $i + 2 + ord( $buffer[ $i + 2 ] ) * 256 + ord( $buffer[ $i + 3 ] );

			if ( $skip <= strlen( $buffer ) ) {
				$i = $skip;
				continue;
			}

			fseek( $handle, $start + $skip );
			$start += $skip;
			$buffer = '';
			$i      = 0;
		}
	}

	/**
	 * Whether the byte after an FF byte of a JPEG file is not a segment with a length: FF 00 in the scan data, a fill
	 * byte (FF FF), a restart marker (FF D0 to FF D7), the start marker or TEM.
	 *
	 * @param string $next The byte.
	 *
	 * @return bool
	 */
	private static function stands_alone( $next ) {
		$code = ord( $next );

		return 0x00 === $code || 0x01 === $code || 0xFF === $code || ( $code >= 0xD0 && $code <= 0xD8 );
	}

	/**
	 * Whether a PNG file has its IEND chunk. The chunks are skipped by their length.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return bool
	 */
	private static function png_ends( $handle ) {
		fseek( $handle, 8 );

		while ( true ) {
			$chunk = (string) fread( $handle, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

			if ( 8 !== strlen( $chunk ) ) {
				return false;
			}

			$length = (int) hexdec( bin2hex( substr( $chunk, 0, 4 ) ) );
			// ponytail: reads each chunk whole; the plan's file limit (10 MB and up) bounds it.
			$data = $length > 0 ? (string) fread( $handle, $length ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$crc  = (string) fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

			// A changed byte in the image data: the CRC of its chunk does not match.
			if ( strlen( $data ) !== $length || 4 !== strlen( $crc ) || sprintf( '%08x', crc32( substr( $chunk, 4, 4 ) . $data ) ) !== bin2hex( $crc ) ) {
				return false;
			}

			if ( 'IEND' === substr( $chunk, 4, 4 ) ) {
				return true;
			}
		}
	}

	/**
	 * Whether a GIF file ends with its trailer byte (3B). Zero bytes after it do not count.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return bool
	 */
	private static function gif_ends( $handle ) {
		fseek( $handle, -16, SEEK_END );

		return ';' === substr( rtrim( (string) fread( $handle, 16 ), "\0" ), -1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	}

	/**
	 * Whether GD decodes a JPEG file without a warning of libjpeg ("Premature end of JPEG file", "Corrupt JPEG data"):
	 * a file with all its markers can still have damaged image data. A file that needs more memory than is free, or
	 * a site without GD, is not decoded.
	 *
	 * @param string $path   File path.
	 * @param int    $pixels Width times height.
	 *
	 * @return bool
	 */
	private static function jpeg_decodes( $path, $pixels ) {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		// GD keeps 4 bytes per pixel, and libjpeg needs some more while it decodes.
		$fits = $limit <= 0 || $limit - memory_get_usage() > $pixels * 6;

		if ( ! $fits || ! function_exists( 'imagecreatefromjpeg' ) ) {
			return true;
		}

		// A warning is the answer: the bundled GD fails on it, an external libgd only warns and still returns an image.
		$warned = false;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- restored below.
			static function () use ( &$warned ) {
				$warned = true;

				return true;
			}
		);
		$ignore = ini_get( 'gd.jpeg_ignore_warning' );
		ini_set( 'gd.jpeg_ignore_warning', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored below.

		try {
			$image = imagecreatefromjpeg( $path );
		} finally {
			ini_set( 'gd.jpeg_ignore_warning', (string) $ignore ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			restore_error_handler();
		}

		// No imagedestroy(): deprecated in PHP 8.5, the image is freed with the variable.
		return $image && ! $warned;
	}

	/**
	 * Check one output result from the server. Anything that is not a valid result becomes "failed".
	 *
	 * @param mixed $result Output result from the server.
	 *
	 * @return OutputResult
	 */
	private static function output_result( $result ) {
		$status = is_array( $result ) ? ( $result['status'] ?? '' ) : '';

		if ( 'ok' === $status && is_string( $result['url'] ?? null ) && is_numeric( $result['size'] ?? null ) ) {
			return [
				'status' => 'ok',
				'url'    => $result['url'],
				'size'   => (int) $result['size'],
				'type'   => (string) ( $result['type'] ?? '' ),
			];
		}

		if ( 'skipped' === $status ) {
			return [
				'status' => 'skipped',
				'reason' => (string) ( $result['reason'] ?? '' ),
			];
		}

		if ( 'failed' === $status ) {
			return [
				'status' => 'failed',
				'error'  => [
					'code'   => (string) ( $result['error']['code'] ?? 'processing_failed' ),
					'detail' => (string) ( $result['error']['detail'] ?? '' ),
				],
			];
		}

		return self::failed_output( self::error( 'invalid_response', __( 'The server did not return this output.', 'robin-image-optimizer' ) ) );
	}

	/**
	 * A "failed" output result for an error.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return OutputResult
	 */
	private static function failed_output( WP_Error $error ) {
		return [
			'status' => 'failed',
			'error'  => [
				'code'   => (string) $error->get_error_data()['code'],
				'detail' => $error->get_error_message(),
			],
		];
	}

	/**
	 * The error that a backoff gives to each file, without a request.
	 *
	 * @return WP_Error
	 */
	private static function backoff_error() {
		$backoff = get_transient( self::BACKOFF_TRANSIENT );
		$code    = is_array( $backoff ) && is_string( $backoff['code'] ?? null ) && '' !== $backoff['code'] ? $backoff['code'] : 'rate_limited';
		$message = is_array( $backoff ) && is_string( $backoff['message'] ?? null ) && '' !== $backoff['message'] ? $backoff['message'] : __( 'Too many optimization requests. Try again in a few minutes.', 'robin-image-optimizer' );

		return self::error(
			$code,
			$message,
			[
				'status'      => is_array( $backoff ) ? (int) ( $backoff['status'] ?? 0 ) : 0,
				'retry_after' => self::backoff_seconds(),
			]
		);
	}

	/**
	 * The error of a call that is not sent: less than 1 second is left before the PHP execution-time limit.
	 *
	 * @return WP_Error
	 */
	private static function time_limit_error() {
		return self::error( 'time_limit', __( 'The request is near the PHP execution-time limit. Optimize the image again.', 'robin-image-optimizer' ) );
	}

	/**
	 * Build a client error. Every error of this class has the same data keys.
	 *
	 * @param string                                                                                          $code    Problem code from the server, or a client code such as missing_file.
	 * @param string                                                                                          $message Message for the user.
	 * @param array{status?: int, request_id?: string, retry_after?: int, retryable?: bool, permanent?: bool} $data    Values for the error data.
	 *
	 * @return WP_Error
	 */
	public static function error( $code, $message, array $data = [] ) {
		return new WP_Error(
			'wrio_v2_' . $code,
			$message,
			array_merge(
				[
					'status'      => 0,
					'code'        => $code,
					'request_id'  => '',
					'retry_after' => 0,
					'retryable'   => false,
					'permanent'   => in_array( $code, self::PERMANENT_CODES, true ),
				],
				$data
			)
		);
	}

	/**
	 * Send POST /optimize for each file, $concurrency requests at the same time.
	 *
	 * The requests go through the same WordPress steps as wp_remote_request(): the http_request_args and
	 * pre_http_request filters, the WP_HTTP_BLOCK_EXTERNAL block, the http_response filter, the WordPress CA bundle
	 * and the WP_PROXY_* settings. Then a plugin or a test that answers or changes HTTP requests does the same here.
	 *
	 * @param array<int|string, File> $files       Files to send, by request key.
	 * @param int<1, max>             $concurrency Requests at the same time.
	 *
	 * @return array<int|string, array<string, mixed>|WP_Error> Responses in the wp_remote_request() format.
	 */
	private function send_many( array $files, $concurrency ) {
		$responses = [];
		$url       = $this->base_url . 'optimize';

		foreach ( array_chunk( $files, $concurrency, true ) as $chunk ) {
			$timeout = wrio_get_http_timeout( self::TIMEOUT );

			if ( 0 === $timeout ) {
				foreach ( $chunk as $key => $file ) {
					$responses[ $key ] = self::time_limit_error();
				}
				continue;
			}

			wp_raise_memory_limit( 'image' );

			$requests = [];

			foreach ( $chunk as $key => $file ) {
				$boundary = 'wrio' . wp_generate_password( 24, false );
				$args     = apply_filters(
					'http_request_args',
					[
						'method'          => 'POST',
						'timeout'         => $timeout,
						'user-agent'      => $this->user_agent(),
						'headers'         => array_merge(
							$this->headers(),
							[
								'Content-Type'        => 'multipart/form-data; boundary=' . $boundary,
								'X-Trigger'           => self::$trigger,
								'X-Client-Request-Id' => $file['client_id'] ?? wp_generate_uuid4(),
							]
						),
						'body'            => $this->multipart( $file, $boundary ),
						'sslverify'       => true,
						'sslcertificates' => ABSPATH . 'wp-includes/certificates/ca-bundle.crt',
					],
					$url
				);
				$pre      = apply_filters( 'pre_http_request', false, $args, $url );

				if ( false !== $pre ) {
					$responses[ $key ] = $pre;
					continue;
				}

				// As wp_remote_request(): a site that blocks external requests does not send the image and the key.
				if ( ( new \WP_Http() )->block_request( $url ) ) {
					$responses[ $key ] = new WP_Error( 'http_request_not_executed', __( 'This site blocks external HTTP requests (WP_HTTP_BLOCK_EXTERNAL).', 'robin-image-optimizer' ) );
					continue;
				}

				$requests[ $key ] = [
					'url'     => $url,
					'type'    => 'POST',
					'headers' => $args['headers'],
					'data'    => $args['body'],
					'options' => self::transport_options( $url, $args ),
					'args'    => $args,
				];
			}

			if ( empty( $requests ) ) {
				continue;
			}

			$answers = Requests::request_multiple( array_map( fn( $request ) => array_diff_key( $request, [ 'args' => true ] ), $requests ) );

			foreach ( $answers as $key => $answer ) {
				$response = $answer instanceof Response
					? ( new WP_HTTP_Requests_Response( $answer ) )->to_array()
					: new WP_Error( 'http_request_failed', $answer instanceof \Exception ? $answer->getMessage() : 'Unknown transport error.' );

				$responses[ $key ] = is_wp_error( $response ) ? $response : apply_filters( 'http_response', $response, $requests[ $key ]['args'], $url );
			}
		}

		return $responses;
	}

	/**
	 * Requests options with the certificate and proxy settings of WP_Http::request().
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments after the http_request_args filter.
	 *
	 * @return array<string, mixed>
	 */
	private static function transport_options( $url, array $args ) {
		$verify = (bool) apply_filters( 'https_ssl_verify', $args['sslverify'], $url );

		$options = [
			'timeout'         => $args['timeout'],
			'connect_timeout' => min( 10, $args['timeout'] ),
			'useragent'       => $args['user-agent'],
			'verify'          => $verify ? $args['sslcertificates'] : false,
		];

		$proxy = new \WP_HTTP_Proxy();

		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$options['proxy'] = new \WpOrg\Requests\Proxy\Http(
				$proxy->use_authentication()
					? [ $proxy->host() . ':' . $proxy->port(), $proxy->username(), $proxy->password() ]
					: $proxy->host() . ':' . $proxy->port()
			);
		}

		return $options;
	}

	/**
	 * Build the multipart/form-data body of POST /optimize.
	 *
	 * @param array  $file     File and its options.
	 * @param string $boundary Multipart boundary.
	 *
	 * @phpstan-param File $file
	 *
	 * @return string
	 */
	private function multipart( array $file, $boundary ) {
		$fields = [
			[ 'level', $file['level'] ],
			[ 'keep_metadata', $file['keep_metadata'] ? '1' : '0' ],
		];

		if ( 'custom' === $file['level'] ) {
			$fields[] = [ 'quality', (string) ( $file['quality'] ?? 100 ) ];
		}

		foreach ( $file['outputs'] as $output ) {
			$fields[] = [ 'outputs[]', $output ];
		}

		if ( ! empty( $file['dry_run'] ) ) {
			$fields[] = [ 'dry_run', '1' ];
		}

		$body = '';

		foreach ( $fields as list( $name, $value ) ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		// The server reads the type from the file bytes, so the name only needs to be safe in the header.
		$name  = str_replace( [ '"', "\r", "\n" ], '', wp_basename( $file['path'] ) );
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n";
		$body .= (string) file_get_contents( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= "\r\n--{$boundary}--\r\n";

		return $body;
	}

	/**
	 * Turn a response into the decoded body, or a WP_Error for a problem+json or a broken answer.
	 *
	 * @param array<string, mixed>|WP_Error $response Response in the wp_remote_request() format.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			self::save_last_error( 'http_request_failed', 0, $response->get_error_message(), '' );

			return self::error( 'http_request_failed', $response->get_error_message(), [ 'retryable' => true ] );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		// GET /account answers with limits, POST /optimize with the original and its outputs.
		if ( 200 === $status && is_array( $body ) && ( isset( $body['limits'] ) || ( isset( $body['original']['size'] ) && is_array( $body['outputs'] ?? null ) ) ) ) {
			$body['request_id'] = self::header( $response, 'x-request-id' );

			return $body;
		}

		$code        = is_array( $body ) && is_string( $body['code'] ?? null ) ? $body['code'] : '';
		$detail      = is_array( $body ) && is_string( $body['detail'] ?? null ) ? $body['detail'] : '';
		$fields      = self::field_errors( $body['errors'] ?? null );
		$detail      = trim( $detail . ' ' . $fields );
		$request_id  = self::header( $response, 'x-request-id' );
		$retry_after = self::retry_after( self::header( $response, 'retry-after' ) );
		$message     = self::message( $code, $status, $detail );
		$instance    = is_array( $body ) && is_string( $body['instance'] ?? null ) ? $body['instance'] : '';

		// The problem also names the request: "urn:robin:request:<id>".
		if ( '' === $request_id && 0 === strpos( $instance, 'urn:robin:request:' ) ) {
			$request_id = sanitize_text_field( substr( $instance, strlen( 'urn:robin:request:' ) ) );
		}

		// The invalid fields, for support. Only the server's field names and messages: no key, no file content.
		if ( 422 === $status ) {
			\WRIO_Plugin::app()->logger->error( substr( sprintf( 'v2 request rejected (HTTP 422): %s (X-Request-Id: %s) %s', $code, $request_id, $fields ), 0, 1000 ) );
		}

		self::save_last_error( $code, $status, $detail, $request_id );

		if ( '' !== $request_id ) {
			// translators: %s is the request ID that support uses to find the request in the server logs.
			$message .= ' ' . sprintf( __( '(Request ID: %s)', 'robin-image-optimizer' ), $request_id );
		}

		// The server does not know the key: nothing is sent with it until a license activation or the end of the
		// pause. The limits are asked again then, because the cached limits are of the key before.
		if ( 'invalid_license' === $code ) {
			delete_transient( $this->limits_key() );
			set_transient(
				self::STOP_TRANSIENT,
				[
					'key'     => $this->limits_key(),
					'message' => $message,
				],
				self::STOP_PAUSE
			);
		}

		// Every 5xx waits as a 429 does: one backoff path, so the bulk run shows the busy state.
		if ( in_array( $status, self::BACKOFF_STATUSES, true ) || $status >= 500 ) {
			if ( $retry_after <= 0 ) {
				$retry_after = in_array( $status, self::BACKOFF_STATUSES, true ) ? self::DEFAULT_BACKOFF : self::SERVER_ERROR_BACKOFF;
			}

			// As for a 429 or 503, the file did nothing wrong: a site failure keeps it in the queue for the step
			// after the wait, without a failure and its retry time. Store::save_error() saves busy_code when
			// the same file gets this answer again and again.
			if ( ! in_array( $status, self::BACKOFF_STATUSES, true ) ) {
				$busy_code = '' !== $code ? $code : 'http_' . $status;
				$code      = 'server_busy';
			}

			// Several answers of one group: the latest end wins, so the site never sends before a server asked.
			if ( $retry_after > self::backoff_seconds() ) {
				set_transient(
					self::BACKOFF_TRANSIENT,
					[
						'until'   => time() + $retry_after,
						'scope'   => self::backoff_scope( $this->base_url, $this->site_url ),
						// The files that the wait holds back report the answer that started it.
						'code'    => '' !== $code ? $code : 'http_' . $status,
						'message' => $message,
						// With the status, Store::is_site_failure() keeps a held-back file in the queue whatever the code.
						'status'  => $status,
					],
					$retry_after
				);
			}
		}

		return self::error(
			'' !== $code ? $code : ( 200 === $status ? 'invalid_response' : 'http_' . $status ),
			$message,
			[
				'status'      => $status,
				'request_id'  => $request_id,
				'retry_after' => $retry_after,
				// A 200 that is not the expected JSON comes from a proxy in the way, and can work the next time.
				'retryable'   => in_array( $status, self::RETRYABLE_STATUSES, true ) || 200 === $status,
				'busy_code'   => $busy_code ?? '',
			]
		);
	}

	/**
	 * The field errors of a 422 validation_failed answer, for example "file: The file field is required.".
	 *
	 * @param mixed $errors The errors list of the answer.
	 *
	 * @return string
	 */
	private static function field_errors( $errors ) {
		$lines = [];

		foreach ( is_array( $errors ) ? $errors : [] as $error ) {
			if ( is_array( $error ) && is_string( $error['field'] ?? null ) && is_string( $error['detail'] ?? null ) ) {
				$lines[] = $error['field'] . ': ' . $error['detail'];
			}
		}

		return implode( ' ', $lines );
	}

	/**
	 * Seconds of a Retry-After header: a number of seconds, or an HTTP date (RFC 9110). 0 without a valid value, and
	 * MAX_BACKOFF at most.
	 *
	 * @param string $value Header value.
	 *
	 * @return int
	 */
	private static function retry_after( $value ) {
		if ( is_numeric( $value ) ) {
			return min( self::MAX_BACKOFF, max( 0, (int) $value ) );
		}

		$time = '' !== $value ? strtotime( $value ) : false;

		return false === $time ? 0 : min( self::MAX_BACKOFF, max( 0, $time - time() ) );
	}

	/**
	 * One header of a response. A repeated header gives its first value.
	 *
	 * @param array<string, mixed> $response Response in the wp_remote_request() format.
	 * @param string               $name     Header name.
	 *
	 * @return string
	 */
	private static function header( array $response, $name ) {
		$value = wp_remote_retrieve_header( $response, $name );

		return is_array( $value ) ? (string) reset( $value ) : $value;
	}

	/**
	 * Translated message for a problem code. Unknown codes use the server detail, then the HTTP status.
	 *
	 * @param string $code   Problem code.
	 * @param int    $status HTTP status.
	 * @param string $detail Problem detail from the server.
	 *
	 * @return string
	 */
	private static function message( $code, $status, $detail ) {
		if ( '' !== $detail ) {
			$fallback = $detail;
		} elseif ( 200 === $status ) {
			$fallback = __( 'The optimization server sent an answer that is not valid.', 'robin-image-optimizer' );
		} else {
			// translators: %d is the HTTP status code.
			$fallback = sprintf( __( 'The optimization server answered with HTTP %d.', 'robin-image-optimizer' ), $status );
		}

		return wrio_error_message( 'failed', $code, [ 'fallback' => $fallback ] );
	}

	/**
	 * Keep the last failed request for the dashboard. The message is translated when it is read.
	 *
	 * @param string $code       Problem code. Empty when the server sent none.
	 * @param int    $status     HTTP status. 0 is a transport error.
	 * @param string $detail     Server detail or transport error.
	 * @param string $request_id Request ID.
	 *
	 * @return void
	 */
	private static function save_last_error( $code, $status, $detail, $request_id ) {
		update_option(
			self::LAST_ERROR_OPTION,
			[
				'code'       => $code,
				'status'     => $status,
				'detail'     => $detail,
				'request_id' => $request_id,
				'time'       => time(),
			],
			false
		);
	}

	/**
	 * The last failed request of the API, with a translated message. Null when no request failed.
	 *
	 * @return array{code: string, status: int, message: string, request_id: string, time: int}|null
	 */
	public static function last_error() {
		$error = get_option( self::LAST_ERROR_OPTION );

		if ( ! is_array( $error ) || empty( $error['time'] ) ) {
			return null;
		}

		$code   = (string) ( $error['code'] ?? '' );
		$status = (int) ( $error['status'] ?? 0 );

		return [
			'code'       => $code,
			'status'     => $status,
			'message'    => self::message( $code, $status, (string) ( $error['detail'] ?? '' ) ),
			'request_id' => (string) ( $error['request_id'] ?? '' ),
			'time'       => (int) $error['time'],
		];
	}

	/**
	 * Request headers. No Authorization header means the free plan.
	 *
	 * @return array<string, string>
	 */
	private function headers() {
		$headers = [
			'Accept'     => 'application/json',
			'X-Site-Url' => $this->site_url,
		];

		if ( '' !== $this->license_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->license_key;
		}

		return $headers;
	}

	/**
	 * The User-Agent header.
	 *
	 * @return string For example "robin-image-optimizer/2.1.0 WordPress/6.8 PHP/8.3.1". The server stores the first token as the client.
	 */
	private function user_agent() {
		return sprintf( 'robin-image-optimizer/%s WordPress/%s PHP/%s', defined( 'WRIO_PLUGIN_VERSION' ) ? WRIO_PLUGIN_VERSION : '0', get_bloginfo( 'version' ), PHP_VERSION );
	}

	/**
	 * Keep why an attachment was queued. Cron or the background queue sends it in a later request.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $trigger       upload, regenerate or manual.
	 *
	 * @return void
	 */
	public static function queue_trigger( $attachment_id, $trigger ) {
		update_post_meta( $attachment_id, self::TRIGGER_META, $trigger );
	}

	/**
	 * Use the trigger kept for a queued attachment, or bulk.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public static function use_queued_trigger( $attachment_id ) {
		$trigger       = get_post_meta( $attachment_id, self::TRIGGER_META, true );
		self::$trigger = is_string( $trigger ) && '' !== $trigger ? $trigger : 'bulk';
	}
}
