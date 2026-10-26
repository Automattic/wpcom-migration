<?php
/**
 * Drives the settings screen inside Playground, one step per runPHP call.
 *
 * Each step throws on a failed expectation; a non-zero PHP exit fails the
 * blueprint step, which fails the boot, and run.sh prints the log.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Connect_Page;
use Automattic\WPCOM_Migration\Reprint\Exporter;
use Automattic\WPCOM_Migration\Reprint\Key_Page;
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
	$manual   = new Manual_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
	$key_page = new Key_Page( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
	$secret   = 'smoke-secret-0123456789abcdef0123456789abcdef';

	switch ( $step ) {
		case 'render-unconfigured':
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'Log in with your WordPress.com account to connect this site', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Add Migration Key</a>', $step );
			wpcom_migration_e2e_expect_contains( $html, 'href="' . esc_url( Key_Page::page_url() ) . '"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Connect WordPress.com', $step );
			wpcom_migration_e2e_expect_not_contains( $html, Connect_Page::DISCONNECT_ACTION, $step );
			wpcom_migration_e2e_expect_contains( $html, 'Then start the migration on <a class="wpcom-migration-external-link" href="https://my.wordpress.com/sites" target="_blank" rel="noreferrer">', $step );
			wpcom_migration_e2e_expect_contains( $html, 'aria-label="(opens in a new tab)"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'By connecting, you agree to the', $step );
			wpcom_migration_e2e_expect_contains( $html, 'WordPress.com Terms of Service</a>.', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Log in with WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 1, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Set up Reprint manually', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'wpcom-migration-reprint-api-url', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'wpcom-migration-status', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'WordPress.com connection</h2>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Not configured yet', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'class="wpcom-migration-header"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'class="wpcom-migration-container"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Migrate your site to WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'WordPress.com copies your posts, pages, media, themes, plugins, and settings', $step );
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
			wpcom_migration_e2e_expect_contains( $html, 'Log in with your WordPress.com account to connect this site', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Add Migration Key</a>', $step );
			wpcom_migration_e2e_expect_contains( $html, 'href="' . esc_url( Key_Page::page_url() ) . '"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connect WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 0, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'id="wpcom-migration-reprint-secret"', $step );
			break;

		case 'render-secret-form':
			$manual_html = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h2>Export secret</h2>', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'name="' . Manual_Page::PUBLIC_KEY_FIELD . '"', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, '<h2>Public key</h2>', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'Public keys need OpenSSL', $step );
			break;

		case 'assert-secret-refused':
			$state = Exporter::get_state();
			if ( $state['has_secret'] ) {
				throw new RuntimeException( "Step '$step': a host that verifies keys must not store a secret: " . wp_json_encode( $state ) );
			}
			$_GET[ Manual_Page::NOTICE_QUERY_ARG ] = 'secret_unsupported';
			$manual_html                           = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, 'This site verifies exports with public keys.', $step );
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
			if ( ! $state['has_secret'] ) {
				throw new RuntimeException( 'Saving the secret should store a valid secret: ' . wp_json_encode( $state ) );
			}
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'Log in with your WordPress.com account to connect this site, or copy over the Migration Key.', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Keep this plugin active until the migration is done. We&#8217;ll email you when it&#8217;s finished.', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Continue on WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'By connecting, you agree to the', $step );
			// The key screen manages keys only; on this host it can't show the secret.
			wpcom_migration_e2e_expect_contains( $html, 'Add Migration Key</a>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Manage Migration Key', $step );
			wpcom_migration_e2e_expect_contains( $html, 'href="' . esc_url( Key_Page::page_url() ) . '"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Connect WordPress.com', $step );
			// A key-only site has no connection to cut.
			wpcom_migration_e2e_expect_not_contains( $html, Connect_Page::DISCONNECT_ACTION, $step );
			wpcom_migration_e2e_expect_primary_count( $html, 1, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'id="wpcom-migration-reprint-api-url"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, '<h2>Export secret</h2>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Shared secret', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Remote API URL', $step );

			$manual_html = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h1>Set up Reprint manually</h1>', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'id="wpcom-migration-reprint-api-url"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, esc_attr( home_url( '?' . Exporter::QUERY_VAR ) ), $step );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h2>Export secret</h2>', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, '<h2>Export URL</h2>', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'Remove secret', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'name="' . Manual_Page::PUBLIC_KEY_FIELD . '"', $step );
			break;

		case 'reject-non-administrator-manual-access':
			add_role(
				'migration-options-only',
				'Migration options only',
				array(
					'read'           => true,
					'manage_options' => true,
				)
			);
			$user_id = wp_insert_user(
				array(
					'user_login' => 'migration-options-only',
					'user_pass'  => wp_generate_password( 24, false ),
					'role'       => 'migration-options-only',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new RuntimeException( 'Could not create the options-only user: ' . $user_id->get_error_message() );
			}

			wp_set_current_user( $user_id );
			if ( ! current_user_can( 'manage_options' ) || in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
				throw new RuntimeException( 'The test user must have manage_options without the administrator role.' );
			}
			if ( '' !== wpcom_migration_e2e_render_manual( $manual ) ) {
				throw new RuntimeException( 'A non-administrator can view the manual export screen.' );
			}

			$die_handler     = static function () {
				throw new RuntimeException( 'manual access denied' );
			};
			$die_filter      = static function () use ( $die_handler ) {
				return $die_handler;
			};
			$redirect_filter = static function () {
				throw new RuntimeException( 'A manual action reached its redirect.' );
			};
			add_filter( 'wp_die_handler', $die_filter );
			add_filter( 'wp_redirect', $redirect_filter );
			try {
				$actions = array(
					array( Manual_Page::SAVE_SECRET_ACTION, 'handle_save_secret', array( Manual_Page::SECRET_FIELD => 'attacker-secret' ) ),
					array( Manual_Page::DISCARD_SECRET_ACTION, 'handle_discard_secret', array() ),
				);
				foreach ( $actions as $action ) {
					wpcom_migration_e2e_post( $action[0], $action[2] );
					try {
						$manual->{$action[1]}();
						throw new RuntimeException( 'A manual action returned without denying access.' );
					} catch ( RuntimeException $exception ) {
						if ( 'manual access denied' !== $exception->getMessage() ) {
							throw $exception;
						}
					}
				}
			} finally {
				remove_filter( 'wp_die_handler', $die_filter );
				remove_filter( 'wp_redirect', $redirect_filter );
				wp_set_current_user( 1 );
			}

			$state = Exporter::get_state();
			if ( 'smoke-secret-0123456789abcdef0123456789abcdef' !== get_option( Exporter::SECRET_OPTION ) || ! $state['has_secret'] ) {
				throw new RuntimeException( 'Non-administrator access changed exporter credentials.' );
			}
			break;

		case 'discard-secret':
			wpcom_migration_e2e_post( Manual_Page::DISCARD_SECRET_ACTION, array() );
			$manual->handle_discard_secret();
			break;

		case 'assert-secret-discarded':
			$state = Exporter::get_state();
			if ( $state['has_secret'] || $state['has_public_key'] ) {
				throw new RuntimeException( 'Removing the secret should delete it: ' . wp_json_encode( $state ) );
			}
			$_GET[ Manual_Page::NOTICE_QUERY_ARG ] = 'discarded';
			$manual_html                           = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, 'Secret removed.', $step );
			if ( strpos( $manual_html, 'Secret removed.' ) > strpos( $manual_html, '<h1>' ) ) {
				throw new RuntimeException( "Step '$step': the notice should sit above the page heading." );
			}
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'Remove secret', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'wpcom-migration-reprint-api-url', $step );
			break;

		case 'render-public-key-form':
			$manual_html = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, 'name="' . Manual_Page::PUBLIC_KEY_FIELD . '"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'Enroll key', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'wpcom-migration-reprint-key-table', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, '<h2>Export secret</h2>', $step );
			break;

		case 'enroll-invalid-public-key':
			wpcom_migration_e2e_post( Manual_Page::SAVE_PUBLIC_KEY_ACTION, array( Manual_Page::PUBLIC_KEY_FIELD => 'not a key' ) );
			$manual->handle_save_public_key();
			break;

		case 'assert-no-public-key':
			$state = Exporter::get_state();
			if ( $state['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': an invalid key must not be stored: " . wp_json_encode( $state ) );
			}
			break;

		case 'enroll-public-key':
			// A PEM block, as an administrator would paste it; the one-line
			// form is kept to compare against what gets stored.
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			update_option( 'wpcom_migration_e2e_public_key', $public_key );
			wpcom_migration_e2e_post( Manual_Page::SAVE_PUBLIC_KEY_ACTION, array( Manual_Page::PUBLIC_KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			$manual->handle_save_public_key();
			break;

		case 'assert-public-key-enrolled':
			$public_key = get_option( 'wpcom_migration_e2e_public_key' );
			$state      = Exporter::get_state();
			if ( get_option( Exporter::PUBLIC_KEY_OPTION ) !== $public_key || ! $state['has_public_key'] || $state['has_secret'] ) {
				throw new RuntimeException( "Step '$step': expected the enrolled key and no secret: " . wp_json_encode( $state ) );
			}
			// A key alone, not known to be the right one: no "ready" claim, the
			// text and the primary button send the user on, and a secondary
			// button still offers the connection.
			$html = wpcom_migration_e2e_render( $page );
			wpcom_migration_e2e_expect_contains( $html, 'Manage Migration Key</a>', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Migration key added. Head over to <a class="wpcom-migration-external-link" href="https://my.wordpress.com/sites"', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Your site is ready to migrate', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Continue on WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Connect WordPress.com', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 1, $step );
			$_GET[ Manual_Page::NOTICE_QUERY_ARG ] = 'key_saved';
			$manual_html                           = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, 'Public key saved.', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'wpcom-migration-reprint-key-table', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, '<code>' . \WordPress\Reprint\Server\Utils::public_key_fingerprint( $public_key ) . '</code>', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'value="' . Manual_Page::REMOVE_PUBLIC_KEY_ACTION . '"', $step );
			wpcom_migration_e2e_expect_contains( $manual_html, 'wpcom-migration-reprint-api-url', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'id="wpcom-migration-reprint-secret"', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'Remove secret', $step );
			break;

		case 'remove-public-key':
			wpcom_migration_e2e_post( Manual_Page::REMOVE_PUBLIC_KEY_ACTION, array() );
			$manual->handle_remove_public_key();
			break;

		case 'assert-public-key-removed':
			$state = Exporter::get_state();
			if ( $state['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': removing the key should delete it: " . wp_json_encode( $state ) );
			}
			$_GET[ Manual_Page::NOTICE_QUERY_ARG ] = 'key_removed';
			$manual_html                           = wpcom_migration_e2e_render_manual( $manual );
			wpcom_migration_e2e_expect_contains( $manual_html, 'Public key removed.', $step );
			wpcom_migration_e2e_expect_not_contains( $manual_html, 'wpcom-migration-reprint-key-table', $step );
			break;

		case 'assert-other-credential-ignored':
			// A credential this host does not verify, stored before the manual
			// screen stopped offering it, must not read as ready.
			if ( \WordPress\Reprint\Server\Utils::key_auth_required() ) {
				$stored = Exporter::store_secret( $secret );
			} else {
				list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
				$stored               = Exporter::store_public_key( $public_key );
			}
			if ( ! $stored ) {
				throw new RuntimeException( "Step '$step': could not store the other credential." );
			}
			$state = Exporter::get_state();
			if ( $state['has_credential'] || $state['has_credential'] ) {
				throw new RuntimeException( "Step '$step': the other credential must count for nothing: " . wp_json_encode( $state ) );
			}
			wpcom_migration_e2e_expect_contains( wpcom_migration_e2e_render( $page ), 'Add Migration Key</a>', $step );
			wpcom_migration_e2e_expect_not_contains( wpcom_migration_e2e_render_manual( $manual ), 'wpcom-migration-reprint-api-url', $step );

			// Discarding also clears the options earlier versions kept.
			foreach ( Exporter::OBSOLETE_OPTIONS as $option ) {
				update_option( $option, 'left-over', false );
			}
			Exporter::discard_credentials();
			foreach ( Exporter::OBSOLETE_OPTIONS as $option ) {
				if ( false !== get_option( $option ) ) {
					throw new RuntimeException( "Step '$step': discarding should delete $option." );
				}
			}
			break;

		case 'render-key-page':
			$html = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, '<h1>Add Migration Key</h1>', $step );
			wpcom_migration_e2e_expect_contains( $html, 'class="wpcom-migration-back-link" href="' . esc_url( Settings_Page::page_url() ) . '"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Copy the migration key from WordPress.com and paste it here.', $step );
			wpcom_migration_e2e_expect_contains( $html, 'WordPress.com Terms of Service</a>.', $step );
			wpcom_migration_e2e_expect_contains( $html, '<textarea', $step );
			wpcom_migration_e2e_expect_contains( $html, 'name="' . Key_Page::KEY_FIELD . '"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'placeholder="Paste the migration key"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'value="' . Key_Page::CONNECT_ACTION . '"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'WordPress.com Terms of Service', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connect with your WordPress.com account instead', $step );
			wpcom_migration_e2e_expect_contains( $html, 'class="wpcom-migration-container"', $step );
			wpcom_migration_e2e_expect_primary_count( $html, 1, $step );
			wpcom_migration_e2e_expect_contains( $html, 'autocapitalize="off"></textarea>', $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );
			break;

		case 'connect-key-empty':
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => "  \n" ) );
			wpcom_migration_e2e_expect_redirect( $key_page, add_query_arg( Key_Page::NOTICE_QUERY_ARG, 'empty', Key_Page::page_url() ), $step );
			break;

		case 'assert-key-empty':
			wpcom_migration_e2e_expect_no_public_key( $step );
			$_GET[ Key_Page::NOTICE_QUERY_ARG ] = 'empty';
			$html                               = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, 'Paste the migration key first.', $step );
			wpcom_migration_e2e_expect_contains( $html, 'notice-error', $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );

			$_GET[ Key_Page::NOTICE_QUERY_ARG ] = 'storage_failure';
			$html                               = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, 'The migration key couldn&#8217;t be saved.', $step );
			wpcom_migration_e2e_expect_contains( $html, 'notice-error', $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );
			break;

		case 'connect-key-invalid':
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => 'not a key' ) );
			wpcom_migration_e2e_expect_redirect( $key_page, add_query_arg( Key_Page::NOTICE_QUERY_ARG, 'invalid', Key_Page::page_url() ), $step );
			break;

		case 'assert-key-invalid':
			wpcom_migration_e2e_expect_no_public_key( $step );
			$_GET[ Key_Page::NOTICE_QUERY_ARG ] = 'invalid';
			$html                               = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, 'look like a migration key', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Copy it again from WordPress.com.', $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );
			break;

		case 'connect-key':
			// A PEM block with Windows line endings and surrounding whitespace,
			// as a browser submits a pasted textarea.
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			update_option( 'wpcom_migration_e2e_public_key', $public_key );
			$pem = '  ' . str_replace( "\n", "\r\n", \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) . "\r\n";
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => $pem ) );
			wpcom_migration_e2e_expect_redirect( $key_page, Settings_Page::page_url(), $step );
			break;

		case 'assert-key-connected':
			$public_key = get_option( 'wpcom_migration_e2e_public_key' );
			$state      = Exporter::get_state();
			if ( get_option( Exporter::PUBLIC_KEY_OPTION ) !== $public_key || ! $state['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': expected the connected key: " . wp_json_encode( $state ) );
			}
			$html = wpcom_migration_e2e_render_key( $key_page );
			// The stored key shows, to replace or clear.
			wpcom_migration_e2e_expect_contains( $html, '<h1>Manage Migration Key</h1>', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Paste a new one to replace it, then continue on <a class="wpcom-migration-external-link" href="https://my.wordpress.com/sites"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'This key was installed by pasting it into wp-admin.', $step );
			wpcom_migration_e2e_expect_contains( $html, 'value="' . Key_Page::DELETE_ACTION . '"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'form="wpcom-migration-delete-key"', $step );
			wpcom_migration_e2e_expect_contains( $html, 'autocapitalize="off">' . esc_textarea( $public_key ) . '</textarea>', $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );

			// Connecting the same key again changes nothing and records no event.
			$installed = 0;
			$counter   = static function ( $event ) use ( &$installed ) {
				if ( 'public_key_installed' === $event ) {
					++$installed;
				}
			};
			add_action( 'wpcom_migration_reprint_export_event', $counter );
			try {
				wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
				wpcom_migration_e2e_expect_redirect( $key_page, Settings_Page::page_url(), $step );
			} finally {
				remove_action( 'wpcom_migration_reprint_export_event', $counter );
			}
			if ( 0 !== $installed ) {
				throw new RuntimeException( "Step '$step': connecting the same key again recorded $installed public_key_installed event(s)." );
			}
			Exporter::discard_credentials();
			break;

		case 'reject-non-administrator-key-access':
			add_role(
				'migration-key-options-only',
				'Migration key options only',
				array(
					'read'           => true,
					'manage_options' => true,
				)
			);
			$user_id = wp_insert_user(
				array(
					'user_login' => 'migration-key-options-only',
					'user_pass'  => wp_generate_password( 24, false ),
					'role'       => 'migration-key-options-only',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new RuntimeException( 'Could not create the options-only user: ' . $user_id->get_error_message() );
			}

			wp_set_current_user( $user_id );
			if ( ! current_user_can( 'manage_options' ) || in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
				throw new RuntimeException( 'The test user must have manage_options without the administrator role.' );
			}
			$html = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_not_contains( $html, '<textarea', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Contact WordPress.com support', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Only an administrator can connect this site with a migration key.', $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );

			try {
				list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
				wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
				wpcom_migration_e2e_expect_key_denied( $key_page, $step );
			} finally {
				wp_set_current_user( 1 );
			}

			wpcom_migration_e2e_expect_no_public_key( $step );
			break;

		case 'connect-key-storage-failure':
			// The option refuses the write, as a full disk or a filter would.
			add_filter(
				'pre_update_option_' . Exporter::PUBLIC_KEY_OPTION,
				static function ( $value, $old_value ) {
					return $old_value;
				},
				10,
				2
			);
			$installed = 0;
			add_action(
				Exporter::EVENT_ACTION,
				static function ( $event ) use ( &$installed ) {
					if ( 'public_key_installed' === $event ) {
						++$installed;
					}
				}
			);
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			wpcom_migration_e2e_expect_redirect( $key_page, add_query_arg( Key_Page::NOTICE_QUERY_ARG, 'storage_failure', Key_Page::page_url() ), $step );
			if ( 0 !== $installed ) {
				throw new RuntimeException( "Step '$step': a key that was not stored must record no event." );
			}
			if ( get_option( Exporter::PUBLIC_KEY_OPTION ) === $public_key ) {
				throw new RuntimeException( "Step '$step': the refused write must not store the key." );
			}
			Exporter::discard_credentials();
			break;

		case 'connect-key-listener-throws':
			// Another plugin's event listener fails after the key is stored.
			// That is not a storage failure, and must not be reported as one.
			$listener = static function () {
				throw new RuntimeException( 'listener failed' );
			};
			add_action( Exporter::EVENT_ACTION, $listener );
			add_filter(
				'wp_redirect',
				static function ( $location ) {
					throw new RuntimeException( 'The key action redirected to ' . $location );
				}
			);
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			try {
				$key_page->handle_connect();
				throw new RuntimeException( "Step '$step': the listener's exception was swallowed." );
			} catch ( RuntimeException $exception ) {
				if ( 'listener failed' !== $exception->getMessage() ) {
					throw $exception;
				}
			}
			if ( get_option( Exporter::PUBLIC_KEY_OPTION ) !== $public_key || ! Exporter::get_state()['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': the key should be stored before the event fires." );
			}
			remove_action( Exporter::EVENT_ACTION, $listener );
			Exporter::discard_credentials();
			break;

		case 'reject-key-bad-nonce':
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			$_POST['_wpnonce']    = 'not-a-nonce';
			$_REQUEST['_wpnonce'] = 'not-a-nonce';
			wpcom_migration_e2e_expect_key_denied( $key_page, $step );
			wpcom_migration_e2e_expect_no_public_key( $step );
			break;

		case 'delete-key':
			// An empty field is refused even with a key stored; Delete removes it.
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			if ( ! Exporter::store_public_key( $public_key ) ) {
				throw new RuntimeException( "Step '$step': could not store a key." );
			}
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => '' ) );
			wpcom_migration_e2e_expect_redirect( $key_page, add_query_arg( Key_Page::NOTICE_QUERY_ARG, 'empty', Key_Page::page_url() ), $step );
			if ( ! Exporter::get_state()['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': an empty field must not remove the key." );
			}

			wpcom_migration_e2e_post( Key_Page::DELETE_ACTION, array() );
			wpcom_migration_e2e_expect_redirect( $key_page, add_query_arg( Key_Page::NOTICE_QUERY_ARG, 'deleted', Key_Page::page_url() ), $step, 'handle_delete' );
			if ( Exporter::get_state()['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': Delete should remove the key." );
			}
			$_GET[ Key_Page::NOTICE_QUERY_ARG ] = 'deleted';
			$html                               = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, 'Migration key deleted.', $step );
			wpcom_migration_e2e_expect_contains( $html, '<h1>Add Migration Key</h1>', $step );
			wpcom_migration_e2e_expect_not_contains( $html, Key_Page::DELETE_ACTION, $step );
			break;

		case 'multisite-key-page':
			$html = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, 'runs on single sites only', $step );
			wpcom_migration_e2e_expect_not_contains( $html, '<textarea', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connect with your WordPress.com account instead', $step );
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			wpcom_migration_e2e_expect_key_denied( $key_page, $step );
			wpcom_migration_e2e_expect_no_public_key( $step );
			break;

		case 'render-key-page-unsupported':
			$html = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, 'This site can&#8217;t connect with a migration key', $step );
			wpcom_migration_e2e_expect_contains( $html, '<a href="https://wordpress.com/help/" target="_blank" rel="noreferrer">Contact WordPress.com support</a> and say that you&#8217;re trying to migrate your site to WordPress.com but can&#8217;t paste the migration key.', $step );
			wpcom_migration_e2e_expect_not_contains( $html, '<textarea', $step );
			wpcom_migration_e2e_expect_not_contains( $html, Key_Page::KEY_FIELD, $step );
			wpcom_migration_e2e_expect_plain_language( $html, $step );
			break;

		case 'connect-key-unsupported':
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			wpcom_migration_e2e_expect_redirect( $key_page, Key_Page::page_url(), $step );
			break;

		case 'assert-key-unsupported':
			wpcom_migration_e2e_expect_no_public_key( $step );
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
 * Renders the migration key screen and returns the markup.
 *
 * @param Key_Page $page The screen.
 * @return string
 */
function wpcom_migration_e2e_render_key( Key_Page $page ) {
	ob_start();
	$page->render_page();
	return ob_get_clean();
}

/**
 * Runs a migration key form handler and fails unless it redirects to the
 * expected location.
 *
 * @param Key_Page $page     The screen.
 * @param string   $expected The expected redirect location.
 * @param string   $step     Name of the step, for the error message.
 * @param string   $handler  The Key_Page method that handles the post.
 * @throws RuntimeException When the handler returns or redirects elsewhere.
 */
function wpcom_migration_e2e_expect_redirect( Key_Page $page, $expected, $step, $handler = 'handle_connect' ) {
	$location = null;
	$filter   = static function ( $url ) use ( &$location ) {
		$location = $url;
		throw new RuntimeException( 'key redirect' );
	};
	add_filter( 'wp_redirect', $filter );
	try {
		$page->{$handler}();
		throw new RuntimeException( "Step '$step': the handler returned without redirecting." );
	} catch ( RuntimeException $exception ) {
		if ( 'key redirect' !== $exception->getMessage() ) {
			throw $exception;
		}
	} finally {
		remove_filter( 'wp_redirect', $filter );
	}

	if ( $expected !== $location ) {
		throw new RuntimeException( "Step '$step': expected a redirect to $expected, got $location." );
	}
}

/**
 * Fails unless the key screen's handler stops the current post with
 * wp_die() before it stores or redirects.
 *
 * @param Key_Page $page The key screen.
 * @param string   $step Name of the step, for the error message.
 * @throws RuntimeException When the handler does not die.
 */
function wpcom_migration_e2e_expect_key_denied( Key_Page $page, $step ) {
	$die_filter      = static function () {
		return static function () {
			throw new RuntimeException( 'key access denied' );
		};
	};
	$redirect_filter = static function () use ( $step ) {
		throw new RuntimeException( "Step '$step': the key action reached its redirect." );
	};
	add_filter( 'wp_die_handler', $die_filter );
	add_filter( 'wp_redirect', $redirect_filter );
	try {
		$page->handle_connect();
		throw new RuntimeException( "Step '$step': the key action returned without denying access." );
	} catch ( RuntimeException $exception ) {
		if ( 'key access denied' !== $exception->getMessage() ) {
			throw $exception;
		}
	} finally {
		remove_filter( 'wp_die_handler', $die_filter );
		remove_filter( 'wp_redirect', $redirect_filter );
	}
}

/**
 * Fails when the markup, as text, uses a word the migration key screen keeps
 * out of sight.
 *
 * @param string $html Rendered markup.
 * @param string $step Name of the step, for the error message.
 * @throws RuntimeException When a forbidden word is present.
 */
function wpcom_migration_e2e_expect_plain_language( $html, $step ) {
	$text = strtolower( wp_strip_all_tags( $html ) );
	foreach ( array( 'public key', 'private key', 'pem', 'rsa', 'secret', 'reprint' ) as $word ) {
		if ( false !== strpos( $text, $word ) ) {
			throw new RuntimeException( "Step '$step': the screen's text contains '$word'." );
		}
	}
}

/**
 * Fails when a public key is stored.
 *
 * @param string $step Name of the step, for the error message.
 * @throws RuntimeException When a key is stored.
 */
function wpcom_migration_e2e_expect_no_public_key( $step ) {
	$state = Exporter::get_state();
	if ( $state['has_public_key'] ) {
		throw new RuntimeException( "Step '$step': no key should be stored: " . wp_json_encode( $state ) );
	}
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
 * Fails unless the markup holds exactly the expected number of primary
 * buttons. The screen's primary is the branded button, not wp-admin's.
 *
 * @param string $html     Rendered markup.
 * @param int    $expected Expected count of class="wpcom-migration-button".
 * @param string $step     Name of the step, for the error message.
 * @throws RuntimeException When the count differs.
 */
function wpcom_migration_e2e_expect_primary_count( $html, $expected, $step ) {
	$count = substr_count( $html, 'class="wpcom-migration-button"' );
	if ( $expected !== $count ) {
		throw new RuntimeException( "Step '$step': expected $expected primary button(s), found $count." );
	}
}
