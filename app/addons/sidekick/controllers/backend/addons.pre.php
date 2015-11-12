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

if (!function_exists('fn_helostore_info')) :
	function fn_helostore_info($productCode)
	{
		if (class_exists('\HeloStore\ADLS\LicenseClient', true)) {
			return \HeloStore\ADLS\LicenseClient::helperInfo($productCode);
		}

		return '';
	}
endif;