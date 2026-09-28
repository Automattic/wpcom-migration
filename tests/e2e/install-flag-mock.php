<?php
/**
 * Installs flag-mock.php as a must-use plugin. Every blueprint runs this
 * first, so no request of any scenario reaches public-api.wordpress.com.
 *
 * @package wpcom-migration
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Runs before WordPress loads.
$wpcom_migration_mu_dir = '/wordpress/wp-content/mu-plugins';
if ( ! is_dir( $wpcom_migration_mu_dir ) && ! mkdir( $wpcom_migration_mu_dir, 0777, true ) ) {
	file_put_contents( 'php://stderr', "Could not create $wpcom_migration_mu_dir\n" );
	exit( 1 );
}
file_put_contents(
	$wpcom_migration_mu_dir . '/wpcom-migration-e2e-flag.php',
	"<?php require '/wordpress/wp-content/wpcom-migration-e2e/flag-mock.php';\n"
);
// phpcs:enable WordPress.WP.AlternativeFunctions
