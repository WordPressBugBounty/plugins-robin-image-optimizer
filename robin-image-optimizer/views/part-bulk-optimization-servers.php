<?php

defined( 'ABSPATH' ) || die( 'Cheatin uh?' );

/**
 * @var array $data
 * @var WRIO_Page $page
 */

$is_premium = wrio_is_license_activate();
?>
<div class="wrio-servers" style="display:none;">
	<div>
		<div class="wrio-server-mode-wrap">
			<span><strong><?php esc_html_e( 'Mode', 'robin-image-optimizer' ); ?>:</strong></span>
			<span class="wrio-server-mode">
				<?php echo esc_html( $is_premium ? __( 'Premium', 'robin-image-optimizer' ) : __( 'Free', 'robin-image-optimizer' ) ); ?>
			</span>
		</div>
		<div class="wrio-server-status-wrap">
			<span><strong><?php esc_html_e( 'Status', 'robin-image-optimizer' ); ?>:</strong></span>
			<span class="wrio-server-status wrio-server-check-proccess"> </span>
		</div>
		<div class="wrio-premium-user-balance-wrap">
			<span><strong><?php esc_html_e( 'Tokens', 'robin-image-optimizer' ); ?>:</strong></span>
			<span class="wrio-premium-user-balance wrio-premium-user-balance-check-proccess"
					data-toggle="tooltip"
					title="<?php echo esc_attr__( 'The all images are limited, including thumbnails', 'robin-image-optimizer' ); ?>"> </span>
		</div>
		<div class="wrio-premium-user-update-wrap">
			<span><strong><?php esc_html_e( 'Next tokens update', 'robin-image-optimizer' ); ?>:</strong></span>
			<span class="wrio-premium-user-update wrio-premium-user-update-check-proccess"
					data-toggle="tooltip"
					title="<?php echo esc_attr__( 'Date when the limit is topped up', 'robin-image-optimizer' ); ?>"></span>
		</div>
	</div>
</div>
