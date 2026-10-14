<?php
/**
 * Shared by the release scripts: where the plugin carries its version, and
 * how the readme changelog is read and written.
 *
 * @package wpcom-migration
 */

/**
 * The places in the plugin tree that carry the release version.
 *
 * Each pattern matches one whole line and captures three groups: the text
 * before the version, the version, and the text after it.
 *
 * @return array<int, array{file: string, pattern: string}>
 */
function wpcom_migration_release_locations() {
	return array(
		array(
			'file'    => 'wpcom_migration.php',
			'pattern' => '/^(Version: )([^\r\n]*)()$/m',
		),
		array(
			'file'    => 'info.php',
			'pattern' => '/^(\t+public \$version = \')([^\']*)(\';)$/m',
		),
		array(
			'file'    => 'readme.txt',
			'pattern' => '/^(Stable tag: )([^\r\n]*)()$/m',
		),
	);
}

/**
 * Whether a string is a release version: two numbers joined by a dot.
 *
 * @param string $version Candidate version.
 * @return bool
 */
function wpcom_migration_release_is_version( $version ) {
	return 1 === preg_match( '/^[0-9]+\.[0-9]+$/', $version );
}

/**
 * Finds the one line a location describes, or fails.
 *
 * @param string                               $contents Contents of the location's file.
 * @param array{file: string, pattern: string} $location One entry of wpcom_migration_release_locations().
 * @return string[] The whole line, the text before the version, the version, and the text after it.
 */
function wpcom_migration_release_match( $contents, $location ) {
	$count = preg_match_all( $location['pattern'], $contents, $matches, PREG_SET_ORDER );
	if ( 1 !== $count ) {
		wpcom_migration_release_fail( sprintf( '%s: expected exactly one line matching %s, found %d.', $location['file'], $location['pattern'], $count ) );
	}

	return $matches[0];
}

/**
 * Reads a file, or fails.
 *
 * @param string $path File to read.
 * @return string
 */
function wpcom_migration_release_read( $path ) {
	$contents = is_file( $path ) ? file_get_contents( $path ) : false;
	if ( false === $contents ) {
		wpcom_migration_release_fail( sprintf( 'Cannot read %s.', $path ) );
	}

	return $contents;
}

/**
 * Splits readme.txt around its changelog entries.
 *
 * The head runs up to the first entry and includes the "== Changelog =="
 * line. Each entry is its "= X.Y =" heading's version and the lines below
 * it. The tail starts at the next "== Section ==" line.
 *
 * @param string $readme Contents of readme.txt.
 * @return array{head: string, entries: array<int, array{version: string, body: string}>, tail: string}
 */
function wpcom_migration_release_parse_changelog( $readme ) {
	$changelog = array(
		'head'    => '',
		'entries' => array(),
		'tail'    => '',
	);
	$section   = 'head';
	$headings  = 0;

	foreach ( preg_split( '/(?<=\n)/', $readme, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
		$text = rtrim( $line, "\r\n" );

		if ( '== Changelog ==' === $text ) {
			++$headings;
			if ( 'head' === $section ) {
				$section            = 'changelog';
				$changelog['head'] .= $line;
				continue;
			}
		}

		if ( 'changelog' === $section ) {
			if ( preg_match( '/^== .+ ==$/', $text ) ) {
				$section = 'tail';
			} elseif ( preg_match( '/^= (.+) =$/', $text, $match ) ) {
				$changelog['entries'][] = array(
					'version' => $match[1],
					'body'    => '',
				);
				continue;
			} elseif ( array() !== $changelog['entries'] ) {
				$changelog['entries'][ count( $changelog['entries'] ) - 1 ]['body'] .= $line;
				continue;
			}
		}

		if ( 'tail' === $section ) {
			$changelog['tail'] .= $line;
		} else {
			$changelog['head'] .= $line;
		}
	}

	if ( 1 !== $headings ) {
		wpcom_migration_release_fail( sprintf( 'readme.txt: expected exactly one "== Changelog ==" line, found %d.', $headings ) );
	}

	return $changelog;
}

/**
 * Joins what wpcom_migration_release_parse_changelog() split.
 *
 * @param array{head: string, entries: array<int, array{version: string, body: string}>, tail: string} $changelog The parsed readme.
 * @return string
 */
function wpcom_migration_release_render_changelog( $changelog ) {
	$readme = $changelog['head'];
	foreach ( $changelog['entries'] as $entry ) {
		$readme .= '= ' . $entry['version'] . " =\n" . $entry['body'];
	}

	return $readme . $changelog['tail'];
}

/**
 * Prints a message to STDERR and exits non-zero.
 *
 * @param string $message What went wrong.
 */
function wpcom_migration_release_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}
