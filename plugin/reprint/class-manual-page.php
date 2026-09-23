<?php
/**
 * The by-hand screen: export secret, exporter toggle, export URL, blog ID.
 *
 * Nothing links here; support gives out the URL.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * Renders wp-admin/admin.php?page=wpcom-migration-manual and handles its
 * forms.
 *
 * Both forms post to admin-post.php rather than options.php: the Settings API
 * writes the option itself, and Exporter's write veto would discard it.
 */
class Manual_Page {

	/**
	 * Page slug; the screen lives at admin.php?page=<slug>.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wpcom-migration-manual';

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
	 * The admin-post action that removes the secret and turns the exporter off.
	 *
	 * @var string
	 */
	const DISCARD_SECRET_ACTION = 'wpcom_migration_reprint_discard_secret';

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
	 * Script handle for manual-page.js.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wpcom-migration-reprint-manual';

	/**
	 * Style handle for the screen's inline styles.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'wpcom-migration-reprint-manual';

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

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 21 );
		add_action( 'admin_post_' . self::SAVE_SECRET_ACTION, array( $this, 'handle_save_secret' ) );
		add_action( 'admin_post_' . self::SAVE_ENABLED_ACTION, array( $this, 'handle_save_enabled' ) );
		add_action( 'admin_post_' . self::DISCARD_SECRET_ACTION, array( $this, 'handle_discard_secret' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Priority 1: before the Command Palette (priority 10) lists $submenu.
		add_action( 'admin_enqueue_scripts', array( $this, 'hide_submenu' ), 1 );
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
	 * Registers the screen as a submenu of Settings_Page.
	 *
	 * Priority 21: Settings_Page creates the parent at 20.
	 */
	public function add_admin_menu() {
		$this->page_hook = add_submenu_page(
			Settings_Page::PAGE_SLUG,
			__( 'Set up by hand', 'wpcom-migration' ),
			__( 'Set up by hand', 'wpcom-migration' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Takes the screen out of the sidebar, along with the row WordPress adds
	 * for the parent the first time it gains a child.
	 *
	 * Can't run in add_admin_menu(): admin.php finds a submenu page's parent
	 * by scanning $submenu, and it does that before it lets the request in,
	 * so the rows have to survive until then. admin_enqueue_scripts fires
	 * once that check has passed.
	 */
	public function hide_submenu() {
		remove_submenu_page( Settings_Page::PAGE_SLUG, self::PAGE_SLUG );
		remove_submenu_page( Settings_Page::PAGE_SLUG, Settings_Page::PAGE_SLUG );
	}

	/**
	 * Enqueues the screen's script and styles on the screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		$plugin_data = get_file_data( $this->plugin_file, array( 'Version' => 'Version' ) );
		$version     = '' !== $plugin_data['Version'] ? $plugin_data['Version'] : false;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'reprint/manual-page.js', $this->plugin_file ),
			array( 'wp-a11y' ),
			$version,
			true
		);

		// Descriptions get a paragraph's margin, not wp-admin's tighter one.
		wp_register_style( self::STYLE_HANDLE, false, array(), $version );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, '.wpcom-migration-manual p.description { margin: 1em 0; }' );
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
	 * Removes the secret, turns the exporter off, and redirects back with a
	 * result notice.
	 */
	public function handle_discard_secret() {
		$this->authorize( self::DISCARD_SECRET_ACTION );

		Exporter::discard_credentials();
		$this->redirect_with_notice( 'discarded' );
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
	 *                       discarded, not_configured, storage_failure.
	 */
	private function redirect_with_notice( $result ) {
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY_ARG, $result, self::page_url() ) );
		exit;
	}

	/**
	 * Renders the screen: the secret form; the exporter toggle and export URL
	 * once the secret is valid; the blog ID when connected.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( is_multisite() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Set up by hand', 'wpcom-migration' ) . '</h1>';
			echo '<p>' . esc_html__( 'The exporter runs on single sites only.', 'wpcom-migration' ) . '</p></div>';
			return;
		}

		$state = Exporter::get_state();

		echo '<div class="wrap wpcom-migration-manual">';
		echo '<h1>' . esc_html__( 'Set up by hand', 'wpcom-migration' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Support may ask you to set this up by hand.', 'wpcom-migration' ) . '</p>';

		$this->render_result_notice();
		$this->render_secret_form( $state );

		if ( $state['secret_valid'] ) {
			$this->render_enable_form( $state );
			$this->render_api_url();
		}

		$blog_id = class_exists( '\Automattic\WPCOM_Migration\Connection' ) && \Automattic\WPCOM_Migration\Connection::is_user_connected()
			? \Automattic\WPCOM_Migration\Connection::blog_id()
			: null;
		if ( null !== $blog_id ) {
			echo '<hr />';
			echo '<p>' . sprintf(
				/* translators: %d: WordPress.com blog ID. */
				esc_html__( 'WordPress.com blog ID: %d', 'wpcom-migration' ),
				(int) $blog_id
			) . '</p>';
		}

		echo '</div>';
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
		if ( $state['has_secret'] ) {
			$this->render_discard_form();
		}
	}

	/**
	 * Renders the form that removes the secret.
	 */
	private function render_discard_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCARD_SECRET_ACTION ); ?>" />
			<?php wp_nonce_field( self::DISCARD_SECRET_ACTION ); ?>
			<button type="submit" name="wpcom_migration_reprint_discard_secret_submit" class="button-link"><?php esc_html_e( 'Remove secret', 'wpcom-migration' ); ?></button>
			<p class="description"><?php esc_html_e( 'Also turns the exporter off. WordPress.com can no longer export this site until a new secret is saved.', 'wpcom-migration' ); ?></p>
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
			'discarded'       => array( 'success', __( 'Secret removed. The exporter is off.', 'wpcom-migration' ) ),
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
