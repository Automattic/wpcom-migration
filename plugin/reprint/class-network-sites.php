<?php
/**
 * The sites of a network that hold an export credential.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * A network option listing the IDs of the sites the exporter wrote a
 * credential to while the plugin was active.
 *
 * On a network, the plugin is activated once for every site, and the
 * activation and deactivation hooks run only on the main site. The list lets
 * those hooks reach the other sites' credentials without visiting every site
 * of a large network.
 *
 * It also decides which credentials count. A site missing from the list has
 * none, whatever its options hold. The list is deleted at every activation
 * and deactivation and only Exporter may write it, so a credential written
 * by anything else, while the plugin was inactive say, never counts. On a
 * single site, Exporter deletes such credentials at activation instead.
 *
 * Unused on single sites.
 */
class Network_Sites {

	/**
	 * Network option holding the list of site IDs.
	 *
	 * @var string
	 */
	const OPTION = 'wpcom_migration_reprint_sites';

	/**
	 * Whether the current site is on the list.
	 *
	 * @return bool
	 */
	public static function has_current() {
		return in_array( get_current_blog_id(), self::ids(), true );
	}

	/**
	 * Adds the current site. Write it only with Exporter's guard held open.
	 */
	public static function add_current() {
		$ids = self::ids();
		if ( in_array( get_current_blog_id(), $ids, true ) ) {
			return;
		}

		$ids[] = get_current_blog_id();
		update_site_option( self::OPTION, $ids );
	}

	/**
	 * Removes the current site. Removing needs no guard: it only takes a
	 * credential away.
	 */
	public static function remove_current() {
		$ids = array_values( array_diff( self::ids(), array( get_current_blog_id() ) ) );
		if ( array() === $ids ) {
			delete_site_option( self::OPTION );
			return;
		}
		update_site_option( self::OPTION, $ids );
	}

	/**
	 * Runs a callback on each site on the list, then empties the list.
	 *
	 * @param callable $callback Runs with each site switched to.
	 */
	public static function drain( callable $callback ) {
		foreach ( self::ids() as $id ) {
			if ( ! get_site( $id ) ) {
				continue;
			}

			switch_to_blog( $id );
			try {
				$callback();
			} finally {
				restore_current_blog();
			}
		}

		delete_site_option( self::OPTION );
	}

	/**
	 * The site IDs on the list.
	 *
	 * @return int[]
	 */
	private static function ids() {
		$ids = get_site_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
