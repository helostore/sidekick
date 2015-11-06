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
	public function update($updates)
	{
		foreach ($updates as $productCode => $update) {
			$this->updateAddon($productCode, $update);
		}
	}

	public function getProducts($params = array())
	{
		list($addons, ) = fn_get_addons(array());
		$products = array();
		foreach ($addons as $productCode => $addon) {
			if (!$this->isOwnProduct($productCode)) {
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
	public function isOwnProduct($productCode)
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

	public function updateAddon($productCode, $update)
	{
		aa(func_get_args());
	}
}