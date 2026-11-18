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
 * administrator on a single site, a super admin on a network.
 *
 * A role check, not a capability one: migrating hands WordPress.com the
 * whole database and file tree, and plugins grant manage_options to roles
 * that should not be able to do that. On a network, an export also carries
 * the shared plugins and themes and the network accounts of the site's
 * members, which a site's own administrator has no say over. A super admin
 * often has no role on the site at all.
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

		if ( is_multisite() ) {
			return is_super_admin( $user->ID );
		}

		return in_array( 'administrator', $user->roles, true );
	}

	/**
	 * Why the current user may not, for a screen to show.
	 *
	 * @return string
	 */
	public static function refusal() {
		return is_multisite()
			? __( 'Only a super admin can migrate a site of this network.', 'wpcom-migration' )
			: __( 'Only an administrator can migrate this site.', 'wpcom-migration' );
	}
}
