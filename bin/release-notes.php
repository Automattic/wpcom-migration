#!/usr/bin/env php
<?php
/**
 * Prints one version's changelog entry from readme.txt.
 *
 * Usage: php bin/release-notes.php <readme> <version>
 *
 * @package wpcom-migration
 */

wpcom_migration_release_notes( $argv );

/**
 * Prints the entry, or fails.
 *
 * @param string[] $args Command-line arguments.
 */
function wpcom_migration_release_notes( $args ) {
	require_once __DIR__ . '/release-lib.php';

	if ( 3 !== count( $args ) ) {
		wpcom_migration_release_fail( 'Usage: php bin/release-notes.php <readme> <version>' );
	}
	list( , $readme, $version ) = $args;

	foreach ( wpcom_migration_release_parse_changelog( wpcom_migration_release_read( $readme ) )['entries'] as $entry ) {
		if ( $entry['version'] === $version ) {
			fwrite( STDOUT, trim( $entry['body'] ) . "\n" );
			return;
		}
	}

	wpcom_migration_release_fail( sprintf( 'readme.txt has no changelog entry for %s.', $version ) );
}
