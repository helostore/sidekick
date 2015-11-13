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
use HeloStore\ADLS\UpdateManager;
use Tygh\Registry;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

}

if ($mode == 'test') {
	$manager = new UpdateManager();
	$manager->restoreAddonSettings('sidekick');
}
if ($mode == 'update') {
	if (!empty($_REQUEST['product'])) {
		$productCode = $_REQUEST['product'];
		\HeloStore\ADLS\LicenseClient::update($productCode);
		fn_redirect('addons.manage');

	}
	return array(CONTROLLER_STATUS_OK);
}
