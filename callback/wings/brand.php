<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('BVBrandCallback')) :

class BVBrandCallback extends BVCallbackBase {
	public $settings;

	const BRAND_WING_VERSION = 1.1;

	public function __construct($callback_handler) {
		$this->settings = $callback_handler->settings;
	}

	public function process($request) {
		$bvinfo = new WPCOMInfo($this->settings);
		$option_name = $bvinfo->brand_option;
		$whitelabel_infos = $bvinfo->getPluginsWhitelabelInfos();
		$params = $request->params;
		switch($request->method) {
		case 'setbrand':
			foreach ($params as $slug => $whitelabel_info) {
				if (isset($slug) && is_array($whitelabel_info)) {
					$whitelabel_infos[$slug] = $whitelabel_info;
				}
			}
			$this->settings->updateOption($option_name, $whitelabel_infos);
			$resp = array("setbrand" => $this->settings->getOption($option_name));
			break;
		case 'rmbrand':
			foreach ($params["delete_keys"] as $slug) {
				unset($whitelabel_infos[$slug]);
			}
			$this->settings->updateOption($option_name, $whitelabel_infos);
			$resp = array("rmbrand" => true);
			break;
		case 'rmallbrands':
			$this->settings->deleteOption($option_name);
			$resp = array("rmallbrands" => !$this->settings->getOption($option_name));
			break;
		default:
			$resp = false;
		}
		return $resp;
	}
}
endif;