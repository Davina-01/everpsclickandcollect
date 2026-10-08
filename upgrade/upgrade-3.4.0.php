<?php
/**
 * 2019-2023 Team Ever
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 *  @author    Team Ever <https://www.team-ever.com/>
 *  @copyright 2019-2023 Team Ever
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 3.4.0: "Pick up now" / "Pick up later" with up to 3 time periods, pickup hours per week day,
 * "prepare on arrival" rules, messages T1-T6, staff can change the pickup time.
 * Orders saved by older versions keep their time slots and are still displayed.
 *
 * @param Everpsclickandcollect $module
 */
function upgrade_module_3_4_0($module)
{
    $table = _DB_PREFIX_ . 'everpsclickandcollect';
    $columns = array(
        'pickup_mode' => 'varchar(10) DEFAULT NULL',
        'pickup_periods' => 'text DEFAULT NULL',
        'pickup_prepare' => 'tinyint(1) DEFAULT NULL',
        'pickup_summary' => 'varchar(255) DEFAULT NULL',
    );
    foreach ($columns as $name => $definition) {
        $exists = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . pSQL($name) . '\'');
        if (!$exists) {
            Db::getInstance()->execute('ALTER TABLE `' . $table . '` ADD `' . $name . '` ' . $definition);
        }
    }
    // Old time slot settings are not used anymore
    foreach (array(
        'EVERPSCLICKANDCOLLECT_SLOT_DURATION',
        'EVERPSCLICKANDCOLLECT_LEAD_TIME',
        'EVERPSCLICKANDCOLLECT_DAYS_AHEAD',
        'EVERPSCLICKANDCOLLECT_SLOT_MAX',
        'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT',
        'EVERPSCLICKANDCOLLECT_SLOT_MODE',
    ) as $key) {
        Configuration::deleteByName($key);
    }

    // Pickup information is printed on delivery slips only, not on invoices
    $module->unregisterHook('displayPDFInvoice');
    $module->registerHook('displayPDFDeliverySlip');

    // Pickup hours per week day replace "business days" + earliest / latest time
    $schedule = array();
    $openDays = json_decode((string) Configuration::get('EVERPSCLICKANDCOLLECT_OPEN_DAYS'), true);
    $earliest = Configuration::get('EVERPSCLICKANDCOLLECT_PICKUP_EARLIEST');
    $latest = Configuration::get('EVERPSCLICKANDCOLLECT_PICKUP_LATEST');
    if (is_array($openDays) && $earliest && $latest && Configuration::get('EVERPSCLICKANDCOLLECT_SCHEDULE') === false) {
        for ($day = 1; $day <= 7; ++$day) {
            $schedule[$day] = in_array($day, $openDays) ? $earliest . '-' . $latest : '';
        }
        Configuration::updateValue('EVERPSCLICKANDCOLLECT_SCHEDULE', json_encode($schedule));
    }
    foreach (array('EVERPSCLICKANDCOLLECT_OPEN_DAYS', 'EVERPSCLICKANDCOLLECT_PICKUP_EARLIEST', 'EVERPSCLICKANDCOLLECT_PICKUP_LATEST') as $key) {
        Configuration::deleteByName($key);
    }

    // T3 and T4 do not exist anymore (T3 merged into T2, T4 removed)
    foreach (array('T3', 'T4') as $code) {
        Configuration::deleteByName('EVERPSCLICKANDCOLLECT_TEXT_' . $code);
        Configuration::deleteByName('EVERPSCLICKANDCOLLECT_TEXT_' . $code . '_ON');
    }
    // Reuse the WhatsApp link of the old custom checkout message, if any
    if (!Configuration::get('EVERPSCLICKANDCOLLECT_WHATSAPP')) {
        foreach (Language::getIDs(false) as $idLang) {
            if (preg_match('#https?://(?:wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com)/[^"\'\s<]+#i', (string) Configuration::get('EVERPSCLICKANDCOLLECT_MSG', $idLang), $m)) {
                Configuration::updateValue('EVERPSCLICKANDCOLLECT_WHATSAPP', $m[0]);
                break;
            }
        }
    }

    return $module->installSlotDefaults() && $module->installPickupTab();
}
