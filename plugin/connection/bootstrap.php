<?php
/**
 * Loads the WordPress.com connection: package setup, REST routes, screen.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Connection;
use Automattic\WPCOM_Migration\REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpcom_migration_plugin_file           = dirname( __DIR__ ) . '/wpcom_migration.php';
$wpcom_migration_connection_autoloader = dirname( __DIR__ ) . '/vendor/autoload_packages.php';

// A source checkout without `composer install` has no vendor tree. The rest
// of the plugin still works; the connection is simply absent. See README.md.
if ( ! file_exists( $wpcom_migration_connection_autoloader ) ) {
	return;
}

require_once $wpcom_migration_connection_autoloader;

if ( ! defined( 'WPCOM_MIGRATION_SLUG' ) ) {
	// What WordPress.com sees in jetpack_connection_active_plugins and in the
	// authorization request's plugin list.
	define( 'WPCOM_MIGRATION_SLUG', 'wpcom-migration' );
	define( 'WPCOM_MIGRATION_NAME', 'Migrate to WordPress.com' );
	define( 'WPCOM_MIGRATION_URI', 'https://wordpress.com/move/' );
}

Connection::init();

add_action( 'rest_api_init', array( new REST_Controller(), 'register_routes' ) );

// Leaving the plugin leaves the connection; a secret WordPress.com installed
// has no owner without it.
register_deactivation_hook( $wpcom_migration_plugin_file, array( Connection::class, 'disconnect' ) );

unset( $wpcom_migration_plugin_file, $wpcom_migration_connection_autoloader );
