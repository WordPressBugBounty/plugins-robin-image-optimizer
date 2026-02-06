<?php
/**
 * License Page class
 *
 * Self-contained license management page without Factory Templates dependency.
 * Reuses existing factory-bootstrap-500 CSS framework.
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
	 * @phpstan-ignore-next-line property.onlyRead
	 */
	private $premium;

	/**
	 * License instance
	 *
	 * @var WRIO_License
	 * @phpstan-ignore-next-line property.onlyRead
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
	 * Constructor
	 */
	public function __construct() {
		$this->is_premium = null;
		$this->plan_name  = __( 'Robin image optimizer Premium', 'robin-image-optimizer' );

		// Initialize subscribe widget.
		$this->subscribe_widget = new WRIO_Subscribe_Widget();

		add_action( 'admin_menu', [ $this, 'register_page' ], 9 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wrio_license_action', [ $this, 'ajax_handler' ] );

		// Register this page in the Factory navigation menu
		$this->register_in_factory_menu();
	}

	/**
	 * Register this page in the Factory impressive menu system
	 *
	 * This allows the license page to appear in the left navigation bar
	 * alongside other Factory-registered pages.
	 *
	 * @return void
	 */
	private function register_in_factory_menu() {
		global $factory_impressive_page_menu;

		$page_url = admin_url( 'admin.php?page=' . $this->id );

		$factory_impressive_page_menu['robin-image-optimizer'][ $this->id ] = [
			'type'              => 'page',
			'url'               => $page_url,
			'title'             => __( 'License', 'robin-image-optimizer' ) . ' <span class="dashicons dashicons-admin-network"></span>',
			'short_description' => __( 'Product activation', 'robin-image-optimizer' ),
			'position'          => 10,
			'parent'            => 'rio_general',
		];
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
		// Parent slug follows Factory pattern: {page_id}-{plugin_name}
		$parent_slug = 'rio_general-robin-image-optimizer';

		$this->page_hook = add_submenu_page(
			$parent_slug,
			__( 'License', 'robin-image-optimizer' ),
			__( 'License', 'robin-image-optimizer' ),
			'manage_options',
			$this->id,
			[ $this, 'render_page' ],
			90 // Position before About Us (which uses 100)
		);
	}

	/**
	 * Enqueue page assets
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wrio-license-manager',
			WRIO_PLUGIN_URL . '/admin/assets/js/wrio-license-manager.js',
			[ 'jquery' ],
			WRIO_Plugin::app()->getPluginVersion(),
			true
		);

		// Enqueue the Factory bootstrap core CSS (provides .btn, .form-control, etc.)
		wp_enqueue_style(
			'wrio-bootstrap-core',
			WRIO_PLUGIN_URL . '/libs/factory/bootstrap/assets/css-min/bootstrap.core.min.css',
			[],
			WRIO_Plugin::app()->getPluginVersion()
		);

		// Enqueue the license manager CSS from Factory templates
		wp_enqueue_style(
			'wrio-license-manager',
			WRIO_PLUGIN_URL . '/libs/factory/templates/assets/css/license-manager.css',
			[ 'wrio-bootstrap-core' ],
			WRIO_Plugin::app()->getPluginVersion()
		);

		// Enqueue the impressive page template CSS for full layout
		wp_enqueue_style(
			'wrio-impressive-template',
			WRIO_PLUGIN_URL . '/libs/factory/templates/pages/templates/impressive/assets/css/impressive.page.template.css',
			[ 'wrio-bootstrap-core' ],
			WRIO_Plugin::app()->getPluginVersion()
		);

		// Enqueue subscribe widget CSS.
		wp_enqueue_style(
			'wrio-subscribe-widget',
			WRIO_PLUGIN_URL . '/admin/assets/css/wrio-subscribe-widget.css',
			[],
			WRIO_Plugin::app()->getPluginVersion()
		);

		// Hide internal pages from sidebar (Custom Folders, Nextgen Gallery)
		wp_add_inline_style(
			'wrio-impressive-template',
			'#io_folders_statistic-robin-image-optimizer-tab, #io_nextgen_gallery_statistic-robin-image-optimizer-tab { display: none !important; }'
		);
	}

	/**
	 * Handle AJAX license actions
	 *
	 * @return void
	 */
	public function ajax_handler() {
		// Verify nonce
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wrio_license_action' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'robin-image-optimizer' ) ] );
		}

		// Check capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'robin-image-optimizer' ) ] );
		}

		$this->init_premium();

		$action      = isset( $_POST['license_action'] ) ? sanitize_text_field( wp_unslash( $_POST['license_action'] ) ) : '';
		$license_key = isset( $_POST['licensekey'] ) ? trim( wp_unslash( $_POST['licensekey'] ) ) : '';

		$allowed_actions = [ 'activate', 'deactivate', 'unsubscribe' ];

		if ( empty( $action ) || ! in_array( $action, $allowed_actions, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid action.', 'robin-image-optimizer' ) ] );
		}

		try {
			switch ( $action ) {
				case 'activate':
					if ( empty( $license_key ) ) {
						wp_send_json_error( [ 'message' => __( 'Please enter a license key.', 'robin-image-optimizer' ) ] );
					}
					$this->premium->activate( $license_key );
					$message = __( 'Your license has been successfully activated.', 'robin-image-optimizer' );
					break;

				case 'deactivate':
					$this->premium->deactivate();
					$message = __( 'Your license has been deactivated.', 'robin-image-optimizer' );
					break;

				case 'unsubscribe':
					$this->premium->cancel_paid_subscription();
					$message = __( 'Your subscription has been cancelled.', 'robin-image-optimizer' );
					break;

				default:
					wp_send_json_error( [ 'message' => __( 'Unknown action.', 'robin-image-optimizer' ) ] );
			}

			wp_send_json_success( [ 'message' => $message ] );

		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Render the admin page
	 *
	 * @return void
	 */
	public function render_page() {
		$this->init_premium();
		$min_height = $this->calculate_menu_height();
		?>
		<div id="WBCR" class="wrap">
			<div class="wbcr-factory-templates-759-impressive-page-template factory-bootstrap-500 factory-fontawesome-000">
				<div class="wbcr-factory-page wbcr-factory-page-<?php echo esc_attr( $this->id ); ?>">
					<?php $this->render_header(); ?>
					<div class="wbcr-factory-left-navigation-bar">
						<?php $this->render_page_menu(); ?>
					</div>
					<div class="wbcr-factory-page-inner-wrap">
						<div class="wbcr-factory-content-section wbcr-fullwidth">
							<div class="wbcr-factory-content" style="min-height:<?php echo esc_attr( (string) $min_height ); ?>px">
								<div id="wrio-license-wrapper"
									data-loader="<?php echo esc_url( admin_url( 'images/spinner.gif' ) ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'wrio_license_action' ) ); ?>">
									<?php $this->render_license_form(); ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="clearfix"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Calculate minimum height for content based on menu items
	 *
	 * @return int
	 */
	private function calculate_menu_height() {
		global $factory_impressive_page_menu;

		$menu_scope = 'robin-image-optimizer';
		$min_height = 0;

		if ( isset( $factory_impressive_page_menu[ $menu_scope ] ) ) {
			foreach ( $factory_impressive_page_menu[ $menu_scope ] as $page ) {
				if ( ! isset( $page['parent'] ) || empty( $page['parent'] ) ) {
					$min_height += 77;
				}
			}
		}

		return $min_height;
	}

	/**
	 * Render the page header
	 *
	 * @return void
	 */
	private function render_header() {
		?>
		<div class="wbcr-factory-page-header">
			<div class="wbcr-factory-header-logo">
				<?php echo esc_html( WRIO_Plugin::app()->getPluginTitle() ); ?>
				<span class="version"><?php echo esc_html( WRIO_Plugin::app()->getPluginVersion() ); ?></span>
				<span class="dash">—</span>
			</div>
			<div class="wbcr-factory-header-title">
				<h2><?php esc_html_e( 'Page', 'robin-image-optimizer' ); ?>: <?php esc_html_e( 'License', 'robin-image-optimizer' ); ?></h2>
			</div>
			<div class="wbcr-factory-control">
					<?php do_action( 'wbcr_factory_pages_impressive_header', WRIO_Plugin::app()->getPluginName() ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the left navigation menu
	 *
	 * @return void
	 */
	private function render_page_menu() {
		global $factory_impressive_page_menu;

		$menu_scope   = 'robin-image-optimizer';
		$self_page_id = $this->id;

		if ( ! isset( $factory_impressive_page_menu[ $menu_scope ] ) ) {
			return;
		}

		$page_menu = $factory_impressive_page_menu[ $menu_scope ];

		// Sort by position (descending - higher position = higher in menu)
		uasort(
			$page_menu,
			function ( $a, $b ) {
				return $b['position'] <=> $a['position'];
			}
		);

		?>
		<ul>
			<?php
			// First, render parent pages
			foreach ( $page_menu as $page_screen => $page ) :
				if ( ! empty( $page['parent'] ) ) {
					continue;
				}
				$active_tab = ( $page_screen === $self_page_id ) ? ' wbcr-factory-active-tab' : '';
				?>
				<li class="wbcr-factory-nav-tab<?php echo esc_attr( $active_tab ); ?>">
					<a href="<?php echo esc_url( $page['url'] ); ?>"
						id="<?php echo esc_attr( $page_screen ); ?>-tab"
						class="wbcr-factory-tab__link js-wbcr-factory-tab__link">
						<div class="wbcr-factory-tab__title">
							<?php echo wp_kses( $page['title'], [ 'span' => [ 'class' => [] ] ] ); ?>
						</div>
						<?php if ( ! empty( $page['short_description'] ) ) : ?>
							<div class="wbcr-factory-tab__short-description">
								<?php echo esc_html( $page['short_description'] ); ?>
							</div>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>

			<?php
			// Then, render child pages
			foreach ( $page_menu as $page_screen => $page ) :
				if ( empty( $page['parent'] ) ) {
					continue;
				}
				$active_tab = ( $page_screen === $self_page_id ) ? ' wbcr-factory-active-tab' : '';
				?>
				<li class="wbcr-factory-nav-tab<?php echo esc_attr( $active_tab ); ?>">
					<a href="<?php echo esc_url( $page['url'] ); ?>"
						id="<?php echo esc_attr( $page_screen ); ?>-tab"
						class="wbcr-factory-tab__link js-wbcr-factory-tab__link">
						<div class="wbcr-factory-tab__title">
							<?php echo wp_kses( $page['title'], [ 'span' => [ 'class' => [] ] ] ); ?>
						</div>
						<?php if ( ! empty( $page['short_description'] ) ) : ?>
							<div class="wbcr-factory-tab__short-description">
								<?php echo esc_html( $page['short_description'] ); ?>
							</div>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Get license type for CSS class
	 *
	 * @return string free|gift|trial|paid
	 */
	private function get_license_type() {
		if ( ! $this->is_premium || null === $this->license ) {
			return 'free';
		}

		if ( $this->license->is_lifetime() ) {
			return 'gift';
		}

		if ( $this->license->get_expiration_time( 'days' ) < 1 ) {
			return 'trial';
		}

		return 'paid';
	}

	/**
	 * Get hidden license key (first 8 + last 4 characters)
	 *
	 * @return string
	 */
	private function get_hidden_license_key() {
		if ( ! $this->is_premium || null === $this->license ) {
			return '';
		}

		return $this->license->get_masked_key();
	}



	/**
	 * Get unified expiration display string
	 *
	 * @return string
	 */
	private function get_expiration_display() {
		if ( ! $this->is_premium || null === $this->license ) {
			return __( 'N/A', 'robin-image-optimizer' );
		}

		return $this->license->get_expiration_display();
	}


	/**
	 * Render the license form
	 *
	 * @return void
	 */
	private function render_license_form() {
		$license_type = $this->get_license_type();

		$plan_title = __( 'Free', 'robin-image-optimizer' );
		$has_pro    = null !== $this->premium && null !== $this->premium->get_plan_id();
		if ( $has_pro ) {
			$plan_title = __( 'Pro', 'robin-image-optimizer' );
		}

		$license_status_label = __( 'License', 'robin-image-optimizer' );

		?>
		<div id="license-manager"
			class="factory-bootstrap-500 onp-page-wrap <?php echo esc_attr( $license_type ); ?>-license-manager-content">

			<?php if ( ! $has_pro ) : ?>
				<?php $this->render_upgrade_banner(); ?>
			<?php endif; ?>

			<div class="onp-container">
				<div class="license-details">
					<h3>
						<?php echo esc_html( $license_status_label ); ?>
						<span class="license-info-badge <?php echo esc_attr( 'license-info-badge--plan-' . ( $has_pro ? 'pro' : 'free' ) ); ?>">
							<?php echo esc_html( $plan_title ); ?>
						</span>
						<?php if ( $this->is_premium ) : ?>
						<span class="license-info-badge license-info-badge--expiration">
							<?php echo esc_html( $this->get_expiration_display() ); ?>
						</span>
						<?php endif; ?>
					</h3>
				</div>

				<div class="license-input">
					<form action="" method="post">
						<?php if ( $this->is_premium ) : ?>
							<p><?php esc_html_e( 'Have a key to activate the premium version? Paste it here:', 'robin-image-optimizer' ); ?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Have a key to activate the plugin? Paste it here:', 'robin-image-optimizer' ); ?></p>
						<?php endif; ?>

						

						<div class="license-key-wrap">
							<input
								type="text"
								id="license-key"
								name="licensekey"
								value=""
								class="form-control"
								placeholder="<?php echo esc_html( $this->get_hidden_license_key() ); ?>"
							/>

							<?php if ( $this->is_premium ) : ?>
								<button data-action="deactivate" class="button button-primary wrio-license-btn" type="button" id="license-submit">
									<?php esc_html_e( 'Deactivate', 'robin-image-optimizer' ); ?>
								</button>
							<?php else : ?>
								<button data-action="activate" class="button button-primary wrio-license-btn" type="button" id="license-submit">
									<?php esc_html_e( 'Activate', 'robin-image-optimizer' ); ?>
								</button>
							<?php endif; ?>
						</div>

						<?php $this->render_learnmore_section(); ?>

						<div id="license-form-error-container"></div>
						<?php $this->render_freemius_migration_notice(); ?>
					</form>
				</div>

			</div>

			<?php $this->subscribe_widget->render(); ?>
		</div>
		<?php
	}

	/**
	 * Render the upgrade banner for free users
	 *
	 * @return void
	 */
	private function render_upgrade_banner() {
		$support     = WRIO_Plugin::app()->get_support();
		$pricing_url = $support->get_pricing_url( true, 'license_banner' );
		?>
		<div class="wrio-license-upgrade-banner">
			<div class="wrio-license-upgrade-icon">
				<span>⚡</span>
			</div>
			<div class="wrio-license-upgrade-content">
				<h2 class="wrio-license-upgrade-title"><?php esc_html_e( 'Supercharge Your Image Optimization', 'robin-image-optimizer' ); ?></h2>
				<p class="wrio-license-upgrade-subtitle"><?php esc_html_e( "You're using the free version. Upgrade to unlock unlimited conversions and premium features.", 'robin-image-optimizer' ); ?></p>
				<div class="wrio-license-upgrade-features">
					<div class="wrio-license-upgrade-feature">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span><?php esc_html_e( 'Unlimited AVIF', 'robin-image-optimizer' ); ?></span>
					</div>
					<div class="wrio-license-upgrade-feature">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span><?php esc_html_e( 'Custom Folders Optimization', 'robin-image-optimizer' ); ?></span>
					</div>
					<div class="wrio-license-upgrade-feature">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span><?php esc_html_e( 'NextGen Gallery Support', 'robin-image-optimizer' ); ?></span>
					</div>
					<div class="wrio-license-upgrade-feature">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span><?php esc_html_e( 'Priority Support', 'robin-image-optimizer' ); ?></span>
					</div>
					<div class="wrio-license-upgrade-feature">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span><?php esc_html_e( 'More Compression Modes', 'robin-image-optimizer' ); ?></span>
					</div>
					<div class="wrio-license-upgrade-feature">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span><?php esc_html_e( 'Super Page Cache Pro', 'robin-image-optimizer' ); ?></span>
					</div>
				</div>
				<a href="<?php echo esc_url( $pricing_url ); ?>" class="wrio-license-upgrade-button" target="_blank" rel="noopener">
					<?php esc_html_e( 'View Pro Plans', 'robin-image-optimizer' ); ?> →
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render learn more section
	 *
	 * @return void
	 */
	private function render_learnmore_section() {
		$support = WRIO_Plugin::app()->get_support();

		if ( ! $this->is_premium ) :
			?>
			<p style="margin-top: 10px;">
				<?php
				printf(
					wp_kses(
						/* translators: %1$s: opening link tag, %2$s: closing link tag */
						__( 'Can\'t find your key? Go to %1$sthis page%2$s and login using the e-mail address associated with your purchase.', 'robin-image-optimizer' ),
						[
							'a' => [
								'href'   => [],
								'target' => [],
								'rel'    => [],
							],
						]
					),
					'<a href="' . esc_url( $support->get_contacts_url( true, 'license_page' ) ) . '" target="_blank" rel="noopener">',
					'</a>'
				);
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * Render migration notice for Freemius license holders
	 *
	 * @return void
	 */
	private function render_freemius_migration_notice() {
		if ( ! $this->is_premium || null === $this->license || $this->license->get_source() !== WRIO_License::SOURCE_FREEMIUS ) {
			return;
		}
		?>
		<div class="wrio-migration-notice">
			<p>
				<strong><?php esc_html_e( 'Action Required:', 'robin-image-optimizer' ); ?></strong>
				<?php esc_html_e( 'Your license was issued through our previous licensing system (Freemius). Please contact support to receive a new Themeisle license key for continued access to updates and support.', 'robin-image-optimizer' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( WRIO_Plugin::app()->get_support()->get_contacts_url( true, 'license_migration' ) ); ?>" target="_blank" rel="noopener" class="button button-primary">
					<?php esc_html_e( 'Contact Support', 'robin-image-optimizer' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
