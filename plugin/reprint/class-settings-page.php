<?php
/**
 * The Reprint migration screen: connect to WordPress.com or add the
 * migration key, then continue on WordPress.com.
 *
 * Modeled on reprint-server-wp's SettingsPage.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * Renders wp-admin/admin.php?page=wpcom-migration-status and places the
 * WordPress.com connection controls, and owns the plugin's sidebar row.
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
	 * Registers the screen as the plugin's one sidebar entry, with Connection
	 * as its first flyout row, unless owns_menu() says otherwise. The screen
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

		if ( self::owns_menu() ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Migrate to WordPress.com', 'wpcom-migration' ),
				__( 'Connection', 'wpcom-migration' ),
				'manage_options',
				self::PAGE_SLUG
			);
			return;
		}

		// Gone from $menu, the screen gets no flyout row of its own when the
		// key and manual screens attach to it.
		remove_menu_page( self::PAGE_SLUG );
		add_action( 'load-' . $this->page_hook, array( $this, 'set_title' ) );
	}

	/**
	 * Whether the screen gets the plugin's sidebar row, with Connection and
	 * Migration Key under it: while WordPress.com names it main, and while
	 * the user is on it or the key screen, so whoever reached the new
	 * screens can move between them. Never on a network. The old screen
	 * gives up its row whenever this one has it.
	 *
	 * @return bool
	 */
	public static function owns_menu() {
		/**
		 * Filters whether the screen gets a sidebar row. The screen stays
		 * reachable at its URL either way.
		 *
		 * The old BlogVault screen answers false when the plugin is
		 * whitelabelled to hide. Delete the filter with that screen.
		 *
		 * @param bool $show Whether the screen gets a sidebar row.
		 */
		if ( ! apply_filters( 'wpcom_migration_show_menu', true ) || is_multisite() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Comparing the page slug selects a display behaviour.
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );

		return Main_Screen::is_reprint() || in_array( $page, array( self::PAGE_SLUG, Key_Page::PAGE_SLUG ), true );
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

		self::enqueue_styles( $this->plugin_file );
	}

	/**
	 * Enqueues the screen stylesheets, which Key_Page shares.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public static function enqueue_styles( $plugin_file ) {
		$plugin_data = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );
		$version     = '' !== $plugin_data['Version'] ? $plugin_data['Version'] : false;

		wp_enqueue_style(
			'wpcom-migration-variables',
			plugins_url( 'assets/css/variables.css', $plugin_file ),
			array(),
			$version
		);
		wp_enqueue_style(
			'wpcom-migration-fonts',
			plugins_url( 'assets/css/fonts.css', $plugin_file ),
			array(),
			$version
		);
		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'reprint/settings-page.css', $plugin_file ),
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
	 * Renders the screen: one centred column.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( null !== $this->connection_section ) {
			// Where wp-admin puts notices: above the page, not inside the column.
			echo '<div class="wpcom-migration-notices">';
			$this->connection_section->render_result_notice();
			echo '</div>';
		}
		?>
		<div class="wpcom-migration-container">
			<main class="wpcom-migration-content">
				<h1><?php esc_html_e( 'Migrate your site to WordPress.com', 'wpcom-migration' ); ?></h1>
				<?php $this->render_body(); ?>
			</main>
		</div>
		<?php
	}

	/**
	 * Renders what goes under the heading. A user connected to WordPress.com
	 * is sent on to it; with a key stored, the primary button sends them on
	 * too, while a secondary one still offers the connection; anyone else is
	 * asked to connect or to add the migration key. Either way the key button
	 * is there, adding one or managing the one stored.
	 */
	private function render_body() {
		if ( is_multisite() ) {
			self::render_sentence( __( 'The exporter runs on single sites only, so this site can\'t be migrated from here.', 'wpcom-migration' ) );
			return;
		}

		$section        = $this->connection_section;
		$user_connected = null !== $section && \Automattic\WPCOM_Migration\Connection::is_user_connected();
		$has_key        = '' !== Key_Page::stored_key();

		if ( $user_connected ) {
			self::render_sentence( __( 'Your site is ready to migrate. Head over to WordPress.com to start and manage the migration. Your site stays online and unchanged while it\'s copied.', 'wpcom-migration' ) );
			self::render_sentence( __( 'Keep this plugin active until the migration is done. We\'ll email you when it\'s finished.', 'wpcom-migration' ) );
		} elseif ( $has_key ) {
			// The key is stored, not known to be the right one, so this doesn't claim the site is ready.
			self::render_sentence(
				/* translators: %s: a link reading "WordPress.com". */
				__( 'Migration key added. Head over to %s to start and manage the migration. Your site stays online and unchanged while it\'s copied.', 'wpcom-migration' ),
				self::wordpress_com_link()
			);
			self::render_sentence( __( 'Keep this plugin active until the migration is done. We\'ll email you when it\'s finished.', 'wpcom-migration' ) );
		} else {
			self::render_sentence( __( 'WordPress.com copies your posts, pages, media, themes, plugins, and settings. Your site stays online and unchanged while we copy it.', 'wpcom-migration' ) );
			self::render_sentence(
				/* translators: %s: a link reading "WordPress.com". */
				__( 'Log in with your WordPress.com account to connect this site, or copy over the Migration Key. Then start the migration on %s.', 'wpcom-migration' ),
				self::wordpress_com_link()
			);
		}

		echo '<div class="wpcom-migration-actions">';
		if ( null !== $section ) {
			if ( $user_connected ) {
				$section->render_continue_button();
			} elseif ( $has_key ) {
				$section->render_continue_button();
				$section->render_connect_button( true );
			} else {
				$section->render_connect_button();
			}
		}
		echo '<a class="wpcom-migration-button wpcom-migration-button--secondary" href="' . esc_url( Key_Page::page_url() ) . '">'
			. esc_html( $has_key ? __( 'Manage Migration Key', 'wpcom-migration' ) : __( 'Add Migration Key', 'wpcom-migration' ) )
			. '</a>';
		echo '</div>';

		if ( null === $section ) {
			return;
		}

		if ( $user_connected ) {
			$section->render_account();
		} else {
			\Automattic\WPCOM_Migration\Connect_Page::render_terms();
		}
	}

	/**
	 * Renders one paragraph. The key screen shares it.
	 *
	 * @param string $text Plain text, with %s where $link goes when given one.
	 * @param string $link Escaped HTML for the %s, or '' for none.
	 */
	public static function render_sentence( $text, $link = '' ) {
		$html = esc_html( wptexturize( $text ) );
		if ( '' !== $link ) {
			$html = sprintf( $html, $link );
		}

		echo '<p class="wpcom-migration-guidance">' . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * A link reading "WordPress.com" to the user's sites there, in a new tab,
	 * marked the way WordPress's ExternalLink component marks one, for a user
	 * with no button leading there, such as one who only pasted a key. It
	 * asks WordPress.com nothing. The key screen shares it.
	 *
	 * @return string Escaped HTML.
	 */
	public static function wordpress_com_link() {
		return '<a class="wpcom-migration-external-link" href="' . esc_url( \Automattic\WPCOM_Migration\Connect_Page::SITES_URL ) . '" target="_blank" rel="noreferrer">'
			. '<span class="wpcom-migration-external-link__contents">WordPress.com</span>'
			. '<span class="wpcom-migration-external-link__icon" aria-label="' . esc_attr__( '(opens in a new tab)', 'wpcom-migration' ) . '">' . ( is_rtl() ? '&#8598;' : '&#8599;' ) . '</span>'
			. '</a>';
	}
}
