<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMRecover')) :
	class WPCOMRecover {

		const SECRET_TTL = 1800;
		const TAG_LENGTH = 32;
		const SALT_LENGTH = 64;
		const MIN_SALT_LENGTH = 32;
		const SALT_CONSTANT = 'AUTH_SALT';
		const SALT_PLACEHOLDER = 'put your unique phrase here';

		public static $default_secret_key = 'bv_default_secret_key';
		private static $other_salt_constants = array(
			'AUTH_KEY', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT',
			'LOGGED_IN_KEY', 'LOGGED_IN_SALT',
			'NONCE_KEY', 'NONCE_SALT'
		);

		public static function saltMaterial($settings) {
			$salt = self::configSalt();
			if (!empty($salt)) {
				return $salt;
			}

			return self::storedSalt($settings);
		}

		private static function configSalt() {
			if (!defined(self::SALT_CONSTANT)) {
				return null;
			}

			$value = constant(self::SALT_CONSTANT);
			if (!is_string($value) || strlen($value) < self::MIN_SALT_LENGTH ||
					self::isPlaceholder($value) || self::isSharedWithOtherSalts($value)) {
				return null;
			}

			return $value;
		}

		private static function isSharedWithOtherSalts($value) {
			foreach (self::$other_salt_constants as $constant) {
				if (defined($constant) && constant($constant) === $value) {
					return true;
				}
			}

			return false;
		}

		private static function storedSalt($settings) {
			$key_details = $settings->getOption(self::$default_secret_key);
			if (!is_array($key_details) || !isset($key_details["salt"])) {
				return null;
			}

			$salt = $key_details["salt"];
			if (!is_string($salt) || strlen($salt) < self::MIN_SALT_LENGTH) {
				return null;
			}

			return $salt;
		}

		private static function isPlaceholder($value) {
			if ($value === self::SALT_PLACEHOLDER) {
				return true;
			}

			#wp-config-sample.php is localized for some locales, so the placeholder
			#is not always the English string. wp_salt() guards against the
			#translated form the same way.
			// phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			return function_exists('__') && $value === __('put your unique phrase here');
		}

		public static function defaultSecret($settings) {
			$secret = self::getDefaultSecret($settings);
			if (empty($secret)) {
				$secret = WPCOMRecover::refreshDefaultSecret($settings);
			}
			return $secret;
		}

		public static function refreshDefaultSecret($settings) {
			$settings->deleteOption(self::$default_secret_key);

			$key_details = array();
			$key_details["key"] = WPCOMAccount::randString(32);
			$key_details["expires_at"] = time() + self::SECRET_TTL;

			#Only carried when wp-config.php has nothing usable to bind the tag to.
			if (empty(self::configSalt())) {
				$key_details["salt"] = WPCOMAccount::randString(self::SALT_LENGTH);
			}

			$settings->updateOption(self::$default_secret_key, $key_details);

			return $key_details["key"];
		}

		public static function connectionTag($settings) {
			$secret = self::getDefaultSecret($settings);
			if (empty($secret)) {
				return null;
			}

			$material = self::saltMaterial($settings);
			if (empty($material)) {
				return null;
			}

			return substr(hash_hmac('sha256', $secret, $material), 0, self::TAG_LENGTH);
		}

		public static function verifyTag($settings, $tag) {
			$expected = self::connectionTag($settings);
			if (empty($expected)) {
				return false;
			}

			return is_string($tag) && hash_equals($expected, $tag);
		}

		public static function deleteDefaultSecret($settings) {
			return $settings->deleteOption(self::$default_secret_key);
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

		public static function find($settings, $pubkey, $tag = null) {
			if (!self::validate($pubkey)) {
				return null;
			}

			if (!self::verifyTag($settings, $tag)) {
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
