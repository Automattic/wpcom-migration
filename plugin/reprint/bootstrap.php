<?php
/**
 * Loads the Reprint exporter and its settings screen.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Reprint\Exporter;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpcom_migration_reprint_autoloader = dirname( __DIR__ ) . '/vendor/autoload_packages.php';

// A source checkout without `composer install` has no vendor tree. The rest
// of the plugin still works; the exporter is simply absent. See README.md.
if ( ! file_exists( $wpcom_migration_reprint_autoloader ) ) {
	return;
}

require_once $wpcom_migration_reprint_autoloader;
unset( $wpcom_migration_reprint_autoloader );

Exporter::maybe_init();

if ( is_admin() ) {
	new Settings_Page( dirname( __DIR__ ) . '/wpcom_migration.php' );
}
