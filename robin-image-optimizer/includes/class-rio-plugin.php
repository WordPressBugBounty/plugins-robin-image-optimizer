<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Основной класс плагина
 *
 * @version       1.0
 */
class WRIO_Plugin extends Wbcr_Factory600_Plugin {

	/**
	 * @see self::app()
	 * @var Wbcr_Factory600_Plugin
	 */
	private static $app;

	/**
	 * @since  3.1.0
	 * @var array
	 */
	private $plugin_data;

	/**
	 * Independent premium provider (replaces Freemius)
	 *
	 * @var WRIO_Premium_Provider|null
	 * @phpstan-ignore-next-line property.phpDocType
	 */
	public $premium;

	/**
	 * Independent support class
	 *
	 * @var WRIO_Support|null
	 */
	private $wrio_support;

	/**
	 * Конструктор
	 *
	 * Применяет конструктор родительского класса и записывает экземпляр текущего класса в свойство $app.
	 * Подробнее о свойстве $app см. self::app()
	 *
	 * @param string $plugin_path
	 * @param array  $data
	 *
	 * @throws \Exception
	 */
	public function __construct( $plugin_path, $data ) {
		parent::__construct( $plugin_path, $data );

		self::$app         = $this;
		$this->plugin_data = $data;

		// Initialize independent premium provider (overrides Factory premium)
		$this->init_independent_premium();

		// Initialize independent support class
		$this->init_independent_support();

		$this->includes();

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// Processing
			if ( wrio_is_license_activate() ) {
				require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-processing.php';
				require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing.php';
				require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-folder-processing.php';
				require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-nextgen-processing.php';

				require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing-webp.php';
				require_once WRIO_PLUGIN_DIR . '/includes/classes/processing/class-rio-media-processing-avif.php';
			}
		}

		if ( is_admin() ) {
			$this->initActivation();

			// completely disable image size threshold
			add_filter( 'big_image_size_threshold', '__return_false' );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				// Ajax files
				require_once WRIO_PLUGIN_DIR . '/admin/ajax/backup.php';
				require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-bulk-optimization.php';
				new WRIO_Bulk_Optimization();

				// require_once( WRIO_PLUGIN_DIR . '/admin/ajax/logs.php' );

				// Not under AJAX logical operator above on purpose to have helpers available to find out whether
				// metas were migrated or not
				require_once WRIO_PLUGIN_DIR . '/admin/ajax/meta-migrations.php';
			}

			add_action(
				'init',
				function () {
					$this->registerPages();
					if ( WRIO_Plugin::app()->premium->is_active() ) {
						update_option( 'robin_image_optimizer_logger_flag', 'yes' );
					}
				}
			);
		}

		add_action( 'plugins_loaded', [ $this, 'pluginsLoaded' ] );

		$sdk_namespace = self::get_sdk_namespace();
		add_filter(
			'themesle_sdk_namespace_' . md5( WRIO_PLUGIN_FILE ),
			function () use ( $sdk_namespace ) {
				return $sdk_namespace;
			}
		);

		add_filter( 'themeisle_sdk_products', [ __CLASS__, 'register_sdk' ] );
		add_filter( 'themeisle_sdk_ran_promos', [ __CLASS__, 'sdk_hide_promo_notice' ] );

		// We hide the license notice as it is not required for this plugin.
		add_filter( $sdk_namespace . '_hide_license_notices', '__return_true' );
		add_filter( $sdk_namespace . '_hide_license_field', '__return_true' );
		add_filter( $sdk_namespace . '_about_us_metadata', [ __CLASS__, 'register_about_page' ] );

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
		add_action( 'admin_print_styles', [ $this, 'truncate_menu_items' ], 100 );
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
		 * @var \WBCR\Factory_600\Premium\Provider|null
		 */
		$license_manager = self::app()->premium;
		if ( $license_manager ) {
			$license_status = $license_manager->is_active() ? 'valid' : 'invalid';

			/**
			 * The license data.
			 *
			 * @var WBCR\Factory_Freemius_Rio_600\Entities\License|null
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

		do_action( 'themeisle_internal_page', WRIO_PLUGIN_DIR, $page_slug );
	}

	/**
	 * Hide SDK promo notice for pro uses.
	 *
	 * @access public
	 */
	public static function sdk_hide_promo_notice( $should_show ) {
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
	 * @param array<string, mixed> $data About page data.
	 *
	 * @return array<string, mixed> About page configuration.
	 */
	public static function register_about_page( $data ) {
		return [
			'location'         => 'rio_general-robin-image-optimizer',
			'logo'             => WRIO_PLUGIN_URL . '/admin/assets/img/icon-256x256.gif',
			'review_link'      => false,
			'has_upgrade_menu' => false,
		];
	}

	/**
	 * Статический метод для быстрого доступа к интерфейсу плагина.
	 *
	 * Позволяет разработчику глобально получить доступ к экземпляру класса плагина в любом месте
	 * плагина, но при этом разработчик не может вносить изменения в основной класс плагина.
	 *
	 * Используется для получения настроек плагина, информации о плагине, для доступа к вспомогательным
	 * классам.
	 *
	 * @return \Wbcr_Factory600_Plugin|\WRIO_Plugin
	 */
	public static function app() {
		return self::$app;
	}

	/**
	 * Подключаем функции бекенда
	 *
	 * @throws Exception
	 */
	public function pluginsLoaded() {
		if ( is_admin() || wrio_doing_cron() || wrio_doing_rest_api() ) {
			$media_library = WRIO_Media_Library::get_instance();
			$media_library->initHooks();
		}

		if ( is_admin() ) {
			require_once WRIO_PLUGIN_DIR . '/admin/boot.php';
			// require_once( WRIO_PLUGIN_DIR . '/admin/includes/classes/class-rio-nextgen-landing.php' );

			// Parent page class
			require_once WRIO_PLUGIN_DIR . '/admin/pages/class-rio-page.php';
			// $this->registerPages();
		}

		if ( wrio_doing_cron() || wrio_doing_rest_api() ) {
			$media_library = WRIO_Media_Library::get_instance();
			$media_library->initHooks();
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
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-views.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-attachment.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-media-library.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/processors/class-rio-server-abstract.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-image-statistic.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-image-query.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-optimization-orchestrator.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-backup.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-optimization-tools.php';

		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-helper.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-object.php'; // Base object

		// Database related models
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-active-record.php';
		// Base class
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-base-extra-data.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-attachment-extra-data.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class.webp-extra-data.php';
		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-server-smushit-extra-data.php';

		require_once WRIO_PLUGIN_DIR . '/includes/classes/models/class-rio-process-queue-table.php'; // Processing queue model

		// Cron
		// ----------------
		require_once WRIO_PLUGIN_DIR . '/includes/classes/class-rio-cron.php';
		new WRIO_Cron();

		// Register cache invalidation hooks for WRIO_Image_Query
		WRIO_Image_Query::register_hooks();
	}

	/**
	 * Инициализируем активацию плагина
	 */
	protected function initActivation() {
		include_once WRIO_PLUGIN_DIR . '/admin/activation.php';
		self::app()->registerActivation( 'WIO_Activation' );
	}

	/**
	 * Регистрируем страницы плагина
	 *
	 * @throws Exception
	 */
	private function registerPages() {
		$admin_path = WRIO_PLUGIN_DIR . '/admin/pages/';

		// Register main menu page first, then submenus
		self::app()->registerPage( 'WRIO_StatisticPage', $admin_path . '/class-rio-statistic.php' );
		self::app()->registerPage( 'WRIO_SettingsPage', $admin_path . '/class-rio-settings.php' );

		if ( ! wrio_is_clearfy_license_activate() ) {
			require_once WRIO_PLUGIN_DIR . '/admin/includes/class-wrio-subscribe-widget.php';
			require_once WRIO_PLUGIN_DIR . '/admin/pages/class-rio-license.php';
			new WRIO_License_Page_View();
		}

		if ( self::app()->getPopulateOption( 'error_log', false ) ) {
			self::app()->registerPage( 'WRIO_LogPage', $admin_path . '/class-rio-log.php' );
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
	 * This overrides the Factory framework's premium property with our
	 * lightweight independent implementation.
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

		$support_config = [
			'url'         => isset( $this->plugin_data['support_details']['url'] ) ? $this->plugin_data['support_details']['url'] : 'https://developer.flavflavor.dev',
			'plugin_name' => $this->getPluginName(),
		];

		// Allow pages_map override if provided in plugin data
		if ( isset( $this->plugin_data['support_details']['pages_map'] ) ) {
			$support_config['pages_map'] = $this->plugin_data['support_details']['pages_map'];
		}

		$this->wrio_support = new WRIO_Support( $support_config );
	}

	/**
	 * Get independent support instance
	 *
	 * This method provides backward compatibility with existing code
	 * that calls $plugin->get_support().
	 *
	 * @return WRIO_Support|null
	 * @phpstan-ignore-next-line return.type
	 */
	public function get_support() {
		if ( ! isset( $this->wrio_support ) ) {
			$this->init_independent_support();
		}

		return $this->wrio_support;
	}

	/**
	 * Truncate the menu item name so it doesn't break the layout of the WP Admin sidebar.
	 *
	 * @return void
	 */
	public function truncate_menu_items() {
		echo '<style>
			#toplevel_page_rio_general-robin-image-optimizer div.wp-menu-name { 
				color: #fff;
				overflow: hidden;
				text-overflow: ellipsis;
				max-width: 130px;
				padding-left: 0 !important;
				white-space: nowrap;
			}
		</style>';
	}
}
