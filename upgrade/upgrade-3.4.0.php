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
 * 3.4.0: "Pick up now" / "Pick up later" with up to 3 time periods,
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

    return $module->installSlotDefaults() && $module->installPickupTab();
}
