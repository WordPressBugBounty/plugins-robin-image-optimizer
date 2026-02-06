<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

use WRIO\WEBP\HTML\Delivery as WEBP_Delivery;

/**
 * @var array $data
 */

$webp_enabled = WRIO_Format_Converter_Factory::is_webp_enabled();
$avif_enabled = WRIO_Format_Converter_Factory::is_avif_enabled();
$show_options = $webp_enabled || $avif_enabled;
?>

<div class="form-group factory-control-webp_delivery_mode wrio-conversion-delivery-options"<?php echo ! $show_options ? ' style="display:none;"' : ''; ?>>
	<label class="col-sm-4 control-label">
		<?php esc_html_e( 'Delivery mode for converted images', 'robin-image-optimizer' ); ?>
	</label>
	<div class="control-group col-sm-8">
		<div class="factory-control factory-control-dropdown">
			<ul>
				<li>
					<label for="wrio-webp-options-radio-redirection">
						<input
							type="radio"
							id="wrio-webp-options-radio-redirection"
							name="wrio_webp_delivery_mode"
							class="wrio-webp-options-radio"
							value="<?php echo esc_attr( WEBP_Delivery::REDIRECT_DELIVERY_MODE ); ?>"
							<?php disabled( ! $data['allow_redirection_mode'] ); ?>
							<?php checked( WEBP_Delivery::REDIRECT_DELIVERY_MODE, $data['delivery_mode'] ); ?>
						>
						<?php esc_html_e( 'Redirection (via .htaccess)', 'robin-image-optimizer' ); ?>
					</label>
					<p class="wrio-webp-options-info">
						<?php
						echo wp_kses_post(
							sprintf(
								// translators: %1$s and %2$s are opening and closing bold tags.
								__( 'This will add rules in the .htaccess that redirects directly to existing converted files. Best performance is achieved by redirecting in .htaccess. Based on testing your particular hosting configuration, we determined that your server %1$scan\'t%2$s serve the WebP/AVIF versions of the JPEG files seamlessly, via .htaccess.', 'robin-image-optimizer' ),
								'<b style="color:red">',
								'</b>'
							)
						);
						?>
						<br>
						<?php esc_html_e( 'Server', 'robin-image-optimizer' ); ?>: 
						<?php
						if ( 'apache' === $data['server'] ) {
							echo wp_kses_post( "<span style='color:green'>" . $data['server'] . '</span>' );
						} else {
							echo wp_kses_post( "<span style='color:red'>" . $data['server'] . ' (' . __( 'Unsupported', 'robin-image-optimizer' ) . ')</span>' );
						}
						?>
					</p>
				</li>
				<li>
					<label for="wrio-webp-options-radio-picture">
						<input
						type="radio"
						id="wrio-webp-options-radio-picture"
						name="wrio_webp_delivery_mode"
						class="wrio-webp-options-radio"
						value="<?php echo esc_attr( WEBP_Delivery::PICTURE_DELIVERY_MODE ); ?>"
						<?php checked( WEBP_Delivery::PICTURE_DELIVERY_MODE, $data['delivery_mode'] ); ?>
					>
						<?php esc_html_e( 'Replace <img> tags with <picture> tags, adding the Webp/AVIF to srcset.', 'robin-image-optimizer' ); ?>
					</label>
					<p class="wrio-webp-options-info">
						<?php
						echo wp_kses(
							sprintf(
								// translators: %1$s is 'picturefill.js', %2$s and %3$s are opening and closing strong tags.
								__( 'Each &lt;img&gt; will be replaced with a &lt;picture&gt; tag that will also provide the WebP/AVIF image as a choice for browsers that support it. Also loads the %1$s for browsers that don\'t support the &lt;picture&gt; tag. You don\'t need to activate this if you\'re using the Cache Enabler plugin because your WebP/AVIF images are already handled by this plugin.%2$s Please make a test before using this option%3$s, as if the styles that your theme is using rely on the position of your <img> tag, you might experience display problems. %2$sYou can revert anytime to the previous state by just deactivating the option.%3$s', 'robin-image-optimizer' ),
								'picturefill.js',
								'<strong>',
								'</strong>'
							),
							[ 'strong' => [] ]
						);
						?>
					</p>
				</li>
				<li>
					<label for="wrio-webp-options-radio-url">
						<input
							type="radio"
							id="wrio-webp-options-radio-url"
							name="wrio_webp_delivery_mode"
							class="wrio-webp-options-radio"
							value="<?php echo esc_attr( WEBP_Delivery::URL_DELIVERY_MODE ); ?>"
							<?php checked( WEBP_Delivery::URL_DELIVERY_MODE, $data['delivery_mode'] ); ?>
						>
						<?php esc_html_e( 'Replace image URLs', 'robin-image-optimizer' ); ?>
					</label>
					<p class="wrio-webp-options-info">
						<?php echo wp_kses_post( __( '"Image URLs" replaces the image URLs to point to the WebP/AVIF rather than the original. Handles src, srcset, common lazy-load attributes and even inline styles.', 'robin-image-optimizer' ) ); ?>
					</p>
				</li>
			</ul>
		</div>
	</div>
</div>
