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
 * 3.4.5: the 4 notes (NOTE, T2, T3, T1) become one text "Notes" with one switch, one note per line.
 * Done by the installer's repair() (EverpsclickandcollectMigrator::mergeNotes()), idempotent.
 *
 * @param Everpsclickandcollect $module
 */
function upgrade_module_3_4_5($module)
{
    return $module->getInstaller()->repairForUpgrade('3.4.5');
}
