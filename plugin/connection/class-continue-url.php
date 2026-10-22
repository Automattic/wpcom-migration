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
 * Fetches { matched, url, ttl } signed with the current user's token, or
 * with the blog token when only the site is connected, and stores the
 * matched URL, or none, until it expires. A failed fetch keeps the stored
 * answer and retries after RETRY_AFTER. Without a connection, or on a
 * network, nothing is asked.
 */
class Continue_Url {

	/**
	 * Option holding { destination, expires_at }.
	 *
	 * @var string
	 */
	const OPTION = 'wpcom_migration_continue_url';

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
		if ( is_multisite() || ! Connection::is_site_connected() ) {
			return null;
		}

		if ( false === self::$destination ) {
			self::$destination = self::lookup();
		}

		return self::$destination;
	}

	/**
	 * Deletes the stored answer, so the next check asks again. Runs on
	 * disconnect, which deactivation runs too, and when a user authorizes
	 * the connection.
	 */
	public static function forget() {
		self::$destination = false;
		delete_option( self::OPTION );
	}

	/**
	 * The stored destination while it is fresh, else a fetched one.
	 *
	 * @return string|null
	 */
	private static function lookup() {
		$stored      = get_option( self::OPTION );
		$has_stored  = is_array( $stored ) && array_key_exists( 'destination', $stored ) && isset( $stored['expires_at'] );
		$destination = $has_stored && is_string( $stored['destination'] ) ? $stored['destination'] : null;

		if ( $has_stored && (int) $stored['expires_at'] > time() ) {
			return $destination;
		}

		$answer = self::fetch();
		if ( null === $answer ) {
			$ttl = self::RETRY_AFTER;
		} else {
			$destination = $answer['destination'];
			$ttl         = $answer['ttl'];
		}

		update_option(
			self::OPTION,
			array(
				'destination' => $destination,
				'expires_at'  => time() + $ttl,
			),
			false
		);

		return $destination;
	}

	/**
	 * Asks WordPress.com, as the current user when they are connected.
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

		$response = Connection::is_user_connected()
			? Client::wpcom_json_api_request_as_user( $path, '2', $args, null, 'wpcom' )
			: Client::wpcom_json_api_request_as_blog( $path, '2', $args, null, 'wpcom' );

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
