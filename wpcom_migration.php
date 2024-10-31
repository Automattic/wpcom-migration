<?php
/*
Plugin Name: Migrate to WordPress.com
Plugin URI: https://www.wordpress.com
Description: The easiest way to migrate your site to WordPress.com.
Author: WordPress.com
Author URI: https://www.wordpress.com
Version: 5.81
Network: True
 */

/*  Copyright 2017  Migrate to WordPress.com  (email : support@blogvault.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* Global response array */

if (!defined('ABSPATH')) exit;
##OLDWPR##

require_once dirname( __FILE__ ) . '/wp_settings.php';
require_once dirname( __FILE__ ) . '/wp_site_info.php';
require_once dirname( __FILE__ ) . '/wp_db.php';
require_once dirname( __FILE__ ) . '/wp_api.php';
require_once dirname( __FILE__ ) . '/wp_actions.php';
require_once dirname( __FILE__ ) . '/info.php';
require_once dirname( __FILE__ ) . '/account.php';
require_once dirname( __FILE__ ) . '/helper.php';
##WP_2FA_REQUIRE_FILE##
##WP_LOGIN_WHITELABEL_REQUIRE_FILE##
##WPCACHEMODULE##


$bvsettings = new WPCOMWPSettings();
$bvsiteinfo = new WPCOMWPSiteInfo();
$bvdb = new WPCOMWPDb();


$bvapi = new WPCOMWPAPI($bvsettings);
$bvinfo = new WPCOMInfo($bvsettings);
$wp_action = new WPCOMWPAction($bvsettings, $bvsiteinfo, $bvapi);

register_uninstall_hook(__FILE__, array('WPCOMWPAction', 'uninstall'));
register_activation_hook(__FILE__, array($wp_action, 'activate'));
register_deactivation_hook(__FILE__, array($wp_action, 'deactivate'));


add_action('wp_footer', array($wp_action, 'footerHandler'), 100);
add_action('wpcom_clear_bv_services_config', array($wp_action, 'clear_bv_services_config'));

##SOADDUNINSTALLACTION##

##DISABLE_OTHER_OPTIMIZATION_PLUGINS##

if (defined('WP_CLI') && WP_CLI) {
		require_once dirname( __FILE__ ) . '/wp_cli.php';
		$wp_cli = new WPCOMWPCli($bvsettings, $bvinfo, $bvsiteinfo, $bvapi);
		WP_CLI::add_command("bvwpcom", $wp_cli);
}

if (is_admin()) {
	require_once dirname( __FILE__ ) . '/wp_admin.php';
	$wpadmin = new WPCOMWPAdmin($bvsettings, $bvsiteinfo);
	add_action('admin_init', array($wpadmin, 'initHandler'));
	add_filter('all_plugins', array($wpadmin, 'initWhitelabel'));
	add_filter('plugin_row_meta', array($wpadmin, 'hidePluginDetails'), 10, 2);
	##HEALTH_INFO_HOOK##
	if ($bvsiteinfo->isMultisite()) {
		add_action('network_admin_menu', array($wpadmin, 'menu'));
	} else {
		add_action('admin_menu', array($wpadmin, 'menu'));
	}
	add_filter('plugin_action_links', array($wpadmin, 'settingsLink'), 10, 2);
	add_action('admin_head', array($wpadmin, 'removeAdminNotices'), 3);
	##POPUP_ON_DEACTIVATION##
	##ACTIVATEWARNING##
	add_action('admin_enqueue_scripts', array($wpadmin, 'wpcomsecAdminMenu'));
	##ALPURGECACHEFUNCTION##
	##ALADMINMENU##
}

if ((array_key_exists('bvreqmerge', $_POST)) || (array_key_exists('bvreqmerge', $_GET))) {
	$_REQUEST = array_merge($_GET, $_POST);
}

##REMOVE_BV_PRELOAD_MODULE##
##PHP_ERROR_MONITORING_MODULE##
if ($bvinfo->hasValidDBVersion()) {
	##ACTLOGMODULE##
	##MAINTENANCEMODULE##
}

if ((array_key_exists('bvplugname', $_REQUEST)) && ($_REQUEST['bvplugname'] == "wpcom-migration")) {
	require_once dirname( __FILE__ ) . '/callback/base.php';
	require_once dirname( __FILE__ ) . '/callback/response.php';
	require_once dirname( __FILE__ ) . '/callback/request.php';
	require_once dirname( __FILE__ ) . '/recover.php';

	$pubkey = WPCOMAccount::sanitizeKey($_REQUEST['pubkey']);

	if (array_key_exists('rcvracc', $_REQUEST)) {
		$account = WPCOMRecover::find($bvsettings, $pubkey);
	} else {
		$account = WPCOMAccount::find($bvsettings, $pubkey);
	}

	$request = new BVCallbackRequest($account, $_REQUEST, $bvsettings);
	$response = new BVCallbackResponse($request->bvb64cksize);

	if ($request->authenticate() === 1) {
		if (array_key_exists('bv_ignr_frm_cptch', $_REQUEST)) {
			##DISABLE_CAPTCHA_IN_FORM_PLUGINS##
		} else {
			##BVBASEPATH##

			require_once dirname( __FILE__ ) . '/callback/handler.php';

			$params = $request->processParams($_REQUEST);
			if ($params === false) {
				$response->terminate($request->corruptedParamsResp());
			}
			$request->params = $params;
			$callback_handler = new BVCallbackHandler($bvdb, $bvsettings, $bvsiteinfo, $request, $account, $response);
			if ($request->is_afterload) {
				add_action('wp_loaded', array($callback_handler, 'execute'));
			} else if ($request->is_admin_ajax) {
				add_action('wp_ajax_bvadm', array($callback_handler, 'bvAdmExecuteWithUser'));
				add_action('wp_ajax_nopriv_bvadm', array($callback_handler, 'bvAdmExecuteWithoutUser'));
			} else {
				$callback_handler->execute();
			}
		}
	} else {
		$response->terminate($request->authFailedResp());
	}
} else {
	if ($bvinfo->hasValidDBVersion()) {
		##PROTECTMODULE##
		##DYNSYNCMODULE##
	}
	##WPAUTOUPDATEBLOCKMODULE##
	##HIDEPLUGINUPDATEMODULE##
	##THIRDPARTYCACHINGMODULE##
}

##WP2FAMODULE##
##WP_LOGIN_WHITELABEL_MODULE##
##CLEAR_WP_2FA_CONFIG_ACTION##