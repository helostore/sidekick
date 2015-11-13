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
				fn_set_notification('N', __('sidekick.product_update_available_title'), $message, 'S');
			}
		}
	}
//	public function update($updates)
//	{
//		foreach ($updates as $productCode => $update) {
//			$this->updateAddon($productCode, $update);
//		}
//	}

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
			$section = Settings::instance()->getSectionByName($productCode, Settings::ADDON_SECTION);
			$prevSettings = Settings::instance()->getList($section['section_id'], 0, true);
		}

		$tempPath = fn_get_cache_path(false) . 'tmp/';
		$extractPath = $tempPath . $productCode . '/';
		$archivePath = $tempPath . '/' . $productCode . '.zip';
		if (!fn_put_contents($archivePath, $content)) {
			// error
			aa('Error at ' . __LINE__);
			return false;
		}

		fn_rm($extractPath);
		fn_mkdir($extractPath);


		if (!fn_decompress_files($archivePath, $extractPath)) {
			// error
			aa('Error at ' . __LINE__);
			return false;
		}

		$issueDirs = fn_check_copy_ability($extractPath, Registry::get('config.dir.root'));
		if (!empty($issueDirs)) {
			$message = __('sidekick.product_update_file_permissions_error', array('[files]' => implode("<br>", $issueDirs)));
			fn_set_notification('E', __('error'), $message, 'S');
//			Tygh::$app['view']->assign('non_writable', $non_writable_folders);
//
//			if (defined('AJAX_REQUEST')) {
//				Tygh::$app['view']->display('views/addons/components/correct_permissions.tpl');
//
//				exit();
//			}

		} else {

			$rootPath = Registry::get('config.dir.root');

			if (!fn_copy($extractPath, $rootPath)) {
				// error
				aa('Error at ' . __LINE__);
				return false;
			}
			fn_rm($extractPath);


			if (!empty($installed)) {
				if (fn_uninstall_addon($productCode, true)) {

				}
			}
			if (fn_install_addon($productCode, true)) {
				$force_redirection = 'addons.manage';
				if (defined('AJAX_REQUEST')) {
					Tygh::$app['ajax']->assign('force_redirection', fn_url($force_redirection));
					exit;
				} else {
					return array(CONTROLLER_STATUS_REDIRECT, $force_redirection);
				}

			} else {
				return false;
			}

			if (!empty($installed)) {
//				$section = Settings::instance()->getSectionByName($productCode, Settings::ADDON_SECTION);
//				$currentSettings = Settings::instance()->getList($section['section_id'], 0, true);


			}

			return true;

			// restore add-on settings
//			$settings = Settings::instance()->getValues($productCode, Settings::ADDON_SECTION, true);
//			aa($settings);

//			fn_update_addon($prevSettings);


		}



//		// Re-create source folder
//		fn_rm($extract_path);
//		fn_mkdir($extract_path);
//
//		fn_copy($addon_pack['path'], $extract_path . $productCode);
//
//
//
//		$updateUrl = $update['updateUrl'];
//		$content = fn_get_contents($updateUrl);
//		aa(Http::getError());
//aa($content,1);
//		if (fn_put_contents($target_restore_file_path, $content, '', $target_restore_file_perms)) {
//
//		}
//		aa(func_get_args());

		return false;
	}
}