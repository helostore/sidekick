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

use HeloStore\ADLS\LicenseClient;
use HeloStore\ADLS\UpdateManager;
use Tygh\Addons\SchemesManager;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * @param $addon
 *
 * @return bool
 */
function fn_sidekick_check($addon)
{
	if (!UpdateManager::isOwnProduct($addon)) {
		return false;
	}
	if (LicenseClient::activate($addon)) {
		return true;
	}
	return false;
}

function fn_sidekick_info($productCode)
{
	if (class_exists('LicenseClient', true)) {
		return LicenseClient::helperInfo($productCode);
	}

	return '';
}

/* Hooks */
/**
 * @param $statusNew
 * @param $statusOld
 */
function fn_settings_actions_addons_sidekick(&$statusNew, $statusOld)
{
	if ($statusNew == $statusOld) {
		return;
	}
	if ($statusNew == 'D') {
		$dependencies = db_get_fields("SELECT addon FROM ?:addons WHERE status = 'A' AND FIND_IN_SET(?s, dependencies)", SIDEKICK_ADDON_NAME);
		if (!empty($dependencies)) {
			$scheme = SchemesManager::getScheme(SIDEKICK_ADDON_NAME);
			$statusNew = 'A';
			fn_set_notification('W', __('warning'), __('sidekick.dependency_breakage_warning', array(
				'[addons]' => implode(', ', SchemesManager::getNames($dependencies)),
				'[addon_name]' => $scheme->getName()
			)));
		}
	}
}

/**
 * Regularly check for updates (once every 2 days) on authentication of an administrator
 *
 * @param $auth
 * @param $userInfo
 * @param $firstInit
 *
 * @return bool
 */
function fn_sidekick_user_init($auth, $userInfo, $firstInit)
{
	if (!empty($userInfo) && !empty($userInfo['user_type']) && $userInfo['user_type'] == 'A') {
		if (fn_is_expired_storage_data('helostore_update_check', SECONDS_IN_DAY * 2)) {
			fn_define('SIDEKICK_SILENT_UPDATES_CHECK', true);

			return LicenseClient::checkUpdates();
		}
	}

	return false;
}
/* /Hooks */
