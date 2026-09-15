#!/usr/bin/env php
<?php
/**
 * Downgrades a staged copy of the plugin to PHP 7.0 syntax with Rector, then
 * checks the result with the build tool's syntax validator.
 *
 * Usage: php bin/downgrade-tree.php <staging-root> <source-root> <relative-path>...
 *
 * The source tree is never rewritten: the script refuses a staging root equal
 * to the source root and any path that resolves inside it.
 *
 * @package wpcom-migration
 */

use WordPress\Reprint\Build\Php56SyntaxValidator;

$wpcom_migration_repo_root = dirname( __DIR__ );
$wpcom_migration_tool_root = $wpcom_migration_repo_root . '/tools/php56-build';
$wpcom_migration_autoload  = $wpcom_migration_tool_root . '/vendor/autoload.php';
$wpcom_migration_rector    = $wpcom_migration_tool_root . '/vendor/bin/rector';

if ( $argc < 4 ) {
	wpcom_migration_fail( 'Usage: php bin/downgrade-tree.php <staging-root> <source-root> <relative-path>...' );
}

if ( ! is_file( $wpcom_migration_autoload ) || ! is_file( $wpcom_migration_rector ) ) {
	wpcom_migration_fail( 'Install the PHP 7.0 build tool with: composer install --working-dir=tools/php56-build' );
}

$wpcom_migration_staging_root = realpath( $argv[1] );
$wpcom_migration_source_root  = realpath( $argv[2] );
if ( false === $wpcom_migration_staging_root || ! is_dir( $wpcom_migration_staging_root ) ) {
	wpcom_migration_fail( sprintf( 'The staging root does not exist: %s', $argv[1] ) );
}
if ( false === $wpcom_migration_source_root || ! is_dir( $wpcom_migration_source_root ) ) {
	wpcom_migration_fail( sprintf( 'The source root does not exist: %s', $argv[2] ) );
}
if ( $wpcom_migration_staging_root === $wpcom_migration_source_root ) {
	wpcom_migration_fail( 'Refusing to downgrade the maintained source tree. Pass a separate staging root.' );
}

$wpcom_migration_paths = array();
foreach ( array_slice( $argv, 3 ) as $relative ) {
	$path = realpath( $wpcom_migration_staging_root . '/' . $relative );
	if ( false === $path ) {
		wpcom_migration_fail( sprintf( 'The staging tree is incomplete. Missing %s.', $relative ) );
	}
	if ( 0 === strpos( $path . '/', $wpcom_migration_source_root . '/' ) ) {
		wpcom_migration_fail( sprintf( 'Refusing to downgrade a path from the maintained source tree: %s.', $path ) );
	}
	$wpcom_migration_paths[] = $path;
}

wpcom_migration_assert_reserved_variables_are_unused( $wpcom_migration_paths );

$wpcom_migration_command = escapeshellarg( $wpcom_migration_rector )
	. ' process '
	. implode( ' ', array_map( 'escapeshellarg', $wpcom_migration_paths ) )
	. ' --config ' . escapeshellarg( $wpcom_migration_tool_root . '/rector-php70.php' )
	. ' --no-progress-bar --no-diffs --clear-cache';
passthru( $wpcom_migration_command, $wpcom_migration_status );
if ( 0 !== $wpcom_migration_status ) {
	wpcom_migration_fail( sprintf( 'The PHP 7.0 Rector downgrade failed with exit code %d.', $wpcom_migration_status ) );
}

require_once $wpcom_migration_autoload;

try {
	( new Php56SyntaxValidator( '7.0' ) )->assertPaths( $wpcom_migration_paths );
} catch ( Throwable $throwable ) {
	wpcom_migration_fail( $throwable->getMessage() );
}

fwrite( STDOUT, "Downgraded tree contains none of the syntax the PHP 7.0 build rejects.\n" );

/**
 * Stops the build if staged code already uses the temporary variable prefix
 * the null-coalescing downgrade introduces.
 *
 * @param string[] $paths Files and directories to inspect.
 */
function wpcom_migration_assert_reserved_variables_are_unused( array $paths ) {
	$prefix = '$__reprint_php56_';
	foreach ( wpcom_migration_php_files( $paths ) as $file ) {
		$code = file_get_contents( $file );
		if ( false === $code ) {
			wpcom_migration_fail( sprintf( 'Could not read staged PHP file %s.', $file ) );
		}
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && T_VARIABLE === $token[0] && 0 === strncmp( $token[1], $prefix, strlen( $prefix ) ) ) {
				wpcom_migration_fail( sprintf( 'Staged PHP uses the reserved downgrade variable %s in %s on line %d.', $token[1], $file, $token[2] ) );
			}
		}
	}
}

/**
 * Lists the PHP files under the given paths.
 *
 * @param string[] $paths Files and directories to inspect.
 * @return string[]
 */
function wpcom_migration_php_files( array $paths ) {
	$files = array();
	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			if ( '.php' === substr( $path, -4 ) ) {
				$files[] = $path;
			}
			continue;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && '.php' === substr( $file->getFilename(), -4 ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	return $files;
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
