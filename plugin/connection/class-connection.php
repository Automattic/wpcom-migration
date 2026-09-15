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
use Automattic\WPCOM_Migration\Reprint\Exporter;
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

		add_action( 'jetpack_client_authorized', array( __CLASS__, 'on_user_authorized' ) );
		add_action( 'jetpack_client_authorize_error', array( __CLASS__, 'on_authorize_error' ) );
	}

	/**
	 * Records the package's success event after the user returns from
	 * WordPress.com.
	 *
	 * @param int $blog_id The WordPress.com blog ID.
	 */
	public static function on_user_authorized( $blog_id ) {
		self::record_event(
			'user_authorized',
			array(
				'blog_id' => (int) $blog_id,
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

		return $manager->get_authorization_url( wp_get_current_user(), $return_url );
	}

	/**
	 * Removes this plugin from the connection and discards the Reprint
	 * credentials. If no other plugin uses the connection, the site
	 * disconnects from WordPress.com.
	 */
	public static function disconnect() {
		$was_connected = self::is_site_connected();

		if ( $was_connected ) {
			self::manager()->remove_connection();
		}

		Exporter::discard_credentials();

		if ( $was_connected ) {
			self::record_event( 'disconnected', array( 'user_id' => get_current_user_id() ) );
		}
	}
}
