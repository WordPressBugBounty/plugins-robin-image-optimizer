<?php

namespace WBCR\Factory_Freemius_Rio_600\Entities;

use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @version 1.0
 */
class Scope extends Entity {

	/**
	 * @var string
	 */
	public $public_key;
	/**
	 * @var string
	 */
	public $secret_key;

	/**
	 * @param bool|stdClass $scope_entity
	 */
	function __construct( $scope_entity = false ) {
		parent::__construct( $scope_entity );
	}
}
