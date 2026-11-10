<?php
/**
 * React admin app: root element, assets and the data handed to the browser.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the mount point of the Chakra UI admin app and enqueues its build.
 */
class WRIO_App {

	/**
	 * REST namespace shared with the JavaScript side.
	 */
	const REST_NAMESPACE = 'robin-image-optimizer/v1';

	/**
	 * Print the element the React app mounts into.
	 *
	 * @param string $route bulk|settings|license.
	 * @param string $scope media-library|custom-folders|nextgen-gallery.
	 *
	 * @return void
	 */
	public static function render( $route, $scope = 'media-library' ) {
		printf(
			'<div id="wrio-app" data-route="%s" data-scope="%s"></div>',
			esc_attr( $route ),
			esc_attr( $scope )
		);
	}

	/**
	 * Enqueue the built app and localize its data.
	 *
	 * @param string $route bulk|settings|license.
	 * @param string $scope media-library|custom-folders|nextgen-gallery.
	 *
	 * @return void
	 */
	public static function enqueue( $route, $scope = 'media-library' ) {
		$asset = self::enqueue_script( 'wrio-app', 'index', true );

		if ( null === $asset ) {
			return;
		}

		wp_enqueue_style(
			'wrio-app',
			WRIO_PLUGIN_URL . '/admin/assets/app/build/style-index.css',
			[],
			$asset['version']
		);
		wp_set_script_translations( 'wrio-app', 'robin-image-optimizer' );
		wp_localize_script( 'wrio-app', 'wrioApp', self::get_data( $route, $scope ) );

		add_filter(
			'admin_body_class',
			static function ( $classes ) {
				return $classes . ' wrio-app-page';
			}
		);
	}

	/**
	 * Enqueue one webpack entry from admin/assets/app/build. Its dependencies
	 * and version come from the <entry>.asset.php file the build writes.
	 *
	 * @param non-empty-string $handle    Script handle.
	 * @param string           $entry     Entry name in webpack.config.js.
	 * @param bool             $in_footer Print the script in the footer.
	 *
	 * @return array{dependencies: string[], version: string}|null The asset data, null when the entry is not built.
	 */
	public static function enqueue_script( $handle, $entry, $in_footer = false ) {
		$asset_file = WRIO_PLUGIN_DIR . '/admin/assets/app/build/' . $entry . '.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return null;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			$handle,
			WRIO_PLUGIN_URL . '/admin/assets/app/build/' . $entry . '.js',
			array_merge( $asset['dependencies'], self::hot_runtime() ),
			$asset['version'],
			$in_footer
		);

		return $asset;
	}

	/**
	 * `npm start` splits a shared webpack runtime out of the bundle, which the
	 * app does not start without. A built release never writes that file.
	 *
	 * @return string[] Handle to depend on, empty for a built release.
	 */
	protected static function hot_runtime() {
		$runtime = WRIO_PLUGIN_DIR . '/admin/assets/app/build/runtime.js';

		if ( ! file_exists( $runtime ) ) {
			return [];
		}

		wp_enqueue_script(
			'wrio-app-runtime',
			WRIO_PLUGIN_URL . '/admin/assets/app/build/runtime.js',
			[],
			(string) filemtime( $runtime ),
			true
		);

		return [ 'wrio-app-runtime' ];
	}

	/**
	 * Everything the app needs on first render.
	 *
	 * @param string $route bulk|settings|license.
	 * @param string $scope media-library|custom-folders|nextgen-gallery.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_data( $route, $scope ) {
		$plugin     = WRIO_Plugin::app();
		$is_premium = wrio_is_license_activate();
		$support    = $plugin->get_support();

		$pricing_url = $support->get_pricing_url( true, 'statistic' );

		$data = [
			'api'       => self::REST_NAMESPACE,
			'version'   => $plugin->getPluginVersion(),
			'isPremium' => $is_premium,
			'urls'      => [
				'bulk'      => WRIO_Admin_Pages::url( 'rio_general' ),
				'folders'   => $is_premium ? WRIO_Admin_Pages::url( 'io_folders_statistic' ) : $pricing_url,
				'nextgen'   => $is_premium ? WRIO_Admin_Pages::url( 'io_nextgen_gallery_statistic' ) : $pricing_url,
				'settings'  => WRIO_Admin_Pages::url( 'rio_settings' ),
				'license'   => admin_url( 'admin.php?page=wrio_license' ),
				// The page exists only while the option is on; the tab toggles without a reload.
				'errorLog'  => admin_url( 'admin.php?page=' . WRIO_Admin_Pages::LOG ),
				'mediaNew'  => admin_url( 'media-new.php' ),
				'pricing'   => $pricing_url,
				'avifPlans' => $support->get_pricing_url( true, 'avif_banner' ),
				'support'   => 'https://wordpress.org/support/plugin/robin-image-optimizer/',
				'ticket'    => self::themeisle_link( 'https://themeisle.com/contact/', 'bug-security-ticket' ),
				'review'    => 'https://wordpress.org/support/plugin/robin-image-optimizer/reviews/#new-post',
				'logo'      => WRIO_PLUGIN_URL . '/admin/assets/img/logo.png',
			],
		];

		return array_merge( $data, self::get_route_data( $route, $scope ) );
	}

	const ROUTES = [ 'bulk', 'settings', 'license', 'log' ];
	const SCOPES = [ 'media-library', 'custom-folders', 'nextgen-gallery' ];

	/**
	 * Saved space, in bytes, that earns a milestone card. Few and far apart, so the card stays rare.
	 */
	const MILESTONES = [ 100 * MB_IN_BYTES, 200 * MB_IN_BYTES, GB_IN_BYTES, 5 * GB_IN_BYTES, 10 * GB_IN_BYTES ];

	/**
	 * WebP conversions a free site needs before the rail suggests AVIF.
	 */
	const AVIF_UPSELL_MIN_WEBP = 50;

	/**
	 * A measured AVIF saving below this percent is not worth a number: the card says "up to 50%" instead.
	 */
	const AVIF_ESTIMATE_MIN_PERCENT = 20;

	/**
	 * The part of the payload that depends on the page: loaded on first render
	 * and again over REST when the app switches page without a reload.
	 *
	 * @param string $route bulk|settings|license|log.
	 * @param string $scope media-library|custom-folders|nextgen-gallery.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_route_data( $route, $scope ) {
		$plugin     = WRIO_Plugin::app();
		$is_premium = wrio_is_license_activate();
		$backup     = new WIO_Backup();
		$rail       = self::get_rail_data( $is_premium );

		$data = [
			'route'    => $route,
			'scope'    => $scope,
			'flags'    => [
				'nextgenActive'    => wrio_is_active_nextgen_gallery(),
				'errorLog'         => (bool) $plugin->getPopulateOption( 'error_log', false ),
				// Clearfy owns the licence on these installs, so the plugin does
				// not register a License page and the app must not offer the tab.
				'clearfyLicense'   => wrio_is_clearfy_license_activate(),
				'isNetworkAdmin'   => $plugin->isNetworkAdmin(),
				'backupWritable'   => $backup->isBackupWritable(),
				'imagesBackup'     => (bool) $plugin->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) ),
				'needMigration'    => wbcr_rio_has_meta_to_migrate(),
				'optimizationType' => $is_premium ? $plugin->getPopulateOption( 'image_optimization_type', 'schedule' ) : 'schedule',
				// The rail card says the same thing with the site's own numbers, so one of them is enough.
				'showAvifBanner'   => 'media-library' === $scope && ! $is_premium && ! wrio_is_avif_banner_dismissed() && ! $rail['avifUpsell'],
			],
			'rail'     => $rail,
			'notices'  => self::get_notices( $route ),
			// "Learn more about AVIF": here, not in the first-load urls, so a page switch updates utm_medium.
			'avifDocs' => wrio_docs_url( 'https://docs.themeisle.com/robin-image-optimizer/how-to-convert-images-to-avif-in-robin-image-optimizer', 'avif', $route ),
		];

		if ( 'bulk' === $route ) {
			$data['bulk'] = self::get_bulk_data( $scope );
		}

		if ( 'license' === $route ) {
			$data['license'] = self::license_view()->get_data();
		}

		if ( 'log' === $route ) {
			$data['log'] = self::get_log_data();
		}

		if ( 'settings' === $route ) {
			$data['settings'] = [
				'values'   => WRIO_Settings::get(),
				'defaults' => WRIO_Settings::defaults(),
				'meta'     => WRIO_Settings::meta(),
			];
		}

		return $data;
	}

	/**
	 * Cards in the right rail: a savings milestone, the AVIF upsell and the review request.
	 *
	 * @param bool $is_premium Whether a licence is active.
	 *
	 * @return array{milestone: array{bytes: int, label: string, images: int}|null, avifUpsell: array{converted: int, measured: array{percent: int|null, images: int}|null}|null, review: bool}
	 */
	public static function get_rail_data( $is_premium ) {
		$stats   = WRIO_Image_Statistic::get_instance()->get();
		$user    = get_current_user_id();
		$reached = self::reached_milestone( $stats );

		// Sites that passed a milestone before this card existed get no late congratulations for it.
		$baseline = get_option( 'wrio_milestone_baseline' );
		if ( false === $baseline ) {
			$baseline = $reached;
			add_option( 'wrio_milestone_baseline', $baseline );
		}

		$milestone = null;
		if ( $reached > (int) $baseline && $reached > (int) get_user_meta( $user, 'wrio_milestone_dismissed', true ) ) {
			$milestone = [
				'bytes'  => $reached,
				'label'  => (string) size_format( $reached ),
				'images' => (int) $stats['optimized'],
			];
		}

		$avif_upsell = null;
		// Only the WebP files that were made: a skipped conversion (not smaller) saved nothing.
		$converted = (int) $stats['webp_made'];
		if ( ! $is_premium && $converted >= self::AVIF_UPSELL_MIN_WEBP && ! get_user_meta( $user, 'wrio_avif_dismissed', true ) ) {
			$estimate    = WRIO_Avif_Estimate::get();
			$avif_upsell = [
				'converted' => $converted,
				'measured'  => $estimate && $estimate['percent'] >= self::AVIF_ESTIMATE_MIN_PERCENT ? $estimate : null,
			];
		}

		return [
			'milestone'  => $milestone,
			'avifUpsell' => $avif_upsell,
			'review'     => ! get_user_meta( $user, 'wrio_review_dismissed', true ),
		];
	}

	/**
	 * The largest milestone the media library's saved space has reached.
	 *
	 * @param array<string, mixed> $stats Media library statistics.
	 *
	 * @return int Bytes, 0 before the first milestone.
	 */
	public static function reached_milestone( array $stats ) {
		$saved   = (int) $stats['original_size'] - (int) $stats['optimized_size'];
		$reached = 0;

		foreach ( self::MILESTONES as $milestone ) {
			if ( $saved >= $milestone ) {
				$reached = $milestone;
			}
		}

		return $reached;
	}

	/**
	 * The licence page view, created here when the admin menu has not (REST).
	 *
	 * @return WRIO_License_Page_View
	 */
	protected static function license_view() {
		if ( ! class_exists( 'WRIO_License_Page_View' ) ) {
			require_once WRIO_PLUGIN_DIR . '/admin/includes/class-wrio-subscribe-widget.php';
			require_once WRIO_PLUGIN_DIR . '/admin/pages/class-rio-license.php';
		}

		return WRIO_License_Page_View::$instance ? WRIO_License_Page_View::$instance : new WRIO_License_Page_View();
	}

	/**
	 * Statistics, log, folders and state for one optimization scope.
	 *
	 * @param string $scope media-library|custom-folders|nextgen-gallery.
	 * @param bool   $fresh Recompute the statistics instead of reading the cached values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_bulk_data( $scope, $fresh = false ) {
		// The custom folders classes resolve paths with get_home_path(), an admin-only function.
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$plugin     = WRIO_Plugin::app();
		$statistics = self::get_statistics( $scope );

		$cron_running    = $plugin->getPopulateOption( 'cron_running', false );
		$process_running = $plugin->getPopulateOption( 'process_running', false );

		$data = [
			'scope'    => $scope,
			'stats'    => $fresh ? $statistics->load() : $statistics->get(),
			'log'      => $statistics->get_last_optimized_images(),
			'failures' => $statistics->get_failures(),
			'running'  => [
				'cron'    => $cron_running === $scope,
				'process' => wrio_processing_scope( (string) $process_running ) === wrio_processing_scope( $scope ),
			],
			'percents' => [
				// Compression only: a newly enabled format must not drop the percent of an optimized library.
				'media-library'   => (int) WRIO_Image_Statistic::get_instance()->get()['compressed_percent'],
				'custom-folders'  => class_exists( 'WRIO_Image_Statistic_Folders' ) ? (int) WRIO_Image_Statistic_Folders::get_instance()->getOptimizedPercent() : 0,
				'nextgen-gallery' => class_exists( 'WRIO_Image_Statistic_Nextgen' ) ? (int) WRIO_Image_Statistic_Nextgen::get_instance()->getOptimizedPercent() : 0,
			],
			'features' => self::get_features( $scope ),
			'folders'  => [],
			// A running scheduled run keeps its upload dates when the page pauses and resumes it after a reload.
			'runDates' => 'media-library' === $scope ? WRIO_Image_Query::get_run_dates() : [
				'from' => '',
				'to'   => '',
			],
		];

		$custom_folders = class_exists( 'WRIO_Custom_Folders' ) ? WRIO_Custom_Folders::get_instance() : null;

		if ( 'custom-folders' === $scope && $custom_folders instanceof WRIO_Custom_Folders ) {
			foreach ( $custom_folders->getFolders() as $folder ) {
				$data['folders'][] = [
					'uid'            => $folder->get( 'uid' ),
					'path'           => $folder->get( 'path' ),
					// Counts saved by the scan and the optimizer; walking the directory here
					// made every page load and refresh crawl the whole tree.
					'filesCount'     => (int) $folder->get( 'files_count' ),
					'optimizedCount' => (int) $folder->get( 'optimized_count' ),
				];
			}
		}

		return $data;
	}

	/**
	 * Every log entry in file order, plus the total log size, as the Factory page showed them.
	 *
	 * @return array{entries: list<array{date: string, time: string, host: string, level: string, message: string}>, size: string, exportNonce: string, support: array{url: string, message: string, button: string}}
	 */
	public static function get_log_data() {
		$logger  = WRIO_Plugin::app()->logger;
		$entries = [];
		$file    = $logger->get_dir();

		if ( $file && file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = (string) file_get_contents( $file );
			foreach ( explode( "\n", str_replace( "\r\n", "\n", $content ) ) as $line ) {
				if ( '' === trim( $line ) ) {
					continue;
				}
				// Example: 17-03-2021 13:44:23 [site.com][info] Message
				if ( preg_match( '/^(\S+)\s+(\S+)\s+\[([^\]]*)\]\[([^\]]*)\]\s*(.*)$/', $line, $m ) ) {
					$entries[] = [
						'date'    => $m[1],
						'time'    => $m[2],
						'host'    => $m[3],
						'level'   => $m[4],
						'message' => $m[5],
					];
				} elseif ( ! empty( $entries ) ) {
					$last                         = count( $entries ) - 1;
					$entries[ $last ]['message'] .= "\n" . $line;
				} else {
					// A line without the prefix before any entry, for example from a merged older log.
					$entries[] = [
						'date'    => '',
						'time'    => '',
						'host'    => '',
						'level'   => '',
						'message' => $line,
					];
				}
			}
		}

		$is_premium = wrio_is_license_activate();

		return [
			'entries'     => $entries,
			'size'        => (string) size_format( $logger->get_total_size() ),
			// The app appends this to the Error Log page URL it already has;
			// WRIO_Admin_Pages::maybe_export_log() handles ?action=export.
			'exportNonce' => wp_create_nonce( 'export-' . WRIO_Plugin::app()->getPluginName() ),
			'support'     => [
				'url'     => $is_premium
					? self::themeisle_link( 'http://themeisle.com/contact', 'debug-page', 'debug-support-banner' )
					: 'https://wordpress.org/support/plugin/robin-image-optimizer/',
				'message' => $is_premium
					? __( 'Contact our support team for priority assistance.', 'robin-image-optimizer' )
					: __( 'Please open a topic on the WordPress.org support forum.', 'robin-image-optimizer' ),
				'button'  => $is_premium
					? __( 'Contact Support', 'robin-image-optimizer' )
					: __( 'Open Support Forum', 'robin-image-optimizer' ),
			],
		];
	}

	/**
	 * Statistics object for a scope.
	 *
	 * @param string $scope media-library|custom-folders|nextgen-gallery.
	 *
	 * @return WRIO_Image_Statistic
	 */
	public static function get_statistics( $scope ) {
		if ( 'custom-folders' === $scope && class_exists( 'WRIO_Image_Statistic_Folders' ) ) {
			return WRIO_Image_Statistic_Folders::get_instance();
		}
		if ( 'nextgen-gallery' === $scope && class_exists( 'WRIO_Image_Statistic_Nextgen' ) ) {
			return WRIO_Image_Statistic_Nextgen::get_instance();
		}

		return WRIO_Image_Statistic::get_instance();
	}

	/**
	 * Features in use, shown as chips under the hero: compression plus the
	 * enabled conversion formats (media library only).
	 *
	 * @param string $scope Optimization scope.
	 *
	 * @return string[] Feature keys: compression|webp|avif|only-compression.
	 */
	protected static function get_features( $scope ) {
		$show_formats = ! in_array( $scope, [ 'custom-folders', 'nextgen-gallery' ], true );

		if ( $show_formats ) {
			$formats = WRIO_Formats::get_enabled_formats();
			if ( ! empty( $formats ) ) {
				return array_merge( [ 'compression' ], $formats );
			}
		}

		return [ 'only-compression' ];
	}

	/**
	 * Plugin notices shown above the content.
	 *
	 * @param string $route bulk|settings|license.
	 *
	 * @return array<int, array{type: string, text: string}>
	 */
	protected static function get_notices( $route ) {
		$notices = [];

		if ( current_user_can( 'update_plugins' ) && wbcr_rio_has_meta_to_migrate() ) {
			$notices[] = [
				'type' => 'warning',
				'text' => wrio_get_meta_migration_notice_text(),
			];
		}

		if ( 'settings' !== $route ) {
			return $notices;
		}

		$upload_dir = wp_upload_dir();
		$wio_backup = $upload_dir['basedir'] . '/wio_backup/';

		if ( ! wp_is_writable( $upload_dir['basedir'] ) ) {
			$notices[] = [
				'type' => 'error',
				// translators: %s is the folder path.
				'text' => sprintf( __( 'Folder %s is unavailable for writing', 'robin-image-optimizer' ), 'wp-content/uploads/' ),
			];
		}

		if ( file_exists( $wio_backup ) && ! wp_is_writable( $wio_backup ) ) {
			$notices[] = [
				'type' => 'error',
				// translators: %s is the folder path.
				'text' => sprintf( __( 'Folder %s is unavailable for writing', 'robin-image-optimizer' ), 'wp-content/uploads/wio-backup/' ),
			];
		}

		$delivery = \WRIO\WEBP\HTML\Delivery::class;

		if ( $delivery::should_use_converted_images() && $delivery::is_delivery_mode_enabled() && $delivery::skip_for_optimole() ) {
			$notices[] = [
				'type' => 'warning',
				'text' => __( 'Robin does not change page images while Optimole is active. Make sure Optimole is connected and image optimization is enabled.', 'robin-image-optimizer' ),
			];
		}

		// Cloudflare caches one copy per URL whatever the Accept header: a browser without WebP support could get the
		// WebP copy of another visitor.
		if ( 'redirect' === WRIO_Plugin::app()->getPopulateOption( 'webp_delivery_mode', 'picture' ) && wrio_behind_cloudflare() ) {
			$notices[] = [
				'type' => 'warning',
				'text' => __( 'This site is behind Cloudflare. Cloudflare caches one copy of each image URL for every browser, so Redirect delivery can serve a WebP or AVIF file to a browser that cannot show it. Use Picture delivery, or set Cloudflare to vary the image cache by the Accept header.', 'robin-image-optimizer' ),
			];
		}

		// The last health test of Background optimization failed. The cron health check still runs the queue.
		$test = get_option( 'wrio_loopback_test' );

		if ( is_array( $test ) && empty( $test['ok'] ) && 'background' === WRIO_Plugin::app()->getPopulateOption( 'image_optimization_type', 'schedule' ) ) {
			$notices[] = [
				'type' => 'error',
				'text' => sprintf(
					// translators: 1: the reason, 2: the button label.
					__( 'The last test of Background optimization failed: %1$s. Images can wait until WP-Cron runs. Fix the cause, then click "%2$s" in Settings, or select Scheduled optimization.', 'robin-image-optimizer' ),
					(string) $test['error'],
					__( 'Test again', 'robin-image-optimizer' )
				),
			];
		}

		// A system cron job can run WP-Cron with DISABLE_WP_CRON on: warn only when the events are late.
		if ( ! wrio_cron_runs() ) {
			$notices[] = [
				'type' => 'error',
				'text' => sprintf(
					// translators: 1: wp-cron.php, 2: the WP-CLI command.
					__( 'WP-Cron does not run on this site, so scheduled optimization and new uploads wait. Let a system cron job call %1$s or run %2$s.', 'robin-image-optimizer' ),
					'wp-cron.php',
					'wp robin queue run'
				) . ' ' . WRIO_ERRORS_DOCS_URL . '#wp_cron',
			];
		}

		return $notices;
	}

	/**
	 * Themeisle link with the SDK's locale and campaign tags when the SDK is loaded (wp-admin).
	 *
	 * @param string $url      Base URL.
	 * @param string $area     utm campaign/area.
	 * @param string $location utm content/location.
	 *
	 * @return string
	 */
	protected static function themeisle_link( $url, $area, $location = '' ) {
		if ( ! function_exists( 'tsdk_utmify' ) || ! function_exists( 'tsdk_translate_link' ) ) {
			return $url;
		}

		return '' === $location ? tsdk_utmify( tsdk_translate_link( $url ), $area ) : tsdk_utmify( tsdk_translate_link( $url ), $area, $location );
	}
}
