#!/usr/bin/env php
<?php
/**
 * Asserts that a plugin tree carries one release version everywhere.
 *
 * Every place wpcom_migration_release_locations() lists must carry the
 * version, and the readme changelog must start with a non-empty entry for
 * it.
 *
 * Usage: php bin/check-release.php <plugin-dir> <version>
 *
 * @package wpcom-migration
 */

wpcom_migration_check_release( $argv );

/**
 * Runs the check.
 *
 * @param string[] $args Command-line arguments.
 */
function wpcom_migration_check_release( $args ) {
	require_once __DIR__ . '/release-lib.php';

	if ( 3 !== count( $args ) ) {
		wpcom_migration_release_fail( 'Usage: php bin/check-release.php <plugin-dir> <version>' );
	}
	list( , $dir, $version ) = $args;

	$dir = rtrim( $dir, '/' );

	if ( ! wpcom_migration_release_is_version( $version ) ) {
		wpcom_migration_release_fail( sprintf( 'Not an X.Y version: %s', $version ) );
	}

	$errors = array();
	foreach ( wpcom_migration_release_locations() as $location ) {
		$match = wpcom_migration_release_match( wpcom_migration_release_read( $dir . '/' . $location['file'] ), $location );
		if ( $match[2] !== $version ) {
			$errors[] = sprintf( '%s: "%s" should carry %s.', $location['file'], trim( $match[0] ), $version );
		}
	}

	$entries = wpcom_migration_release_parse_changelog( wpcom_migration_release_read( $dir . '/readme.txt' ) )['entries'];
	if ( array() === $entries ) {
		$errors[] = 'readme.txt: the changelog has no entries.';
	} elseif ( $entries[0]['version'] !== $version ) {
		$errors[] = sprintf( 'readme.txt: the first changelog entry is %s, expected %s.', $entries[0]['version'], $version );
	} elseif ( '' === trim( $entries[0]['body'] ) ) {
		$errors[] = sprintf( 'readme.txt: the %s changelog entry is empty.', $version );
	}

	if ( array() !== $errors ) {
		wpcom_migration_release_fail( implode( "\n", $errors ) );
	}

	fwrite( STDOUT, sprintf( "Release check passed: %s\n", $version ) );
}
