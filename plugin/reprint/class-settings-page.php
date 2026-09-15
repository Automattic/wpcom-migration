<?php
/**
 * Admin screen for the Reprint exporter: shared secret and export window.
 *
 * Modeled on reprint-server-wp's SettingsPage.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * Renders wp-admin/admin.php?page=wpcom-migration-reprint and handles its forms.
 *
 * Both forms post to admin-post.php rather than options.php: the Settings API
 * writes the option itself, and Exporter's write veto would discard it.
 */
class Settings_Page {

	/**
	 * Page slug; the screen lives at admin.php?page=<slug>.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wpcom-migration-reprint';

	/**
	 * admin-post action that saves the secret.
	 *
	 * @var string
	 */
	const SAVE_SECRET_ACTION = 'wpcom_migration_reprint_save_secret';

	/**
	 * admin-post action that opens or closes the window.
	 *
	 * @var string
	 */
	const SAVE_ENABLED_ACTION = 'wpcom_migration_reprint_save_enabled';

	/**
	 * Query argument carrying the result of a form post back to the screen.
	 *
	 * @var string
	 */
	const NOTICE_QUERY_ARG = 'wpcom_migration_reprint_notice';

	/**
	 * Name of the secret input.
	 *
	 * @var string
	 */
	const SECRET_FIELD = 'wpcom_migration_reprint_secret';

	/**
	 * Name of the enable checkbox.
	 *
	 * @var string
	 */
	const ENABLED_FIELD = 'wpcom_migration_reprint_enabled';

	/**
	 * Script handle for settings-page.js.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wpcom-migration-reprint-settings';

	/**
	 * Absolute path of the plugin's main file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string|false
	 */
	private $page_hook = false;

	/**
	 * Registers the screen, its form handlers and its script.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_' . self::SAVE_SECRET_ACTION, array( $this, 'handle_save_secret' ) );
		add_action( 'admin_post_' . self::SAVE_ENABLED_ACTION, array( $this, 'handle_save_enabled' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * The screen's URL.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Registers the page without a menu entry.
	 */
	public function add_admin_menu() {
		$this->page_hook = add_submenu_page(
			'',
			__( 'Export to WordPress.com', 'wpcom-migration' ),
			__( 'Export to WordPress.com', 'wpcom-migration' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the screen's script on the screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		$plugin_data = get_file_data( $this->plugin_file, array( 'Version' => 'Version' ) );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'reprint/settings-page.js', $this->plugin_file ),
			array( 'wp-a11y' ),
			'' !== $plugin_data['Version'] ? $plugin_data['Version'] : false,
			true
		);
	}

	/**
	 * Saves the secret and redirects back with a result notice.
	 */
	public function handle_save_secret() {
		$this->authorize( self::SAVE_SECRET_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in authorize().
		$secret = sanitize_text_field( wp_unslash( $_POST[ self::SECRET_FIELD ] ?? '' ) );

		if ( '' === $secret ) {
			$this->redirect_with_notice( 'not_configured' );
		}

		$state = Exporter::get_state();
		if ( $state['secret_valid'] && get_option( Exporter::SECRET_OPTION ) === $secret ) {
			$this->redirect_with_notice( 'unchanged' );
		}

		if ( ! Exporter::store_secret( $secret ) ) {
			$this->redirect_with_notice( 'storage_failure' );
		}

		Exporter::record_event( 'secret_rotated', array( 'user_id' => get_current_user_id() ) );
		$this->redirect_with_notice( 'saved' );
	}

	/**
	 * Opens or closes the export window and redirects back with a result notice.
	 */
	public function handle_save_enabled() {
		$this->authorize( self::SAVE_ENABLED_ACTION );

		$state = Exporter::get_state();
		if ( ! $state['secret_valid'] ) {
			$this->redirect_with_notice( 'not_configured' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in authorize().
		if ( isset( $_POST[ self::ENABLED_FIELD ] ) ) {
			Exporter::open_export_window();
			Exporter::record_event( 'window_opened', array( 'user_id' => get_current_user_id() ) );
			$this->redirect_with_notice( 'enabled' );
		}

		Exporter::close_export_window();
		Exporter::record_event( 'window_closed', array( 'user_id' => get_current_user_id() ) );
		$this->redirect_with_notice( 'disabled' );
	}

	/**
	 * Stops a form post that is not a nonce-carrying administrator request on
	 * a single site.
	 *
	 * @param string $action The admin-post action being handled.
	 */
	private function authorize( $action ) {
		if ( is_multisite() ) {
			wp_die( esc_html__( 'The exporter is not supported on networks.', 'wpcom-migration' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the exporter.', 'wpcom-migration' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the screen with a result code and exits.
	 *
	 * @param string $result One of saved, unchanged, enabled, disabled,
	 *                       not_configured, storage_failure.
	 */
	private function redirect_with_notice( $result ) {
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY_ARG, $result, self::page_url() ) );
		exit;
	}

	/**
	 * Renders the screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Export to WordPress.com', 'wpcom-migration' ) . '</h1>';

		if ( is_multisite() ) {
			$this->render_notice( 'warning', esc_html__( 'The exporter is not supported on networks.', 'wpcom-migration' ) );
			echo '</div>';
			return;
		}

		$state = Exporter::get_state();

		echo '<p>' . esc_html__( 'Allow WordPress.com to download this site\'s database and files.', 'wpcom-migration' ) . '</p>';

		$this->render_result_notice();
		$this->render_status_notice( $state );
		$this->render_secret_form( $state );

		if ( $state['secret_valid'] ) {
			$this->render_enable_form( $state );
			$this->render_api_url();
		}

		echo '</div>';
	}

	/**
	 * Renders the notice describing the current state.
	 *
	 * @param array $state Exporter::get_state().
	 */
	private function render_status_notice( array $state ) {
		if ( ! $state['has_secret'] ) {
			$this->render_notice(
				'warning',
				'<strong>' . esc_html__( 'Not configured yet.', 'wpcom-migration' ) . '</strong> '
				. esc_html__( 'Enter the shared secret to get started.', 'wpcom-migration' )
			);
			return;
		}

		if ( ! $state['secret_valid'] ) {
			$this->render_notice(
				'error',
				'<strong>' . esc_html__( 'Secret invalidated.', 'wpcom-migration' ) . '</strong> '
				. esc_html__( 'The site\'s salts changed. Save a new secret.', 'wpcom-migration' )
			);
			return;
		}

		if ( $state['window_open'] ) {
			$expires = $state['window_expires_at'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			$this->render_notice(
				'info',
				'<strong>' . sprintf(
					/* translators: %s: time of day. */
					esc_html__( 'Exporter enabled until %s.', 'wpcom-migration' ),
					esc_html( date_i18n( get_option( 'time_format' ), $expires ) )
				) . '</strong> '
				. esc_html__( 'Each export request keeps it open for another hour.', 'wpcom-migration' )
			);
			return;
		}

		$this->render_notice(
			'info',
			'<strong>' . esc_html__( 'Exporter disabled.', 'wpcom-migration' ) . '</strong> '
			. esc_html__( 'Enable it below to allow exports for the next hour.', 'wpcom-migration' )
		);
	}

	/**
	 * Renders the secret form.
	 *
	 * @param array $state Exporter::get_state().
	 */
	private function render_secret_form( array $state ) {
		$stored_secret = $state['secret_valid'] ? (string) get_option( Exporter::SECRET_OPTION, '' ) : '';
		?>
		<h2><?php esc_html_e( 'Shared secret', 'wpcom-migration' ); ?></h2>
		<p><?php esc_html_e( 'Paste the secret supplied by WordPress.com.', 'wpcom-migration' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_SECRET_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_SECRET_ACTION ); ?>
			<label class="screen-reader-text" for="wpcom-migration-reprint-secret"><?php esc_html_e( 'Shared secret', 'wpcom-migration' ); ?></label>
			<input type="password"
				class="regular-text code"
				id="wpcom-migration-reprint-secret"
				name="<?php echo esc_attr( self::SECRET_FIELD ); ?>"
				value="<?php echo esc_attr( $stored_secret ); ?>"
				autocomplete="off" />
			<button type="button"
				class="button wpcom-migration-reprint-toggle-secret"
				aria-controls="wpcom-migration-reprint-secret"
				aria-pressed="false"
				aria-label="<?php esc_attr_e( 'Show secret', 'wpcom-migration' ); ?>"
				data-show-label="<?php esc_attr_e( 'Show secret', 'wpcom-migration' ); ?>"
				data-hide-label="<?php esc_attr_e( 'Hide secret', 'wpcom-migration' ); ?>">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
			</button>
			<?php submit_button( __( 'Save secret', 'wpcom-migration' ), 'primary', 'wpcom_migration_reprint_save_secret_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the enable form.
	 *
	 * @param array $state Exporter::get_state().
	 */
	private function render_enable_form( array $state ) {
		?>
		<hr />
		<h2><?php esc_html_e( 'Export window', 'wpcom-migration' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ENABLED_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_ENABLED_ACTION ); ?>
			<label>
				<input type="checkbox"
					name="<?php echo esc_attr( self::ENABLED_FIELD ); ?>"
					value="1"<?php checked( $state['window_open'] ); ?> />
				<?php esc_html_e( 'Enable the exporter', 'wpcom-migration' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'While enabled, anyone with the shared secret can download this site\'s database and files. It turns itself off an hour after the last export request.', 'wpcom-migration' ); ?></p>
			<?php submit_button( __( 'Save', 'wpcom-migration' ), 'secondary', 'wpcom_migration_reprint_save_enabled_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the read-only endpoint URL with a copy button.
	 */
	private function render_api_url() {
		?>
		<hr />
		<h2><?php esc_html_e( 'Remote API URL', 'wpcom-migration' ); ?></h2>
		<p><?php esc_html_e( 'Use this URL when WordPress.com asks for the remote Reprint API URL.', 'wpcom-migration' ); ?></p>
		<input type="text"
			class="regular-text code"
			id="wpcom-migration-reprint-api-url"
			value="<?php echo esc_attr( home_url( '?' . Exporter::QUERY_VAR ) ); ?>"
			readonly />
		<button type="button"
			class="button wpcom-migration-reprint-copy-url"
			data-copied-message="<?php esc_attr_e( 'Remote API URL copied.', 'wpcom-migration' ); ?>">
			<?php esc_html_e( 'Copy', 'wpcom-migration' ); ?>
		</button>
		<?php
	}

	/**
	 * Renders the notice for the result of the last form post, if any.
	 */
	private function render_result_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixed query value selects a read-only notice.
		$result = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_ARG ] ?? '' ) );

		$notices = array(
			'saved'           => array( 'success', __( 'Secret saved.', 'wpcom-migration' ) ),
			'unchanged'       => array( 'success', __( 'The secret was already up to date.', 'wpcom-migration' ) ),
			'enabled'         => array( 'success', __( 'Exporter enabled for the next hour.', 'wpcom-migration' ) ),
			'disabled'        => array( 'success', __( 'Exporter disabled.', 'wpcom-migration' ) ),
			'not_configured'  => array( 'error', __( 'Enter a shared secret first.', 'wpcom-migration' ) ),
			'storage_failure' => array( 'error', __( 'The secret could not be saved.', 'wpcom-migration' ) ),
		);

		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		$this->render_notice( $notices[ $result ][0], esc_html( $notices[ $result ][1] ), true );
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
