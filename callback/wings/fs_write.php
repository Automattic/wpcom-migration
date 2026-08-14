<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMFSWriteCallback')) :

class WPCOMFSWriteCallback extends WPCOMCallbackBase {

	const MEGABYTE = 1048576;
	const FS_WRITE_WING_VERSION = 1.2;
	
	public function __construct() {
	}

	public function removeFiles($files) {
		$result = array();

		foreach($files as $file) {
			$file_result = array();

			if (file_exists($file)) {

				$file_result['status'] = unlink($file);
				if ($file_result['status'] === false) {
					$file_result['error'] = "UNLINK_FAILED";
				}

			} else {
				$file_result['status'] = true;
				$file_result['error'] = "NOT_PRESENT";
			}

			$result[$file] = $file_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function makeDirs($dirs, $permissions = 0777, $recursive = true) {
		$result = array();

		foreach($dirs as $dir) {
			$dir_result = array();

			if (file_exists($dir)) {

				if (is_dir($dir)) {
					$dir_result['status'] = true;
					$dir_result['message'] = "DIR_ALREADY_PRESENT";
				} else {
					$dir_result['status'] = false;
					$dir_result['error'] = "FILE_PRESENT_IN_PLACE_OF_DIR";
				}

			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Using mkdir() directly as there is no direct suport for recursion
				$dir_result['status'] = mkdir($dir, $permissions, $recursive);
				if ($dir_result['status'] === false) {
					$dir_result['error'] = "MKDIR_FAILED";
				}

			}

			$result[$dir] = $dir_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function removeDirs($dirs) {
		$result = array();

		foreach ($dirs as $dir) {
			$dir_result = array();

			if ((WPCOMWPFileSystem::getInstance()->isDir($dir) === true) && !is_link($dir)) {
				if ($this->isEmptyDir($dir)) {
					$dir_result['status'] = WPCOMWPFileSystem::getInstance()->rmdir($dir);
					if ($dir_result['status'] === false) {
						$dir_result['error'] = "RMDIR_FAILED";
						$fs_error = WPCOMWPFileSystem::getInstance()->checkForErrors();
						if (isset($fs_error)) {
							$dir_result['fs_error'] = $fs_error;
						}
					}
				} else {
					$dir_result['status'] = false;
					$dir_result['error'] = "NOT_EMPTY";
				}
			} else {
				$dir_result['status'] = false;
				$dir_result['error'] = "NOT_DIR";
			}

			$result[$dir] = $dir_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function isEmptyDir($dir) {
		$handle = opendir($dir);

		while (false !== ($entry = readdir($handle))) {
			if ($entry != "." && $entry != "..") {
				closedir($handle);
				return false;
			}
		}
		closedir($handle);

		return true;
	}

	public function doChmod($path_infos) {
		$result = array();

		foreach ($path_infos as $path => $mode) {
			$path_result = array();

			if (WPCOMWPFileSystem::getInstance()->exists($path) === true) {
				$path_result['status'] = WPCOMWPFileSystem::getInstance()->chmod($path, $mode);
				if ($path_result['status'] === false) {
					$path_result['error'] = "CHMOD_FAILED";
					$fs_error = WPCOMWPFileSystem::getInstance()->checkForErrors();
					if (isset($fs_error)) {
						$path_result['fs_error'] = $fs_error;
					}
				}
			} else {
				$path_result['status'] = false;
				$path_result['error'] = "NOT_FOUND";
			}

			$result[$path] = $path_result;
		}

		$result['status'] = true;
		return $result;
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	public function concatFiles($ifiles, $ofile, $bsize, $offset) {
		if (($offset !== 0) && (!file_exists($ofile))) {
			return array(
				'status' => false,
				'error' => 'OFILE_NOT_FOUND_BEFORE_CONCAT'
			);
		}

		if (file_exists($ofile) && ($offset !== 0)) {
			$handle = fopen($ofile, 'rb+');
		} else {
			$handle = fopen($ofile, 'wb+');
		}

		if ($handle === false) {
			return array(
				'status' => false,
				'error' => 'FOPEN_FAILED'
			);
		}

		if ($offset !== 0) {
			if (fseek($handle, $offset, SEEK_SET) === -1) {
				return array(
					'status' => false,
					'error' => 'FSEEK_FAILED'
				);
			}
		}

		$total_written = 0;
		foreach($ifiles as $file) {
			$fp = fopen($file, 'rb');
			if ($fp === false) {
				return array(
					'status' => false,
					'error' => "UNABLE_TO_OPEN_TMP_OFILE_FOR_READING"
				);
			}

			while (!feof($fp)) {
				$content = fread($fp, $bsize);
				if ($content === false) {
					return array(
						'status' => false,
						'error' => "UNABLE_TO_READ_INFILE",
						'filename' => $file
					);
				}

				$written = fwrite($handle, $content);
				if ($written === false) {
					return array(
						'status' => false,
						'error' => "UNABLE_TO_WRITE_TO_OFILE",
						'filename' => $file
					);
				}
				$total_written += $written;
			}

			fclose($fp);
		}
		
		$result = array();
		$result['fclose'] = fclose($handle);

		if (file_exists($ofile) && ($total_written != 0)) {
			$result['status'] = true;
			$result['fsize'] = filesize($ofile);
			$result['total_written'] = $total_written;
		} else {
			$result['status'] = false;
			$result['error'] = 'CONCATINATED_FILE_FAILED';
		}

		return $result;
	}
	// phpcs:enable

	public function renameFiles($path_infos) {
		$result = array();

		foreach ($path_infos as $oldpath => $newpath) {
			$action_result = array();

			if (WPCOMWPFileSystem::getInstance()->exists($oldpath)) {
				$action_result['status'] = WPCOMWPFileSystem::getInstance()->move($oldpath, $newpath, true);
				if ($action_result['status'] === false) {
					$action_result['error'] = "RENAME_FAILED";
					$fs_error = WPCOMWPFileSystem::getInstance()->checkForErrors();
					if (isset($fs_error)) {
						$action_result['fs_error'] = $fs_error;
					}
				} else {
					if (function_exists('opcache_invalidate')) {
						$action_result['opcache'] = opcache_invalidate($newpath, true);
					}
				}
			} else {
				$action_result['status'] = false;
				$action_result['error'] = "NOT_FOUND";
			}

			$result[$oldpath] = $action_result;
		}

		$result['status'] = true;
		return $result;
	}

	public function curlFile($ifile_url, $ofile, $timeout) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			return array(
				'error' => 'FOPEN_FAILED_FOR_TEMP_OFILE'
			);
		}

		$result = array();

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_close, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_errno
		$ch = curl_init($ifile_url);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_FILE, $fp);

		if (!curl_exec($ch)) {
			$result['error'] = curl_error($ch);
			$result['errorno'] = curl_errno($ch);
		}

		curl_close($ch);

		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_close, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_errno

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose($fp);


		return $result;
	}

	public function streamCopyFile($ifile_url, $ofile) {
		$result = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen($ifile_url, "rb");

		if ($handle === false) {
			return array(
				'error' => "UNABLE_TO_OPEN_REMOTE_FILE_STREAM"
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return array(
				'error' => 'FOPEN_FAILED_FOR_OFILE'
			);
		}

		if (stream_copy_to_stream($handle, $fp) === false) {
			$result['error'] = "UNABLE_TO_WRITE_TO_TMP_OFILE";
		}

		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $result;
	}

	public function writeContentToFile($content, $ofile) {
		$result = array();

		if (WPCOMWPFileSystem::getInstance()->putContents($ofile, $content) === false) {
			$result['error'] = 'UNABLE_TO_WRITE_TO_TMP_OFILE';
			$fs_error = WPCOMWPFileSystem::getInstance()->checkForErrors();
			if (isset($fs_error)) {
				$result['fs_error'] = $fs_error;
			}
		}

		return $result;
	}

	public function moveUploadedFile($ofile) {
		$result = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		if (isset($_FILES['myfile'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- tmp_name is a path and nonce is ignored here
			$myfile = $_FILES['myfile'];
			$is_upload_ok = false;

			// Validate PHP upload errors manually
			// This approach handles any file type (PHP, ZIP, SQL, etc.) without MIME restrictions
			// Uses WordPress Filesystem API instead of wp_handle_upload() which is designed for media uploads
			switch ($myfile['error']) {
			case UPLOAD_ERR_OK:
				$is_upload_ok = true;
				break;
			case UPLOAD_ERR_NO_FILE:
				$result['error'] = "UPLOADERR_NO_FILE";
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				$result['error'] = "UPLOADERR_FORM_SIZE";
				break;
			default:
				$result['error'] = "UPLOAD_ERR_UNKNOWN";
			}

			if ($is_upload_ok && !isset($myfile['tmp_name'])) {
				$result['error'] = "MYFILE_TMP_NAME_NOT_FOUND";
				$is_upload_ok = false;
			}

			if ($is_upload_ok) {
				$tmp_name = $myfile['tmp_name'];

				// Ensure target directory exists
				$target_dir = dirname($ofile);
				if (!file_exists($target_dir)) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Using mkdir() directly as there is no direct support for recursion
					if (!mkdir($target_dir, 0777, true)) {
						$result['error'] = 'MKDIR_FAILED_FOR_TARGET';
						return $result;
					}
				}

				// Use WordPress Filesystem API to move the uploaded file
				// This is WordPress.org compliant and handles any file type
				if (WPCOMWPFileSystem::getInstance()->move($tmp_name, $ofile, true) === false) {
					$result['error'] = 'MOVE_UPLOAD_FILE_FAILED';
					$fs_error = WPCOMWPFileSystem::getInstance()->checkForErrors();
					if (isset($fs_error)) {
						$result['fs_error'] = $fs_error;
					}
				}
			}

		} else {
			$result['error'] = "FILE_NOT_PRESENT_IN_FILES";
		}

		return $result;
	}


	public function uploadFile($params) {
		$resp = array();
		$ofile = $params['ofile'];

		switch($params['protocol']) {
		case "curl":
			$timeout = isset($params['timeout']) ? $params['timeout'] : 60;
			$ifile_url = isset($params['ifileurl']) ? $params['ifileurl'] : null;

			$resp = $this->curlFile($ifile_url, $ofile, $timeout);
			break;
		case "streamcopy":
			$ifile_url = isset($params['ifileurl']) ? $params['ifileurl'] : null;

			$resp = $this->streamCopyFile($ifile_url, $ofile);
			break;
		case "httpcontenttransfer":
			$resp = $this->writeContentToFile($params['content'], $ofile);
			break;
		case "httpfiletransfer":
			$resp = $this->moveUploadedFile($ofile);
			break;
		default:
			$resp['error'] = "INVALID_PROTOCOL";
		}

		if (isset($resp['error'])) {
			$resp['status'] = false;
		} else {

			if (file_exists($ofile)) {
				$resp['status'] = true;
				$resp['fsize'] = filesize($ofile);
			} else {
				$resp['status'] = false;
				$resp['error'] = "OFILE_NOT_FOUND";
			}

		}

		return $resp;
	}

	public function runFileCmd($cmd_key, $cmd_params) {
		switch ($cmd_key) {
		case "wrtfle":
			return $this->uploadFile($cmd_params);
		case "renmefle":
			$from = $cmd_params['from'];
			$to = $cmd_params['to'];
			$rename_result = $this->renameFiles(array($from => $to));
			return isset($rename_result[$from]) ? $rename_result[$from] : array('status' => false, 'error' => 'RENAME_NO_RESULT');
		case "chmd":
			$path = $cmd_params['path'];
			$chmod_result = $this->doChmod(array($path => $cmd_params['mode']));
			return isset($chmod_result[$path]) ? $chmod_result[$path] : array('status' => false, 'error' => 'CHMOD_NO_RESULT');
		case "mkdr":
			$path = $cmd_params['path'];
			$perms = isset($cmd_params['perms']) ? $cmd_params['perms'] : 0777;
			$rec = isset($cmd_params['rec']) ? (bool) $cmd_params['rec'] : true;
			$mkdir_result = $this->makeDirs(array($path), $perms, $rec);
			return isset($mkdir_result[$path]) ? $mkdir_result[$path] : array('status' => false, 'error' => 'MKDIR_NO_RESULT');
		case "rmfle":
			$files = $cmd_params['files'];
			$rm_result = $this->removeFiles($files);
			$first = reset($files);
			return isset($rm_result[$first]) ? $rm_result[$first] : array('status' => false, 'error' => 'RMFLE_NO_RESULT');
		case "rmdr":
			$dirs = $cmd_params['dirs'];
			$rmdr_result = $this->removeDirs($dirs);
			$first = reset($dirs);
			return isset($rmdr_result[$first]) ? $rmdr_result[$first] : array('status' => false, 'error' => 'RMDR_NO_RESULT');
		default:
			return array('status' => false, 'error' => 'UNKNOWN_CMD');
		}
	}

	public function executeFileOps($ops, $all_required = false) {
		$result = array();
		$all_success = true;

		foreach ($ops as $op) {
			$identifier = $op['identifier'];
			$cmds = $op['cmds'];
			$op_result = array();

			foreach ($cmds as $cmd) {
				foreach ($cmd as $cmd_key => $cmd_params) {
					$cmd_result = $this->runFileCmd($cmd_key, $cmd_params);
					$op_result[$cmd_key] = $cmd_result;

					if (isset($cmd_result['status']) && $cmd_result['status'] === false) {
						$all_success = false;
						break 2;
					}
				}
			}

			$result[$identifier] = $op_result;

			if ($all_required && !$all_success) {
				break;
			}
		}

		$result['status'] = $all_success;
		return $result;
	}

	public function process($request) {
		$params = $request->params;

		switch ($request->method) {
		case "rmfle":
			$resp = $this->removeFiles($params['files']);
			break;
		case "chmd":
			$resp = $this->doChmod($params['pathinfos']);
			break;
		case "mkdr":
			$resp = $this->makeDirs($params['dirs'], $params['permissions'], $params['recursive']);
			break;
		case "rmdr":
			$resp = $this->removeDirs($params['dirs']);
			break;
		case "renmefle":
			$resp = $this->renameFiles($params['pathinfos']);
			break;
		case "wrtfle":
			$resp = $this->uploadFile($params);
			break;
		case "fleops":
			$all_required = isset($params['all_required']) ? (bool) $params['all_required'] : false;
			$resp = $this->executeFileOps($params['ops'], $all_required);
			break;
		case "cncatfls":
			$bsize = (isset($params['bsize'])) ? $params['bsize'] : (8 * WPCOMFSWriteCallback::MEGABYTE);
			$offset = (isset($params['offset'])) ? $params['offset'] : 0;
			$resp = $this->concatFiles($params['infiles'], $params['ofile'], $bsize, $offset);
			break;
		default:
			$resp = false;
		}

		return $resp;
	}
}
endif;