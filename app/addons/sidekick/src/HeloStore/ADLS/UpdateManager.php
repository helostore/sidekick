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


use Tygh\Addons\SchemesManager;
use Tygh\Http;
use Tygh\Registry;
use Tygh\Settings;
use Tygh\Tygh;

class UpdateManager
{

	public function processNotifications($updates)
	{
		foreach ($updates as $productCode => $update) {
			$settings = $this->getSettings($productCode);
			$productName = !empty($settings['name']) ? $settings['name'] : '';
			$currentVersion = !empty($settings['version']) ? $settings['version'] : '';
			$nextVersion = !empty($update['version']) ? $update['version'] : '';
			if (empty($productName) || empty($currentVersion) || empty($nextVersion)) {
				continue;
			}

			if (version_compare($nextVersion, $currentVersion) === 1) {
				$updateUrl = fn_url('sidekick.update?product=' . $productCode);
				$message = __('sidekick.product_update_available', array(
					'[addon]' => $productName,
					'[currentVersion]' => $currentVersion,
					'[nextVersion]' => $nextVersion,
					'[updateUrl]' => $updateUrl,
				));
				fn_set_notification('N', __('sidekick.product_update_available_title'), $message, 'K');
			}
		}
	}

	public function getProducts($params = array())
	{
		list($addons, ) = fn_get_addons(array());
		$products = array();
		foreach ($addons as $productCode => $addon) {
			if (!self::isOwnProduct($productCode)) {
				continue;
			}
			if (!empty($params['codes'])) {
				if (!in_array($productCode, $params['codes'])) {
					continue;
				}
			}
			$products[$productCode] = $this->getSettings($productCode);
		}

		return $products;
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
	public static function isOwnProduct($productCode)
	{
		$scheme = SchemesManager::getScheme($productCode);
		if (empty($scheme)) {
			return false;
		}
		try {
			// xml prop is protected. We care not. We go for it. (XmlScheme3 should have implemented getAuthors()!)
			$a = (Array)$scheme;
			$key = "\0*\0_xml";;
			if (empty($a) || empty($a[$key]) || ! $a[$key] instanceof \SimpleXMLElement) {
				return false;
			}

			$author = (Array)$a[$key]->authors->author;
			if (empty($author) || empty($author['name']) || $author['name'] != SIDEKICK_AUTHOR_NAME) {
				return false;
			}

			return true;

		} catch (\Exception $e) {
			// Doing nothing, having a coffee, chilling.
		}

		return false;
	}

	public function updateAddon($productCode, $settings, $content)
	{
		define('ADLS_UPDATING', true);
		$installed = db_get_field("SELECT status FROM ?:addons WHERE addon = ?s", $productCode);
		if (!empty($installed)) {
			$this->preserveAddonSettings($productCode);
		}

		$tempPath = fn_get_cache_path(false) . 'tmp/';
		$extractPath = $tempPath . $productCode . '/';
		$archivePath = $tempPath . '/' . $productCode . '.zip';
		if (!fn_put_contents($archivePath, $content)) {
			// error
			return false;
		}

		fn_rm($extractPath);
		fn_mkdir($extractPath);


		if (!fn_decompress_files($archivePath, $extractPath)) {
			// error
			return false;
		}

		$issueDirs = fn_check_copy_ability($extractPath, Registry::get('config.dir.root'));
		if (!empty($issueDirs)) {
			$message = __('sidekick.product_update_file_permissions_error', array('[files]' => implode("<br>", $issueDirs)));
			fn_set_notification('E', __('error'), $message, 'K');
		} else {
			$rootPath = Registry::get('config.dir.root');
			if (!fn_copy($extractPath, $rootPath)) {
				// error
				return false;
			}
			fn_rm($extractPath);

			if (!empty($installed)) {
				fn_uninstall_addon($productCode, true);
			}

			if (fn_install_addon($productCode, true)) {
				$this->preserveAddonSettings($productCode);
				fn_set_notification('N', __('notice'), __('sidekick.update_successful', array('[product]' => $settings['name'])));
				$force_redirection = 'addons.manage';
				if (defined('AJAX_REQUEST')) {
					Tygh::$app['ajax']->assign('force_redirection', fn_url($force_redirection));
					exit;
				} else {
					fn_redirect($force_redirection);
				}

			}

			return true;
		}
	}

	public function preserveAddonSettings($productCode)
	{
		static $prevSettings = null;
		$persistentSettings = array('email', 'password', 'license');
		// on first call, store previous settings
		if ($prevSettings === null) {
			$section = Settings::instance()->getSectionByName($productCode, Settings::ADDON_SECTION);
			$settings = Settings::instance()->getList($section['section_id'], 0, true);
			if (!empty($settings)) {
				foreach ($settings as $setting) {
					if (in_array($setting['name'], $persistentSettings)) {
						$prevSettings[$setting['name']] = $setting['value'];
					}
				}
			}
			return !empty($prevSettings);
		}

		// nothing to restore
		if (empty($prevSettings)) {
			return false;
		}

		// on subsequent calls, restore settings
		$section = Settings::instance()->getSectionByName($productCode, Settings::ADDON_SECTION);
		$currentSettings = Settings::instance()->getList($section['section_id'], 0, true);

		$changes = false;
		foreach ($currentSettings as $setting) {
			if (empty($setting['value']) && !empty($prevSettings[$setting['name']])) {
				$setting['value'] = $prevSettings[$setting['name']];
				$changes = true;
			}
		}
		if ($changes) {
			fn_update_addon($currentSettings);
		}

		return $changes;
	}
}