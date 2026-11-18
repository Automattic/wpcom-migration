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
 * Every write here, adding or removing, needs Exporter's guard held open:
 * the guard turns any other write into a rewrite of the stored list.
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
	 * Adds the current site.
	 *
	 * Two sites joining at once can each write the list without the other,
	 * so the add is read back from the database and tried once more when
	 * another write won.
	 *
	 * @return bool Whether the site is on the list.
	 */
	public static function add_current() {
		$site_id = get_current_blog_id();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$ids = self::ids( true );
			if ( in_array( $site_id, $ids, true ) ) {
				return true;
			}

			$ids[] = $site_id;
			update_site_option( self::OPTION, $ids );
		}

		return in_array( $site_id, self::ids( true ), true );
	}

	/**
	 * Removes a site. Deletes the list once it is empty.
	 *
	 * @param int $site_id The site's ID.
	 */
	public static function remove( $site_id ) {
		$listed = self::ids();
		$ids    = array_values( array_diff( $listed, array( (int) $site_id ) ) );
		if ( $ids === $listed ) {
			return;
		}

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
	 * @param bool $fresh Whether to skip the object cache and read the
	 *                    database, to see another request's write.
	 * @return int[]
	 */
	private static function ids( $fresh = false ) {
		if ( $fresh ) {
			$network_id = get_current_network_id();
			wp_cache_delete( $network_id . ':' . self::OPTION, 'site-options' );
			wp_cache_delete( $network_id . ':notoptions', 'site-options' );
		}

		$ids = get_site_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
