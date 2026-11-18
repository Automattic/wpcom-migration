<?php
/**
 * Sets up the connection package and attaches the WordPress.com connection
 * section to the Reprint migration screen.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Connect_Page;
use Automattic\WPCOM_Migration\Connection;
use Automattic\WPCOM_Migration\Continue_Url;

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

add_filter( 'wpcom_migration_continue_url', array( Continue_Url::class, 'filter' ) );
add_action( 'jetpack_user_authorized', array( Continue_Url::class, 'forget' ) );

// Leaving the plugin leaves the connection, and forgets every user's stored
// destination. Not register_deactivation_hook(): on a network this reads the
// list of sites, so it runs at priority 5, before the exporter's hook at 10
// empties the list.
add_action( 'deactivate_' . plugin_basename( $wpcom_migration_plugin_file ), array( Connection::class, 'leave' ), 5 );

if ( is_admin() ) {
	$wpcom_migration_settings_page = wpcom_migration_settings_page();
	if ( null !== $wpcom_migration_settings_page ) {
		$wpcom_migration_settings_page->set_connection_section( new Connect_Page( $wpcom_migration_plugin_file ) );
	}
}

unset( $wpcom_migration_plugin_file, $wpcom_migration_connection_autoloader, $wpcom_migration_settings_page );
