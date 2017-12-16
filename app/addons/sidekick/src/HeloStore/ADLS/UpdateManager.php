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

namespace HeloStore\ADLS;


use Tygh\Addons\SchemesManager;
use Tygh\Registry;
use Tygh\Settings;

class UpdateManager
{
	/**
	 * @param $updates
	 * @param array $requestProducts
	 */
	public function processNotifications($updates, $requestProducts = array())
	{
		foreach ($updates as $productCode => $update) {
			$updateUrl = fn_url('sidekick.update?product=' . $productCode);

			if ( ! empty( $update['notifications'] ) ) {
				foreach ( $update['notifications'] as $notification ) {
					$notificationType = isset( $notification['notification_type'] ) ? $notification['notification_type'] : 'N';
					$notificationExtra = isset( $notification['notification_extra'] ) ? $notification['notification_extra'] : '';
					$notificationState = isset( $notification['notification_state'] ) ? $notification['notification_state'] : 'K';
					$title = isset( $notification['title'] ) ? $notification['title'] : __('notice');
					$message = $notification['message'];
					$message = str_replace( '[updateUrl]', $updateUrl, $message );
					fn_set_notification($notificationType, $title, $message, $notificationState, $notificationExtra);
				}
			} else {
				// @TODO deprecate, logic moved into ADLS module
				$settings = $this->getSettings($productCode);
				$productName = !empty($settings['name']) ? $settings['name'] : '';
				$currentVersion = !empty($settings['version']) ? $settings['version'] : '';
				// For testing purposes
				if ( ! empty( $requestProducts ) && ! empty( $requestProducts[ $productCode ] ) ) {
					$currentVersion = $requestProducts[ $productCode ]['version'];
				}

				$nextVersion = !empty($update['version']) ? $update['version'] : '';
				if (empty($productName) || empty($currentVersion) || empty($nextVersion)) {
					continue;
				}

				if (version_compare($nextVersion, $currentVersion) === 1) {

					$message = __('sidekick.product_update_available', array(
						'[addon]' => $productName,
						'[currentVersion]' => $currentVersion,
						'[nextVersion]' => $nextVersion,
						'[updateUrl]' => $updateUrl,
					));

					fn_set_notification('N', __('sidekick.product_update_available_title'), $message, 'K', 'sidekick.product_update_available_title');
				}
			}
		}
	}

	/**
	 * @param array $params
	 *
	 * @return array
	 */
	public function getProducts($params = array())
	{
		list($addons, ) = fn_get_addons(array());
		$products = array();
		foreach ($addons as $productCode => $addon) {
			if (!self::isOwnProduct($productCode)) {
				continue;
			}
			if (!empty($params['codes'])) {
				if (!in_array($productCode, $params['codes'])) {
					continue;
				}
			}
			$products[$productCode] = $this->getSettings($productCode);
		}

		return $products;
	}

	/**
	 * @param $productCode
	 *
	 * @return array|bool
	 */
	public function getSettings($productCode)
	{
		$settings = Settings::instance()->getValues($productCode, Settings::ADDON_SECTION, false);
		$scheme = SchemesManager::getScheme($productCode);
		if (!empty($scheme)) {
			if (method_exists($scheme, 'getVersion')) {
				$settings['version'] = $scheme->getVersion();
				$settings['name'] = $scheme->getName();
				$settings['code'] = $productCode;
			}
		}

		return $settings;
	}

	/**
	 * @param $productCode
	 *
	 * @return bool
	 */
	public static function isOwnProduct($productCode)
	{
		$scheme = SchemesManager::getScheme($productCode);
		if (empty($scheme)) {
			return false;
		}
		try {
			// xml prop is protected. We care not. We go for it. (XmlScheme3 should have implemented getAuthors()!)
			$a = (Array)$scheme;
			$key = "\0*\0_xml";;
			if (empty($a) || empty($a[$key]) || ! $a[$key] instanceof \SimpleXMLElement) {
				return false;
			}

			$author = (Array)$a[$key]->authors->author;
			if (empty($author) || empty($author['name']) || $author['name'] != SIDEKICK_AUTHOR_NAME) {
				return false;
			}

			return true;

		} catch (\Exception $e) {
			// Doing nothing, having a coffee, chilling.
		}

		return false;
	}

	/**
	 * @param $productCode
	 * @param $settings
	 * @param $content
	 *
	 * @return bool
	 */
	public function updateAddon($productCode, $settings, $content)
	{
		define('ADLS_UPDATING', true);
		$installed = db_get_field("SELECT status FROM ?:addons WHERE addon = ?s", $productCode);
		if (!empty($installed)) {
			$this->preserveAddonSettings($productCode);
		}

		$tempPath = fn_get_cache_path(false) . 'tmp/';
		$extractPath = $tempPath . $productCode . '/';
		$archivePath = $tempPath . '/' . $productCode . '.zip';
		if (!fn_put_contents($archivePath, $content)) {
			$message = sprintf('Insufficient write permissions: cannot write archive to `%s`', $archivePath);
			fn_set_notification('E', __('error'), $message, 'K');

			return false;
		}

		fn_rm($extractPath);
		fn_mkdir($extractPath);


		if (!fn_decompress_files($archivePath, $extractPath)) {
			$message = sprintf('Insufficient write permissions: cannot unpack archive to `%s`', $extractPath);
			fn_set_notification('E', __('error'), $message, 'K');

			return false;
		}

		$issueDirs = fn_check_copy_ability($extractPath, Registry::get('config.dir.root'));
		if (!empty($issueDirs)) {
			$message = __('sidekick.product_update_file_permissions_error', array('[files]' => implode("<br>", $issueDirs)));
			fn_set_notification('E', __('error'), $message, 'K');
		} else {
			$rootPath = Registry::get('config.dir.root');
			if (!fn_copy($extractPath, $rootPath)) {
				$message = sprintf('Insufficient write permissions: cannot copy files to `%s`', $rootPath);
				fn_set_notification('E', __('error'), $message, 'K');

				return false;
			}
			fn_rm($extractPath);

			if (!empty($installed)) {
				fn_uninstall_addon($productCode, false);
			}

			if (fn_install_addon($productCode, false)) {
				$this->preserveAddonSettings($productCode);
				fn_set_notification('N', __('notice'), __('sidekick.update_successful', array('[product]' => $settings['name'])), 'S');
			}

			return true;
		}
	}

	/**
	 * @param $productCode
	 *
	 * @return bool
	 */
	public function preserveAddonSettings($productCode)
	{
		static $prevSettings = null;
		$persistentSettings = array('email', 'password', 'license');
		// on first call, store previous settings
		if ($prevSettings === null) {
			$section = Settings::instance()->getSectionByName($productCode, Settings::ADDON_SECTION);
			$settings = Settings::instance()->getList($section['section_id'], 0, true);
			if (!empty($settings)) {
				foreach ($settings as $setting) {
					if (in_array($setting['name'], $persistentSettings)) {
						$prevSettings[$setting['name']] = $setting['value'];
					}
				}
			}

			return !empty($prevSettings);
		}

		// nothing to restore
		if (empty($prevSettings)) {
			return false;
		}

		// on subsequent calls, restore settings
		$section = Settings::instance()->getSectionByName($productCode, Settings::ADDON_SECTION);
		$currentSettings = Settings::instance()->getList($section['section_id'], 0, true);

		$changes = false;
		foreach ($currentSettings as $i => $setting) {
			if (empty($setting['value']) && !empty($prevSettings[$setting['name']])) {
				$currentSettings[$i]['value'] = $prevSettings[$setting['name']];
				$value = $prevSettings[$setting['name']];
				db_query('UPDATE ?:settings_objects SET value = ?s WHERE object_id = ?i', $value, $setting['object_id']);
				$changes = true;
			}
		}

		return $changes;
	}

	/**
	 * @param $productCode
	 * @param array $update
	 *
	 * @return bool
	 */
	public static function showUpdateSummary($productCode, $update = array())
	{
		if (empty($productCode) || empty($update)) {
			return false;
		}
		$releaseLogFilename = 'release.json';
		$scheme = SchemesManager::getScheme($productCode);
		if (empty($scheme)) {
			return false;
		}

		$addonPath = Registry::get('config.dir.addons') . $productCode . DIRECTORY_SEPARATOR . '';
		$releaseLogPath = $addonPath . $releaseLogFilename;
		if (!file_exists($releaseLogPath)) {
			return false;
		}
		$string = file_get_contents($releaseLogPath);
		$releases = json_decode($string, true);
		if (empty($releases) || !is_array($releases)) {
			return false;
		}
		$release = array_shift($releases);
		if (!is_array($release)
			|| empty($release['version'])
			|| empty($release['releaseTimestamp'])
			|| empty($release['commits'])
			|| !is_array($release['commits'])
		) {
			return false;
		}

		$message = __('sidekick.update_summary_message', array(
			'[product]' => $scheme->getName(),
			'[version]' => $release['version'],
			'[date]' => fn_date_format($release['releaseTimestamp'], "%m/%d/%Y"),
			'[commits]' => implode('<br>', $release['commits'])
		));

		if (!empty($update['reviewMessage'])) {
			$message .= $update['reviewMessage'];
		}
		fn_set_notification('I', __('sidekick.update_summary_title'), $message, 'S', 'sidekick.update_summary');

		return true;
	}
}
