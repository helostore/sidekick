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

use Tygh\Addons\AXmlScheme;
use Tygh\Registry;
use Tygh\Settings;


// Polyfills from CS-Cart 4.7.1-SP1

if ( ! function_exists('fn_update_addon_language_variables')) {
    /**
     * Updates language variables of particular addon
     *
     * @param AXmlScheme $addon_scheme Addon scheme
     */
    function fn_update_addon_language_variables(AXmlScheme $addon_scheme)
    {
        $language_variables = $addon_scheme->getLanguageValues(false);
        if ( ! empty($language_variables)) {
            db_query('REPLACE INTO ?:language_values ?m', $language_variables);
        }

        $language_variables = $addon_scheme->getLanguageValues(true);
        if ( ! empty($language_variables)) {
            db_query('REPLACE INTO ?:original_values ?m', $language_variables);
        }
    }
}

if ( ! function_exists('fn_get_addon_settings_values')) {
    /**
     * Gets setting values of particular addon
     *
     * @param  string $addon_name Addon name
     *
     * @return array Array of setting values
     * @internal
     */
    function fn_get_addon_settings_values($addon)
    {
        $setting_values = array();

        if (empty($addon)) {
            return $setting_values;
        }

        $setting_values = Settings::instance()->getValues($addon, Settings::ADDON_SECTION, false);
        $setting_values = ! empty($setting_values) ? $setting_values : array();

        foreach ($setting_values as $setting_name => $setting_value) {
            if (is_array($setting_value)) {
                $setting_values[$setting_name] = array_keys($setting_value);
            }
        }

        return $setting_values;
    }
}

if ( ! function_exists('fn_get_addon_settings_vendor_values')) {

    /**
     * Gets vendor values of particular addon
     *
     * @param  string $addon_name Addon name
     *
     * @return array Array of vendor values
     * @internal
     */
    function fn_get_addon_settings_vendor_values($addon)
    {
        $vendor_values = array();

        if (
            ! fn_allowed_for('ULTIMATE')
            || empty($addon)
        ) {
            return $vendor_values;
        }

        $section    = Settings::instance()->getSectionByName($addon, Settings::ADDON_SECTION);
        $section_id = ! empty($section['section_id']) ? $section['section_id'] : 0;
        if (empty($section_id)) {
            return array();
        }
        $settings   = Settings::instance()->getList($section_id, 0, true);

        foreach ($settings as $setting) {
            $vendor_values[$setting['name']] = Settings::instance()->getAllVendorsValues($setting['name'], $addon);
        }

        return $vendor_values;
    }
}

if ( ! function_exists('fn_update_addon_settings_polyfill')) {

    /**
     * Update addon settings in database
     *
     * @param AXmlScheme $addon_scheme Addon scheme
     * @param boolean $execute_functions Trigger settings update functions
     * @param array $values Array of setting values
     * @param array $vendor_values Array of setting vendor values
     *
     * @return bool True on success, false otherwise
     */
    function fn_update_addon_settings_polyfill(
        $addon_scheme,
        $execute_functions = true,
        $values = array(),
        $vendor_values = array()
    ) {
        $section = Settings::instance()->getSectionByName($addon_scheme->getId(), Settings::ADDON_SECTION);

        if (isset($section['section_id'])) {
            Settings::instance()->removeSection($section['section_id']);
        }

        $tabs = $addon_scheme->getSections();

        // If isset section settings in xml data and that addon settings is not exists
        if ( ! empty($tabs)) {
            Registry::set('runtime.database.skip_errors', true);

            // Create root settings section
            $addon_section_id = Settings::instance()->updateSection(array(
                'parent_id'    => 0,
                'edition_type' => $addon_scheme->getEditionType(),
                'name'         => $addon_scheme->getId(),
                'type'         => Settings::ADDON_SECTION,
            ));

            foreach ($tabs as $tab_index => $tab) {
                // Add addon tab as setting section tab
                $section_tab_id = Settings::instance()->updateSection(array(
                    'parent_id'    => $addon_section_id,
                    'edition_type' => $tab['edition_type'],
                    'name'         => $tab['id'],
                    'position'     => $tab_index * 10,
                    'type'         => isset($tab['separate']) ? Settings::SEPARATE_TAB_SECTION : Settings::TAB_SECTION,
                ));

                // Import translations for tab
                if ( ! empty($section_tab_id)) {
                    fn_update_addon_settings_descriptions($section_tab_id, Settings::SECTION_DESCRIPTION,
                        $tab['translations']);
                    fn_update_addon_settings_originals($addon_scheme->getId(), $tab['id'], 'section', $tab['original']);

                    $settings = $addon_scheme->getSettings($tab['id']);

                    foreach ($settings as $k => $setting) {
                        if ( ! empty($setting['id'])) {

                            if ( ! empty($setting['parent_id'])) {
                                $setting['parent_id'] = Settings::instance()->getId($setting['parent_id'],
                                    $addon_scheme->getId());
                            }

                            $setting_id = Settings::instance()->update(array(
                                'name'           => $setting['id'],
                                'section_id'     => $addon_section_id,
                                'section_tab_id' => $section_tab_id,
                                'type'           => $setting['type'],
                                'position'       => isset($setting['position']) ? $setting['position'] : $k * 10,
                                'edition_type'   => $setting['edition_type'],
                                'is_global'      => 'N',
                                'handler'        => $setting['handler'],
                                'parent_id'      => intval($setting['parent_id'])
                            ));

                            if ( ! empty($setting_id)) {
                                $setting_value = isset($values[$setting['id']]) ? $values[$setting['id']] : $setting['default_value'];

                                Settings::instance()->updateValueById($setting_id, $setting_value, null,
                                    $execute_functions);

                                if (
                                    ! empty($vendor_values[$setting['id']])
                                    // && Settings::instance()->isVendorValuesSupportedByEditionType($setting['edition_type'])
                                ) {
                                    foreach ($vendor_values[$setting['id']] as $company_id => $vendor_setting_value) {
                                        if ($setting_value != $vendor_setting_value) {
                                            Settings::instance()->updateValueById($setting_id, $vendor_setting_value,
                                                $company_id, $execute_functions);
                                        }
                                    }
                                }

                                fn_update_addon_settings_descriptions($setting_id, Settings::SETTING_DESCRIPTION,
                                    $setting['translations']);
                                fn_update_addon_settings_originals($addon_scheme->getId(), $setting['id'], 'option',
                                    $setting['original']);

                                if (isset($setting['variants'])) {
                                    foreach ($setting['variants'] as $variant_k => $variant) {
                                        $variant_id = Settings::instance()->updateVariant(array(
                                            'object_id' => $setting_id,
                                            'name'      => $variant['id'],
                                            'position'  => isset($variant['position']) ? $variant['position'] : $variant_k * 10,
                                        ));

                                        if ( ! empty($variant_id)) {
                                            fn_update_addon_settings_descriptions($variant_id,
                                                Settings::VARIANT_DESCRIPTION, $variant['translations']);
                                            fn_update_addon_settings_originals($addon_scheme->getId(),
                                                $setting['id'] . '::' . $variant['id'], 'variant',
                                                $variant['original']);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            Registry::set('runtime.database.skip_errors', false);

            $errors = Registry::get('runtime.database.errors');
            if ( ! empty($errors)) {
                $error_text = '';
                foreach ($errors as $error) {
                    $error_text .= '<br/>' . $error['message'] . ': <code>' . $error['query'] . '</code>';
                }
                fn_set_notification('E', __('addon_sql_error'), $error_text, '', 'addon_update_settings_sql_error');

                Registry::set('runtime.database.errors', array());

                return false;
            }
        }

        return true;
    }
}