<?php
/**
 * Sends Automattic-proxied users from the BlogVault screen to the Reprint
 * screen.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * While WordPress.com names the BlogVault screen main, a script on that
 * screen asks the flag endpoint again from the browser. WordPress.com answers
 * reprint when the request comes through Automattic's proxy, which the
 * site's own request never does. On that answer the script leads to
 * PIN_ACTION, which pins the Reprint screen as main and opens it.
 */
class Proxied_Redirect {

	/**
	 * The admin-post action that pins the Reprint screen.
	 *
	 * @var string
	 */
	const PIN_ACTION = 'wpcom_migration_pin_reprint';

	/**
	 * Script handle for proxied-redirect.js.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wpcom-migration-proxied-redirect';

	/**
	 * The BlogVault screen's hook suffix.
	 *
	 * @var string
	 */
	const BLOGVAULT_HOOK = 'toplevel_page_wpcom-migration';

	/**
	 * Absolute path of the plugin's main file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Registers the script and the pin handler.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::PIN_ACTION, array( $this, 'handle_pin' ) );
	}

	/**
	 * Enqueues the script on the BlogVault screen while it is main. Never on
	 * a network, where the Reprint screen can't migrate.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( self::BLOGVAULT_HOOK !== $hook_suffix || is_multisite() || Main_Screen::is_reprint() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$plugin_data = get_file_data( $this->plugin_file, array( 'Version' => 'Version' ) );
		$version     = '' !== $plugin_data['Version'] ? $plugin_data['Version'] : false;

		// In the head, so the request starts before the screen renders. The
		// script touches no DOM.
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'reprint/proxied-redirect.js', $this->plugin_file ),
			array(),
			$version,
			false
		);
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'var wpcomMigrationProxiedRedirect = ' . wp_json_encode(
				array(
					'endpoint' => Main_Screen::endpoint_url(),
					'pinUrl'   => self::pin_url(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Where the script leads on a reprint answer.
	 *
	 * @return string
	 */
	public static function pin_url() {
		return add_query_arg(
			array(
				'action'   => self::PIN_ACTION,
				'_wpnonce' => wp_create_nonce( self::PIN_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Pins the Reprint screen as main and opens it.
	 */
	public function handle_pin() {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wpcom-migration' ), 403 );
		}

		check_admin_referer( self::PIN_ACTION );

		Main_Screen::pin();

		wp_safe_redirect( Settings_Page::page_url() );
		exit;
	}
}
