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
		}
		$addonId = null;
		if ( ! empty( $_REQUEST['addon'] ) ) {
			$addonId = $_REQUEST['addon'];
		}
		\HeloStore\ADLS\LicenseClient::checkUpdates(false, $addonId);

		return array(CONTROLLER_STATUS_OK, 'addons.manage');
	}
}

if ($mode == 'update') {
	if (!empty($_REQUEST['product'])) {
		$productCode = $_REQUEST['product'];
		fn_delete_notification('sidekick.product_update_available_title');
		if (\HeloStore\ADLS\LicenseClient::update($productCode)) {
			$redirection = 'addons.manage';
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
