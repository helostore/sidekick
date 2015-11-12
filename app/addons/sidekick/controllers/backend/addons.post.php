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
	if ($mode == 'update') {
		if (!empty($_REQUEST['addon'])) {
			$action = Registry::get('runtime.action');
			$addon = $_REQUEST['addon'];
			$ownProduct = \HeloStore\ADLS\UpdateManager::isOwnProduct($addon);

			if ($ownProduct) {
				if ($action == 'activate') {
					$status = fn_update_addon_status($addon, 'A');
					if ($status == 'A') {
						Registry::clearCachedKeyValues();
					}
				}
				if ($action == 'check_updates') {
					\HeloStore\ADLS\LicenseClient::checkUpdates();
				}
				return array(CONTROLLER_STATUS_OK, 'addons.manage');
			}
		}
	}
}


if ($mode == 'check_updates') {
	\HeloStore\ADLS\LicenseClient::checkUpdates();
	return array(CONTROLLER_STATUS_OK, 'addons.manage');
}