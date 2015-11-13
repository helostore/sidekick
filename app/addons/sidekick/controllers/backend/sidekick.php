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
use Tygh\Registry;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if ($mode == 'activate') {
		if (!empty($_REQUEST['addon'])) {
			$addon = $_REQUEST['addon'];
			$ownProduct = \HeloStore\ADLS\UpdateManager::isOwnProduct($addon);

			if (isset($_REQUEST['addon_data'])) {
				fn_update_addon($_REQUEST['addon_data']);
			}

			if ($ownProduct) {
				$activated = false;
				$status = Registry::get('addons.' . $addon . '.status');
				if ($status == 'A') {
					$activated = fn_sidekick_check($addon);
				} else {
					$status = fn_update_addon_status($addon, 'A');
					if ($status == 'A') {
						$activated = true;
					}
				}
				if ($activated) {
					Registry::clearCachedKeyValues();
				}

				return array(CONTROLLER_STATUS_OK, 'addons.manage');
			}
		}
	}

	if ($mode == 'check') {
		if (isset($_REQUEST['addon_data'])) {
			fn_update_addon($_REQUEST['addon_data']);
		}
		\HeloStore\ADLS\LicenseClient::checkUpdates();

		return array(CONTROLLER_STATUS_OK, 'addons.manage');
	}
}

if ($mode == 'update') {
	if (!empty($_REQUEST['product'])) {
		$productCode = $_REQUEST['product'];
		\HeloStore\ADLS\LicenseClient::update($productCode);
	}
	return array(CONTROLLER_STATUS_OK);
}
