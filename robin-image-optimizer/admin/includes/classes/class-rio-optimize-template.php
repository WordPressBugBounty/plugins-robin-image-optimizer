<?php
/**
 * Template of the media library optimization column.
 *
 * @package Robin_Image_Optimizer
 */

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
	 *
	 * @var string
	 */
	protected $page_type = 'media-library';


	/**
	 * Constructor.
	 *
	 * @param string $type Page type.
	 */
	public function __construct( $type = 'media-library' ) {
		$this->page_type = $type;
	}

	/**
	 * Возвращает html код блока ручной оптимизации
	 *
	 * @param array<string, mixed> $params {
	 *     Параметры.
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
	 * @type bool $can_convert Optional. Whether to show the WebP/AVIF convert buttons. Default true.
	 * }
	 *
	 * @return string
	 */
	public function getMediaColumnTemplate( $params ) {
		ob_start();

		$ajax_action_optimize = apply_filters( 'wbcr/rio/optimize_template/reoptimize_ajax_action', 'wio_reoptimize_image', $this->page_type ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public hook name.
		$ajax_action_convert  = apply_filters( 'wbcr/rio/optimize_template/convert_ajax_action', 'wio_convert_image', $this->page_type ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public hook name.
		$ajax_action_restore  = apply_filters( 'wbcr/rio/optimize_template/restore_ajax_action', 'wio_restore_image', $this->page_type ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Public hook name.

		$attachment_id        = $params['attachment_id'];
		$is_optimized         = $params['is_optimized'];
		$attach_dimensions    = $params['attach_dimensions'];
		$attachment_file_size = $params['attachment_file_size'];
		$is_skipped           = $params['is_skipped'];
		$is_supported_format  = isset( $params['is_supported_format'] ) ? $params['is_supported_format'] : true;
		$can_convert          = isset( $params['can_convert'] ) ? $params['can_convert'] : true;
		$webp_size            = isset( $params['webp_size'] ) ? $params['webp_size'] : 0;
		$avif_size            = isset( $params['avif_size'] ) ? $params['avif_size'] : 0;
		$webp_percent         = isset( $params['webp_percent'] ) ? $params['webp_percent'] : 0;
		$avif_percent         = isset( $params['avif_percent'] ) ? $params['avif_percent'] : 0;
		$backuped             = isset( $params['backuped'] ) ? $params['backuped'] : false;
		$other_levels         = isset( $params['other_levels'] ) ? $params['other_levels'] : $backuped;
		$error_msg            = isset( $params['error_msg'] ) ? $params['error_msg'] : '';

		if ( $is_skipped ) {
			return (string) ob_get_clean();
		}

		// Don't show buttons for unsupported formats
		if ( ! $is_supported_format ) {
			return (string) ob_get_clean();
		}

		// Why bulk and automatic runs skip the image, also once a manual click optimized it.
		$excluded      = [
			'image'  => __( 'this image is excluded in its options', 'robin-image-optimizer' ),
			'path'   => __( 'its path matches an "Exclude paths" pattern', 'robin-image-optimizer' ),
			'filter' => __( 'the wrio_should_optimize filter skips it', 'robin-image-optimizer' ),
		][ $params['excluded'] ?? '' ] ?? '';
		$excluded_html = '' === $excluded ? '' : '<ul class="wio-datas-list wio-excluded"><li class="wio-data-item"><span class="data">' . esc_html__( 'Excluded:', 'robin-image-optimizer' ) . '</span> <strong>' . esc_html( $excluded ) . '</strong></li></ul>';

		if ( $is_optimized ) {
			echo $excluded_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
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
							class="data"><?php esc_html_e( 'New Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="big"><?php echo esc_attr( size_format( $attachment_file_size, 1 ) ); ?></strong>
					<span class="wio-chart-value">(<?php echo esc_attr( $diff_percent ); ?>%)</span></li>
				<?php if ( $webp_size ) : ?>
				<li class="wio-data-item"><span
							class="data"><?php esc_html_e( 'WebP Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="big"><?php echo esc_attr( size_format( $webp_size, 1 ) ); ?></strong>
					<span class="wio-chart-value">(<?php echo esc_attr( $webp_percent ); ?>%)</span></li>
				<?php endif; ?>
				<?php if ( $avif_size ) : ?>
				<li class="wio-data-item"><span
							class="data"><?php esc_html_e( 'AVIF Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="big"><?php echo esc_attr( size_format( $avif_size, 1 ) ); ?></strong>
					<span class="wio-chart-value">(<?php echo esc_attr( $avif_percent ); ?>%)</span></li>
				<?php endif; ?>
				<li class="wio-data-item">
					<span class="data"><?php esc_html_e( 'Original Filesize:', 'robin-image-optimizer' ); ?></span>
					<strong class="original"><?php echo esc_attr( size_format( $original_main_size, 1 ) ); ?></strong>
				</li>
				<li class="wio-data-item"><span class="data"><?php esc_html_e( 'Level:', 'robin-image-optimizer' ); ?></span>
					<strong>
						<?php
						if ( ! $error_msg ) {
							// если уровень кастомный от 1 до 100
							if ( is_numeric( $optimization_level ) ) {
								/* translators: %d: custom compression level, from 1 to 100. */
								echo esc_html( sprintf( __( 'Custom %d%%', 'robin-image-optimizer' ), intval( $optimization_level ) ) );
							} elseif ( 'normal' === $optimization_level ) {
								// если уровень один из настроек
								echo esc_html__( 'Lossless', 'robin-image-optimizer' );
							} elseif ( 'aggresive' === $optimization_level ) {
								echo esc_html__( 'Lossy', 'robin-image-optimizer' );
							} elseif ( 'googlepage' === $optimization_level ) {
								echo esc_html__( 'G PageSpeed', 'robin-image-optimizer' );
							} else {
								echo esc_html__( 'High', 'robin-image-optimizer' );
							}
						}
						?>
					</strong>
				</li>
				<li class="wio-data-item">
					<span class="data"><?php esc_html_e( 'Thumbnails Optimized:', 'robin-image-optimizer' ); ?></span>
					<strong class="original"><?php echo intval( $thumbnails_optimized ); ?></strong>
				</li>
				<li class="wio-data-item">
					<span class="data"><?php esc_html_e( 'Overall Saving:', 'robin-image-optimizer' ); ?></span>
					<strong class="original"><?php echo esc_attr( $diff_percent_all ); ?>%</strong>
				</li>
				<?php if ( $error_msg ) : ?>
					<li class="wio-data-item">
						<span class="data"><?php esc_html_e( 'Error Message:', 'robin-image-optimizer' ); ?></span>
						<strong><?php echo esc_attr( $error_msg ); ?></strong></li>
				<?php endif; ?>
			</ul>
			<div class="wio-datas-actions-links" style="display:inline;">
				<?php // A new level starts from the original (see WRIO\V2\Optimizer::has_original()). ?>
				<?php if ( $other_levels && 'normal' !== $optimization_level ) : ?>
					<a data-action="<?php echo esc_attr( $ajax_action_optimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level="normal"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'reoptimize' ) ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php
						printf(
							/* translators: %1$s and %2$s: the tags around the words that narrow screens hide. */
							esc_html__( '%1$sRe-Optimize to:%2$s Lossless', 'robin-image-optimizer' ),
							'<span class="wio-hide-if-small">',
							'</span>'
						);
						?>
					</a>
				<?php endif; ?>
				<?php if ( $other_levels && 'aggresive' !== $optimization_level ) : ?>
					<a data-action="<?php echo esc_attr( $ajax_action_optimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level="aggresive"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'reoptimize' ) ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php
						printf(
							/* translators: %1$s and %2$s: the tags around the words that narrow screens hide. */
							esc_html__( '%1$sRe-Optimize to:%2$s Lossy', 'robin-image-optimizer' ),
							'<span class="wio-hide-if-small">',
							'</span>'
						);
						?>
					</a>
				<?php endif; ?>
				<?php if ( $other_levels && 'ultra' !== $optimization_level ) : ?>
					<a data-action="<?php echo esc_attr( $ajax_action_optimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level="ultra"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'reoptimize' ) ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php
						printf(
							/* translators: %1$s and %2$s: the tags around the words that narrow screens hide. */
							esc_html__( '%1$sRe-Optimize to:%2$s High', 'robin-image-optimizer' ),
							'<span class="wio-hide-if-small">',
							'</span>'
						);
						?>
					</a>
				<?php endif; ?>
				<?php if ( $can_convert ) : ?>
					<?php // WebP conversion is available to all users ?>
				<a data-action="<?php echo esc_attr( $ajax_action_convert ); ?>"
					data-id="<?php echo esc_attr( $attachment_id ); ?>"
					data-format="webp"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'convert' ) ); ?>"
					href="#"
					class="wio-convert button-wio-manual-override-upload"
					data-waiting-label="<?php esc_html_e( 'Convert in progress', 'robin-image-optimizer' ); ?>">
					<span class="dashicons dashicons-images-alt2"></span>
					<?php echo esc_html( $webp_size ? __( 'Re-convert to WebP', 'robin-image-optimizer' ) : __( 'Convert to WebP', 'robin-image-optimizer' ) ); ?>
				</a>
					<?php // AVIF conversion is a premium-only feature ?>
					<?php if ( wrio_is_license_activate() ) : ?>
					<a data-action="<?php echo esc_attr( $ajax_action_convert ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-format="avif"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'convert' ) ); ?>"
						href="#"
						class="wio-convert button-wio-manual-override-upload"
						data-waiting-label="<?php esc_html_e( 'Convert in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-images-alt2"></span>
						<?php echo esc_html( $avif_size ? __( 'Re-convert to AVIF', 'robin-image-optimizer' ) : __( 'Convert to AVIF', 'robin-image-optimizer' ) ); ?>
					</a>
				<?php endif; ?>
				<?php endif; ?>
				<?php if ( $backuped ) : ?>
					<a
						href="#" data-action="<?php echo esc_attr( $ajax_action_restore ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'restore' ) ); ?>"
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
		} elseif ( '0 x 0' !== $attach_dimensions ) {
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
					<?php if ( $error_msg ) : ?>
					<li class="wio-data-item">
						<span class="data"><?php esc_html_e( 'Error Message:', 'robin-image-optimizer' ); ?></span>
						<strong><?php echo esc_html( $error_msg ); ?></strong></li>
					<?php endif; ?>
				</ul>
				<div class="wio-datas-actions-links" style="display:inline;">
					<a data-action="<?php echo esc_attr( $ajax_action_optimize ); ?>"
						data-id="<?php echo esc_attr( $attachment_id ); ?>"
						data-level=""
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'reoptimize' ) ); ?>"
						href="#"
						class="wio-reoptimize button-wio-manual-override-upload"
						data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'Optimize', 'robin-image-optimizer' ); ?>
					</a>
					<?php if ( $can_convert ) : ?>
						<?php // WebP conversion is available to all users ?>
					<a data-action="<?php echo esc_attr( $ajax_action_convert ); ?>"
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
						<a data-action="<?php echo esc_attr( $ajax_action_convert ); ?>"
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
					<?php endif; ?>
					<?php if ( $backuped ) : ?>
					<a
						href="#" data-action="<?php echo esc_attr( $ajax_action_restore ); ?>"
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
				// No conversions yet - show buttons, and the error of the last try.
				echo $excluded_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				?>
				<?php if ( $error_msg ) : ?>
				<ul class="wio-datas-list">
					<li class="wio-data-item">
						<span class="data"><?php esc_html_e( 'Error Message:', 'robin-image-optimizer' ); ?></span>
						<strong><?php echo esc_html( $error_msg ); ?></strong></li>
				</ul>
				<?php endif; ?>
				<button
					type="button" data-action="<?php echo esc_attr( $ajax_action_optimize ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'reoptimize' ) ); ?>"
					data-id="<?php echo esc_attr( $attachment_id ); ?>" data-level=""
					class="wio-reoptimize button button-primary button-large"
					data-waiting-label="<?php esc_attr_e( 'Optimization in progress', 'robin-image-optimizer' ); ?>"
					data-size="<?php echo esc_attr( size_format( $attachment_file_size ) ); ?>"
					data-dimensions="<?php echo esc_attr( $attach_dimensions ); ?>"
				>
					<?php esc_html_e( 'Optimize', 'robin-image-optimizer' ); ?>
				</button>
				<?php if ( $can_convert ) : ?>
					<?php // WebP conversion is available to all users ?>
				<button
					type="button" data-action="<?php echo esc_attr( $ajax_action_convert ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'convert' ) ); ?>"
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
						type="button" data-action="<?php echo esc_attr( $ajax_action_convert ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'convert' ) ); ?>"
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
				<?php endif; ?>
				<?php // The main file failed, and the optimized thumbnails have backups. ?>
				<?php if ( $backuped ) : ?>
				<button
					type="button" data-action="<?php echo esc_attr( $ajax_action_restore ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'restore' ) ); ?>"
					data-id="<?php echo esc_attr( $attachment_id ); ?>"
					class="button-wio-restore attachment-has-backup button button-large"
					data-waiting-label="<?php esc_attr_e( 'Recovery in progress', 'robin-image-optimizer' ); ?>"
				>
					<?php esc_html_e( 'Restore original', 'robin-image-optimizer' ); ?>
				</button>
				<?php endif; ?>
				<?php
			}
		}

		return (string) ob_get_clean();
	}
}
