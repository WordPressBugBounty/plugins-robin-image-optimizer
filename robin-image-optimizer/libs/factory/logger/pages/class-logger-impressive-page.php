<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс отвечает за работу страницы логов.
 *
 * @version       1.0
 */
class Wbcr_FactoryLogger359_PageBase extends \WBCR\Factory_Templates_759\Pages\PageBase {

	/**
	 * {@inheritdoc}
	 */
	public $id; // Уникальный идентификатор страницы

	/**
	 * {@inheritdoc}
	 */
	public $page_menu_dashicon = 'dashicons-admin-tools';

	/**
	 * {@inheritdoc}
	 */
	public $type = 'page';

	/**
	 * @param Wbcr_Factory600_Plugin $plugin
	 */
	public function __construct( $plugin ) {
		$this->id = $plugin->getPrefix() . 'logger';

		$this->menu_title                  = __( 'Plugin Log', 'robin-image-optimizer' );
		$this->page_menu_short_description = __( 'Plugin debug report', 'robin-image-optimizer' );

		add_action( 'wp_ajax_wbcr_factory_logger_359_' . $plugin->getPrefix() . 'logs_cleanup', [ $this, 'ajax_cleanup' ] );

		parent::__construct( $plugin );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function assets( $scripts, $styles ) {
		parent::assets( $scripts, $styles );

		$this->styles->add( FACTORY_LOGGER_359_URL . '/assets/css/logger.css' );
		$this->scripts->add( FACTORY_LOGGER_359_URL . '/assets/js/logger.js', [ 'jquery' ], 'wbcr_factory_logger_359', FACTORY_LOGGER_359_VERSION );
		$this->scripts->localize(
			'wbcr_factory_logger_359',
			[
				'clean_logs_nonce' => wp_create_nonce( 'wbcr_factory_logger_359_clean_logs' ),
				'plugin_prefix'    => $this->plugin->getPrefix(),
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMenuTitle() {
		return __( 'Plugin Log', 'robin-image-optimizer' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function showPageContent() {
		$buttons = "
            <div class='btn-group'>
                <a href='" . wp_nonce_url( $this->getActionUrl( 'export' ), 'export-' . $this->plugin->getPluginName() ) . "'
                   class='button button-primary'>" . __( 'Export Debug Information', 'robin-image-optimizer' ) . "</a>
                <a href='#'
                   class='button button-secondary'
                   onclick='wbcr_factory_logger_359_LogCleanup(this);return false;'
                   data-working='" . __( 'Working...', 'robin-image-optimizer' ) . "'>" .
					sprintf(
						'%1$s %2$s',
						__( 'Clear Logs', 'robin-image-optimizer' ),
						'(<span id="wbcr-log-size">' . $this->get_log_size_formatted() . '</span>)'
					) . '
                   </a>
            </div>';
		?>
		<div class="wbcr-factory-page-group-header" style="margin-top:0;">
			<strong><?php _e( 'Plugin Log', 'robin-image-optimizer' ); ?></strong>
			<p>
				<?php _e( 'Track plugin activity here. Share this log with support to troubleshoot issues.', 'robin-image-optimizer' ); ?>
			</p>
		</div>
		<div class="wbcr-factory-page-group-body" style="padding: 0 20px">
			<?php $this->render_support_upsell_banner(); ?>
			<?php echo $buttons; ?>
			<div class="wbcr-log-viewer" id="wbcr-log-viewer">
				<?php echo $this->plugin->logger->prettify(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the support banner for debug log troubleshooting.
	 *
	 * @return void
	 */
	protected function render_support_upsell_banner() {
		$is_premium = function_exists( 'wrio_is_license_activate' ) && wrio_is_license_activate();

		$url = $is_premium
			? tsdk_utmify( tsdk_translate_link( 'http://themeisle.com/contact' ), 'debug-page', 'debug-support-banner' )
			: 'https://wordpress.org/support/plugin/robin-image-optimizer/';

		if ( empty( $url ) ) {
			return;
		}

		$support_message = $is_premium
			? __( 'Contact our support team for priority assistance.', 'robin-image-optimizer' )
			: __( 'Please open a topic on the WordPress.org support forum.', 'robin-image-optimizer' );
		$button_label    = $is_premium
			? __( 'Contact Support', 'robin-image-optimizer' )
			: __( 'Open Support Forum', 'robin-image-optimizer' );
		?>
		<div id="WBCR">
			<div class="wrio-errorlog-support-banner">
				<div class="wrio-errorlog-support-icon">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="12" cy="12" r="10" stroke="#2196F3" stroke-width="2" fill="none"></circle>
						<path d="M12 8v4m0 4h.01" stroke="#2196F3" stroke-width="2" stroke-linecap="round"></path>
					</svg>
				</div>
				<div class="wrio-errorlog-support-content">
					<p class="wrio-errorlog-support-title"><?php esc_html_e( 'Need help troubleshooting?', 'robin-image-optimizer' ); ?></p>
					<p class="wrio-errorlog-support-subtitle"><?php echo esc_html( $support_message ); ?></p>
				</div>
				<a href="<?php echo esc_url( $url ); ?>" class="wrio-errorlog-support-button" target="_blank" rel="noopener">
					<?php echo esc_html( $button_label ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	public function ajax_cleanup() {
		check_admin_referer( 'wbcr_factory_logger_359_clean_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		if ( ! $this->plugin->logger->clean_up() ) {
			wp_send_json_error(
				[
					'message' => esc_html__( 'Failed to clear logs. Please try again later.', 'robin-image-optimizer' ),
					'type'    => 'danger',
				]
			);
		}

		wp_send_json(
			[
				'message' => esc_html__( 'Logs cleared successfully.', 'robin-image-optimizer' ),
				'type'    => 'success',
			]
		);
	}

	/**
	 * Processing log export action in form of ZIP archive.
	 */
	public function exportAction() {
		if (
			! ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'export-' . $this->plugin->getPluginName() ) ) ||
			! $this->plugin->current_user_can()
		) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action!', 'robin-image-optimizer' ) );
		}

		$export = new WBCR\Factory_Logger_359\Log_Export( $this->plugin->logger );

		if ( $export->prepare() ) {
			$export->download( true );
		}
	}

	/**
	 * Get log size formatted.
	 *
	 * @return false|string
	 */
	private function get_log_size_formatted() {

		try {
			return size_format( $this->plugin->logger->get_total_size() );
		} catch ( \Exception $exception ) {
			$this->plugin->logger->error( sprintf( 'Failed to get total log size as exception was thrown: %s', $exception->getMessage() ) );
		}

		return '';
	}
}
