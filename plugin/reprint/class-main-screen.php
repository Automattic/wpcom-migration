<?php
/**
 * Which screen the plugin's entry points lead to, as WordPress.com decides.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * Fetches { main_screen, ttl } from WordPress.com, stores it until it
 * expires, and answers whether the Reprint screen is the main one.
 *
 * A failed fetch keeps the stored answer, or stores blogvault when there is
 * none, and retries after RETRY_AFTER. Multisite is always blogvault.
 */
class Main_Screen {

	/**
	 * The flag endpoint, without its query.
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://public-api.wordpress.com/wpcom/v2/migration-plugin-config';

	/**
	 * Option holding { main_screen, expires_at }.
	 *
	 * @var string
	 */
	const OPTION = 'wpcom_migration_main_screen';

	/**
	 * Seconds to wait for the endpoint.
	 *
	 * @var int
	 */
	const TIMEOUT = 2;

	/**
	 * Seconds to keep an answer that came without a usable ttl.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 300;

	/**
	 * Shortest ttl honoured.
	 *
	 * @var int
	 */
	const MIN_TTL = 60;

	/**
	 * Longest ttl honoured.
	 *
	 * @var int
	 */
	const MAX_TTL = 86400;

	/**
	 * Seconds until the next attempt after a failed fetch.
	 *
	 * @var int
	 */
	const RETRY_AFTER = 300;

	/**
	 * The answer for this request, once known.
	 *
	 * @var bool|null
	 */
	private static $is_reprint = null;

	/**
	 * Whether the Reprint screen is the main one. Fetches when the stored
	 * answer is missing or expired.
	 *
	 * @return bool
	 */
	public static function is_reprint() {
		if ( is_multisite() ) {
			return false;
		}

		if ( null === self::$is_reprint ) {
			self::$is_reprint = 'reprint' === self::main_screen();
		}

		return self::$is_reprint;
	}

	/**
	 * Deletes the stored answer, so the next check asks again. Runs on
	 * activation and deactivation.
	 */
	public static function forget() {
		self::$is_reprint = null;
		delete_option( self::OPTION );
	}

	/**
	 * The stored answer while it is fresh, else a fetched one.
	 *
	 * @return string 'reprint' or 'blogvault'.
	 */
	private static function main_screen() {
		$stored      = get_option( self::OPTION );
		$has_stored  = is_array( $stored ) && isset( $stored['main_screen'], $stored['expires_at'] );
		$main_screen = $has_stored && 'reprint' === $stored['main_screen'] ? 'reprint' : 'blogvault';

		if ( $has_stored && (int) $stored['expires_at'] > time() ) {
			return $main_screen;
		}

		$answer = self::fetch();
		if ( null === $answer ) {
			$ttl = self::RETRY_AFTER;
		} else {
			$main_screen = $answer['main_screen'];
			$ttl         = $answer['ttl'];
		}

		update_option(
			self::OPTION,
			array(
				'main_screen' => $main_screen,
				'expires_at'  => time() + $ttl,
			),
			false
		);

		return $main_screen;
	}

	/**
	 * Asks WordPress.com.
	 *
	 * @return array|null { main_screen, ttl } with ttl clamped, or null when
	 *                    the answer is missing or not understood.
	 */
	private static function fetch() {
		$plugin_data = get_file_data( dirname( __DIR__ ) . '/wpcom_migration.php', array( 'Version' => 'Version' ) );
		$response    = wp_remote_get(
			add_query_arg( 'plugin_version', rawurlencode( $plugin_data['Version'] ), self::ENDPOINT ),
			array(
				'timeout'     => self::TIMEOUT,
				// Each hop gets its own timeout; the endpoint never redirects.
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['main_screen'] ) || ! in_array( $body['main_screen'], array( 'reprint', 'blogvault' ), true ) ) {
			return null;
		}

		$ttl = isset( $body['ttl'] ) && is_int( $body['ttl'] ) ? $body['ttl'] : self::DEFAULT_TTL;

		return array(
			'main_screen' => $body['main_screen'],
			'ttl'         => max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) ),
		);
	}
}
