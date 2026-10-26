<?php
/**
 * The WordPress.com connection section of the Reprint migration screen.
 *
 * Admin-post handlers with nonces, and notices carried back through query
 * arguments, as the key screen has too.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration;

/**
 * WordPress.com connection section of the Reprint migration screen.
 *
 * Handles the connect and disconnect forms and supplies the buttons, the
 * terms and the account line that Reprint\Settings_Page places.
 */
class Connect_Page {

	/**
	 * The user's sites on WordPress.com, where the screen sends them when it
	 * knows no better.
	 *
	 * @var string
	 */
	const SITES_URL = 'https://my.wordpress.com/sites';

	/**
	 * The admin-post action that starts the connection.
	 *
	 * @var string
	 */
	const CONNECT_ACTION = 'wpcom_migration_connect';

	/**
	 * The admin-post action that removes the connection.
	 *
	 * @var string
	 */
	const DISCONNECT_ACTION = 'wpcom_migration_disconnect';

	/**
	 * Query argument carrying the result of a form post back to the screen.
	 *
	 * @var string
	 */
	const NOTICE_QUERY_ARG = 'wpcom_migration_connect_notice';

	/**
	 * Query argument carrying an error code alongside a failure result.
	 *
	 * @var string
	 */
	const CODE_QUERY_ARG = 'wpcom_migration_connect_code';

	/**
	 * Query argument marking the screen as where WordPress.com sends the user
	 * back after authorizing.
	 *
	 * @var string
	 */
	const CONNECTED_QUERY_ARG = 'wpcom_migration_connected';

	/**
	 * Absolute path of the plugin's main file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Registers the form handlers.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_page_access_denied', array( $this, 'handle_calypso_retry' ) );
		add_action( 'admin_init', array( $this, 'handle_return' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * The URL of the screen the section sits on.
	 *
	 * @return string
	 */
	public static function page_url() {
		return \Automattic\WPCOM_Migration\Reprint\Settings_Page::page_url();
	}

	/**
	 * Where WordPress.com sends the user back after authorizing: the screen,
	 * marked so handle_return() knows.
	 *
	 * @return string
	 */
	public static function return_url() {
		return add_query_arg( self::CONNECTED_QUERY_ARG, '1', self::page_url() );
	}

	/**
	 * Starts the connection.
	 */
	public function handle_connect() {
		$this->authorize( self::CONNECT_ACTION );

		$this->send_to_wordpress_com();
	}

	/**
	 * Registers the site if needed and sends the user to WordPress.com, or
	 * back here with the registration error code.
	 */
	private function send_to_wordpress_com() {
		$authorization_url = Connection::authorization_url( self::return_url() );

		if ( is_wp_error( $authorization_url ) ) {
			$this->redirect_with_notice( 'register_failed', $authorization_url->get_error_code() );
		}

		// Not wp_safe_redirect(): the destination is WordPress.com.
		wp_redirect( $authorization_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Answers admin.php?page=jetpack&connect_url_redirect=true, the URL
	 * WordPress.com retries a failed authorization through. This plugin has
	 * no page with that slug, so it is handled where WordPress would refuse
	 * it; a site with a real Jetpack page keeps its own handler. Only a
	 * registered site with no user token goes back to WordPress.com; any
	 * other state lands on the screen.
	 */
	public function handle_calypso_retry() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WordPress.com builds this URL; no nonce possible.
		if ( ! isset( $_GET['page'], $_GET['connect_url_redirect'] ) || 'jetpack' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		// phpcs:enable

		if ( is_multisite() || ! in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
			return;
		}

		// An unregistered site has no authorization to retry, and sending it
		// on would register it on an unnonced GET.
		if ( ! Connection::is_site_connected() || Connection::is_user_connected() ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$this->send_to_wordpress_com();
	}

	/**
	 * Sends a user who has just connected on to their destination site on
	 * WordPress.com, when WordPress.com matched one. Otherwise the screen
	 * renders as usual. Authorizing deleted the stored answer, so this asks
	 * WordPress.com afresh.
	 */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WordPress.com builds this URL; the handler only reads and redirects.
		if ( ! isset( $_GET['page'], $_GET[ self::CONNECTED_QUERY_ARG ] ) || \Automattic\WPCOM_Migration\Reprint\Settings_Page::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		// phpcs:enable

		if ( ! current_user_can( 'manage_options' ) || ! Connection::is_user_connected() ) {
			return;
		}

		$destination = Continue_Url::destination();
		if ( null === $destination ) {
			return;
		}

		// Not wp_safe_redirect(): the destination is WordPress.com.
		wp_redirect( $destination ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Drops the return marker from the address bar once the screen renders,
	 * so a reload is a plain visit.
	 *
	 * @param string[] $args Query arguments wp-admin removes.
	 * @return string[]
	 */
	public function removable_query_args( $args ) {
		$args[] = self::CONNECTED_QUERY_ARG;
		return $args;
	}

	/**
	 * Removes the connection and redirects back with a result notice.
	 */
	public function handle_disconnect() {
		$this->authorize( self::DISCONNECT_ACTION );

		// The screen offers no disconnect while other plugins share the
		// connection, which disconnecting would leave in place; a post from a
		// page loaded before one joined changes nothing.
		if ( array() !== Connection::other_connected_plugins() ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		Connection::disconnect();

		$this->redirect_with_notice( 'disconnected' );
	}

	/**
	 * Stops a form post that is not a nonce-carrying administrator request on
	 * a single site.
	 *
	 * A role check, not a capability one: connecting hands WordPress.com the
	 * whole site, and plugins grant manage_options to roles that should not
	 * be able to do that.
	 *
	 * @param string $action The admin-post action being handled.
	 */
	private function authorize( $action ) {
		if ( is_multisite() ) {
			wp_die( esc_html__( 'The WordPress.com connection is not supported on networks.', 'wpcom-migration' ) );
		}

		if ( ! in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
			wp_die( esc_html__( 'Only an administrator can connect this site to WordPress.com.', 'wpcom-migration' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the screen with a result code and exits.
	 *
	 * @param string $result One of disconnected, register_failed.
	 * @param string $code   Error code to show alongside a failure, if any.
	 */
	private function redirect_with_notice( $result, $code = '' ) {
		$url = add_query_arg( self::NOTICE_QUERY_ARG, $result, self::page_url() );
		if ( '' !== $code ) {
			$url = add_query_arg( self::CODE_QUERY_ARG, $code, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Renders the login form as one of the screen's buttons. render_terms()
	 * renders the terms it agrees to.
	 *
	 * @param bool $secondary Whether another button is the primary one.
	 */
	public function render_connect_button( $secondary = false ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::CONNECT_ACTION ); ?>
			<button type="submit" class="wpcom-migration-button<?php echo $secondary ? ' wpcom-migration-button--secondary' : ''; ?>">
				<?php esc_html_e( 'Connect WordPress.com', 'wpcom-migration' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Renders the terms connecting agrees to. The key screen shares it.
	 */
	public static function render_terms() {
		?>
		<p class="wpcom-migration-description">
			<?php
			printf(
				/* translators: %s: link to the WordPress.com terms of service. */
				esc_html__( 'By connecting, you agree to the %s.', 'wpcom-migration' ),
				'<a href="https://wordpress.com/tos/" target="_blank" rel="noreferrer">' . esc_html__( 'WordPress.com Terms of Service', 'wpcom-migration' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the link to WordPress.com as the screen's button. It starts
	 * nothing on this site.
	 */
	public function render_continue_button() {
		?>
		<a class="wpcom-migration-button" target="_top" href="<?php echo esc_url( self::continue_url() ); ?>">
			<?php esc_html_e( 'Continue on WordPress.com', 'wpcom-migration' ); ?>
			<svg class="wpcom-migration-button__icon" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path d="M1.5 8.5l7-7M3 1.5h5.5V7" /></svg>
		</a>
		<?php
	}

	/**
	 * Renders the WordPress.com account the current user is connected as:
	 * the avatar, the name, then on one line the email and either the
	 * plugins the connection is shared with or, for an administrator, the
	 * disconnect link. Disconnecting would leave a shared connection in
	 * place, so it isn't offered then. Without the account's details it says
	 * only that the user is connected.
	 */
	public function render_account() {
		$user        = Connection::connected_wpcom_user();
		$name        = $user['display_name'] ?? $user['login'] ?? '';
		$email       = $user['email'] ?? '';
		$shared_with = Connection::other_connected_plugins();
		$disconnect  = array() === $shared_with && in_array( 'administrator', wp_get_current_user()->roles, true );

		$details = array();
		if ( '' !== $email ) {
			$details[] = esc_html( $email );
		}
		if ( array() !== $shared_with ) {
			/* translators: %s: plugin names, e.g. "Jetpack" or "Jetpack and Jetpack Boost". */
			$details[] = esc_html( sprintf( __( 'Shared with %s', 'wpcom-migration' ), wp_sprintf_l( '%l', $shared_with ) ) );
		} elseif ( $disconnect ) {
			// The button submits the form below, outside this paragraph.
			$details[] = '<button type="submit" form="wpcom-migration-disconnect" class="wpcom-migration-link wpcom-migration-link--quiet">' . esc_html__( 'Disconnect', 'wpcom-migration' ) . '</button>';
		}
		?>
		<div class="wpcom-migration-account">
			<?php
			if ( '' !== $email ) {
				echo get_avatar( $email, 40, '', '', array( 'class' => 'wpcom-migration-account__avatar' ) );
			}
			?>
			<div>
				<p class="wpcom-migration-account__name">
					<?php
					echo '' !== $name
						/* translators: %s: the WordPress.com account's display name. */
						? esc_html( sprintf( __( 'Connected as %s', 'wpcom-migration' ), $name ) )
						: esc_html__( 'Connected to WordPress.com', 'wpcom-migration' );
					?>
				</p>
				<?php if ( array() !== $details ) : ?>
					<p class="wpcom-migration-description"><?php echo implode( ' · ', $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above. ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $disconnect ) : ?>
			<form id="wpcom-migration-disconnect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>" />
				<?php wp_nonce_field( self::DISCONNECT_ACTION ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Where the user goes to start the migration. A plain link; it starts
	 * nothing on this site.
	 *
	 * @return string
	 */
	public static function continue_url() {
		/**
		 * Filters the WordPress.com URL the screen's button links to.
		 *
		 * @param string $url Default: the user's sites on WordPress.com.
		 */
		return apply_filters( 'wpcom_migration_continue_url', self::SITES_URL );
	}

	/**
	 * Renders the notice for the result of the last form post, if any. A
	 * failure shows its error code, never the raw message.
	 */
	public function render_result_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Fixed values select a read-only notice.
		$result = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_ARG ] ?? '' ) );
		$code   = sanitize_key( wp_unslash( $_GET[ self::CODE_QUERY_ARG ] ?? '' ) );
		// phpcs:enable

		if ( 'disconnected' === $result ) {
			$this->render_notice( 'success', esc_html__( 'Disconnected from WordPress.com.', 'wpcom-migration' ), true );
			return;
		}

		if ( 'register_failed' === $result ) {
			$this->render_notice(
				'error',
				'<strong>' . esc_html__( 'This site could not register with WordPress.com.', 'wpcom-migration' ) . '</strong> '
				. sprintf(
					/* translators: %s: error code. */
					esc_html__( 'Error code: %s. Check that the site can reach wordpress.com and try again.', 'wpcom-migration' ),
					'<code>' . esc_html( '' !== $code ? $code : 'unknown' ) . '</code>'
				),
				true
			);
		}
	}

	/**
	 * Renders one native inline notice.
	 *
	 * @param string $type        error, success, warning or info.
	 * @param string $message     Escaped HTML.
	 * @param bool   $dismissible Whether to add the dismiss control.
	 */
	private function render_notice( $type, $message, $dismissible = false ) {
		if ( ! in_array( $type, array( 'error', 'success', 'warning', 'info' ), true ) ) {
			$type = 'error';
		}

		$classes = 'notice notice-' . $type . ' inline';
		if ( $dismissible ) {
			$classes .= ' is-dismissible';
		}

		echo '<div class="' . esc_attr( $classes ) . '"><p>' . wp_kses_post( $message ) . '</p></div>';
	}
}
