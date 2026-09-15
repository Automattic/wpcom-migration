<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMCallbackBase')) :

class WPCOMCallbackBase {

	public static $wing_infos = array("BRAND_WING_VERSION" => '1.1',
		"DB_WING_VERSION" => '1.5',
		"ACCOUNT_WING_VERSION" => '1.2',
		"MISC_WING_VERSION" => '1.4',
		"FS_WING_VERSION" => '1.4',
		"INFO_WING_VERSION" => '2.7',
		"FS_WRITE_WING_VERSION" => '1.2',
		"FS_WRITE_WING_VERSION" => '1.2',
		);

	public function objectToArray($obj) {
		return json_decode(json_encode($obj), true);
	}

	public function base64Encode($data, $chunk_size) {
		if (is_int($chunk_size) && $chunk_size > 0) {
			$out = "";
			$len = strlen($data);
			for ($i = 0; $i < $len; $i += $chunk_size) {
				$out .= base64_encode(substr($data, $i, $chunk_size));
			}
		} else {
			$out = base64_encode($data);
		}
		return $out;
	}
}
endif;
