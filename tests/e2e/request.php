<?php
/**
 * Sends signed and unsigned requests to a Playground site running the built
 * plugin and checks the export endpoint's answers for one credential state.
 *
 * Usage: php tests/e2e/request.php <base-url> <built-plugin-dir> <scenario>
 *
 * Scenarios: open, closed, secret-hash-deleted, enabled-hash-deleted, screen,
 * provisioning-hmac, provisioning-key, connection, menu. The secret must match the one the matching
 * blueprint stores.
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

$wpcom_migration_server_src = $wpcom_migration_plugin_dir . '/vendor/wp-php-toolkit/reprint-server/src/';
if ( ! is_file( $wpcom_migration_server_src . 'class-hmac-client.php' ) ) {
	wpcom_migration_e2e_fail( 'Not a built plugin tree (no HMAC client): ' . $wpcom_migration_plugin_dir );
}
foreach ( array( 'class-envelope-signer.php', 'class-utils.php', 'class-hmac-client.php', 'class-public-key-client.php' ) as $wpcom_migration_server_file ) {
	require_once $wpcom_migration_server_src . $wpcom_migration_server_file;
}
require_once __DIR__ . '/provisioning-steps.php';

switch ( $wpcom_migration_scenario ) {
	case 'open':
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		break;

	case 'screen':
		// The screen blueprint ends with the window open (secret valid,
		// enabled); the same signed-preflight assertions prove the screen's
		// form handlers left the exporter in a working state.
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		break;

	case 'menu':
		// The blueprint's runPHP steps are the whole test; there is nothing
		// to ask the site over HTTP.
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

	case 'provisioning-hmac':
		// On a host without OpenSSL, WordPress.com installs the plugin through
		// core, then provisions the exporter with a shared secret and an
		// application password. All over HTTP with basic auth, as
		// WordPress.com sends it.
		$rotate_url = $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/rotate-export-secret';
		$enable_url = $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/enable-export';

		// The routes exist.
		$response = wpcom_migration_e2e_request( $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1', array() );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		foreach ( array( '/wpcom-migration/v1/reprint/rotate-export-secret', '/wpcom-migration/v1/reprint/install-public-key', '/wpcom-migration/v1/reprint/enable-export' ) as $route ) {
			if ( ! isset( $json['routes'][ $route ] ) ) {
				wpcom_migration_e2e_fail( "Namespace index lacks $route: " . $response['body'] );
			}
		}

		// Nobody: 401. An editor: 403.
		$response = wpcom_migration_e2e_request( $rotate_url, array(), 'POST' );
		wpcom_migration_e2e_expect_status( $response, 401 );
		wpcom_migration_e2e_expect_rest_error( $response, 'rest_forbidden' );

		$response = wpcom_migration_e2e_request( $rotate_url, wpcom_migration_e2e_basic_auth_headers( 'e2e-editor', WPCOM_MIGRATION_E2E_EDITOR_APP_PASSWORD ), 'POST' );
		wpcom_migration_e2e_expect_status( $response, 403 );
		wpcom_migration_e2e_expect_rest_error( $response, 'rest_forbidden' );

		$admin_headers = wpcom_migration_e2e_basic_auth_headers( 'admin', WPCOM_MIGRATION_E2E_ADMIN_APP_PASSWORD );

		// No OpenSSL, so no key could ever be verified.
		$response = wpcom_migration_e2e_request( $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/install-public-key', $admin_headers, 'POST', array( 'public_key' => 'irrelevant' ) );
		wpcom_migration_e2e_expect_status( $response, 501 );
		wpcom_migration_e2e_expect_rest_error( $response, 'wpcom_migration_key_auth_unsupported' );

		// No secret stored yet: enable refuses rather than open a window
		// nothing can ever answer.
		$response = wpcom_migration_e2e_request( $enable_url, $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 409 );
		wpcom_migration_e2e_expect_rest_error( $response, 'wpcom_migration_no_credential' );

		// An administrator rotates: a fresh 64-hex secret and the export URL.
		$response = wpcom_migration_e2e_request( $rotate_url, $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		if ( ! isset( $json['secret'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $json['secret'] ) ) {
			wpcom_migration_e2e_fail( 'Rotate did not return a 64-hex secret: ' . $response['body'] );
		}
		if ( ! isset( $json['export_url'] ) || $wpcom_migration_base_url . '/?reprint-api-wpcom-migration' !== $json['export_url'] ) {
			wpcom_migration_e2e_fail( 'Rotate returned an unexpected export_url: ' . $response['body'] );
		}
		$rotated_secret = $json['secret'];

		// Secret stored, window still closed: a signed request gets 409.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $rotated_secret ) );
		wpcom_migration_e2e_expect_status( $response, 409 );
		wpcom_migration_e2e_expect_error_json( $response, 409 );

		// Enable: the window opens.
		$response = wpcom_migration_e2e_request( $enable_url, $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		if ( ! isset( $json['enabled_at'] ) || ! is_int( $json['enabled_at'] ) || $json['enabled_at'] < time() - 60 ) {
			wpcom_migration_e2e_fail( 'Enable did not return a fresh enabled_at: ' . $response['body'] );
		}
		if ( ! isset( $json['export_url'] ) ) {
			wpcom_migration_e2e_fail( 'Enable must also carry export_url: ' . $response['body'] );
		}

		// The secret WordPress.com received serves a real export.
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $rotated_secret ) );

		// Rotating again retires the old secret: a request signed with it is
		// refused, while the new one still opens the window.
		$response = wpcom_migration_e2e_request( $rotate_url, $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		if ( ! isset( $json['secret'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $json['secret'] ) ) {
			wpcom_migration_e2e_fail( 'Second rotate did not return a 64-hex secret: ' . $response['body'] );
		}
		$new_secret = $json['secret'];

		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $rotated_secret ) );
		wpcom_migration_e2e_expect_status( $response, 403 );
		wpcom_migration_e2e_expect_error_json( $response, 403 );

		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $new_secret ) );
		break;

	case 'provisioning-key':
		// WordPress.com provisions a site that verifies keys: it installs its
		// public key, opens the window, and signs every export request with
		// the private half.
		$install_url = $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/install-public-key';
		$enable_url  = $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/enable-export';

		list( $private_key, $public_key ) = WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
		$key_client                       = new WordPress\Reprint\Server\PublicKeyClient( $private_key );

		// The route exists.
		$response = wpcom_migration_e2e_request( $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1', array() );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		if ( ! isset( $json['routes']['/wpcom-migration/v1/reprint/install-public-key'] ) ) {
			wpcom_migration_e2e_fail( 'Namespace index lacks install-public-key: ' . $response['body'] );
		}

		// Nobody: 401. An editor: 403.
		$response = wpcom_migration_e2e_request( $install_url, array(), 'POST', array( 'public_key' => $public_key ) );
		wpcom_migration_e2e_expect_status( $response, 401 );
		wpcom_migration_e2e_expect_rest_error( $response, 'rest_forbidden' );

		$response = wpcom_migration_e2e_request( $install_url, wpcom_migration_e2e_basic_auth_headers( 'e2e-editor', WPCOM_MIGRATION_E2E_EDITOR_APP_PASSWORD ), 'POST', array( 'public_key' => $public_key ) );
		wpcom_migration_e2e_expect_status( $response, 403 );
		wpcom_migration_e2e_expect_rest_error( $response, 'rest_forbidden' );

		$admin_headers = wpcom_migration_e2e_basic_auth_headers( 'admin', WPCOM_MIGRATION_E2E_ADMIN_APP_PASSWORD );

		// This host verifies keys, so a secret would never be accepted.
		$response = wpcom_migration_e2e_request( $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/rotate-export-secret', $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 501 );
		wpcom_migration_e2e_expect_rest_error( $response, 'wpcom_migration_secret_auth_unsupported' );

		// Nothing stored yet: enable refuses.
		$response = wpcom_migration_e2e_request( $enable_url, $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 409 );
		wpcom_migration_e2e_expect_rest_error( $response, 'wpcom_migration_no_credential' );

		// Not a key, a private key, and a key under 3072 bits: each refused
		// with Reprint's reason.
		$weak_key = openssl_pkey_get_details(
			openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			)
		)['key'];
		foreach ( array( 'not a key', $private_key, $weak_key ) as $bad_key ) {
			$response = wpcom_migration_e2e_request( $install_url, $admin_headers, 'POST', array( 'public_key' => $bad_key ) );
			wpcom_migration_e2e_expect_status( $response, 400 );
			wpcom_migration_e2e_expect_rest_error( $response, 'wpcom_migration_invalid_public_key' );
		}

		// An administrator installs the key: the id the client computes, and
		// the export URL.
		$response = wpcom_migration_e2e_request( $install_url, $admin_headers, 'POST', array( 'public_key' => $public_key ) );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		if ( ! isset( $json['key_id'] ) || $key_client->get_key_id() !== $json['key_id'] ) {
			wpcom_migration_e2e_fail( 'Install returned the wrong key_id (want ' . $key_client->get_key_id() . '): ' . $response['body'] );
		}
		if ( ! isset( $json['export_url'] ) || $wpcom_migration_base_url . '/?reprint-api-wpcom-migration' !== $json['export_url'] ) {
			wpcom_migration_e2e_fail( 'Install returned an unexpected export_url: ' . $response['body'] );
		}

		// Key stored, window still closed: a key-signed request gets 409.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_key_signed_headers( $key_client, $wpcom_migration_endpoint ) );
		wpcom_migration_e2e_expect_status( $response, 409 );
		wpcom_migration_e2e_expect_error_json( $response, 409 );

		// Enable, and the key serves a real export.
		$response = wpcom_migration_e2e_request( $enable_url, $admin_headers, 'POST' );
		wpcom_migration_e2e_expect_status( $response, 200 );
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_key_signed_headers( $key_client, $wpcom_migration_endpoint ) );

		// Installing another key, sent as PEM, retires the first.
		list( $second_private_key, $second_public_key ) = WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
		$second_client                                  = new WordPress\Reprint\Server\PublicKeyClient( $second_private_key );

		$response = wpcom_migration_e2e_request( $install_url, $admin_headers, 'POST', array( 'public_key' => WordPress\Reprint\Server\Utils::public_key_to_pem( $second_public_key ) ) );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		if ( ! isset( $json['key_id'] ) || $second_client->get_key_id() !== $json['key_id'] ) {
			wpcom_migration_e2e_fail( 'A PEM install returned the wrong key_id (want ' . $second_client->get_key_id() . '): ' . $response['body'] );
		}

		// The retired key is unknown now, and says so in the code the client
		// maps to its message.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_key_signed_headers( $key_client, $wpcom_migration_endpoint ) );
		wpcom_migration_e2e_expect_status( $response, 403 );
		wpcom_migration_e2e_expect_error_json( $response, 403, 'unknown_key' );

		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_key_signed_headers( $second_client, $wpcom_migration_endpoint ) );

		// A secret-signed request on a site with only a key: no secret is
		// configured, which the client reads from a 503 not_configured.
		$response = wpcom_migration_e2e_request( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		wpcom_migration_e2e_expect_status( $response, 503 );
		wpcom_migration_e2e_expect_error_json( $response, 503, 'not_configured' );
		break;

	case 'connection':
		// Over HTTP, unsigned: the routes exist and refuse.
		$response = wpcom_migration_e2e_request( $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1', array() );
		wpcom_migration_e2e_expect_status( $response, 200 );
		$json = wpcom_migration_e2e_expect_json( $response );
		foreach ( array( '/wpcom-migration/v1/reprint/rotate-export-secret', '/wpcom-migration/v1/reprint/install-public-key', '/wpcom-migration/v1/reprint/enable-export' ) as $route ) {
			if ( ! isset( $json['routes'][ $route ] ) ) {
				wpcom_migration_e2e_fail( "Namespace index lacks $route: " . $response['body'] );
			}
		}

		$response = wpcom_migration_e2e_request( $wpcom_migration_base_url . '/wp-json/wpcom-migration/v1/reprint/rotate-export-secret', array(), 'POST' );
		wpcom_migration_e2e_expect_status( $response, 401 );
		wpcom_migration_e2e_expect_rest_error( $response, 'rest_forbidden' );

		// The window the REST route opened serves a real export.
		wpcom_migration_e2e_assert_open( $wpcom_migration_endpoint, wpcom_migration_e2e_signed_headers( $wpcom_migration_secret ) );
		break;

	default:
		wpcom_migration_e2e_fail( 'Unknown scenario: ' . $wpcom_migration_scenario );
}

fwrite( STDOUT, "Scenario '$wpcom_migration_scenario' passed.\n" );

/**
 * Asserts a signed preflight request answers as the window being open.
 *
 * @param string   $url     Preflight endpoint URL.
 * @param string[] $headers Signature header lines for $url.
 */
function wpcom_migration_e2e_assert_open( $url, array $headers ) {
	$response = wpcom_migration_e2e_request( $url, $headers );
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
 * X-Auth-* header lines for a GET signed with a private key.
 *
 * @param WordPress\Reprint\Server\PublicKeyClient $client The signing client.
 * @param string                                   $url    The request URL; its path and query are signed.
 * @return string[]
 */
function wpcom_migration_e2e_key_signed_headers( $client, $url ) {
	$lines = array();
	foreach ( $client->get_auth_headers( 'GET', $url ) as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}
	return $lines;
}

/**
 * A basic-auth header line for an application password, as WordPress.com
 * sends it.
 *
 * @param string $user_login The user.
 * @param string $password   The application password.
 * @return string[]
 */
function wpcom_migration_e2e_basic_auth_headers( $user_login, $password ) {
	return array( 'Authorization: Basic ' . base64_encode( $user_login . ':' . $password ) );
}

/**
 * Fails unless the body is a WordPress REST error with the given code.
 *
 * @param array  $response Response from wpcom_migration_e2e_request().
 * @param string $code     Expected 'code' value.
 */
function wpcom_migration_e2e_expect_rest_error( array $response, $code ) {
	$json = wpcom_migration_e2e_expect_json( $response );
	if ( ! isset( $json['code'] ) || $code !== $json['code'] ) {
		wpcom_migration_e2e_fail( sprintf( 'Expected REST error %s, got: %s', $code, $response['body'] ) );
	}
}

/**
 * Sends a request.
 *
 * @param string     $url       Request URL.
 * @param string[]   $headers   Header lines.
 * @param string     $method    HTTP method.
 * @param array|null $json_body Body to send as JSON, or null for none.
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function wpcom_migration_e2e_request( $url, array $headers, $method = 'GET', $json_body = null ) {
	$http = array(
		'method'        => $method,
		'ignore_errors' => true,
		'timeout'       => 120,
	);
	if ( null !== $json_body ) {
		$headers[]       = 'Content-Type: application/json';
		$http['content'] = json_encode( $json_body );
	}
	$http['header'] = implode( "\r\n", $headers );

	$context = stream_context_create( array( 'http' => $http ) );

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
 * Fails unless the body is the exporter's error JSON with the given code,
 * and the given reason when one is named.
 *
 * @param array       $response Response from wpcom_migration_e2e_request().
 * @param int         $code     Expected 'code' value.
 * @param string|null $reason   Expected 'reason' value, or null to skip the check.
 */
function wpcom_migration_e2e_expect_error_json( array $response, $code, $reason = null ) {
	$json = wpcom_migration_e2e_expect_json( $response );
	if ( ! isset( $json['code'], $json['error'] ) || $code !== (int) $json['code'] ) {
		wpcom_migration_e2e_fail( sprintf( 'Expected error JSON with code %d, got: %s', $code, $response['body'] ) );
	}
	if ( null !== $reason && ( ! isset( $json['reason'] ) || $reason !== $json['reason'] ) ) {
		wpcom_migration_e2e_fail( sprintf( 'Expected error JSON with reason %s, got: %s', $reason, $response['body'] ) );
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
