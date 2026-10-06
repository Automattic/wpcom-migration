<?php
/**
 * REST routes WordPress.com calls to provision the exporter.
 *
 * Two ways in: core's own authentication (an application password, or a
 * cookie with a valid REST nonce) or a Jetpack user token. Either way the
 * caller must be an administrator. A user set by anything else is refused.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use Automattic\Jetpack\Connection\Rest_Authentication;
use InvalidArgumentException;
use WordPress\Reprint\Server\PublicKeyServer;
use WordPress\Reprint\Server\Utils;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST wpcom-migration/v1/reprint/rotate-export-secret, install-public-key and
 * enable-export.
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
	 * Registers the routes. Always registered: the permission check refuses
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
			'/' . $this->rest_base . '/install-public-key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_public_key' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'public_key' => array(
							'description' => __( 'RSA public key, as PEM or one line of base64.', 'wpcom-migration' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
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
	 * @return WP_REST_Response|WP_Error The new secret, or a 500 or 501.
	 */
	public function rotate_secret() {
		// Where OpenSSL can verify keys, Reprint signs with them and its
		// servers stop accepting secrets, so a secret issued here would go
		// unused.
		if ( Utils::key_auth_required() ) {
			return new WP_Error(
				'wpcom_migration_secret_auth_unsupported',
				__( 'This site verifies exports with public keys. Install a public key instead.', 'wpcom-migration' ),
				array( 'status' => 501 )
			);
		}

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
	 * Replaces the enrolled public key and returns its key id.
	 *
	 * Reprint checks and canonicalizes the key, so the id returned is the one
	 * the client computes from its private key.
	 *
	 * @param WP_REST_Request $request The request, carrying public_key.
	 * @return WP_REST_Response|WP_Error The key id, or a 400, 500 or 501.
	 */
	public function install_public_key( WP_REST_Request $request ) {
		if ( ! Utils::key_auth_required() ) {
			return new WP_Error(
				'wpcom_migration_key_auth_unsupported',
				__( 'This site cannot verify public keys. Rotate the export secret instead.', 'wpcom-migration' ),
				array( 'status' => 501 )
			);
		}

		try {
			$public_key = PublicKeyServer::assert_valid_public_key( (string) $request['public_key'] );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error(
				'wpcom_migration_invalid_public_key',
				$exception->getMessage(),
				array( 'status' => 400 )
			);
		}

		if ( ! Exporter::store_public_key( $public_key ) ) {
			return new WP_Error(
				'wpcom_migration_public_key_not_stored',
				__( 'Failed to persist the public key.', 'wpcom-migration' ),
				array( 'status' => 500 )
			);
		}

		$key_id = Utils::public_key_fingerprint( $public_key );

		Exporter::record_event(
			'public_key_installed',
			array(
				'user_id' => get_current_user_id(),
				'key_id'  => $key_id,
			)
		);

		return new WP_REST_Response(
			array(
				'key_id'     => $key_id,
				'export_url' => $this->export_url(),
			),
			200
		);
	}

	/**
	 * Opens the export window without rotating the secret, so a caller that
	 * already has a credential can reopen a window that closed.
	 *
	 * @return WP_REST_Response|WP_Error The unix time the window opened at.
	 */
	public function enable_export() {
		$state = Exporter::get_state();
		if ( ! $state['credential_valid'] ) {
			return new WP_Error(
				'wpcom_migration_no_credential',
				__( 'Install a public key or save a secret first.', 'wpcom-migration' ),
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
	 * An administrator, authenticated by a Jetpack user token or by core.
	 *
	 * A role check, not a capability one: this installs a credential that
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

		// Two ways in, neither weaker than the other: a Jetpack user token
		// (WordPress.com calling a connected site) or core's own
		// authentication (an application password, or a cookie with a valid
		// REST nonce). A user set by anything else — a plugin forcing a
		// login on every request, say — is refused.
		if ( ! Rest_Authentication::is_signed_with_user_token() && ! self::is_wordpress_authenticated() ) {
			return false;
		}

		return in_array( 'administrator', $user->roles, true );
	}

	/**
	 * Whether core, not Jetpack, authenticated the current user: an
	 * application password validated on this request, or the REST cookie
	 * nonce, checked the way core's rest_cookie_check_errors() checks it.
	 *
	 * @return bool
	 */
	private static function is_wordpress_authenticated() {
		if ( Rest_Authentication::is_signed_with_user_token() || Rest_Authentication::is_signed_with_blog_token() ) {
			return false;
		}

		if ( null !== rest_get_authenticated_app_password() ) {
			return true;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is the nonce check.
		$nonce = null;
		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
		}
		// phpcs:enable

		return null !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
