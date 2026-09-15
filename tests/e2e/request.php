<?php
/**
 * Sends signed and unsigned requests to a Playground site running the built
 * plugin and checks the export endpoint's answers for one credential state.
 *
 * Usage: php tests/e2e/request.php <base-url> <built-plugin-dir> <scenario>
 *
 * Scenarios: open, closed, secret-hash-deleted, enabled-hash-deleted, screen.
 * The secret must match the one the matching blueprint stores.
 *
 * @package wpcom-migration
 */

if ( 4 !== $argc ) {
	wpcom_migration_e2e_fail( 'Usage: php tests/e2e/request.php <base-url> <built-plugin-dir> <scenario>' );
}

$wpcom_migration_base_url   = rtrim( $argv[1], '/' );
$wpcom_migration_plugin_dir = rtrim( $argv[2], '/' );
$wpcom_migration_scenario   = $argv[3];
$wpcom_migration_secret     = 'smoke-secret-0123456789abcdef0123456789abcdef';
$wpcom_migration_endpoint   = $wpcom_migration_base_url . '/?reprint-api-wpcom-migration&endpoint=preflight';

$wpcom_migration_client_file = $wpcom_migration_plugin_dir . '/vendor/wp-php-toolkit/reprint-server/src/class-hmac-client.php';
if ( ! is_file( $wpcom_migration_client_file ) ) {
	wpcom_migration_e2e_fail( 'Not a built plugin tree (no HMAC client): ' . $wpcom_migration_plugin_dir );
}
require_once $wpcom_migration_client_file;

switch ( $wpcom_migration_scenario ) {
	case 'open':
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, $wpcom_migration_secret );
		break;

	case 'screen':
		// The screen blueprint ends with the window open (secret valid,
		// enabled); the same signed-preflight assertions prove the screen's
		// form handlers left the exporter in a working state.
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, $wpcom_migration_secret );
		break;

	case 'closed':
		// Unsigned with the window closed: the request falls through to
		// WordPress. The plain front page, no JSON, no export headers.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, array() );
		wpcom_migration_e2e_expect_status( $response, 200 );
		if ( false === stripos( $response['body'], '<html' ) ) {
			wpcom_migration_e2e_fail( 'Unsigned request with the window closed did not return the front page: ' . substr( $response['body'], 0, 200 ) );
		}
		if ( isset( $response['headers']['access-control-allow-origin'] ) ) {
			wpcom_migration_e2e_fail( 'Unsigned request with the window closed carried export CORS headers.' );
		}
		if ( isset( $response['headers']['content-type'] ) && false !== stripos( $response['headers']['content-type'], 'json' ) ) {
			wpcom_migration_e2e_fail( 'Unsigned request with the window closed answered JSON.' );
		}

		// Signed with the window closed: 409.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		wpcom_migration_e2e_expect_status( $response, 409 );
		wpcom_migration_e2e_expect_error_json( $response, 409 );
		break;

	case 'secret-hash-deleted':
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		wpcom_migration_e2e_expect_status( $response, 503 );
		wpcom_migration_e2e_expect_error_json( $response, 503 );
		break;

	case 'enabled-hash-deleted':
		// A stamp without its hash reads as closed.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		wpcom_migration_e2e_expect_status( $response, 409 );
		wpcom_migration_e2e_expect_error_json( $response, 409 );
		break;

	default:
		wpcom_migration_e2e_fail( 'Unknown scenario: ' . $wpcom_migration_scenario );
}

fwrite( STDOUT, "Scenario '$wpcom_migration_scenario' passed.\n" );

/**
 * Asserts a signed preflight request answers as the window being open.
 *
 * @param string $url    Preflight endpoint URL.
 * @param string $secret The shared secret.
 */
function wpcom_migration_e2e_assert_open( $url, $secret ) {
	$response = wpcom_migration_e2e_request( $url, wpcom_migration_e2e_signed_headers( $secret ) );
	wpcom_migration_e2e_expect_status( $response, 200 );
	$json = wpcom_migration_e2e_expect_json( $response );
	foreach ( array( 'ok', 'protocol_version', 'php', 'wp_detect' ) as $key ) {
		if ( ! array_key_exists( $key, $json ) ) {
			wpcom_migration_e2e_fail( "Preflight JSON lacks the '$key' key: " . $response['body'] );
		}
	}
	if ( true !== $json['ok'] ) {
		wpcom_migration_e2e_fail(
			sprintf(
				"Preflight reported ok=false. error=%s wp_detect=%s db=%s\nBody: %s",
				isset( $json['error'] ) ? print_r( $json['error'], true ) : '(absent)',
				isset( $json['wp_detect'] ) ? print_r( $json['wp_detect'], true ) : '(absent)',
				isset( $json['db'] ) ? print_r( $json['db'], true ) : '(absent)',
				$response['body']
			)
		);
	}
	wpcom_migration_e2e_expect_header( $response, 'access-control-allow-origin', '*' );
}

/**
 * X-Auth-* header lines for an empty-bodied signed request.
 *
 * @param string $secret The shared secret.
 * @return string[]
 */
function wpcom_migration_e2e_signed_headers( $secret ) {
	$client = new Site_Export_HMAC_Client( $secret );
	$lines  = array();
	foreach ( $client->get_auth_headers( '' ) as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}
	return $lines;
}

/**
 * Sends a GET request.
 *
 * @param string   $url     Request URL.
 * @param string[] $headers Header lines.
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function wpcom_migration_e2e_request( $url, array $headers ) {
	$context = stream_context_create(
		array(
			'http' => array(
				'method'        => 'GET',
				'header'        => implode( "\r\n", $headers ),
				'ignore_errors' => true,
				'timeout'       => 120,
			),
		)
	);

	$body = file_get_contents( $url, false, $context );
	if ( false === $body ) {
		wpcom_migration_e2e_fail( 'Request failed: ' . $url );
	}

	$status         = 0;
	$parsed_headers = array();
	foreach ( $http_response_header as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $match ) ) {
			// A redirect chain repeats the status line; the last one wins.
			$status         = (int) $match[1];
			$parsed_headers = array();
			continue;
		}
		$parts = explode( ':', $line, 2 );
		if ( 2 === count( $parts ) ) {
			$parsed_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
		}
	}

	return array(
		'status'  => $status,
		'headers' => $parsed_headers,
		'body'    => $body,
	);
}

/**
 * Fails unless the response has the given status.
 *
 * @param array $response Response from wpcom_migration_e2e_request().
 * @param int   $expected Expected status code.
 */
function wpcom_migration_e2e_expect_status( array $response, $expected ) {
	if ( $expected !== $response['status'] ) {
		wpcom_migration_e2e_fail( sprintf( 'Expected HTTP %d, got %d: %s', $expected, $response['status'], substr( $response['body'], 0, 300 ) ) );
	}
}

/**
 * Fails unless the response carries the given header value.
 *
 * @param array  $response Response from wpcom_migration_e2e_request().
 * @param string $name     Lower-case header name.
 * @param string $expected Expected value.
 */
function wpcom_migration_e2e_expect_header( array $response, $name, $expected ) {
	if ( ! isset( $response['headers'][ $name ] ) || $expected !== $response['headers'][ $name ] ) {
		wpcom_migration_e2e_fail( sprintf( 'Expected header %s: %s, got: %s', $name, $expected, isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '(absent)' ) );
	}
}

/**
 * Fails unless the body is a JSON object; returns it.
 *
 * @param array $response Response from wpcom_migration_e2e_request().
 * @return array
 */
function wpcom_migration_e2e_expect_json( array $response ) {
	$json = json_decode( $response['body'], true );
	if ( ! is_array( $json ) ) {
		wpcom_migration_e2e_fail( 'Expected a JSON body, got: ' . substr( $response['body'], 0, 300 ) );
	}
	return $json;
}

/**
 * Fails unless the body is the exporter's error JSON with the given code.
 *
 * @param array $response Response from wpcom_migration_e2e_request().
 * @param int   $code     Expected 'code' value.
 */
function wpcom_migration_e2e_expect_error_json( array $response, $code ) {
	$json = wpcom_migration_e2e_expect_json( $response );
	if ( ! isset( $json['code'], $json['error'] ) || $code !== (int) $json['code'] ) {
		wpcom_migration_e2e_fail( sprintf( 'Expected error JSON with code %d, got: %s', $code, $response['body'] ) );
	}
}

/**
 * Prints a message to STDERR and exits non-zero.
 *
 * @param string $message What went wrong.
 */
function wpcom_migration_e2e_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}
