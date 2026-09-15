<?php
/**
 * REST routes WordPress.com calls to provision the Reprint exporter.
 *
 * A port of Jetpack's Reprint_Export\REST_Controller under this plugin's
 * namespace, with export_url added to both responses.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration;

use Automattic\Jetpack\Connection\Rest_Authentication;
use Automattic\WPCOM_Migration\Reprint\Exporter;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST wpcom-migration/v1/reprint/rotate-export-secret and enable-export.
 */
class REST_Controller extends WP_REST_Controller {

	/**
	 * The API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wpcom-migration/v1';

	/**
	 * The REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'reprint';

	/**
	 * Registers both routes. Always registered: the permission check refuses
	 * unsigned callers, and a 404 would read as "plugin absent".
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rotate-export-secret',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rotate_secret' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/enable-export',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enable_export' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * The export endpoint, so the caller need not know the query-arg
	 * convention.
	 *
	 * @return string
	 */
	public function export_url() {
		return home_url( '/?' . Exporter::QUERY_VAR );
	}

	/**
	 * Rotates the shared secret and returns it.
	 *
	 * Uses random_bytes() rather than wp_generate_password(): that helper is
	 * for passwords a person types, and sites can filter it through
	 * `random_password`, an extension point a credential should not have.
	 *
	 * @return WP_REST_Response The new secret, or a 500.
	 */
	public function rotate_secret() {
		$secret = bin2hex( random_bytes( 32 ) );

		if ( ! Exporter::store_secret( $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'Failed to persist the new secret.' ), 500 );
		}

		Exporter::record_event( 'secret_rotated', array( 'user_id' => get_current_user_id() ) );

		return new WP_REST_Response(
			array(
				'secret'     => $secret,
				'export_url' => $this->export_url(),
			),
			200
		);
	}

	/**
	 * Opens the export window without rotating the secret, so a caller that
	 * already has one can reopen a window that closed.
	 *
	 * @return WP_REST_Response The unix time the window opened at.
	 */
	public function enable_export() {
		$enabled_at = Exporter::open_export_window();

		Exporter::record_event( 'window_opened', array( 'user_id' => get_current_user_id() ) );

		return new WP_REST_Response(
			array(
				'enabled_at' => $enabled_at,
				'export_url' => $this->export_url(),
			),
			200
		);
	}

	/**
	 * A request signed with a user token, for a site administrator.
	 *
	 * A role check, not a capability one: this hands out a secret that
	 * streams the whole database and file tree, and no capability says that.
	 * `manage_options` is the closest, and plugins grant it to shop managers.
	 * Blog-token requests are refused: the secret must be tied to a person
	 * who authorized this.
	 *
	 * @return bool
	 */
	public function permission_check() {
		if ( ! Rest_Authentication::is_signed_with_user_token() ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		// Network administrator only: a subsite administrator would leave with
		// every other site's users, content and uploads.
		if ( is_multisite() ) {
			return is_super_admin( $user->ID );
		}

		return in_array( 'administrator', $user->roles, true );
	}
}
