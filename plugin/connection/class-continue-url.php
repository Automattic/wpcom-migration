<?php
/**
 * Where the screen's WordPress.com button leads, as WordPress.com decides.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration;

use Automattic\Jetpack\Connection\Client;

/**
 * Asks WordPress.com which of its sites this site is migrating into and
 * points the screen's button there.
 *
 * Fetches { matched, url, ttl } signed with the current user's token, since
 * the answer is theirs: it searches their sites. Keeps the matched URL, or
 * none, in a transient per user until the ttl runs out. A failed fetch keeps
 * none, so the button gets the default link, and asks again after
 * RETRY_AFTER. Without a connected user, or on a network, nothing is asked:
 * the screen only links to WordPress.com for a connected user.
 */
class Continue_Url {

	/**
	 * Transient name, before the user ID, holding { destination }.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wpcom_migration_continue_url_';

	/**
	 * The site-wide option holding { destination, expires_at } that versions
	 * before the per-user transient kept.
	 *
	 * @var string
	 */
	const LEGACY_OPTION = 'wpcom_migration_continue_url';

	/**
	 * Seconds until the next attempt after a failed fetch.
	 *
	 * @var int
	 */
	const RETRY_AFTER = 300;

	/**
	 * The destination for this request: a URL, null when there is none, or
	 * false until looked up.
	 *
	 * @var string|null|false
	 */
	private static $destination = false;

	/**
	 * Filters wpcom_migration_continue_url.
	 *
	 * @param string $url The URL so far.
	 * @return string The destination site's URL, or $url when there is none.
	 */
	public static function filter( $url ) {
		return self::destination() ?? $url;
	}

	/**
	 * The destination site's URL, when WordPress.com matched one.
	 *
	 * @return string|null
	 */
	public static function destination() {
		if ( is_multisite() || ! Connection::is_user_connected() ) {
			return null;
		}

		if ( false === self::$destination ) {
			self::$destination = self::lookup();
		}

		return self::$destination;
	}

	/**
	 * Deletes the current user's stored answer, so the next check asks
	 * again. Runs when they authorize the connection or disconnect it.
	 */
	public static function forget() {
		self::$destination = false;
		delete_transient( self::transient() );
	}

	/**
	 * Deletes every user's stored answer, and the legacy site-wide one. Runs
	 * on deactivation, which removing the plugin always comes after. With a
	 * persistent object cache the transients aren't in the database; they run
	 * out with their ttl.
	 */
	public static function forget_all() {
		global $wpdb;

		self::$destination = false;
		delete_option( self::LEGACY_OPTION );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API deletes transients by prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%'
			)
		);
	}

	/**
	 * The current user's transient name.
	 *
	 * @return string
	 */
	public static function transient() {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * The stored destination while it is fresh, else a fetched one.
	 *
	 * @return string|null
	 */
	private static function lookup() {
		$stored = get_transient( self::transient() );
		if ( is_array( $stored ) && array_key_exists( 'destination', $stored ) ) {
			return is_string( $stored['destination'] ) ? $stored['destination'] : null;
		}

		$answer      = self::fetch();
		$destination = null === $answer ? null : $answer['destination'];

		set_transient( self::transient(), array( 'destination' => $destination ), null === $answer ? self::RETRY_AFTER : $answer['ttl'] );

		return $destination;
	}

	/**
	 * Asks WordPress.com, as the current user.
	 *
	 * @return array|null { destination, ttl }, destination null without a
	 *                    match and ttl 300 when missing and clamped to a
	 *                    minute … a day, or null when the answer is missing
	 *                    or not understood.
	 */
	private static function fetch() {
		$path = add_query_arg( 'source_url', rawurlencode( home_url() ), '/migration-plugin/destination' );
		$args = array(
			'method'      => 'GET',
			'timeout'     => 2,
			// Each hop gets its own timeout; the endpoint never redirects.
			'redirection' => 0,
		);

		$response = Client::wpcom_json_api_request_as_user( $path, '2', $args, null, 'wpcom' );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['matched'] ) || ! is_bool( $body['matched'] ) ) {
			return null;
		}

		$destination = null;
		if ( $body['matched'] ) {
			if ( ! isset( $body['url'] ) || ! is_string( $body['url'] ) || '' === $body['url'] ) {
				return null;
			}
			$destination = $body['url'];
		}

		$ttl = isset( $body['ttl'] ) && is_int( $body['ttl'] ) ? $body['ttl'] : 300;

		return array(
			'destination' => $destination,
			'ttl'         => max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $ttl ) ),
		);
	}
}
