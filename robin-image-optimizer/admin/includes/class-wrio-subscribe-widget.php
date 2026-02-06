<?php
/**
 * Subscribe Widget Class
 *
 * Self-contained newsletter subscription widget that can be used on any admin page.
 * No dependencies on Factory libs.
 *
 * @package    Robin_Image_Optimizer
 * @subpackage Admin\Includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WRIO_Subscribe_Widget
 */
class WRIO_Subscribe_Widget {

	/**
	 * ThemeIsle subscription API endpoint
	 */
	const API_URL = 'https://api.themeisle.com/tracking/subscribe';

	/**
	 * Option name to track subscription status
	 */
	const OPTION_SUBSCRIBED = 'wrio_user_subscribed';

	/**
	 * Plugin slug for API
	 *
	 * @var string
	 */
	private $plugin_name = 'wbcr_image_optimizer';

	/**
	 * Privacy policy URL
	 *
	 * @var string
	 */
	private $privacy_url = 'https://themeisle.com/privacy-policy/';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_ajax_wrio_subscribe', [ $this, 'ajax_handler' ] );
	}

	/**
	 * Check if user has already subscribed
	 *
	 * @return bool
	 */
	public function is_subscribed() {
		return (bool) get_option( self::OPTION_SUBSCRIBED, false );
	}

	/**
	 * Render the subscribe widget
	 *
	 * @return void
	 */
	public function render() {
		if ( $this->is_subscribed() ) {
			return;
		}

		$nonce = wp_create_nonce( 'wrio_subscribe' );
		?>
		<div class="wrio-subscribe-widget">
			<div class="wrio-subscribe-widget__header">
				<h3><?php esc_html_e( 'Subscribe to plugin\'s newsletter', 'robin-image-optimizer' ); ?></h3>
			</div>
			<div class="wrio-subscribe-widget__body">
				<p><?php esc_html_e( 'Get the latest news and updates about the plugin:', 'robin-image-optimizer' ); ?></p>
				<div class="wrio-subscribe-widget__messages">
					<div class="wrio-subscribe-widget__success" style="display:none;">
						<?php esc_html_e( 'Thank you for subscribing.', 'robin-image-optimizer' ); ?>
					</div>
					<div class="wrio-subscribe-widget__error" style="display:none;"></div>
				</div>
				<form class="wrio-subscribe-widget__form">
					<div class="wrio-subscribe-widget__field-wrap">
						<input
							type="email"
							name="email"
							class="wrio-subscribe-widget__email"
							placeholder="<?php esc_attr_e( 'Enter your email address', 'robin-image-optimizer' ); ?>"
							required
						>
						<button type="submit" class="button button-primary wrio-subscribe-widget__button">
							<?php esc_html_e( 'Subscribe', 'robin-image-optimizer' ); ?>
						</button>
					</div>
					<label class="wrio-subscribe-widget__checkbox-label">
						<input type="checkbox" name="agree_terms" checked required>
						<?php
						printf(
							/* translators: %1$s: opening link tag, %2$s: closing link tag */
							esc_html__( 'I agree to receive the Themeisle newsletter. See our %1$sPrivacy Policy%2$s for details.', 'robin-image-optimizer' ),
							'<a href="' . esc_url( $this->privacy_url ) . '" target="_blank" rel="noopener">',
							'</a>'
						);
						?>
					</label>
				</form>
			</div>
		</div>
		<script>
		(function() {
			const form = document.querySelector('.wrio-subscribe-widget__form');
			if (!form) return;

			form.addEventListener('submit', function(e) {
				e.preventDefault();

				const button = form.querySelector('.wrio-subscribe-widget__button');
				const successEl = document.querySelector('.wrio-subscribe-widget__success');
				const errorEl = document.querySelector('.wrio-subscribe-widget__error');
				const email = form.querySelector('[name="email"]').value;
				const agreed = form.querySelector('[name="agree_terms"]').checked;

				if (!agreed) {
					return;
				}

				button.disabled = true;
				button.textContent = '<?php echo esc_js( __( 'Subscribing...', 'robin-image-optimizer' ) ); ?>';
				errorEl.style.display = 'none';

				const data = new FormData();
				data.append('action', 'wrio_subscribe');
				data.append('email', email);
				data.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');

				fetch(ajaxurl, {
					method: 'POST',
					body: data
				})
				.then(r => r.json())
				.then(res => {
					if (res.success) {
						form.style.display = 'none';
						successEl.style.display = 'block';
					} else {
						errorEl.textContent = res.data.message || '<?php echo esc_js( __( 'An error occurred. Please try again.', 'robin-image-optimizer' ) ); ?>';
						errorEl.style.display = 'block';
						button.disabled = false;
						button.textContent = '<?php echo esc_js( __( 'Subscribe', 'robin-image-optimizer' ) ); ?>';
					}
				})
				.catch(() => {
					errorEl.textContent = '<?php echo esc_js( __( 'An error occurred. Please try again.', 'robin-image-optimizer' ) ); ?>';
					errorEl.style.display = 'block';
					button.disabled = false;
					button.textContent = '<?php echo esc_js( __( 'Subscribe', 'robin-image-optimizer' ) ); ?>';
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle AJAX subscription request
	 *
	 * @return void
	 */
	public function ajax_handler() {
		check_ajax_referer( 'wrio_subscribe', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'robin-image-optimizer' ) ] );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'robin-image-optimizer' ) ] );
		}

		$body = wp_json_encode(
			[
				'slug'  => $this->plugin_name,
				'site'  => home_url(),
				'email' => $email,
			]
		);

		// Ensure body is a string, not false.
		if ( false === $body ) {
			wp_send_json_error( [ 'message' => __( 'Failed to encode request body.', 'robin-image-optimizer' ) ] );
		}

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Cache-Control' => 'no-cache',
					'Accept'        => 'application/json, */*;q=0.1',
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['code'] ) ) {
			update_option( self::OPTION_SUBSCRIBED, 1 );
			wp_send_json_success( [ 'message' => __( 'Subscribed successfully!', 'robin-image-optimizer' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Subscription failed. Please try again.', 'robin-image-optimizer' ) ] );
	}
}
