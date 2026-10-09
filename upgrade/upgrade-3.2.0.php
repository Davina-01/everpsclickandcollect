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
 * 3.2.0: pickup time slots + PrestaShop 8.2 fixes
 *
 * @param Everpsclickandcollect $module
 */
function upgrade_module_3_2_0($module)
{
    // Several 30 minute slots can be stored per order
    Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'everpsclickandcollect`
        MODIFY `delivery_hour` text DEFAULT NULL'
    );
    $module->unregisterHook('displayAdminOrder');
    // The order list column (actionOrderGrid* hooks) was removed in 3.4.7: not registered any more.
    // PrestaShop 9 refuses to register a hook whose method does not exist.

    return $module->registerHook('displayAdminOrderMain')
        && $module->registerHook('actionValidateStepComplete')
        && $module->installSlotDefaults();
}
