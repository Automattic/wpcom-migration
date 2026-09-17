<?php
/**
 * HMAC-authenticated, time-limited Reprint export.
 *
 * Modeled on Jetpack's Reprint_Exporter (Automattic/jetpack#52278).
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use WordPress\Reprint\Server\HMACServer;
use WordPress\Reprint\Server\HTTPServer;

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
	 * Option holding the HMAC of the secret under the site's auth salt.
	 *
	 * @var string
	 */
	const SECRET_HASH_OPTION = 'wpcom_migration_reprint_secret_hash';

	/**
	 * Option holding the unix time the export window was last opened. The
	 * window is a sliding 60-minute one.
	 *
	 * @var string
	 */
	const ENABLED_OPTION = 'wpcom_migration_reprint_enabled';

	/**
	 * Option holding the HMAC of the window stamp under the site's auth salt.
	 *
	 * @var string
	 */
	const ENABLED_HASH_OPTION = 'wpcom_migration_reprint_enabled_hash';

	/**
	 * The options only this class may write.
	 *
	 * @var string[]
	 */
	const GUARDED_OPTIONS = array(
		self::SECRET_OPTION,
		self::SECRET_HASH_OPTION,
		self::ENABLED_OPTION,
		self::ENABLED_HASH_OPTION,
	);

	/**
	 * Clock-skew tolerance, in seconds, allowed for HMAC signatures.
	 *
	 * @var int
	 */
	const HMAC_CLOCK_SKEW = 300;

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
	 * Runs at plugin-file load. The handler itself waits for plugins_loaded:
	 * wp_salt() lives in pluggable.php, which WordPress requires after the
	 * plugin files and before that action, and the credential hashes cannot be
	 * checked without it. The lowest priority there is the earliest point the
	 * request can be answered.
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
	 * Whoever sets both can export the whole site, since they pick the secret
	 * and can then sign their own requests. Allowed by where the write came
	 * from, not by who is logged in: the usual arbitrary-option-write bug is a
	 * form missing its nonce, running in an administrator's own session.
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
		 * secret, a credential hash or the signature.
		 *
		 * @param string $event   One of export_served, export_refused,
		 *                        secret_rotated, window_opened, window_closed,
		 *                        credentials_discarded, credential_hash_mismatch.
		 * @param array  $context Details of the event.
		 */
		do_action( 'wpcom_migration_reprint_export_event', $event, $context );
	}

	/**
	 * Discards any stored export credentials.
	 *
	 * Runs on plugin activation and deactivation, clearing whatever was
	 * written while protect_options() was not in place.
	 */
	public static function discard_credentials() {
		$had_any = false;
		foreach ( self::GUARDED_OPTIONS as $option ) {
			$had_any = delete_option( $option ) || $had_any;
		}

		if ( $had_any ) {
			self::record_event(
				'credentials_discarded',
				array( 'boundary' => current_filter() )
			);
		}
	}

	/**
	 * Stores a shared secret together with its salt-keyed hash.
	 *
	 * Saving a secret does not touch the export window.
	 *
	 * @param string $secret The new secret.
	 * @return bool Whether the secret and a matching hash are now stored.
	 */
	public static function store_secret( $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			return false;
		}

		self::write_option( self::SECRET_OPTION, $secret );
		self::write_option( self::SECRET_HASH_OPTION, self::compute_credential_hash( self::SECRET_HASH_OPTION, $secret ) );

		// Read back rather than trust update_option(): it reports false for an
		// unchanged value, and re-saving the same secret after a salt change
		// rewrites only the hash.
		return get_option( self::SECRET_OPTION ) === $secret
			&& self::credential_hash_matches( self::SECRET_HASH_OPTION, $secret );
	}

	/**
	 * Computes the HMAC binding a stored credential to the site's auth salt.
	 *
	 * Keyed with wp_salt() rather than AUTH_SALT, so sites still carrying the
	 * sample placeholder salts can export at all. Accepted cost: wp_salt() then
	 * stores its own salt in wp_options, where whoever can write the credential
	 * can read it, so the hashes add no protection there.
	 *
	 * The option name prefixes the message, so copying the window timestamp
	 * and its hash into the secret options does not make a secret that
	 * verifies.
	 *
	 * @param string     $hash_option The option the hash is stored in.
	 * @param string|int $value       The stored value.
	 * @return string
	 */
	private static function compute_credential_hash( $hash_option, $value ) {
		return hash_hmac( 'sha256', $hash_option . "\0" . (string) $value, wp_salt( 'auth' ) );
	}

	/**
	 * Whether the stored hash is the one the site's auth salt gives for a
	 * stored credential.
	 *
	 * @param string     $hash_option The option the hash is stored in.
	 * @param string|int $value       The stored value.
	 * @return bool
	 */
	private static function credential_hash_matches( $hash_option, $value ) {
		$stored_hash = get_option( $hash_option );
		if ( ! is_string( $stored_hash ) ) {
			return false;
		}

		return hash_equals( self::compute_credential_hash( $hash_option, $value ), $stored_hash );
	}

	/**
	 * Whether the current export window is open.
	 *
	 * @param int|null $now Unix time to compare against, or null for now.
	 * @return bool
	 */
	public static function is_export_window_open( $now = null ) {
		$enabled_at = (int) get_option( self::ENABLED_OPTION, 0 );
		$now        = null === $now ? time() : (int) $now;

		return $enabled_at > 0
			&& $enabled_at <= $now + self::HMAC_CLOCK_SKEW
			&& ( $now - $enabled_at ) <= HOUR_IN_SECONDS
			&& self::credential_hash_matches( self::ENABLED_HASH_OPTION, $enabled_at );
	}

	/**
	 * Opens the export window by stamping the enabled option with the current
	 * time and hashing the stamp.
	 *
	 * @return int The unix time the window was opened at.
	 */
	public static function open_export_window() {
		$now = time();

		// Value then hash: a crash between them leaves a mismatch, which reads
		// as closed. Each skips an unchanged value, so a busy client costs at
		// most two writes per elapsed second.
		self::write_option( self::ENABLED_OPTION, $now );
		self::write_option( self::ENABLED_HASH_OPTION, self::compute_credential_hash( self::ENABLED_HASH_OPTION, $now ) );

		return $now;
	}

	/**
	 * Closes the export window.
	 */
	public static function close_export_window() {
		delete_option( self::ENABLED_OPTION );
		delete_option( self::ENABLED_HASH_OPTION );
	}

	/**
	 * The state the settings screen renders.
	 *
	 * @return array {
	 *     @type bool     $has_secret        Whether a secret is stored.
	 *     @type bool     $secret_valid      Whether its hash matches under the current salt.
	 *     @type bool     $window_open       Whether the export window is open.
	 *     @type int|null $window_expires_at Unix time the window lapses, or null when closed.
	 * }
	 */
	public static function get_state() {
		$secret      = get_option( self::SECRET_OPTION, '' );
		$has_secret  = is_string( $secret ) && '' !== $secret;
		$window_open = self::is_export_window_open();

		return array(
			'has_secret'        => $has_secret,
			'secret_valid'      => $has_secret && self::credential_hash_matches( self::SECRET_HASH_OPTION, $secret ),
			'window_open'       => $window_open,
			'window_expires_at' => $window_open ? (int) get_option( self::ENABLED_OPTION, 0 ) + HOUR_IN_SECONDS : null,
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
		// when every request needs the HMAC secret anyway. Preflights come
		// before HMAC because browsers send them without credentials, and
		// before the window check so a client whose window has closed can
		// reach the 409 below.
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

		// Without a valid signature a closed window answers nothing, so an idle
		// site stays indistinguishable from one that never had the feature.
		$window_open = self::is_export_window_open();

		$secret = get_option( self::SECRET_OPTION, '' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			if ( ! $window_open ) {
				return;
			}
			$this->error( 503, 'Export not configured. Save a shared secret on the Migrate to WordPress.com Migration status screen.' );
			return;
		}

		// A secret this class did not hash under the current salt is no
		// credential at all, so it never reaches signature verification.
		if ( ! self::credential_hash_matches( self::SECRET_HASH_OPTION, $secret ) ) {
			if ( ! $window_open ) {
				return;
			}
			self::record_event( 'credential_hash_mismatch' );
			$this->error( 503, 'Export credential invalidated: the stored secret does not match this site\'s salts. Save a new secret on the settings screen.' );
			return;
		}

		$auth_error = $this->verify_hmac( $secret );
		if ( null !== $auth_error ) {
			if ( ! $window_open ) {
				return;
			}
			$this->error( 403, $auth_error );
			return;
		}

		// Signature checks out, so say which state this is: still here, only
		// needing re-arming, rather than gone.
		if ( ! $window_open ) {
			$this->error( 409, 'Export window closed. Enable the exporter on the settings screen.' );
			return;
		}

		// An export spans many requests and can run past the hour, so keep the
		// window open while a client is working.
		self::open_export_window();

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
	 * Verifies the HMAC signature of the current request.
	 *
	 * Seam so a test double can skip the real server.
	 *
	 * @param string $secret The shared secret.
	 * @return string|null Error message on failure, null on success.
	 */
	protected function verify_hmac( $secret ) {
		$hmac_server = new HMACServer( $secret, self::HMAC_CLOCK_SKEW );
		return $hmac_server->verify_globals();
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
	 * @param int    $code    HTTP status code.
	 * @param string $message Error description.
	 */
	protected function error( $code, $message ) {
		self::record_event(
			'export_refused',
			array(
				'code'   => $code,
				'reason' => $message,
			)
		);

		$this->send_cors_headers();
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		echo json_encode(
			array(
				'error' => $message,
				'code'  => $code,
			),
			JSON_FORCE_OBJECT
		);
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
