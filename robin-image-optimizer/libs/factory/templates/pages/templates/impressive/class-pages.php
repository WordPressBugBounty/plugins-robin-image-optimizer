<?php

namespace WBCR\Factory_Templates_759\Pages;

/**
 * В этом классе добавляются общие ресурсы и элементы, необходимые для всех связанных плагинов.
 *
 * @since         2.0.5
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// global $ssssdfsfsdf;

/**
 * Class Wbcr_FactoryPages600_ImpressiveThemplate
 *
 * @method string getInfoWidget() - get widget content information
 * @method string getRatingWidget(array $args = []) - get widget content rating
 * @method string getDonateWidget() - get widget content donate
 * @method string getSubscribeWidget()
 * @method string getBusinessSuggetionWidget()
 * @method string getSupportWidget
 */
class PageBase extends \WBCR\Factory_Templates_759\Impressive {

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.5 - добавлен
	 * @var bool
	 */
	public $show_right_sidebar_in_options = true;

	/**
	 * {@inheritDoc}
	 *
	 * @since  2.0.5 - добавлен
	 * @var bool
	 */
	public $available_for_multisite = true;

	/**
	 * {@inheritDoc}
	 *
	 * @since  2.0.6 - добавлен
	 * @var bool
	 */
	public $internal = true;

	/**
	 * Show on the page a search form for search options of plugin?
	 *
	 * @since  2.2.0 - Added
	 * @var bool - true show, false hide
	 */
	public $show_search_options_form;

	/**
	 * @param \Wbcr_Factory600_Plugin $plugin
	 */
	public function __construct( \Wbcr_Factory600_Plugin $plugin ) {
		parent::__construct( $plugin );

		if ( is_null( $this->show_search_options_form ) ) {
			$this->show_search_options_form = false;
			if ( 'options' === $this->type ) {
				$this->show_search_options_form = true;
			}
		}

		if ( 'options' === $this->type && 'hide_my_wp' !== $this->id ) {
			$this->register_options_to_search();
		}
	}

	/**
	 * @param $name
	 * @param $arguments
	 *
	 * @return null|string
	 */
	public function __call( $name, $arguments ) {
		if ( substr( $name, 0, 3 ) == 'get' ) {
			$called_method_name = 'show' . substr( $name, 3 );
			if ( method_exists( $this, $called_method_name ) ) {
				ob_start();

				$this->$called_method_name( $arguments );
				$content = ob_get_contents();
				ob_end_clean();

				return $content;
			}
		}

		return null;
	}

	/**
	 * Requests assets (js and css) for the page.
	 *
	 * @param \Wbcr_Factory600_ScriptList $scripts
	 * @param \Wbcr_Factory600_StyleList  $styles
	 *
	 * @return void
	 * @see Wbcr_FactoryPages600_AdminPage
	 */
	public function assets( $scripts, $styles ) {
		parent::assets( $scripts, $styles );

		$this->styles->add( FACTORY_TEMPLATES_759_URL . '/assets/css/clearfy-base.css' );

		// todo: вынести все общие скрипты и стили фреймворка, продумать совместимость с другими плагинами
		if ( defined( 'WCL_PLUGIN_URL' ) ) {
			$this->styles->add( WCL_PLUGIN_URL . '/admin/assets/css/general.css' );
		}

		if ( ! ( $this->plugin->has_premium() && $this->plugin->premium->is_active() ) ) {
			$this->scripts->add(
				FACTORY_TEMPLATES_759_URL . '/assets/js/clearfy-widgets.js',
				[
					'jquery',
					'wfactory-600-core-general',
					'wbcr-factory-templates-759-global',
				],
				'wbcr-factory-templates-759-widgets'
			);
		}

		// Script for search form on plugin options
		if ( $this->show_search_options_form ) {
			$this->styles->add( FACTORY_TEMPLATES_759_URL . '/assets/css/libs/autocomplete.css' );

			$this->scripts->add( FACTORY_TEMPLATES_759_URL . '/assets/js/libs/jquery.autocomplete.min.js' );
			$this->scripts->add( FACTORY_TEMPLATES_759_URL . '/assets/js/clearfy-search-options.js' );
		}

		/**
		 * Allows you to enqueue scripts to the internal pages of the plugin.
		 * $this->getResultId() - page id + plugin name = quick_start-wbcr_clearfy
		 *
		 * @since 2.0.5
		 */
		do_action( 'wbcr/clearfy/page_assets', $this->getResultId(), $scripts, $styles );
	}

	/**
	 * @return \Wbcr_Factory600_Request
	 */
	public function request() {
		return $this->plugin->request;
	}

	/**
	 * @param      $option_name
	 * @param bool $default *
	 *
	 * @return mixed|void
	 * @since 2.0.5
	 */
	public function getPopulateOption( $option_name, $default = false ) {
		return $this->plugin->getPopulateOption( $option_name, $default );
	}

	/**
	 * @param      $option_name
	 * @param bool $default
	 *
	 * @return mixed|void
	 */
	public function getOption( $option_name, $default = false ) {
		return $this->plugin->getOption( $option_name, $default );
	}

	/**
	 * @param $option_name
	 * @param $value
	 *
	 * @return void
	 */
	public function updatePopulateOption( $option_name, $value ) {
		$this->plugin->updatePopulateOption( $option_name, $value );
	}

	/**
	 * @param $option_name
	 * @param $value
	 *
	 * @return void
	 */
	public function updateOption( $option_name, $value ) {
		$this->plugin->updateOption( $option_name, $value );
	}

	/**
	 * @param $option_name
	 *
	 * @return void
	 */
	public function deletePopulateOption( $option_name ) {
		$this->plugin->deletePopulateOption( $option_name );
	}

	/**
	 * @param $option_name
	 *
	 * @return void
	 */
	public function deleteOption( $option_name ) {
		$this->plugin->deleteOption( $option_name );
	}

	/**
	 * @param string $position
	 *
	 * @return mixed|void
	 */
	protected function getPageWidgets( $position = 'bottom' ) {
		$widgets = [];

		/**
		 * @since 4.0.1 - добавлен
		 * @since 4.0.9 - изменено имя
		 */
		$widgets = apply_filters( 'wbcr/factory/pages/impressive/widgets', $widgets, $position, $this->plugin, $this );

		return $widgets;
	}

	/**
	 * Создает Html разметку виджета для рекламы премиум версии
	 *
	 * @since  2.0.2
	 * @deprecated
	 */
	public function showBusinessSuggetionWidget() {
	}

	/**
	 * Создает html разметку виджета с информационными маркерами
	 *
	 * @since  2.0.0
	 */
	public function showInfoWidget() {
		?>
		<div class="wbcr-factory-sidebar-widget">
			<ul>
				<li>
						<span class="wbcr-factory-hint-icon-simple wbcr-factory-simple-red">
							?
						</span>
					- <?php _e( 'A neutral setting that won\'t affect your site, but make sure you need it before enabling.', 'robin-image-optimizer' ); ?>
				</li>
				<li>
						<span class="wbcr-factory-hint-icon-simple wbcr-factory-simple-grey">
							?
						</span>
					- <?php _e( 'Use with caution. Some plugins and themes may depend on this feature.', 'robin-image-optimizer' ); ?>
				</li>
				<li>
						<span class="wbcr-factory-hint-icon-simple wbcr-factory-simple-green">
							?
						</span>
					- <?php _e( 'Absolutely safe setting, We recommend to use.', 'robin-image-optimizer' ); ?>
				</li>
			</ul>
			----------<br>
			<p><?php _e( 'Hover to the icon to get help for the feature you selected.', 'robin-image-optimizer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Создает html разметку виджета рейтинга
	 *
	 * @param array $args
	 *
	 * @since  2.0.0
	 */
	public function showRatingWidget( array $args ) {
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$page_url = 'https://wordpress.org/support/plugin/clearfy/reviews';
		} else {
			$page_url = $args[0];
		}

		$page_url = apply_filters( 'wbcr_factory_pages_600_imppage_rating_widget_url', $page_url, $this->plugin->getPluginName(), $this->getResultId() );

		?>
		<div class="wbcr-factory-sidebar-widget">
			<p>
				<strong><?php _e( 'Want to help improve this plugin?', 'robin-image-optimizer' ); ?></strong>
			</p>
			<p><?php _e( 'Your feedback helps us improve the plugin. Leave a review on wordpress.org to let us know how we\'re doing.', 'robin-image-optimizer' ); ?></p>
			<p><?php _e( 'Share your ideas for new features or improvements.', 'robin-image-optimizer' ); ?></p>
			<p>
				<i class="wbcr-factory-icon-5stars"></i>
				<a href="<?php echo $page_url; ?>" title="Leave a review" target="_blank">
					<strong><?php _e( 'Leave a review', 'robin-image-optimizer' ); ?></strong>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Создает html размету виджета доната
	 *
	 * @since  2.0.0
	 */
	public function showDonateWidget() {
		?>
		<div class="wbcr-factory-sidebar-widget">
			<p>
				<strong><?php _e( 'Donation for plugin development', 'robin-image-optimizer' ); ?></strong>
			</p>
			<?php if ( get_locale() !== 'ru_RU' ) : ?>
				<form id="wbcr-factory-paypal-donation-form" action="https://www.paypal.com/cgi-bin/webscr"
						method="post" target="_blank">
					<input type="hidden" name="cmd" value="_s-xclick">
					<input type="hidden" name="hosted_button_id" value="VDX7JNTQPNPFW">
					<div class="wbcr-factory-donation-price">5$</div>
					<input type="image" src="<?php echo FACTORY_TEMPLATES_759_URL; ?>/templates/assets/img/paypal-donate.png"
							border="0" name="submit" alt="PayPal – The safer, easier way to pay online!">
				</form>
			<?php else : ?>
				<iframe frameborder="0" allowtransparency="true" scrolling="no"
						src="https://money.yandex.ru/embed/donate.xml?account=410011242846510&quickpay=donate&payment-type-choice=on&mobile-payment-type-choice=on&default-sum=300&targets=%D0%9D%D0%B0+%D0%BF%D0%BE%D0%B4%D0%B4%D0%B5%D1%80%D0%B6%D0%BA%D1%83+%D0%BF%D0%BB%D0%B0%D0%B3%D0%B8%D0%BD%D0%B0+%D0%B8+%D1%80%D0%B0%D0%B7%D1%80%D0%B0%D0%B1%D0%BE%D1%82%D0%BA%D1%83+%D0%BD%D0%BE%D0%B2%D1%8B%D1%85+%D1%84%D1%83%D0%BD%D0%BA%D1%86%D0%B8%D0%B9.+&target-visibility=on&project-name=Themeisle&project-site=&button-text=05&comment=on&hint=%D0%9A%D0%B0%D0%BA%D1%83%D1%8E+%D1%84%D1%83%D0%BD%D0%BA%D1%86%D0%B8%D1%8E+%D0%BD%D1%83%D0%B6%D0%BD%D0%BE+%D0%B4%D0%BE%D0%B1%D0%B0%D0%B2%D0%B8%D1%82%D1%8C+%D0%B2+%D0%BF%D0%BB%D0%B0%D0%B3%D0%B8%D0%BD%3F&mail=on&successURL="
						width="508" height="187"></iframe>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Создает html разметку виджета поддержки
	 *
	 * @since  2.0.8
	 */
	public function showSupportWidget() {
		$free_support_url = 'https://wordpress.org/support/plugin/robin-image-optimizer/';
		$support_url      = tsdk_utmify( tsdk_translate_link( 'https://themeisle.com/contact/' ), 'bug-security-ticket' );

		?>
		<div id="wbcr-clr-support-widget" class="wbcr-factory-sidebar-widget">
			<p><strong><?php esc_html_e( 'Having Issues?', 'robin-image-optimizer' ); ?></strong></p>
			<div class="wbcr-clr-support-widget-body">
				<p>
					<?php esc_html_e( 'Need help? Create a support ticket and we\'ll assist you.', 'robin-image-optimizer' ); ?>
				</p>
				<ul>
					<li><span class="dashicons dashicons-sos"></span>
						<a href="<?php echo esc_url( $free_support_url ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Get free support', 'robin-image-optimizer' ); ?>
						</a>
					</li>
					<li style="margin-top: 15px;background: #fff4f1;padding: 10px;color: #a58074;">
						<span class="dashicons dashicons-warning"></span>
						<?php
						echo wp_kses_post(
							sprintf(
								// translators: %1$s is opening <a> tag, %2$s is closing </a> tag
								__( 'Found a bug or security issue? %1$sCreate a ticket%2$s for a faster response.', 'robin-image-optimizer' ),
								'<a href="' . esc_url( $support_url ) . '" target="_blank" rel="noopener">',
								'</a>'
							)
						);
						?>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}


	public function showSubscribeWidget() {
		$widget_settings = $this->plugin->getPluginInfoAttr( 'subscribe_settings' );
		$group_id        = isset( $widget_settings['group_id'] ) ? $widget_settings['group_id'] : 0;
		$terms           = 'https://themeisle.com/privacy-policy/';
		?>
		<div id="wbcr-clr-subscribe-widget" class="wbcr-factory-sidebar-widget wbcr-factory-subscribe-widget">
			<p><strong><?php _e( 'Subscribe to plugin’s newsletter', 'robin-image-optimizer' ); ?></strong></p>
			<div class="wbcr-clr-subscribe-widget-body">

				<div class="wbcr-factory-subscribe-widget__message-contanier">
					<div class="wbcr-factory-subscribe-widget__text wbcr-factory-subscribe-widget__text--success">
						<?php _e( 'Thank you for subscribing.', 'robin-image-optimizer' ); ?>
					</div>
					<div class="wbcr-factory-subscribe-widget__text wbcr-factory-subscribe-widget__text--success2">
						<?php _e( 'Thank you for your subscription.', 'robin-image-optimizer' ); ?>
					</div>
				</div>

				<form id="wbcr-factory-subscribe-widget__subscribe-form" method="post" data-nonce="<?php echo wp_create_nonce( 'clearfy_subscribe_for_' . $this->plugin->getPluginName() ); ?>">
					<input id="wbcr-factory-subscribe-widget__email" class="wbcr-factory-subscribe-widget__field" type="email" name="email" placeholder="<?php _e( 'Enter your email address', 'robin-image-optimizer' ); ?>" required>
					<label class="wbcr-factory-subscribe-widget__checkbox-label">
						<input class="wbcr-factory-subscribe-widget__checkbox" type="checkbox" name="agree_terms" required>
						<?php
						echo wp_kses_post(
							// translators: %1$s is opening <a> tag, %2$s is closing </a> tag.
							sprintf( __( 'I agree to receive the Themeisle newsletter. See our %1$sPrivacy Policy%2$s for details.', 'robin-image-optimizer' ), '<a href="' . $terms . '" target="_blank">', '</a>' )
						);
						?>
					</label>
					<input type="hidden" id="wbcr-factory-subscribe-widget__group-id" value="<?php echo esc_attr( $group_id ); ?>">
					<input type="hidden" id="wbcr-factory-subscribe-widget__plugin-name" value="<?php echo esc_attr( $this->plugin->getPluginName() ); ?>">
					<input type="submit" class="btn wbcr-factory-subscribe-widget__button" value="<?php _e( 'Subscribe', 'robin-image-optimizer' ); ?>">
				</form>
			</div>
		</div>

		<?php
	}

	/**
	 * Registers page options in the options registry
	 *
	 * This will allow the user to search all the plugin options.
	 */
	public function register_options_to_search() {
		require_once FACTORY_TEMPLATES_759_DIR . '/includes/class-search-options.php';

		$options  = $this->getPageOptions();
		$page_url = $this->getBaseUrl();
		$page_id  = $this->getResultId();

		\WBCR\Factory_Templates_759\Search_Options::register_options( $options, $page_url, $page_id );
	}

	/**
	 * Add search plugin options form to each option page
	 */
	public function printAllNotices() {
		parent::printAllNotices(); // TODO: Change the autogenerated stub

		if ( ! $this->show_search_options_form ) {
			return;
		}
		?>
		<div id="wbcr-factory-templates-759__search_options_form" class="wbcr-factory-templates-759__autocomplete-wrap">
			<label for="autocomplete" class="wbcr-factory-templates-759__autocomplete-label">
				<?php _e( 'Can\'t find the settings you need? Use the search by the plugin options:', 'robin-image-optimizer' ); ?>
			</label>
			<input type="text" placeholder="<?php _e( 'Enter the option name to search...', 'robin-image-optimizer' ); ?>" name="country" id="wbcr-factory-templates-759__autocomplete"/>

		</div>
		<?php
	}
}

