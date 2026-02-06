<?php

defined( 'ABSPATH' ) || die( 'Cheatin’ uh?' );

/**
 * @var array                           $data
 * @var WRIO_Page $page
 */
?>
<style>
	/**
	 * Стили временно в коде.
	 * Если такой вариант реализации прокрутки для таблицы подойдёт, то стили нужно будет перенести в основной файл
	 * Пример взят с https://jsfiddle.net/tsayen/xuvsncr2/28/
	 */

	.wrio-table-container {
		height: 25em;
	}

	.wrio-table-container table {
		display: flex;
		flex-flow: column;
		height: 100%;
		width: 100%;
	}

	.wrio-table-container table thead {
		/* head takes the height it requires,
		and it's not scaled when table is resized */
		flex: 0 0 auto;
		width: calc(100% - 0.9em);
	}

	.wrio-table-container table tbody {
		/* body takes all the remaining available space */
		flex: 1 1 auto;
		display: block;
		overflow-y: scroll;
	}

	.wrio-table-container table tbody tr {
		width: 100%;
	}

	.wrio-table-container table thead,
	.wrio-table-container table tbody tr {
		display: table;
		table-layout: fixed;
	}

	.wrio-table-container table tbody tr {
		width: 100%;
		word-break: break-all;

	}

	.flash {
		-moz-animation: flash 1s ease-out;
		-webkit-animation: flash 1s ease-out;
		-ms-animation: flash 1s ease-out;
		animation: flash 1s ease-out;
	}

	@-webkit-keyframes flash {
		0% {
			background-color: transparent;
		}
		30% {
			background-color: #fffade;
		}
		100% {
			background-color: transparent;
		}
	}

	@-moz-keyframes flash {
		0% {
			background-color: transparent;
		}
		30% {
			background-color: #fffade;
		}

		100% {
			background-color: transparent;
		}
	}

	@-ms-keyframes flash {
		0% {
			background-color: transparent;
		}
		30% {
			background-color: #fffade;
		}
		100% {
			background-color: transparent;
		}
	}
</style>
<div class="wrio-optimization-progress">
	<div class="wbcr-factory-page-group-header" style="margin-bottom:0;">
		<strong><?php esc_html_e( 'Optimization log', 'robin-image-optimizer' ); ?></strong>
		<p><?php esc_html_e( 'Optimization log shows the last 100 optimized images. You can check the quality of the image by clicking on the file name.', 'robin-image-optimizer' ); ?></p>
	</div>
	<div class="<?php echo esc_attr( empty( $data['process_log'] ) ? 'wrio-table-container-empty' : 'wrio-table-container' ); ?>">
		<table class="wrio-table">
			<thead>
			<tr>
				<th></th>
				<th><?php esc_html_e( 'File name', 'robin-image-optimizer' ); ?></th>
				<th><?php esc_html_e( 'Initial size', 'robin-image-optimizer' ); ?></th>
				<th><?php esc_html_e( 'Optimized size', 'robin-image-optimizer' ); ?></th>
				<?php if ( 'custom-folders' !== $data['scope'] ) : ?>
					<th><?php esc_html_e( 'Compressed thumbnails', 'robin-image-optimizer' ); ?></th>
				<?php endif; ?>
				<th><?php esc_html_e( 'Overall Saving', 'robin-image-optimizer' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php if ( empty( $data['process_log'] ) ) : ?>
				<tr>
					<td colspan="<?php echo( 'custom-folders' !== $data['scope'] ? '9' : '8' ); ?>"><?php esc_html_e( "You don't have optimized images.", 'robin-image-optimizer' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( (array) $data['process_log'] as $item ) : ?>
					<?php if ( isset( $item['type'] ) && 'error' === $item['type'] ) : ?>
						<tr class="wrio-table-item wrio-row-id-<?php echo esc_attr( $item['id'] ); ?> wrio-error" data-attachment-id="<?php echo esc_attr( $item['attachment_id'] ?? $item['id'] ); ?>">
							<td>
								<?php if ( ! empty( $item['original_url'] ) ) : ?>
									<a href="<?php echo esc_url( $item['original_url'] ); ?>" target="_blank">
										<img width="40" height="40" src="<?php echo esc_attr( $item['thumbnail_url'] ); ?>" alt="">
									</a>
								<?php else : ?>
									<img width="40" height="40" src="<?php echo esc_attr( $item['thumbnail_url'] ); ?>" alt="">
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_attr( $item['url'] ); ?>" target="_blank"><?php echo esc_attr( $item['file_name'] ); ?></a>
							</td>
							<td colspan="<?php echo( 'custom-folders' !== $data['scope'] ? '7' : '6' ); ?>">
								<?php esc_html_e( 'Error', 'robin-image-optimizer' ); ?>:
								<?php if ( isset( $item['error_msg'] ) ) : ?>
									<?php echo esc_attr( $item['error_msg'] ); ?>
								<?php else : ?>
									<?php esc_html_e( 'An unexpected error occurred. Please try again.', 'robin-image-optimizer' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php else : ?>
						<tr class="wrio-table-item wrio-row-id-<?php echo esc_attr( $item['id'] ); ?>" data-attachment-id="<?php echo esc_attr( $item['attachment_id'] ?? $item['id'] ); ?>">
							<td>
								<?php if ( ! empty( $item['original_url'] ) ) : ?>
									<a href="<?php echo esc_url( $item['original_url'] ); ?>" target="_blank">
										<img width="40" height="40" src="<?php echo esc_attr( $item['thumbnail_url'] ); ?>" alt="">
									</a>
								<?php else : ?>
									<img width="40" height="40" src="<?php echo esc_attr( $item['thumbnail_url'] ); ?>" alt="">
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_attr( $item['url'] ); ?>"><?php echo esc_attr( $item['file_name'] ); ?></a>
							</td>
							<td class="wrio-original-size">
								<?php echo esc_attr( $item['original_size'] ); ?>
							</td>
							<td class="wrio-optimized-size">
								<?php echo esc_attr( $item['optimized_size'] ); ?>
							</td>
							<?php if ( $data['scope'] !== 'custom-folders' ) : ?>
								<td class="wrio-thumbnails-count">
									<?php echo esc_attr( $item['thumbnails_count'] ); ?>
								</td>
							<?php endif; ?>
							<td class="wrio-total-saving">
								<?php echo esc_attr( $item['total_saving'] ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<?php if ( 'media-library' === $data['scope'] && ! wrio_is_license_activate() && ! wrio_is_avif_banner_dismissed() ) : ?>
<div class="wrio-avif-upsell-banner" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wrio_dismiss_avif_banner' ) ); ?>">
	<button type="button" class="wrio-avif-banner-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'robin-image-optimizer' ); ?>">&times;</button>
	<div class="wrio-avif-banner-icon">
		<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M13 2L3 14h7v8l10-12h-7V2z" fill="#FFB638" stroke="#FF9800" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
		</svg>
	</div>
	<div class="wrio-avif-banner-content">
		<div class="wrio-avif-banner-header">
			<h3 class="wrio-avif-banner-title"><?php esc_html_e( 'Want even smaller files?', 'robin-image-optimizer' ); ?></h3>
			<span class="wrio-avif-pro-badge"><?php esc_html_e( 'PRO', 'robin-image-optimizer' ); ?></span>
		</div>
		<p class="wrio-avif-banner-description">
			<?php
			printf(
				/* translators: %1$s and %2$s are <strong> tags wrapping the compression percentage */
				esc_html__( 'AVIF format delivers %1$s20-50%% better compression%2$s than WebP. Unlock AVIF conversion to maximize your savings.', 'robin-image-optimizer' ),
				'<strong>',
				'</strong>'
			);
			?>
		</p>
		<div class="wrio-avif-banner-actions">
			<a href="<?php echo esc_url( WRIO_Plugin::app()->get_support()->get_pricing_url( true, 'avif_banner' ) ); ?>" class="wrio-avif-unlock-button" target="_blank" rel="noopener">
				<?php esc_html_e( 'Unlock AVIF Conversion', 'robin-image-optimizer' ); ?>
			</a>
			<a href="https://developers.google.com/speed/webp/faq#what_is_the_difference_between_webp_and_avif" target="_blank" rel="noopener" class="wrio-avif-learn-more">
				<?php esc_html_e( 'Learn more about AVIF', 'robin-image-optimizer' ); ?>
			</a>
		</div>
	</div>
</div>
<?php endif; ?>