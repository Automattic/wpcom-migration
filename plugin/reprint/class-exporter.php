<?php
/**
 * Signed Reprint export.
 *
 * Modeled on Jetpack's Reprint_Exporter (Automattic/jetpack#52278).
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use WordPress\Reprint\Server\HTTPServer;
use WordPress\Reprint\Server\PublicKeyServer;
use WordPress\Reprint\Server\RequestAuthenticator;
use WordPress\Reprint\Server\Utils;

/**
 * Owns the export credentials and answers ?reprint-api-wpcom-migration.
 */
class Exporter {

	/**
	 * Query argument that selects the export endpoint.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'reprint-api-wpcom-migration';

	/**
	 * Option holding the HMAC shared secret.
	 *
	 * @var string
	 */
	const SECRET_OPTION = 'wpcom_migration_reprint_secret';

	/**
	 * Option holding the enrolled public key, in one-line form.
	 *
	 * @var string
	 */
	const PUBLIC_KEY_OPTION = 'wpcom_migration_reprint_public_key';

	/**
	 * Option holding how the public key got here: 'api' when WordPress.com
	 * installed it through the REST route, 'pasted' when an administrator
	 * pasted it into wp-admin.
	 *
	 * @var string
	 */
	const PUBLIC_KEY_SOURCE_OPTION = 'wpcom_migration_reprint_public_key_source';

	/**
	 * The options only this class may write.
	 *
	 * @var string[]
	 */
	const GUARDED_OPTIONS = array(
		self::SECRET_OPTION,
		self::PUBLIC_KEY_OPTION,
	);

	/**
	 * Options earlier versions kept, deleted along with the credentials.
	 *
	 * @var string[]
	 */
	const OBSOLETE_OPTIONS = array(
		'wpcom_migration_reprint_secret_hash',
		'wpcom_migration_reprint_public_key_hash',
	);

	/**
	 * Clock-skew tolerance, in seconds, allowed for request signatures.
	 *
	 * @var int
	 */
	const CLOCK_SKEW = 300;

	/**
	 * Action fired for export events.
	 *
	 * @var string
	 */
	const EVENT_ACTION = 'wpcom_migration_reprint_export_event';

	/**
	 * Whether the exporter is in the middle of one of its own option writes.
	 *
	 * @var bool
	 */
	private static $writing_own_options = false;

	/**
	 * Registers the write veto and, on an export request, the handler.
	 *
	 * Runs at plugin-file load. The handler itself waits for plugins_loaded,
	 * once pluggable.php has defined the functions it may call. The lowest
	 * priority there is the earliest point the request can be answered.
	 */
	public static function maybe_init() {
		self::protect_options();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		add_action( 'plugins_loaded', array( new self(), 'handle_request' ), PHP_INT_MIN );
	}

	/**
	 * Blocks writes to the export options from anywhere but this class.
	 *
	 * Whoever sets one can export the whole site, since they pick the
	 * credential and can then sign their own requests. Allowed by where the
	 * write came from, not by who is logged in: the usual arbitrary-option-write
	 * bug is a form missing its nonce, running in an administrator's own
	 * session.
	 */
	public static function protect_options() {
		foreach ( self::GUARDED_OPTIONS as $option ) {
			// Last word: a later filter must not be able to reinstate the value.
			add_filter( "pre_update_option_{$option}", array( __CLASS__, 'veto_foreign_update' ), PHP_INT_MAX, 2 );
		}

		// add_option() has no filter that can cancel a write, only actions either
		// side of the insert, so stopping the request is the only lever.
		add_action( 'add_option', array( __CLASS__, 'veto_foreign_add' ), 10, 1 );
	}

	/**
	 * Cancels a foreign update by handing back the value already stored.
	 *
	 * @param mixed $value     The incoming value.
	 * @param mixed $old_value The value currently stored.
	 * @return mixed The incoming value for our own writes, the stored one otherwise.
	 */
	public static function veto_foreign_update( $value, $old_value ) {
		return self::$writing_own_options ? $value : $old_value;
	}

	/**
	 * Stops the request when something else tries to create a guarded option.
	 *
	 * @param string $option The option being added.
	 */
	public static function veto_foreign_add( $option ) {
		if ( ! in_array( $option, self::GUARDED_OPTIONS, true ) ) {
			return;
		}

		if ( self::$writing_own_options ) {
			return;
		}

		wp_die(
			esc_html__( 'Reprint export options can only be written by the Migrate to WordPress.com plugin.', 'wpcom-migration' ),
			esc_html__( 'Forbidden', 'wpcom-migration' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Writes one of the export options with the guard held open.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value to store.
	 * @return bool Whether the value was changed.
	 */
	private static function write_option( $option, $value ) {
		self::$writing_own_options = true;
		try {
			return update_option( $option, $value, false );
		} finally {
			self::$writing_own_options = false;
		}
	}

	/**
	 * Reports an export event.
	 *
	 * @param string $event   Event name.
	 * @param array  $context Details of the event.
	 */
	public static function record_event( $event, array $context = array() ) {
		/**
		 * Fires when the Reprint exporter serves, refuses, or changes state.
		 *
		 * A request the handler ignores fires nothing, and no event carries the
		 * secret or the signature.
		 *
		 * @param string $event   One of export_served, export_refused,
		 *                        secret_rotated, secret_removed,
		 *                        public_key_installed, public_key_removed,
		 *                        credentials_discarded.
		 * @param array  $context Details of the event.
		 */
		do_action( 'wpcom_migration_reprint_export_event', $event, $context );
	}

	/**
	 * Discards any stored export credentials.
	 *
	 * Runs on plugin activation and deactivation, clearing whatever was
	 * written while protect_options() was not in place; and on disconnect.
	 */
	public static function discard_credentials() {
		$had_any = false;
		foreach ( self::GUARDED_OPTIONS as $option ) {
			$had_any = delete_option( $option ) || $had_any;
		}
		delete_option( self::PUBLIC_KEY_SOURCE_OPTION );
		foreach ( self::OBSOLETE_OPTIONS as $option ) {
			delete_option( $option );
		}

		if ( $had_any ) {
			self::record_event(
				'credentials_discarded',
				array( 'boundary' => current_filter() )
			);
		}
	}

	/**
	 * Discards the stored secret, leaving any public key in place.
	 */
	public static function discard_secret() {
		self::discard_credential( self::SECRET_OPTION, 'secret_removed' );
	}

	/**
	 * Discards the stored public key, leaving any secret in place.
	 */
	public static function discard_public_key() {
		self::discard_credential( self::PUBLIC_KEY_OPTION, 'public_key_removed' );
		delete_option( self::PUBLIC_KEY_SOURCE_OPTION );
	}

	/**
	 * Deletes one credential.
	 *
	 * @param string $option The credential's option.
	 * @param string $event  Event to report when anything was deleted.
	 */
	private static function discard_credential( $option, $event ) {
		if ( delete_option( $option ) ) {
			self::record_event( $event, array( 'user_id' => get_current_user_id() ) );
		}
	}

	/**
	 * Stores a shared secret.
	 *
	 * @param string $secret The new secret.
	 * @return bool Whether the secret is now stored.
	 */
	public static function store_secret( $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return false;
		}

		self::write_option( self::SECRET_OPTION, $secret );

		// Read back rather than trust update_option(): it reports false for an
		// unchanged value.
		return get_option( self::SECRET_OPTION ) === $secret;
	}

	/**
	 * Stores a public key, replacing any key stored before.
	 *
	 * Takes the one-line form PublicKeyServer::assert_valid_public_key()
	 * returns. Saving a key leaves any secret in place.
	 *
	 * @param string $public_key The one-line public key.
	 * @param string $source     'api' or 'pasted'. See PUBLIC_KEY_SOURCE_OPTION.
	 * @return bool Whether the key is now stored.
	 */
	public static function store_public_key( $public_key, $source = 'pasted' ) {
		if ( ! is_string( $public_key ) || '' === $public_key ) {
			return false;
		}

		self::write_option( self::PUBLIC_KEY_OPTION, $public_key );
		if ( get_option( self::PUBLIC_KEY_OPTION ) !== $public_key ) {
			return false;
		}

		update_option( self::PUBLIC_KEY_SOURCE_OPTION, $source, false );
		return true;
	}

	/**
	 * How the stored public key got here.
	 *
	 * @return string 'api', 'pasted', or '' for a key stored before the
	 *                source was recorded.
	 */
	public static function public_key_source() {
		$source = get_option( self::PUBLIC_KEY_SOURCE_OPTION, '' );
		return in_array( $source, array( 'api', 'pasted' ), true ) ? $source : '';
	}

	/**
	 * Validates a pasted public key, stores it in place of any other and
	 * records the enrollment.
	 *
	 * Does not check Utils::key_auth_required(). Callers do. A key that is
	 * not usable throws PublicKeyServer's InvalidArgumentException.
	 *
	 * @param string $pasted_key The sanitized pasted key text.
	 * @return bool True when the key was stored, false when it was already enrolled.
	 * @throws Credential_Storage_Exception When the key could not be stored.
	 */
	public static function enroll_public_key( $pasted_key ): bool {
		$public_key = PublicKeyServer::assert_valid_public_key( $pasted_key );

		if ( get_option( self::PUBLIC_KEY_OPTION ) === $public_key ) {
			return false;
		}

		if ( ! self::store_public_key( $public_key, 'pasted' ) ) {
			throw new Credential_Storage_Exception( 'The public key could not be stored.' );
		}

		self::record_event(
			'public_key_installed',
			array(
				'user_id' => get_current_user_id(),
				'key_id'  => Utils::public_key_fingerprint( $public_key ),
			)
		);

		return true;
	}

	/**
	 * The state the screens render.
	 *
	 * The credential is the one this host verifies: the public key where
	 * Reprint requires keys, the secret elsewhere. The other one, if stored,
	 * counts for nothing.
	 *
	 * @return array {
	 *     @type bool $has_secret     Whether a secret is stored.
	 *     @type bool $has_public_key Whether a public key is stored.
	 *     @type bool $has_credential Whether the credential is stored, which
	 *                                turns the exporter on.
	 * }
	 */
	public static function get_state() {
		$secret         = get_option( self::SECRET_OPTION, '' );
		$has_secret     = is_string( $secret ) && '' !== $secret;
		$public_key     = get_option( self::PUBLIC_KEY_OPTION, '' );
		$has_public_key = is_string( $public_key ) && '' !== $public_key;

		return array(
			'has_secret'     => $has_secret,
			'has_public_key' => $has_public_key,
			'has_credential' => Utils::key_auth_required() ? $has_public_key : $has_secret,
		);
	}

	/**
	 * Handles the ?reprint-api-wpcom-migration request.
	 *
	 * Runs on plugins_loaded at the lowest priority, before caching and other
	 * plugins touch output. A request that ends here never reaches WordPress.
	 */
	public function handle_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// Multisite is out of scope; the screen refuses networks, so never serve one.
		if ( is_multisite() ) {
			return;
		}

		// Any origin: the client may run in a browser (Playground) from
		// deployments we cannot know ahead of time, and origin is no boundary
		// when every request must be signed anyway. Preflights come before
		// the signature check because browsers send them without credentials.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'OPTIONS' === $request_method ) {
			$this->send_cors_headers();
			if ( ! headers_sent() ) {
				header( 'Allow: GET, POST, OPTIONS' );
			}
			$this->terminate();
			return;
		}

		$state = self::get_state();
		if ( ! $state['has_credential'] ) {
			$this->error( 503, 'Export not set up: no export credential is stored on this site. Start the migration again on WordPress.com.', 'wpcom_migration_no_credential' );
			return;
		}

		$secret      = $state['has_secret'] ? get_option( self::SECRET_OPTION ) : null;
		$public_keys = array();
		if ( $state['has_public_key'] ) {
			$public_key = get_option( self::PUBLIC_KEY_OPTION );

			$public_keys[ Utils::public_key_fingerprint( $public_key ) ] = $public_key;
		}

		$auth_failure = $this->verify_signature( $secret, $public_keys );
		if ( null !== $auth_failure ) {
			// Reprint's client maps the reason to its message, and reads the
			// two not-set-up reasons as an answer only from a 503.
			$unconfigured = array( RequestAuthenticator::REASON_NOT_CONFIGURED, RequestAuthenticator::REASON_NO_KEYS_ENROLLED );
			$status       = in_array( $auth_failure['reason'], $unconfigured, true ) ? 503 : 403;
			$this->error( $status, $auth_failure['message'], $auth_failure['reason'] );
			return;
		}

		try {
			$this->serve_export();
		} catch ( \InvalidArgumentException $exception ) {
			$this->error( 400, $exception->getMessage() );
			return;
		}

		self::record_event( 'export_served', array( 'endpoint' => $this->requested_endpoint() ) );
		$this->terminate();
	}

	/**
	 * The endpoint the client asked for, or 'unknown'.
	 *
	 * Matched against the set the export server accepts so an unexpected value
	 * cannot travel into a consumer's log.
	 *
	 * @return string
	 */
	protected function requested_endpoint() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$endpoint = isset( $_GET['endpoint'] ) ? sanitize_key( wp_unslash( $_GET['endpoint'] ) ) : '';

		$known = array( 'preflight', 'db_index', 'sql_chunk', 'file_index', 'file_fetch' );

		return in_array( $endpoint, $known, true ) ? $endpoint : 'unknown';
	}

	/**
	 * Verifies the current request's signature against the stored secret or
	 * public key. Reprint decides which scheme this host accepts.
	 *
	 * Seam so a test double can skip the real authenticator.
	 *
	 * @param string|null          $secret      The valid shared secret, or null.
	 * @param array<string,string> $public_keys Valid public keys by key id.
	 * @return array|null {
	 *     Null on success, otherwise the failure.
	 *
	 *     @type string $message Error description.
	 *     @type string $reason  Reprint's reason code, e.g. unknown_key or not_configured.
	 * }
	 */
	protected function verify_signature( $secret, array $public_keys ) {
		$authenticator = new RequestAuthenticator( $secret, $public_keys, self::CLOCK_SKEW );
		$error         = $authenticator->verify_globals();
		if ( null === $error ) {
			return null;
		}

		return array(
			'message' => $error,
			'reason'  => $authenticator->last_error_reason() ?? RequestAuthenticator::REASON_AUTH_FAILED,
		);
	}

	/**
	 * Streams the export response.
	 *
	 * Seam so a test double can skip a real export.
	 */
	protected function serve_export() {
		$this->send_cors_headers();
		HTTPServer::serve( array( 'default_directory' => ABSPATH ) );
	}

	/**
	 * Emits no-cache and CORS headers the export client needs.
	 *
	 * Sent only with responses we produce, so a request that falls through to
	 * WordPress does not pick them up.
	 */
	protected function send_cors_headers() {
		if ( headers_sent() ) {
			return;
		}

		nocache_headers();

		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: *' );
	}

	/**
	 * Sends a JSON error response and terminates.
	 *
	 * The body carries Reprint's auth_version, without which its client
	 * reports the plugin as outdated.
	 *
	 * @param int         $code    HTTP status code.
	 * @param string      $message Error description.
	 * @param string|null $reason  Machine-readable reason code, or null for none.
	 */
	protected function error( $code, $message, $reason = null ) {
		$context = array(
			'code'   => $code,
			'reason' => $message,
		);
		$body    = array(
			'error'        => $message,
			'code'         => $code,
			'auth_version' => Utils::AUTH_VERSION,
		);
		if ( null !== $reason ) {
			$context['reason_code'] = $reason;
			$body['reason']         = $reason;
		}

		self::record_event( 'export_refused', $context );

		$this->send_cors_headers();
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		echo json_encode( $body, JSON_FORCE_OBJECT );
		$this->terminate();
	}

	/**
	 * Terminates the request.
	 *
	 * Seam wrapping exit so a test double can record that the request ended.
	 */
	protected function terminate() {
		exit;
	}
}
