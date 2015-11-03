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

use Tygh\Addons\SchemesManager;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
			fn_set_notification('W', __('warning'), __('sidekick_dependency_breakage_warning', array(
				'[addons]' => implode(', ', SchemesManager::getNames($dependencies)),
				'[addon_name]' => $scheme->getName()
			)));
		}
	}

}