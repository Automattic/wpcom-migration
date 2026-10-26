<?php
/**
 * The migration key screen: connects the site by pasting the key shown on
 * WordPress.com.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use InvalidArgumentException;
use WordPress\Reprint\Server\Utils;

/**
 * Renders wp-admin/admin.php?page=wpcom-migration-key and handles its form.
 *
 * The form posts to admin-post.php with a nonce, like Manual_Page's.
 */
class Key_Page {

	/**
	 * Page slug. The screen lives at admin.php?page=<slug>.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wpcom-migration-key';

	/**
	 * The admin-post action that connects with the pasted key.
	 *
	 * @var string
	 */
	const CONNECT_ACTION = 'wpcom_migration_connect_migration_key';

	/**
	 * The admin-post action that deletes the stored key.
	 *
	 * @var string
	 */
	const DELETE_ACTION = 'wpcom_migration_delete_migration_key';

	/**
	 * Name of the key textarea.
	 *
	 * @var string
	 */
	const KEY_FIELD = 'wpcom_migration_migration_key';

	/**
	 * Query argument carrying the result of a form post back to the screen.
	 *
	 * @var string
	 */
	const NOTICE_QUERY_ARG = 'wpcom_migration_key_notice';

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
	 * Registers the screen, its form handler and its styles.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 21 );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Priority 1: before the Command Palette (priority 10) lists $submenu.
		add_action( 'admin_enqueue_scripts', array( $this, 'hide_submenu' ), 1 );
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
	 * The stored migration key, or '' for none. A secret on a host that
	 * doesn't verify keys counts as none. The status screen's button reads it
	 * too.
	 *
	 * @return string
	 */
	public static function stored_key() {
		return Utils::key_auth_required() ? (string) get_option( Exporter::PUBLIC_KEY_OPTION, '' ) : '';
	}

	/**
	 * Registers the screen as a submenu of Settings_Page.
	 *
	 * Priority 21: Settings_Page creates the parent at 20.
	 */
	public function add_admin_menu() {
		$this->page_hook = add_submenu_page(
			Settings_Page::PAGE_SLUG,
			__( 'Migration Key', 'wpcom-migration' ),
			__( 'Migration Key', 'wpcom-migration' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Takes the screen out of the sidebar while the status screen doesn't
	 * own the menu.
	 *
	 * Can't run in add_admin_menu(): admin.php finds a submenu page's parent
	 * by scanning $submenu, and it does that before it lets the request in,
	 * so the rows have to survive until then. admin_enqueue_scripts fires
	 * once that check has passed.
	 */
	public function hide_submenu() {
		if ( ! Settings_Page::owns_menu() ) {
			remove_submenu_page( Settings_Page::PAGE_SLUG, self::PAGE_SLUG );
		}
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

		Settings_Page::enqueue_styles( $this->plugin_file );
	}

	/**
	 * Drops other plugins' notices on this screen, as Settings_Page does.
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
	 * Connects with the pasted key and redirects to the migration screen, or
	 * back here with a result notice.
	 */
	public function handle_connect() {
		$this->authorize( self::CONNECT_ACTION );

		if ( ! Utils::key_auth_required() ) {
			// The screen's body explains why this host can't connect this way.
			wp_safe_redirect( self::page_url() );
			exit;
		}

		// Enrollment is the real check. The sanitizer only cleans the pasted text.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in authorize().
		$pasted_key = trim( sanitize_textarea_field( wp_unslash( $_POST[ self::KEY_FIELD ] ?? '' ) ) );

		if ( '' === $pasted_key ) {
			$this->redirect_with_notice( 'empty' );
		}

		try {
			Exporter::enroll_public_key( $pasted_key );
		} catch ( InvalidArgumentException $exception ) {
			$this->redirect_with_notice( 'invalid' );
		} catch ( Credential_Storage_Exception $exception ) {
			$this->redirect_with_notice( 'storage_failure' );
		}

		wp_safe_redirect( Settings_Page::page_url() );
		exit;
	}

	/**
	 * Deletes the stored key and redirects back with a result notice.
	 */
	public function handle_delete() {
		$this->authorize( self::DELETE_ACTION );

		Exporter::discard_public_key();

		$this->redirect_with_notice( 'deleted' );
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

		if ( ! in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
			wp_die( esc_html__( 'Only an administrator can manage the exporter.', 'wpcom-migration' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the screen with a result code and exits.
	 *
	 * @param string $result One of empty, deleted, invalid or storage_failure.
	 */
	private function redirect_with_notice( $result ) {
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY_ARG, $result, self::page_url() ) );
		exit;
	}

	/**
	 * Renders the screen: one centred column holding the key form.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key = self::stored_key();

		// Where wp-admin puts notices: above the page, not inside the column.
		echo '<div class="wpcom-migration-notices">';
		$this->render_result_notice();
		echo '</div>';
		?>
		<div class="wpcom-migration-container">
			<main class="wpcom-migration-content">
				<a class="wpcom-migration-back-link" href="<?php echo esc_url( Settings_Page::page_url() ); ?>"><?php echo is_rtl() ? '&rarr;' : '&larr;'; ?> <?php esc_html_e( 'Back to Connection page', 'wpcom-migration' ); ?></a>
				<h1><?php echo esc_html( '' !== $key ? __( 'Manage Migration Key', 'wpcom-migration' ) : __( 'Add Migration Key', 'wpcom-migration' ) ); ?></h1>
				<?php $this->render_body( $key ); ?>
			</main>
		</div>
		<?php
	}

	/**
	 * Renders what fits the site and the user under the heading.
	 *
	 * @param string $key The stored key, or '' for none.
	 */
	private function render_body( $key ) {
		if ( is_multisite() ) {
			Settings_Page::render_sentence( __( 'The exporter runs on single sites only, so this site can\'t be migrated from here.', 'wpcom-migration' ) );
			return;
		}

		if ( ! in_array( 'administrator', wp_get_current_user()->roles, true ) ) {
			Settings_Page::render_sentence( __( 'Only an administrator can connect this site with a migration key.', 'wpcom-migration' ) );
			return;
		}

		if ( ! Utils::key_auth_required() ) {
			$this->render_support_sentence();
			return;
		}

		if ( '' === $key ) {
			Settings_Page::render_sentence( __( 'Copy the migration key from WordPress.com and paste it here.', 'wpcom-migration' ) );
		} else {
			Settings_Page::render_sentence(
				/* translators: %s: a link reading "WordPress.com". */
				__( 'This is the migration key from WordPress.com. Paste a new one to replace it, then continue on %s.', 'wpcom-migration' ),
				Settings_Page::wordpress_com_link()
			);
			$this->render_source_sentence();
		}

		$this->render_form( $key );
	}

	/**
	 * Renders where the stored key came from, when that was recorded.
	 */
	private function render_source_sentence() {
		$sentences = array(
			'api'    => __( 'This key was installed for you after connecting to WordPress.com.', 'wpcom-migration' ),
			'pasted' => __( 'This key was installed by pasting it into wp-admin.', 'wpcom-migration' ),
		);

		$source = Exporter::public_key_source();
		if ( isset( $sentences[ $source ] ) ) {
			Settings_Page::render_sentence( $sentences[ $source ] );
		}
	}

	/**
	 * Renders the key form, with the terms it agrees to, and Delete next to
	 * Save while a key is stored. Delete posts its own form, which sits
	 * outside this one since forms can't nest.
	 *
	 * @param string $key The stored key, or '' for none.
	 */
	private function render_form( $key ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONNECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::CONNECT_ACTION ); ?>
			<p>
				<label class="wpcom-migration-label" for="wpcom-migration-key"><?php esc_html_e( 'Migration key', 'wpcom-migration' ); ?></label>
				<textarea id="wpcom-migration-key"
					class="wpcom-migration-textarea"
					name="<?php echo esc_attr( self::KEY_FIELD ); ?>"
					rows="7"
					placeholder="<?php esc_attr_e( 'Paste the migration key', 'wpcom-migration' ); ?>"
					spellcheck="false"
					autocomplete="off"
					autocapitalize="off"><?php echo esc_textarea( $key ); ?></textarea>
			</p>
			<div class="wpcom-migration-actions">
				<button type="submit" class="wpcom-migration-button"><?php esc_html_e( 'Save', 'wpcom-migration' ); ?></button>
				<?php if ( '' !== $key ) : ?>
					<button type="submit" form="wpcom-migration-delete-key" class="wpcom-migration-button wpcom-migration-button--secondary"><?php esc_html_e( 'Delete', 'wpcom-migration' ); ?></button>
				<?php endif; ?>
			</div>
			<?php \Automattic\WPCOM_Migration\Connect_Page::render_terms(); ?>
		</form>
		<?php if ( '' !== $key ) : ?>
			<form id="wpcom-migration-delete-key" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
				<?php wp_nonce_field( self::DELETE_ACTION ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders what to tell WordPress.com support on a host that can't verify
	 * a migration key.
	 */
	private function render_support_sentence() {
		echo '<p class="wpcom-migration-guidance">';
		printf(
			/* translators: %s: link to WordPress.com support, reading "Contact WordPress.com support". */
			esc_html( wptexturize( __( 'This site can\'t connect with a migration key. %s and say that you\'re trying to migrate your site to WordPress.com but can\'t paste the migration key.', 'wpcom-migration' ) ) ),
			'<a href="https://wordpress.com/help/" target="_blank" rel="noreferrer">' . esc_html__( 'Contact WordPress.com support', 'wpcom-migration' ) . '</a>'
		);
		echo '</p>';
	}

	/**
	 * Renders the notice for the result of the last form post, if any.
	 */
	private function render_result_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixed query value selects a read-only notice.
		$result = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY_ARG ] ?? '' ) );

		$notices = array(
			'deleted'         => array( 'success', __( 'Migration key deleted.', 'wpcom-migration' ) ),
			'empty'           => array( 'error', __( 'Paste the migration key first.', 'wpcom-migration' ) ),
			'invalid'         => array( 'error', __( 'That doesn\'t look like a migration key. Copy it again from WordPress.com.', 'wpcom-migration' ) ),
			'storage_failure' => array( 'error', __( 'The migration key couldn\'t be saved. Try again, or contact WordPress.com support.', 'wpcom-migration' ) ),
		);

		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		list( $type, $message ) = $notices[ $result ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline is-dismissible"><p>' . esc_html( wptexturize( $message ) ) . '</p></div>';
	}
}
