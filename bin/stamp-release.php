#!/usr/bin/env php
<?php
/**
 * Writes a release version and its changelog entry into the plugin source.
 *
 * Sets every place wpcom_migration_release_locations() lists and puts the
 * entry at the top of the readme changelog, replacing any entry for the
 * same version. Writes nothing if a place doesn't match exactly once, or if
 * the old version still appears in the tree outside vendor/ and the
 * changelog.
 *
 * Usage: php bin/stamp-release.php <plugin-dir> <version> <changelog-file>
 *
 * @package wpcom-migration
 */

wpcom_migration_stamp_release( $argv );

/**
 * Runs the stamp.
 *
 * @param string[] $args Command-line arguments.
 */
function wpcom_migration_stamp_release( $args ) {
	require_once __DIR__ . '/release-lib.php';

	if ( 4 !== count( $args ) ) {
		wpcom_migration_release_fail( 'Usage: php bin/stamp-release.php <plugin-dir> <version> <changelog-file>' );
	}
	list( , $dir, $version, $changelog_file ) = $args;

	$dir = rtrim( $dir, '/' );

	if ( ! wpcom_migration_release_is_version( $version ) ) {
		wpcom_migration_release_fail( sprintf( 'Not an X.Y version: %s', $version ) );
	}

	$bullets = wpcom_migration_stamp_bullets( wpcom_migration_release_read( $changelog_file ) );

	$files       = array();
	$old_version = null;
	foreach ( wpcom_migration_release_locations() as $location ) {
		$path = $dir . '/' . $location['file'];
		if ( ! isset( $files[ $path ] ) ) {
			$files[ $path ] = wpcom_migration_release_read( $path );
		}
		$match = wpcom_migration_release_match( $files[ $path ], $location );
		if ( null === $old_version ) {
			$old_version = $match[2];
		}
		$files[ $path ] = preg_replace( $location['pattern'], '${1}' . $version . '${3}', $files[ $path ], 1 );
	}

	$readme = $dir . '/readme.txt';
	if ( ! isset( $files[ $readme ] ) ) {
		$files[ $readme ] = wpcom_migration_release_read( $readme );
	}
	$changelog = wpcom_migration_release_parse_changelog( $files[ $readme ] );
	if ( "\n" !== substr( $changelog['head'], -1 ) ) {
		$changelog['head'] .= "\n";
	}
	$changelog['entries'] = array_values(
		array_filter(
			$changelog['entries'],
			static function ( $entry ) use ( $version ) {
				return $entry['version'] !== $version;
			}
		)
	);
	array_unshift(
		$changelog['entries'],
		array(
			'version' => $version,
			'body'    => implode( "\n", $bullets ) . "\n\n",
		)
	);
	$files[ $readme ] = wpcom_migration_release_render_changelog( $changelog );

	if ( $old_version !== $version ) {
		$leftovers = wpcom_migration_stamp_leftovers( $dir, $old_version, $files );
		if ( array() !== $leftovers ) {
			wpcom_migration_release_fail(
				sprintf(
					"The old version %s still appears outside the places bin/release-lib.php lists. Add the place to wpcom_migration_release_locations(), or change the line:\n%s",
					$old_version,
					implode( "\n", $leftovers )
				)
			);
		}
	}

	foreach ( $files as $path => $contents ) {
		if ( false === file_put_contents( $path, $contents ) ) {
			wpcom_migration_release_fail( sprintf( 'Cannot write %s.', $path ) );
		}
	}

	fwrite( STDOUT, sprintf( "Stamped %s (was %s).\n", $version, $old_version ) );
}

/**
 * Splits the changelog file into bullets, or fails.
 *
 * @param string $text Contents of the changelog file.
 * @return string[] One "* ..." line per change.
 */
function wpcom_migration_stamp_bullets( $text ) {
	$lines = preg_split( '/\R/', trim( $text ) );
	if ( array( '' ) === $lines ) {
		wpcom_migration_release_fail( 'The changelog is empty.' );
	}
	foreach ( $lines as $line ) {
		if ( 0 !== strpos( $line, '* ' ) ) {
			wpcom_migration_release_fail( sprintf( 'Not a changelog bullet: %s', $line ) );
		}
	}

	return $lines;
}

/**
 * Lists the lines outside vendor/ and the changelog that still carry a version.
 *
 * @param string                $dir     Plugin directory.
 * @param string                $version The version to look for.
 * @param array<string, string> $pending Contents about to be written, by path.
 * @return string[] One "path:line: text" entry per line found.
 */
function wpcom_migration_stamp_leftovers( $dir, $version, $pending ) {
	$pattern = '/(?<![0-9.])' . preg_quote( $version, '/' ) . '(?![0-9])/';
	$files   = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			static function ( $file ) {
				return 'vendor' !== $file->getFilename();
			}
		)
	);

	$found = array();
	foreach ( $files as $file ) {
		$path     = $file->getPathname();
		$contents = isset( $pending[ $path ] ) ? $pending[ $path ] : file_get_contents( $path );
		if ( false === $contents || false !== strpos( $contents, "\0" ) ) {
			continue; // Unreadable or binary.
		}
		if ( $dir . '/readme.txt' === $path ) {
			$changelog = wpcom_migration_release_parse_changelog( $contents );
			$contents  = $changelog['head'] . $changelog['tail'];
		}
		foreach ( preg_split( '/\R/', $contents ) as $index => $line ) {
			if ( preg_match( $pattern, $line ) ) {
				$found[] = sprintf( '%s:%d: %s', $path, $index + 1, trim( $line ) );
			}
		}
	}

	return $found;
}
