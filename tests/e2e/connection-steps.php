<?php
/**
 * Drives the WordPress.com connection glue inside Playground, one step per
 * runPHP call. WordPress.com is unreachable here, so a connection is planted
 * as Jetpack's own tests do: tokens written straight to Jetpack_Options.
 *
 * Each step throws on a failed expectation; a non-zero PHP exit fails the
 * blueprint step, which fails the boot, and run.sh prints the log.
 *
 * @package wpcom-migration
 */

use Automattic\Jetpack\Connection\Plugin_Storage;
use Automattic\Jetpack\Connection\Rest_Authentication;
use Automattic\WPCOM_Migration\Connect_Page;
use Automattic\WPCOM_Migration\Connection;
use Automattic\WPCOM_Migration\Reprint\Exporter;

// WordPress's fatal handler would swallow the message into a generic error
// page; print it where run.sh shows the log instead.
set_exception_handler(
	function ( $exception ) {
		file_put_contents( 'php://stderr', get_class( $exception ) . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}
);

const WPCOM_MIGRATION_E2E_BLOG_ID    = 424242;
const WPCOM_MIGRATION_E2E_BLOG_TOKEN = 'e2eblogkey.e2eblogsecret';
const WPCOM_MIGRATION_E2E_USER_TOKEN = 'e2euserkey.e2eusersecret.1';

/**
 * Runs one connection step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_connection_step( $step ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';

	wp_set_current_user( 1 );
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'User 1 cannot manage_options.' );
	}

	switch ( $step ) {
		case 'assert-registered-not-connected':
			$plugins = Plugin_Storage::get_all();
			if ( ! is_array( $plugins ) || ! isset( $plugins[ WPCOM_MIGRATION_SLUG ] ) ) {
				throw new RuntimeException( 'The plugin is not registered with the connection package: ' . wp_json_encode( $plugins ) );
			}
			if ( Connection::is_site_connected() || Connection::is_user_connected() ) {
				throw new RuntimeException( 'A fresh site must not read as connected.' );
			}
			if ( null !== Connection::blog_id() || null !== Connection::connected_wpcom_user() ) {
				throw new RuntimeException( 'A fresh site must have no blog ID or user data.' );
			}
			break;

		case 'plant-tokens':
			Jetpack_Options::update_option( 'id', WPCOM_MIGRATION_E2E_BLOG_ID );
			Jetpack_Options::update_option( 'blog_token', WPCOM_MIGRATION_E2E_BLOG_TOKEN );
			Jetpack_Options::update_option( 'master_user', 1 );
			Jetpack_Options::update_option( 'user_tokens', array( 1 => WPCOM_MIGRATION_E2E_USER_TOKEN ) );
			// The package would otherwise ask WordPress.com for this.
			set_transient(
				'jetpack_connected_user_data_1',
				array(
					'login' => 'e2e-tester',
					'email' => 'e2e@example.com',
				),
				DAY_IN_SECONDS
			);
			break;

		case 'assert-connected':
			if ( ! Connection::is_site_connected() ) {
				throw new RuntimeException( 'Planted blog token and ID should read as site-connected.' );
			}
			if ( ! Connection::is_user_connected() ) {
				throw new RuntimeException( 'Planted user token should read as user-connected for user 1.' );
			}
			if ( WPCOM_MIGRATION_E2E_BLOG_ID !== Connection::blog_id() ) {
				throw new RuntimeException( 'blog_id() should return the planted ID, got: ' . var_export( Connection::blog_id(), true ) );
			}
			$user = Connection::connected_wpcom_user();
			if ( ! is_array( $user ) || 'e2e-tester' !== $user['login'] ) {
				throw new RuntimeException( 'connected_wpcom_user() should return the cached login: ' . wp_json_encode( $user ) );
			}
			break;

		case 'calypso-retry-without-user-token':
			// Calypso re-enters the flow through a URL it hardcodes
			// (admin.php?page=jetpack&connect_url_redirect=true) when an
			// authorization attempt fails. Site connected, user not.
			Jetpack_Options::delete_option( 'user_tokens' );
			Jetpack_Options::delete_option( 'master_user' );
			if ( ! Connection::is_site_connected() || Connection::is_user_connected() ) {
				throw new RuntimeException( "Step '$step': expected site-connected and not user-connected." );
			}
			wpcom_migration_e2e_calypso_retry(); // Redirects and exits.
			break;

		case 'assert-calypso-retry-authorize-url':
			$location = (string) get_option( 'wpcom_migration_e2e_last_redirect' );
			$query    = array();
			wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
			if ( 0 !== strpos( $location, 'https://jetpack.wordpress.com/jetpack.authorize/' ) ) {
				throw new RuntimeException( "Step '$step': expected a redirect to the authorize URL, got: " . var_export( $location, true ) );
			}
			if ( ! isset( $query['redirect_after_auth'], $query['skip_pricing'] ) || Connect_Page::page_url() !== $query['redirect_after_auth'] || '1' !== $query['skip_pricing'] ) {
				throw new RuntimeException( "Step '$step': the authorize URL should return to the screen: " . $location );
			}
			delete_option( 'wpcom_migration_e2e_last_redirect' );
			break;

		case 'calypso-retry-with-user-token':
			// Planted again by the previous step; nothing to authorize.
			if ( ! Connection::is_user_connected() ) {
				throw new RuntimeException( "Step '$step': expected user-connected." );
			}
			wpcom_migration_e2e_calypso_retry(); // Redirects and exits.
			break;

		case 'assert-calypso-retry-screen':
			$location = (string) get_option( 'wpcom_migration_e2e_last_redirect' );
			if ( Connect_Page::page_url() !== $location ) {
				throw new RuntimeException( "Step '$step': expected a redirect to the screen, got: " . var_export( $location, true ) );
			}
			delete_option( 'wpcom_migration_e2e_last_redirect' );
			break;

		case 'rest-rotate-secret-user-token':
			$response = wpcom_migration_e2e_signed_rest_request( '/wpcom-migration/v1/reprint/rotate-export-secret', WPCOM_MIGRATION_E2E_USER_TOKEN, 1 );
			wpcom_migration_e2e_expect_rest_status( $response, 200, $step );
			$data = $response->get_data();
			if ( ! isset( $data['secret'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $data['secret'] ) ) {
				throw new RuntimeException( "Step '$step': expected a 64-hex secret, got: " . wp_json_encode( $data ) );
			}
			if ( ! isset( $data['export_url'] ) || home_url( '/?' . Exporter::QUERY_VAR ) !== $data['export_url'] ) {
				throw new RuntimeException( "Step '$step': unexpected export_url: " . wp_json_encode( $data ) );
			}
			$state = Exporter::get_state();
			if ( ! $state['secret_valid'] || $state['window_open'] ) {
				throw new RuntimeException( "Step '$step': rotating should store a valid secret and leave the window closed: " . wp_json_encode( $state ) );
			}
			break;

		case 'rest-enable-export-user-token':
			$response = wpcom_migration_e2e_signed_rest_request( '/wpcom-migration/v1/reprint/enable-export', WPCOM_MIGRATION_E2E_USER_TOKEN, 1 );
			wpcom_migration_e2e_expect_rest_status( $response, 200, $step );
			$data = $response->get_data();
			if ( ! isset( $data['enabled_at'] ) || ! is_int( $data['enabled_at'] ) || $data['enabled_at'] < time() - 60 ) {
				throw new RuntimeException( "Step '$step': expected a fresh enabled_at, got: " . wp_json_encode( $data ) );
			}
			if ( ! isset( $data['export_url'] ) ) {
				throw new RuntimeException( "Step '$step': enable-export must also carry export_url." );
			}
			$state = Exporter::get_state();
			if ( ! $state['window_open'] ) {
				throw new RuntimeException( "Step '$step': enabling should open the window: " . wp_json_encode( $state ) );
			}
			break;

		case 'rest-rotate-secret-blog-token':
			// A blog token sets no user, so WordPress answers 401, not 403.
			$response = wpcom_migration_e2e_signed_rest_request( '/wpcom-migration/v1/reprint/rotate-export-secret', WPCOM_MIGRATION_E2E_BLOG_TOKEN, 0 );
			wpcom_migration_e2e_expect_rest_status( $response, 401, $step );
			$secret_before = get_option( Exporter::SECRET_OPTION );
			if ( ! $secret_before ) {
				throw new RuntimeException( "Step '$step': the earlier user-token rotation should have left a secret." );
			}
			break;

		case 'swap-in-known-secret':
			// request.php signs with a fixed secret; the random one never leaves
			// Playground. The window opened through REST stays open.
			if ( ! Exporter::store_secret( 'smoke-secret-0123456789abcdef0123456789abcdef' ) ) {
				throw new RuntimeException( "Step '$step': could not store the known secret." );
			}
			break;

		case 'render-not-connected':
			$html = wpcom_migration_e2e_render_connect_page();
			wpcom_migration_e2e_expect_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'value="' . Connect_Page::CONNECT_ACTION . '"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, Connect_Page::DISCONNECT_ACTION, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connected as', $step );
			break;

		case 'connect-registration-fails':
			// WordPress.com is unreachable; make registration fail before the
			// network, the way a site with a blocked outbound connection would.
			add_filter(
				'jetpack_pre_register',
				function () {
					return new WP_Error( 'e2e_blocked', 'Blocked by the e2e scenario.' );
				}
			);
			// The handler redirects and exits; keep the destination for the next step.
			add_filter(
				'wp_redirect',
				function ( $location ) {
					update_option( 'wpcom_migration_e2e_last_redirect', $location );
					return $location;
				}
			);
			wpcom_migration_e2e_post( Connect_Page::CONNECT_ACTION, array() );
			( new Connect_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' ) )->handle_connect(); // Redirects and exits.
			break;

		case 'assert-registration-failure-reported':
			$location = get_option( 'wpcom_migration_e2e_last_redirect' );
			$query    = array();
			wp_parse_str( (string) wp_parse_url( (string) $location, PHP_URL_QUERY ), $query );
			if ( ! isset( $query[ Connect_Page::NOTICE_QUERY_ARG ], $query[ Connect_Page::CODE_QUERY_ARG ] )
				|| 'register_failed' !== $query[ Connect_Page::NOTICE_QUERY_ARG ]
				|| 'e2e_blocked' !== $query[ Connect_Page::CODE_QUERY_ARG ] ) {
				throw new RuntimeException( "Step '$step': expected a register_failed redirect with the error code, got: " . var_export( $location, true ) );
			}
			if ( Connection::is_site_connected() ) {
				throw new RuntimeException( "Step '$step': a failed registration must write no tokens." );
			}
			$_GET[ Connect_Page::NOTICE_QUERY_ARG ] = 'register_failed';
			$_GET[ Connect_Page::CODE_QUERY_ARG ]   = 'e2e_blocked';
			$html                                   = wpcom_migration_e2e_render_connect_page();
			wpcom_migration_e2e_expect_contains( $html, 'could not register', $step );
			wpcom_migration_e2e_expect_contains( $html, 'e2e_blocked', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Blocked by the e2e scenario', $step );
			delete_option( 'wpcom_migration_e2e_last_redirect' );
			break;

		case 'authorization-url-returns-to-screen':
			// Calypso's authorize page, not the site's webhook, decides where the
			// browser lands afterwards. It reads redirect_after_auth from the
			// authorize URL and, with skip_pricing, goes straight there; a `from`
			// starting with wpcom-migration would send the user to Calypso's
			// migration flow instead.
			$authorization_url = Connection::authorization_url( Connect_Page::page_url() );
			if ( is_wp_error( $authorization_url ) ) {
				throw new RuntimeException( "Step '$step': authorization_url() failed: " . $authorization_url->get_error_code() );
			}
			$query = array();
			wp_parse_str( (string) wp_parse_url( $authorization_url, PHP_URL_QUERY ), $query );
			if ( ! isset( $query['redirect_after_auth'] ) || Connect_Page::page_url() !== $query['redirect_after_auth'] ) {
				throw new RuntimeException( "Step '$step': redirect_after_auth should be the screen URL, got: " . $authorization_url );
			}
			if ( ! isset( $query['skip_pricing'] ) || '1' !== $query['skip_pricing'] ) {
				throw new RuntimeException( "Step '$step': skip_pricing=1 is missing: " . $authorization_url );
			}
			if ( isset( $query['from'] ) && 0 === strpos( $query['from'], 'wpcom-migration' ) ) {
				throw new RuntimeException( "Step '$step': a wpcom-migration `from` diverts the user to Calypso's migration flow: " . $authorization_url );
			}
			// The site's webhook, which WordPress.com calls, reads its own copy.
			$redirect_uri_query = array();
			wp_parse_str( (string) wp_parse_url( (string) ( $query['redirect_uri'] ?? '' ), PHP_URL_QUERY ), $redirect_uri_query );
			if ( ! isset( $redirect_uri_query['redirect'] ) || Connect_Page::page_url() !== $redirect_uri_query['redirect'] ) {
				throw new RuntimeException( "Step '$step': redirect_uri should carry the screen URL as redirect, got: " . $authorization_url );
			}
			break;

		case 'render-connected':
			$html = wpcom_migration_e2e_render_connect_page();
			wpcom_migration_e2e_expect_contains( $html, 'Connected as e2e-tester', $step );
			wpcom_migration_e2e_expect_contains( $html, (string) WPCOM_MIGRATION_E2E_BLOG_ID, $step );
			wpcom_migration_e2e_expect_contains( $html, 'Continue on WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'https://wordpress.com/setup/site-migration?from=' . rawurlencode( home_url() ), $step );
			wpcom_migration_e2e_expect_contains( $html, 'value="' . Connect_Page::DISCONNECT_ACTION . '"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Log in with WordPress.com', $step );
			break;

		case 'render-connected-without-user-data':
			delete_transient( 'jetpack_connected_user_data_1' );
			// Cache the failure the package would otherwise record after a
			// remote call, so the render is offline and quick.
			set_transient( 'jetpack_connected_user_data_1', 'error', 5 * MINUTE_IN_SECONDS );
			$html = wpcom_migration_e2e_render_connect_page();
			wpcom_migration_e2e_expect_contains( $html, 'Connected to WordPress.com', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connected as', $step );
			break;

		default:
			throw new RuntimeException( 'Unknown connection step: ' . $step );
	}
}

/**
 * Dispatches a REST request signed the way WordPress.com signs them, through
 * the package's real verifier.
 *
 * Fills $_GET and $_SERVER as the signed request would arrive, signs with
 * the package's own Jetpack_Signature, clears the current user so
 * determine_current_user runs Rest_Authentication (nonce, timestamp, token,
 * signature), then dispatches in-process. Only the HTTP hop is skipped.
 *
 * @param string $route        REST route, e.g. /wpcom-migration/v1/reprint/enable-export.
 * @param string $access_token "key.secret" for a blog token, "key.secret.user_id" for a user token.
 * @param int    $user_id      0 for a blog token.
 * @return WP_REST_Response
 * @throws RuntimeException When signing fails.
 */
function wpcom_migration_e2e_signed_rest_request( $route, $access_token, $user_id ) {
	$token_parts = explode( '.', $access_token );
	$token_key   = $token_parts[0];

	$_GET = array(
		'_for'      => 'jetpack',
		'token'     => $token_key . ':1:' . (int) $user_id,
		'timestamp' => (string) time(),
		'nonce'     => substr( md5( uniqid( '', true ) ), 0, 10 ),
		'body-hash' => '',
	);

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['HTTP_HOST']      = 'e2e.example';
	$_SERVER['SERVER_PORT']    = '80';
	$_SERVER['REQUEST_URI']    = '/wp-json' . $route . '?' . http_build_query( $_GET );

	$signature = ( new Jetpack_Signature( $token_parts[0] . '.' . $token_parts[1], 0 ) )->sign_current_request( array( 'body' => null ) );
	if ( is_wp_error( $signature ) || ! $signature ) {
		throw new RuntimeException( 'Could not sign the request: ' . ( is_wp_error( $signature ) ? $signature->get_error_message() : 'empty signature' ) );
	}
	$_GET['signature'] = $signature;
	$_REQUEST          = $_GET;

	// Force determine_current_user to run again, now that the request is signed.
	$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberate: re-run authentication.
	Rest_Authentication::init()->reset_saved_auth_state();
	wp_get_current_user();

	return rest_do_request( new WP_REST_Request( 'POST', $route ) );
}

/**
 * Fails unless the REST response has the given status.
 *
 * @param WP_REST_Response $response Response from rest_do_request().
 * @param int              $expected Expected status.
 * @param string           $step     Step name, for the message.
 * @throws RuntimeException When the status differs.
 */
function wpcom_migration_e2e_expect_rest_status( $response, $expected, $step ) {
	if ( $expected !== $response->get_status() ) {
		throw new RuntimeException( sprintf( "Step '%s': expected HTTP %d, got %d: %s", $step, $expected, $response->get_status(), wp_json_encode( $response->get_data() ) ) );
	}
}

/**
 * Runs the handler for Calypso's retry URL, keeping where it redirected in an
 * option for the next step. The handler exits.
 */
function wpcom_migration_e2e_calypso_retry() {
	$_GET     = array(
		'page'                           => 'jetpack',
		'connect_url_redirect'           => 'true',
		'jetpack_connect_login_redirect' => 'true',
		'from'                           => '[unknown]',
		'redirect_after_auth'            => Connect_Page::page_url(),
	);
	$_REQUEST = $_GET;

	$_SERVER['REQUEST_METHOD'] = 'GET';

	add_filter(
		'wp_redirect',
		function ( $location ) {
			update_option( 'wpcom_migration_e2e_last_redirect', $location );
			return $location;
		}
	);
	( new Connect_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' ) )->handle_calypso_retry();
}

/**
 * Renders the connect screen and returns the markup.
 *
 * @return string
 */
function wpcom_migration_e2e_render_connect_page() {
	ob_start();
	( new Connect_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' ) )->render_page();
	return ob_get_clean();
}

// screen-steps.php defines the three helpers below with the same bodies. One
// blueprint loads one file, so they never meet; the guards cover a future
// combined load.
if ( ! function_exists( 'wpcom_migration_e2e_post' ) ) {
	/**
	 * Fills in a nonce-bearing POST request for an admin-post action.
	 *
	 * @param string $action The admin-post action.
	 * @param array  $fields Extra form fields.
	 */
	function wpcom_migration_e2e_post( $action, array $fields ) {
		$_POST    = array_merge(
			array(
				'action'   => $action,
				'_wpnonce' => wp_create_nonce( $action ),
			),
			$fields
		);
		$_REQUEST = $_POST;

		$_SERVER['REQUEST_METHOD'] = 'POST';
	}
}

if ( ! function_exists( 'wpcom_migration_e2e_expect_contains' ) ) {
	/**
	 * Fails unless the markup contains a needle.
	 *
	 * @param string $html   Rendered markup.
	 * @param string $needle Text expected to appear.
	 * @param string $step   Step name, for the message.
	 * @throws RuntimeException When the needle is absent.
	 */
	function wpcom_migration_e2e_expect_contains( $html, $needle, $step ) {
		if ( false === strpos( $html, $needle ) ) {
			throw new RuntimeException( "Step '$step': expected to find '$needle' in: " . substr( $html, 0, 400 ) );
		}
	}
}

if ( ! function_exists( 'wpcom_migration_e2e_expect_not_contains' ) ) {
	/**
	 * Fails unless the markup does not contain a needle.
	 *
	 * @param string $html   Rendered markup.
	 * @param string $needle Text expected to be absent.
	 * @param string $step   Step name, for the message.
	 * @throws RuntimeException When the needle is present.
	 */
	function wpcom_migration_e2e_expect_not_contains( $html, $needle, $step ) {
		if ( false !== strpos( $html, $needle ) ) {
			throw new RuntimeException( "Step '$step': expected not to find '$needle' in: " . substr( $html, 0, 400 ) );
		}
	}
}
