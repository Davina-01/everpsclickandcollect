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

class EverpsclickandcollectAjaxEverShippingStoreModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    /**
     * Saves the customer choice while they click (store, date, slots).
     * The final check is done when they press "Continue" (hookActionValidateStepComplete).
     */
    public function displayAjaxSaveShippingStore()
    {
        header('Content-Type: application/json');
        $idStore = (int) Tools::getValue('everclickncollect_id');
        $cart = $this->context->cart;
        if (!$idStore || !$this->module->isAllowedStore($idStore)) {
            $this->ajaxRender(json_encode(array(
                'return' => false,
                'error' => $this->module->l('ID store is not valid', 'ajaxevershippingstore')
            )));
            return;
        }
        if (!Validate::isLoadedObject($cart)) {
            $this->ajaxRender(json_encode(array(
                'return' => false,
                'error' => $this->module->l('Cart not found', 'ajaxevershippingstore')
            )));
            return;
        }
        // Keep only what is already valid: the full check is done when the customer presses "Continue"
        $P = 'EverpsclickandcollectPickup';
        $mode = '';
        $merged = array();
        if ((bool) Configuration::get('EVERPSCLICKANDCOLLECT_ASK_DATE')) {
            $settings = $P::getSettings();
            $mode = (string) Tools::getValue('evercnc_mode');
            if ($mode === $P::MODE_LATER) {
                $valid = array();
                foreach ($P::readPeriods(Tools::getValue('evercnc_periods', array())) as $period) {
                    list($ok, $error) = $P::validatePeriods(array($period), $settings, true);
                    if (!$error) {
                        $valid = array_merge($valid, $ok);
                    }
                }
                $merged = $P::mergePeriods(array_slice($valid, 0, $P::MAX_PERIODS));
            } elseif ($mode !== $P::MODE_NOW || !$P::isNowAvailable($settings)) {
                $mode = '';
            }
        }
        $this->module->savePickupChoice(
            (int) $cart->id,
            $idStore,
            $mode,
            $merged,
            (string) Tools::getValue('evercnc_by')
        );
        $this->context->cookie->__set('everclickncollect_id', $idStore);
        $this->ajaxRender(json_encode(array(
            'return' => true,
        )));
    }
}
