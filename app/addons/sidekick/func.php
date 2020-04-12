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
use Tygh\Addons\SchemesManager;
use Tygh\Registry;
use Tygh\Settings;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Secure existing passwords in all HELOstore add-ons
 */
function fn_sidekick_secure_passwords()
{
    $client = \HeloStore\ADLS\LicenseClientFactory::build();
    list($addons, ) = fn_get_addons(array());
    foreach ($addons as $addonCode => $addon) {
        if ( ! $client->getEnvironment()->isOwnProduct($addonCode)) {
            continue;
        }
        fn_sidekick_encrypt_password($addonCode);
    }
}

/**
 * Hash passwords in add-on settings
 *
 * @param $addon
 * @param $requestOptions
 *
 * @return array
 */
function fn_sidekick_encrypt_password($addon, $requestOptions = array())
{
    $section = Settings::instance()->getSectionByName($addon, Settings::ADDON_SECTION);
    if (empty($section)) {
        return array(CONTROLLER_STATUS_OK);
    }

    $sectionsOptions = Settings::instance()->getList($section['section_id']);
    $passwordOptionId = null;
    $currentHash = null;
    $currentValue = null;
    $isCurrentValueMd5 = false;
    foreach ($sectionsOptions as $sectionCode => $options) {
        foreach ($options as $optionId => $option) {
            if (empty($option) || empty($option['name'])) {
                continue;
            }
            if ($option['name'] !== 'password') {
                continue;
            }
            $passwordOptionId = $optionId;
            $currentValue = $option['value'];
            $isCurrentValueMd5 = strlen($currentValue) == 32 && ctype_xdigit($currentValue);
            if ($isCurrentValueMd5) {
                $currentHash = $currentValue;
            }
        }
    }
    if (empty($currentValue) && empty($requestOptions)) {
        return array(CONTROLLER_STATUS_OK);
    }

    $nextValue = null;
    $nextHash = null;
    if ( ! empty($requestOptions)) {
        if (empty($requestOptions[$passwordOptionId])) {
            return array(CONTROLLER_STATUS_OK);
        }
        $nextValue = $requestOptions[$passwordOptionId];
        $nextHash = md5($nextValue);
    } else {
        $nextValue = $currentValue;
        if ($isCurrentValueMd5) {
            $nextHash = $currentHash;
        } else {
            $nextHash = md5($nextValue);
        }
    }
    if ($nextValue === null) {
        return array(CONTROLLER_STATUS_OK);
    }
    $isNextValueMd5 = strlen($nextValue) == 32 && ctype_xdigit($nextValue);

    // The password's hash did not change
    //  - case 1. The input value is already a hash, and it did not change
    if ($isNextValueMd5 && $currentValue === $nextValue) {
        return array(CONTROLLER_STATUS_OK);
    }
    //  - case 2. The input value is not a hash (ie. user has re-entered the password in plain-text), but the hashes did not change
    if (!$isNextValueMd5 && $currentValue === $nextHash) {
        return array(CONTROLLER_STATUS_OK);
    }
    Settings::instance()->updateValueById($passwordOptionId, $nextHash);

    return array(CONTROLLER_STATUS_OK);
}

/**
 * Hash passwords in add-on settings on update
 *
 * @return array
 */
function fn_sidekick_encrypt_password_in_settings()
{
    if (empty($_REQUEST) || empty($_REQUEST['addon'])) {
        return array(CONTROLLER_STATUS_OK);
    }

    if (empty($_REQUEST['addon_data']) || empty($_REQUEST['addon_data']['options'])) {
        return array(CONTROLLER_STATUS_OK);
    }

    $addon = $_REQUEST['addon'];
    $client = \HeloStore\ADLS\LicenseClientFactory::build();
    $ownProduct = $client->getEnvironment()->isOwnProduct($addon);
    if (!$ownProduct) {
        return array(CONTROLLER_STATUS_OK);
    }

    return fn_sidekick_encrypt_password($addon, $_REQUEST['addon_data']['options']);
}


/**
 * @param $addon
 *
 * @return bool
 */
function fn_sidekick_check($addon)
{
    $client = \HeloStore\ADLS\LicenseClientFactory::build();

	if (!$client->getEnvironment()->isOwnProduct($addon)) {
		return false;
	}
	if (LicenseClient::activate($addon)) {
		return true;
	}
	return false;
}

function fn_sidekick_info($productCode, $showSecurePasswordsButton = false, $useAjax = true)
{
    // Attempt to load new client implementation
    if (class_exists('\HeloStore\ADLS\LicenseClientFactory', true)) {
        $client = \HeloStore\ADLS\LicenseClientFactory::build();
        return $client->getEnvironment()->helperInfo($productCode, $showSecurePasswordsButton, $useAjax);
    }

    // Fallback on the old client implementation
    if (class_exists('\HeloStore\ADLS\LicenseClient', true)) {
        if (method_exists('\HeloStore\ADLS\LicenseClient', 'helperInfo')) {
            return LicenseClient::helperInfo($productCode, $showSecurePasswordsButton, $useAjax);
        }
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
        $autoCheckUpdates = Registry::get('addons.' . SIDEKICK_ADDON_NAME . '.auto_check_updates');
        if ($autoCheckUpdates !== 'Y') {
            return false;
        }

		if (fn_is_expired_storage_data('helostore_update_check', SECONDS_IN_DAY * 2)) {
			fn_define('SIDEKICK_SILENT_UPDATES_CHECK', true);

			return LicenseClient::checkUpdates('all');
		}
	}

	return false;
}
/* /Hooks */
