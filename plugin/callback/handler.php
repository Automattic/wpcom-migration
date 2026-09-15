<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('WPCOMCallbackHandler')) :

	class WPCOMCallbackHandler {
		public $db;
		public $settings;
		public $siteinfo;
		public $request;
		public $account;
		public $response;
		public $bvinfo;

		public function __construct($db, $settings, $siteinfo, $request, $account, $response) {
			$this->db = $db;
			$this->settings = $settings;
			$this->siteinfo = $siteinfo;
			$this->request = $request;
			$this->account = $account;
			$this->response = $response;
			$this->bvinfo = new WPCOMInfo($this->settings);
		}

		public function bvAdmExecuteWithoutUser() {
			$this->execute(array("bvadmwithoutuser" => true));
		}

		public function bvAdmExecuteWithUser() {
			$this->execute(array("bvadmwithuser" => true));
		}

		public function execute($resp = array()) {
			$params = $this->request->params;
			if (array_key_exists('disable_global_cache', $params)) {
				$GLOBALS['_wp_using_ext_object_cache'] = false;
			}

			$this->routeRequest();
			$resp = array(
				"request_info" => $this->request->info(),
				"site_info" => $this->siteinfo->info(),
				"account_info" => $this->account->info(),
				"bvinfo" => $this->bvinfo->info(),
				"api_pubkey" => substr(WPCOMAccount::getApiPublicKey($this->settings), 0, 8)
			);
			$this->response->terminate($resp);
		}

		public function deferExecutionUntilShutdown() {
			if (function_exists('error_clear_last')) {
				error_clear_last();
			}
			ob_start();
			remove_action('shutdown', 'wp_ob_end_flush_all', 1);
			add_action('shutdown', array($this, 'scheduleExecutionAfterShutdown'), PHP_INT_MAX);
		}

		public function scheduleExecutionAfterShutdown() {
			register_shutdown_function(array($this, 'executeAfterShutdown'));
		}

		public function executeAfterShutdown() {
			if ($this->request->keep_page_output) {
				$this->removeContentLengthHeader();
				$this->execute();
				return;
			}
			if ($this->shouldPreserveFrontendResponse()) {
				return;
			}
			$this->discardBufferedOutput();
			$this->clearFrontendResponseHeaders();
			$this->execute();
		}

		private function shouldPreserveFrontendResponse() {
			if ($this->hasFatalError() || headers_sent()) {
				return true;
			}
			if (!function_exists('http_response_code')) {
				return false;
			}
			$response_code = http_response_code();
			return $response_code !== false && $response_code !== 200;
		}

		private function hasFatalError() {
			$error = error_get_last();
			$fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
			return is_array($error) && in_array($error['type'], $fatal_types, true);
		}

		private function discardBufferedOutput() {
			while (ob_get_level() > 0) {
				if (!@ob_end_clean()) {
					break;
				}
			}
		}

		private function clearFrontendResponseHeaders() {
			$this->removeContentLengthHeader();
			header_remove('Content-Encoding');
			header_remove('Location');
		}

		private function removeContentLengthHeader() {
			if (!headers_sent()) {
				header_remove('Content-Length');
			}
		}

		public function routeRequest() {
			switch ($this->request->wing) {
			case 'manage':
				require_once dirname( __FILE__ ) . '/wings/manage.php';
				$module = new WPCOMManageCallback($this);
				break;
			case 'fs':
				require_once dirname( __FILE__ ) . '/wings/fs.php';
				$module = new WPCOMFSCallback($this);
				break;
			case 'db':
				require_once dirname( __FILE__ ) . '/wings/db.php';
				$module = new WPCOMDBCallback($this);
				break;
			case 'info':
				require_once dirname( __FILE__ ) . '/wings/info.php';
				$module = new WPCOMInfoCallback($this);
				break;
			case 'dynsync':
				require_once dirname( __FILE__ ) . '/wings/dynsync.php';
				$module = new WPCOMDynSyncCallback($this);
				break;
			case 'ipstr':
				require_once dirname( __FILE__ ) . '/wings/ipstore.php';
				$module = new WPCOMIPStoreCallback($this);
				break;
			case 'wtch':
				require_once dirname( __FILE__ ) . '/wings/watch.php';
				$module = new WPCOMWatchCallback($this);
				break;
			case 'brand':
				require_once dirname( __FILE__ ) . '/wings/brand.php';
				$module = new WPCOMBrandCallback($this);
				break;
			case 'pt':
				require_once dirname( __FILE__ ) . '/wings/protect.php';
				$module = new WPCOMProtectCallback($this);
				break;
			case 'act':
				require_once dirname( __FILE__ ) . '/wings/account.php';
				$module = new WPCOMAccountCallback($this);
				break;
			case 'fswrt':
				require_once dirname( __FILE__ ) . '/wings/fs_write.php';
				$module = new WPCOMFSWriteCallback();
				break;
			case 'actlg':
				require_once dirname( __FILE__ ) . '/wings/actlog.php';
				$module = new WPCOMActLogCallback($this);
				break;
			case 'speed':
				require_once dirname( __FILE__ ) . '/wings/speed.php';
				$module = new WPCOMSpeedCallback($this);
				break;
			case 'scrty':
				require_once dirname( __FILE__ ) . '/wings/security.php';
				$module = new WPCOMSecurityCallback($this);
				break;
			default:
				require_once dirname( __FILE__ ) . '/wings/misc.php';
				$module = new WPCOMMiscCallback($this);
				break;
			}
			$resp = $module->process($this->request);
			if ($resp === false) {
				$resp = array(
					"statusmsg" => "Bad Command",
					"status" => false);
			}
			$resp = array(
				$this->request->wing => array(
					$this->request->method => $resp
				)
			);
			$this->response->addStatus("callbackresponse", $resp);
			return 1;
		}
	}
endif;
