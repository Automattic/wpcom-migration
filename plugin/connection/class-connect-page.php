<?php
/**
 * The WordPress.com connection section of the Reprint migration screen.
 *
 * Modeled on Reprint\Settings_Page: admin-post handlers, nonces, and notices
 * carried back through query arguments.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration;

/**
 * WordPress.com connection section of the Reprint migration screen.
 *
 * Handles the connect and disconnect forms and supplies the login button, the
 * WordPress.com link and the disconnect link that Reprint\Settings_Page
 * places.
 */
class Connect_Page {

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
		$authorization_url = Connection::authorization_url( self::page_url() );

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
	 * Removes the connection and redirects back with a result notice.
	 */
	public function handle_disconnect() {
		$this->authorize( self::DISCONNECT_ACTION );

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
	 * Renders the login form: the screen's primary button, or a plain link.
	 *
	 * @param bool $primary Whether the submit is the primary button.
	 */
	public function render_connect_button( $primary ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::CONNECT_ACTION ); ?>
			<p>
				<button type="submit" name="wpcom_migration_connect_submit" class="<?php echo $primary ? 'wpcom-migration-button' : 'wpcom-migration-link'; ?>">
					<?php esc_html_e( 'Log in with WordPress.com', 'wpcom-migration' ); ?>
				</button>
			</p>
			<p class="wpcom-migration-description">
				<?php
				printf(
					/* translators: %s: link to the WordPress.com terms of service. */
					esc_html__( 'By connecting you agree to the %s.', 'wpcom-migration' ),
					'<a href="https://wordpress.com/tos/" target="_blank" rel="noreferrer">' . esc_html__( 'WordPress.com Terms of Service', 'wpcom-migration' ) . '</a>'
				);
				?>
			</p>
		</form>
		<?php
	}

	/**
	 * Renders the link to WordPress.com: the screen's primary button, or a
	 * plain link. It starts nothing on this site.
	 *
	 * @param bool $primary Whether it is the primary button.
	 */
	public function render_continue_button( $primary ) {
		?>
		<p>
			<a<?php echo $primary ? ' class="wpcom-migration-button"' : ''; ?> target="_top" href="<?php echo esc_url( self::continue_url() ); ?>">
				<?php esc_html_e( 'Continue on WordPress.com', 'wpcom-migration' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Renders the disconnect form as a link, with what disconnecting does.
	 */
	public function render_disconnect_link() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::DISCONNECT_ACTION ); ?>
			<p>
				<span class="wpcom-migration-description">
					<button type="submit" name="wpcom_migration_disconnect_submit" class="wpcom-migration-link wpcom-migration-link--delete"><?php esc_html_e( 'Disconnect', 'wpcom-migration' ); ?></button>
					<?php esc_html_e( 'Removes this site\'s WordPress.com connection and the export secret WordPress.com installed.', 'wpcom-migration' ); ?>
				</span>
			</p>
		</form>
		<?php
	}

	/**
	 * The WordPress.com email address of the connected user, when the package
	 * has it.
	 *
	 * @return string|null
	 */
	public static function connected_email() {
		$user_data = Connection::connected_wpcom_user();
		if ( is_array( $user_data ) && ! empty( $user_data['email'] ) ) {
			return (string) $user_data['email'];
		}

		return null;
	}

	/**
	 * Where the user goes to start the migration. A plain link; it starts
	 * nothing on this site.
	 *
	 * @return string
	 */
	public static function continue_url() {
		/**
		 * Filters the WordPress.com URL the connected screen links to.
		 *
		 * @param string $url Default: the WordPress.com site-migration setup, with this site as the source.
		 */
		return apply_filters(
			'wpcom_migration_continue_url',
			'https://wordpress.com/setup/site-migration?from=' . rawurlencode( home_url() )
		);
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
