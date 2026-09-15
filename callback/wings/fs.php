<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMFSCallback')) :
require_once dirname( __FILE__ ) . '/../streams.php';

class WPCOMFSCallback extends WPCOMCallbackBase {
	public $stream;
	public $account;

	public static $cwAllowedFiles = array(".htaccess", ".user.ini", "malcare-waf.php");
	const FS_WING_VERSION = 1.4;

	public function __construct($callback_handler) {
		$this->account = $callback_handler->account;
	}

	function fileStat($relfile, $md5 = false) {
		$absfile = ABSPATH.$relfile;
		$fdata = array();
		$fdata["filename"] = $relfile;

		if (@is_readable($absfile) === false) {
			$fdata["failed"] = true;
			$fdata["error"] = "NOT_READABLE";
			return $fdata;
		}

		$stats = @stat($absfile);
		if ($stats) {
			foreach (preg_grep('#size|uid|gid|mode|mtime#i', array_keys($stats)) as $key ) {
				$fdata[$key] = $stats[$key];
			}
			if (is_link($absfile)) {
				$fdata["link"] = @readlink($absfile);
			}
			if ($md5 === true && !is_dir($absfile)) {
				$fdata["md5"] = $this->calculateMd5($absfile, array(), 0, 0, 0);
			}
		} else {
			$fdata["failed"] = true;
		}
		return $fdata;
	}

	function scanFilesUsingGlob($initdir = "./", $offset = 0, $limit = 0, $bsize = 512, $recurse = true, $regex = '{.??,}*') {
		$i = 0;
		$dirs = array();
		$dirs[] = $initdir;
		$bfc = 0;
		$bfa = array();
		$current = 0;
		$abspath = realpath(ABSPATH).'/';
		$abslen = strlen($abspath);
		# XNOTE: $recurse cannot be used directly here
		while ($i < count($dirs)) {
			$dir = $dirs[$i];

			foreach (glob($abspath.$dir.$regex, GLOB_NOSORT | GLOB_BRACE) as $absfile) {
				$relfile = substr($absfile, $abslen);
				if (is_dir($absfile) && !is_link($absfile)) {
					$dirs[] = $relfile."/";
				}
				$current++;
				if ($offset >= $current)
					continue;
				if (($limit != 0) && (($current - $offset) > $limit)) {
					$i = count($dirs);
					break;
				}
				$bfa[] = $this->fileStat($relfile);
				$bfc++;
				if ($bfc == $bsize) {
					$str = serialize($bfa);
					$this->stream->writeStream($str);
					$bfc = 0;
					$bfa = array();
				}
			}
			$regex = '{.??,}*';
			$i++;
			if ($recurse == false)
				break;
		}
		if ($bfc != 0) {
			$str = serialize($bfa);
			$this->stream->writeStream($str);
		}
		return array("status" => "done");
	}

	function scanFiles($initdir = "./", $offset = 0, $limit = 0, $bsize = 512, $recurse = true, $md5 = false) {
		$i = 0;
		$links = array();
		$dirs = array();
		$dirs[] = $initdir;
		$bfc = 0;
		$bfa = array();
		$current = 0;
		while ($i < count($dirs)) {
			$dir = $dirs[$i];
			$d = @opendir(ABSPATH.$dir);
			if ($d) {
				while (($file = readdir($d)) !== false) {
					if ($file == '.' || $file == '..') { continue; }
					$relfile = $dir.$file;
					$absfile = ABSPATH.$relfile;
					if (is_link($absfile)) {
						$links[] = $relfile;
					}
					if (is_dir($absfile) && !is_link($absfile)) {
						$dirs[] = $relfile."/";
					}
					$current++;
					if ($offset >= $current)
						continue;
					if (($limit != 0) && (($current - $offset) > $limit)) {
						$i = count($dirs);
						break;
					}
					$bfa[] = $this->fileStat($relfile, $md5);
					$bfc++;
					if ($bfc == $bsize) {
						$str = serialize($bfa);
						$this->stream->writeStream($str);
						$bfc = 0;
						$bfa = array();
					}
				}
				closedir($d);
			}
			$i++;
			if ($recurse == false)
				break;
		}
		if ($bfc != 0) {
			$str = serialize($bfa);
			$this->stream->writeStream($str);
		}

		return $links;
	}

	function getDirectoryPath($dir, $traversal_stack) {
		$base_path = rtrim($dir, '/');
		$sub_path = empty($traversal_stack) ? '' : '/' . implode('/', array_column($traversal_stack, 0));
		return $base_path . $sub_path . '/';
	}

	function seekDirectoryHandle($directory_handle, $offset) {
		while ($offset > 0 && ($file = @readdir($directory_handle)) !== false) {
			if ($file === "." || $file === "..") continue;
			$offset--;
		}
	}

	function scanFilesDfs($dir = "/", $traversal_stack = array(), $folder_offset = 0, $limit = 0, $traversal_stack_max_size = 100,
			$batch_size = 512, $is_recursive = true, $include_md5 = false) {
		$links = [];
		$batch_count = 0;
		$batch_files = [];
		$count = 0;
		$traversal_stack_max_size_reached_count = 0;

		$base_path = $this->getDirectoryPath($dir, $traversal_stack);
		$directory_handle = @opendir(ABSPATH . $base_path);

		$this->seekDirectoryHandle($directory_handle, $folder_offset);

		while ($limit == 0 || ($limit > 0 && $count < $limit)) {
			if (($file = @readdir($directory_handle)) !== false) {
				if ($file === "." || $file === "..") continue;

				$relative_path = $base_path . $file;
				$absolute_path = ABSPATH . $relative_path;

				$count++;
				$folder_offset++;

				$batch_files[] = $this->fileStat($relative_path, $include_md5);
				$batch_count++;

				if ($batch_count >= $batch_size) {
					$this->stream->writeStream(serialize($batch_files));
					$batch_count = 0;
					$batch_files = [];
				}

				if (is_link($absolute_path)) {
					$links[] = $relative_path;
				} elseif ($is_recursive && is_dir($absolute_path)) {
					if (count($traversal_stack) >= $traversal_stack_max_size) {
						$traversal_stack_max_size_reached_count += 1;
						continue;
					}

					closedir($directory_handle);

					array_push($traversal_stack, [$file, $folder_offset]);
					$base_path = $this->getDirectoryPath($dir, $traversal_stack);

					$directory_handle = @opendir(ABSPATH . $base_path);
					$folder_offset = 0;
				}

				continue;
			}

			if ($directory_handle !== false) {
				closedir($directory_handle);
			}

			if (empty($traversal_stack)) {
				break;
			}
			$current_info = array_pop($traversal_stack);

			$base_path = $this->getDirectoryPath($dir, $traversal_stack);
			$directory_handle = @opendir(ABSPATH . $base_path);

			if ($directory_handle === false) {
				continue;
			}

			$this->seekDirectoryHandle($directory_handle, $current_info[1]);
			$folder_offset = $current_info[1];
		}

		if ($batch_count > 0) {
			$this->stream->writeStream(serialize($batch_files));
		}

		return [
			'links' => $links,
			'traversal_stack' => $traversal_stack,
			'folder_offset' => $folder_offset,
			'traversal_stack_max_size_reached_count' => $traversal_stack_max_size_reached_count
		];
	}

	function calculateMd5($absfile, $fdata, $offset, $limit, $bsize) {
		if ($offset == 0 && $limit == 0) {
			$md5 = md5_file($absfile);
		} else {
			if ($limit == 0)
				$limit = $fdata["size"];
			if ($offset + $limit < $fdata["size"])
				$limit = $fdata["size"] - $offset;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen($absfile, "rb");
			$ctx = hash_init('md5');
			fseek($handle, $offset, SEEK_SET);
			$dlen = 1;
			while (($limit > 0) && ($dlen > 0)) {
				if ($bsize > $limit)
					$bsize = $limit;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Required for handling partial file reads with offset and limit
				$d = fread($handle, $bsize);
				$dlen = strlen($d);
				hash_update($ctx, $d);
				$limit -= $dlen;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose($handle);
			$md5 = hash_final($ctx);
		}
		return $md5;
	}

	function getFilesContent($files, $withContent = true) {
		$result = array();

		foreach ($files as $file) {
			$fdata = $this->fileStat($file);
			$absfile = ABSPATH . $file;

			if ((WPCOMWPFileSystem::getInstance()->isDir($absfile) === true) && !is_link($absfile)) {
				$fdata['is_dir'] = true;
			} else {
				if (isset($fdata["error"]) && $fdata["error"] === "NOT_READABLE") {
					$fdata['error'] = 'file not readable';
				} else {
					if ($withContent === true) {
						$content = WPCOMWPFileSystem::getInstance()->getContents($absfile);
						if ($content !== false) {
							$fdata['content'] = $content;
						} else {
							$fdata['error'] = 'unable to read file';
						}
					}
				}
			}

			$fs_error = WPCOMWPFileSystem::getInstance()->checkForErrors();
			if (isset($fs_error)) {
				$fdata['fs_error'] = $fs_error;
			}
			$result[$file] = $fdata;
		}

		return $result;
	}

	function getFilesStats($files, $offset = 0, $limit = 0, $bsize = 102400, $md5 = false) {
		$result = array();
		foreach ($files as $file) {
			$fdata = $this->fileStat($file);
			$absfile = ABSPATH.$file;
			if (isset($fdata["error"]) && $fdata["error"] === "NOT_READABLE") {
				$result["missingfiles"][] = $file;
				continue;
			}
			if ($md5 === true && !is_dir($absfile)) {
				$fdata["md5"] = $this->calculateMd5($absfile, $fdata, $offset, $limit, $bsize);
			}
			$result["stats"][] = $fdata;
		}
		return $result;
	}

	function uploadFiles($files, $offset = 0, $limit = 0, $bsize = 102400) {
		$result = array();
		foreach ($files as $file) {
			if (WPCOMWPFileSystem::getInstance()->isReadable(ABSPATH.$file) === false) {
				$result["missingfiles"][] = $file;
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for binary-safe chunked reading
			$handle = fopen(ABSPATH.$file, "rb");
			if (($handle != null) && is_resource($handle)) {
				$fdata = $this->fileStat($file);
				$_limit = $limit;
				$_bsize = $bsize;
				if ($_limit == 0)
					$_limit = $fdata["size"];
				if ($offset + $_limit > $fdata["size"])
					$_limit = $fdata["size"] - $offset;
				$fdata["limit"] = $_limit;
				$sfdata = serialize($fdata);
				$this->stream->writeStream($sfdata);
				fseek($handle, $offset, SEEK_SET);
				$dlen = 1;
				while (($_limit > 0) && ($dlen > 0)) {
					if ($_bsize > $_limit)
						$_bsize = $_limit;
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Required for binary-safe chunked reading
					$d = fread($handle, $_bsize);
					$dlen = strlen($d);
					$this->stream->writeStream($d);
					$_limit -= $dlen;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for cleanup
				fclose($handle);
			} else {
				$result["unreadablefiles"][] = $file;
			}
		}
		$result["status"] = "done";
		return $result;
	}

	function process($request) {
		$params = $request->params;
		$stream_init_info = WPCOMStream::startStream($this->account, $request);

		if (array_key_exists('stream', $stream_init_info)) {
			$this->stream = $stream_init_info['stream'];
			switch ($request->method) {
			case "scanfilesglob":
				$initdir = $params['initdir'];
				$offset = intval($params['offset']);
				$limit = intval($params['limit']);
				$bsize = intval($params['bsize']);
				$regex = $params['regex'];
				$recurse = true;
				if (array_key_exists('recurse', $params) && $params["recurse"] == "false") {
					$recurse = false;
				}
				$resp = $this->scanFilesUsingGlob($initdir, $offset, $limit, $bsize, $recurse, $regex);
				break;
			case "scanfiles":
				$links = array();
				$dir_options = array();
				if (array_key_exists('dir_options', $params)) {
					$dir_options = $params['dir_options'];
				}
				$bsize = intval($params['bsize']);
				foreach($dir_options as $option) {
					$dir = $option['dir'];
					$offset = intval($option['offset']);
					$limit = intval($option['limit']);
					$recurse = true;
					if (array_key_exists('recurse', $option) && $option["recurse"] == "false") {
						$recurse = false;
					}
					$md5 = true;
					if (array_key_exists('md5', $option) && $option["md5"] == "false") {
						$md5 = false;
					}

					$_links = $this->scanFiles($dir, $offset, $limit, $bsize, $recurse, $md5);
					$links = array_merge($links, $_links);
				}
				$resp = array("status" => "done", "links" => $links);
				break;
			case "scanfilesdfs":
				$resp = array();
				$dir_options = array();
				if (array_key_exists('dir_options', $params)) {
					$dir_options = $params['dir_options'];
				}
				$bsize = intval($params['bsize']);
				$traversal_stack_max_size = intval($params['traversal_stack_max_size']);
				foreach($dir_options as $option) {
					$dir = $option['dir'];
					$traversal_stack = $option['traversal_stack'];
					$folder_offset = intval($option['folder_offset']);
					$limit = intval($option['limit']);

					$recurse = true;
					if (array_key_exists('recurse', $option) && $option["recurse"] == "false") {
						$recurse = false;
					}

					$md5 = true;
					if (array_key_exists('md5', $option) && $option["md5"] == "false") {
						$md5 = false;
					}

					$resp[$dir] = $this->scanFilesDfs($dir, $traversal_stack, $folder_offset, $limit,
							$traversal_stack_max_size, $bsize, $recurse, $md5);
				}
				$resp["status"] = "done";
				break;
			case "getfilesstats":
				$files = $params['files'];
				$offset = intval($params['offset']);
				$limit = intval($params['limit']);
				$bsize = intval($params['bsize']);
				$md5 = false;
				if (array_key_exists('md5', $params)) {
					$md5 = true;
				}
				$resp = $this->getFilesStats($files, $offset, $limit, $bsize, $md5);
				break;
			case "sendmanyfiles":
				$files = $params['files'];
				$offset = intval($params['offset']);
				$limit = intval($params['limit']);
				$bsize = intval($params['bsize']);
				$resp = $this->uploadFiles($files, $offset, $limit, $bsize);
				break;
			case "filelist":
				$dir_options = array();
				if (array_key_exists('dir_options', $params)) {
					$dir_options = $params['dir_options'];
				}
				if (array_key_exists('chdir', $params)) {
					chdir(ABSPATH);
				}
				$resp = array();
				foreach($dir_options as $options) {
					$glob_option = 0;
					if (array_key_exists('onlydir', $options)) {
						$glob_option = GLOB_ONLYDIR;
					}

					$regexes = array("*", ".*");
					if (array_key_exists('regex', $options)) {
						$regexes = array($options['regex']);
					}

					$md5 = false;
					if (array_key_exists('md5', $options)) {
						$md5 = $options['md5'];
					}

					$directoryList = array();

					foreach($regexes as $regex) {
						$directoryList = array_merge($directoryList, glob($options['dir'].$regex, $glob_option));
					}
					$resp[$options['dir']] = $this->getFilesStats($directoryList, 0, 0, 0, $md5);
				}
				break;
			case "dirsexists":
				$resp = array();
				$dirs = $params['dirs'];

				foreach ($dirs as $dir) {
					$path = ABSPATH.$dir;
					if (file_exists($path) && is_dir($path) && !is_link($path)) {
						$resp[$dir] = true;
					} else {
						$resp[$dir] = false;
					}
				}

				$resp["status"] = "Done";
				break;
			case "gtfilescntent":
				$files = $params['files'];
				$withContent = array_key_exists('withcontent', $params) ? $params['withcontent'] : true;
				$resp = array("files_content" => $this->getFilesContent($files, $withContent));
				break;
			case "gtfls":
				$resp = array();

				if (array_key_exists('get_files_content', $params)) {
					$args = $params['get_files_content'];
					$with_content = array_key_exists('withcontent', $args) ? $args['withcontent'] : true;
					$resp['get_files_content'] = $this->getFilesContent($args['files'], $with_content);
				}

				if (array_key_exists('get_files_stats', $params)) {
					$args = $params['get_files_stats'];
					$md5 = array_key_exists('md5', $args) ? $args['md5'] : false;
					$stats = $this->getFilesStats(
							$args['files'], $args['offset'], $args['limit'], $args['bsize'], $md5
					);

					$result = array();

					if (array_key_exists('stats', $stats)) {
						$result['stats'] = array();
						foreach ($stats['stats'] as $stat) {
							$result['stats'][$stat['filename']] = $stat;
						}
					}

					if (array_key_exists('missingfiles', $stats)) {
						$result['missingfiles'] = $stats['missingfiles'];
					}

					$resp['get_files_stats'] = $result;
				}

				break;
			default:
				$resp = false;
			}
			$end_stream_info = $this->stream->endStream();
			if (!empty($end_stream_info) && is_array($resp)) {
				$resp = array_merge($resp, $end_stream_info);
			}
		} else {
			$resp = $stream_init_info;
		}
		return $resp;
	}
}
endif;