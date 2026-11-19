<?php
/**
 * Drives the admin menu inside Playground, one step per runPHP call.
 *
 * The blueprint defines WP_ADMIN before wp-load so the plugin registers its
 * admin hooks; the menu globals are then built by hand and inspected. Each
 * step throws on a failed expectation.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Connect_Page;
use Automattic\WPCOM_Migration\Reprint\Key_Page;
use Automattic\WPCOM_Migration\Reprint\Manual_Page;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

// WordPress's fatal handler would swallow the message into a generic error
// page; print it where run.sh shows the log instead.
set_exception_handler(
	function ( $exception ) {
		file_put_contents( 'php://stderr', get_class( $exception ) . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}
);

/**
 * Runs one menu step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_menu_step( $step ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	// Firing admin_enqueue_scripts here, outside of a real admin page load,
	// needs get_current_screen() defined for core's own hooked callbacks.
	require_once ABSPATH . 'wp-admin/includes/screen.php';

	wp_set_current_user( 1 );
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'User 1 cannot manage_options.' );
	}

	// runPHP is no page load; the activation redirect only fires on a GET.
	$_SERVER['REQUEST_METHOD'] = 'GET';

	if ( 'whitelabelled' === $step ) {
		// The brand option WPCOMInfo::getBrandInfo() reads. BlogVault can
		// set it, but it no longer hides any sidebar row.
		update_option( 'wpcombrand', array( 'hide_from_menu' => true ) );
	}

	$slugs = array();

	// These steps drive admin_menu themselves, with $pagenow and
	// $plugin_page set the way admin.php sets them; the shared fire below
	// would just be redone with the wrong globals.
	if ( ! in_array( $step, array( 'screens-reachable', 'old-screen-title' ), true ) ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own menu globals; this test builds them by hand.
		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		do_action( wpcom_migration_e2e_menu_hook( $step ), '' );

		$slugs = wpcom_migration_e2e_menu_slugs();
	}

	switch ( $step ) {
		case 'sidebar':
			// Mirrors what admin-header.php does before the sidebar and the
			// WP 6.9+ command palette read $submenu: the by-hand screen only
			// clears its flyout rows on admin_enqueue_scripts.
			do_action( 'admin_enqueue_scripts', '' );

			if ( array( Settings_Page::PAGE_SLUG ) !== $slugs ) {
				throw new RuntimeException( 'Expected one sidebar row for the Reprint screen, got: ' . wp_json_encode( $slugs ) );
			}

			wpcom_migration_e2e_assert_flyout( 'The Reprint screen' );
			break;

		case 'screens-reachable':
			wpcom_migration_e2e_assert_screens_reachable( array( Settings_Page::PAGE_SLUG, Manual_Page::PAGE_SLUG, Key_Page::PAGE_SLUG, 'wpcom-migration' ) );
			break;

		case 'old-screen-still-reachable':
			$hook = get_plugin_page_hookname( 'wpcom-migration', '' );
			if ( 'toplevel_page_wpcom-migration' !== $hook ) {
				throw new RuntimeException( "The old screen's hook suffix changed: $hook" );
			}
			if ( ! isset( $GLOBALS['_registered_pages'][ $hook ] ) ) {
				throw new RuntimeException( 'The old screen is no longer a registered page; its URL would be refused.' );
			}
			if ( ! has_action( $hook ) ) {
				throw new RuntimeException( 'The old screen has no render callback; its URL would render nothing.' );
			}
			break;

		case 'manual-screen-registered':
			$hook = get_plugin_page_hookname( Manual_Page::PAGE_SLUG, Settings_Page::PAGE_SLUG );
			if ( ! isset( $GLOBALS['_registered_pages'][ $hook ] ) ) {
				throw new RuntimeException( "The by-hand screen is not a registered page: $hook" );
			}
			if ( ! has_action( $hook ) ) {
				throw new RuntimeException( "The by-hand screen has no render callback: $hook" );
			}

			$hook = get_plugin_page_hookname( Key_Page::PAGE_SLUG, Settings_Page::PAGE_SLUG );
			if ( ! isset( $GLOBALS['_registered_pages'][ $hook ] ) || ! has_action( $hook ) ) {
				throw new RuntimeException( "The migration key screen is not a rendering registered page: $hook" );
			}
			break;

		case 'whitelabelled':
			try {
				if ( array( Settings_Page::PAGE_SLUG ) !== $slugs ) {
					throw new RuntimeException( 'A brand with hide_from_menu should still leave the Reprint row, got: ' . wp_json_encode( $slugs ) );
				}
				wpcom_migration_e2e_assert_screens_reachable( array( Settings_Page::PAGE_SLUG, Manual_Page::PAGE_SLUG, Key_Page::PAGE_SLUG, 'wpcom-migration' ) );
			} finally {
				delete_option( 'wpcombrand' );
			}
			break;

		case 'old-screen-title':
			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			wpcom_migration_e2e_assert_title( 'wpcom-migration', $admin->bvinfo->getBrandName() );
			break;

		case 'settings-link':
			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$links = $admin->settingsLink( array(), 'wpcom-migration/wpcom_migration.php' );

			if ( 1 !== count( $links ) ) {
				throw new RuntimeException( 'Expected exactly one Settings link, got: ' . wp_json_encode( $links ) );
			}
			if ( false === strpos( $links[0], 'page=' . Settings_Page::PAGE_SLUG ) ) {
				throw new RuntimeException( 'The Settings link does not point at the Reprint screen: ' . $links[0] );
			}
			break;

		case 'activation-redirect':
			$captured = null;
			add_filter(
				'wp_redirect',
				function ( $location ) use ( &$captured ) {
					$captured = $location;

					// An empty location makes wp_redirect() return false
					// before it sends a header.
					return '';
				}
			);

			update_option( 'wpcomredirect', 'yes' );

			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$admin->initHandler();

			if ( null === $captured ) {
				throw new RuntimeException( 'Activating did not redirect at all.' );
			}
			if ( false === strpos( $captured, 'page=' . Settings_Page::PAGE_SLUG ) ) {
				throw new RuntimeException( 'The activation redirect does not land on the Reprint screen: ' . $captured );
			}
			break;

		case 'activation-redirect-waits-for-ajax':
		case 'activation-redirect-waits-for-post':
		case 'activation-redirect-waits-for-admin-post':
		case 'activation-redirect-waits-for-iframe':
			if ( 'activation-redirect-waits-for-ajax' === $step ) {
				add_filter( 'wp_doing_ajax', '__return_true' );
			} elseif ( 'activation-redirect-waits-for-post' === $step ) {
				$_SERVER['REQUEST_METHOD'] = 'POST';
			} elseif ( 'activation-redirect-waits-for-admin-post' === $step ) {
				$GLOBALS['pagenow'] = 'admin-post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin-post.php sets it so.
			} else {
				define( 'IFRAME_REQUEST', true );
			}
			$captured = null;
			add_filter(
				'wp_redirect',
				function ( $location ) use ( &$captured ) {
					$captured = $location;
					return '';
				}
			);
			update_option( 'wpcomredirect', 'yes' );

			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$admin->initHandler();

			if ( null !== $captured ) {
				throw new RuntimeException( 'A request that shows no screen should not redirect, got: ' . $captured );
			}
			if ( 'yes' !== get_option( 'wpcomredirect' ) ) {
				throw new RuntimeException( 'A request that shows no screen should leave the redirect for the next page load.' );
			}
			update_option( 'wpcomredirect', 'no' );
			break;

		case 'activation-redirect-runs-early':
			$early = $GLOBALS['wp_filter']['admin_init']->callbacks[1] ?? array();
			$found = false;
			foreach ( $early as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WPCOMWPAdmin && 'initHandler' === $callback['function'][1] ) {
					$found = true;
				}
			}
			if ( ! $found ) {
				throw new RuntimeException( 'The activation redirect should run on admin_init at priority 1.' );
			}
			break;

		case 'activation-redirect-script-fallback':
			// The filter blocks the redirect, as a plugin's own filter might.
			add_filter( 'wp_redirect', '__return_empty_string' );
			update_option( 'wpcomredirect', 'yes' );

			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$admin->initHandler();

			if ( 'yes' !== get_option( 'wpcomredirect' ) ) {
				throw new RuntimeException( 'A blocked redirect should keep the flag until the page prints the script.' );
			}
			ob_start();
			do_action( 'admin_print_scripts' );
			$output = ob_get_clean();
			if ( false === strpos( $output, 'window.location.replace' ) || false === strpos( $output, 'page=' . Settings_Page::PAGE_SLUG ) ) {
				throw new RuntimeException( 'A blocked redirect should print a script to the Reprint screen, got: ' . $output );
			}
			if ( 'no' !== get_option( 'wpcomredirect' ) ) {
				throw new RuntimeException( 'Printing the script should use up the flag.' );
			}
			break;

		case 'activation-redirect-skips-own-screen':
			// The WordPress.com connection return, already on the Reprint
			// screen with an argument a redirect would drop.
			$captured = null;
			add_filter(
				'wp_redirect',
				function ( $location ) use ( &$captured ) {
					$captured = $location;
					return '';
				}
			);
			update_option( 'wpcomredirect', 'yes' );
			$_GET['page']                              = Settings_Page::PAGE_SLUG;
			$_GET[ Connect_Page::CONNECTED_QUERY_ARG ] = '1';

			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$admin->initHandler();

			if ( null !== $captured ) {
				throw new RuntimeException( 'Already on the migration screen, nothing should redirect, got: ' . $captured );
			}
			if ( false !== has_action( 'admin_print_scripts', array( $admin, 'printRedirectScript' ) ) ) {
				throw new RuntimeException( 'Already on the migration screen, no script should send the browser on.' );
			}
			if ( 'no' !== get_option( 'wpcomredirect' ) ) {
				throw new RuntimeException( 'Reaching the migration screen should use up the flag.' );
			}
			break;

		case 'reactivation-redirect':
			// An account makes activate() take its already-configured branch,
			// which pings the app; answer every request locally.
			update_option( 'bvAccountsList', array( 'e2e-public-key' => array( 'wpcom-migration' => 1 ) ) );
			add_filter(
				'pre_http_request',
				function () {
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
					);
				}
			);
			$plugin = 'wpcom-migration/wpcom_migration.php';
			deactivate_plugins( $plugin, true );
			update_option( 'wpcomredirect', 'no' );
			$result = activate_plugin( $plugin );
			delete_option( 'bvAccountsList' );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'Reactivating failed: ' . $result->get_error_message() );
			}
			if ( 'yes' !== get_option( 'wpcomredirect' ) ) {
				throw new RuntimeException( 'Reactivating should ask for the redirect again.' );
			}
			update_option( 'wpcomredirect', 'no' );
			break;

		case 'bulk-activation-stays':
			$captured = null;
			add_filter(
				'wp_redirect',
				function ( $location ) use ( &$captured ) {
					$captured = $location;
					return '';
				}
			);
			update_option( 'wpcomredirect', 'yes' );
			$_GET['activate-multi'] = 'true';

			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$admin->initHandler();

			if ( null !== $captured ) {
				throw new RuntimeException( 'A bulk activation should stay on the plugin list, got: ' . $captured );
			}
			if ( 'no' !== get_option( 'wpcomredirect' ) ) {
				throw new RuntimeException( 'A bulk activation should use up the redirect.' );
			}
			break;

		case 'multisite-subsite':
			// The plugin's entry points on a network lead to the old screen
			// in the network admin; a subsite has no row, since the Reprint
			// screen can't migrate a network.
			do_action( 'admin_enqueue_scripts', '' );

			if ( array() !== $slugs ) {
				throw new RuntimeException( 'A subsite of a network should have no plugin row, got: ' . wp_json_encode( $slugs ) );
			}

			$admin    = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			$expected = network_admin_url( 'admin.php?page=wpcom-migration' );
			if ( $expected !== $admin->migrationScreenUrl() ) {
				throw new RuntimeException( 'On a network the plugin should send users to ' . $expected . ', got: ' . $admin->migrationScreenUrl() );
			}

			wpcom_migration_e2e_assert_title( Settings_Page::PAGE_SLUG, __( 'Migrate to WordPress.com', 'wpcom-migration' ) );
			wpcom_migration_e2e_assert_screens_reachable( array( Settings_Page::PAGE_SLUG, Manual_Page::PAGE_SLUG, Key_Page::PAGE_SLUG ) );
			break;

		case 'multisite-network':
			if ( array( 'wpcom-migration' ) !== $slugs ) {
				throw new RuntimeException( 'The network admin should keep the old screen\'s row, got: ' . wp_json_encode( $slugs ) );
			}

			$admin = new WPCOMWPAdmin( new WPCOMWPSettings(), new WPCOMWPSiteInfo() );
			wpcom_migration_e2e_assert_title( 'wpcom-migration', $admin->bvinfo->getBrandName(), 'network_admin_menu' );
			wpcom_migration_e2e_assert_screens_reachable( array( 'wpcom-migration' ), 'network_admin_menu' );
			break;

		case 'stored-main-screen-deleted':
			// Earlier versions cached WordPress.com's answer in this option;
			// the first admin load after an update deletes it.
			update_option(
				'wpcom_migration_main_screen',
				array(
					'main_screen' => 'blogvault',
					'expires_at'  => time() + 300,
				),
				false
			);
			do_action( 'admin_init' );
			if ( false !== get_option( 'wpcom_migration_main_screen' ) ) {
				throw new RuntimeException( 'An admin load should delete the stored main screen answer.' );
			}
			break;

		default:
			throw new RuntimeException( 'Unknown menu step: ' . $step );
	}
}

/**
 * The slugs of every top-level row currently in the menu.
 *
 * @return array
 */
function wpcom_migration_e2e_menu_slugs() {
	$slugs = array();
	foreach ( (array) $GLOBALS['menu'] as $item ) {
		$slugs[] = $item[2];
	}
	sort( $slugs );

	return $slugs;
}

/**
 * Asserts the Reprint row's flyout: Connection, then Migration Key.
 *
 * @param string $label What is checked, for messages.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_assert_flyout( $label ) {
	$rows = array();
	foreach ( (array) ( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] ?? array() ) as $item ) {
		$rows[ $item[2] ] = $item[0];
	}

	$expected = array(
		Settings_Page::PAGE_SLUG => 'Connection',
		Key_Page::PAGE_SLUG      => 'Migration Key',
	);
	if ( $expected !== $rows ) {
		throw new RuntimeException( "$label should draw Connection and Migration Key, got: " . wp_json_encode( $rows ) );
	}
}

/**
 * Asserts each admin.php?page=<slug> would render: authorized and hooked,
 * as admin.php checks before it lets the request in.
 *
 * Drives admin_menu itself, once per slug, with $pagenow and $plugin_page
 * set the way admin.php sets them. This is the path the
 * 'manual-screen-registered' step doesn't cover, because that looks the
 * parent up by passing it in directly instead of letting core discover it
 * from $submenu the way admin.php does.
 *
 * @param array  $page_slugs Page slugs.
 * @param string $menu_hook  admin_menu, or network_admin_menu for the network admin.
 * @throws RuntimeException When a page would be refused or render nothing.
 */
function wpcom_migration_e2e_assert_screens_reachable( array $page_slugs, $menu_hook = 'admin_menu' ) {
	foreach ( $page_slugs as $slug ) {
		wpcom_migration_e2e_fire_admin_menu_for( $slug, $menu_hook );

		// wp-admin/includes/menu.php refuses with a 403 when this is false.
		if ( ! user_can_access_admin_page() ) {
			throw new RuntimeException( "admin.php?page=$slug would be refused with a 403." );
		}
		// admin.php renders the page through this hook.
		if ( ! get_plugin_page_hook( $slug, 'admin.php' ) ) {
			throw new RuntimeException( "admin.php?page=$slug has no render hook." );
		}
	}
}

/**
 * Asserts the <title> admin-header.php would print for admin.php?page=<slug>:
 * admin.php fires load-<hook> and then admin-header.php asks
 * get_admin_page_title().
 *
 * @param string $slug      Page slug.
 * @param string $expected  Expected title.
 * @param string $menu_hook admin_menu, or network_admin_menu for the network admin.
 * @throws RuntimeException When the title differs.
 */
function wpcom_migration_e2e_assert_title( $slug, $expected, $menu_hook = 'admin_menu' ) {
	wpcom_migration_e2e_fire_admin_menu_for( $slug, $menu_hook );

	$hook = get_plugin_page_hook( $slug, 'admin.php' );
	if ( ! $hook ) {
		throw new RuntimeException( "admin.php?page=$slug has no render hook." );
	}
	do_action( "load-{$hook}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's own dynamic hook.

	$title = get_admin_page_title();
	if ( $expected !== $title ) {
		throw new RuntimeException( "admin.php?page=$slug would have the title " . wp_json_encode( $title ) . ', expected ' . wp_json_encode( $expected ) . '.' );
	}
}

/**
 * Resets core's menu globals and fires the menu hook as admin.php does for
 * admin.php?page=<slug>.
 *
 * @param string $slug      Page slug.
 * @param string $menu_hook admin_menu, or network_admin_menu for the network admin.
 */
function wpcom_migration_e2e_fire_admin_menu_for( $slug, $menu_hook = 'admin_menu' ) {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own globals; this test sets them the way admin.php does.
	$GLOBALS['pagenow']            = 'admin.php';
	$GLOBALS['plugin_page']        = $slug;
	$GLOBALS['parent_file']        = null;
	$GLOBALS['title']              = null;
	$GLOBALS['menu']               = array();
	$GLOBALS['submenu']            = array();
	$GLOBALS['_registered_pages']  = array();
	$GLOBALS['_parent_pages']      = array();
	$GLOBALS['_wp_menu_nopriv']    = array();
	$GLOBALS['_wp_submenu_nopriv'] = array();
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

	do_action( $menu_hook, '' );
}

/**
 * The menu hook a step's admin screen fires.
 *
 * @param string $step Step name.
 * @return string
 */
function wpcom_migration_e2e_menu_hook( $step ) {
	return 'multisite-network' === $step ? 'network_admin_menu' : 'admin_menu';
}
