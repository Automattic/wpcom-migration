<?php
/**
 * Loads the Reprint exporter and its settings screen.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Reprint\Exporter;
use Automattic\WPCOM_Migration\Reprint\REST_Controller;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpcom_migration_plugin_file        = dirname( __DIR__ ) . '/wpcom_migration.php';
$wpcom_migration_reprint_autoloader = dirname( __DIR__ ) . '/vendor/autoload_packages.php';

// A source checkout without `composer install` has no vendor tree. The rest
// of the plugin still works, including activation; the exporter is simply
// absent. See README.md.
if ( ! file_exists( $wpcom_migration_reprint_autoloader ) ) {
	return;
}

require_once $wpcom_migration_reprint_autoloader;

Exporter::maybe_init();

add_action( 'rest_api_init', array( new REST_Controller(), 'register_routes' ) );

// Credentials never survive an activation boundary: whatever was written
// while the write veto was not in place is discarded.
register_activation_hook( $wpcom_migration_plugin_file, array( Exporter::class, 'discard_credentials' ) );
register_deactivation_hook( $wpcom_migration_plugin_file, array( Exporter::class, 'discard_credentials' ) );

if ( is_admin() ) {
	new Settings_Page( $wpcom_migration_plugin_file );
}

unset( $wpcom_migration_plugin_file, $wpcom_migration_reprint_autoloader );
