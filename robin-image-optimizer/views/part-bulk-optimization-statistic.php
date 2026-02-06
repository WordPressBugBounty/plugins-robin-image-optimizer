<?php

defined( 'ABSPATH' ) || die( 'Cheatin uh?' );

/**
 * @var array $data
 * @var WRIO_Page $page
 */

if ( isset( $data['scope'] ) ) {
	$scope = $data['scope'];
}

$total_images = $data['stats']['optimized'] + $data['stats']['unoptimized'];
?>
<div class="wrio-cards-container">
	<!-- Image Optimization Card -->
	<div class="wrio-card">
		<div class="wrio-card-title"><?php _e( 'Image Optimization', 'robin-image-optimizer' ); ?></div>
		<div class="wrio-donut-container">
			<canvas id="wio-main-chart" width="160" height="160"
					data-unoptimized="<?php echo esc_attr( $data['stats']['unoptimized'] ); ?>"
					data-optimized="<?php echo esc_attr( $data['stats']['optimized'] ); ?>"
					data-errors="<?php echo esc_attr( $data['stats']['error'] ); ?>"
					style="display: block;">
			</canvas>
			<div class="wrio-donut-percent">
				<span id="wio-overview-chart-percent"><?php echo esc_attr( $data['stats']['optimized_percent'] ); ?></span><span>%</span>
			</div>
		</div>

		<div class="wrio-legend">
			<div class="wrio-legend-item">
				<span class="wrio-legend-dot gray"></span>
				<?php _e( 'Unoptimized', 'robin-image-optimizer' ); ?> - <span id="wio-unoptimized-num"><?php echo esc_attr( $data['stats']['unoptimized'] ); ?></span>
			</div>
			<div class="wrio-legend-item">
				<span class="wrio-legend-dot green"></span>
				<?php _e( 'Optimized', 'robin-image-optimizer' ); ?> - <span id="wio-optimized-num"><?php echo esc_attr( $data['stats']['optimized'] ); ?></span>
			</div>
			<div class="wrio-legend-item">
				<span class="wrio-legend-dot red"></span>
				<?php _e( 'Error', 'robin-image-optimizer' ); ?> - <span id="wio-error-num"><?php echo esc_attr( $data['stats']['error'] ); ?></span>
			</div>
		</div>

		<div class="wrio-size-info">
			<div class="wrio-size-row">
				<span><?php _e( 'Original size', 'robin-image-optimizer' ); ?></span>
				<span id="wio-original-size"><?php echo esc_attr( wrio_convert_bytes( $data['stats']['original_size'] ) ); ?></span>
			</div>
			<div class="wrio-size-bar">
				<div class="wrio-size-bar-fill" style="width: 100%"></div>
			</div>
			<div class="wrio-size-row">
				<span><?php _e( 'Optimized size', 'robin-image-optimizer' ); ?></span>
				<span id="wio-optimized-size"><?php echo esc_attr( wrio_convert_bytes( $data['stats']['optimized_size'] ) ); ?></span>
			</div>
			<div class="wrio-size-bar">
				<div id="wio-optimized-bar" class="wrio-size-bar-fill optimized" style="width: <?php echo esc_attr( isset( $data['stats']['percent_line'] ) ? $data['stats']['percent_line'] : 0 ); ?>%"></div>
			</div>
			<div class="wrio-size-row">
				<span><?php _e( 'Total saved', 'robin-image-optimizer' ); ?></span>
				<span id="wio-total-saved" class="wrio-green"><?php echo esc_attr( $data['stats']['save_size_percent'] ); ?>%</span>
			</div>
		</div>

		<div class="wrio-card-footer">
			<?php $this->print_template( 'part-bulk-optimization-button', $data, $page ); ?>
		</div>
	</div>
</div>

<div class="wrio-statistic-message"></div>
