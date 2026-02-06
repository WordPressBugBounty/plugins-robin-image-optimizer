<?php
/**
 * Sidebar widgets
 *
 * @version       1.0
 */

/**
 * Return support widget markup
 *
 * @return string
 */
function wrio_get_sidebar_support_widget() {
	$free_support_url = 'https://wordpress.org/support/plugin/robin-image-optimizer/';
	$support_url      = tsdk_utmify( tsdk_translate_link( 'https://themeisle.com/contact/' ), 'bug-security-ticket' );

	ob_start();
	?>
	<div id="wbcr-clr-support-widget" class="wbcr-factory-sidebar-widget">
		<p><strong><?php _e( 'Having Issues?', 'robin-image-optimizer' ); ?></strong></p>
		<div class="wbcr-clr-support-widget-body">
			<p>
				<?php _e( 'Need help? Create a support ticket and we\'ll assist you.', 'robin-image-optimizer' ); ?>
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

	$output = ob_get_contents();

	ob_end_clean();

	return $output;
}
