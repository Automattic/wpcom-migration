<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('BVFSWriteCallback')) :

class BVFSWriteCallback extends BVCallbackBase {

	const MEGABYTE = 1048576;
	const FS_WRITE_WING_VERSION = 1.0;
	
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

		foreach($dirs as $dir) {
			$dir_result = array();

			if (is_dir($dir) && !is_link($dir)) {

				if ($this->isEmptyDir($dir)) {

					$dir_result['status'] = rmdir($dir);
					if ($dir_result['status'] === false) {
						$dir_result['error'] = "RMDIR_FAILED";
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

		foreach($path_infos as $path => $mode) {
			$path_result = array();

			if (file_exists($path)) {

				$path_result['status'] = chmod($path, $mode);
				if ($path_result['status'] === false) {
					$path_result['error'] = "CHMOD_FAILED";
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

	public function renameFiles($path_infos) {
		$result = array();

		foreach($path_infos as $oldpath => $newpath) {
			$action_result = array();
			$failed = array();

			if (file_exists($oldpath)) {

				$action_result['status'] = rename($oldpath, $newpath);
				if ($action_result['status'] === false) {
					$action_result['error'] = "RENAME_FAILED";
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
		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			return array(
				'error' => 'FOPEN_FAILED_FOR_TEMP_OFILE'
			);
		}

		$result = array();
		$ch = curl_init($ifile_url);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_FILE, $fp);

		if (!curl_exec($ch)) {
			$result['error'] = curl_error($ch);
			$result['errorno'] = curl_errno($ch);
		}

		curl_close($ch);
		fclose($fp);

		return $result;
	}

	public function streamCopyFile($ifile_url, $ofile) {
		$result = array();
		$handle = fopen($ifile_url, "rb");

		if ($handle === false) {
			return array(
				'error' => "UNABLE_TO_OPEN_REMOTE_FILE_STREAM"
			);
		}

		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			fclose($handle);

			return array(
				'error' => 'FOPEN_FAILED_FOR_OFILE'
			);
		}

		if (stream_copy_to_stream($handle, $fp) === false) {
			$result['error'] = "UNABLE_TO_WRITE_TO_TMP_OFILE";
		}

		fclose($handle);
		fclose($fp);

		return $result;
	}

	public function writeContentToFile($content, $ofile) {
		$result = array();

		$fp = fopen($ofile, "wb+");
		if ($fp === false) {
			return array(
				'error' => 'FOPEN_FAILED_FOR_TEMP_OFILE'
			);
		}

		if (fwrite($fp, $content) === false) {
			$result['error'] = "UNABLE_TO_WRITE_TO_TMP_OFILE";
		}
		fclose($fp);

		return $result;
	}

	public function moveUploadedFile($ofile) {
		$result = array();

		if (isset($_FILES['myfile'])) {
			$myfile = $_FILES['myfile'];
			$is_upload_ok = false;

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
				if (move_uploaded_file($myfile['tmp_name'], $ofile) === false) {
					$result['error'] = 'MOVE_UPLOAD_FILE_FAILED';
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
		case "cncatfls":
			$bsize = (isset($params['bsize'])) ? $params['bsize'] : (8 * BVFSWriteCallback::MEGABYTE);
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