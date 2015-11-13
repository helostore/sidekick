<?php
/**
 * HELOstore
 *
 * This source file is part of a commercial software. Only users who have purchased a valid license through
 * https://helostore.com/ and accepted to the terms of the License Agreement can install this product.
 *
 * @category   Add-ons
 * @package    HELOstore
 * @copyright  Copyright (c) 2015-2016 HELOstore. (https://helostore.com/)
 * @license    https://helostore.com/legal/license-agreement/   License Agreement
 * @version    $Id$
 */


namespace HeloStore\ADLS;


use ReflectionClass;
use SimpleXMLElement;
use Tygh\Addons\SchemesManager;
use Tygh\Http;
use Tygh\Registry;
use Tygh\Settings;

class LicenseClient
{
	const CONTEXT_INSTALL = 'install';
	const CONTEXT_ACTIVATE = 'activate';
	const CONTEXT_UNINSTALL = 'uninstall';
	const CONTEXT_DEACTIVATE = 'deactivate';
	const CONTEXT_AUTHENTICATION = 'authentication';
	const CONTEXT_UPDATE_CHECK = 'update_check';
	const CONTEXT_UPDATE_REQUEST = 'update_request';
	const CONTEXT_UPDATE_DOWNLOAD = 'update_download';

	const API_ENDPOINT = 'helostore.com/index.php?dispatch=adls_api';

	const LICENSE_STATUS_ACTIVE = 'A';
	const LICENSE_STATUS_DISABLED = 'D';
	const LICENSE_STATUS_INACTIVE = 'I';

	const CODE_SUCCESS = 0;

	const CODE_ERROR_INVALID_TOKEN = 400;
	const CODE_ERROR_MISSING_TOKEN = 401;
	const CODE_ERROR_ALIEN = 402;
	const CODE_ERROR_MISSING_EMAIL = 403;
	const CODE_ERROR_MISSING_PASSWORD = 404;
	const CODE_ERROR_MISSING_LICENSE = 405;
	const CODE_ERROR_MISSING_DOMAIN = 406;
	const CODE_ERROR_COMMUNICATION_FAILURE = 407;
	const CODE_ERROR_ACCESS_DENIED = 408;

	const CODE_ERROR_PRODUCT_SUBSCRIPTION_TYPE_NOT_FOUND = 420;

	const CODE_ERROR_INVALID_LICENSE_OR_DOMAIN = 450;
	const CODE_ERROR_INVALID_CUSTOMER_EMAIL = 451;
	const CODE_ERROR_INVALID_CREDENTIALS_COMBINATION = 452;
	const CODE_ERROR_MISMATCH_CREDENTIALS_COMBINATION = 453;

	const CODE_ERROR_UPDATE_INVALID_REMOTE_PATH = 460;
	const CODE_ERROR_UPDATE_FAILED_REMOTE_FILE_OPEN = 461;

	const CODE_NOTIFICATION_SETTINGS_UNCHANGED = 200;

	const CODE_TYPE_ERROR = 'error';
	const CODE_TYPE_UNKNOWN = 'alien';
	const CODE_TYPE_SUCCESS = 'success';
	const CODE_TYPE_NOTIFICATION = 'notification';

	protected $tries = 0;
	protected $maxTries = 3;

	public function __construct()
	{
	}

	public function request($context, $data, $settings)
	{
		if (!in_array($context, array(
				LicenseClient::CONTEXT_AUTHENTICATION,
				LicenseClient::CONTEXT_UPDATE_CHECK))) {

			$tokenResponse = $this->refreshToken($data, $settings);
			if (!empty($tokenResponse) && isset($tokenResponse['code']) && $this->isSuccess($tokenResponse['code'])) {
				$data['token'] = fn_get_storage_data('helostore_token');
			} else {
				return $tokenResponse;
			}
		}

		$url = $this->formatApiUrl($context);
		$data['context'] = $context;
		$response = Http::get($url, $data);

		$error = Http::getError();
		if (!empty($error)) {
			$response['code'] = LicenseClient::CODE_ERROR_COMMUNICATION_FAILURE;
			$response['message'] = $error;
		}

		$_tmp = json_decode($response, true);
		if (is_array($_tmp)) {
			$response = $_tmp;
		}
		if (!empty($response) && !empty($response['code']) && $response['code'] == LicenseClient::CODE_ERROR_INVALID_TOKEN) {
			fn_set_storage_data('helostore_token', '');
		}

		return $response;
	}

	public function formatApiUrl($context, $args = array())
	{
		$protocol = (defined('WS_DEBUG') ? 'http' : 'https');
		$url = $protocol . '://' . (defined('WS_DEBUG') ? 'local.' : '') . self::API_ENDPOINT . '.' . $context;
		if (!empty($args)) {
			$query = array();
			foreach ($args as $k => $v) {
				$query[] = $k . '=' . urlencode($v);
			}
			$query = implode('&', $query);
			$url .= '&' . $query;
		}

		return $url;
	}

	public function gatherData($context, $settings)
	{
		$data = array();
		$data['server'] = array(
			'hostname' => $_SERVER['SERVER_NAME'],
			'ip' => $_SERVER['SERVER_ADDR'],
			'port' => $_SERVER['SERVER_PORT'],
		);
		$data['platform'] = array(
			'name' => PRODUCT_NAME,
			'version' => PRODUCT_VERSION,
			'edition' => PRODUCT_EDITION,
		);
		$data['language'] = CART_LANGUAGE;

		if (!empty($settings)) {
			$data['product'] = array(
				'code' => $settings['code'],
				'license' => isset($settings['license']) ? $settings['license'] : '',
				'version' => isset($settings['version']) ? $settings['version'] : '',
				'status' => isset($settings['status']) ? $settings['status'] : '',
			);
			$data['email'] = isset($settings['email']) ? $settings['email'] : '';
		}

		return $data;
	}

	public function getSettings($productCode)
	{
		$settings = Settings::instance()->getValues($productCode, Settings::ADDON_SECTION, false);
		$scheme = SchemesManager::getScheme($productCode);
		if (!empty($scheme)) {
			if (method_exists($scheme, 'getVersion')) {
				$settings['version'] = $scheme->getVersion();
				$settings['name'] = $scheme->getName();
				$settings['code'] = $productCode;
			}
		}

		return $settings;
	}

	private function refreshToken($data, $settings)
	{
		if ($this->tries >= $this->maxTries) {
			return false;
		}
		$this->tries++;
//		$this->messages[] = 'Refreshing token';
		if (!empty($settings)) {
			$data['password'] = $settings['password'];
		}

		$response = $this->request(LicenseClient::CONTEXT_AUTHENTICATION, $data, $settings);

		if (!empty($response['token'])) {
			fn_set_storage_data('helostore_token', $response['token']);
//			$this->messages[] = 'Received new token';
			$this->tries = 0;
//			return true;
		}

		if ($this->tries > 1) {
			sleep(5);
		}

		return $response;

//		return false;
	}

	public function handleResponse($context, $response, $productCode)
	{
		$code = isset($response['code']) ? intval($response['code']) : -1;
		$message = !empty($response['message']) ? $response['message'] : '';
		$codeName = LicenseClient::getCodeName($code);
		$codeType = LicenseClient::getCodeType($code);
		$error = LicenseClient::isError($code);
		$alien = LicenseClient::isAlien($code);

		$success = LicenseClient::isSuccess($code);
		$debug = defined('WS_DEBUG');


		if ($context == LicenseClient::CONTEXT_ACTIVATE) {
			if ($success) {
				$this->setLicenseStatus($productCode, LicenseClient::LICENSE_STATUS_ACTIVE);
			} else if ($error) {
				$this->setLicenseStatus($productCode, '');
			} else {
				// nothing changed, stay put
			}
		} else if ($context == LicenseClient::CONTEXT_UPDATE_REQUEST) {

		}

		if ($success) {
			if (!empty($message)) {
				fn_set_notification('S', __('well_done'), $message);
			} else  {
				// There are silent responses, you know..
			}
		} else if ($error || $alien) {
			if ($codeName !== false) {
				fn_set_notification('E',  __('error'), __('sidekick.' . $codeName) . ($debug ? ' (' . $codeName . ')' : ''));
			} else {
				$message = json_encode($response);
				fn_set_notification('E', 'Unknown error', $message . ($debug ? ' (' . $codeName . ')' : ''));
				fn_set_notification('E', 'Unknown error trace', btx());
			}
		}

		if (defined('WS_DEBUG')) {
			if (!empty($response['request'])) {
				fn_set_notification('W', 'Request', json_encode($response['request']));
			}
			if (!empty($response['trace'])) {
				fn_set_notification('W', 'Trace', $response['trace']);
			}
		}


		return (!$error);
	}
	public function isLicenseActive($productCode)
	{
		$status = $this->getLicenseStatus($productCode);

		return ($status == LicenseClient::LICENSE_STATUS_ACTIVE);
	}

	public function getLicenseStatus($productCode)
	{
		return fn_get_storage_data('helostore/' . $productCode . '/license_status');
	}
	public function setLicenseStatus($productCode, $status)
	{
		return fn_set_storage_data('helostore/' . $productCode . '/license_status', $status);
	}
	public function haveSettingsChanged($productCode)
	{
		$settings = Settings::instance()->getValues($productCode, Settings::ADDON_SECTION, false);
		$previousSettings = Registry::get('addons.' . $productCode);

		if (
			$previousSettings['license'] != $settings['license']
			|| $previousSettings['email'] != $settings['email']
			|| $previousSettings['password'] != $settings['password']
			|| empty($settings['email'])
			|| empty($settings['password'])
			|| empty($settings['license'])
		) {
//			Registry::set('addons.' . $productCode, $settings);

			return true;
		}

		return false;
	}
	public function hasRequiredSettings($productCode)
	{
		$settings = Settings::instance()->getValues($productCode, Settings::ADDON_SECTION, false);

		if (empty($settings['email'])
			|| empty($settings['password'])
//			|| empty($settings['license'])
		) {
			return false;
		}

		return true;
	}
	public function processPreEvents($context, $settings)
	{
		$productCode = $settings['code'];
		$productName = $settings['name'];
//		if ($context == LicenseClient::CONTEXT_INSTALL
//			|| ($context == LicenseClient::CONTEXT_ACTIVATE && !$this->hasRequiredSettings($productCode))
//		) {
//			$url = fn_url('addons.update?addon=' . $productCode);
//			$message = __('sidekick.app_setup_message', array('[addon]' => $productName, '[url]' => $url));
//			fn_set_notification('N', __('sidekick.app_setup_title'), $message, 'S');
//		}

		if (in_array($context, array(LicenseClient::CONTEXT_DEACTIVATE, LicenseClient::CONTEXT_UNINSTALL))) {
			$this->setLicenseStatus($productCode, '');
		}
	}
	public function requestUpdateCheck($context, $data)
	{
		$manager = new UpdateManager();;
		$data['products'] = $manager->getProducts();
		unset($data['product']);

		$response = $this->request($context, $data, array());

		if (!empty($response) && !empty($response['updates'])) {
			$manager->processNotifications($response['updates']);
		}

		return $response;
	}

	public function requestUpdateRequest($context, $data, $productCodes)
	{
		$manager = new UpdateManager();
		$data['products'] = $manager->getProducts(array('codes' => $productCodes));
		unset($data['product']);

		$response = $this->request($context, $data, array());
		if (!empty($response) && !empty($response['updates'])) {
			$manager->processNotifications($response['updates']);
//			foreach ($response['updates'] as $update) {
//				$this->requestUpdateDownload($update);
//			}
//			$manager->update($response['updates']);
		}

		return $response;
	}

	public function requestUpdateDownload($productCode)
	{
		$context = LicenseClient::CONTEXT_UPDATE_DOWNLOAD;
		$settings = $this->getSettings($productCode);
		$data = $this->gatherData($context, $settings);

		$response = $this->request($context, $data, $settings);
		$manager = new UpdateManager();
		$result = $manager->updateAddon($productCode, $settings, $response);
		if ($result) {
			fn_redirect('addons.update?addon=' . $productCode);
		}

		return $result;
	}







	public static function getCodeTypes()
	{
		static $types = array(
			LicenseClient::CODE_TYPE_ERROR,
			LicenseClient::CODE_TYPE_SUCCESS,
			LicenseClient::CODE_TYPE_NOTIFICATION,
		);

		return $types;
	}
	public static function getCodeConstants()
	{
		static $constants = null;
		$types = self::getCodeTypes();
		if ($constants == null) {
			$reflection = new ReflectionClass(__CLASS__);
			$allConstants = $reflection->getConstants();
			foreach ($types as $type) {
				$constants[$type] = array();
				$typePrefix = 'CODE_' . strtoupper($type);
				$typePrefixLen = strlen($typePrefix);
				foreach ($allConstants as $constant => $value) {
					if (substr($constant, 0, $typePrefixLen) == $typePrefix) {
						$constants[$type][$constant] = $value;
					}
				}
			}
		}

		return $constants;
	}
	public static function getCodeName($code)
	{
		$constants = self::getCodeConstants();
		foreach ($constants as $type => $typeConstants) {
			$name = array_search($code, $typeConstants);
			if ($name !== false) {
				return $name;
			}
		}

		return false;
	}
	public static function getCodeType($code)
	{
		$constants = self::getCodeConstants();
		$types = self::getCodeTypes();
		foreach ($types as $type) {
			if (in_array($code, $constants[$type])) {
				return $type;
			}
		}

		return LicenseClient::CODE_TYPE_UNKNOWN;
	}
	public static function isSuccess($code)
	{
		return (LicenseClient::getCodeType($code) === LicenseClient::CODE_TYPE_SUCCESS);
	}
	public static function isError($code)
	{
		$codeType = LicenseClient::getCodeType($code);
		return ($codeType === LicenseClient::CODE_TYPE_ERROR);
	}
	public static function isAlien($code)
	{
		return (LicenseClient::getCodeType($code) === LicenseClient::CODE_TYPE_UNKNOWN);
	}
	public static function inferAddonName($backtrack = 1)
	{
		$trace = debug_backtrace(false);
		$caller = array();
		for ($i = 1; $i <= $backtrack + 1; $i++) {
			$caller = array_shift($trace);
		}
		$productCode = '';
		if (!empty($caller) && !empty($caller['file'])) {
			// ughhh, workaround to handle symlinks!
			$callerPath = str_replace(array('\\', '/'), '/', $caller['file']);
			$relativePath = substr($callerPath, strrpos($callerPath, '/app/') + 1);
			$dirs = explode('/', $relativePath);
			array_shift($dirs);
			array_shift($dirs);
			$productCode = array_shift($dirs);
		}

		return $productCode;
	}


	public static function helperInfo($productCode)
	{
		$client = new LicenseClient();
		$active = null;
		$productName = '';
		if (!empty($productCode)) {
			$active = $client->isLicenseActive($productCode);
			$settings = $client->getSettings($productCode);
			$version = !empty($settings) && !empty($settings['version']) ? $settings['version'] : 0;
			$productName = !empty($settings) && !empty($settings['name']) ? $settings['name'] : '';
		}

		$mode = Registry::get('runtime.mode');
		if (!in_array($mode, array('reinstall'))) {
			LicenseClient::checkUpdates();
		}

		return '
			<div style="text-align: center;padding:5px 10%;">
				' . ($active === true ? '<p>' . __('sidekick.license_status_active') . '</p>' : '') . '
				' . ($active !== true ? '<p><input class="btn btn-primary cm-ajax" type="submit" value="' . __('activate') . '" name="dispatch[addons.update.activate]"></p>' : '') . '
				<p>' . __('sidekick.contact_hint') . '</p>
				' . (!empty($version) ? '<p>' . $productName . ' ' . __('version') . ': ' . $version . '</p>' : '') . '
				<p><input class="btn btn-tertiary cm-ajax" type="submit" value="' . __('sidekick.check_updates_button') . '" name="dispatch[addons.update.check_updates]"></p>
			</div>
			';
	}
	public static function process($context, $productCode = '', $backtrack = 1)
	{
		if (empty($context)) {
			return false;
		}
		$client = new LicenseClient();

		$productCode = !empty($productCode) ? $productCode : self::inferAddonName($backtrack);
		$settings = $client->getSettings($productCode);
		$data = $client->gatherData($context, $settings);
		$client->processPreEvents($context, $settings);

		if ($context == LicenseClient::CONTEXT_UPDATE_CHECK) {
			$response = $client->requestUpdateCheck($context, $data);
			return $client->handleResponse($context, $response, $productCode);
		}
		if ($context == LicenseClient::CONTEXT_UPDATE_REQUEST) {
			$response = $client->requestUpdateRequest($context, $data, $productCode);
			if (!empty($response['updates'])) {
				foreach ($response['updates'] as $update) {
					$client->requestUpdateDownload($update['code']);
				}
			}
			return $client->handleResponse($context, $response, $productCode);
		}


		if (!$client->hasRequiredSettings($productCode)) {
			return false;
		}

		if ($context == LicenseClient::CONTEXT_ACTIVATE) {
			$changes = $client->haveSettingsChanged($productCode);
			$inactive = !$client->isLicenseActive($productCode);
			if ($changes || $inactive) {
				$response = $client->request($context, $data, $settings);
			} else {
				$response = array(
					'code' => LicenseClient::CODE_NOTIFICATION_SETTINGS_UNCHANGED
				);
			}
		} else {
			$response = $client->request($context, $data, $settings);
		}

		return $client->handleResponse($context, $response, $productCode);
	}
	public static function activate($productCode = '', $backtrack = 2)
	{
		return LicenseClient::process(LicenseClient::CONTEXT_ACTIVATE, $productCode, $backtrack);
	}
	public static function deactivate($productCode = '', $backtrack = 2)
	{
		return LicenseClient::process(LicenseClient::CONTEXT_DEACTIVATE, $productCode, $backtrack);
	}

	public static function checkUpdates()
	{
		return LicenseClient::process(LicenseClient::CONTEXT_UPDATE_CHECK);
	}

	public static function update($productCodes)
	{
		return LicenseClient::process(LicenseClient::CONTEXT_UPDATE_REQUEST, $productCodes);
	}
}