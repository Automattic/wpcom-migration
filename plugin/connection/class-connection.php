<?php
/**
 * WordPress.com connection glue: package setup, connect, disconnect, queries.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration;

use Automattic\Jetpack\Config;
use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Connection\Rest_Authentication;
use WP_Error;

/**
 * Thin wrapper around the connection package so the screen and the REST
 * controller never touch Manager directly.
 */
class Connection {

	/**
	 * Action fired for every connection event.
	 *
	 * @var string
	 */
	const EVENT_ACTION = 'wpcom_migration_connection_event';

	/**
	 * The `from` WordPress.com's authorize page gets: a standard entry-point
	 * name (lowercase, underscores, no hyphens).
	 *
	 * @var string
	 */
	const OAUTH_ENTRY_POINT = 'wpcom_migration';

	/**
	 * Tells the connection package about this plugin and hooks REST
	 * authentication.
	 *
	 * Runs at plugin load: Config acts on plugins_loaded at priority 2, so it
	 * must be told before then. ensure() hooks XML-RPC, the authorize webhook
	 * and Plugin_Storage; it does not hook REST authentication, so every
	 * standalone Jetpack plugin calls Rest_Authentication::init() itself.
	 */
	public static function init() {
		( new Config() )->ensure(
			'connection',
			array(
				'slug'     => WPCOM_MIGRATION_SLUG,
				'name'     => WPCOM_MIGRATION_NAME,
				'url_info' => WPCOM_MIGRATION_URI,
			)
		);
		Rest_Authentication::init();

		add_action( 'jetpack_user_authorized', array( __CLASS__, 'on_user_authorized' ) );
		add_action( 'jetpack_client_authorize_error', array( __CLASS__, 'on_authorize_error' ) );
	}

	/**
	 * Records the package's success event once the user's token is stored.
	 */
	public static function on_user_authorized() {
		self::record_event(
			'user_authorized',
			array(
				'blog_id' => (int) self::blog_id(),
				'user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Records the package's failure event after the user returns from
	 * WordPress.com. The package's own fallback redirect stands.
	 *
	 * @param WP_Error $error The authorization error.
	 */
	public static function on_authorize_error( $error ) {
		self::record_event(
			'authorize_failed',
			array( 'code' => is_wp_error( $error ) ? $error->get_error_code() : 'unknown' )
		);
	}

	/**
	 * Fires the event action. No context value carries a token or secret.
	 *
	 * @param string $event   Event name.
	 * @param array  $context Event context.
	 */
	public static function record_event( $event, array $context = array() ) {
		/**
		 * Fires when the WordPress.com connection changes or fails to.
		 *
		 * No event carries a token or secret.
		 *
		 * @param string $event   One of site_registered, registration_failed,
		 *                        user_authorized, authorize_failed, disconnected.
		 * @param array  $context Details of the event.
		 */
		do_action( 'wpcom_migration_connection_event', $event, $context );
	}

	/**
	 * A Manager bound to this plugin's slug, so plugin-scoped calls such as
	 * remove_connection() know which plugin is speaking.
	 *
	 * @return Manager
	 */
	private static function manager() {
		return new Manager( WPCOM_MIGRATION_SLUG );
	}

	/**
	 * Whether the site holds a blog token and ID.
	 *
	 * @return bool
	 */
	public static function is_site_connected() {
		return self::manager()->is_connected();
	}

	/**
	 * Whether the current user holds a user token.
	 *
	 * @return bool
	 */
	public static function is_user_connected() {
		return self::manager()->is_user_connected();
	}

	/**
	 * The connected WordPress.com user's data (login, email), if known.
	 *
	 * The package caches this for a day and otherwise asks WordPress.com;
	 * when that fails it returns false, passed on here as null.
	 *
	 * @return array|null
	 */
	public static function connected_wpcom_user() {
		$user_data = self::manager()->get_connected_user_data();

		return is_array( $user_data ) ? $user_data : null;
	}

	/**
	 * The WordPress.com blog ID, if the site is registered.
	 *
	 * @return int|null
	 */
	public static function blog_id() {
		$blog_id = Manager::get_site_id( true );

		return is_wp_error( $blog_id ) || ! $blog_id ? null : (int) $blog_id;
	}

	/**
	 * Registers the site if needed and returns the WordPress.com URL that
	 * asks the current user to authorize the connection.
	 *
	 * Registration records terms-of-service agreement; the form that leads
	 * here carries the terms line. No redirect happens here so the caller
	 * can decide how to send the user on and how to report a failure.
	 *
	 * @param string $return_url Where WordPress.com sends the user back to.
	 * @return string|WP_Error The authorization URL, or why registration failed.
	 */
	public static function authorization_url( $return_url ) {
		$manager = self::manager();

		if ( ! $manager->is_connected() ) {
			$registered = $manager->try_registration( true );

			if ( is_wp_error( $registered ) ) {
				self::record_event( 'registration_failed', array( 'code' => $registered->get_error_code() ) );
				return $registered;
			}
			if ( true !== $registered ) {
				self::record_event( 'registration_failed', array( 'code' => 'registration_returned_false' ) );
				return new WP_Error( 'registration_failed', 'Registration with WordPress.com failed.' );
			}

			self::record_event( 'site_registered', array( 'user_id' => get_current_user_id() ) );
		}

		$authorization_url = $manager->get_authorization_url( wp_get_current_user(), $return_url, self::OAUTH_ENTRY_POINT );

		// WordPress.com's authorize page sends the browser back to
		// redirect_after_auth; without skip_pricing it goes to the Jetpack
		// plans page first.
		return add_query_arg(
			array(
				'redirect_after_auth' => rawurlencode( $return_url ),
				'skip_pricing'        => '1',
			),
			$authorization_url
		);
	}

	/**
	 * Names of the other active plugins that use the connection, such as
	 * Jetpack. While there are any, disconnect() leaves the connection: it
	 * asks the package the same question before removing it.
	 *
	 * @return string[]
	 */
	public static function other_connected_plugins() {
		$plugins = self::manager()->get_connected_plugins();
		if ( ! is_array( $plugins ) ) {
			return array();
		}

		unset( $plugins[ WPCOM_MIGRATION_SLUG ] );

		$names = array();
		foreach ( $plugins as $slug => $plugin ) {
			$names[] = isset( $plugin['name'] ) && '' !== $plugin['name'] ? $plugin['name'] : $slug;
		}

		return $names;
	}

	/**
	 * Disconnects the site from WordPress.com unless another plugin, such as
	 * Jetpack, uses the connection, and forgets the stored destination URL.
	 * The migration key stays: only deactivating the plugin discards it.
	 */
	public static function disconnect() {
		$was_connected = self::is_site_connected();

		if ( $was_connected ) {
			self::manager()->remove_connection();
		}

		Continue_Url::forget();

		if ( $was_connected && ! self::is_site_connected() ) {
			self::record_event( 'disconnected', array( 'user_id' => get_current_user_id() ) );
		}
	}
}
