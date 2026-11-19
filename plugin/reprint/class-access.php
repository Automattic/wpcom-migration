<?php
/**
 * Who may migrate the site.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use WP_User;

/**
 * The one answer every screen, form handler and REST route gives: an
 * administrator on a single site; on a network, a super admin who is also
 * an administrator of the site.
 *
 * A role check, not a capability one: migrating hands WordPress.com the
 * whole database and file tree, and plugins grant manage_options to roles
 * that should not be able to do that. On a network, an export also carries
 * the shared plugins and themes and the network accounts of the site's
 * members, which a site's own administrator has no say over.
 *
 * The super admin must also be an administrator of the site because the
 * export carries only the site's own users: its members and the authors of
 * its content. A super admin with no role on the site would be missing from
 * the migrated site, and WordPress.com could not match its account to
 * theirs or find an administrator there.
 */
class Access {

	/**
	 * Whether the user may migrate the current site.
	 *
	 * @param WP_User|null $user The user, or null for the current one.
	 * @return bool
	 */
	public static function can_migrate( ?WP_User $user = null ) {
		$user = $user ?? wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}

		if ( is_multisite() && ! is_super_admin( $user->ID ) ) {
			return false;
		}

		return self::administers_site( $user );
	}

	/**
	 * Why the current user may not, for a screen to show.
	 *
	 * @return string
	 */
	public static function refusal() {
		if ( ! is_multisite() ) {
			return __( 'Only an administrator can migrate this site.', 'wpcom-migration' );
		}

		if ( is_super_admin() ) {
			return __( 'To migrate this site, first add yourself as one of its administrators: in Network Admin, go to Sites, edit this site, and add yourself under Users.', 'wpcom-migration' );
		}

		return __( 'Only a super admin who is also an administrator of this site can migrate it.', 'wpcom-migration' );
	}

	/**
	 * Whether the user has the administrator role on the current site.
	 *
	 * Reads the roles for the current site, not those WP_User was loaded
	 * with, which belong to whichever site was current then.
	 *
	 * @param WP_User $user The user.
	 * @return bool
	 */
	private static function administers_site( WP_User $user ) {
		$site_user = new WP_User( $user->ID, '', get_current_blog_id() );
		return in_array( 'administrator', $site_user->roles, true );
	}
}
