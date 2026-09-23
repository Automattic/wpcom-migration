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

	wp_set_current_user( 1 );
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new RuntimeException( 'User 1 cannot manage_options.' );
	}

	if ( 'suppressed' === $step ) {
		add_filter( 'wpcom_migration_show_menu', '__return_false', 99 );
	}

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own menu globals; this test builds them by hand.
	$GLOBALS['menu']              = array();
	$GLOBALS['submenu']           = array();
	$GLOBALS['_registered_pages'] = array();
	$GLOBALS['_parent_pages']     = array();
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

	do_action( 'admin_menu', '' );

	$slugs = wpcom_migration_e2e_menu_slugs();

	switch ( $step ) {
		case 'sidebar':
			if ( array( Settings_Page::PAGE_SLUG ) !== $slugs ) {
				throw new RuntimeException( 'Expected one sidebar row for the Reprint screen, got: ' . wp_json_encode( $slugs ) );
			}

			if ( ! empty( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] ) ) {
				throw new RuntimeException( 'The Reprint screen should draw no flyout: ' . wp_json_encode( $GLOBALS['submenu'][ Settings_Page::PAGE_SLUG ] ) );
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
