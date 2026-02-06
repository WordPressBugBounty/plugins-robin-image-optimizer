<?php

defined( 'ABSPATH' ) || die( 'Cheatin uh?' );

/**
 * AVIF conversion button template.
 * Premium feature only.
 *
 * @var array $data
 * @var WRIO_Page $page
 */

$cron_running    = WRIO_Plugin::app()->getPopulateOption( 'avif_conversion_cron_running', false );
$process_running = WRIO_Plugin::app()->getPopulateOption( $data['scope'] . '_avif_process_running', false );

if ( ! $cron_running || $cron_running != $data['scope'] ) {
	$cron_running = false;
}

if ( ! $process_running || $process_running != $data['scope'] . '_avif' ) {
	$process_running = false;
}

$button_classes = [
	'wio-optimize-button',
];

$button_name = __( 'Convert to AVIF', 'robin-image-optimizer' );

if ( $cron_running || $process_running ) {
	$button_classes[] = 'wrio-cron-mode wio-running';
	$button_name      = $process_running ? __( 'Stop conversion', 'robin-image-optimizer' ) : __( 'Stop schedule conversion', 'robin-image-optimizer' );
}

?>
<button type="button" id="wrio-start-avif-conversion" data-format="avif" class="<?php echo join( ' ', $button_classes ); ?>">
	<?php echo esc_attr( $button_name ); ?>
</button>
