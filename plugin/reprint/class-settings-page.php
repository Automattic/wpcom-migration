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
	 * Style handle for settings-page.css.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'wpcom-migration-reprint-screen';

	/**
	 * The screen is not available: the site is a network.
	 *
	 * @var string
	 */
	const MODE_BLOCKED = 'blocked';

	/**
	 * A credential is stored but none matches the site's salts.
	 *
	 * @var string
	 */
	const MODE_BROKEN = 'broken';

	/**
	 * A credential is valid and the exporter is on.
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
	 * WordPress.com installed a credential; the exporter is off; no user connection.
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
	 * Hook suffix returned by add_menu_page().
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
	 * Registers the screen, its styles and its notice suppression.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'remove_admin_notices' ), 3 );
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
	 * Registers the screen as the plugin's one sidebar entry, then takes the
	 * row back out on a network, when the old screen's brand has asked for
	 * no menu, or while WordPress.com names the old screen main. The screen
	 * stays reachable at its URL either way.
	 */
	public function add_admin_menu() {
		$this->page_hook = add_menu_page(
			__( 'Migrate to WordPress.com', 'wpcom-migration' ),
			__( 'Migrate to WordPress.com', 'wpcom-migration' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-wordpress-alt'
		);

		/**
		 * Filters whether the screen gets a sidebar row. The screen stays
		 * reachable at its URL either way.
		 *
		 * The old BlogVault screen answers false when the plugin is
		 * whitelabelled to hide. Delete the filter with that screen.
		 *
		 * @param bool $show Whether the screen gets a sidebar row.
		 */
		if ( apply_filters( 'wpcom_migration_show_menu', true ) && ! is_multisite() && Main_Screen::is_reprint() ) {
			return;
		}

		remove_menu_page( self::PAGE_SLUG );
		add_action( 'load-' . $this->page_hook, array( $this, 'set_title' ) );
	}

	/**
	 * Sets the screen's <title>, which core otherwise looks up in the
	 * sidebar rows.
	 */
	public function set_title() {
		global $title;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin-header.php prints this global.
		$title = __( 'Migrate to WordPress.com', 'wpcom-migration' );
	}

	/**
	 * Enqueues the screen's styles on the screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		$plugin_data = get_file_data( $this->plugin_file, array( 'Version' => 'Version' ) );
		$version     = '' !== $plugin_data['Version'] ? $plugin_data['Version'] : false;

		wp_enqueue_style(
			'wpcom-migration-variables',
			plugins_url( 'assets/css/variables.css', $this->plugin_file ),
			array(),
			$version
		);
		wp_enqueue_style(
			'wpcom-migration-fonts',
			plugins_url( 'assets/css/fonts.css', $this->plugin_file ),
			array(),
			$version
		);
		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'reprint/settings-page.css', $this->plugin_file ),
			array( 'wpcom-migration-variables', 'wpcom-migration-fonts' ),
			$version
		);
	}

	/**
	 * Drops other plugins' notices on this screen, as the old main screen
	 * does: the layout has nowhere to put them.
	 */
	public function remove_admin_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Comparing the page slug selects a display behaviour.
		if ( self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Renders the screen: one centred column holding the mode.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state          = Exporter::get_state();
		$user_connected = null !== $this->connection_section && \Automattic\WPCOM_Migration\Connection::is_user_connected();
		$mode           = self::mode( $state, $user_connected );
		if ( null !== $this->connection_section ) {
			// Where wp-admin puts notices: above the page, not inside the column.
			echo '<div class="wpcom-migration-notices">';
			$this->connection_section->render_result_notice();
			echo '</div>';
		}
		?>
		<div class="wpcom-migration-container">
			<main class="wpcom-migration-content">
				<?php $this->render_mode( $mode, $user_connected ); ?>
			</main>
		</div>
		<?php
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

		if ( $state['has_credential'] && ! $state['credential_valid'] ) {
			return self::MODE_BROKEN;
		}

		if ( $state['credential_valid'] && $state['window_open'] ) {
			return self::MODE_READY;
		}

		if ( $user_connected ) {
			return self::MODE_CONNECTED_WAITING;
		}

		if ( $state['credential_valid'] ) {
			return self::MODE_PROVISIONED_WAITING;
		}

		return self::MODE_NEEDS_CONNECTING;
	}

	/**
	 * Renders the mode: a status pill where one fits, the heading, where the
	 * site stands, and the controls that fit. Connection controls render only
	 * when a section is attached.
	 *
	 * @param string $mode           One of the MODE_* constants.
	 * @param bool   $user_connected Whether the current user is connected to WordPress.com.
	 */
	private function render_mode( $mode, $user_connected ) {
		$section = $this->connection_section;

		switch ( $mode ) {
			case self::MODE_BLOCKED:
				$this->render_pill_and_heading();
				$this->render_sentence( __( 'The exporter runs on single sites only, so this site can\'t be migrated from here.', 'wpcom-migration' ) );
				return;

			case self::MODE_BROKEN:
				$this->render_pill_and_heading();
				$this->render_sentence( __( 'The export credential no longer matches this site — its security salts changed. Start the migration again on WordPress.com.', 'wpcom-migration' ) );
				if ( null !== $section && $user_connected ) {
					$section->render_continue_button();
					$section->render_disconnect_link();
				} elseif ( null !== $section ) {
					$section->render_connect_button();
				}
				return;

			case self::MODE_READY:
				$this->render_pill_and_heading( __( 'Site ready to migrate', 'wpcom-migration' ), 'info' );
				$this->render_sentence( __( 'Your site is now ready to be copied by WordPress.com. Your site stays online and unchanged while it\'s copied.', 'wpcom-migration' ) );
				$this->render_sentence( __( 'Keep this plugin active until the migration is done. We email you when it\'s finished.', 'wpcom-migration' ) );
				if ( null !== $section ) {
					$section->render_continue_button();
				}
				return;

			case self::MODE_CONNECTED_WAITING:
				$this->render_pill_and_heading( __( 'Connected to WordPress.com', 'wpcom-migration' ), 'success' );
				$this->render_ready_to_migrate();
				if ( null !== $section ) {
					$section->render_continue_button();
					$section->render_disconnect_link();
				}
				return;

			case self::MODE_PROVISIONED_WAITING:
				$this->render_pill_and_heading( __( 'Set up by WordPress.com', 'wpcom-migration' ), 'info' );
				$this->render_ready_to_migrate();
				if ( null !== $section ) {
					$section->render_continue_button();
				}
				return;

			case self::MODE_NEEDS_CONNECTING:
			default:
				$this->render_pill_and_heading();
				$this->render_sentence( __( 'WordPress.com copies your posts, pages, media, themes, plugins, and settings. Your site stays online and unchanged while we copy it.', 'wpcom-migration' ) );
				$this->render_sentence( __( 'Log in with your WordPress.com account to connect this site. Nothing is copied until you start the migration on WordPress.com.', 'wpcom-migration' ) );
				if ( null !== $section ) {
					$section->render_connect_button();
				}
				return;
		}
	}

	/**
	 * Renders the heading, with a status pill above it when given one.
	 *
	 * @param string $pill Pill text, or '' for none.
	 * @param string $tone success (green) or info (blue).
	 */
	private function render_pill_and_heading( $pill = '', $tone = 'info' ) {
		if ( '' !== $pill ) {
			echo '<p class="wpcom-migration-pill wpcom-migration-pill--' . esc_attr( $tone ) . '">' . esc_html( $pill ) . '</p>';
		}
		echo '<h1>' . esc_html__( 'Migrate your site to WordPress.com', 'wpcom-migration' ) . '</h1>';
	}

	/**
	 * Renders the sentence the two waiting modes share.
	 */
	private function render_ready_to_migrate() {
		$this->render_sentence(
			sprintf(
				/* translators: %s: the site's address, without the scheme. */
				__( '%s is ready to migrate. The migration runs from WordPress.com, so there\'s nothing else to do here.', 'wpcom-migration' ),
				untrailingslashit( preg_replace( '#^https?://#i', '', home_url() ) )
			)
		);
	}

	/**
	 * Renders one paragraph of the mode.
	 *
	 * @param string $text Plain text.
	 */
	private function render_sentence( $text ) {
		echo '<p class="wpcom-migration-guidance">' . esc_html( $text ) . '</p>';
	}
}
