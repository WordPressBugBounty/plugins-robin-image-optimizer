<?php
/**
 * REST API for the admin app.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under robin-image-optimizer/v1. Every route requires manage_options.
 */
class WRIO_Rest_Controller {

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			WRIO_App::REST_NAMESPACE,
			'/stats',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ __CLASS__, 'check_permissions' ],
				'callback'            => [ __CLASS__, 'get_stats' ],
				'args'                => [
					'scope' => [
						'type'    => 'string',
						'default' => 'media-library',
						'enum'    => [ 'media-library', 'custom-folders', 'nextgen-gallery' ],
					],
				],
			]
		);

		register_rest_route(
			WRIO_App::REST_NAMESPACE,
			'/failures',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ __CLASS__, 'check_permissions' ],
				'callback'            => [ __CLASS__, 'get_failures' ],
				'args'                => [
					'scope' => [
						'type'    => 'string',
						'default' => 'media-library',
						'enum'    => WRIO_App::SCOPES,
					],
					'group' => [
						'type'     => 'string',
						'required' => true,
					],
					// Checked in get_failures().
					'after' => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);

		register_rest_route(
			WRIO_App::REST_NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => [ __CLASS__, 'check_permissions' ],
					'callback'            => [ __CLASS__, 'get_settings' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => [ __CLASS__, 'check_settings_permissions' ],
					'callback'            => [ __CLASS__, 'save_settings' ],
				],
			]
		);

		register_rest_route(
			WRIO_App::REST_NAMESPACE,
			'/settings/reset',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => [ __CLASS__, 'check_settings_permissions' ],
				'callback'            => [ __CLASS__, 'reset_settings' ],
			]
		);

		register_rest_route(
			WRIO_App::REST_NAMESPACE,
			'/backup',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => [ __CLASS__, 'check_permissions' ],
					'callback'            => [ __CLASS__, 'get_backup' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => [ __CLASS__, 'check_permissions' ],
					'callback'            => [ __CLASS__, 'clear_backup' ],
				],
			]
		);

		register_rest_route(
			WRIO_App::REST_NAMESPACE,
			'/app-data',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ __CLASS__, 'check_permissions' ],
				'callback'            => [ __CLASS__, 'get_app_data' ],
				'args'                => [
					'route' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => WRIO_App::ROUTES,
					],
					'scope' => [
						'type'    => 'string',
						'default' => 'media-library',
						'enum'    => WRIO_App::SCOPES,
					],
				],
			]
		);

		// Routes for the app's actions; each callback wraps a shared method.
		$actions = [
			[ '/backup/restore', WP_REST_Server::CREATABLE, 'restore_backup' ],
			[ '/diagnostics', WP_REST_Server::READABLE, 'diagnostics' ],
			[ '/failures/item', WP_REST_Server::CREATABLE, 'failure_action' ],
			[ '/optimization/process', WP_REST_Server::CREATABLE, 'process' ],
			[ '/optimization/schedule/start', WP_REST_Server::CREATABLE, 'schedule_start' ],
			[ '/optimization/schedule/stop', WP_REST_Server::CREATABLE, 'schedule_stop' ],
			[ '/server-status', WP_REST_Server::READABLE, 'server_status' ],
			[ '/background-test', WP_REST_Server::CREATABLE, 'background_test' ],
			[ '/delivery-test', WP_REST_Server::CREATABLE, 'delivery_test_start' ],
			[ '/delivery-test/(?P<id>[a-z0-9]{12})', WP_REST_Server::CREATABLE, 'delivery_test_finish' ],
			[ '/counts/attachments', WP_REST_Server::READABLE, 'count_attachments' ],
			[ '/counts/thumbnails', WP_REST_Server::READABLE, 'count_thumbnails' ],
			[ '/counts/summary', WP_REST_Server::READABLE, 'count_summary' ],
			[ '/folders/browse', WP_REST_Server::READABLE, 'browse_folders' ],
			[ '/folders', WP_REST_Server::CREATABLE, 'add_folder' ],
			[ '/folders/(?P<uid>[a-f0-9]+)/scan', WP_REST_Server::CREATABLE, 'scan_folder' ],
			[ '/folders/(?P<uid>[a-f0-9]+)', WP_REST_Server::DELETABLE, 'remove_folder' ],
			[ '/license', WP_REST_Server::CREATABLE, 'license' ],
			[ '/subscribe', WP_REST_Server::CREATABLE, 'subscribe' ],
			[ '/avif-banner/dismiss', WP_REST_Server::CREATABLE, 'dismiss_avif_banner' ],
			[ '/notices/(?P<notice>milestone|review|avif)/dismiss', WP_REST_Server::CREATABLE, 'dismiss_notice' ],
			[ '/log', WP_REST_Server::DELETABLE, 'clear_logs' ],
		];
		foreach ( $actions as list( $route, $methods, $callback ) ) {
			register_rest_route(
				WRIO_App::REST_NAMESPACE,
				$route,
				[
					'methods'             => $methods,
					'permission_callback' => [ __CLASS__, 'check_permissions' ],
					'callback'            => static function ( WP_REST_Request $request ) use ( $callback ) {
						self::load_admin_environment();

						/**
						 * Route handler.
						 *
						 * @var callable $method
						 */
						$method = [ __CLASS__, $callback ];

						return $method( $request );
					},
				]
			);
		}
	}

	/**
	 * Only administrators use the app.
	 *
	 * @return bool
	 */
	public static function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Saving settings needs the right to change them for every site they apply to: on a network activation they are
	 * network options, so a site administrator may not change them.
	 *
	 * @return bool
	 */
	public static function check_settings_permissions() {
		return current_user_can( WRIO_Plugin::app()->isNetworkActive() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * The shared handlers rely on wp-admin includes (get_home_path(), image
	 * helpers) and on the background processing classes, which WordPress and the
	 * plugin only load for admin requests. Load them for these routes.
	 *
	 * @return void
	 */
	public static function load_admin_environment() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		if ( wrio_is_license_activate() && ! class_exists( 'WRIO_Media_Processing' ) ) {
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-folder-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-nextgen-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing-webp.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing-avif.php';
		}
	}

	/**
	 * Statistics, log and folders for one scope.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response
	 */
	public static function get_stats( WP_REST_Request $request ) {
		return rest_ensure_response( WRIO_App::get_bulk_data( $request->get_param( 'scope' ), true ) );
	}

	/**
	 * A page of the items of one failures group of a scope.
	 *
	 * @param WP_REST_Request $request scope, group, after.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_failures( WP_REST_Request $request ) {
		// The cursor of get_failure_page(): 'Y-m-d H:i:s|id', with a real date. Another value would go to SQL as a
		// DATETIME. Here and not in the route schema: the robin/get-failures ability calls this method without it.
		// The format first: DateTime::createFromFormat() throws a ValueError on a NUL byte (PHP 8.0+).
		$after  = (string) $request->get_param( 'after' );
		$date   = explode( '|', $after )[0];
		$parsed = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\|\d+\z/', $after ) ? DateTime::createFromFormat( 'Y-m-d H:i:s', $date ) : false;
		if ( '' !== $after && ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $date ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid parameter(s): after', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		$scope   = (string) $request->get_param( 'scope' );
		$refused = self::premium_required( $scope );
		if ( $refused ) {
			return $refused;
		}

		// Like the bulk data: no list before the copy of the old rows ends.
		if ( ! \WRIO\Storage\Backfill::reads() ) {
			return rest_ensure_response(
				[
					'items'    => [],
					'total'    => 0,
					'next'     => '',
					'per_page' => 20,
				]
			);
		}

		self::load_admin_environment();

		return rest_ensure_response( WRIO_App::get_statistics( $scope )->get_failure_page( (string) $request->get_param( 'group' ), (string) $request->get_param( 'after' ) ) );
	}

	/**
	 * What a page needs when the app switches to it without a reload.
	 *
	 * @param WP_REST_Request $request route, scope.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response
	 */
	public static function get_app_data( WP_REST_Request $request ) {
		return rest_ensure_response(
			WRIO_App::get_route_data( (string) $request->get_param( 'route' ), (string) $request->get_param( 'scope' ) )
		);
	}

	/**
	 * Current settings values.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return rest_ensure_response( WRIO_Settings::get() );
	}

	/**
	 * Save the submitted settings and return the stored values.
	 *
	 * @param WP_REST_Request $request Request with the values as JSON body.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$input = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $input ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Settings must be sent as a JSON object.', 'robin-image-optimizer' ),
				[ 'status' => 400 ]
			);
		}

		$values = WRIO_Settings::save( $input );

		if ( is_wp_error( $values ) ) {
			$values->add_data( [ 'status' => 400 ] );
		}

		return rest_ensure_response( $values );
	}

	/**
	 * Reset every setting to its default.
	 *
	 * @return WP_REST_Response
	 */
	public static function reset_settings() {
		return rest_ensure_response( WRIO_Settings::reset() );
	}

	/**
	 * Backup folder size and number of backed-up images.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_backup() {
		return rest_ensure_response( WRIO_Settings::backup_stats() );
	}

	/**
	 * Retry or ignore one failed image.
	 *
	 * @param WP_REST_Request $request scope, id, action.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function failure_action( WP_REST_Request $request ) {
		$scope = (string) $request->get_param( 'scope' );
		$scope = in_array( $scope, WRIO_App::SCOPES, true ) ? $scope : 'media-library';

		return self::premium_required( $scope ) ?? self::respond( WRIO_Actions::failure_action( $scope, (int) $request->get_param( 'id' ), (string) $request->get_param( 'action' ) ) );
	}

	/**
	 * The diagnostics report for support.
	 *
	 * @return WP_REST_Response
	 */
	public static function diagnostics() {
		return rest_ensure_response( [ 'text' => wrio_diagnostics() ] );
	}

	/**
	 * One optimization step; the app calls it until `end` is true.
	 *
	 * @param WP_REST_Request $request scope, reset_current_errors, date_from, date_to.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function process( WP_REST_Request $request ) {
		$refused = self::premium_required( (string) $request->get_param( 'scope' ) );
		if ( $refused ) {
			return $refused;
		}

		/**
		 * Filters the wall-clock time budget of a step of the bulk page, in seconds. The step does not start a request
		 * or a retry that would end after it, so a network that does not answer cannot hold the step longer than the
		 * web server allows.
		 *
		 * @param int $seconds Budget in seconds.
		 */
		wrio_set_time_budget( (int) apply_filters( 'wrio_bulk_step_time_budget', 50 ) );

		try {
			return self::respond(
				WRIO_Bulk_Optimization::run_process(
					$request->get_param( 'scope' ),
					(bool) $request->get_param( 'reset_current_errors' ),
					$request->get_param( 'date_from' ),
					$request->get_param( 'date_to' )
				)
			);
		} finally {
			wrio_set_time_budget( null );
		}
	}

	/**
	 * Start the scheduled or background run.
	 *
	 * @param WP_REST_Request $request scope, date_from, date_to.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function schedule_start( WP_REST_Request $request ) {
		$refused = self::premium_required( (string) $request->get_param( 'scope' ) );
		if ( $refused ) {
			return $refused;
		}

		return self::respond( WRIO_Bulk_Optimization::run_schedule_start( $request->get_param( 'scope' ), $request->get_param( 'date_from' ), $request->get_param( 'date_to' ) ) );
	}

	/**
	 * Stop the scheduled or background run.
	 *
	 * @param WP_REST_Request $request scope.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function schedule_stop( WP_REST_Request $request ) {
		$refused = self::premium_required( (string) $request->get_param( 'scope' ) );
		if ( $refused ) {
			return $refused;
		}

		return self::respond( WRIO_Bulk_Optimization::run_schedule_stop( $request->get_param( 'scope' ) ) );
	}

	/**
	 * Is the optimization server reachable for this plan.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function server_status() {
		return self::respond( WRIO_Bulk_Optimization::run_servers_status() );
	}

	/**
	 * Run the health test of Background optimization again: the "Test again" button.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function background_test() {
		$result = wrio_test_background();

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );

			return $result;
		}

		return self::respond( [ 'ok' => true ] );
	}

	/**
	 * Copy the test files and give the requests the browser makes.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delivery_test_start() {
		if ( ! class_exists( 'WRIO\\WEBP\\Delivery_Test' ) ) {
			return new WP_Error( 'wrio_not_available', __( 'The delivery test is not available.', 'robin-image-optimizer' ), [ 'status' => 501 ] );
		}

		return self::respond( WRIO\WEBP\Delivery_Test::start() );
	}

	/**
	 * Classify what the browser got for the test requests and delete the test files.
	 * Without "observed", the site requests the test files itself.
	 *
	 * @param WP_REST_Request $request Request with the id and the "observed" answers.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delivery_test_finish( WP_REST_Request $request ) {
		if ( ! class_exists( 'WRIO\\WEBP\\Delivery_Test' ) ) {
			return new WP_Error( 'wrio_not_available', __( 'The delivery test is not available.', 'robin-image-optimizer' ), [ 'status' => 501 ] );
		}
		$observed = $request->get_param( 'observed' );

		return self::respond( WRIO\WEBP\Delivery_Test::finish( (string) $request->get_param( 'id' ), is_array( $observed ) ? array_values( $observed ) : null ) );
	}

	/**
	 * Attachments the plugin can optimize.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function count_attachments() {
		return self::respond( WRIO_Bulk_Optimization::run_total_attachments() );
	}

	/**
	 * Thumbnails of one page of attachments; repeat with next_offset and total = found_thumbs until done.
	 *
	 * @param WP_REST_Request $request offset, limit, total.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function count_thumbnails( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );

		return self::respond(
			WRIO_Bulk_Optimization::run_total_thumbs(
				(int) $request->get_param( 'offset' ),
				$limit > 0 ? min( 500, $limit ) : 200,
				(int) $request->get_param( 'total' )
			)
		);
	}

	/**
	 * One page of the counts of what a media library run will do; repeat with after = next until next is null.
	 *
	 * @param WP_REST_Request $request after, date_from, date_to.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function count_summary( WP_REST_Request $request ) {
		return self::respond( WRIO_Bulk_Summary::step( (int) $request->get_param( 'after' ), 100, $request->get_param( 'date_from' ), $request->get_param( 'date_to' ) ) );
	}

	/**
	 * Sub-folders of a directory under the site root.
	 *
	 * @param WP_REST_Request $request dir.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function browse_folders( WP_REST_Request $request ) {
		$refused = self::premium_required( 'custom-folders' );
		if ( $refused ) {
			return $refused;
		}
		if ( ! class_exists( 'WRIO_Custom_Folders' ) ) {
			return self::not_available();
		}

		return self::respond( WRIO_Custom_Folders::browse_dir( (string) $request->get_param( 'dir' ), true, false ) );
	}

	/**
	 * Register a folder for optimization.
	 *
	 * @param WP_REST_Request $request path.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_folder( WP_REST_Request $request ) {
		$refused = self::premium_required( 'custom-folders' );
		if ( $refused ) {
			return $refused;
		}
		if ( ! class_exists( 'WRIO_Custom_Folders' ) ) {
			return self::not_available();
		}
		$path = $request->get_param( 'path' );
		// The default trim() also drops null bytes, which addFolder() must see to refuse the path.
		$path = is_string( $path ) ? trim( $path, " \t\n\r\x0B" ) : '';
		if ( '' === $path ) {
			return self::bad_request();
		}

		$folders = WRIO_Custom_Folders::get_instance();
		$folder  = $folders->addFolder( $path );
		if ( is_wp_error( $folder ) ) {
			$folder->add_data( [ 'status' => 400 ] );

			return $folder;
		}

		return rest_ensure_response( $folder->toArray() );
	}

	/**
	 * Index the next batch of files of a folder.
	 *
	 * @param WP_REST_Request $request uid, offset, total.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function scan_folder( WP_REST_Request $request ) {
		$refused = self::premium_required( 'custom-folders' );
		if ( $refused ) {
			return $refused;
		}
		if ( ! class_exists( 'WRIO_Custom_Folders' ) ) {
			return self::not_available();
		}

		return self::respond(
			WRIO_Custom_Folders::scan_step(
				(string) $request->get_param( 'uid' ),
				(int) $request->get_param( 'offset' ),
				(int) $request->get_param( 'total' )
			)
		);
	}

	/**
	 * Exclude a folder from optimization.
	 *
	 * @param WP_REST_Request $request uid.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_folder( WP_REST_Request $request ) {
		$refused = self::premium_required( 'custom-folders' );
		if ( $refused ) {
			return $refused;
		}
		if ( ! class_exists( 'WRIO_Custom_Folders' ) ) {
			return self::not_available();
		}
		$folders = WRIO_Custom_Folders::get_instance();
		$uid     = (string) $request->get_param( 'uid' );
		if ( ! $folders->getFolder( $uid ) ) {
			return new WP_Error( 'wrio_not_found', __( 'Folder not found.', 'robin-image-optimizer' ), [ 'status' => 404 ] );
		}
		$folders->removeFolder( $uid );
		$folders->saveFolders();

		return rest_ensure_response( [ 'removed' => true ] );
	}

	/**
	 * Restore a batch of images from the backup.
	 *
	 * @param WP_REST_Request $request Request; "skip" holds the attachments that failed in earlier steps.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_backup( WP_REST_Request $request ) {
		return self::respond( WRIO_Actions::restore_backup_step( array_map( 'intval', (array) $request->get_param( 'skip' ) ) ) );
	}

	/**
	 * Delete the backup folder.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_backup() {
		return self::respond( WRIO_Actions::clear_backup() );
	}

	/**
	 * Activate, deactivate or unsubscribe the licence.
	 *
	 * @param WP_REST_Request $request action, key.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function license( WP_REST_Request $request ) {
		return self::respond( WRIO_Actions::license( (string) $request->get_param( 'action' ), (string) $request->get_param( 'key' ) ) );
	}

	/**
	 * Newsletter sign-up.
	 *
	 * @param WP_REST_Request $request email.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function subscribe( WP_REST_Request $request ) {
		return self::respond( WRIO_Actions::subscribe( (string) $request->get_param( 'email' ) ) );
	}

	/**
	 * Hide the AVIF banner for the current user.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_avif_banner() {
		return self::respond( WRIO_Actions::dismiss_avif_banner() );
	}

	/**
	 * Hide a rail card (milestone or review) for the current user.
	 *
	 * @param WP_REST_Request $request notice.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_notice( WP_REST_Request $request ) {
		return self::respond( WRIO_Actions::dismiss_notice( (string) $request->get_param( 'notice' ) ) );
	}

	/**
	 * Delete the plugin log files.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clear_logs() {
		return self::respond( WRIO_Actions::clear_logs() );
	}

	/**
	 * Shared result to response: errors keep their status, data is wrapped.
	 *
	 * @param array<mixed>|WP_Error $result Result of a shared method.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function respond( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Custom folders and NextGEN scopes are Pro features; the UI locks them,
	 * the routes refuse them.
	 *
	 * @param string $scope Scope of the request.
	 *
	 * @return WP_Error|null Error for a free plan, null when allowed.
	 */
	protected static function premium_required( $scope ) {
		// An empty scope is reported as a bad request by the handler itself.
		if ( '' === $scope || 'media-library' === $scope || wrio_is_license_activate() ) {
			return null;
		}

		return new WP_Error( 'wrio_premium_required', __( 'This feature requires a premium licence.', 'robin-image-optimizer' ), [ 'status' => 403 ] );
	}

	/**
	 * Error for a missing parameter.
	 *
	 * @return WP_Error
	 */
	protected static function bad_request() {
		return new WP_Error( 'wrio_bad_request', __( 'Missing or invalid parameters.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
	}

	/**
	 * Error when the custom folders add-on is not loaded.
	 *
	 * @return WP_Error
	 */
	protected static function not_available() {
		return new WP_Error( 'wrio_not_available', __( 'Custom folders are not available.', 'robin-image-optimizer' ), [ 'status' => 501 ] );
	}
}
