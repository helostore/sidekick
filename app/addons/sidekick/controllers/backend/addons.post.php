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
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ($mode == 'update') {
		if (!empty($_REQUEST['addon'])) {
			$addon = $_REQUEST['addon'];
			fn_sidekick_check($addon);
		}
		return array(CONTROLLER_STATUS_OK);
	}
}

function fn_sidekick_check($addon)
{
	$scheme = \Tygh\Addons\SchemesManager::getScheme($addon);
	if (empty($scheme)) {
		return false;
	}
	try {
		// xml prop is protected. We care not. We go for it. (XmlScheme3 should have implemented getAuthors()!)
		$a = (Array)$scheme;
		$key = "\0*\0_xml";;
		if (empty($a) || empty($a[$key]) || ! $a[$key] instanceof SimpleXMLElement) {
			return false;
		}

		$author = (Array)$a[$key]->authors->author;
		if (empty($author) || empty($author['name']) || $author['name'] != SIDEKICK_AUTHOR_NAME) {
			return false;
		}

		if (\HeloStore\ADLS\LicenseClient::activate($addon)) {
			return true;
		}
	} catch (\Exception $e) {
		// Doing nothing, having a coffee, chilling.
	}

	return false;
}