<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('WPCOMWPFileSystem')) :
	class WPCOMWPFileSystem {
		private static $instance = null;
		private $filesystem = null;

		const RESOURCE_TYPE_FILE = 'f';

		private function __construct() {
			$this->initFilesystem();
		}

		private function initFilesystem() {
			if ($this->filesystem === null) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				$this->filesystem = new WP_Filesystem_Direct(null);
			}
		}

		public static function getInstance() {
			if (self::$instance === null) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function putContents($file, $contents, $perm = 0644) {
			return $this->filesystem->put_contents($file, $contents, $perm);
		}

		public function getContents($file) {
			return $this->filesystem->get_contents($file);
		}

		public function removeFile($path) {
			return $this->filesystem->delete($path, false, self::RESOURCE_TYPE_FILE);
		}

		public function rmdir($path, $recursive = false) {
			return $this->filesystem->delete($path, $recursive);
		}

		public function isWritable($path) {
			return $this->filesystem->is_writable($path);
		}

		public function isDir($path) {
			return $this->filesystem->is_dir($path);
		}

		public function exists($path) {
			return $this->filesystem->exists($path);
		}

		public function move($source, $destination, $overwrite = false) {
			return $this->filesystem->move($source, $destination, $overwrite);
		}

		public function getchmodOctal($path) {
			return intval($this->getchmod($path), 8);
		}

		public function getchmod($path) {
			return $this->filesystem->getchmod($path);
		}

		public function size($file) {
			return $this->filesystem->size($file);
		}

		public function chmod($file, $mode = false, $recursive = false) {
			return $this->filesystem->chmod($file, $mode, $recursive);
		}

		public function isReadable($file) {
			return $this->filesystem->is_readable($file);
		}

		public function checkForErrors() {
			if ($this->filesystem !== null && is_wp_error($this->filesystem->errors)) {
				$wp_error = $this->filesystem->errors;
				if (!empty($wp_error->errors)) {
					return $wp_error->get_error_message();
				}
			}
			return null;
		}
	}
endif;