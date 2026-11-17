<?php
/**
 * What Reprint needs to export one site of a network.
 *
 * Ported from get_multisite_export_context() in Reprint's own plugin,
 * reprint-server-wp/wordpress/multisite.php.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use RuntimeException;
use WordPress\Reprint\Server\MultisiteDatabaseSelection;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages travel in the exporter's JSON errors, not HTML.

/**
 * Builds the trusted source context HTTPServer's `multisite` option takes,
 * for the site WordPress loaded for this request's URL.
 *
 * With it, Reprint exports that site's tables, the network users who belong
 * to it, the shared code and that site's uploads. Without it, Reprint would
 * export the whole network.
 */
class Multisite_Context {

	/**
	 * The context for the current site.
	 *
	 * No switch_to_blog(): it changes tables but does not load the other
	 * site's plugins. Paths are absolute, with no trailing slash.
	 *
	 * @return array {
	 *     @type int    $site_id             Selected site ID.
	 *     @type int    $network_id          Selected network ID.
	 *     @type string $base_prefix         Network table prefix.
	 *     @type string $abspath             WordPress root.
	 *     @type string $content_dir         abspath/wp-content.
	 *     @type string $exporter_dir        This plugin's directory, left out of the export.
	 *     @type string $uploads_dir         content_dir/uploads for site 1,
	 *                                       content_dir/uploads/sites/7 for site 7.
	 *     @type string $uploads_url         Uploads base URL.
	 *     @type string $home_url            Selected home URL.
	 *     @type string $site_url            Selected WordPress URL.
	 *     @type string $content_url         Shared content URL.
	 *     @type string $network_content_url Shared network content URL.
	 * }
	 * @throws RuntimeException When the network's layout can't be split into
	 *                          one site safely. The message says why.
	 */
	public static function build() {
		global $wpdb;

		if ( defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ) ) {
			throw new RuntimeException( 'This network uses custom user tables, which can\'t be migrated one site at a time.' );
		}

		$site_id     = (int) get_current_blog_id();
		$network_id  = (int) get_current_network_id();
		$base_prefix = $wpdb->base_prefix;

		$site = get_site( $site_id );
		if ( ! $site || $site->archived || $site->spam || $site->deleted ) {
			throw new RuntimeException( 'This site is archived, marked as spam, deleted or missing.' );
		}

		// Legacy networks keep uploads in blogs.dir and rewrite media URLs.
		// Reprint's file selection expects uploads/ and uploads/sites/<id>/.
		if ( get_site_option( 'ms_files_rewriting' ) || defined( 'UPLOADS' ) || defined( 'BLOGUPLOADDIR' ) ) {
			throw new RuntimeException( 'This network uses the legacy uploads layout, which can\'t be migrated one site at a time.' );
		}

		// Reprint walks files from ABSPATH, so wp-content has to be inside it.
		if ( rtrim( WP_CONTENT_DIR, '/' ) !== rtrim( ABSPATH, '/' ) . '/wp-content' ) {
			throw new RuntimeException( 'This network keeps wp-content outside the WordPress directory, which can\'t be migrated one site at a time.' );
		}

		// basedir, not path: path can end in the current month. Passing false
		// keeps wp_upload_dir() from creating directories during an export.
		$uploads          = wp_upload_dir( null, false );
		$expected_uploads = WP_CONTENT_DIR . '/uploads' . ( 1 === $site_id ? '' : '/sites/' . $site_id );
		if ( rtrim( $uploads['basedir'], '/' ) !== $expected_uploads ) {
			throw new RuntimeException( 'This site keeps its uploads in a custom directory, which can\'t be migrated one site at a time: ' . $uploads['basedir'] );
		}

		self::assert_tables_separable( $base_prefix, $network_id );

		return array(
			'site_id'             => $site_id,
			'network_id'          => $network_id,
			'base_prefix'         => $base_prefix,
			'abspath'             => rtrim( ABSPATH, '/' ),
			'content_dir'         => rtrim( WP_CONTENT_DIR, '/' ),
			'exporter_dir'        => rtrim( dirname( __DIR__ ), '/' ),
			'uploads_dir'         => rtrim( $uploads['basedir'], '/' ),
			'uploads_url'         => rtrim( $uploads['baseurl'], '/' ),
			'home_url'            => get_option( 'home' ),
			'site_url'            => get_option( 'siteurl' ),
			'content_url'         => content_url(),
			'network_content_url' => network_site_url( '/wp-content' ),
		);
	}

	/**
	 * Refuses a network with a table Reprint has no rule for.
	 *
	 * Such a table may hold several sites' rows, such as a plugin's table
	 * shared by the whole network, and Reprint can't tell which rows are this
	 * site's. Asks Reprint's rules, not $wpdb->tables: a plugin registering
	 * its table must not make it exportable. Runs on every export request.
	 *
	 * @param string $base_prefix Network table prefix.
	 * @param int    $network_id  Selected network ID.
	 * @throws RuntimeException Naming the first table without a rule.
	 */
	private static function assert_tables_separable( $base_prefix, $network_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Needs the current table list, including tables added since the last request.
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
			if ( 0 !== strpos( $table, $base_prefix ) ) {
				continue;
			}

			$suffix = substr( $table, strlen( $base_prefix ) );

			// BlogVault's tables (WPCOMWPDb::getBVTable()). Reprint has no rule
			// for them, so it leaves them out of the export.
			if ( 0 === strpos( $suffix, 'bv_' ) ) {
				continue;
			}

			$table_site_id = preg_match( '/^([1-9][0-9]*)_/', $suffix, $matches ) ? (int) $matches[1] : 1;
			$selection     = new MultisiteDatabaseSelection( $base_prefix, $table_site_id, $network_id );
			if ( $table === $selection->get_user_table_name() ) {
				continue;
			}
			if ( ! $selection->includes_table( $table ) ) {
				throw new RuntimeException( 'The table ' . $table . ' may hold data from several sites of this network, so this site can\'t be migrated on its own.' );
			}
		}
	}
}
