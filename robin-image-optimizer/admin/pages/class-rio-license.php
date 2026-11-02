<?php
/**
 * License Page class
 *
 * Registers the License submenu page and its ajax actions; the React app renders it.
 *
 * @package    Robin_Image_Optimizer
 * @subpackage Admin\Pages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_License_Page_View
 *
 * Handles the license management admin page.
 */
class WRIO_License_Page_View {

	/**
	 * Page ID
	 *
	 * @var string
	 */
	public $id = 'wrio_license';

	/**
	 * Page hook suffix
	 *
	 * @var string|false
	 */
	private $page_hook;

	/**
	 * Premium provider instance
	 *
	 * @var WRIO_Premium_Provider
	 */
	private $premium;

	/**
	 * License instance
	 *
	 * @var WRIO_License
	 */
	private $license;

	/**
	 * Whether premium is activated
	 *
	 * @var bool|null
	 */
	private $is_premium;

	/**
	 * Plan name
	 *
	 * @var string
	 */
	public $plan_name;

	/**
	 * Subscribe widget instance
	 *
	 * @var WRIO_Subscribe_Widget
	 */
	private $subscribe_widget;

	/**
	 * The page instance, so the app can read its data.
	 *
	 * @var WRIO_License_Page_View|null
	 */
	public static $instance = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		self::$instance   = $this;
		$this->is_premium = null;
		$this->plan_name  = __( 'Robin image optimizer Premium', 'robin-image-optimizer' );

		// Initialize subscribe widget.
		$this->subscribe_widget = new WRIO_Subscribe_Widget();

		add_action( 'admin_menu', [ $this, 'register_page' ], 9 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Initialize premium data
	 *
	 * Called late to ensure WRIO_Plugin is available.
	 *
	 * @return void
	 */
	private function init_premium() {
		if ( null !== $this->premium ) {
			return;
		}

		/**
		 * Initializes the premium provider instance.
		 *
		 * @var WRIO_Premium_Provider $premium
		 */
		$premium       = WRIO_Plugin::app()->premium;
		$this->premium = $premium;
		/**
		 * Gets the license instance from premium provider.
		 *
		 * @var WRIO_License $license
		 */
		$license          = $premium->get_license();
		$this->license    = $license;
		$this->is_premium = $premium->is_activate();
	}

	/**
	 * Register the admin page
	 *
	 * @return void
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			WRIO_Admin_Pages::MAIN,
			__( 'License', 'robin-image-optimizer' ),
			__( 'License', 'robin-image-optimizer' ),
			'manage_options',
			$this->id,
			[ $this, 'render_page' ],
			90 // Position before About Us (which uses 100)
		);
	}

	/**
	 * Enqueue the React app on this page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		WRIO_App::enqueue( 'license' );
	}


	/**
	 * Activate, deactivate or unsubscribe. Shared by admin-ajax and REST.
	 *
	 * @param string $action      activate, deactivate or unsubscribe.
	 * @param string $license_key Key for activate.
	 *
	 * @return array{message: string}|WP_Error
	 */
	public function run_action( $action, $license_key ) {
		$this->init_premium();

		if ( ! in_array( $action, [ 'activate', 'deactivate', 'unsubscribe' ], true ) ) {
			return new WP_Error( 'wrio_bad_request', __( 'Invalid action.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		try {
			switch ( $action ) {
				case 'activate':
					if ( '' === trim( $license_key ) ) {
						return new WP_Error( 'wrio_bad_request', __( 'Please enter a license key.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
					}
					$this->premium->activate( trim( $license_key ) );
					$message = __( 'Your license has been successfully activated.', 'robin-image-optimizer' );
					break;

				case 'deactivate':
					$this->premium->deactivate();
					$message = __( 'Your license has been deactivated.', 'robin-image-optimizer' );
					break;

				default:
					$this->premium->cancel_paid_subscription();
					$message = __( 'Your subscription has been cancelled.', 'robin-image-optimizer' );
					break;
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'wrio_license_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		return [ 'message' => $message ];
	}

	/**
	 * Render the admin page: the React app mounts here.
	 *
	 * @return void
	 */
	public function render_page() {
		echo '<div id="tsdk_banner" class="robin-banner"></div>';
		WRIO_App::render( 'license' );
	}

	/**
	 * License state for the app.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data() {
		$this->init_premium();

		$support    = WRIO_Plugin::app()->get_support();
		$is_premium = (bool) $this->is_premium;
		$license    = $is_premium ? $this->license : null;

		return [
			'isPremium'  => $is_premium,
			'hasPro'     => null !== $this->premium && null !== $this->premium->get_plan_id(),
			'maskedKey'  => $license ? $license->get_masked_key() : '',
			'expiration' => $license ? $license->get_expiration_display() : '',
			'isFreemius' => $license && WRIO_License::SOURCE_FREEMIUS === $license->get_source(),
			'subscribed' => $this->subscribe_widget->is_subscribed(),
			'urls'       => [
				'pricing'   => $support->get_pricing_url( true, 'license_banner' ),
				'contacts'  => $support->get_contacts_url( true, 'license_page' ),
				'migration' => $support->get_contacts_url( true, 'license_migration' ),
				'privacy'   => 'https://themeisle.com/privacy-policy/',
			],
		];
	}
}
