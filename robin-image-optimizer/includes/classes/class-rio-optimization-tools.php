<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Инструменты для оптмизации изображений
 *
 * @author        Webcraftic <wordpress.webraftic@gmail.com>
 * @copyright (c) 22.09.2018, Webcraftic
 * @version       1.0
 */
class WIO_OptimizationTools {

	/**
	 * Конфигурация серверов и соответствующих классов
	 */
	private static $processors = [
		'server_1' => [
			'file' => '/includes/classes/processors/class-rio-server-resmush.php',
			'class' => 'WIO_Image_Processor_Resmush'
		],
		'server_2' => [
			'file' => '/includes/classes/processors/class-rio-server-robin.php',
			'class' => 'WIO_Image_Processor_Robin'
		],
		'server_5' => [
			'file' => '/includes/classes/processors/class-rio-server-premium.php',
			'class' => 'WIO_Image_Processor_Premium'
		],
	];

	/**
	 * Возвращает объект, отвечающий за оптимизацию изображений через API сторонних сервисов
	 *
	 * @param string|null $name
	 * @return WIO_Image_Processor_Abstract
	 */
	public static function getImageProcessor( $name = null ) {
		$server = $name ?? WRIO_Plugin::app()->getPopulateOption( 'image_optimization_server', 'server_2' );

		$processor = self::$processors[$server] ?? self::$processors['server_2'];

		require_once WRIO_PLUGIN_DIR . $processor['file'];

		return new $processor['class']();
	}
}
