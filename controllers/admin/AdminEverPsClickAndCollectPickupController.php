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
 * Saves the pickup time changed by the staff on the back office order page
 * (e.g. the customer asked on WhatsApp to come another day).
 */
class AdminEverPsClickAndCollectPickupController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = new Order($idOrder);
        $back = $this->context->link->getAdminLink(
            'AdminOrders',
            true,
            array('route' => 'admin_orders_view', 'orderId' => $idOrder)
        );
        if (!Validate::isLoadedObject($order) || !Tools::isSubmit('submitEvercncPickup')) {
            Tools::redirectAdmin($back);
        }
        $P = 'EverpsclickandcollectPickup';
        $row = $this->module->getCartPickup((int) $order->id_cart);
        $idStore = $row ? (int) $row['id_store'] : (int) Configuration::get('EVERPSCLICKANDCOLLECT_DEFAULT_STORE');
        $mode = (string) Tools::getValue('evercnc_mode');
        if ($mode === $P::MODE_NOW) {
            $this->module->savePickupChoice((int) $order->id_cart, $idStore, $P::MODE_NOW, array());
        } elseif ($mode === $P::MODE_LATER) {
            // Staff may set any date (no "bookable days" or "past time" restriction)
            list($valid, $error) = $P::validatePeriods(
                $P::readPeriods(Tools::getValue('evercnc_periods', array())),
                $P::getSettings(),
                false
            );
            if (!$error && !$valid) {
                $error = 'no_period';
            }
            if ($error) {
                Tools::redirectAdmin($back . '&evercnc_error=' . urlencode($error) . '#evercnc-pickup');
            }
            $this->module->savePickupChoice((int) $order->id_cart, $idStore, $P::MODE_LATER, $P::mergePeriods($valid));
        }
        Tools::redirectAdmin($back . '&evercnc_saved=1#evercnc-pickup');
    }
}
