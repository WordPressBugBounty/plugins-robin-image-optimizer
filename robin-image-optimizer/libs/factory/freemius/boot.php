<?php
/**
 * Load Freemius module.
 *
 * @since         1.0.0
 * @package       core
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'FACTORY_FREEMIUS_RIO_600_LOADED' ) ) {
	return;
}

define( 'FACTORY_FREEMIUS_RIO_600_VERSION', '1.7.0' );

define( 'FACTORY_FREEMIUS_RIO_600_LOADED', true );
define( 'FACTORY_FREEMIUS_RIO_600_DIR', __DIR__ );
define( 'FACTORY_FREEMIUS_RIO_600_URL', plugins_url( '', __FILE__ ) );

// comp merge
// Freemius
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/entities/class-freemius-entity.php';
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/entities/class-freemius-scope.php';
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/entities/class-freemius-user.php';
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/entities/class-freemius-site.php';
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/entities/class-freemius-license.php';
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/licensing/class-freemius-provider.php';
require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/updates/class-freemius-repository.php';

if ( ! class_exists( 'WBCR\Factory_Freemius_Rio_600\Sdk\Freemius_Api_WordPress' ) ) {
	require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/sdk/FreemiusWordPress.php';
}

require_once FACTORY_FREEMIUS_RIO_600_DIR . '/includes/class-freemius-api.php';

/**
 * Freemius provider registration for robin-image-optimizer (Factory 600)
 *
 * @param Wbcr_Factory600_Plugin $plugin
 */
add_action(
	'wbcr_factory_freemius_rio_600_plugin_created',
	function ( $plugin ) {
		// Устанавливаем класс провайдера лицензий для премиум менеджера
		$plugin->set_license_provider( 'freemius', 'WBCR\Factory_Freemius_Rio_600\Premium\Provider' );
		// Устанавливаем класс репозитория обновлений для менеджера обновлений
		$plugin->set_update_repository( 'freemius', 'WBCR\Factory_Freemius_Rio_600\Updates\Freemius_Repository' );
	}
);
// endcomp
