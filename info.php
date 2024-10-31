<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMInfo')) :
	class WPCOMInfo {
		public $settings;
		public $config;
		public $plugname = 'wpcom-migration';
		public $brandname = 'Migrate to WordPress.com';
		public $badgeinfo = 'wpcombadge';
		public $ip_header_option = 'wpcomipheader';
		public $brand_option = 'wpcombrand';
		public $wp_lp_whitelabel_option = 'wpcomLpWhitelabelConf';
		public $version = '5.81';
		public $webpage = 'https://www.wordpress.com/';
		public $appurl = 'https://migrate.blogvault.net';
		public $slug = 'wpcom-migration/wpcom_migration.php';
		public $plug_redirect = 'wpcomredirect';
		public $logo = '../assets/img/wordpress.com.png';
		public $brand_icon = 'dashicons-wordpress-alt';
		public $services_option_name = 'BVSERVICESOPTIONNAME';
		public $author = 'WordPress.com';
		public $title = 'Migrate to WordPress.com';

		const DB_VERSION = '5';
		const AL_CONF_VERSION = '1.1';

		public function __construct($settings) {
			$this->settings = $settings;
			$this->config = $this->settings->getOption($this->services_option_name);
		}

		public function getCurrentDBVersion() {
			$bvconfig = $this->config;
			if ($bvconfig && array_key_exists('db_version', $bvconfig)) {
				return $bvconfig['db_version'];
			}
			return false;
		}

		public function hasValidDBVersion() {
			return WPCOMInfo::DB_VERSION === $this->getCurrentDBVersion();
		}

		public function getLatestWooCommerceDBVersion() {
			if (defined('WC_ABSPATH') && file_exists(WC_ABSPATH . 'includes/class-wc-install.php')) {
				include_once WC_ABSPATH . 'includes/class-wc-install.php';

				if (class_exists('WC_Install')) {
					$update_versions = array_keys(WC_Install::get_db_update_callbacks());

					if (!empty($update_versions)) {
						asort($update_versions);
						return end($update_versions);
					}
				}
			}

			return false;
		}

		public function getConnectionKey() {
			require_once dirname( __FILE__ ) . '/recover.php';
			$bvsiteinfo = new WPCOMWPSiteInfo();
			$encoded_url = base64_encode($bvsiteinfo->siteurl());
			$secret = WPCOMRecover::defaultSecret($this->settings);

			return base64_encode("v2:".$secret.":".$encoded_url.":".$this->plugname);
		}

		public function getDefaultSecret() {
			require_once dirname( __FILE__ ) . '/recover.php';
			$bvsiteinfo = new WPCOMWPSiteInfo();
			return WPCOMRecover::defaultSecret($this->settings);
		}

		public function getLatestElementorDBVersion($file) {
			$managerClass = $file === "elementor/elementor.php" ? '\Elementor\Core\Upgrade\Manager' : '\ElementorPro\Core\Upgrade\Manager';

			if (!class_exists($managerClass)) {
				return false;
			}

			$manager = new $managerClass();
			return $manager->get_new_version();
		}

		public static function getRequestID() {
			if (!defined("BV_REQUEST_ID")) {
				define("BV_REQUEST_ID", uniqid(mt_rand()));
			}
			return BV_REQUEST_ID;
		}

		public function canSetCWBranding() {
			if (WPCOMWPSiteInfo::isCWServer()) {

				$bot_protect_accounts = WPCOMAccount::accountsByType($this->settings, 'botprotect');
				if (sizeof($bot_protect_accounts) >= 1)
					return true;

				$bot_protect_accounts = WPCOMAccount::accountsByPattern($this->settings, 'email', '/@cw_user\.com$/');
				if (sizeof($bot_protect_accounts) >= 1)
					return true;
			}

			return false;
		}

		public function canWhiteLabel($slug = NULL) {
			if (array_key_exists("bv_override_global_whitelabel", $_REQUEST)) {
				return false;
			}
			if (array_key_exists("bv_override_plugin_whitelabel", $_REQUEST) && isset($slug) &&
				$_REQUEST["bv_override_plugin_whitelabel"] === $slug) {
				return false;
			}
			return true;
		}

		public function getPluginWhitelabelInfo($slug = null) {
			if ($slug === null) {
				$slug = $this->slug;
			}
			$whitelabel_infos = $this->getPluginsWhitelabelInfos();
			if (!array_key_exists($slug, $whitelabel_infos) || !is_array($whitelabel_infos[$slug])) {
				return array();
			}
			return $whitelabel_infos[$slug];
		}

		public function getBrandInfo() {
			return $this->settings->getOption($this->brand_option);
		}

		public function getPluginsWhitelabelInfos() {
			$whitelabel_infos = $this->settings->getOption($this->brand_option);
			return is_array($whitelabel_infos) ? $whitelabel_infos : array();
		}

		public function getLPWhitelabelInfo() {
			$infos = $this->settings->getOption($this->wp_lp_whitelabel_option);
			return is_array($infos) ? $infos : array();
		}

		public function getPluginsWhitelabelInfoByTitle() {
			$whitelabel_infos = $this->getPluginsWhitelabelInfos();
			$whitelabel_infos_by_title = array();
			foreach ($whitelabel_infos as $slug => $whitelabel_info) {
				if (is_array($whitelabel_info) && array_key_exists('default_title', $whitelabel_info) && isset($whitelabel_info['default_title'])) {
					$whitelabel_info['slug'] = $slug;
					$whitelabel_infos_by_title[$whitelabel_info['default_title']] = $whitelabel_info;
				}
			}
			return $whitelabel_infos_by_title;
		}

		public function getBrandName() {
			$brand = $this->getPluginWhitelabelInfo();
			if (is_array($brand) && array_key_exists('menuname', $brand)) {
				return $brand['menuname'];
			}
		  
			return $this->brandname;
		}

		public function getBrandIcon() {
			$brand = $this->getPluginWhitelabelInfo();
			if (is_array($brand) && array_key_exists('brand_icon', $brand)) {
				return $brand['brand_icon'];
			}
			return $this->brand_icon;
		}

		public function getWatchTime() {
			$time = $this->settings->getOption('bvwatchtime');
			return ($time ? $time : 0);
		}

		public function appUrl() {
			if (defined('BV_APP_URL')) {
				return BV_APP_URL;
			} else {
				$brand = $this->getPluginWhitelabelInfo();
				if (is_array($brand) && array_key_exists('appurl', $brand)) {
					return $brand['appurl'];
				}
				return $this->appurl;
			}
		}

		public function isActivePlugin() {
			$expiry_time = time() - (3 * 24 * 3600);
			return ($this->getWatchTime() > $expiry_time);
		}

		public function isValidEnvironment(){
			$bvsiteinfo = new WPCOMWPSiteInfo();
			$bvconfig = $this->config;

			if (is_multisite()) {
				return true;
			} elseif ($bvconfig && array_key_exists("siteurl_scheme", $bvconfig)) {
				$siteurl = $bvsiteinfo->siteurl('', $bvconfig["siteurl_scheme"]);
				if (array_key_exists("abspath", $bvconfig) &&
						array_key_exists("siteurl", $bvconfig) && !empty($siteurl)) {
					return ($bvconfig["abspath"] == ABSPATH && $bvconfig["siteurl"] == $siteurl);
				}
			}
			return true;
		}

		public function isProtectModuleEnabled() {
			return $this->isServiceActive("protect") && $this->isValidEnvironment();
		}

		public function isDynSyncModuleEnabled() {
			if ($this->isServiceActive("dynsync")) {
				$dynconfig = $this->config['dynsync'];
				if (array_key_exists('dynplug', $dynconfig) && ($dynconfig['dynplug'] === $this->plugname)) {
					return true;
				}
			}
			return false;
		}

		public function isServiceActive($service) {
			$bvconfig = $this->config;
			if ($bvconfig && array_key_exists('services', $bvconfig)) {
				return in_array($service, $bvconfig['services']) && $this->isActivePlugin();
			}
			return false;
		}

		public function isActivateRedirectSet() {
			return ($this->settings->getOption($this->plug_redirect) === 'yes') ? true : false;
		}

		public function isMalcare() {
			return $this->getBrandName() === 'MalCare';
		}

		public function isBlogvault() {
			return $this->getBrandName() === 'BlogVault';
		}

		public function info() {
			return array(
				"bvversion" => $this->version,
				"sha1" => "true",
				"plugname" => $this->plugname
			);
		}
	}
endif;