<?php
/**
 * Drives the settings screen inside Playground, one step per runPHP call.
 *
 * Each step throws on a failed expectation; a non-zero PHP exit fails the
 * blueprint step, which fails the boot, and run.sh prints the log.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Reprint\Exporter;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

/**
 * Runs one screen step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_smoke_screen_step( $step ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';

	wp_set_current_user( 1 );
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'User 1 cannot manage_options.' );
	}

	$page   = new Settings_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
	$secret = 'smoke-secret-0123456789abcdef0123456789abcdef';

	switch ( $step ) {
		case 'render-unconfigured':
			$html = wpcom_migration_smoke_render( $page );
			wpcom_migration_smoke_expect_contains( $html, 'Not configured yet', $step );
			wpcom_migration_smoke_expect_contains( $html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_smoke_expect_not_contains( $html, 'wpcom-migration-reprint-api-url', $step );
			break;

		case 'save-secret-empty':
			wpcom_migration_smoke_post( Settings_Page::SAVE_SECRET_ACTION, array( Settings_Page::SECRET_FIELD => '' ) );
			$page->handle_save_secret(); // Redirects and exits.
			break;

		case 'assert-unconfigured':
			$state = Exporter::get_state();
			if ( $state['has_secret'] ) {
				throw new RuntimeException( 'An empty secret was stored.' );
			}
			break;

		case 'save-secret':
			wpcom_migration_smoke_post( Settings_Page::SAVE_SECRET_ACTION, array( Settings_Page::SECRET_FIELD => $secret ) );
			$page->handle_save_secret();
			break;

		case 'assert-secret-saved':
			$state = Exporter::get_state();
			if ( ! $state['secret_valid'] || $state['window_open'] ) {
				throw new RuntimeException( 'Saving the secret should store a valid secret and leave the window closed: ' . wp_json_encode( $state ) );
			}
			$html = wpcom_migration_smoke_render( $page );
			wpcom_migration_smoke_expect_contains( $html, 'Exporter disabled', $step );
			wpcom_migration_smoke_expect_contains( $html, 'id="wpcom-migration-reprint-api-url"', $step );
			wpcom_migration_smoke_expect_contains( $html, esc_attr( home_url( '?' . Exporter::QUERY_VAR ) ), $step );
			break;

		case 'enable':
			wpcom_migration_smoke_post( Settings_Page::SAVE_ENABLED_ACTION, array( Settings_Page::ENABLED_FIELD => '1' ) );
			$page->handle_save_enabled();
			break;

		case 'assert-enabled':
			$state = Exporter::get_state();
			if ( ! $state['window_open'] ) {
				throw new RuntimeException( 'Enabling should open the window: ' . wp_json_encode( $state ) );
			}
			$html = wpcom_migration_smoke_render( $page );
			wpcom_migration_smoke_expect_contains( $html, 'Exporter enabled until', $step );
			break;

		case 'disable':
			wpcom_migration_smoke_post( Settings_Page::SAVE_ENABLED_ACTION, array() );
			$page->handle_save_enabled();
			break;

		case 'assert-disabled':
			$state = Exporter::get_state();
			if ( $state['window_open'] ) {
				throw new RuntimeException( 'Disabling should close the window: ' . wp_json_encode( $state ) );
			}
			break;

		default:
			throw new RuntimeException( 'Unknown screen step: ' . $step );
	}
}

/**
 * Renders the screen and returns the markup.
 *
 * @param Settings_Page $page The screen.
 * @return string
 */
function wpcom_migration_smoke_render( Settings_Page $page ) {
	ob_start();
	$page->render_page();
	return ob_get_clean();
}

/**
 * Fills in a signed POST request for an admin-post action.
 *
 * @param string $action The admin-post action.
 * @param array  $fields Extra form fields.
 */
function wpcom_migration_smoke_post( $action, array $fields ) {
	$post = array_merge(
		array(
			'action'   => $action,
			'_wpnonce' => wp_create_nonce( $action ),
		),
		$fields
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This *is* the nonce-bearing request the step is building.
	$_POST    = $post;
	$_REQUEST = $_POST;

	$_SERVER['REQUEST_METHOD'] = 'POST';
}

/**
 * Fails unless the markup contains a needle.
 *
 * @param string $html  Rendered markup.
 * @param string $needle Text expected to appear.
 * @param string $step  Name of the step, for the error message.
 * @throws RuntimeException When the needle is absent.
 */
function wpcom_migration_smoke_expect_contains( $html, $needle, $step ) {
	if ( false === strpos( $html, $needle ) ) {
		throw new RuntimeException( "Step '$step': expected to find '$needle' in: " . substr( $html, 0, 300 ) );
	}
}

/**
 * Fails unless the markup does not contain a needle.
 *
 * @param string $html  Rendered markup.
 * @param string $needle Text expected to be absent.
 * @param string $step  Name of the step, for the error message.
 * @throws RuntimeException When the needle is present.
 */
function wpcom_migration_smoke_expect_not_contains( $html, $needle, $step ) {
	if ( false !== strpos( $html, $needle ) ) {
		throw new RuntimeException( "Step '$step': expected not to find '$needle' in: " . substr( $html, 0, 300 ) );
	}
}
