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
 * Renders the login button or the connected state and handles the connect
 * and disconnect forms; the screen it sits on is Reprint\Settings_Page.
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
	 * it; a site with a real Jetpack page keeps its own handler.
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

		if ( Connection::is_user_connected() ) {
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
	 * Renders the section: the connected state, or the login form.
	 */
	public function render_section() {
		if ( Connection::is_user_connected() ) {
			$this->render_connected();
		} else {
			$this->render_connect_form();
		}
	}

	/**
	 * Renders the login button and what it allows.
	 */
	private function render_connect_form() {
		?>
		<p><?php esc_html_e( 'Log in with your WordPress.com account to let WordPress.com read this site and migrate it. Nothing is copied until you start the migration on WordPress.com.', 'wpcom-migration' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::CONNECT_ACTION ); ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the WordPress.com terms of service. */
					esc_html__( 'By connecting you agree to the %s.', 'wpcom-migration' ),
					'<a href="https://wordpress.com/tos/" target="_blank" rel="noreferrer">' . esc_html__( 'WordPress.com Terms of Service', 'wpcom-migration' ) . '</a>'
				);
				?>
			</p>
			<?php submit_button( __( 'Log in with WordPress.com', 'wpcom-migration' ), 'primary', 'wpcom_migration_connect_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the connected state: who, which blog, where next, and the way out.
	 */
	private function render_connected() {
		$user_data = Connection::connected_wpcom_user();
		$blog_id   = Connection::blog_id();

		if ( is_array( $user_data ) && ! empty( $user_data['login'] ) ) {
			$heading = sprintf(
				/* translators: %s: WordPress.com user login. */
				__( 'Connected as %s', 'wpcom-migration' ),
				$user_data['login']
			);
		} else {
			$heading = __( 'Connected to WordPress.com', 'wpcom-migration' );
		}
		?>
		<h2><?php echo esc_html( $heading ); ?></h2>
		<?php if ( null !== $blog_id ) : ?>
			<p>
				<?php
				printf(
					/* translators: %d: WordPress.com blog ID. */
					esc_html__( 'WordPress.com blog ID: %d', 'wpcom-migration' ),
					(int) $blog_id
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<a class="button button-primary" target="_top" href="<?php echo esc_url( self::continue_url() ); ?>">
				<?php esc_html_e( 'Continue on WordPress.com', 'wpcom-migration' ); ?>
			</a>
		</p>
		<hr />
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::DISCONNECT_ACTION ); ?>
			<p class="description"><?php esc_html_e( 'Disconnecting removes this site\'s WordPress.com connection and the export secret WordPress.com installed.', 'wpcom-migration' ); ?></p>
			<?php submit_button( __( 'Disconnect', 'wpcom-migration' ), 'secondary', 'wpcom_migration_disconnect_submit' ); ?>
		</form>
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
