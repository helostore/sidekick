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
use Tygh\Tygh;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if ($mode == 'activate') {
		if (!empty($_REQUEST['addon'])) {
			$addon = $_REQUEST['addon'];
            $client = \HeloStore\ADLS\LicenseClientFactory::build();
			$ownProduct = $client->getEnvironment()->isOwnProduct($addon);

			if (isset($_REQUEST['addon_data'])) {
				fn_update_addon($_REQUEST['addon_data']);
			}

			if ($ownProduct) {
                fn_sidekick_encrypt_password_in_settings();
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
					if (method_exists('Tygh\Registry', 'clearCachedKeyValues')) {
						Registry::clearCachedKeyValues();
					}
				}

				return array(CONTROLLER_STATUS_OK, 'addons.manage');
			}
		}
	}

	if ($mode == 'check') {
		if (isset($_REQUEST['addon_data'])) {
			fn_update_addon($_REQUEST['addon_data']);
            fn_sidekick_encrypt_password_in_settings();
		}
		$addonId = null;
		if ( ! empty( $_REQUEST['addon'] ) ) {
			$addonId = $_REQUEST['addon'];
		}
		if ( ! empty( $action ) && $action == 'all' ) {
			$addonId = 'all';
		}
		\HeloStore\ADLS\LicenseClient::checkUpdates($addonId);

		return array(CONTROLLER_STATUS_OK, 'addons.manage');
	}
}

if ($mode == 'secure_passwords') {
    fn_sidekick_secure_passwords();
    fn_set_storage_data('helostore/patch/secure_password', 1);
    fn_set_notification('N', __('notice'), 'All the passwords related to HELOstore products have been secured with md5 hashing.', 'K');
    exit;
}

if ($mode == 'update_summary' && !empty($_REQUEST['product'])) {
    $productCode = $_REQUEST['product'];
    $client = \HeloStore\ADLS\LicenseClientFactory::build();
    $updateManager = $client->getUpdateManager();
    $updateManager->showUpdateSummary($productCode);

    return array(CONTROLLER_STATUS_REDIRECT, 'addons.manage');
}

if ($mode == 'update') {
	if (!empty($_REQUEST['product'])) {
		$productCode = $_REQUEST['product'];
		fn_delete_notification('sidekick.product_update_available_title');
		if (\HeloStore\ADLS\LicenseClient::update($productCode)) {
			$redirection = 'sidekick.update_summary?product='.$productCode;
			if (defined('AJAX_REQUEST')) {
				if (class_exists('Tygh', true)) {
					Tygh::$app['ajax']->assign('force_redirection', fn_url($redirection));
				} else {
					Registry::get('ajax')->assign('force_redirection', fn_url($redirection));
				}
				exit;
			} else {
				return array(CONTROLLER_STATUS_REDIRECT, $redirection);
			}
		}

	}
	return array(CONTROLLER_STATUS_OK);
}
