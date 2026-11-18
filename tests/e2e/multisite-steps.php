<?php
/**
 * Drives the exporter on a network inside Playground, one step per runPHP
 * call.
 *
 * Playground's multisite runs without a server, so nothing here goes over
 * HTTP. Steps that act as the shop site set HTTP_HOST and REQUEST_URI before
 * wp-load, as a request to that site's URL would. Each step throws on a
 * failed expectation.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Connect_Page;
use Automattic\WPCOM_Migration\Connection;
use Automattic\WPCOM_Migration\Reprint\Exporter;
use Automattic\WPCOM_Migration\Reprint\Key_Page;
use Automattic\WPCOM_Migration\Reprint\Multisite_Context;
use Automattic\WPCOM_Migration\Reprint\Network_Sites;
use Automattic\WPCOM_Migration\Reprint\REST_Controller;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

// The screen helpers: rendering, form posts and their expectations.
require_once __DIR__ . '/screen-steps.php';

// WordPress's fatal handler would swallow the message into a generic error
// page; print it where run.sh shows the log instead.
set_exception_handler(
	function ( $exception ) {
		file_put_contents( 'php://stderr', get_class( $exception ) . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}
);

const WPCOM_MIGRATION_E2E_SHOP_PATH   = '/shop/';
const WPCOM_MIGRATION_E2E_PLUGIN      = 'wpcom-migration/wpcom_migration.php';
const WPCOM_MIGRATION_E2E_PLUGIN_FILE = WP_PLUGIN_DIR . '/' . WPCOM_MIGRATION_E2E_PLUGIN;
const WPCOM_MIGRATION_E2E_MS_SECRET   = 'multisite-secret-0123456789abcdef0123456789abcdef';

// A network that can't find the requested site ends the request with exit
// code 0 before the step runs, which Playground counts as a pass. Each step
// records itself here, and all-ran checks the record.
const WPCOM_MIGRATION_E2E_MS_RAN   = 'wpcom_migration_e2e_multisite_ran';
const WPCOM_MIGRATION_E2E_MS_STEPS = array(
	'create-shop',
	'list-guarded',
	'shop-context',
	'shop-served',
	'shop-off-list',
	'shop-list-race',
	'shop-selection',
	'main-selection',
	'deleted-site-delisted',
	'shop-shared-table',
	'main-shared-table',
	'shop-rest-permission',
	'shop-screens-site-admin',
	'shop-screens-super-admin',
	'shop-connect-enlists',
	'network-deactivation',
);

/**
 * Runs one multisite step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_multisite_step( $step ) {
	if ( ! is_multisite() ) {
		throw new RuntimeException( "Step '$step': not on a network." );
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	switch ( $step ) {
		case 'create-shop':
			$shop_id = wpmu_create_blog( 'localhost', WPCOM_MIGRATION_E2E_SHOP_PATH, 'Shop', 1 );
			if ( is_wp_error( $shop_id ) ) {
				throw new RuntimeException( "Step '$step': " . $shop_id->get_error_message() );
			}
			break;

		case 'list-guarded':
			// Nothing but the exporter may put a site on the list.
			delete_site_option( Network_Sites::OPTION );
			add_site_option( Network_Sites::OPTION, array( 99 ) );
			if ( array() !== get_site_option( Network_Sites::OPTION, array() ) ) {
				throw new RuntimeException( "Step '$step': a foreign add should store an empty list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			update_site_option( Network_Sites::OPTION, array( 99 ) );
			if ( array() !== get_site_option( Network_Sites::OPTION, array() ) ) {
				throw new RuntimeException( "Step '$step': a foreign update should keep the stored list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			delete_site_option( Network_Sites::OPTION );
			break;

		case 'shop-context':
			wpcom_migration_e2e_expect_shop( $step );
			$context = Multisite_Context::build();
			$shop_id = get_current_blog_id();
			$expect  = array(
				'site_id'      => $shop_id,
				'network_id'   => 1,
				'uploads_dir'  => rtrim( WP_CONTENT_DIR, '/' ) . '/uploads/sites/' . $shop_id,
				'home_url'     => get_option( 'home' ),
				'exporter_dir' => rtrim( WP_PLUGIN_DIR, '/' ) . '/wpcom-migration',
			);
			foreach ( $expect as $key => $value ) {
				if ( $context[ $key ] !== $value ) {
					throw new RuntimeException( "Step '$step': context $key should be " . wp_json_encode( $value ) . ', got: ' . wp_json_encode( $context[ $key ] ) );
				}
			}
			if ( untrailingslashit( WPCOM_MIGRATION_E2E_SHOP_PATH ) !== wp_parse_url( $context['home_url'], PHP_URL_PATH ) ) {
				throw new RuntimeException( "Step '$step': the context should describe the shop, got: " . $context['home_url'] );
			}
			break;

		case 'shop-served':
			// A credential on the shop exports the shop, not the network.
			wpcom_migration_e2e_expect_shop( $step );
			wpcom_migration_e2e_store_secret( $step );

			$exporter = wpcom_migration_e2e_run_exporter();
			if ( array() !== $exporter->errors ) {
				throw new RuntimeException( "Step '$step': the shop should be served, got: " . wp_json_encode( $exporter->errors ) );
			}
			if ( ! isset( $exporter->served['multisite']['site_id'] ) || get_current_blog_id() !== $exporter->served['multisite']['site_id'] ) {
				throw new RuntimeException( "Step '$step': the export should be limited to the shop, got: " . wp_json_encode( $exporter->served ) );
			}
			if ( ABSPATH !== $exporter->served['default_directory'] ) {
				throw new RuntimeException( "Step '$step': the export should start at ABSPATH." );
			}
			break;

		case 'shop-off-list':
			// A credential on a site that's off the list doesn't count. The
			// main site is on the list too, so taking the shop off is a real
			// update, not the delete an emptied list gets.
			wpcom_migration_e2e_expect_shop( $step );
			$main_id = get_main_site_id();
			switch_to_blog( $main_id );
			wpcom_migration_e2e_store_secret( $step );
			restore_current_blog();
			if ( ! Exporter::get_state()['has_credential'] ) {
				throw new RuntimeException( "Step '$step': the shop should still hold its credential." );
			}
			wpcom_migration_e2e_delist_current();
			if ( Network_Sites::has_current() ) {
				throw new RuntimeException( "Step '$step': the shop should be off the list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			if ( array( $main_id ) !== get_site_option( Network_Sites::OPTION ) ) {
				throw new RuntimeException( "Step '$step': the main site should stay on the list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			if ( Exporter::get_state()['has_credential'] ) {
				throw new RuntimeException( "Step '$step': a site off the list should have no credential." );
			}
			$exporter = wpcom_migration_e2e_run_exporter();
			if ( null !== $exporter->served || ! isset( $exporter->errors[0] ) || 503 !== $exporter->errors[0]['code'] ) {
				throw new RuntimeException( "Step '$step': a site off the list should not be served, got: " . wp_json_encode( $exporter->errors ) );
			}

			// Joining the list again starts from nothing: the stale secret is gone.
			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			Exporter::store_public_key( \WordPress\Reprint\Server\PublicKeyServer::assert_valid_public_key( \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ), 'api' );
			if ( false !== get_option( Exporter::SECRET_OPTION ) ) {
				throw new RuntimeException( "Step '$step': rejoining the list should discard the stale secret." );
			}
			if ( ! Network_Sites::has_current() || ! Exporter::get_state()['has_public_key'] ) {
				throw new RuntimeException( "Step '$step': storing a key should put the shop back on the list." );
			}

			// Deactivating on one site takes it off the list and leaves the others.
			Exporter::discard_credentials( false );
			if ( Network_Sites::has_current() || false !== get_option( Exporter::PUBLIC_KEY_OPTION ) ) {
				throw new RuntimeException( "Step '$step': deactivating on the shop should delete its key and take it off the list." );
			}
			if ( array( $main_id ) !== get_site_option( Network_Sites::OPTION ) ) {
				throw new RuntimeException( "Step '$step': the main site should stay on the list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			break;

		case 'shop-list-race':
			// Another site's request writes the list right after the shop's
			// write, without the shop. The shop sees that and joins again.
			wpcom_migration_e2e_expect_shop( $step );
			global $wpdb;
			$main_id = get_main_site_id();
			if ( array( $main_id ) !== get_site_option( Network_Sites::OPTION ) ) {
				throw new RuntimeException( "Step '$step': expected only the main site on the list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			$lost = 0;
			$race = static function () use ( $wpdb, $main_id, &$race, &$lost ) {
				remove_action( 'update_site_option_' . Network_Sites::OPTION, $race );
				++$lost;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stands in for another request's write.
				$wpdb->update( $wpdb->sitemeta, array( 'meta_value' => maybe_serialize( array( $main_id ) ) ), array( 'meta_key' => Network_Sites::OPTION ) );
			};
			add_action( 'update_site_option_' . Network_Sites::OPTION, $race );
			if ( ! Exporter::store_secret( WPCOM_MIGRATION_E2E_MS_SECRET ) ) {
				throw new RuntimeException( "Step '$step': the secret should be stored." );
			}
			wp_cache_delete( get_current_network_id() . ':' . Network_Sites::OPTION, 'site-options' );
			if ( 1 !== $lost || array( $main_id, get_current_blog_id() ) !== get_site_option( Network_Sites::OPTION ) ) {
				throw new RuntimeException( "Step '$step': the shop should have joined again after the lost write, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			break;

		case 'shop-selection':
			// What Reprint itself makes of the shop's context: the filters its
			// file_index and sql_chunk endpoints apply, and its dispatcher.
			wpcom_migration_e2e_expect_shop( $step );
			global $wpdb;
			$context = Multisite_Context::build();
			$shop_id = get_current_blog_id();
			$content = rtrim( WP_CONTENT_DIR, '/' );
			wpcom_migration_e2e_expect_paths(
				new \WordPress\Reprint\Server\MultisiteFileSelection( $context ),
				array(
					$content . "/uploads/sites/$shop_id/2026/01/shop.jpg" => true,
					$content . '/plugins/hello.php'        => true,
					// The exporter's own folder stays out, though shared plugins go.
					$content . '/plugins/wpcom-migration/wpcom_migration.php' => false,
					ABSPATH . 'wp-includes/version.php'    => true,
					$content . '/uploads/2026/01/main.jpg' => false,
					$content . '/uploads/sites/999/2026/01/other.jpg' => false,
				),
				$step
			);
			wpcom_migration_e2e_expect_tables(
				new \WordPress\Reprint\Server\MultisiteDatabaseSelection( $wpdb->base_prefix, $context['site_id'], $context['network_id'] ),
				array(
					$wpdb->prefix . 'posts'          => true,
					$wpdb->prefix . 'options'        => true,
					$wpdb->base_prefix . 'posts'     => false,
					$wpdb->base_prefix . 'options'   => false,
					$wpdb->base_prefix . '999_posts' => false,
				),
				$step
			);

			// The dispatcher takes the context and refuses an old client.
			$server = new \WordPress\Reprint\Server\HTTPServer( array( 'multisite' => $context ) );
			try {
				$server->normalize_config( array( 'endpoint' => 'preflight' ) );
				throw new RuntimeException( "Step '$step': a client without multisite_mode should be refused." );
			} catch ( InvalidArgumentException $exception ) {
				// Expected.
			}
			$config = $server->normalize_config(
				array(
					'endpoint'       => 'preflight',
					'multisite_mode' => 'one-site-network-v1',
				)
			);
			if ( $shop_id !== $config['_multisite']['site_id'] ) {
				throw new RuntimeException( "Step '$step': the dispatcher should carry the shop's context, got: " . wp_json_encode( $config['_multisite'] ) );
			}
			break;

		case 'main-selection':
			// The main site's uploads root holds every other site's uploads,
			// and its tables have no number.
			if ( ! is_main_site() ) {
				throw new RuntimeException( "Step '$step': this request should have loaded the main site." );
			}
			global $wpdb;
			$context = Multisite_Context::build();
			$shop_id = get_blog_id_from_url( 'localhost', WPCOM_MIGRATION_E2E_SHOP_PATH );
			$content = rtrim( WP_CONTENT_DIR, '/' );
			wpcom_migration_e2e_expect_paths(
				new \WordPress\Reprint\Server\MultisiteFileSelection( $context ),
				array(
					$content . '/uploads/2026/01/main.jpg' => true,
					$content . "/uploads/sites/$shop_id/2026/01/shop.jpg" => false,
				),
				$step
			);
			wpcom_migration_e2e_expect_tables(
				new \WordPress\Reprint\Server\MultisiteDatabaseSelection( $wpdb->base_prefix, $context['site_id'], $context['network_id'] ),
				array(
					$wpdb->base_prefix . 'posts' => true,
					$wpdb->base_prefix . $shop_id . '_posts' => false,
				),
				$step
			);
			break;

		case 'deleted-site-delisted':
			// A deleted site leaves the list, so a later site given its ID
			// doesn't start on it.
			$temp_id = wpmu_create_blog( 'localhost', '/temp/', 'Temp', 1 );
			if ( is_wp_error( $temp_id ) ) {
				throw new RuntimeException( "Step '$step': " . $temp_id->get_error_message() );
			}
			switch_to_blog( $temp_id );
			wpcom_migration_e2e_store_secret( $step );
			$listed = Network_Sites::has_current();
			restore_current_blog();
			if ( ! $listed ) {
				throw new RuntimeException( "Step '$step': the temporary site should be on the list." );
			}
			$deleted = wp_delete_site( $temp_id );
			if ( is_wp_error( $deleted ) ) {
				throw new RuntimeException( "Step '$step': " . $deleted->get_error_message() );
			}
			if ( in_array( $temp_id, (array) get_site_option( Network_Sites::OPTION, array() ), true ) ) {
				throw new RuntimeException( "Step '$step': a deleted site should leave the list, got: " . wp_json_encode( get_site_option( Network_Sites::OPTION ) ) );
			}
			break;

		case 'shop-shared-table':
			// A table without a site number is usually the main site's own,
			// such as Action Scheduler's. The shop's export leaves it out.
			wpcom_migration_e2e_expect_shop( $step );
			global $wpdb;
			$main = $wpdb->base_prefix . 'e2e_main_orders';
			$bv   = $wpdb->base_prefix . 'bv_e2e';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "CREATE TABLE `$bv` (id INT)" );
			$wpdb->query( "CREATE TABLE `$main` (id INT)" );
			wpcom_migration_e2e_store_secret( $step );
			$exporter = wpcom_migration_e2e_run_exporter();
			$wpdb->query( "DROP TABLE `$main`" );
			$wpdb->query( "DROP TABLE `$bv`" );
			// phpcs:enable

			if ( null === $exporter->served ) {
				throw new RuntimeException( "Step '$step': the main site's tables should not stop the shop's export, got: " . wp_json_encode( $exporter->errors ) );
			}
			break;

		case 'main-shared-table':
			// The main site's export would lose such a table, so it stops.
			if ( ! is_main_site() ) {
				throw new RuntimeException( "Step '$step': this request should have loaded the main site." );
			}
			global $wpdb;
			$shared = $wpdb->base_prefix . 'e2e_shared_orders';
			$bv     = $wpdb->base_prefix . 'bv_e2e';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "CREATE TABLE `$bv` (id INT)" );
			try {
				Multisite_Context::build();
			} catch ( RuntimeException $exception ) {
				$wpdb->query( "DROP TABLE `$bv`" );
				throw new RuntimeException( "Step '$step': BlogVault's tables should not stop the export, got: " . $exception->getMessage() );
			}

			$wpdb->query( "CREATE TABLE `$shared` (id INT)" );
			wpcom_migration_e2e_store_secret( $step );
			$exporter = wpcom_migration_e2e_run_exporter();
			$wpdb->query( "DROP TABLE `$shared`" );
			$wpdb->query( "DROP TABLE `$bv`" );
			// phpcs:enable

			if ( null !== $exporter->served || ! isset( $exporter->errors[0] ) ) {
				throw new RuntimeException( "Step '$step': a table without a site number should stop the main site's export." );
			}
			$error = $exporter->errors[0];
			if ( 400 !== $error['code'] || 'multisite_unsupported' !== $error['reason'] || false === strpos( $error['message'], $shared ) ) {
				throw new RuntimeException( "Step '$step': the refusal should name the table, got: " . wp_json_encode( $error ) );
			}
			break;

		case 'shop-rest-permission':
			wpcom_migration_e2e_expect_shop( $step );
			$controller = new REST_Controller();

			// The shop's own administrator isn't enough.
			$admin_id = wp_insert_user(
				array(
					'user_login' => 'shop-admin',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'shop-admin@example.com',
					'role'       => 'administrator',
				)
			);
			if ( is_wp_error( $admin_id ) ) {
				throw new RuntimeException( "Step '$step': " . $admin_id->get_error_message() );
			}
			wpcom_migration_e2e_rest_login( $admin_id );
			if ( true === $controller->permission_check() ) {
				throw new RuntimeException( "Step '$step': a site administrator should be refused on a network." );
			}

			// A super admin is, with no role on the shop.
			wpcom_migration_e2e_rest_login( 1 );
			if ( ! is_super_admin( 1 ) ) {
				throw new RuntimeException( "Step '$step': user 1 should be a super admin." );
			}
			if ( true !== $controller->permission_check() ) {
				throw new RuntimeException( "Step '$step': a super admin should be let in on a network." );
			}
			break;

		case 'shop-screens-site-admin':
			// The shop's own administrator gets the screens' refusal, and
			// their form posts die before they store anything.
			wpcom_migration_e2e_expect_shop( $step );
			wpcom_migration_e2e_reset_shop();
			wp_set_current_user( get_user_by( 'login', 'shop-admin' )->ID );
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new RuntimeException( "Step '$step': the shop's administrator should have manage_options." );
			}
			$refusal = esc_html( wptexturize( 'Only a super admin can migrate a site of this network.' ) );

			$html = wpcom_migration_e2e_render_shop_screen();
			wpcom_migration_e2e_expect_contains( $html, $refusal, $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Connect to WordPress.com', $step );
			wpcom_migration_e2e_expect_not_contains( $html, 'Migration Key', $step );

			$key_page = new Key_Page( WPCOM_MIGRATION_E2E_PLUGIN_FILE );
			$html     = wpcom_migration_e2e_render_key( $key_page );
			wpcom_migration_e2e_expect_contains( $html, $refusal, $step );
			wpcom_migration_e2e_expect_not_contains( $html, '<textarea', $step );

			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			wpcom_migration_e2e_expect_key_denied( $key_page, $step );
			wpcom_migration_e2e_expect_no_public_key( $step );

			wpcom_migration_e2e_post( Connect_Page::CONNECT_ACTION, array() );
			wpcom_migration_e2e_expect_dies(
				function () {
					( new Connect_Page( WPCOM_MIGRATION_E2E_PLUGIN_FILE ) )->handle_connect();
				},
				$step
			);
			if ( Network_Sites::has_current() ) {
				throw new RuntimeException( "Step '$step': a refused post should leave the shop off the list." );
			}
			break;

		case 'shop-screens-super-admin':
			// A super admin with no role on the shop sees the screen a single
			// site's administrator does, and a pasted key puts the shop on the
			// list.
			wpcom_migration_e2e_expect_shop( $step );
			wpcom_migration_e2e_reset_shop();
			remove_user_from_blog( 1, get_current_blog_id() );
			wp_set_current_user( 1 );
			if ( is_user_member_of_blog( 1 ) || ! is_super_admin( 1 ) ) {
				throw new RuntimeException( "Step '$step': user 1 should be a super admin with no role on the shop." );
			}

			$html = wpcom_migration_e2e_render_shop_screen();
			wpcom_migration_e2e_expect_contains( $html, 'Connect this site to your WordPress.com account', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Connect to WordPress.com', $step );
			wpcom_migration_e2e_expect_contains( $html, 'Use Migration Key Instead</a>', $step );

			$key_page = new Key_Page( WPCOM_MIGRATION_E2E_PLUGIN_FILE );
			wpcom_migration_e2e_expect_contains( wpcom_migration_e2e_render_key( $key_page ), '<textarea', $step );

			list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
			wpcom_migration_e2e_post( Key_Page::CONNECT_ACTION, array( Key_Page::KEY_FIELD => \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ) );
			wpcom_migration_e2e_expect_redirect( $key_page, Settings_Page::page_url(), $step );
			if ( ! Network_Sites::has_current() || '' === Key_Page::stored_key() ) {
				throw new RuntimeException( "Step '$step': the key should be stored and the shop on the list." );
			}
			wpcom_migration_e2e_expect_contains( wpcom_migration_e2e_render_shop_screen(), 'Migration key added.', $step );

			// Off the list, the key the shop's options still hold isn't shown.
			wpcom_migration_e2e_delist_current();
			if ( '' !== Key_Page::stored_key() ) {
				throw new RuntimeException( "Step '$step': a site off the list should show no key." );
			}
			wpcom_migration_e2e_expect_not_contains( wpcom_migration_e2e_render_shop_screen(), 'Migration key added.', $step );
			break;

		case 'shop-connect-enlists':
			// Connecting puts the shop on the list before any token is
			// written, even when registration then fails.
			wpcom_migration_e2e_expect_shop( $step );
			wpcom_migration_e2e_reset_shop();
			wp_set_current_user( 1 );
			add_filter(
				'jetpack_pre_register',
				function () {
					return new WP_Error( 'e2e_blocked', 'Blocked by the e2e scenario.' );
				}
			);
			if ( ! is_wp_error( Connection::authorization_url( Connect_Page::return_url() ) ) ) {
				throw new RuntimeException( "Step '$step': registration should have failed." );
			}
			if ( ! Network_Sites::has_current() ) {
				throw new RuntimeException( "Step '$step': connecting should put the shop on the list." );
			}
			break;

		case 'network-deactivation':
			// Through WordPress, from the main site, so the hooks get the
			// network-wide flag the way WordPress passes it. The connection
			// leaves every listed site before the exporter empties the list.
			$hook = 'deactivate_' . plugin_basename( WPCOM_MIGRATION_E2E_PLUGIN_FILE );
			if ( 5 !== has_action( $hook, array( Connection::class, 'leave' ) ) || 10 !== has_action( $hook, array( Exporter::class, 'discard_credentials' ) ) ) {
				throw new RuntimeException( "Step '$step': the connection should leave at priority 5, before the exporter discards at 10." );
			}

			$shop_id = get_blog_id_from_url( 'localhost', WPCOM_MIGRATION_E2E_SHOP_PATH );
			wpcom_migration_e2e_store_secret( $step );
			switch_to_blog( $shop_id );
			wpcom_migration_e2e_store_secret( $step );
			\Jetpack_Options::update_option( 'id', 424242 );
			\Jetpack_Options::update_option( 'blog_token', 'e2eblogkey.e2eblogsecret' );
			if ( ! Connection::is_site_connected() ) {
				throw new RuntimeException( "Step '$step': the shop should be connected." );
			}
			restore_current_blog();

			// WordPress.com is unreachable; skip the deregister call.
			add_filter( 'jetpack_connection_disconnect_site_wpcom', '__return_false' );
			deactivate_plugins( WPCOM_MIGRATION_E2E_PLUGIN, false, true );
			if ( is_plugin_active_for_network( WPCOM_MIGRATION_E2E_PLUGIN ) ) {
				throw new RuntimeException( "Step '$step': the plugin should be inactive on the network." );
			}

			switch_to_blog( $shop_id );
			$connected = Connection::is_site_connected();
			restore_current_blog();
			if ( $connected ) {
				throw new RuntimeException( "Step '$step': the shop should be disconnected." );
			}

			foreach ( array( get_main_site_id(), $shop_id ) as $site_id ) {
				switch_to_blog( $site_id );
				$left = get_option( Exporter::SECRET_OPTION );
				restore_current_blog();
				if ( false !== $left ) {
					throw new RuntimeException( "Step '$step': site $site_id should have no secret left." );
				}
			}
			if ( false !== get_site_option( Network_Sites::OPTION ) ) {
				throw new RuntimeException( "Step '$step': the list should be gone." );
			}

			$activated = activate_plugin( WPCOM_MIGRATION_E2E_PLUGIN, '', true );
			if ( is_wp_error( $activated ) ) {
				throw new RuntimeException( "Step '$step': " . $activated->get_error_message() );
			}
			break;

		case 'all-ran':
			$ran = get_site_option( WPCOM_MIGRATION_E2E_MS_RAN, array() );
			if ( WPCOM_MIGRATION_E2E_MS_STEPS !== $ran ) {
				throw new RuntimeException( "Step '$step': expected " . wp_json_encode( WPCOM_MIGRATION_E2E_MS_STEPS ) . ' to run, got: ' . wp_json_encode( $ran ) );
			}
			if ( ! is_plugin_active_for_network( WPCOM_MIGRATION_E2E_PLUGIN ) ) {
				throw new RuntimeException( "Step '$step': the plugin should be active on the network." );
			}
			return;

		default:
			throw new RuntimeException( "Unknown multisite step '$step'." );
	}

	$ran   = get_site_option( WPCOM_MIGRATION_E2E_MS_RAN, array() );
	$ran[] = $step;
	update_site_option( WPCOM_MIGRATION_E2E_MS_RAN, $ran );
}

/**
 * Takes the current site off the list and leaves its credential stored, as
 * a write that put the credential there while the plugin was inactive would.
 */
function wpcom_migration_e2e_delist_current() {
	$delist = new ReflectionMethod( Exporter::class, 'delist' );
	$delist->setAccessible( true );
	$delist->invoke( null, get_current_blog_id() );
}

/**
 * Fails unless Reprint's file selection takes and leaves the given paths.
 *
 * @param \WordPress\Reprint\Server\MultisiteFileSelection $selection The selection.
 * @param array<string,bool>                               $expected  Whether each path is in the export.
 * @param string                                           $step      Step name.
 * @throws RuntimeException When a path is taken or left wrongly.
 */
function wpcom_migration_e2e_expect_paths( $selection, array $expected, $step ) {
	foreach ( $expected as $path => $included ) {
		if ( $included !== $selection->includes_path( $path ) ) {
			throw new RuntimeException( "Step '$step': $path should " . ( $included ? '' : 'not ' ) . 'be in the export.' );
		}
	}
}

/**
 * Fails unless Reprint's database selection takes and leaves the given tables.
 *
 * @param \WordPress\Reprint\Server\MultisiteDatabaseSelection $selection The selection.
 * @param array<string,bool>                                   $expected  Whether each table is in the export.
 * @param string                                               $step      Step name.
 * @throws RuntimeException When a table is taken or left wrongly.
 */
function wpcom_migration_e2e_expect_tables( $selection, array $expected, $step ) {
	foreach ( $expected as $table => $included ) {
		if ( $included !== $selection->includes_table( $table ) ) {
			throw new RuntimeException( "Step '$step': table $table should " . ( $included ? '' : 'not ' ) . 'be in the export.' );
		}
	}
}

/**
 * Takes the shop off the list and deletes its credentials, so a step starts
 * from nothing.
 */
function wpcom_migration_e2e_reset_shop() {
	Exporter::discard_credentials( false );
}

/**
 * Renders the migration screen with its connection section, as the
 * bootstraps wire it.
 *
 * @return string
 */
function wpcom_migration_e2e_render_shop_screen() {
	$page = new Settings_Page( WPCOM_MIGRATION_E2E_PLUGIN_FILE );
	$page->set_connection_section( new Connect_Page( WPCOM_MIGRATION_E2E_PLUGIN_FILE ) );
	return wpcom_migration_e2e_render( $page );
}

/**
 * Fails unless the callback stops with wp_die().
 *
 * @param callable $callback Runs the handler.
 * @param string   $step     Step name.
 * @throws RuntimeException When the callback doesn't die.
 */
function wpcom_migration_e2e_expect_dies( callable $callback, $step ) {
	$die_filter = static function () {
		return static function () {
			throw new RuntimeException( 'access denied' );
		};
	};
	add_filter( 'wp_die_handler', $die_filter );
	try {
		$callback();
		throw new RuntimeException( "Step '$step': the handler returned without denying access." );
	} catch ( RuntimeException $exception ) {
		if ( 'access denied' !== $exception->getMessage() ) {
			throw $exception;
		}
	} finally {
		remove_filter( 'wp_die_handler', $die_filter );
	}
}

/**
 * Throws unless this request loaded the shop.
 *
 * @param string $step Step name.
 * @throws RuntimeException When this is another site.
 */
function wpcom_migration_e2e_expect_shop( $step ) {
	if ( WPCOM_MIGRATION_E2E_SHOP_PATH !== get_site()->path ) {
		throw new RuntimeException( "Step '$step': this request should have loaded the shop, got: " . get_site()->path );
	}
}

/**
 * Stores the test secret on the current site.
 *
 * @param string $step Step name.
 * @throws RuntimeException When it isn't stored.
 */
function wpcom_migration_e2e_store_secret( $step ) {
	if ( ! Exporter::store_secret( WPCOM_MIGRATION_E2E_MS_SECRET ) ) {
		throw new RuntimeException( "Step '$step': the secret should be stored." );
	}
	// A host with OpenSSL verifies keys, so the secret alone may not count.
	if ( \WordPress\Reprint\Server\Utils::key_auth_required() && ! Exporter::get_state()['has_public_key'] ) {
		list( , $public_key ) = \WordPress\Reprint\Server\PublicKeyClient::generate_keypair();
		Exporter::store_public_key( \WordPress\Reprint\Server\PublicKeyServer::assert_valid_public_key( \WordPress\Reprint\Server\Utils::public_key_to_pem( $public_key ) ), 'api' );
	}
}

/**
 * Sends the current site an export request through an exporter that skips
 * the signature check and records what it would have served or refused
 * instead of answering.
 *
 * @return Exporter With public $served and $errors.
 */
function wpcom_migration_e2e_run_exporter() {
	$_GET[ Exporter::QUERY_VAR ] = '';
	$_GET['endpoint']            = 'preflight';
	$_SERVER['REQUEST_METHOD']   = 'GET';

	$exporter = new class() extends Exporter {
		/**
		 * The options serve_export() received, or null.
		 *
		 * @var array|null
		 */
		public $served = null;

		/**
		 * The errors error() received.
		 *
		 * @var array[]
		 */
		public $errors = array();

		/**
		 * Accepts every request.
		 *
		 * @param string|null          $secret      Unused.
		 * @param array<string,string> $public_keys Unused.
		 * @return null
		 */
		protected function verify_signature( $secret, array $public_keys ) {
			return null;
		}

		/**
		 * Records the options.
		 *
		 * @param array $options HTTPServer options.
		 */
		protected function serve_export( array $options ) {
			$this->served = $options;
		}

		/**
		 * Records the error.
		 *
		 * @param int         $code    HTTP status code.
		 * @param string      $message Error description.
		 * @param string|null $reason  Reason code.
		 */
		protected function error( $code, $message, $reason = null ) {
			$this->errors[] = array(
				'code'    => $code,
				'message' => $message,
				'reason'  => $reason,
			);
		}

		/**
		 * Lets the step carry on.
		 */
		protected function terminate() {}
	};
	$exporter->handle_request();
	return $exporter;
}

/**
 * Logs a user in the way the REST cookie check accepts.
 *
 * @param int $user_id User ID.
 */
function wpcom_migration_e2e_rest_login( $user_id ) {
	wp_set_current_user( $user_id );
	$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
}
