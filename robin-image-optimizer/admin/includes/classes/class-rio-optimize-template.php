<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс реализует шаблон блока статистики
 *
 * @version       1.0
 */
class WIO_OptimizePageTemplate {

	/**
	 * Тип страницы
	 */
	protected $page_type = 'media-library';


	public function __construct( $type = 'media-library' ) {
		$this->page_type = $type;
	}

	/**
	 * Выводит контент страницы с учётом мультисайта
	 *
	 * @param WRIO_Page $page
	 *
	 * @throws Exception
	 */

	/*
	public function showPageContent( WRIO_Page $page ) {
		do_action( 'wbcr/rio/multisite_current_blog' );
		$this->pageContent( $page );
		do_action( 'wbcr/rio/multisite_restore_blog' );
	}*/

	/**
	 * Выбор сайта для мультисайт режима
	 */
	/*
	public function selectSite() {
		if ( ! WRIO_Plugin::app()->isNetworkAdmin() ) {
			return;
		}
		$blogs        = WIO_Multisite::getBlogs( $this->page_type );
		$current_blog = WRIO_Plugin::app()->getPopulateOption( 'current_blog', 1 );
		?>
		<select style="width:200px;display:inline-block; height: 45px; margin-left:40px;" id="wbcr-rio-current-blog"
				class="factory-dropdown factory-from-control-dropdown form-control"
				data-context="<?php echo esc_attr( $this->page_type ); ?>"
				data-nonce="<?php echo wp_create_nonce( 'update_blog_id' ); ?>">
			<?php foreach ( $blogs as $blog ) : ?>
				<?php
				$blog_name = $blog->domain . $blog->path;
				if ( defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL ) {
					$blog_name = $blog->domain;
				}
				?>
				<option <?php selected( $current_blog, $blog->blog_id ); ?>
						value="<?php echo esc_attr( $blog->blog_id ); ?>"><?php echo esc_attr( $blog_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}*/

	/**
	 * Возвращает html код блока ручной оптимизации
	 *
	 * @param array $params {
	 *                                    Параметры
	 *
	 * @type int $attachment_id Attachment post ID
	 * @type bool $is_optimized Оптимизировано ли изображение
	 * @type string $attach_dimensions Размеры изображения. Например 200x150
	 * @type int $attachment_file_size Размер оригинального основного файла в байтах
	 * @type bool $is_skipped Пропущено ли изображение. Изображения с таким флагом больше не участвуют в
	 *       оптимизации
	 * @type int $optimized_size Оптимизированный размер основного файла + превьюшек в байтах
	 * @type int $original_size Оригинальный размер основного файла + превьюшек в байтах
	 * @type int $original_main_size Оригинальный размер основного файла в байтах
	 * @type int $thumbnails_optimized Кол-во оптимизированных превьюшек
	 * @type string $optimization_level Уровень оптимизации
	 * @type string $error_msg Текст ошибки
	 * @type bool $backuped Сделана ли резервная копия
	 * @type float $diff_percent Разница между оригиналом и оптимизацией в процентах
	 * @type float $diff_percent_all Общая оптимизация в процентах
	 * }
	 *
	 * @return string
	 */
	public function getMediaColumnTemplate( $params ) {
		ob_start();

		$ajaxActionOptimize = apply_filters( 'wbcr/rio/optimize_template/reoptimize_ajax_action', 'wio_reoptimize_image', $this->page_type );
		$ajaxActionConvert  = apply_filters( 'wbcr/rio/optimize_template/convert_ajax_action', 'wio_convert_image', $this->page_type );
		$ajaxActionRestore  = apply_filters( 'wbcr/rio/optimize_template/restore_ajax_action', 'wio_restore_image', $this->page_type );

		$attachment_id        = $params['attachment_id'];
		$is_optimized         = $params['is_optimized'];
		$attach_dimensions    = $params['attach_dimensions'];
		$attachment_file_size = $params['attachment_file_size'];
		$is_skipped           = $params['is_skipped'];
		$is_supported_format  = isset( $params['is_supported_format'] ) ? $params['is_supported_format'] : true;
		$webp_size            = $params['webp_size'];
		$avif_size            = isset( $params['avif_size'] ) ? $params['avif_size'] : 0;
		$webp_percent         = isset( $params['webp_percent'] ) ? $params['webp_percent'] : 0;
		$avif_percent         = isset( $params['avif_percent'] ) ? $params['avif_percent'] : 0;
		$backuped             = isset( $params['backuped'] ) ? $params['backuped'] : false;

		if ( $is_skipped ) {
			return (string) ob_get_clean();
		}

		// Don't show buttons for unsupported formats
		if ( ! $is_supported_format ) {
			return (string) ob_get_clean();
		}

		if ( $is_optimized ) {
			$original_main_size   = $params['original_main_size'];
			$thumbnails_optimized = $params['thumbnails_optimized'];
			$optimization_level   = $params['optimization_level'];
			$error_msg            = $params['error_msg'];
			$diff_percent         = $params['diff_percent'];
			$diff_percent_all     = $params['diff_percent_all'];

			?>
			<ul class="wio-datas-list" data-size="<?php echo esc_attr( size_format( $attachment_file_size ) ); ?>"
				data-dimensions="<?php echo esc_attr( $attach_dimensions ); ?>">
				<li class="wio-data-item"><span
							class="data"><?php _e( 'New Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="big"><?php echo esc_attr( size_format( $attachment_file_size, 1 ) ); ?></strong>
					<span class="wio-chart-value">(<?php echo esc_attr( $diff_percent ); ?>%)</span></li>
				<?php if ( $webp_size ) : ?>
				<li class="wio-data-item"><span
							class="data"><?php _e( 'WebP Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="big"><?php echo esc_attr( size_format( $webp_size, 1 ) ); ?></strong>
					<span class="wio-chart-value">(<?php echo esc_attr( $webp_percent ); ?>%)</span></li>
				<?php endif; ?>
				<?php if ( $avif_size ) : ?>
				<li class="wio-data-item"><span
							class="data"><?php _e( 'AVIF Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="big"><?php echo esc_attr( size_format( $avif_size, 1 ) ); ?></strong>
					<span class="wio-chart-value">(<?php echo esc_attr( $avif_percent ); ?>%)</span></li>
				<?php endif; ?>
				<li class="wio-data-item">
					<span class="data"><?php _e( 'Original Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="original"><?php echo esc_attr( size_format( $original_main_size, 1 ) ); ?></strong>
				</li>
				<li class="wio-data-item"><span class="data"><?php _e( 'Level:', 'robin-image-optimizer' ); ?></span>
					<strong>
						<?php
						if ( ! $error_msg ) {
							// если уровень кастомный от 1 до 100
							if ( is_numeric( $optimization_level ) ) {
								echo __( 'Custom', 'robin-image-optimizer' ) . ' ' . intval( $optimization_level ) . '%';
							} else {
								// если уровень один из настроек
								if ( $optimization_level == 'normal' ) {
									echo __( 'Lossless', 'robin-image-optimizer' );
								} elseif ( $optimization_level == 'aggresive' ) {
									echo __( 'Lossy', 'robin-image-optimizer' );
								} else {
									echo __( 'High', 'robin-image-optimizer' );
								}
							}
						}
						?>
					</strong>
				</li>
				<li class="wio-data-item">
					<span class="data"><?php _e( 'Thumbnails Optimized:', 'robin-image-optimizer' ); ?></span>
					<strong class="original"><?php echo intval( $thumbnails_optimized ); ?></strong>
				</li>
				<li class="wio-data-item">
					<span class="data"><?php _e( 'Overall Saving:', 'robin-image-optimizer' ); ?></span>
					<strong class="original"><?php echo esc_attr( $diff_percent_all ); ?>%</strong>
				</li>
				<?php if ( $error_msg ) : ?>
					<li class="wio-data-item">
						<span class="data"><?php _e( 'Error Message:', 'robin-image-optimizer' ); ?></span>
						<strong><?php echo esc_attr( $error_msg ); ?></strong></li>
				<?php endif; ?>
			</ul>
			<div class="wio-datas-actions-links" style="display:inline;">
				<?php if ( $optimization_level != 'normal' ) : ?>
					<a data-action="<?php echo esc_attr( $ajaxActionOptimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level="normal"
						data-nonce="<?php echo wp_create_nonce( 'reoptimize' ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php _e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<span
							class="wio-hide-if-small"
						>
							<?php _e( 'Re-Optimize to:', 'robin-image-optimizer' ); ?>
						</span><?php _e( 'Lossless', 'robin-image-optimizer' ); ?>
						<span class="wio-hide-if-small"></span>
					</a>
				<?php endif; ?>
				<?php if ( $optimization_level != 'aggresive' ) : ?>
					<a data-action="<?php echo esc_attr( $ajaxActionOptimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level="aggresive"
						data-nonce="<?php echo wp_create_nonce( 'reoptimize' ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php _e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<span
							class="wio-hide-if-small"
						>
							<?php _e( 'Re-Optimize to:', 'robin-image-optimizer' ); ?>
						</span>
						<?php _e( 'Lossy', 'robin-image-optimizer' ); ?>
						<span class="wio-hide-if-small"></span>
					</a>
				<?php endif; ?>
				<?php if ( $optimization_level != 'ultra' ) : ?>
					<a data-action="<?php echo esc_attr( $ajaxActionOptimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level="ultra"
						data-nonce="<?php echo wp_create_nonce( 'reoptimize' ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php _e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<span
							class="wio-hide-if-small"
						>
							<?php _e( 'Re-Optimize to:', 'robin-image-optimizer' ); ?>
						</span>
						<?php _e( 'High', 'robin-image-optimizer' ); ?>
						<span class="wio-hide-if-small"></span>
					</a>
				<?php endif; ?>
				<?php // WebP conversion is available to all users ?>
				<a data-action="<?php echo esc_attr( $ajaxActionConvert ); ?>"
					data-id="<?php echo esc_attr( $attachment_id ); ?>"
					data-format="webp"
					data-nonce="<?php echo wp_create_nonce( 'convert' ); ?>"
					href="#"
					class="wio-convert button-wio-manual-override-upload"
					data-waiting-label="<?php esc_html_e( 'Convert in progress', 'robin-image-optimizer' ); ?>">
					<span class="dashicons dashicons-images-alt2"></span>
					<?php echo esc_html( $webp_size ? __( 'Re-convert to WebP', 'robin-image-optimizer' ) : __( 'Convert to WebP', 'robin-image-optimizer' ) ); ?>
				</a>
				<?php // AVIF conversion is a premium-only feature ?>
				<?php if ( wrio_is_license_activate() ) : ?>
					<a data-action="<?php echo esc_attr( $ajaxActionConvert ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-format="avif"
						data-nonce="<?php echo wp_create_nonce( 'convert' ); ?>"
						href="#"
						class="wio-convert button-wio-manual-override-upload"
						data-waiting-label="<?php esc_html_e( 'Convert in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-images-alt2"></span>
						<?php echo esc_html( $avif_size ? __( 'Re-convert to AVIF', 'robin-image-optimizer' ) : __( 'Convert to AVIF', 'robin-image-optimizer' ) ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $backuped ) : ?>
					<a
						href="#" data-action="<?php echo esc_attr( $ajaxActionRestore ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-nonce="<?php echo wp_create_nonce( 'restore' ); ?>"
						class="button-wio-restore attachment-has-backup"
						data-waiting-label="<?php esc_html_e( 'Recovery in progress', 'robin-image-optimizer' ); ?>"
					>
						<span class="dashicons dashicons-image-rotate"></span>
						<?php esc_html_e( 'Restore original', 'robin-image-optimizer' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<!-- .wio-datas-actions-links -->
			<?php
		} elseif ( $attach_dimensions !== '0 x 0' ) {
			// Show full stats view if any conversion exists
			if ( $webp_size || $avif_size ) {
				?>
				<ul class="wio-datas-list" data-size="<?php echo esc_attr( size_format( $attachment_file_size ) ); ?>"
					data-dimensions="<?php echo esc_attr( $attach_dimensions ); ?>">
					<li class="wio-data-item">
						<span class="data"><?php esc_html_e( 'Original Filesize:', 'robin-image-optimizer' ); ?></span>
						<strong class="big"><?php echo esc_attr( size_format( $attachment_file_size, 1 ) ); ?></strong>
					</li>
					<?php if ( $webp_size ) : ?>
						<?php $webp_saving = $attachment_file_size ? round( ( $attachment_file_size - $webp_size ) * 100 / $attachment_file_size ) : 0; ?>
					<li class="wio-data-item">
						<span class="data"><?php esc_html_e( 'WebP Filesize:', 'robin-image-optimizer' ); ?></span>
						<strong class="big"><?php echo esc_attr( size_format( $webp_size, 1 ) ); ?></strong>
						<span class="wio-chart-value">(<?php echo esc_attr( $webp_saving ); ?>%)</span>
					</li>
					<?php endif; ?>
					<?php if ( $avif_size ) : ?>
						<?php $avif_saving = $attachment_file_size ? round( ( $attachment_file_size - $avif_size ) * 100 / $attachment_file_size ) : 0; ?>
					<li class="wio-data-item">
						<span class="data"><?php esc_html_e( 'AVIF Filesize:', 'robin-image-optimizer' ); ?></span>
						<strong class="big"><?php echo esc_attr( size_format( $avif_size, 1 ) ); ?></strong>
						<span class="wio-chart-value">(<?php echo esc_attr( $avif_saving ); ?>%)</span>
					</li>
					<?php endif; ?>
				</ul>
				<div class="wio-datas-actions-links" style="display:inline;">
					<a data-action="<?php echo esc_attr( $ajaxActionOptimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level=""
						data-nonce="<?php echo wp_create_nonce( 'reoptimize' ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Optimize', 'robin-image-optimizer' ); ?>
					</a>
					<?php // WebP conversion is available to all users ?>
					<a data-action="<?php echo esc_attr( $ajaxActionConvert ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-format="webp"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'convert' ) ); ?>"
						href="#"
						class="wio-convert button-wio-manual-override-upload"
						data-waiting-label="<?php esc_attr_e( 'Convert in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-images-alt2"></span>
						<?php echo esc_html( $webp_size ? __( 'Re-convert to WebP', 'robin-image-optimizer' ) : __( 'Convert to WebP', 'robin-image-optimizer' ) ); ?>
					</a>
					<?php // AVIF conversion is a premium-only feature ?>
					<?php if ( wrio_is_license_activate() ) : ?>
						<a data-action="<?php echo esc_attr( $ajaxActionConvert ); ?>"
							data-id="<?php echo esc_attr( $attachment_id ); ?>"
							data-format="avif"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'convert' ) ); ?>"
							href="#"
							class="wio-convert button-wio-manual-override-upload"
							data-waiting-label="<?php esc_attr_e( 'Convert in progress', 'robin-image-optimizer' ); ?>">
							<span class="dashicons dashicons-images-alt2"></span>
							<?php echo esc_html( $avif_size ? __( 'Re-convert to AVIF', 'robin-image-optimizer' ) : __( 'Convert to AVIF', 'robin-image-optimizer' ) ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $backuped ) : ?>
					<a
						href="#" data-action="<?php echo esc_attr( $ajaxActionRestore ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'restore' ) ); ?>"
						class="button-wio-restore attachment-has-backup"
						data-waiting-label="<?php esc_attr_e( 'Recovery in progress', 'robin-image-optimizer' ); ?>"
					>
						<span class="dashicons dashicons-image-rotate"></span>
						<?php esc_html_e( 'Restore original', 'robin-image-optimizer' ); ?>
					</a>
					<?php endif; ?>
				</div>
				<!-- .wio-datas-actions-links -->
				<?php
			} else {
				// No conversions yet - show buttons
				?>
				<button
					type="button" data-action="<?php echo esc_attr( $ajaxActionOptimize ); ?>"
					data-nonce="<?php echo wp_create_nonce( 'reoptimize' ); ?>"
					data-id="<?php echo esc_attr( $attachment_id ); ?>" data-level=""
					class="wio-reoptimize button button-primary button-large"
					data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>"
					data-size="<?php echo esc_attr( size_format( $attachment_file_size ) ); ?>"
					data-dimensions="<?php echo esc_attr( $attach_dimensions ); ?>"
				>
					<?php esc_html_e( 'Optimize', 'robin-image-optimizer' ); ?>
				</button>
				<?php // WebP conversion is available to all users ?>
				<button
					type="button" data-action="<?php echo esc_attr( $ajaxActionConvert ); ?>"
					data-nonce="<?php echo wp_create_nonce( 'convert' ); ?>"
					data-id="<?php echo esc_attr( $attachment_id ); ?>"
					data-format="webp"
					class="wio-convert button button-primary button-large"
					data-waiting-label="<?php esc_attr_e( 'Convert in progress', 'robin-image-optimizer' ); ?>"
					data-size="<?php echo esc_attr( size_format( $attachment_file_size ) ); ?>"
					data-dimensions="<?php echo esc_attr( $attach_dimensions ); ?>"
				>
					<?php esc_html_e( 'Convert to WebP', 'robin-image-optimizer' ); ?>
				</button>
				<?php // AVIF conversion is a premium-only feature ?>
				<?php if ( wrio_is_license_activate() ) : ?>
					<button
						type="button" data-action="<?php echo esc_attr( $ajaxActionConvert ); ?>"
						data-nonce="<?php echo wp_create_nonce( 'convert' ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-format="avif"
						class="wio-convert button button-primary button-large"
						data-waiting-label="<?php esc_attr_e( 'Convert in progress', 'robin-image-optimizer' ); ?>"
						data-size="<?php echo esc_attr( size_format( $attachment_file_size ) ); ?>"
						data-dimensions="<?php echo esc_attr( $attach_dimensions ); ?>"
					>
						<?php esc_html_e( 'Convert to AVIF', 'robin-image-optimizer' ); ?>
					</button>
				<?php endif; ?>
				<?php
			}
		}

		return (string) ob_get_clean();
	}
}
