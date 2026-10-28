<?php
/**
 * Points Automattic-proxied users on the BlogVault screen to the Reprint
 * screen.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

/**
 * While WordPress.com names the BlogVault screen main, a script on that
 * screen asks the flag endpoint again from the browser. WordPress.com answers
 * reprint when the request comes through Automattic's proxy, which the
 * site's own request never does. On that answer the script shows a notice
 * linking to the Reprint screen.
 */
class Proxied_Notice {

	/**
	 * Script handle for proxied-notice.js.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wpcom-migration-proxied-notice';

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
	 * Registers the script.
	 *
	 * @param string $plugin_file Absolute path of the plugin's main file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'reprint/proxied-notice.js', $this->plugin_file ),
			array(),
			$version,
			true
		);
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'var wpcomMigrationProxiedNotice = ' . wp_json_encode(
				array(
					'endpoint' => Main_Screen::ENDPOINT,
					'message'  => __( 'Testing the new migration flow?', 'wpcom-migration' ),
					'linkText' => __( 'Open the new screen →', 'wpcom-migration' ),
					'linkUrl'  => Settings_Page::page_url(),
				)
			) . ';',
			'before'
		);
	}
}
