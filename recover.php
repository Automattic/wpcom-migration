<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMRecover')) :
	class WPCOMRecover {
		public static $default_secret_key = 'bv_default_secret_key';

		public static function defaultSecret($settings) {
			$secret = self::getDefaultSecret($settings);
			if (empty($secret)) {
				$secret = WPCOMRecover::refreshDefaultSecret($settings);
			}
			return $secret;
		}

		public static function refreshDefaultSecret($settings) {
			$key_details = array();
			$key_details["key"] = WPCOMAccount::randString(32);
			$key_details["expires_at"] = time() + (24 * 60 * 60);

			$settings->updateOption(self::$default_secret_key, $key_details);

			return $key_details["key"];
		}


		public static function deleteDefaultSecret($settings) {
			$settings->deleteOption(self::$default_secret_key);
		}

		public static function getDefaultSecret($settings) {
			$key_details = $settings->getOption(self::$default_secret_key);

			if (is_array($key_details) && $key_details["expires_at"] > time()) {
				return $key_details["key"];
			}

			return null;
		}

		public static function getSecretStatus($settings) {
			$key_details = $settings->getOption(self::$default_secret_key);
			$status = 'ACTIVE';
			if (!is_array($key_details)) {
				  $status = 'DELETED';
			} elseif ($key_details["expires_at"] <= time()) {
				  $status = 'EXPIRED';
			}

			return $status;
		}

		public static function validate($key) {
			return $key && strlen($key) >= 32;
		}

		public static function find($settings, $pubkey) {
			if (!self::validate($pubkey)) {
				return null;
			}

			$secret = self::getDefaultSecret($settings);
			if (!self::validate($secret)) {
				return null;
			}

			$account = new WPCOMAccount($settings, $pubkey, $secret);
			return $account;
		}
	}
endif;