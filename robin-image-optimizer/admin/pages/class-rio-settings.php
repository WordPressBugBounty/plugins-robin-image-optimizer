<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс отвечает за работу страницы настроек
 *
 * @version       1.0
 */
class WRIO_SettingsPage extends WRIO_Page {

	/**
	 * {@inheritdoc}
	 */
	public $id = 'rio_settings';

	/**
	 * {@inheritdoc}
	 */
	public $page_menu_dashicon = 'dashicons-admin-generic';

	/**
	 * {@inheritdoc}
	 *
	 * @var string
	 */
	public $page_parent_page = 'rio_general';

	/**
	 * {@inheritdoc}
	 *
	 * @var bool
	 */
	public $show_right_sidebar_in_options = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since  1.1.3 - Added
	 * @var bool
	 */
	public $show_bottom_sidebar = true;

	/**
	 * @var bool
	 */
	public $show_search_options_form = false;

	/**
	 * Menu target for WordPress admin submenu.
	 *
	 * @var string
	 */
	public $menu_target = 'rio_general-robin-image-optimizer';

	/**
	 * Use admin.php as base URL instead of menu_target.
	 *
	 * @var bool
	 */
	public $custom_target = true;

	/**
	 * @var bool
	 */
	public $internal = false;

	/**
	 * @param WRIO_Plugin $plugin
	 */
	public function __construct( WRIO_Plugin $plugin ) {

		$this->menu_title                  = __( 'Settings', 'robin-image-optimizer' );
		$this->page_menu_short_description = __( 'Plugin configuration', 'robin-image-optimizer' );

		if ( defined( 'WBCR_CLEARFY_PLUGIN_ACTIVE' ) ) {
			$this->show_search_options_form = true;
		}

		add_filter(
			'wbcr/factory/option_image_optimization_type',
			function ( $option_value ) {
				if ( ! wrio_is_license_activate() && $option_value === 'background' ) {
					$option_value = 'schedule';
				}

				return $option_value;
			}
		);

		add_filter(
			'wbcr/factory/option_convert_avif_format',
			function ( $option_value ) {
				if ( ! wrio_is_license_activate() && $option_value ) {
					$option_value = false;
				}

				return $option_value;
			}
		);

		parent::__construct( $plugin );
	}

	/**
	 * Подключаем скрипты и стили для страницы
	 *
	 * @return void
	 * @since 1.0.0
	 * @see   Wbcr_FactoryPages600_AdminPage
	 */
	public function assets( $scripts, $styles ) {
		parent::assets( $scripts, $styles );

		$this->styles->add( WRIO_PLUGIN_URL . '/admin/assets/css/base-statistic.css' );
		$this->scripts->add( WRIO_PLUGIN_URL . '/admin/assets/js/restore-backup.js' );

		if ( ! wrio_is_license_activate() ) {
			$this->styles->add( WRIO_PLUGIN_URL . '/admin/assets/css/settings-premium.css' );
			$this->scripts->add( WRIO_PLUGIN_URL . '/admin/assets/js/settings-premium.js' );
		}

		if ( defined( 'WBCR_CLEARFY_PLUGIN_ACTIVE' ) ) {
			$this->styles->add( WCL_PLUGIN_URL . '/admin/assets/css/general.css' );
		}
	}


	/**
	 * Выводим предупреждения
	 */
	protected function warningNotice() {
		$upload_dir = wp_upload_dir();

		if ( ! wp_is_writable( $upload_dir['basedir'] ) ) {
			$this->printErrorNotice(
				sprintf(
					// translators: %s is the folder path.
					__( 'Folder %s is unavailable for writing', 'robin-image-optimizer' ),
					'wp-content/uploads/'
				)
			);
		}

		$wio_backup = $upload_dir['basedir'] . '/wio_backup/';
		if ( file_exists( $wio_backup ) && ! wp_is_writable( $wio_backup ) ) {
			$this->printErrorNotice(
				sprintf(
					// translators: %s is the folder path.
					__( 'Folder %s is unavailable for writing', 'robin-image-optimizer' ),
					'wp-content/uploads/wio-backup/'
				)
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON == true ) {
			$this->printErrorNotice(
				sprintf(
					// translators: %s is the file name (wp-config.php).
					__( 'Cron is disabled in %s', 'robin-image-optimizer' ),
					'wp-config.php'
				)
			);
		}
	}


	/**
	 * Метод должен передать массив опций для создания формы с полями.
	 * Созданием страницы и формы занимается фреймворк
	 *
	 * @return mixed[]
	 * @since 1.0.0
	 */
	public function getPageOptions() {
		$options = [];

		$options[] = [
			'type' => 'html',
			'html' => '<div class="wbcr-factory-page-group-header"><strong>' . __( 'Main Settings', 'robin-image-optimizer' ) . '</strong><p>' . __( 'Configure image optimization settings.', 'robin-image-optimizer' ) . '</p></div>',
		];

		// Радио переключатель
		$options[] = [
			'type'    => 'dropdown',
			'name'    => 'image_optimization_level',
			'way'     => 'buttons',
			'title'   => __( 'Compression mode', 'robin-image-optimizer' ),
			'data'    => [
				[
					'normal',
					__( 'Lossless', 'robin-image-optimizer' ),
					__( 'This mode provides lossless compression and your images will be optimized without visible changes. If you want an ideal image quality, we recommend this mode. The size of the files will be reduced approximately 2 times. If this is not enough for you, try other modes.', 'robin-image-optimizer' ),
				],
				[
					'aggresive',
					__( 'Lossy', 'robin-image-optimizer' ),
					__( 'This mode provides an ideal optimization of your images without significant quality loss. The file size will be reduced approximately 5 times with a slight decrease in image quality. In most cases that cannot be seen with the naked eye.', 'robin-image-optimizer' ),
				],
				[
					'ultra',
					__( 'High', 'robin-image-optimizer' ),
					__( 'This mode will use all available optimization methods for maximum image compression. The file size will be reduced approximately 7 times. The quality of some images may deteriorate slightly. Use this mode if you need the maximum weight reduction, and you are ready to accept the loss of image quality.', 'robin-image-optimizer' ),
				],
				[
					'googlepage',
					__( 'G PageSpeed', 'robin-image-optimizer' ),
					__( 'This mode uses the optimal settings for Google Page Speed', 'robin-image-optimizer' ),
				],
				[
					'custom',
					__( 'Custom', 'robin-image-optimizer' ),
					__( 'This mode allows you to configure your own compression ratio.', 'robin-image-optimizer' ),
				],
			],
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Select the compression mode appropriate for your case.', 'robin-image-optimizer' ),
			'default' => 'normal',
			'events'  => [
				'normal'     => [
					'hide' => '.factory-control-image_optimization_level_custom',
				],
				'aggresive'  => [
					'hide' => '.factory-control-image_optimization_level_custom',
				],
				'ultra'      => [
					'hide' => '.factory-control-image_optimization_level_custom',
				],
				'googlepage' => [
					'hide' => '.factory-control-image_optimization_level_custom',
				],
				'custom'     => [
					'show' => '.factory-control-image_optimization_level_custom',
				],
			],
		];

		// Текстовое поле
		$options[] = [
			'type'    => 'textbox',
			'name'    => 'image_optimization_level_custom',
			'title'   => __( 'Enter custom quality', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Custom quality 1-100', 'robin-image-optimizer' ),
			'default' => '70',
		];

		// Переключатель
		$options[] = [
			'type'    => 'checkbox',
			'way'     => 'buttons',
			'name'    => 'auto_optimize_when_upload',
			'title'   => __( 'Auto optimization on upload', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Automatically compress all images that you upload directly to the WordPress media library, when editing pages and posts or using themes and plugins.', 'robin-image-optimizer' ),
			'default' => false,
		];

		// Переключатель
		$options[] = [
			'type'    => 'checkbox',
			'way'     => 'buttons',
			'name'    => 'backup_origin_images',
			'title'   => __( 'Backup images', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'green',
			],
			'hint'    => __( 'Before optimizing, all your images will be saved in a separate folder for future recovery.', 'robin-image-optimizer' ),
			'default' => true,
		];

		// Переключатель
		$options[] = [
			'type'      => 'checkbox',
			'way'       => 'buttons',
			'name'      => 'error_log',
			'title'     => __( 'Error Log', 'robin-image-optimizer' ),
			'layout'    => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'      => __( 'Enable error logging. The log will be displayed on a separate tab.', 'robin-image-optimizer' ),
			'default'   => false,
			'eventsOn'  => [
				'show' => '#wrio-error-log-options',
			],
			'eventsOff' => [
				'hide' => '#wrio-error-log-options',
			],
		];

		$options[] = [
			'type' => 'html',
			'html' => [ $this, 'error_log_options' ],
		];

		// WebP conversion option (FREE)
		$options[] = [
			'type'    => 'checkbox',
			'way'     => 'buttons',
			'name'    => 'convert_webp_format',
			'title'   => __( 'Convert Images to WebP', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Convert JPEG & PNG images into WebP format and replace them for browsers which support it. Unsupported browsers would be skipped.', 'robin-image-optimizer' ),
			'default' => false,
		];

		// AVIF conversion option (PRO only)
		$options[] = [
			'type'     => 'checkbox',
			'way'      => 'buttons',
			'name'     => 'convert_avif_format',
			'title'    => __( 'Convert Images to AVIF', 'robin-image-optimizer' ),
			'layout'   => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'     => __( 'Convert JPEG & PNG images into AVIF format. AVIF provides superior compression but requires a premium license.', 'robin-image-optimizer' ),
			'default'  => false,
			'cssClass' => ! wrio_is_license_activate() ? [ 'factory-checkbox-disabled', 'wrio-checkbox-premium-label' ] : [],
		];

		$options[] = [
			'type' => 'html',
			'html' => [ $this, 'conver_webp_options' ],
		];

		// восстановление
		$options[] = [
			'type' => 'html',
			'html' => [ $this, 'rollbackButton' ],
		];

		// Переключатель
		$options[] = [
			'type'    => 'checkbox',
			'way'     => 'buttons',
			'name'    => 'save_exif_data',
			'title'   => __( 'Strip EXIF data', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'EXIF is information stored in photos: camera model, shutter speed, exposure compensation, ISO, GPS, etc. By default, the plugin removes EXIF extended data. If your project is dedicated to photography and you need to display this data, then enable this option.', 'robin-image-optimizer' ),
			'default' => true,
		];

		$options[] = [
			'type' => 'html',
			'html' => '<div class="wbcr-factory-page-group-header"><strong>' . __( 'Optimization', 'robin-image-optimizer' ) . '</strong><p>' . __( 'Here you can specify additional image optimization options.', 'robin-image-optimizer' ) . '</p></div>',
		];

		$options[] = [
			'type'    => 'dropdown',
			'name'    => 'image_optimization_order',
			'way'     => 'buttons',
			'title'   => __( 'Optimization order', 'robin-image-optimizer' ),
			'data'    => [
				[
					'asc',
					__( 'Ascending', 'robin-image-optimizer' ),
					__( 'Optimization will start with old images in the media library', 'robin-image-optimizer' ),
				],
				[
					'desc',
					__( 'Descending', 'robin-image-optimizer' ),
					__( 'Optimization will start with new images in the media library', 'robin-image-optimizer' ),
				],
			],
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( /** @lang text */ 'Select the optimization order from the media library.', 'robin-image-optimizer' ),
			'default' => 'asc',
		];

		// Переключатель
		$options[] = [
			'type'      => 'checkbox',
			'way'       => 'buttons',
			'name'      => 'resize_larger',
			'title'     => __( 'Resizing large images', 'robin-image-optimizer' ),
			'layout'    => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'      => __( 'When you upload images from a camera or stock, they may be too high resolution and it is not necessary for web. The option allows you to automatically change images resolution on upload.', 'robin-image-optimizer' ),
			'default'   => false,
			// когда чекбокс включен показываем поле с классом .factory-control-resize_larger_w
			'eventsOn'  => [
				'show' => '.factory-control-resize_larger_w,.factory-control-resize_larger_h',
			],
			// когда чекбокс выключен, скрываем поле с классом .factory-control-resize_larger_w
			'eventsOff' => [
				'hide' => '.factory-control-resize_larger_w,.factory-control-resize_larger_h',
			],
		];

		// Текстовое поле
		$options[] = [
			'type'    => 'textbox',
			'name'    => 'resize_larger_w',
			'title'   => __( 'Enter the maximum width (px)', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Set the maximum images resolution on the long side. For horizontal images, this will be the width, and for vertical images - the height. The resolution of the images will be changed proportionally according to the set value.', 'robin-image-optimizer' ),
			'default' => '1600',
		];

		// Текстовое поле
		$options[] = [
			'type'    => 'textbox',
			'name'    => 'resize_larger_h',
			'title'   => __( 'Enter the maximum height (px)', 'robin-image-optimizer' ),
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Set the maximum images resolution on the long side. For horizontal images, this will be the width, and for vertical images - the height. The resolution of the images will be changed proportionally according to the set value.', 'robin-image-optimizer' ),
			'default' => '1600',
		];

		$options[] = [
			'type'    => 'list',
			'way'     => 'checklist',
			'name'    => 'allowed_formats',
			'title'   => __( 'Optimize formats', 'robin-image-optimizer' ),
			'data'    => [
				[ 'image/jpeg', 'JPG' ],
				[ 'image/png', 'PNG' ],
				[ 'image/gif', 'GIF' ],
			],
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Choose which formats of images should be optimized and uncheck those that do not need optimization.', 'robin-image-optimizer' ),
			'default' => 'image/jpeg,image/png,image/gif',
		];

		// получаем зарегистрированные размеры изображений
		$wp_image_sizes  = wrio_get_image_sizes();
		$wio_image_sizes = [];
		foreach ( $wp_image_sizes as $key => $value ) {
			$wio_image_sizes[] = [
				$key,
				$key . ' - ' . $value['width'] . 'x' . $value['height'],
			];
		}

		$options[] = [
			'type'    => 'list',
			'way'     => 'checklist',
			'name'    => 'allowed_sizes_thumbnail',
			'title'   => __( 'Optimize thumbnails', 'robin-image-optimizer' ),
			'data'    => $wio_image_sizes,
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => __( 'Choose which sizes of thumbnails should be optimized and uncheck those that do not need optimization.', 'robin-image-optimizer' ),
			'default' => 'thumbnail,medium',
		];

		// cron
		$options[] = [
			'type' => 'html',
			'html' => '<div class="wbcr-factory-page-group-header"><strong>' . __( 'Scheduled and background optimization', 'robin-image-optimizer' ) . '</strong><p>' . __( 'Settings for scheduled and background image optimization.', 'robin-image-optimizer' ) . '</p></div>',
		];

		$options[] = [
			'type'    => 'dropdown',
			'name'    => 'image_optimization_type',
			'way'     => 'buttons',
			'title'   => __( 'Background optimization type', 'robin-image-optimizer' ),
			'data'    => [
				[
					'schedule',
					__( 'Scheduled', 'robin-image-optimizer' ),
					__( 'Optimization will take place on a schedule', 'robin-image-optimizer' ),
				],
				[
					'background',
					__( 'Background', 'robin-image-optimizer' ),
					__( 'Optimization will occur in the background constantly', 'robin-image-optimizer' ),
				],
			],
			'layout'  => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'    => sprintf(
				// translators: %1$s is the bold start tag, %2$s is the bold end tag.
				__( '%1$sScheduled optimization%2$s will occur on a scheduled basis.', 'robin-image-optimizer' ),
				'<b>',
				'</b>'
			) . '<br>' . sprintf(
				// translators: %1$s is the bold start tag, %2$s is the bold end tag.
				__( '%1$sBackground optimization%2$s will occur in the background constantly.', 'robin-image-optimizer' ),
				'<b>',
				'</b>'
			),
			'default' => 'schedule',
			'events'  => [
				'schedule'   => [
					'show' => '#wbcr-io-shedule-options',
				],
				'background' => [
					'hide' => '#wbcr-io-shedule-options',
				],
			],
		];

		$group_items[] = [
			'type'    => 'dropdown',
			'way'     => 'buttons',
			'name'    => 'image_autooptimize_shedule_time',
			'data'    => [
				[ 'wio_1_min', __( '1 minute', 'robin-image-optimizer' ) ],
				// translators: %s is the number of minutes.
				[ 'wio_2_min', sprintf( __( '%s minutes', 'robin-image-optimizer' ), '2' ) ],
				// translators: %s is the number of minutes.
				[ 'wio_5_min', sprintf( __( '%s minutes', 'robin-image-optimizer' ), '5' ) ],
				// translators: %s is the number of minutes.
				[ 'wio_10_min', sprintf( __( '%s minutes', 'robin-image-optimizer' ), '10' ) ],
				// translators: %s is the number of minutes.
				[ 'wio_30_min', sprintf( __( '%s minutes', 'robin-image-optimizer' ), '30' ) ],
				[ 'wio_hourly', __( 'Hour', 'robin-image-optimizer' ) ],
				[ 'wio_daily', __( 'Day', 'robin-image-optimizer' ) ],
			],
			'default' => 'wio_5_min',
			'title'   => __( 'Run every', 'robin-image-optimizer' ),
			'hint'    => __( 'Select time at which the task will be repeated.', 'robin-image-optimizer' ),
		];

		$group_items[] = [
			'type'         => 'textbox',
			'name'         => 'image_autooptimize_items_number_per_interation',
			'title'        => __( 'Images per iteration', 'robin-image-optimizer' ),
			'layout'       => [
				'hint-type'       => 'icon',
				'hint-icon-color' => 'grey',
			],
			'hint'         => __( 'Specify the number of images that will be optimized during the job. For example, if you enter 5 and select 5 min, the plugin will optimize 5 images every 5 minutes.', 'robin-image-optimizer' ),
			'default'      => '3',
			'htmlAttrs'    => [
				'type' => 'number',
				'min'  => '1',
				'step' => '1',
			],
			'filter_value' => function ( $value ) {
				$int_value = intval( $value );
				return $int_value < 1 ? 1 : $int_value;
			},
		];

		$options[] = [
			'type'  => 'div',
			'id'    => 'wbcr-io-shedule-options',
			'items' => $group_items,
		];

		$options = apply_filters( 'wbcr/rio/settings_page/options', $options );

		$formOptions = [];

		$formOptions[] = [
			'type'  => 'form-group',
			'items' => $options,
			// 'cssClass' => 'postbox'
		];

		return $formOptions;
	}

	/**
	 * Save advanced options in database
	 *
	 * @since  1.3.6
	 */
	public function beforeFormSave() {

		/**
		 * Used to save webp options. It can also be used to intercept
		 * other unregistered fields.
		 *
		 * @since 1.3.6
		 */
		do_action( 'wrio/settings_page/berfore_form_save' );

		$error_log = (int) WRIO_Plugin::app()->request->post( WRIO_Plugin::app()->getPrefix() . 'error_log', 0 );

		if ( ! $error_log ) {
			return;
		}

		$keep_error_log_on_frontend = (int) WRIO_Plugin::app()->request->post( 'wrio_keep_error_log_on_frontend', 0 );

		WRIO_Plugin::app()->updatePopulateOption( 'keep_error_log_on_frontend', $keep_error_log_on_frontend );
	}

	/**
	 * This method adds advanced options for the "Convert Images to WebP" checkbox.
	 *
	 * @since  1.3.6
	 */
	public function conver_webp_options() {

		/**
		 * This hook prints options for delivering webp images.
		 *
		 * @since 1.3.6
		 */
		do_action( 'wrio/settings_page/conver_webp_options' );
	}

	/**
	 * This method adds advanced options for the "Error log" checkbox.
	 *
	 * @since  1.3.6
	 */
	public function error_log_options() {
		$this->view->print_template(
			'part-settings-page-error-log-options',
			[
				'keep_error_log_on_frontend' => (int) WRIO_Plugin::app()->getPopulateOption( 'keep_error_log_on_frontend', 0 ),
			]
		);
	}

	/**
	 * Кнопка восстановления изображений
	 */
	public function rollbackButton() {
		?>
		<div class="form-group form-group-checkbox factory-control-rollback-button">
			<label for="wio-clear-backup-btn" class="col-sm-4 control-label">
				<?php esc_html_e( 'Manage backups', 'robin-image-optimizer' ); ?>
				<span class="factory-hint-icon factory-hint-icon-red" data-toggle="factory-tooltip"
						data-placement="right" title=""
						data-original-title="<?php esc_html_e( 'You can restore the original images from a backup or clear them.', 'robin-image-optimizer' ); ?>"
					>
						?
					</span>
			</label>
			<input type="hidden" value="<?php echo wp_create_nonce( 'wio-iph' ); ?>" id="wio-iph-nonce">
			<div class="control-group col-sm-8">
				<div class="factory-buttons-way btn-group">
					<a class="btn btn-default" id="wio-restore-backup-btn"
						data-confirm="<?php esc_html_e( 'Are you sure you want to restore all images? This cannot be undone.', 'robin-image-optimizer' ); ?>"
						href="#"><?php esc_html_e( 'Restore', 'robin-image-optimizer' ); ?></a>
					<a class="btn btn-default" id="wio-clear-backup-btn"
						data-confirm="<?php esc_html_e( 'Are you sure you want to clear the backup folder? All backup images will be permanently deleted.', 'robin-image-optimizer' ); ?>"
						href="#"><?php esc_html_e( 'Clear Backup', 'robin-image-optimizer' ); ?></a>
				</div>
				<div class="progress" id="wio-restore-backup-progress" style="display:none;">
					<div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="0"
						aria-valuemin="0" aria-valuemax="100" style="width:0%">
					</div>
				</div>
				<p id="wio-restore-backup-msg"
					style="display:none;"><?php esc_html_e( 'Restore completed.', 'robin-image-optimizer' ); ?></p>
				<p id="wio-clear-backup-msg"
					style="display:none;"><?php esc_html_e( 'The backup folder was cleared.', 'robin-image-optimizer' ); ?></p>
			</div>
		</div>
		<?php
	}
}
