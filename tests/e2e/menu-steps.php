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

use Automattic\WPCOM_Migration\Reprint\Manual_Page;
use Automattic\WPCOM_Migration\Reprint\Settings_Page;

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

	if ( 'suppressed' === $step ) {
		add_filter( 'wpcom_migration_show_menu', '__return_false', 99 );
	}

	$slugs = array();

	// 'screens-reachable' drives admin_menu itself, once per slug, with
	// $pagenow and $plugin_page set the way admin.php sets them; the shared
	// fire above would just be redone with the wrong globals.
	if ( 'screens-reachable' !== $step ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own menu globals; this test builds them by hand.
		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		do_action( 'admin_menu', '' );

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

			if ( ! empty( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] ) ) {
				throw new RuntimeException( 'The Reprint screen should draw no flyout: ' . wp_json_encode( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] ) );
			}
			break;

		case 'screens-reachable':
			// What admin.php enforces before it renders a page: refuse with a
			// 403 unless the page is both authorized and hooked. This is the
			// path the 'manual-screen-registered' step below doesn't cover,
			// because it looks the parent up by passing it in directly instead
			// of letting core discover it from $submenu the way admin.php does.
			foreach ( array( Settings_Page::PAGE_SLUG, Manual_Page::PAGE_SLUG, 'wpcom-migration' ) as $slug ) {
				// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own globals; this test sets them the way admin.php does.
				$GLOBALS['pagenow']            = 'admin.php';
				$GLOBALS['plugin_page']        = $slug;
				$GLOBALS['parent_file']        = null;
				$GLOBALS['menu']               = array();
				$GLOBALS['submenu']            = array();
				$GLOBALS['_registered_pages']  = array();
				$GLOBALS['_parent_pages']      = array();
				$GLOBALS['_wp_menu_nopriv']    = array();
				$GLOBALS['_wp_submenu_nopriv'] = array();
				// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

				do_action( 'admin_menu', '' );

				// admin.php checks both of these before it fires
				// admin_enqueue_scripts, while still requiring
				// wp-admin/includes/menu.php. wp-admin/includes/menu.php
				// refuses with a 403 when this is false.
				if ( ! user_can_access_admin_page() ) {
					throw new RuntimeException( "admin.php?page=$slug would be refused with a 403." );
				}
				// admin.php renders the page through this hook.
				if ( ! get_plugin_page_hook( $slug, 'admin.php' ) ) {
					throw new RuntimeException( "admin.php?page=$slug has no render hook." );
				}
			}
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
			break;

		case 'suppressed':
			if ( array() !== $slugs ) {
				throw new RuntimeException( 'wpcom_migration_show_menu=false should leave no sidebar row, got: ' . wp_json_encode( $slugs ) );
			}
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
