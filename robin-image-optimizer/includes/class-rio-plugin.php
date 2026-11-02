<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. WRIO_Plugin::app() returns the instance.
 */
class WRIO_Plugin {

	/**
	 * Prefix of the plugin's option names.
	 */
	const PREFIX = 'wbcr_io_';

	/**
	 * Plugin name, used in hook names and nonces.
	 */
	const NAME = 'wbcr_image_optimizer';

	/**
	 * @see self::app()
	 * @var WRIO_Plugin
	 */
	private static $app;

	/**
	 * Plugin logger.
	 *
	 * @var WRIO_Logger
	 */
	public $logger;

	/**
	 * True while the activation hook of a network activation runs.
	 *
	 * @var bool
	 */
	private $network_activation = false;

	/**
	 * Reads request parameters.
	 *
	 * @var WRIO_Request
	 */
	public $request;

	/**
	 * Independent premium provider (replaces Freemius)
	 *
	 * @var WRIO_Premium_Provider
	 */
	public $premium;

	/**
	 * Independent support class
	 *
	 * @var WRIO_Support
	 */
	private $wrio_support;

	/**
	 * Sets up the plugin.
	 */
	public function __construct() {
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-logger.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-request.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-migrations.php';

		self::$app     = $this;
		$this->logger  = new WRIO_Logger();
		$this->request = new WRIO_Request();

		// Initialize independent premium provider
		$this->init_independent_premium();

		// Initialize independent support class
		$this->init_independent_support();

		$this->includes();

		// Every request: an upload in a REST or front-end request queues its image too (#243).
		if ( wrio_is_license_activate() ) {
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-folder-processing.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-nextgen-processing.php';

			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing-webp.php';
			require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing-avif.php';

			if ( wp_doing_ajax() || wp_doing_cron() ) {
				wrio_register_processing();
			}
		}

		// Outside the admin too: "wp plugin activate" runs without is_admin().
		register_activation_hook( WRIO_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( WRIO_PLUGIN_FILE, [ $this, 'deactivate' ] );

		WRIO_Migrations::init();

		if ( is_admin() ) {
			add_filter( 'big_image_size_threshold', [ $this, 'big_image_size_threshold' ] );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				// Ajax files
				require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-bulk-optimization.php';
				new WRIO_Bulk_Optimization();

				// Not under AJAX logical operator above on purpose to have helpers available to find out whether
				// metas were migrated or not
				require_once WRIO_PLUGIN_DIR . '/admin/ajax/meta-migrations.php';
			}

			add_action(
				'init',
				function () {
					$this->register_license_page();
					if ( WRIO_Plugin::app()->premium->is_active() ) {
						update_option( 'robin_image_optimizer_logger_flag', 'yes' );
					}
				}
			);
		}

		add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );

		$sdk_namespace = self::get_sdk_namespace();
		add_filter(
			'themesle_sdk_namespace_' . md5( WRIO_PLUGIN_FILE ),
			function () use ( $sdk_namespace ) {
				return $sdk_namespace;
			}
		);

		add_filter( 'themeisle_sdk_products', [ __CLASS__, 'register_sdk' ] );
		add_filter( 'themeisle_sdk_ran_promos', [ __CLASS__, 'sdk_hide_promo_notice' ] );
		add_filter( 'themeisle_sdk_blackfriday_data', [ $this, 'add_black_friday_data' ] );

		// We hide the license notice as it is not required for this plugin.
		add_filter( $sdk_namespace . '_hide_license_notices', '__return_true' );
		add_filter( $sdk_namespace . '_hide_license_field', '__return_true' );
		add_filter( $sdk_namespace . '_about_us_metadata', [ __CLASS__, 'register_about_page' ] );
		add_filter( $sdk_namespace . '_ai_connect_metadata', [ __CLASS__, 'register_ai_connect' ] );

		add_filter( 'themeisle-sdk/survey/' . WRIO_PLUGIN_DIR, [ $this, 'get_survey_metadata' ], 10, 1 );
		add_filter(
			$sdk_namespace . '_dissallowed_promotions',
			function ( $promotions ) {
				if ( ! is_array( $promotions ) ) {
					$promotions = [];
				}
				$promotions[] = 'optimole';
				return $promotions;
			}
		);
	}

	/**
	 * Get namespace for SDK.
	 *
	 * @return string
	 */
	public static function get_sdk_namespace() {
		$namespace = basename( WRIO_PLUGIN_DIR );
		$namespace = str_replace( '-', '_', strtolower( trim( $namespace ) ) );
		return $namespace;
	}

	/**
	 * Register survey data.
	 *
	 * @param array<string, mixed> $data The data in Formbricks format.
	 *
	 * @return array<string, mixed> The data in Formbricks format.
	 * @see survey.js in SDK.
	 */
	public function get_survey_metadata( $data ) {
		$install_days_number = intval( ( time() - get_option( self::get_sdk_namespace() . '_install', time() ) ) / DAY_IN_SECONDS );

		$license_status = 'invalid';
		$plan           = null;
		$license_key    = null;

		/**
		 * The premium provider.
		 *
		 * @var WRIO_Premium_Provider|null
		 */
		$license_manager = self::app()->premium;
		if ( $license_manager ) {
			$license_status = $license_manager->is_active() ? 'valid' : 'invalid';

			/**
			 * The license data.
			 *
			 * @var WRIO_License|null
			 */
			$license_data = $license_manager->get_license();

			if ( $license_data ) {
				$plan        = $license_data->get_plan_id();
				$license_key = $license_data->get_hidden_key();
			}
		}

		$data = [
			'environmentId' => 'cmioooac34ugdad0179e060te',
			'attributes'    => [
				'install_days_number' => $install_days_number,
				'free_version'        => WRIO_PLUGIN_VERSION,
				'license_status'      => $license_status,
			],
		];

		if ( $plan ) {
			$data['attributes']['plan'] = $plan;
		}

		if ( $license_key ) {
			$data['attributes']['license_key'] = apply_filters( 'themeisle_sdk_secret_masking', $license_key );
		}

		$stats = WRIO_Image_Statistic::get_instance()->get();

		if ( ! empty( $stats['optimized'] ) ) {
			$data['attributes']['optimized_images_count'] = $stats['optimized'];
		}

		if ( ! empty( $stats['unoptimized'] ) ) {
			$data['attributes']['unoptimized_images_count'] = $stats['unoptimized'];
		}

		if ( ! empty( $stats['optimized_percent'] ) ) {
			$data['attributes']['optimized_images_percent'] = round( floatval( $stats['optimized_percent'] ), 2 );
		}

		if ( ! empty( $stats['converted'] ) ) {
			$data['attributes']['converted_images_count'] = $stats['converted'];
		}

		if ( ! empty( $stats['unconverted'] ) ) {
			$data['attributes']['unconverted_images_count'] = $stats['unconverted'];
		}

		if ( ! empty( $stats['save_size_percent'] ) ) {
			$data['attributes']['saved_size_percent'] = round( floatval( $stats['save_size_percent'] ), 2 );
		}

		if ( ! empty( $stats['webp_optimized_size'] ) ) {
			$data['attributes']['webp_optimized_total_size_bytes'] = intval( $stats['webp_optimized_size'] );
		}

		if ( ! empty( $stats['webp_percent_line'] ) ) {
			$data['attributes']['webp_optimized_size_percent'] = round( floatval( $stats['webp_percent_line'] ), 2 );
		}

		if ( ! empty( $stats['original_size'] ) ) {
			$data['attributes']['original_total_size_bytes'] = intval( $stats['original_size'] );
		}

		if ( ! empty( $stats['original'] ) ) {
			$data['attributes']['original_images_count'] = $stats['original'];
		}

		if ( ! empty( $stats['error'] ) ) {
			$data['attributes']['error_images_count'] = $stats['error'];
		}

		return $data;
	}


	/**
	 * Mark internal page of the plugin.
	 *
	 * @return void
	 */
	public function mark_internal_page() {
		$current_screen = get_current_screen();

		if ( ! $current_screen ) {
			return;
		}

		$page_id   = $current_screen->id;
		$page_slug = null;

		if ( 'toplevel_page_rio_general-robin-image-optimizer' === $page_id ) {
			$page_slug = 'bulk-optimization';
		} elseif ( 'toplevel_page_io_folders_statistic-robin-image-optimizer' === $page_id ) {
			$page_slug = 'custom-folders';
		} elseif ( 'robin-image-optimizer_page_rio_settings-robin-image-optimizer' === $page_id ) {
			$page_slug = 'settings';
		} elseif ( 'robin-image-optimizer_page_wbcr_io_logger-robin-image-optimizer' === $page_id ) {
			$page_slug = 'error-log';
		} elseif ( 'robin-image-optimizer_page_wrio_license' === $page_id ) {
			$page_slug = 'license';
		} elseif ( 'toplevel_page_io_nextgen_gallery_statistic-robin-image-optimizer' === $page_id ) {
			$page_slug = 'nextgen-gallery';
		}

		if ( null === $page_slug ) {
			return;
		}

		WRIO_App::enqueue_script( 'wrio-notices', 'notices', true );

		do_action( 'themeisle_internal_page', WRIO_PRODUCT_SLUG, $page_slug );
	}

	/**
	 * Set the black friday data.
	 *
	 * @param array<string, mixed> $configs The configuration array for the loaded products.
	 *
	 * @return array<string, mixed> The configurations.
	 */
	public function add_black_friday_data( $configs ) {
		$config = $configs['default'];

		$message   = __( 'Bulk optimization, WebP & AVIF conversion, lossless & lossy modes. Stop losing visitors to slow images. Exclusively for existing Robin users.', 'robin-image-optimizer' );
		$cta_label = __( 'Get Robin Pro', 'robin-image-optimizer' );

		$sdk_namespace = self::get_sdk_namespace();
		$plan          = apply_filters( 'product_' . $sdk_namespace . '_license_plan', 0 );
		$license       = apply_filters( 'product_' . $sdk_namespace . '_license_key', false );
		$status        = apply_filters( 'product_' . $sdk_namespace . '_license_status', false );

		$is_pro     = 'valid' === $status;
		$is_expired = in_array( $status, [ 'expired', 'active_expired', 'active-expired' ], true );

		if ( $is_pro ) {
			// translators: %s is the discount percentage.
			$config['plugin_meta_message'] = sprintf( __( 'Black Friday Sale - up to %s off', 'robin-image-optimizer' ), '30%' );
			// translators: %1$s - discount, %2$s - discount.
			$message   = sprintf( __( 'Upgrade your Robin Pro plan: %1$s off this week. Already on the plan you need? Renew early and save up to %2$s.', 'robin-image-optimizer' ), '30%', '20%' );
			$cta_label = __( 'See your options', 'robin-image-optimizer' );
		} elseif ( $is_expired ) {
			// translators: %s is the discount percentage.
			$config['upgrade_menu_text'] = sprintf( __( 'BF Sale - %s off', 'robin-image-optimizer' ), '50%' );
			// translators: %s is the discount percentage.
			$config['plugin_meta_message'] = sprintf( __( 'Black Friday Sale - %s off', 'robin-image-optimizer' ), '50%' );
			$message                       = __( 'Your Robin Pro features are still here, just locked. Renew at a reduced rate this week.', 'robin-image-optimizer' );
			$cta_label                     = __( 'Reactivate now', 'robin-image-optimizer' );
		} else {
			// translators: %s is the discount percentage.
			$config['plugin_meta_message'] = sprintf( __( 'Black Friday Sale - %s off', 'robin-image-optimizer' ), '60%' );
			// translators: %s - discount.
			$config['title'] = sprintf( __( 'Robin Pro: %s off this week', 'robin-image-optimizer' ), '60%' );
			// translators: %s is the discount percentage.
			$config['upgrade_menu_text'] = sprintf( __( 'BF Sale - %s off', 'robin-image-optimizer' ), '60%' );
		}

		$url_params = [
			'utm_term' => $is_pro ? 'plan-' . $plan : 'free',
			'lkey'     => ! empty( $license ) ? $license : false,
			'expired'  => $is_expired ? '1' : false,
		];

		$config['cta_label'] = $cta_label;
		$config['message']   = $message;
		$config['sale_url']  = add_query_arg(
			$url_params,
			tsdk_translate_link( tsdk_utmify( 'https://themeisle.link/robin-image-optimizer-bf', 'bfcm', 'robin' ) )
		);

		$configs[ WRIO_PRODUCT_SLUG ] = $config;

		return $configs;
	}

	/**
	 * Hide SDK promo notice for pro uses.
	 *
	 * @access public
	 */
	public static function sdk_hide_promo_notice() {
		return self::app()->premium->is_active();
	}
	/**
	 * Register product into SDK.
	 *
	 * @param array $products All products.
	 *
	 * @return array Registered product.
	 */
	public static function register_sdk( $products ) {
		$products[] = WRIO_PLUGIN_FILE;

		return $products;
	}

	/**
	 * Register About Us page metadata for ThemeIsle SDK.
	 *
	 * @return array<string, mixed> About page configuration.
	 */
	public static function register_about_page() {
		$status      = apply_filters( 'product_' . self::get_sdk_namespace() . '_license_status', false );
		$upgrade_url = rtrim( self::app()->get_support()->get_site_url(), '/' ) . '/upgrade';

		return [
			'location'         => 'rio_general-robin-image-optimizer',
			'logo'             => WRIO_PLUGIN_URL . '/admin/assets/img/icon-256x256.gif',
			'review_link'      => false,
			'has_upgrade_menu' => 'valid' !== $status,
			'upgrade_text'     => __( 'Upgrade to Pro', 'robin-image-optimizer' ),
			'upgrade_link'     => tsdk_translate_link( tsdk_utmify( $upgrade_url, 'sidebarMenuUpgrade', 'robin' ) ),
		];
	}

	/**
	 * Register the AI Connect data.
	 *
	 * @return array<string, mixed> The AI Connect data.
	 */
	public static function register_ai_connect() {
		return [
			'name'           => 'Robin Image Optimizer',
			'notice_cases'   => [
				__( 'optimize your media library', 'robin-image-optimizer' ),
				__( 'convert images to WebP', 'robin-image-optimizer' ),
				__( 'restore original images', 'robin-image-optimizer' ),
			],
			'prompts'        => [
				__( 'Optimize every image in my media library with Robin Image Optimizer.', 'robin-image-optimizer' ),
				__( 'Turn on automatic optimization for new uploads, WebP conversion and backups of the originals.', 'robin-image-optimizer' ),
				__( 'Restore the original of my homepage hero image.', 'robin-image-optimizer' ),
			],
			'ability_prefix' => 'robin',
		];
	}

	/**
	 * The plugin instance.
	 *
	 * @return WRIO_Plugin
	 */
	public static function app() {
		return self::$app;
	}

	/**
	 * Option prefix.
	 *
	 * @return string wbcr_io_
	 */
	public function getPrefix() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return self::PREFIX;
	}

	/**
	 * Internal plugin name.
	 *
	 * @return string wbcr_image_optimizer
	 */
	public function getPluginName() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return self::NAME;
	}

	/**
	 * Plugin title.
	 *
	 * @return string
	 */
	public function getPluginTitle() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return 'Robin image optimizer';
	}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public function getPluginVersion() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return WRIO_PLUGIN_VERSION;
	}

	/**
	 * Whether the plugin is network active, or is being network activated now.
	 *
	 * @return bool
	 */
	public function isNetworkActive() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		if ( $this->network_activation || is_plugin_active_for_network( plugin_basename( WRIO_PLUGIN_FILE ) ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the activation request.
		return $this->isNetworkAdmin() && isset( $_GET['action'], $_GET['networkwide'] ) && 'activate' === $_GET['action'] && 1 === (int) $_GET['networkwide'];
	}

	/**
	 * Whether the current request is in the network admin.
	 *
	 * @return bool
	 */
	public function isNetworkAdmin() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return is_multisite() && is_network_admin();
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- the Factory option API names, kept for their ~250 callers.

	/**
	 * Full option name: wbcr_io_{name}.
	 *
	 * @param string $option_name Name without the prefix.
	 *
	 * @return string
	 */
	public function getOptionName( $option_name ) {
		return self::PREFIX . trim( $option_name );
	}

	/**
	 * Site option, or network option when the plugin is network active.
	 *
	 * @param string $option_name   Name without the prefix.
	 * @param mixed  $default_value Returned when the option is missing.
	 *
	 * @return mixed
	 */
	public function getPopulateOption( $option_name, $default_value = false ) {
		$value = $this->isNetworkActive() ? $this->getNetworkOption( $option_name, $default_value ) : $this->getOption( $option_name, $default_value );

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Factory hook name, kept for compatibility.
		return apply_filters( "wbcr/factory/populate_option_{$option_name}", $value, $option_name, $default_value );
	}

	/**
	 * Updates a site option, or the network option when the plugin is network active.
	 *
	 * @param string $option_name Name without the prefix.
	 * @param mixed  $value       Value.
	 *
	 * @return void
	 */
	public function updatePopulateOption( $option_name, $value ) {
		if ( $this->isNetworkActive() ) {
			$this->updateNetworkOption( $option_name, $value );
		} else {
			$this->updateOption( $option_name, $value );
		}
	}

	/**
	 * Deletes a site option, or the network option when the plugin is network active.
	 *
	 * @param string $option_name Name without the prefix.
	 *
	 * @return void
	 */
	public function deletePopulateOption( $option_name ) {
		if ( $this->isNetworkActive() ) {
			delete_site_option( $this->getOptionName( $option_name ) );
		} else {
			$this->deleteOption( $option_name );
		}
	}

	/**
	 * Network option on multisite, otherwise the site option.
	 *
	 * @param string $option_name   Name without the prefix.
	 * @param mixed  $default_value Returned when the option is missing.
	 *
	 * @return mixed
	 */
	public function getNetworkOption( $option_name, $default_value = false ) {
		if ( ! is_multisite() ) {
			return $this->getOption( $option_name, $default_value );
		}

		$value = get_site_option( $this->getOptionName( $option_name ), null );
		$value = null === $value ? $default_value : self::normalize( $value );

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Factory hook name, kept for compatibility.
		return apply_filters( "wbcr/factory/network_option_{$option_name}", $value, $option_name, $default_value, get_current_network_id() );
	}

	/**
	 * Updates the network option on multisite, otherwise the site option.
	 *
	 * @param string $option_name Name without the prefix.
	 * @param mixed  $value       Value.
	 *
	 * @return void
	 */
	public function updateNetworkOption( $option_name, $value ) {
		update_site_option( $this->getOptionName( $option_name ), $value );

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Factory hook name, kept for compatibility.
		do_action( 'wbcr/factory/update_network_option', $option_name, $value );
	}

	/**
	 * Site option.
	 *
	 * @param string $option_name   Name without the prefix.
	 * @param mixed  $default_value Returned when the option is missing.
	 *
	 * @return mixed
	 */
	public function getOption( $option_name, $default_value = false ) {
		$value = get_option( $this->getOptionName( $option_name ), null );
		$value = null === $value ? $default_value : self::normalize( $value );

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Factory hook name, kept for compatibility.
		return apply_filters( "wbcr/factory/option_{$option_name}", $value, $option_name, $default_value );
	}

	/**
	 * Updates a site option.
	 *
	 * @param string $option_name Name without the prefix.
	 * @param mixed  $value       Value.
	 *
	 * @return bool
	 */
	public function updateOption( $option_name, $value ) {
		$result = update_option( $this->getOptionName( $option_name ), $value );

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Factory hook name, kept for compatibility.
		do_action( 'wbcr/factory/update_option', $option_name, $value );

		return $result;
	}

	/**
	 * Deletes a site option.
	 *
	 * @param string $option_name Name without the prefix.
	 *
	 * @return bool
	 */
	public function deleteOption( $option_name ) {
		return delete_option( $this->getOptionName( $option_name ) );
	}

	// phpcs:enable

	/**
	 * Stored strings "1"/"0" become ints and "true"/"false" become bools, as the Factory options did.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );

		if ( '1' === $trimmed || '0' === $trimmed ) {
			return (int) $trimmed;
		}

		if ( 'true' === $trimmed || 'false' === $trimmed ) {
			return 'true' === $trimmed;
		}

		return $value;
	}

	/**
	 * Loads the back-end features.
	 *
	 * @throws Exception
	 */
	public function plugins_loaded(): void {
		// The upload hooks: wp-admin, cron, REST uploads, and WP-CLI (wp media import).
		if ( is_admin() || wrio_doing_cron() || wrio_doing_rest_api() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$media_library = WRIO_Media_Library::get_instance();
			$media_library->initHooks();
		}

		// React admin app, its settings schema and REST API. The routes only
		// answer REST requests; loading them everywhere keeps the save path
		// identical in admin, REST and test contexts.
		require_once WRIO_PLUGIN_DIR . '/admin/class-rio-app.php';
		require_once WRIO_PLUGIN_DIR . '/admin/class-rio-settings.php';
		require_once WRIO_PLUGIN_DIR . '/admin/class-rio-actions.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-bulk-optimization.php';
		require_once WRIO_PLUGIN_DIR . '/admin/class-rio-rest-controller.php';
		WRIO_Rest_Controller::init();

		if ( is_admin() ) {
			require_once WRIO_PLUGIN_DIR . '/admin/boot.php';

			require_once WRIO_PLUGIN_DIR . '/admin/class-rio-admin-pages.php';
			WRIO_Admin_Pages::init();
		}

		// Load premium addon for all users (WebP conversion is available for free users)
		require_once WRIO_PLUGIN_DIR . '/libs/addons/robin-image-optimizer-premium.php';
		wrio_premium_load();

		add_action( 'admin_enqueue_scripts', [ $this, 'mark_internal_page' ] );
	}

	/**
	 * Подключаем модули классы и функции
	 */
	protected function includes() {

		require_once WRIO_PLUGIN_DIR . '/includes/functions.php';
		require_once WRIO_PLUGIN_DIR . '/includes/error-help.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-views.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-attachment.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-media-library.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-formats.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/v2/class-client.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/v2/class-optimizer.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/v2/class-format-rows.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/storage/class-schema.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/storage/class-store.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/storage/class-backfill.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/storage/class-queries.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/storage/class-events.php';
		add_filter( 'wpmu_drop_tables', [ '\\WRIO\\Storage\\Schema', 'drop_site_tables' ] );
		\WRIO\Storage\Schema::maybe_install();
		\WRIO\Storage\Backfill::init();
		\WRIO\Storage\Events::init();
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-image-statistic.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-image-query.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-optimization-orchestrator.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-bulk-summary.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-media-bulk-action.php';
		WRIO_Media_Bulk_Action::init();
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-backup.php';

		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-helper.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-object.php'; // Base object

		// Database related models
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-active-record.php';
		// Base class
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-extra-data.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-attachment-extra-data.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class.webp-extra-data.php';

		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-process-queue-table.php'; // Processing queue model
		RIO_Process_Queue::try_create_plugin_tables();
		add_action( 'wp_initialize_site', [ $this, 'install_site_tables' ], 100 );
		add_filter( 'wpmu_drop_tables', [ $this, 'drop_queue_table' ] );

		// Cron
		// ----------------
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-cron.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-avif-estimate.php';
		WRIO_Avif_Estimate::init();
		new WRIO_Cron();
		// For a system cron job that runs the queue without a loopback request.
		add_action(
			'wrio/queue/run',
			static function () {
				wrio_run_queue();
			}
		);
		add_action( 'wp_ajax_wrio_loopback_test', 'wrio_loopback_test_handler' );
		if ( $this->getPopulateOption( 'fetchpriority_first_image', false ) ) {
			// Before wptexturize() (10), after do_blocks() (9): a ">" in a quoted value does not break the tag.
			add_filter( 'the_content', 'wrio_escape_img_attribute_gt', 9 );
			// Before wp_filter_content_tags() (12): WordPress then reads the existing attributes.
			add_filter( 'the_content', 'wrio_normalize_priority_attributes', 11 );
			// After wp_filter_content_tags() (12): an attribute that WordPress added stays.
			add_filter( 'the_content', 'wrio_add_fetchpriority', 20 );
			// The whole page decides: a priority that later content renders wins over the one Robin added.
			add_action( 'template_redirect', 'wrio_start_priority_buffer' );
		}
		add_action( 'wp_ajax_nopriv_wrio_loopback_test', 'wrio_loopback_test_handler' );

		// Register cache invalidation hooks for WRIO_Image_Query
		WRIO_Image_Query::register_hooks();

		// Abilities API (WordPress 6.9+)
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-abilities.php';
		WRIO_Abilities::init();
	}

	/**
	 * Turns off the WordPress big image scaling only while Robin resizes large images itself.
	 *
	 * @param int|false $threshold Threshold in pixels, or false when scaling is off.
	 *
	 * @return int|false
	 */
	public function big_image_size_threshold( $threshold ) {
		return $this->getPopulateOption( 'resize_larger', false ) ? false : $threshold;
	}

	/**
	 * Creates the tables of a new site of a network where the plugin is network active.
	 *
	 * @param WP_Site $site The new site.
	 *
	 * @return void
	 */
	public function install_site_tables( $site ) {
		if ( ! $this->isNetworkActive() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		RIO_Process_Queue::try_create_plugin_tables();
		\WRIO\Storage\Schema::maybe_install();
		restore_current_blog();
	}

	/**
	 * Adds the queue table of a deleted site to the tables that WordPress drops.
	 *
	 * @param string[] $tables Tables of the site.
	 *
	 * @return string[]
	 */
	public function drop_queue_table( $tables ) {
		$tables[] = RIO_Process_Queue::table_name();

		return $tables;
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is network activated. WP-CLI has no ?networkwide=1 to read.
	 *
	 * @return void
	 */
	public function activate( $network_wide = false ) {
		$this->network_activation = (bool) $network_wide;
		require_once WRIO_PLUGIN_DIR . '/admin/activation.php';
		( new WIO_Activation() )->activate();
		WRIO_Migrations::record_activation();
		$this->network_activation = false;
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public function deactivate() {
		require_once WRIO_PLUGIN_DIR . '/admin/activation.php';
		( new WIO_Activation() )->deactivate();
	}

	/**
	 * Registers the License page, unless Clearfy holds the licence.
	 * WRIO_Admin_Pages registers the other pages.
	 *
	 * @return void
	 */
	private function register_license_page() {
		if ( ! wrio_is_clearfy_license_activate() ) {
			require_once WRIO_PLUGIN_DIR . '/admin/includes/class-wrio-subscribe-widget.php';
			require_once WRIO_PLUGIN_DIR . '/admin/pages/class-rio-license.php';
			new WRIO_License_Page_View();
		}
	}

	/**
	 * Option enables error logging on frontend. If for some reason webp images are not displayed on the front-end, you can use
	 * this option to catch errors and send this report to the plugin support service.
	 *
	 * @return int
	 * @since  1.3.6
	 */
	public function is_keep_error_log_on_frontend() {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		return (int) $this->getPopulateOption( 'keep_error_log_on_frontend', 0 );
	}

	/**
	 * Initialize independent premium provider
	 *
	 * @return void
	 */
	private function init_independent_premium() {
		// Load the independent license and premium classes
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-wrio-license.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-wrio-premium-provider.php';

		// Create the independent premium provider
		$this->premium = new WRIO_Premium_Provider();
	}

	/**
	 * Initialize independent support class
	 *
	 * @return void
	 */
	private function init_independent_support() {
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-wrio-support.php';

		$this->wrio_support = new WRIO_Support(
			[
				'url'         => 'https://robinoptimizer.com',
				'plugin_name' => $this->getPluginName(),
				'pages_map'   => [
					'features' => 'premium-features',
					'pricing'  => 'pricing',
					'support'  => 'support',
					'docs'     => 'docs',
				],
			]
		);
	}

	/**
	 * Get independent support instance
	 *
	 * This method provides backward compatibility with existing code
	 * that calls $plugin->get_support().
	 *
	 * @return WRIO_Support
	 */
	public function get_support() {
		return $this->wrio_support;
	}
}
