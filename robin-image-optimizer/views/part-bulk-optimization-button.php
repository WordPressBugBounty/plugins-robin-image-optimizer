<?php

defined( 'ABSPATH' ) || die( 'Cheatin’ uh?' );

/**
 * @var array $data
 * @var WRIO_Page $page
 */

$cron_running    = WRIO_Plugin::app()->getPopulateOption( 'cron_running', false );
$process_running = WRIO_Plugin::app()->getPopulateOption( 'process_running', false );

if ( ! $cron_running || $cron_running != $data['scope'] ) {
	$cron_running = false;
}

if ( ! $process_running || $process_running != $data['scope'] ) {
	$process_running = false;
}

$button_classes = [
	'wio-optimize-button',
];

$button_name = '';

if ( $cron_running || $process_running ) {
	$button_classes[] = 'wrio-cron-mode wio-running';
	$button_name      = $process_running ? __( 'Stop background optimization', 'robin-image-optimizer' ) : __( 'Stop schedule optimization', 'robin-image-optimizer' );
} else {
	$button_name = __( 'Start Optimization', 'robin-image-optimizer' );
}

// Get enabled formats for the message
$enabled_formats = [];

// For custom-folders and nextgen, only show "Compression" since format conversion is only for Media Library for the moments.
$show_formats = ! in_array( $data['scope'], [ 'custom-folders', 'nextgen-gallery' ], true );
if ( $show_formats && class_exists( 'WRIO_Format_Converter_Factory' ) ) {
	$enabled_formats = WRIO_Format_Converter_Factory::get_enabled_formats();
}

// Build dynamic message based on enabled formats
$format_message = '';
if ( $show_formats && ! empty( $enabled_formats ) ) {
	$features       = [ __( 'Compression', 'robin-image-optimizer' ) ];
	$features       = array_merge( $features, array_map( 'strtoupper', $enabled_formats ) );
	$format_message = implode( ' • ', $features );
} else {
	$format_message = __( 'Only Compression', 'robin-image-optimizer' );
}

?>
<button type="button" id="wrio-start-optimization" class="<?php echo join( ' ', $button_classes ); ?>">
	<?php echo esc_attr( $button_name ); ?>
</button>
<?php if ( ! empty( $format_message ) ) : ?>
	<p class="wrio-optimization-format-info">
		<?php echo esc_html( $format_message ); ?>
	</p>
<?php endif; ?>