#!/usr/bin/env php
<?php
/**
 * Asserts what the built plugin publishes through the Jetpack autoloader.
 *
 * The Jetpack autoloader is not scoped to this plugin: it folds every
 * installed package's classmap into manifests that arbitrate class names
 * across every plugin on the site. Whatever reprint-server declares, this
 * plugin publishes site-wide, so the list is asserted rather than counted.
 *
 * Usage: php bin/check-autoload-manifest.php <built-plugin-root>
 *
 * @package wpcom-migration
 */

$wpcom_migration_package_dir = 'vendor/wp-php-toolkit/reprint-server/';

$wpcom_migration_expected_classes = array(
	'Site_Export_HMAC_Client',
	'WordPress\\Reprint\\Server\\DatabaseRowsReader',
	'WordPress\\Reprint\\Server\\FileIndexProcessor',
	'WordPress\\Reprint\\Server\\FileTreeProducer',
	'WordPress\\Reprint\\Server\\GzipOutputStream',
	'WordPress\\Reprint\\Server\\HMACServer',
	'WordPress\\Reprint\\Server\\HTTPServer',
	'WordPress\\Reprint\\Server\\MultipartProcessor',
	'WordPress\\Reprint\\Server\\MySQLDumpProducer',
	'WordPress\\Reprint\\Server\\PdoConstants',
	'WordPress\\Reprint\\Server\\PushConfigurationException',
	'WordPress\\Reprint\\Server\\PushEndpoints',
	'WordPress\\Reprint\\Server\\PushException',
	'WordPress\\Reprint\\Server\\PushSession',
	'WordPress\\Reprint\\Server\\ResourceBudget',
	'WordPress\\Reprint\\Server\\SqliteDriverPDO',
	'WordPress\\Reprint\\Server\\SqliteDriverPDOStatement',
	'WordPress\\Reprint\\Server\\WpdbDriverPDO',
	'WordPress\\Reprint\\Server\\WpdbDriverPDOStatement',
);

$wpcom_migration_own_classes = array(
	'Automattic\\WPCOM_Migration\\Connection',
	'Automattic\\WPCOM_Migration\\REST_Controller',
	'Automattic\\WPCOM_Migration\\Reprint\\Exporter',
	'Automattic\\WPCOM_Migration\\Reprint\\Settings_Page',
);

// Every vendor package that may publish classes through the site-wide
// manifests. A package Composer pulls in that is not listed here is a
// decision to make, not a side effect of a version bump.
$wpcom_migration_expected_packages = array(
	'automattic/jetpack-a8c-mc-stats',
	'automattic/jetpack-admin-ui',
	'automattic/jetpack-assets',
	'automattic/jetpack-config',
	'automattic/jetpack-connection',
	'automattic/jetpack-constants',
	'automattic/jetpack-ip',
	'automattic/jetpack-redirect',
	'automattic/jetpack-roles',
	'automattic/jetpack-status',
	'wp-php-toolkit/reprint-server',
);

// The `files` autoload entries the packages declare; each is loaded on every
// request through jetpack_autoload_filemap.php.
$wpcom_migration_expected_files = array(
	'vendor/automattic/jetpack-assets/actions.php',
	'vendor/automattic/jetpack-connection/actions.php',
);

if ( 2 !== $argc ) {
	wpcom_migration_fail( 'Usage: php bin/check-autoload-manifest.php <built-plugin-root>' );
}

$wpcom_migration_plugin_root = realpath( $argv[1] );
if ( false === $wpcom_migration_plugin_root || ! is_dir( $wpcom_migration_plugin_root ) ) {
	wpcom_migration_fail( sprintf( 'The built plugin root does not exist: %s', $argv[1] ) );
}
$wpcom_migration_plugin_root .= '/';

$wpcom_migration_classmap = wpcom_migration_manifest( $wpcom_migration_plugin_root, 'jetpack_autoload_classmap.php', true );
$wpcom_migration_psr4     = wpcom_migration_manifest( $wpcom_migration_plugin_root, 'jetpack_autoload_psr4.php', false );

$wpcom_migration_errors = array();

// The package publishes exactly the classes we expect. Selected by path
// rather than by name so a class the package starts declaring under some
// other name is caught too.
$wpcom_migration_found = array();
foreach ( $wpcom_migration_classmap as $class => $data ) {
	if ( false !== strpos( $data['path'], $wpcom_migration_package_dir ) ) {
		$wpcom_migration_found[ $class ] = $data['path'];
	}
}

$wpcom_migration_found_names = array_keys( $wpcom_migration_found );
sort( $wpcom_migration_found_names );
sort( $wpcom_migration_expected_classes );

foreach ( array_diff( $wpcom_migration_expected_classes, $wpcom_migration_found_names ) as $missing ) {
	$wpcom_migration_errors[] = sprintf( 'Expected class is not in the classmap: %s', $missing );
}
foreach ( array_diff( $wpcom_migration_found_names, $wpcom_migration_expected_classes ) as $extra ) {
	$wpcom_migration_errors[] = sprintf( 'Unexpected class published from reprint-server: %s (widen the list on purpose, or drop it)', $extra );
}

// No fake PDO classes: PDO is a capability probe for co-resident plugins.
foreach ( array( 'PDO', 'PDOStatement', 'PDOException' ) as $pdo_class ) {
	if ( isset( $wpcom_migration_classmap[ $pdo_class ] ) ) {
		$wpcom_migration_errors[] = sprintf( 'The classmap must not publish a %s polyfill.', $pdo_class );
	}
}

// Every reprint entry resolves to a file that shipped. The autoloader ends in
// a bare require with no file_exists() check, so a missing file is a fatal on
// a manifest the whole site reads.
foreach ( $wpcom_migration_found as $class => $path ) {
	if ( ! is_file( $path ) ) {
		$wpcom_migration_errors[] = sprintf( '%s maps to a file that does not exist: %s', $class, $path );
	}
}

// The plugin's own glue is published and shipped.
foreach ( $wpcom_migration_own_classes as $class ) {
	if ( ! isset( $wpcom_migration_classmap[ $class ] ) ) {
		$wpcom_migration_errors[] = sprintf( 'Own class is not in the classmap: %s', $class );
	} elseif ( ! is_file( $wpcom_migration_classmap[ $class ]['path'] ) ) {
		$wpcom_migration_errors[] = sprintf( '%s maps to a file that does not exist: %s', $class, $wpcom_migration_classmap[ $class ]['path'] );
	}
}

// The package declares no psr-4 namespace of its own. The manifest still
// exists and is non-empty, because the Jetpack autoloader always writes an
// entry for its own namespace; the assertion is that none of its entries
// point into reprint-server.
foreach ( $wpcom_migration_psr4 as $namespace => $data ) {
	foreach ( (array) $data['path'] as $path ) {
		if ( false !== strpos( $path, $wpcom_migration_package_dir ) ) {
			$wpcom_migration_errors[] = sprintf( 'reprint-server must not add a psr-4 entry: %s', $namespace );
		}
	}
}

// Every classmap entry under vendor/ belongs to a listed package, and every
// listed package publishes at least one class.
$wpcom_migration_packages_seen = array();
foreach ( $wpcom_migration_classmap as $class => $data ) {
	if ( ! preg_match( '#(?:^|/)vendor/([^/]+/[^/]+)/#', $data['path'], $match ) ) {
		continue;
	}
	$wpcom_migration_packages_seen[ $match[1] ] = true;
	if ( 'automattic/jetpack-autoloader' === $match[1] ) {
		continue; // The autoloader's own classes are its business.
	}
	if ( ! in_array( $match[1], $wpcom_migration_expected_packages, true ) ) {
		$wpcom_migration_errors[] = sprintf( 'Unexpected package publishes %s: %s (list it on purpose, or drop the dependency)', $class, $match[1] );
	}
}
foreach ( $wpcom_migration_expected_packages as $package ) {
	if ( ! isset( $wpcom_migration_packages_seen[ $package ] ) ) {
		$wpcom_migration_errors[] = sprintf( 'Expected package publishes no classes: %s', $package );
	}
}

// The filemap is exactly the two actions.php files, and each shipped.
$wpcom_migration_filemap = wpcom_migration_manifest( $wpcom_migration_plugin_root, 'jetpack_autoload_filemap.php', true );
$wpcom_migration_files   = array();
foreach ( $wpcom_migration_filemap as $data ) {
	if ( preg_match( '#(vendor/.+)$#', $data['path'], $match ) ) {
		$wpcom_migration_files[] = $match[1];
	}
	if ( ! is_file( $data['path'] ) ) {
		$wpcom_migration_errors[] = sprintf( 'Filemap entry does not exist: %s', $data['path'] );
	}
}
sort( $wpcom_migration_files );
sort( $wpcom_migration_expected_files );
if ( $wpcom_migration_files !== $wpcom_migration_expected_files ) {
	$wpcom_migration_errors[] = 'Filemap differs from the expected list: ' . json_encode( $wpcom_migration_files );
}

if ( array() !== $wpcom_migration_errors ) {
	wpcom_migration_fail( implode( "\n", $wpcom_migration_errors ) );
}

fwrite( STDOUT, sprintf( "Autoload manifest check passed: %d reprint-server classes, %d packages published.\n", count( $wpcom_migration_found ), count( $wpcom_migration_packages_seen ) ) );

/**
 * Loads one of the generated manifests.
 *
 * @param string $plugin_root The built plugin root, with a trailing slash.
 * @param string $name        Manifest file name.
 * @param bool   $required    Whether the manifest must exist.
 * @return array<string, array{version: string, path: string|string[]}>
 */
function wpcom_migration_manifest( $plugin_root, $name, $required ) {
	$file = $plugin_root . 'vendor/composer/' . $name;

	if ( ! is_file( $file ) ) {
		if ( $required ) {
			wpcom_migration_fail( sprintf( '%s is missing. Run composer install in plugin/ first.', $name ) );
		}
		return array();
	}

	return require $file;
}

/**
 * Prints a message to STDERR and exits non-zero.
 *
 * @param string $message What went wrong.
 */
function wpcom_migration_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}
