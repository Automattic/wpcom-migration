<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMWPAction')) :
	class WPCOMWPAction {
		public $settings;
		public $siteinfo;
		public $bvinfo;
		public $bvapi;

		public function __construct($settings, $siteinfo, $bvapi) {
			$this->settings = $settings;
			$this->siteinfo = $siteinfo;
			$this->bvapi = $bvapi;
			$this->bvinfo = new WPCOMInfo($settings);
		}
	
		public function activate() {
			if (!isset($_REQUEST['blogvaultkey'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				##BVKEYSLOCATE##
			}
			if (WPCOMAccount::isConfigured($this->settings)) {
				/* This informs the server about the activation */
				$info = array();
				$this->siteinfo->basic($info);
				$this->bvapi->pingbv('/bvapi/activate', $info);
			} else {
				WPCOMAccount::setup($this->settings);
			}
		}

		public function deactivate() {
			$info = array();
			$this->siteinfo->basic($info);
			##DISABLECACHE##
			$this->process_deactivation_feedback($info);

			$this->bvapi->pingbv('/bvapi/deactivate', $info);
		}

		public static function uninstall() {
			##CLEARPTCONFIG##
			##CLEARDYNSYNCCONFIG##
			##CLEARCACHECONFIG##
			do_action('wpcom_clear_bv_services_config');
			##CLEAR_WP_2FA_CONFIG##
			##REMOVE_BV_PRELOAD_ACTION##
			##CLEAR_PHP_ERROR_CONFIG##
		}

		public function clear_bv_services_config() {
			$this->settings->deleteOption($this->bvinfo->services_option_name);
		}

		##CLEAR_WP_2FA_CONFIG_FUNCTION##

		##SOUNINSTALLFUNCTION##

		public function footerHandler() {
			$bvfooter = $this->settings->getOption($this->bvinfo->badgeinfo);
			if ($bvfooter) {
				echo '<div style="max-width:150px;min-height:70px;margin:0 auto;text-align:center;position:relative;">
					<a href='.esc_url($bvfooter['badgeurl']).' target="_blank" ><img src="'.esc_url(plugins_url($bvfooter['badgeimg'], __FILE__)).'" alt="'.esc_attr($bvfooter['badgealt']).'" /></a></div>';
			}
		}

		private function process_deactivation_feedback(&$info) {
			//phpcs:disable WordPress.Security.NonceVerification.Recommended
			if (!isset($_GET['bv_deactivation_assets']) || !is_string($_GET['bv_deactivation_assets'])) {
				return;
			}

			$deactivation_assets = wp_unslash($_GET['bv_deactivation_assets']);
			$info['deactivation_feedback'] = base64_encode($deactivation_assets);
			//phpcs:disable WordPress.Security.NonceVerification.Recommended
		}

		##REMOVE_BV_PRELOAD##
	}
endif;