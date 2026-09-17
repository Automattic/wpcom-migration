<?php
/**
 * REST routes WordPress.com calls to provision the exporter.
 *
 * WordPress.com holds an application password an administrator approved on
 * core's authorize-application screen, installs the plugin through
 * wp/v2/plugins, then calls these two routes with basic auth. Core's own
 * authentication decides who the caller is; this class only checks that it
 * is an administrator.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use WP_Error;
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
	 * anyone else, and a 404 would read as "plugin absent".
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
	 * @return WP_REST_Response|WP_Error The new secret, or a 500.
	 */
	public function rotate_secret() {
		$secret = bin2hex( random_bytes( 32 ) );

		if ( ! Exporter::store_secret( $secret ) ) {
			return new WP_Error(
				'wpcom_migration_secret_not_stored',
				__( 'Failed to persist the new secret.', 'wpcom-migration' ),
				array( 'status' => 500 )
			);
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
	 * @return WP_REST_Response|WP_Error The unix time the window opened at.
	 */
	public function enable_export() {
		$state = Exporter::get_state();
		if ( ! $state['secret_valid'] ) {
			return new WP_Error(
				'wpcom_migration_no_secret',
				__( 'Save a secret first.', 'wpcom-migration' ),
				array( 'status' => 409 )
			);
		}

		$enabled_at = Exporter::open_export_window();

		if ( ! Exporter::is_export_window_open() ) {
			return new WP_Error(
				'wpcom_migration_window_not_opened',
				__( 'Failed to open the export window.', 'wpcom-migration' ),
				array( 'status' => 500 )
			);
		}

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
	 * An authenticated site administrator.
	 *
	 * A role check, not a capability one: this hands out a secret that
	 * streams the whole database and file tree, and no capability says that.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		// The exporter never serves on a network; refuse here rather than
		// let both routes answer 200 for nothing.
		if ( is_multisite() ) {
			return new WP_Error(
				'wpcom_migration_multisite_unsupported',
				__( 'The exporter is not supported on networks.', 'wpcom-migration' ),
				array( 'status' => 501 )
			);
		}

		return in_array( 'administrator', $user->roles, true );
	}
}
