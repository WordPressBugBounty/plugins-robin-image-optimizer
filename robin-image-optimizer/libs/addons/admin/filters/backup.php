<?php
/**
 * Used to process different filters.
 *
 * @version 1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processing restore back-up of custom folder and Nextgen.
 *
 * @return array {
 *     Processing result.
 * @type int $remane Count of remained images to be processed.
 * @type int $total Count of total processed items.
 * }
 * @since 1.0.4
 */
add_filter(
	'wbcr/rio/backup/restore_filter',
	function ( $limit ) {

		$remane_count = 0;
		$total        = 0;

		$premium_backup = WRIOP_Backup::get_instance();

		if ( wrio_is_active_nextgen_gallery() ) {
			/* NextGen*/
			$nextgen_restored = $premium_backup->restoreAllNextGen( $limit );

			if ( isset( $nextgen_restored['remane'] ) ) {
				$remane_count += $nextgen_restored['remane'];
			}

			// The rows that remane counts, so the progress stays between 0 and 100 %.
			$total += $nextgen_restored['total'];
		}

		/* Custom folders */
		$cf_restored = $premium_backup->restoreAllCustomFolders( $limit );

		if ( isset( $cf_restored['remane'] ) ) {
			$remane_count += $cf_restored['remane'];
		}

		$total += $cf_restored['total'];

		return [
			'remane' => $remane_count,
			'total'  => $total,
		];
	}
);
