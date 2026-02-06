<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Инструменты для оптмизации изображений
 *
 * @version       1.0
 */
class WIO_OptimizationTools {

	/**
	 * Конфигурация серверов и соответствующих классов
	 */
	private static $processors = [
		'server_2' => [
			'file'  => '/includes/classes/processors/class-rio-server-robin.php',
			'class' => 'WIO_Image_Processor_Robin',
		],
		'server_5' => [
			'file'  => '/includes/classes/processors/class-rio-server-premium.php',
			'class' => 'WIO_Image_Processor_Premium',
		],
	];

	/**
	 * Возвращает объект, отвечающий за оптимизацию изображений через API сторонних сервисов
	 *
	 * @param string|null $name
	 * @return WIO_Image_Processor_Abstract
	 */
	public static function getImageProcessor( $name = null ) {
		// Auto-detect processor based on license status if not explicitly specified
		if ( null === $name ) {
			$server = wrio_is_license_activate() ? 'server_5' : 'server_2';
		} else {
			$server = $name;
		}

		$processor = self::$processors[ $server ] ?? self::$processors['server_2'];

		require_once WRIO_PLUGIN_DIR . $processor['file'];

		return new $processor['class']();
	}
}
