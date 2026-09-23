<?php
/**
 * Admin screen for the Reprint exporter: export secret, exporter, and the
 * WordPress.com connection.
 *
 * Modeled on reprint-server-wp's SettingsPage.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * Renders wp-admin/admin.php?page=wpcom-migration-status one mode at a time,
 * handles its forms, and places the WordPress.com connection controls.
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
	const PAGE_SLUG = 'wpcom-migration-status';

	/**
	 * The admin-post action that saves the secret.
	 *
	 * @var string
	 */
	const SAVE_SECRET_ACTION = 'wpcom_migration_reprint_save_secret';

	/**
	 * The admin-post action that turns the exporter on or off.
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
	 * The screen is not available: the site is a network.
	 *
	 * @var string
	 */
	const MODE_BLOCKED = 'blocked';

	/**
	 * A secret is stored but no longer matches the site's salts.
	 *
	 * @var string
	 */
	const MODE_BROKEN = 'broken';

	/**
	 * The secret is valid and the exporter is on.
	 *
	 * @var string
	 */
	const MODE_READY = 'ready';

	/**
	 * The user is connected to WordPress.com; the migration has not started.
	 *
	 * @var string
	 */
	const MODE_CONNECTED_WAITING = 'connected_waiting';

	/**
	 * WordPress.com installed a secret; the exporter is off; no user connection.
	 *
	 * @var string
	 */
	const MODE_PROVISIONED_WAITING = 'provisioned_waiting';

	/**
	 * Nothing set up yet.
	 *
	 * @var string
	 */
	const MODE_NEEDS_CONNECTING = 'needs_connecting';

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
	 * The WordPress.com connection section, when the connection bootstrap
	 * has loaded.
	 *
	 * @var \Automattic\WPCOM_Migration\Connect_Page|null
	 */
	private $connection_section = null;

	/**
	 * Registers the screen, its form handlers and its script.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 20 );
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
	 * Attaches the WordPress.com connection section.
	 *
	 * @param \Automattic\WPCOM_Migration\Connect_Page $section The section.
	 */
	public function set_connection_section( \Automattic\WPCOM_Migration\Connect_Page $section ) {
		$this->connection_section = $section;
	}

	/**
	 * Adds the plugin's one sidebar entry, unless the old screen's brand
	 * has asked for no menu at all.
	 */
	public function add_admin_menu() {
		/**
		 * Filters whether the plugin shows a sidebar entry.
		 *
		 * The old BlogVault screen answers false when the plugin is
		 * whitelabelled to hide. Delete the filter with that screen.
		 *
		 * @param bool $show Whether to register the menu entry.
		 */
		if ( ! apply_filters( 'wpcom_migration_show_menu', true ) ) {
			return;
		}

		$this->page_hook = add_menu_page(
			__( 'Migrate to WordPress.com', 'wpcom-migration' ),
			__( 'Migrate to WordPress.com', 'wpcom-migration' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-wordpress-alt'
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
	 * Turns the exporter on or off and redirects back with a result notice.
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
	 * Renders the screen: the mode, then the by-hand section.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state          = Exporter::get_state();
		$user_connected = null !== $this->connection_section && \Automattic\WPCOM_Migration\Connection::is_user_connected();
		$mode           = self::mode( $state, $user_connected );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Reprint migration', 'wpcom-migration' ) . '</h1>';

		$this->render_result_notice();
		if ( null !== $this->connection_section ) {
			$this->connection_section->render_result_notice();
		}

		$this->render_mode( $mode, $state, $user_connected );

		if ( self::MODE_BLOCKED !== $mode ) {
			$this->render_manual( $state, $user_connected );
		}

		echo '</div>';
	}

	/**
	 * Which mode the screen is in: the first of the MODE_* cases that fits,
	 * checked in the order they are declared.
	 *
	 * @param array $state          Exporter::get_state().
	 * @param bool  $user_connected Whether the current user is connected to WordPress.com.
	 * @return string One of the MODE_* constants.
	 */
	public static function mode( array $state, $user_connected ) {
		if ( is_multisite() ) {
			return self::MODE_BLOCKED;
		}

		if ( $state['has_secret'] && ! $state['secret_valid'] ) {
			return self::MODE_BROKEN;
		}

		if ( $state['secret_valid'] && $state['window_open'] ) {
			return self::MODE_READY;
		}

		if ( $user_connected ) {
			return self::MODE_CONNECTED_WAITING;
		}

		if ( $state['secret_valid'] ) {
			return self::MODE_PROVISIONED_WAITING;
		}

		return self::MODE_NEEDS_CONNECTING;
	}

	/**
	 * Renders the mode: a heading, one sentence, at most one primary button,
	 * and the links that fit. Connection controls render only when a
	 * section is attached.
	 *
	 * @param string $mode           One of the MODE_* constants.
	 * @param array  $state          Exporter::get_state().
	 * @param bool   $user_connected Whether the current user is connected to WordPress.com.
	 */
	private function render_mode( $mode, array $state, $user_connected ) {
		$section = $this->connection_section;

		switch ( $mode ) {
			case self::MODE_BLOCKED:
				$this->render_heading( __( 'Not available on networks', 'wpcom-migration' ) );
				$this->render_sentence( __( 'The exporter runs on single sites only.', 'wpcom-migration' ) );
				return;

			case self::MODE_BROKEN:
				$this->render_heading( __( 'The export secret no longer matches this site', 'wpcom-migration' ) );
				$this->render_sentence( __( 'The site\'s salts changed. Start the migration again on WordPress.com, or save a new secret by hand below.', 'wpcom-migration' ) );
				if ( null !== $section && $user_connected ) {
					$section->render_continue_button( true );
					$section->render_disconnect_link();
				} elseif ( null !== $section ) {
					$section->render_connect_button( true );
				}
				return;

			case self::MODE_READY:
				$this->render_heading(
					sprintf(
						/* translators: %s: time of day. */
						__( 'Exporter on until %s', 'wpcom-migration' ),
						self::window_closes_at( $state )
					)
				);
				$this->render_sentence( __( 'The migration runs from WordPress.com. Each export keeps the exporter on for another hour.', 'wpcom-migration' ) );
				if ( null !== $section && $user_connected ) {
					$section->render_continue_button( false );
					$section->render_disconnect_link();
				}
				return;

			case self::MODE_CONNECTED_WAITING:
				$login = null !== $section ? \Automattic\WPCOM_Migration\Connect_Page::connected_login() : null;
				if ( null !== $login ) {
					$this->render_heading(
						sprintf(
							/* translators: %s: WordPress.com user login. */
							__( 'Connected as %s', 'wpcom-migration' ),
							$login
						)
					);
				} else {
					$this->render_heading( __( 'Connected to WordPress.com', 'wpcom-migration' ) );
				}
				$this->render_sentence( __( 'WordPress.com sets up the exporter when the migration starts.', 'wpcom-migration' ) );
				if ( null !== $section ) {
					$section->render_continue_button( true );
					$section->render_disconnect_link();
				}
				return;

			case self::MODE_PROVISIONED_WAITING:
				$this->render_heading( __( 'Set up by WordPress.com', 'wpcom-migration' ) );
				$this->render_sentence( __( 'The exporter is off until the migration starts. Nothing to do here.', 'wpcom-migration' ) );
				if ( null !== $section ) {
					$section->render_connect_button( false );
				}
				return;

			case self::MODE_NEEDS_CONNECTING:
			default:
				$this->render_heading( __( 'Connect this site to WordPress.com', 'wpcom-migration' ) );
				$this->render_sentence( __( 'Log in with your WordPress.com account so WordPress.com can read this site and migrate it. Nothing is copied until you start the migration there.', 'wpcom-migration' ) );
				if ( null !== $section ) {
					$section->render_connect_button( true );
				}
				return;
		}
	}

	/**
	 * Renders the mode heading.
	 *
	 * @param string $text Plain text.
	 */
	private function render_heading( $text ) {
		echo '<h2 class="wpcom-migration-mode">' . esc_html( $text ) . '</h2>';
	}

	/**
	 * Renders the mode's one sentence.
	 *
	 * @param string $text Plain text.
	 */
	private function render_sentence( $text ) {
		echo '<p class="wpcom-migration-guidance">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The time of day the exporter turns itself off, in the site's format.
	 *
	 * @param array $state Exporter::get_state(), with the window open.
	 * @return string
	 */
	private static function window_closes_at( array $state ) {
		$expires = $state['window_expires_at'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		return date_i18n( get_option( 'time_format' ), $expires );
	}

	/**
	 * Renders the collapsed by-hand section: the secret form; the exporter
	 * toggle and export URL once the secret is valid; the blog ID when
	 * connected.
	 *
	 * @param array $state          Exporter::get_state().
	 * @param bool  $user_connected Whether the current user is connected to WordPress.com.
	 */
	private function render_manual( array $state, $user_connected ) {
		echo '<details class="wpcom-migration-manual">';
		echo '<summary><strong>' . esc_html__( 'Set up by hand', 'wpcom-migration' ) . '</strong></summary>';
		echo '<p class="description">' . esc_html__( 'Support may ask you to set this up by hand.', 'wpcom-migration' ) . '</p>';
		$this->render_secret_form( $state );

		if ( $state['secret_valid'] ) {
			$this->render_enable_form( $state );
			$this->render_api_url();
		}

		$blog_id = $user_connected ? \Automattic\WPCOM_Migration\Connection::blog_id() : null;
		if ( null !== $blog_id ) {
			echo '<hr />';
			echo '<p>' . sprintf(
				/* translators: %d: WordPress.com blog ID. */
				esc_html__( 'WordPress.com blog ID: %d', 'wpcom-migration' ),
				(int) $blog_id
			) . '</p>';
		}

		echo '</details>';
	}

	/**
	 * Renders the secret form.
	 *
	 * @param array $state Exporter::get_state().
	 */
	private function render_secret_form( array $state ) {
		$stored_secret = $state['secret_valid'] ? (string) get_option( Exporter::SECRET_OPTION, '' ) : '';
		?>
		<h2><?php esc_html_e( 'Export secret', 'wpcom-migration' ); ?></h2>
		<p><?php esc_html_e( 'Paste the secret supplied by WordPress.com.', 'wpcom-migration' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_SECRET_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_SECRET_ACTION ); ?>
			<label class="screen-reader-text" for="wpcom-migration-reprint-secret"><?php esc_html_e( 'Export secret', 'wpcom-migration' ); ?></label>
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
			<?php submit_button( __( 'Save secret', 'wpcom-migration' ), 'secondary', 'wpcom_migration_reprint_save_secret_submit' ); ?>
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
		<h2><?php esc_html_e( 'Exporter', 'wpcom-migration' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ENABLED_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_ENABLED_ACTION ); ?>
			<label>
				<input type="checkbox"
					name="<?php echo esc_attr( self::ENABLED_FIELD ); ?>"
					value="1"<?php checked( $state['window_open'] ); ?> />
				<?php esc_html_e( 'Turn the exporter on', 'wpcom-migration' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'While on, anyone with the export secret can download this site\'s database and files. It turns itself off an hour after the last export.', 'wpcom-migration' ); ?></p>
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
		<h2><?php esc_html_e( 'Export URL', 'wpcom-migration' ); ?></h2>
		<p><?php esc_html_e( 'Use this URL when WordPress.com asks for the export URL.', 'wpcom-migration' ); ?></p>
		<input type="text"
			class="regular-text code"
			id="wpcom-migration-reprint-api-url"
			value="<?php echo esc_attr( home_url( '?' . Exporter::QUERY_VAR ) ); ?>"
			readonly />
		<button type="button"
			class="button wpcom-migration-reprint-copy-url"
			data-copied-message="<?php esc_attr_e( 'Export URL copied.', 'wpcom-migration' ); ?>">
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
			'enabled'         => array( 'success', __( 'Exporter on for the next hour.', 'wpcom-migration' ) ),
			'disabled'        => array( 'success', __( 'Exporter off.', 'wpcom-migration' ) ),
			'not_configured'  => array( 'error', __( 'Enter an export secret first.', 'wpcom-migration' ) ),
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
