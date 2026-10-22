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
 * with the blog token when only the site is connected, and stores it until
 * it expires. A failed fetch keeps the stored answer, or stores the default
 * URL when there is none, and retries after RETRY_AFTER. Without a
 * connection, or on a network, nothing is asked.
 */
class Continue_Url {

	/**
	 * Option holding { url, matched, expires_at }.
	 *
	 * @var string
	 */
	const OPTION = 'wpcom_migration_continue_url';

	/**
	 * Where the button goes when WordPress.com knows no destination.
	 *
	 * @var string
	 */
	const DEFAULT_URL = 'https://my.wordpress.com/sites';

	/**
	 * Seconds until the next attempt after a failed fetch.
	 *
	 * @var int
	 */
	const RETRY_AFTER = 300;

	/**
	 * The answer for this request, { url, matched }, once known.
	 *
	 * @var array|null
	 */
	private static $answer = null;

	/**
	 * Filters wpcom_migration_continue_url.
	 *
	 * @param string $url The URL so far.
	 * @return string The destination WordPress.com gave, or $url when not
	 *                connected.
	 */
	public static function filter( $url ) {
		if ( is_multisite() || ! Connection::is_site_connected() ) {
			return $url;
		}

		return self::answer()['url'];
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

		$answer = self::answer();

		return $answer['matched'] ? $answer['url'] : null;
	}

	/**
	 * Deletes the stored answer, so the next check asks again. Runs on
	 * disconnect, which deactivation runs too, and when a user authorizes
	 * the connection.
	 */
	public static function forget() {
		self::$answer = null;
		delete_option( self::OPTION );
	}

	/**
	 * The answer for this request, looked up once.
	 *
	 * @return array { url, matched }
	 */
	private static function answer() {
		if ( null === self::$answer ) {
			self::$answer = self::lookup();
		}

		return self::$answer;
	}

	/**
	 * The stored answer while it is fresh, else a fetched one.
	 *
	 * @return array { url, matched }
	 */
	private static function lookup() {
		$stored     = get_option( self::OPTION );
		$has_stored = is_array( $stored ) && isset( $stored['url'], $stored['expires_at'] ) && is_string( $stored['url'] );
		$answer     = array(
			'url'     => $has_stored ? $stored['url'] : self::DEFAULT_URL,
			'matched' => $has_stored && ! empty( $stored['matched'] ),
		);

		if ( $has_stored && (int) $stored['expires_at'] > time() ) {
			return $answer;
		}

		$fetched = self::fetch();
		if ( null === $fetched ) {
			$ttl = self::RETRY_AFTER;
		} else {
			$answer = array(
				'url'     => $fetched['url'],
				'matched' => $fetched['matched'],
			);
			$ttl    = $fetched['ttl'];
		}

		update_option( self::OPTION, $answer + array( 'expires_at' => time() + $ttl ), false );

		return $answer;
	}

	/**
	 * Asks WordPress.com, as the current user when they are connected.
	 *
	 * @return array|null { url, matched, ttl } with ttl 300 when missing
	 *                    and clamped to a minute … a day, or null when the
	 *                    answer is missing or not understood.
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
		if ( ! is_array( $body ) || ! isset( $body['url'] ) || ! is_string( $body['url'] ) || '' === $body['url'] ) {
			return null;
		}

		$ttl = isset( $body['ttl'] ) && is_int( $body['ttl'] ) ? $body['ttl'] : 300;

		return array(
			'url'     => $body['url'],
			'matched' => true === ( $body['matched'] ?? false ),
			'ttl'     => max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $ttl ) ),
		);
	}
}
