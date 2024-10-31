<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMWPLoginWhitelabel')) :

class WPCOMWPLoginWhitelabel {
	private $bvinfo;
	private $label;
	private $logo;

	public function __construct() {
		$this->bvinfo = new WPCOMInfo(new WPCOMWPSettings());

		$whitelabel_info = $this->bvinfo->getLPWhitelabelInfo();
		if (isset($whitelabel_info['label']) && is_string($whitelabel_info['label'])) {
			$this->label = $whitelabel_info['label'];
		}

		if (isset($whitelabel_info['logo']) && is_string($whitelabel_info['logo'])) {
			$this->logo = $whitelabel_info['logo'];
		}
	}

	public function init() {
		add_action('login_head', array($this, 'custom_login_head'));
		add_filter('login_message', array($this, 'custom_login_message'));
	}

	function custom_login_head() {
		if (empty($this->logo) ||
				!preg_match('/^data:image\/(jpeg|png);base64,[a-zA-Z0-9\/+]+={0,2}$/', $this->logo)) {

			return;
		}

		$logo_style = 'background-image: none, url("' . esc_attr($this->logo) . '") !important;';

		echo '<style type="text/css">
			.login h1 a {
			' . $logo_style . '
			}
		</style>';
	}

	function custom_login_message($message) {
		if (empty($this->label)) {
			return;
		}

		return '<div style="
			text-align: center;
			font-size: 22px;
			font-weight: bold;
			color: #333;
			margin-top: 10px;
			margin-bottom: 10px;
		">' . esc_html($this->label) . '</div>';
	}
}
endif;