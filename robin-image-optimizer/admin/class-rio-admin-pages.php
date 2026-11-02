<?php
/**
 * Admin pages.
 *
 * Every admin page renders the React app. The page slugs keep the
 * "{id}-robin-image-optimizer" form of the old Factory pages, so saved links,
 * the app's navigation and the screen IDs stay the same.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin pages and loads the app on them.
 */
class WRIO_Admin_Pages {

	/**
	 * Slug suffix of the pages.
	 */
	const SUFFIX = '-robin-image-optimizer';

	/**
	 * Slug of the top-level page.
	 */
	const MAIN = 'rio_general-robin-image-optimizer';

	/**
	 * Slug of the Error Log page. It kept the Factory logger form "{prefix}logger".
	 */
	const LOG = 'wbcr_io_logger-robin-image-optimizer';

	/**
	 * Menu icon: a one-color SVG (Robin's cap with a feather). WordPress paints it in the admin color scheme.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xNy41IDEyLjNDMTYgOCAxMSAzLjggMS41IDEuOGMyLjMgMy42IDMuMyA3IDMgMTAuNXpNMTQuNiA3LjRjMS0yLjYgMi42LTQuNiA0LjktNiAuMSAzLTEuMiA1LjYtMy43IDcuNHpNMSAxMy4zaDcuNGwxLjYgMi41IDEuNi0yLjVIMTlsLTEuMiAzLjVIMi4yeiIvPjwvc3ZnPg==';

	/**
	 * App route and scope of each registered page, by hook suffix.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static $apps = [];

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 9 keeps the submenu order: these pages, License, then the SDK's About Us.
		add_action( 'admin_menu', [ __CLASS__, 'register' ], 9 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_print_styles', [ __CLASS__, 'menu_styles' ], 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( WRIO_PLUGIN_FILE ), [ __CLASS__, 'settings_link' ] );

		// Free plans cannot keep the premium modes the settings page offers.
		add_filter( 'wbcr/factory/option_image_optimization_type', [ __CLASS__, 'free_optimization_type' ] );
		add_filter( 'wbcr/factory/option_convert_avif_format', [ __CLASS__, 'free_avif_format' ] );
	}

	/**
	 * URL of a page.
	 *
	 * @param string $id Page ID without the suffix, e.g. rio_settings.
	 *
	 * @return string
	 */
	public static function url( $id ) {
		return admin_url( 'admin.php?page=' . $id . self::SUFFIX );
	}

	/**
	 * Registers the pages.
	 *
	 * @return void
	 */
	public static function register() {
		$render = [ __CLASS__, 'render' ];

		self::add(
			add_menu_page( __( 'Bulk optimization', 'robin-image-optimizer' ), __( 'Robin Image Optimizer', 'robin-image-optimizer' ), 'manage_options', self::MAIN, $render, self::MENU_ICON, 58 ),
			'bulk'
		);
		add_submenu_page( self::MAIN, __( 'Optimize', 'robin-image-optimizer' ), __( 'Optimize', 'robin-image-optimizer' ), 'manage_options', self::MAIN, $render );

		self::add(
			add_submenu_page( self::MAIN, __( 'Settings', 'robin-image-optimizer' ), __( 'Settings', 'robin-image-optimizer' ), 'manage_options', 'rio_settings' . self::SUFFIX, $render ),
			'settings'
		);

		if ( WRIO_Plugin::app()->getPopulateOption( 'error_log', false ) ) {
			$hook = add_submenu_page( self::MAIN, __( 'Error Log', 'robin-image-optimizer' ), __( 'Error Log', 'robin-image-optimizer' ), 'manage_options', self::LOG, $render );
			self::add( $hook, 'log' );

			if ( $hook ) {
				add_action( 'load-' . $hook, [ __CLASS__, 'maybe_export_log' ] );
			}
		}

		// The app opens these from its tabs. They are top-level pages that
		// menu_styles() hides, as the Factory "internal" pages were.
		self::add(
			add_menu_page( __( 'Custom Folders', 'robin-image-optimizer' ), __( 'Custom Folders', 'robin-image-optimizer' ), 'manage_options', 'io_folders_statistic' . self::SUFFIX, $render, self::MENU_ICON, 58 ),
			'bulk',
			'custom-folders'
		);

		if ( wrio_is_active_nextgen_gallery() ) {
			self::add(
				add_menu_page( __( 'NextGen Gallery', 'robin-image-optimizer' ), __( 'NextGen Gallery', 'robin-image-optimizer' ), 'manage_options', 'io_nextgen_gallery_statistic' . self::SUFFIX, $render, self::MENU_ICON, 58 ),
				'bulk',
				'nextgen-gallery'
			);
		}
	}

	/**
	 * Remembers the app route of a page.
	 *
	 * @param string|false $hook  Hook suffix from add_menu_page() or add_submenu_page().
	 * @param string       $route App route.
	 * @param string       $scope Optimization scope.
	 *
	 * @return void
	 */
	private static function add( $hook, $route, $scope = 'media-library' ) {
		if ( $hook ) {
			self::$apps[ $hook ] = [ $route, $scope ];
		}
	}

	/**
	 * Loads the app on our pages.
	 *
	 * @param string $hook Hook suffix of the current page.
	 *
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( isset( self::$apps[ $hook ] ) ) {
			WRIO_App::enqueue( ...self::$apps[ $hook ] );
		}
	}

	/**
	 * Prints the app mount point. WordPress calls page callbacks from the
	 * page's own hook, so current_action() names the page.
	 *
	 * @return void
	 */
	public static function render() {
		$app = self::$apps[ current_action() ] ?? null;

		if ( null === $app ) {
			return;
		}

		echo '<div id="tsdk_banner" class="robin-banner"></div>';
		WRIO_App::render( ...$app );
	}

	/**
	 * Sends the log archive when the Error Log page is opened with ?action=export.
	 *
	 * @return void
	 */
	public static function maybe_export_log() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below.
		if ( ! isset( $_GET['action'] ) || 'export' !== $_GET['action'] ) {
			return;
		}

		check_admin_referer( 'export-' . WRIO_Plugin::app()->getPluginName() );

		WRIO_Plugin::app()->logger->download_export();
	}

	/**
	 * Hides the top-level entries of the pages opened from the app.
	 *
	 * @return void
	 */
	public static function menu_styles() {
		?>
		<style>
			#toplevel_page_io_folders_statistic-robin-image-optimizer,
			#toplevel_page_io_nextgen_gallery_statistic-robin-image-optimizer {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * Adds the Settings link to the plugin row.
	 *
	 * @param mixed $links Plugin action links. Another plugin's filter can return a non-array.
	 *
	 * @return array<string|int, string>
	 */
	public static function settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			$links = [];
		}

		array_unshift( $links, '<a href="' . esc_url( self::url( 'rio_general' ) ) . '">' . esc_html__( 'Settings', 'robin-image-optimizer' ) . '</a>' );

		return $links;
	}

	/**
	 * Background optimization needs a licence; free plans fall back to the schedule.
	 *
	 * @param mixed $value Option value.
	 *
	 * @return mixed
	 */
	public static function free_optimization_type( $value ) {
		return ! wrio_is_license_activate() && 'background' === $value ? 'schedule' : $value;
	}

	/**
	 * AVIF conversion needs a licence.
	 *
	 * @param mixed $value Option value.
	 *
	 * @return mixed
	 */
	public static function free_avif_format( $value ) {
		return ! wrio_is_license_activate() && $value ? false : $value;
	}
}
