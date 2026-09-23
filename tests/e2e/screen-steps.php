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
use Automattic\WPCOM_Migration\Reprint\Manual_Page;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

/**
 * Runs one screen step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_screen_step( $step ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';

	wp_set_current_user( 1 );
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'User 1 cannot manage_options.' );
	}

	$page = new Settings_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
	$page->set_connection_section( new \Automattic\WPCOM_Migration\Connect_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' ) );
	$manual = new Manual_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
	$secret = 'smoke-secret-0123456789abcdef0123456789abcdef';

	switch ( $step ) {
		case 'render-unconfigured':
			wpcom_migration_e2e_expect_mode( Settings_Page::MODE_NEEDS_CONNECTING, $step );
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'Log in with your WordPress.com account', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connect this site to WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 1, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Set up by hand', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'wpcom-migration-reprint-api-url', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'wpcom-migration-status', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'WordPress.com connection</h2>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Not configured yet', $step );
			wpcom_migration_e2e_expect_contains( $html, 'class="wpcom-migration-header"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'class="wpcom-migration-container"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Migrate your site to WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'WordPress.com copies your posts, pages, media and settings', $step );
			wpcom_migration_e2e_expect_not_contains( $html, '<div class="wrap">', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Reprint migration</h1>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'button-primary', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Powered by', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'wpcom-migration-sidebar', $step );
			break;

		case 'render-unconfigured-without-section':
			// No connection bootstrap: the mode renders without its controls.
			$bare = new Settings_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
			$html = wpcom_migration_e2e_render( $bare );
			wpcom_migration_e2e_expect_contains( $html, 'Log in with your WordPress.com account', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 0, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'id="wpcom-migration-reprint-secret"', $step );
			break;

		case 'save-secret-empty':
			wpcom_migration_e2e_post( Manual_Page::SAVE_SECRET_ACTION, array( Manual_Page::SECRET_FIELD => '' ) );
			$manual->handle_save_secret(); // Redirects and exits.
			break;

		case 'assert-unconfigured':
			$state = Exporter::get_state();
			if ( $state['has_secret'] ) {
				throw new RuntimeException( 'An empty secret was stored.' );
			}
			break;

		case 'save-secret':
			wpcom_migration_e2e_post( Manual_Page::SAVE_SECRET_ACTION, array( Manual_Page::SECRET_FIELD => $secret ) );
			$manual->handle_save_secret();
			break;

		case 'assert-secret-saved':
			$state = Exporter::get_state();
			if ( ! $state['secret_valid'] || $state['window_open'] ) {
				throw new RuntimeException( 'Saving the secret should store a valid secret and leave the window closed: ' . wp_json_encode( $state ) );
			}
			wpcom_migration_e2e_expect_mode( Settings_Page::MODE_PROVISIONED_WAITING, $step );
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'This site is set up and ready', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Set up by WordPress.com', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Nothing to do here', $step );
			// The login stays, demoted to a link.
			wpcom_migration_e2e_expect_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 0, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'id="wpcom-migration-reprint-api-url"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, '<h2>Export secret</h2>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Turn the exporter on', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Exporter disabled', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Shared secret', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Export window', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Remote API URL', $step );

			$manual_html = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h1>Set up by hand</h1>', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'id="wpcom-migration-reprint-api-url"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, esc_attr( home_url( '?' . Exporter::QUERY_VAR ) ), $step );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h2>Export secret</h2>', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'Turn the exporter on', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h2>Export URL</h2>', $step );
			break;

		case 'invalidate-secret':
			delete_option( Exporter::SECRET_HASH_OPTION );
			$state = Exporter::get_state();
			if ( ! $state['has_secret'] || $state['secret_valid'] ) {
				throw new RuntimeException( 'Deleting the hash should leave the secret set but invalid: ' . wp_json_encode( $state ) );
			}
			wpcom_migration_e2e_expect_mode( Settings_Page::MODE_BROKEN, $step );
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'no longer matches this site', $step );
			wpcom_migration_e2e_expect_contains( $html, 'its security salts changed', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 1, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Invalid: the site', $step );
			break;

		case 'enable':
			wpcom_migration_e2e_post( Manual_Page::SAVE_ENABLED_ACTION, array( Manual_Page::ENABLED_FIELD => '1' ) );
			$manual->handle_save_enabled();
			break;

		case 'assert-enabled':
			$state = Exporter::get_state();
			if ( ! $state['window_open'] ) {
				throw new RuntimeException( 'Enabling should open the window: ' . wp_json_encode( $state ) );
			}
			wpcom_migration_e2e_expect_mode( Settings_Page::MODE_READY, $step );
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'The exporter is on until', $step );
			wpcom_migration_e2e_expect_contains( $html, 'each export keeps it on for another hour', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 0, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Continue on WordPress.com', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'This site is set up and ready', $step );
			break;

		case 'disable':
			wpcom_migration_e2e_post( Manual_Page::SAVE_ENABLED_ACTION, array() );
			$manual->handle_save_enabled();
			break;

		case 'assert-disabled':
			$state = Exporter::get_state();
			if ( $state['window_open'] ) {
				throw new RuntimeException( 'Disabling should close the window: ' . wp_json_encode( $state ) );
			}
			wpcom_migration_e2e_expect_mode( Settings_Page::MODE_PROVISIONED_WAITING, $step );
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'This site is set up and ready', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'The exporter is on until', $step );
			break;

		case 'assert-no-mode-headings':
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_not_contains( $html, 'wpcom-migration-mode', $step );
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
function wpcom_migration_e2e_render( Settings_Page $page ) {
	ob_start();
	$page->render_page();
	return ob_get_clean();
}

/**
 * Renders the by-hand screen and returns the markup.
 *
 * @param Manual_Page $page The screen.
 * @return string
 */
function wpcom_migration_e2e_render_manual( Manual_Page $page ) {
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
function wpcom_migration_e2e_post( $action, array $fields ) {
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
function wpcom_migration_e2e_expect_contains( $html, $needle, $step ) {
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
function wpcom_migration_e2e_expect_not_contains( $html, $needle, $step ) {
	if ( false !== strpos( $html, $needle ) ) {
		throw new RuntimeException( "Step '$step': expected not to find '$needle' in: " . substr( $html, 0, 300 ) );
	}
}

/**
 * Fails unless the screen's mode is the one expected.
 *
 * @param string $expected One of Settings_Page::MODE_*.
 * @param string $step     Name of the step, for the error message.
 * @throws RuntimeException When the mode differs.
 */
function wpcom_migration_e2e_expect_mode( $expected, $step ) {
	$user_connected = class_exists( '\Automattic\WPCOM_Migration\Connection' ) && \Automattic\WPCOM_Migration\Connection::is_user_connected();
	$mode           = Settings_Page::mode( Exporter::get_state(), $user_connected );
	if ( $expected !== $mode ) {
		throw new RuntimeException( "Step '$step': expected mode '$expected', got '$mode'." );
	}
}

/**
 * Fails unless the markup holds exactly the expected number of primary
 * buttons. The screen's primary is the branded button, not wp-admin's.
 *
 * @param string $html     Rendered markup.
 * @param int    $expected Expected count of 'wpcom-migration-button'.
 * @param string $step     Name of the step, for the error message.
 * @throws RuntimeException When the count differs.
 */
function wpcom_migration_e2e_expect_primary_count( $html, $expected, $step ) {
	$count = substr_count( $html, 'wpcom-migration-button' );
	if ( $expected !== $count ) {
		throw new RuntimeException( "Step '$step': expected $expected primary button(s), found $count." );
	}
}
