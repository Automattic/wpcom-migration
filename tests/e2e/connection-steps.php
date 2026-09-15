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
use Automattic\WPCOM_Migration\Connection;

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

		default:
			throw new RuntimeException( 'Unknown connection step: ' . $step );
	}
}
