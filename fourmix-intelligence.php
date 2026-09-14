<?php
/**
 * Plugin Name:       Fourmix Intelligence AI
 * Plugin URI:        https://github.com/fourmix-intelligence/fourmix-intelligence-for-wordpress
 * Description:       Fourmix Intelligence の AI 接客、サイト内案内、資料同期を WordPress と WooCommerce に統合します。
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Fourmix Co., Ltd.
 * Author URI:        https://fourmix.co.jp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fourmix-intelligence
 */

defined( 'ABSPATH' ) || exit;

define( 'FOURMIX_INTELLIGENCE_VERSION', '1.0.0' );
define( 'FOURMIX_INTELLIGENCE_FILE', __FILE__ );
define( 'FOURMIX_INTELLIGENCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOURMIX_INTELLIGENCE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'FourmixIntelligence\\WordPress\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$path = FOURMIX_INTELLIGENCE_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'FourmixIntelligence\\WordPress\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FourmixIntelligence\\WordPress\\Installer', 'deactivate' ) );
add_action(
	'plugins_loaded',
	static function (): void {
		FourmixIntelligence\WordPress\Plugin::instance()->boot();
	}
);
