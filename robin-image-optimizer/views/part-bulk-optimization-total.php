<?php

defined( 'ABSPATH' ) || die( 'Cheatin’ uh?' );

/**
 * @var array $data
 * @var WRIO_Page $page
 */

$s = '';
?>

<div class="wio-stat-totals">
	<?php _e('Total found:', 'robin-image-optimizer'); ?> <span id="wio-stat-totals__totals" class="wio-stat-totals__counter wio-stat-totals__loading">0</span>
	<?php _e('Originals:', 'robin-image-optimizer'); ?> <span id="wio-stat-totals__originals" class="wio-stat-totals__counter wio-stat-totals__loading">0</span>
	<?php _e('Thumbnails:', 'robin-image-optimizer'); ?> <span id="wio-stat-totals__thumbnails" class="wio-stat-totals__counter wio-stat-totals__loading">0</span>
</div>