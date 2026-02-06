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
class Wbcr_FactoryLogger359_AdminPage extends Wbcr_FactoryPages600_AdminPage {

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

		$this->menu_title  = __( 'Plugin Log', 'robin-image-optimizer' );
		$this->page_title  = __( 'Plugin log', 'robin-image-optimizer' );
		$this->capabilitiy = 'manage_options';

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
		wp_localize_script(
			'wbcr_factory_logger_359',
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
	 * Show rendered template - $template_name
	 */
	public function indexAction() {
		$this->showPageContent();
	}

	/**
	 * {@inheritdoc}
	 */
	public function showPageContent() {
		$buttons = "
            <div class='wbcr_factory_logger_buttons'>
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
					) .
				'</a>
            </div>';

		?>
		<div class="wbcr_factory_logger_container">
			<div class="wbcr_factory_logger_page_title">
				<h1>
					<?php
					// translators: %s is the plugin title and version.
					echo esc_html( sprintf( __( 'Logs of the %s', 'robin-image-optimizer' ), $this->plugin->getPluginTitle() . ' ' . $this->plugin->getPluginVersion() ) );
					?>
				</h1>
				<p>
					<?php _e( 'Track plugin activity here. Share this log with support to troubleshoot issues.', 'robin-image-optimizer' ); ?>
				</p>
			</div>
			<?php echo $buttons; ?>
			<div class="wbcr-log-viewer" id="wbcr-log-viewer">
				<?php echo $this->plugin->logger->prettify(); ?>
			</div>
		</div>
		<?php
	}

	public function ajax_cleanup() {
		check_admin_referer( 'wbcr_factory_logger_359_clean_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( - 1 );
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
			! ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'export-' . $this->plugin->getPluginName() ) )
			|| ! $this->plugin->current_user_can()
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
