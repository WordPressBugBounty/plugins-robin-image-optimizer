<?php
namespace WBCR\Factory_Freemius_Rio_600\Sdk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WBCR\Factory_Freemius_Rio_600\Sdk\Freemius_InvalidArgumentException' ) ) {
	exit;
}

if ( ! class_exists( 'WBCR\Factory_Freemius_Rio_600\Sdk\Freemius_EmptyArgumentException' ) ) {
	class Freemius_EmptyArgumentException extends Freemius_InvalidArgumentException {

	}
}
