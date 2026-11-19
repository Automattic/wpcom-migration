<?php
/**
 * Loads the Reprint exporter and its settings screen.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Reprint\Exporter;
use Automattic\WPCOM_Migration\Reprint\Key_Page;
use Automattic\WPCOM_Migration\Reprint\Manual_Page;
use Automattic\WPCOM_Migration\Reprint\REST_Controller;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Versions up to 6.78 cached WordPress.com's answer to which screen is main
// in this option. Updates don't run the activation hook, so the first admin
// load after one deletes it. Once it is gone, get_option() answers from the
// object cache where there is one; delete_option() would always query.
// Remove once no site runs 6.78 or earlier.
add_action(
	'admin_init',
	function () {
		if ( false !== get_option( 'wpcom_migration_main_screen' ) ) {
			delete_option( 'wpcom_migration_main_screen' );
		}
	}
);

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

/**
 * Holds the screen so the connection bootstrap can attach its section.
 *
 * @param Settings_Page|null $page The screen, when setting it.
 * @return Settings_Page|null
 */
function wpcom_migration_settings_page( ?Settings_Page $page = null ) {
	static $stored = null;
	if ( null !== $page ) {
		$stored = $page;
	}
	return $stored;
}

if ( is_admin() ) {
	wpcom_migration_settings_page( new Settings_Page( $wpcom_migration_plugin_file ) );
	new Manual_Page( $wpcom_migration_plugin_file );
	new Key_Page( $wpcom_migration_plugin_file );
}

unset( $wpcom_migration_plugin_file, $wpcom_migration_reprint_autoloader );
