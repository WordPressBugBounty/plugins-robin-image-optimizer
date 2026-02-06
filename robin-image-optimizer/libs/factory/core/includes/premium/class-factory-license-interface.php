<?php

namespace WBCR\Factory_600\Premium\Interfaces;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @version       1.0
 */
interface License {

	public function get_key();

	public function get_hidden_key();

	public function get_expiration_time( $format = 'time' );

	public function get_sites_quota();

	public function get_count_active_sites();

	public function is_valid();

	public function is_lifetime();
}
