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

use PrestaShop\PrestaShop\Core\Product\ProductExtraContent;
require_once _PS_MODULE_DIR_.'everpsclickandcollect/models/EverpsclickandcollectStoreStock.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/models/EverpsclickandcollectStore.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/models/EverpsclickandcollectSlots.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/models/EverpsclickandcollectPickup.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/lifecycle/EverpsclickandcollectSchema.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/lifecycle/EverpsclickandcollectCarrierManager.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/lifecycle/EverpsclickandcollectMigrator.php';
require_once _PS_MODULE_DIR_.'everpsclickandcollect/lifecycle/EverpsclickandcollectInstaller.php';

class Everpsclickandcollect extends CarrierModule
{
    private $html;
    private $postErrors = array();
    private $postSuccess = array();
    private $postWarnings = array();
    public $siteUrl;
    public $isSeven;
    /** @var bool true while PrestaShop's own install / uninstall code runs (it calls enable() / disable()) */
    protected $lifecycleBusy = false;

    public function __construct()
    {
        $this->name = 'everpsclickandcollect';
        $this->tab = 'shipping_logistics';
        $this->version = '3.4.5';
        $this->author = 'Team Ever';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Ever PS Click And Collect');
        $this->description = $this->l('Click and Collect delivery method for Prestashop');
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '9.99.99');
        $this->siteUrl = Tools::getHttpHost(true).__PS_BASE_URI__;
        $this->isSeven = Tools::version_compare(_PS_VERSION_, '1.7', '>=') ? true : false;
    }

    /**
     * Lifecycle (see lifecycle/EverpsclickandcollectInstaller.php):
     * PrestaShop registers the module first, then every step is run (idempotent).
     * If a step fails, the registration is rolled back so the install can simply be retried;
     * tables, settings and carrier (deactivated) are kept and reused by the retry.
     */
    public function install()
    {
        $registered = $this->runCoreLifecycle(function () {
            return parent::install();
        });
        if (!$registered) {
            return false;
        }
        $installer = $this->getInstaller();
        if ($installer->install()) {
            return true;
        }
        $this->_errors = array_merge($this->_errors, $installer->getErrors());
        // Roll back the registration only (hooks, back office pages, carrier deactivated): data is kept
        $this->runCoreLifecycle(function () {
            return parent::uninstall();
        });
        $installer->uninstall();
        return false;
    }

    /**
     * Uninstall keeps the data: pickup choices of orders, store stock, settings and the carrier
     * (deactivated, never deleted). Use "Delete all module data" on the configuration page to remove it.
     */
    public function uninstall()
    {
        $unregistered = $this->runCoreLifecycle(function () {
            return parent::uninstall();
        });
        if (!$unregistered) {
            return false;
        }
        $installer = $this->getInstaller();
        if (!$installer->uninstall()) {
            $this->_errors = array_merge($this->_errors, $installer->getErrors());
        }
        return true;
    }

    public function enable($force_all = false)
    {
        if (!parent::enable($force_all)) {
            return false;
        }
        if ($this->lifecycleBusy) {
            return true;
        }
        // Repairs a half installed module or an upgrade whose migrations did not run
        $installer = $this->getInstaller();
        if ($installer->onEnable()) {
            return true;
        }
        $this->_errors = array_merge($this->_errors, $installer->getErrors());
        parent::disable($force_all);
        $installer->onDisable();
        return false;
    }

    public function disable($force_all = false)
    {
        $result = parent::disable($force_all);
        // A disabled module cannot ask for the store and pickup time: its carrier must not be offered
        $this->getInstaller()->onDisable();
        return $result;
    }

    /**
     * DESTRUCTIVE: uninstalls the module and deletes all its data (pickup choices of all orders,
     * store stock, store hours, settings). Carriers are soft deleted so orders keep their carrier.
     * Only called from the confirmed "Delete all module data" action.
     */
    public function purgeData()
    {
        if (Module::isInstalled($this->name) && !$this->uninstall()) {
            return false;
        }
        $installer = $this->getInstaller();
        if ($installer->purge()) {
            return true;
        }
        $this->_errors = array_merge($this->_errors, $installer->getErrors());
        return false;
    }

    /**
     * Runs PrestaShop's own install / uninstall, which call enable() / disable() themselves
     */
    protected function runCoreLifecycle(callable $call)
    {
        $this->lifecycleBusy = true;
        try {
            return $call();
        } finally {
            $this->lifecycleBusy = false;
        }
    }

    /**
     * @return EverpsclickandcollectInstaller
     */
    public function getInstaller()
    {
        return new EverpsclickandcollectInstaller($this);
    }

    /**
     * Default pickup settings (name kept for the 3.2.0 / 3.3.0 upgrade scripts)
     */
    public function installSlotDefaults()
    {
        foreach (EverpsclickandcollectPickup::$defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        foreach (array(
            'EVERPSCLICKANDCOLLECT_COLOR_MAIN' => '#1b82d6',
            'EVERPSCLICKANDCOLLECT_COLOR_WARNING' => '#e8a33d',
            'EVERPSCLICKANDCOLLECT_COLOR_NOTES' => '#5f6f82',
        ) as $key => $color) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $color);
            }
        }
        if (Configuration::get('EVERPSCLICKANDCOLLECT_SCHEDULE') === false) {
            Configuration::updateValue('EVERPSCLICKANDCOLLECT_SCHEDULE', json_encode(EverpsclickandcollectPickup::$defaultSchedule));
        }
        $defaults = $this->getDefaultTexts();
        foreach ($defaults as $code => $byIso) {
            $key = 'EVERPSCLICKANDCOLLECT_TEXT_' . $code;
            $values = array();
            foreach (Language::getLanguages(false) as $lang) {
                $current = Configuration::get($key, (int) $lang['id_lang']);
                $iso = Tools::strtolower($lang['iso_code']);
                // Keep texts edited by the merchant. A language added later gets a copy of the
                // default language text: replace it when it is still an untouched default.
                // French is the default language: languages without their own default text get the French one
                $default = isset($byIso[$iso]) ? $byIso[$iso] : $byIso['fr'];
                $untouchedOther = $current === $byIso['en'] && $iso !== 'en';
                if ($current !== false && $current !== '' && !$untouchedOther) {
                    $values[(int) $lang['id_lang']] = $current;
                    continue;
                }
                $values[(int) $lang['id_lang']] = $default;
            }
            $changed = false;
            foreach ($values as $idLang => $value) {
                if (Configuration::get($key, (int) $idLang) !== $value) {
                    $changed = true;
                }
            }
            if ($changed) {
                Configuration::updateValue($key, $values);
            }
            if (Configuration::get($key . '_ON') === false) {
                Configuration::updateValue($key . '_ON', 1);
            }
        }
        return true;
    }

    /**
     * Default customer messages, in display order.
     * NOTES: small notes below the pickup time, one per line, the first line is the title.
     * T6: "we may not prepare in advance" warning (time range too wide).
     * Variables: {latest} (latest pickup time of the week), {closing}, {now_limit}, {whatsapp} (WhatsApp link)
     */
    public function getDefaultTexts()
    {
        return array(
            'NOTES' => array(
                'fr' => "Merci de respecter l'horaire choisi.\n"
                    . "L'affluence au magasin varie : nous ne pouvons pas garantir que votre commande sera prête dès votre arrivée.\n"
                    . "Comme nous sommes souvent occupés avec les clients, nous ne pouvons pas toujours répondre au téléphone : pour changer d'horaire, laissez-nous un message sur {whatsapp}.\n"
                    . "Pour un retrait entre {latest} et {closing}, choisissez {latest} et prévenez-nous à l'avance sur {whatsapp} : un collègue restera au magasin pour vous attendre.",
                'en' => "Please keep to the time you chose.\n"
                    . "The number of customers in the shop varies, so we cannot guarantee your order will be ready as soon as you arrive.\n"
                    . "As we are often busy with customers and cannot always answer the phone, please leave us a message on {whatsapp} to change your pickup time.\n"
                    . "To pick up between {latest} and {closing}, choose {latest} and let us know in advance on {whatsapp}: a colleague will stay in the shop for you.",
            ),
            'T6' => array(
                'fr' => 'La plage horaire choisie est large : nous ne préparerons peut-être pas votre commande à l\'avance. Merci de votre compréhension.',
                'en' => 'The time range you chose is wide: we may not prepare your order in advance. Thank you for your understanding.',
            ),
        );
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        if (isset($_POST['submitEvercncPurge'])) {
            if (!$this->context->employee || !$this->context->employee->can('delete', 'AdminModulessf')) {
                // Same permission as uninstalling a module
                $this->postErrors[] = $this->l('You do not have permission to delete the module data.');
            } elseif (empty($_POST['evercnc_purge_confirm']) || !isset($_POST['evercnc_purge_word']) || trim((string) $_POST['evercnc_purge_word']) !== 'DELETE') {
                $this->postErrors[] = $this->l('Nothing was deleted: tick the box and type DELETE to confirm.');
            } elseif ($this->purgeData()) {
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminModulesManage'));
            } else {
                // The module may already be uninstalled: do not rebuild anything, only report
                return $this->displayError(
                    $this->l('The module data could not be deleted completely:') . ' ' . implode(' ; ', $this->getErrors())
                );
            }
        }
        // A failed or interrupted upgrade: bring the structure, settings, hooks and carrier up to date
        if (!EverpsclickandcollectSchema::isUpToDate()) {
            $installer = $this->getInstaller();
            if ($installer->repair()) {
                $this->postSuccess[] = $this->l('The module data structure was out of date and has been repaired.');
            } else {
                $this->postErrors[] = $this->l('The module data structure is out of date and could not be repaired:') . ' ' . implode(' ; ', $installer->getErrors());
            }
        }
        $this->registerHook('actionEmailSendBefore');
        $this->registerHook('actionObjectProductDeleteAfter');
        $cron = $this->context->link->getModuleLink(
            $this->name,
            'cron',
            array(
                'token' => Tools::hash($this->name.'/cron')
            ),
            true,
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );
        if (((bool)Tools::isSubmit('submitEverpsclickandcollectModule')) == true) {
            $this->postValidation();

            if (!count($this->postErrors)) {
                $this->postProcess();
            }
        }
        if (((bool)Tools::isSubmit('submitImportStock')) == true) {
            $this->importStockFromCsv(
                (int) Context::getContext()->shop->id
            );
        }
        if (((bool)Tools::isSubmit('submitExportStock')) == true) {
            $this->exportStoreStockToCsv(
                (int) Context::getContext()->shop->id
            );
        }
        if (count($this->postErrors)) {
            foreach ($this->postErrors as $error) {
                $this->html .= $this->displayError($error);
            }
        }
        foreach ($this->postWarnings as $warning) {
            $this->html .= $this->displayWarning($warning);
        }
        if (count($this->postSuccess)) {
            foreach ($this->postSuccess as $success) {
                $this->html .= $this->displayConfirmation($success);
            }
        }
        $this->context->smarty->assign(array(
            'everpsclickandcollect_dir' => $this->_path,
            'everpsclickandcollect_cron' => $cron,
            'stock_file' => _PS_MODULE_DIR_.'everpsclickandcollect/views/import/store_stock.csv',
        ));

        $this->html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/header.tpl');

        $this->html .= $this->renderForm();
        $this->context->smarty->assign(
            'evercnc_purge_url',
            $this->context->link->getAdminLink('AdminModules', true, array(), array('configure' => $this->name))
        );
        $this->html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/purge.tpl');
        $this->html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/footer.tpl');

        return $this->html;
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        // Multilingual fields open on the shop default language (French)
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitEverpsclickandcollectModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm(), $this->getPickupForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        $stores = $this->getStores(
            (int) Context::getContext()->language->id
        );
        $orderStates = OrderState::getOrderStates(
            (int) Context::getContext()->language->id
        );
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-smile',
                ),
                'input' => array(
                    array(
                        'type' => 'file',
                        'label' => $this->l('Import store stock from CSV file'),
                        'desc' => $this->l('Will update stock for each product and store'),
                        'hint' => $this->l('Please export store stock first'),
                        'name' => 'store_stock_file',
                        'display_image' => false,
                        'required' => false
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Allowed click and collect stores'),
                        'desc' => $this->l('If no stores available, please add at least one'),
                        'hint' => $this->l('Please choose at least one store'),
                        'name' => 'EVERPSCLICKANDCOLLECT_STORES_IDS[]',
                        'class' => 'chosen',
                        'identifier' => 'name',
                        'multiple' => true,
                        'required' => true,
                        'options' => array(
                            'query' => $stores,
                            'id' => 'id_store',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Ask for pickup time'),
                        'desc' => $this->l('Customer chooses "Pick up now" or "Pick up later" with the times they may come. See the "Pickup time" settings below'),
                        'name' => 'EVERPSCLICKANDCOLLECT_ASK_DATE',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Manage stock on each store and product ?'),
                        'desc' => $this->l('Will allow you to manage stock on each store and product'),
                        'hint' => $this->l('Else all store and product will be available for click and collect'),
                        'name' => 'EVERPSCLICKANDCOLLECT_STOCK',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Default decrement stock on this store'),
                        'desc' => $this->l('Store will be decremented on this store by default'),
                        'hint' => $this->l('Please choose at least one store'),
                        'name' => 'EVERPSCLICKANDCOLLECT_DEFAULT_STORE',
                        'identifier' => 'name',
                        'required' => true,
                        'options' => array(
                            'query' => $stores,
                            'id' => 'id_store',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show each store stock on product page ?'),
                        'desc' => $this->l('Will show product store stock as table on product page'),
                        'hint' => $this->l('Else no store stock will be shown'),
                        'name' => 'EVERPSCLICKANDCOLLECT_TAB',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show store image on order tunnel ?'),
                        'desc' => $this->l('Will show each store image on order tunnel'),
                        'hint' => $this->l('Else no store image will be shown'),
                        'name' => 'EVERPSCLICKANDCOLLECT_IMG',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Send order to store by email'),
                        'desc' => $this->l('Will send an email to the store with the order summary'),
                        'hint' => $this->l('Set to "Yes" to send orders by email to the concerned stores'),
                        'name' => 'EVERPSCLICKANDCOLLECT_MAIL',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Validated order state'),
                        'hint' => $this->l('Will be used for order export'),
                        'desc' => $this->l('Specify the validated order state'),
                        'name' => 'EVERPSCLICKANDCOLLECT_VALID_STATES[]',
                        'class' => 'chosen',
                        'multiple' => true,
                        'required' => true,
                        'options' => array(
                            'query' => $orderStates,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'textarea',
                        'lang' => true,
                        'label' => $this->l('Custom message on order tunnel'),
                        'desc' => $this->l('Please add custom order tunnel message'),
                        'hint' => $this->l('Only shown when the pickup time is not asked (above the stores). With the pickup time, use the texts of the Pickup time settings'),
                        'name' => 'EVERPSCLICKANDCOLLECT_MSG',
                        'required' => false,
                        'autoload_rte' => true
                    ),
                ),
                'buttons' => array(
                    'importStock' => array(
                        'name' => 'submitImportStock',
                        'type' => 'submit',
                        'class' => 'btn btn-success pull-right',
                        'icon' => 'process-icon-upload',
                        'title' => $this->l('Import store stock file')
                    ),
                    'exportStoreStock' => array(
                        'name' => 'submitExportStock',
                        'type' => 'submit',
                        'class' => 'btn btn-info pull-right',
                        'icon' => 'process-icon-download',
                        'title' => $this->l('Export store stock to CSV')
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        $msg = array();
        foreach (Language::getLanguages(false) as $lang) {
            $msg[$lang['id_lang']] = (
                Tools::getValue('EVERPSCLICKANDCOLLECT_MSG_'
                    .$lang['id_lang'])
            ) ? Tools::getValue(
                'EVERPSCLICKANDCOLLECT_MSG_'
                .$lang['id_lang']
            ) : '';
        }
        return array(
            'EVERPSCLICKANDCOLLECT_STORES_IDS[]' => json_decode(
                Configuration::get(
                    'EVERPSCLICKANDCOLLECT_STORES_IDS'
                )
            ),
            'EVERPSCLICKANDCOLLECT_DEFAULT_STORE' => Configuration::get(
                'EVERPSCLICKANDCOLLECT_DEFAULT_STORE'
            ),
            'EVERPSCLICKANDCOLLECT_ASK_DATE' => Configuration::get(
                'EVERPSCLICKANDCOLLECT_ASK_DATE'
            ),
            'EVERPSCLICKANDCOLLECT_STOCK' => Configuration::get(
                'EVERPSCLICKANDCOLLECT_STOCK'
            ),
            'EVERPSCLICKANDCOLLECT_TAB' => Configuration::get(
                'EVERPSCLICKANDCOLLECT_TAB'
            ),
            'EVERPSCLICKANDCOLLECT_IMG' => Configuration::get(
                'EVERPSCLICKANDCOLLECT_IMG'
            ),
            'EVERPSCLICKANDCOLLECT_VALID_STATES[]' => Tools::getValue(
                'EVERPSCLICKANDCOLLECT_VALID_STATES',
                json_decode(
                    Configuration::get(
                        'EVERPSCLICKANDCOLLECT_VALID_STATES'
                    )
                )
            ),
            'EVERPSCLICKANDCOLLECT_MAIL' => Configuration::get(
                'EVERPSCLICKANDCOLLECT_MAIL'
            ),
            'EVERPSCLICKANDCOLLECT_MSG' => $this->getConfigInMultipleLangs(
                'EVERPSCLICKANDCOLLECT_MSG'
            ),
        ) + $this->getPickupFormValues();
    }

    public function postValidation()
    {
        if (((bool)Tools::isSubmit('submitEverpsclickandcollectModule')) == true) {
            if (Tools::getValue('EVERPSCLICKANDCOLLECT_ASK_DATE')
                && !Validate::isBool(Tools::getValue('EVERPSCLICKANDCOLLECT_ASK_DATE'))
            ) {
                $this->postErrors[] = $this->l(
                    'Error : The field "Ask for date" is not valid'
                );
            }
            if (!Tools::getValue('EVERPSCLICKANDCOLLECT_STORES_IDS')
                || !Validate::isArrayWithIds(Tools::getValue('EVERPSCLICKANDCOLLECT_STORES_IDS'))
            ) {
                $this->postErrors[] = $this->l(
                    'Error : The field "Stores" is not valid'
                );
            }
            if (Tools::getValue('EVERPSCLICKANDCOLLECT_STOCK')
                && !Validate::isBool(Tools::getValue('EVERPSCLICKANDCOLLECT_STOCK'))
            ) {
                $this->postErrors[] = $this->l(
                    'Error : The field "Manage stock" is not valid'
                );
            }
            if (Tools::getValue('EVERPSCLICKANDCOLLECT_TAB')
                && !Validate::isBool(Tools::getValue('EVERPSCLICKANDCOLLECT_TAB'))
            ) {
                $this->postErrors[] = $this->l(
                    'Error : The field "Show stock on product page" is not valid'
                );
            }
            if (Tools::getValue('EVERPSCLICKANDCOLLECT_IMG')
                && !Validate::isBool(Tools::getValue('EVERPSCLICKANDCOLLECT_IMG'))
            ) {
                $this->postErrors[] = $this->l(
                    'Error : The field "Show stock on product page" is not valid'
                );
            }
            if (Tools::getValue('EVERPSCLICKANDCOLLECT_VALID_STATES')
                && !Validate::isArrayWithIds(Tools::getValue('EVERPSCLICKANDCOLLECT_VALID_STATES'))
            ) {
                $this->postErrors[] = $this->l('Error : [Validate order state] is not valid');
            }
            if (Tools::getValue('EVERPSCLICKANDCOLLECT_MAIL')
                && !Validate::isBool(Tools::getValue('EVERPSCLICKANDCOLLECT_MAIL'))
            ) {
                $this->postErrors[] = $this->l(
                    'Error : The field "Send order to store by email" is not valid'
                );
            }
            $this->validatePickupSettings();
            // Multilingual validation
            foreach (Language::getLanguages(false) as $lang) {
                if (Tools::getValue('EVERPSCLICKANDCOLLECT_MSG_'.$lang['id_lang'])
                    && !Validate::isCleanHtml(Tools::getValue('EVERPSCLICKANDCOLLECT_MSG_'.$lang['id_lang']))
                ) {
                    $this->postErrors[] = $this->l(
                        'Error: message is not valid for lang '
                    ).$lang['iso_code'];
                }
            }
        }
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $msg = array();
        foreach (Language::getLanguages(false) as $lang) {
            $msg[$lang['id_lang']] = (
                Tools::getValue('EVERPSCLICKANDCOLLECT_MSG_'
                    .$lang['id_lang'])
            ) ? Tools::getValue(
                'EVERPSCLICKANDCOLLECT_MSG_'
                .$lang['id_lang']
            ) : '';
        }
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_ASK_DATE',
            Tools::getValue('EVERPSCLICKANDCOLLECT_ASK_DATE')
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_STORES_IDS',
            json_encode(Tools::getValue('EVERPSCLICKANDCOLLECT_STORES_IDS')),
            true
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_STOCK',
            Tools::getValue('EVERPSCLICKANDCOLLECT_STOCK')
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_DEFAULT_STORE',
            Tools::getValue('EVERPSCLICKANDCOLLECT_DEFAULT_STORE')
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_TAB',
            Tools::getValue('EVERPSCLICKANDCOLLECT_TAB')
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_IMG',
            Tools::getValue('EVERPSCLICKANDCOLLECT_IMG')
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_VALID_STATES',
            json_encode(Tools::getValue('EVERPSCLICKANDCOLLECT_VALID_STATES')),
            true
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_MAIL',
            Tools::getValue('EVERPSCLICKANDCOLLECT_MAIL')
        );
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_MSG',
            $msg,
            true
        );
        $this->savePickupSettings();
        $this->postSuccess[] = $this->l('All settings have been saved');
    }

    /**
     * "Pickup time" settings panel
     */
    protected function getPickupForm()
    {
        $yesNo = array(
            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')),
            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('No')),
        );
        $days = array();
        foreach ($this->getDayNames() as $i => $name) {
            $days[] = array('id' => $i + 1, 'name' => $name);
        }
        $steps = array();
        foreach (EverpsclickandcollectPickup::$steps as $step) {
            $steps[] = array('id' => $step, 'name' => $step . ' min');
        }
        $variables = $this->l('Variables:') . ' {latest}, {closing}, {now_limit}, {whatsapp}';
        $inputs = array();
        foreach ($this->getDayNames() as $i => $name) {
            $inputs[] = array(
                'type' => 'text',
                'label' => sprintf($this->l('Pickup hours: %s'), $name),
                'desc' => $i === 0
                    ? $this->l('e.g. 10:30-19:00, or 10:30-14:00, 16:00-19:00. Leave empty: no pickup that day. Independent from the store opening hours')
                    : '',
                'name' => 'EVERPSCLICKANDCOLLECT_SCHEDULE_' . ($i + 1),
                'class' => 'fixed-width-xxl',
            );
        }
        $inputs = array_merge($inputs, array(
            array(
                'type' => 'textarea',
                'label' => $this->l('No pickup on these dates'),
                'desc' => $this->l('One per line. Whole day: 2026-12-25. Part of the day: 2026-12-24 14:00-19:00'),
                'name' => 'EVERPSCLICKANDCOLLECT_CLOSED_DATES',
                'autoload_rte' => false,
                'rows' => 4,
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Closing time'),
                'desc' => $this->l('Only used in message T1 ({closing}), e.g. 19:30. {latest} is the latest pickup time of the week'),
                'name' => 'EVERPSCLICKANDCOLLECT_PICKUP_CLOSING',
                'class' => 'fixed-width-sm',
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Minute step'),
                'name' => 'EVERPSCLICKANDCOLLECT_MINUTE_STEP',
                'options' => array('query' => $steps, 'id' => 'id', 'name' => 'name'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('"Pick up now" time limit'),
                'desc' => $this->l('Customers choosing "Pick up now" should arrive within this many minutes'),
                'name' => 'EVERPSCLICKANDCOLLECT_NOW_LIMIT',
                'class' => 'fixed-width-sm',
                'suffix' => 'min',
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Bookable pickup days'),
                'desc' => $this->l('Customers can choose up to this pickup day, counting from today (days without pickup are not counted)'),
                'name' => 'EVERPSCLICKANDCOLLECT_BOOKABLE_DAYS',
                'class' => 'fixed-width-sm',
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Do not prepare when dates are too far apart'),
                'name' => 'EVERPSCLICKANDCOLLECT_SPAN_ON',
                'is_bool' => true,
                'values' => $yesNo,
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Maximum days between first and last date'),
                'desc' => $this->l('1 = Thursday + Friday is prepared, Thursday + Saturday is not'),
                'name' => 'EVERPSCLICKANDCOLLECT_MAX_SPAN',
                'class' => 'fixed-width-sm',
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Do not prepare when the total time is too long'),
                'name' => 'EVERPSCLICKANDCOLLECT_DURATION_ON',
                'is_bool' => true,
                'values' => $yesNo,
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Maximum total time'),
                'desc' => $this->l('Hours, multiple of 0.5. Total of all periods after merging'),
                'name' => 'EVERPSCLICKANDCOLLECT_MAX_DURATION',
                'class' => 'fixed-width-sm',
                'suffix' => 'h',
            ),
            array(
                'type' => 'text',
                'label' => $this->l('WhatsApp link'),
                'desc' => $this->l('e.g. https://wa.me/33612345678. Shown as a "WhatsApp" link where the texts contain {whatsapp}'),
                'name' => 'EVERPSCLICKANDCOLLECT_WHATSAPP',
                'class' => 'fixed-width-xxl',
            ),
            array(
                'type' => 'color',
                'label' => $this->l('Main color'),
                'desc' => $this->l('Selected choices, buttons and links (default #1b82d6)'),
                'name' => 'EVERPSCLICKANDCOLLECT_COLOR_MAIN',
            ),
            array(
                'type' => 'color',
                'label' => $this->l('Warning box color'),
                'desc' => $this->l('Border of the "we may not prepare in advance" box; its background is a light shade of this color'),
                'name' => 'EVERPSCLICKANDCOLLECT_COLOR_WARNING',
            ),
            array(
                'type' => 'color',
                'label' => $this->l('Notes text color'),
                'desc' => $this->l('Small notes below the pickup time'),
                'name' => 'EVERPSCLICKANDCOLLECT_COLOR_NOTES',
            ),
        ));
        // Texts in the order the customer sees them
        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Show the notes'),
            'desc' => $this->l('Checkout, delivery step, below the pickup time. Shown to every click & collect customer.'),
            'name' => 'EVERPSCLICKANDCOLLECT_TEXT_NOTES_ON',
            'is_bool' => true,
            'values' => $yesNo,
        );
        $inputs[] = array(
            'type' => 'textarea',
            'lang' => true,
            'label' => $this->l('Notes'),
            'desc' => $this->l('One note per line; the first line is the title.') . ' ' . $variables,
            'name' => 'EVERPSCLICKANDCOLLECT_TEXT_NOTES',
            'autoload_rte' => false,
            'rows' => 7,
        );
        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Show the warning'),
            'desc' => $this->l('Checkout, delivery step, box below the time periods. Only for "Pick up later" with a time range that is too wide.'),
            'name' => 'EVERPSCLICKANDCOLLECT_TEXT_T6_ON',
            'is_bool' => true,
            'values' => $yesNo,
        );
        $inputs[] = array(
            'type' => 'textarea',
            'lang' => true,
            'label' => $this->l('Warning'),
            'desc' => $variables,
            'name' => 'EVERPSCLICKANDCOLLECT_TEXT_T6',
            'autoload_rte' => false,
            'rows' => 3,
        );

        return array(
            'form' => array(
                'legend' => array('title' => $this->l('Pickup time'), 'icon' => 'icon-time'),
                'input' => $inputs,
                'submit' => array('title' => $this->l('Save')),
            ),
        );
    }

    protected function getPickupFormValues()
    {
        $values = array();
        foreach (array('EVERPSCLICKANDCOLLECT_WHATSAPP', 'EVERPSCLICKANDCOLLECT_COLOR_MAIN', 'EVERPSCLICKANDCOLLECT_COLOR_WARNING', 'EVERPSCLICKANDCOLLECT_COLOR_NOTES') as $key) {
            $values[$key] = Tools::getValue($key, (string) Configuration::get($key));
        }
        foreach (array_keys(EverpsclickandcollectPickup::$defaults) as $key) {
            $stored = Configuration::get($key);
            $values[$key] = Tools::getValue($key, $stored === false ? EverpsclickandcollectPickup::$defaults[$key] : $stored);
        }
        $settings = EverpsclickandcollectPickup::getSettings();
        for ($day = 1; $day <= 7; ++$day) {
            $field = 'EVERPSCLICKANDCOLLECT_SCHEDULE_' . $day;
            $values[$field] = Tools::getValue($field, EverpsclickandcollectPickup::formatRanges($settings['schedule'][$day]));
        }
        $values['EVERPSCLICKANDCOLLECT_CLOSED_DATES'] = Tools::getValue(
            'EVERPSCLICKANDCOLLECT_CLOSED_DATES',
            Configuration::get('EVERPSCLICKANDCOLLECT_CLOSED_DATES')
        );
        foreach (array_keys($this->getDefaultTexts()) as $code) {
            $key = 'EVERPSCLICKANDCOLLECT_TEXT_' . $code;
            $values[$key . '_ON'] = Tools::getValue($key . '_ON', Configuration::get($key . '_ON'));
            $values[$key] = array();
            foreach (Language::getLanguages(false) as $lang) {
                $values[$key][(int) $lang['id_lang']] = Tools::getValue(
                    $key . '_' . $lang['id_lang'],
                    Configuration::get($key, (int) $lang['id_lang'])
                );
            }
        }
        return $values;
    }

    protected function validatePickupSettings()
    {
        $P = 'EverpsclickandcollectPickup';
        $step = (int) Tools::getValue('EVERPSCLICKANDCOLLECT_MINUTE_STEP');
        if (!in_array($step, $P::$steps)) {
            $this->postErrors[] = $this->l('Error : the minute step is not valid');
            $step = 15;
        }
        $dayNames = $this->getDayNames();
        $latest = null;
        $longestDay = 0;
        $pickupDays = 0;
        for ($day = 1; $day <= 7; ++$day) {
            $ranges = $P::parseRanges((string) Tools::getValue('EVERPSCLICKANDCOLLECT_SCHEDULE_' . $day));
            if ($ranges === null) {
                $this->postErrors[] = sprintf(
                    $this->l('Error : pickup hours of %s are not valid. Use e.g. 10:30-19:00 or 10:30-14:00, 16:00-19:00 (no overlap)'),
                    $dayNames[$day - 1]
                );
                continue;
            }
            $total = 0;
            foreach ($ranges as $r) {
                if (($r[0] % 60) % $step !== 0 || ($r[1] % 60) % $step !== 0) {
                    $this->postErrors[] = sprintf(
                        $this->l('Error : the minutes of the pickup hours of %s must be a multiple of the minute step (%d)'),
                        $dayNames[$day - 1],
                        $step
                    );
                    break;
                }
                $total += $r[1] - $r[0];
                $latest = max((int) $latest, $r[1]);
            }
            $pickupDays += $ranges ? 1 : 0;
            $longestDay = max($longestDay, $total);
        }
        if (!$pickupDays) {
            $this->postErrors[] = $this->l('Error : please set pickup hours for at least one day');
        }
        $closing = $P::toMinutes((string) Tools::getValue('EVERPSCLICKANDCOLLECT_PICKUP_CLOSING'));
        if ($closing === null) {
            $this->postErrors[] = $this->l('Error : the closing time must use the HH:MM format, e.g. 19:30');
        } elseif ($latest !== null && $closing < $latest) {
            $this->postErrors[] = $this->l('Error : the closing time cannot be earlier than the latest pickup time');
        }
        $limit = Tools::getValue('EVERPSCLICKANDCOLLECT_NOW_LIMIT');
        if (!Validate::isUnsignedInt($limit) || (int) $limit < 1) {
            $this->postErrors[] = $this->l('Error : the "Pick up now" time limit must be a positive number of minutes');
        }
        $bookable = Tools::getValue('EVERPSCLICKANDCOLLECT_BOOKABLE_DAYS');
        if (!Validate::isUnsignedInt($bookable) || (int) $bookable < 1 || (int) $bookable > 60) {
            $this->postErrors[] = $this->l('Error : bookable business days must be between 1 and 60');
        }
        if (!Validate::isUnsignedInt(Tools::getValue('EVERPSCLICKANDCOLLECT_MAX_SPAN'))) {
            $this->postErrors[] = $this->l('Error : the maximum days between dates must be 0 or more');
        }
        $duration = str_replace(',', '.', (string) Tools::getValue('EVERPSCLICKANDCOLLECT_MAX_DURATION'));
        if (!is_numeric($duration) || (float) $duration <= 0 || fmod((float) $duration * 2, 1) != 0) {
            $this->postErrors[] = $this->l('Error : the maximum total time must be a multiple of 0.5 hour, e.g. 6 or 4.5');
        } elseif ($longestDay
            && Tools::getValue('EVERPSCLICKANDCOLLECT_DURATION_ON')
            && (float) $duration * 60 > $longestDay
        ) {
            $this->postWarnings[] = $this->l('The maximum total time is longer than one day of pickup times: this condition will not take effect unless several days are chosen.');
        }
        $link = trim((string) Tools::getValue('EVERPSCLICKANDCOLLECT_WHATSAPP'));
        if ($link !== '' && !Validate::isAbsoluteUrl($link)) {
            $this->postErrors[] = $this->l('Error : the WhatsApp link must be a full address, e.g. https://wa.me/33612345678');
        }
        foreach (array('EVERPSCLICKANDCOLLECT_COLOR_MAIN', 'EVERPSCLICKANDCOLLECT_COLOR_WARNING', 'EVERPSCLICKANDCOLLECT_COLOR_NOTES') as $key) {
            $color = trim((string) Tools::getValue($key));
            if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $this->postErrors[] = sprintf($this->l('Error : "%s" is not a valid color, use e.g. #24b9d7'), $color);
            }
        }
        $badLine = null;
        if ($P::parseExceptions((string) Tools::getValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES'), $badLine) === null) {
            $this->postErrors[] = sprintf(
                $this->l('Error : "%s" is not valid. Use 2026-12-25 or 2026-12-24 14:00-19:00'),
                $badLine
            );
        }
        foreach (array_keys($this->getDefaultTexts()) as $code) {
            foreach (Language::getLanguages(false) as $lang) {
                $text = (string) Tools::getValue('EVERPSCLICKANDCOLLECT_TEXT_' . $code . '_' . $lang['id_lang']);
                if ($text !== '' && !Validate::isCleanHtml($text)) {
                    $this->postErrors[] = sprintf($this->l('Error : message %s is not valid for language %s'), $code, $lang['iso_code']);
                }
            }
        }
    }

    protected function savePickupSettings()
    {
        $schedule = array();
        for ($day = 1; $day <= 7; ++$day) {
            $schedule[$day] = EverpsclickandcollectPickup::formatRanges(
                (array) EverpsclickandcollectPickup::parseRanges((string) Tools::getValue('EVERPSCLICKANDCOLLECT_SCHEDULE_' . $day))
            );
        }
        Configuration::updateValue('EVERPSCLICKANDCOLLECT_SCHEDULE', json_encode($schedule));
        foreach (array('EVERPSCLICKANDCOLLECT_WHATSAPP', 'EVERPSCLICKANDCOLLECT_COLOR_MAIN', 'EVERPSCLICKANDCOLLECT_COLOR_WARNING', 'EVERPSCLICKANDCOLLECT_COLOR_NOTES') as $key) {
            Configuration::updateValue($key, trim((string) Tools::getValue($key)));
        }
        Configuration::updateValue('EVERPSCLICKANDCOLLECT_PICKUP_CLOSING', EverpsclickandcollectPickup::toTime(
            EverpsclickandcollectPickup::toMinutes((string) Tools::getValue('EVERPSCLICKANDCOLLECT_PICKUP_CLOSING'))
        ));
        foreach (array(
            'EVERPSCLICKANDCOLLECT_MINUTE_STEP',
            'EVERPSCLICKANDCOLLECT_NOW_LIMIT',
            'EVERPSCLICKANDCOLLECT_BOOKABLE_DAYS',
            'EVERPSCLICKANDCOLLECT_MAX_SPAN',
            'EVERPSCLICKANDCOLLECT_SPAN_ON',
            'EVERPSCLICKANDCOLLECT_DURATION_ON',
        ) as $key) {
            Configuration::updateValue($key, (int) Tools::getValue($key));
        }
        Configuration::updateValue(
            'EVERPSCLICKANDCOLLECT_MAX_DURATION',
            (string) (float) str_replace(',', '.', (string) Tools::getValue('EVERPSCLICKANDCOLLECT_MAX_DURATION'))
        );
        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) Tools::getValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES')) as $line) {
            if (trim($line) !== '') {
                $lines[] = preg_replace('/\s+/', ' ', trim($line));
            }
        }
        Configuration::updateValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES', implode("\n", array_unique($lines)));
        foreach (array_keys($this->getDefaultTexts()) as $code) {
            $key = 'EVERPSCLICKANDCOLLECT_TEXT_' . $code;
            Configuration::updateValue($key . '_ON', (int) Tools::getValue($key . '_ON'));
            $texts = array();
            foreach (Language::getLanguages(false) as $lang) {
                $texts[(int) $lang['id_lang']] = trim((string) Tools::getValue($key . '_' . $lang['id_lang']));
            }
            Configuration::updateValue($key, $texts);
        }
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        // Disabled in this shop (the carrier itself is shared by all shops): not available
        if (!$this->active) {
            return false;
        }
        return 0;
    }

    public function getOrderShippingCostExternal($params)
    {
        if (!$this->active) {
            return false;
        }
        return true;
    }

    public function isAllowedStore($id_store)
    {
        $allowed_stores = $this->getAllowedStores();
        if (in_array($id_store, $allowed_stores)) {
            return true;
        }
        return false;

    }

    private function getAllowedStores()
    {
        $allowed_stores = json_decode(
            Configuration::get(
                'EVERPSCLICKANDCOLLECT_STORES_IDS'
            )
        );
        if (!is_array($allowed_stores)) {
            $allowed_stores = array($allowed_stores);
        }
        return $allowed_stores;
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
        if (Tools::getIsset('id_everpsclickandcollect_store')) {
            $this->context->controller->addJS($this->_path.'views/js/everpsclickandcollect.js');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookDisplayHeader()
    {
        $controller = $this->context->controller;
        if (isset($controller->php_self) && $controller->php_self == 'order') {
            $controller->registerJavascript(
                'module-everpsclickandcollect-order',
                'modules/'.$this->name.'/views/js/order.js',
                array('position' => 'bottom', 'priority' => 200)
            );
        }
        $controller->registerStylesheet(
            'module-everpsclickandcollect-front',
            'modules/'.$this->name.'/views/css/front.css'
        );
    }

    public function hookActionCarrierUpdate($params)
    {
        // The staff edited the carrier: PrestaShop made a copy with a new id. Orders of the old id
        // are still recognised (EverpsclickandcollectCarrierManager::isModuleCarrier()).
        EverpsclickandcollectCarrierManager::clearCache();
        if (EverpsclickandcollectCarrierManager::isModuleCarrier((int) $params['id_carrier'])
            && isset($params['carrier']->id)
            && EverpsclickandcollectCarrierManager::isModuleCarrier((int) $params['carrier']->id)
        ) {
            Configuration::updateGlobalValue('EVERPSCLICKANDCOLLECT_CARRIER_ID', (int) $params['carrier']->id);
        }
        /**
         * Not needed since 1.5
         * You can identify the carrier by the id_reference
        */
    }

    public function hookDisplayCarrierExtraContent($params)
    {
        $cart = Context::getContext()->cart;
        $idLang = (int) Context::getContext()->language->id;
        $stores = $this->getTemplateVarStores();
        $msg = $this->getConfigInMultipleLangs('EVERPSCLICKANDCOLLECT_MSG');
        $custom_msg = isset($msg[$idLang]) ? $msg[$idLang] : '';
        $askDate = (bool) Configuration::get('EVERPSCLICKANDCOLLECT_ASK_DATE');
        $current = $this->getCartPickup((int) $cart->id);
        $selectedStoreId = $current ? (int) $current['id_store'] : 0;
        if (!$selectedStoreId) {
            $selectedStoreId = (int) Context::getContext()->cookie->__get('everclickncollect_id');
        }
        $shipping_stores = array();
        foreach ($stores as $store) {
            if ((bool)$this->isAllowedStore((int) $store['id_store']) === false) {
                continue;
            }
            // Manage stock on each store and product if allowed
            if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_STOCK') === true
                && !(bool)EverpsclickandcollectStoreStock::isCartAvailableForStore(
                    (object)$cart,
                    (int) $store['id_store']
                )
            ) {
                continue;
            }
            $shipping_stores[] = $store;
        }
        if (empty($shipping_stores)) {
            $this->smarty->assign(
                array(
                    'everclickncollect_id' => Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')
                )
            );
            return $this->display(__FILE__, 'no_carrier.tpl');
        }
        // Make sure the selected store is still available, else take the first one
        $storeIds = array_map(function ($s) {
            return (int) $s['id_store'];
        }, $shipping_stores);
        if (!in_array($selectedStoreId, $storeIds)) {
            $selectedStoreId = $storeIds[0];
        }
        if (!$current || (int) $current['id_store'] !== $selectedStoreId) {
            $this->savePickupChoice((int) $cart->id, $selectedStoreId, '', array());
            $current = $this->getCartPickup((int) $cart->id);
        }
        $this->context->cookie->__set('everclickncollect_id', $selectedStoreId);
        foreach ($shipping_stores as &$store) {
            $store['selected'] = (int) $store['id_store'] === $selectedStoreId;
        }
        unset($store);

        $this->smarty->assign(
            array(
                'custom_msg' => $custom_msg,
                'ask_date' => $askDate,
                'show_store_img' => Configuration::get('EVERPSCLICKANDCOLLECT_IMG'),
                'only_one' => count($shipping_stores) === 1,
                'ajax_url' => $this->context->link->getModuleLink($this->name, 'ajaxEverShippingStore'),
                'stores' => $shipping_stores,
                'selected_store_id' => $selectedStoreId,
                'everclickncollect_id' => Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID'),
                'evercnc_style' => $this->getColorStyle(),
            )
        );
        if ($askDate) {
            $this->smarty->assign($this->getPickupTemplateVars($current, $idLang));
        }
        return $this->display(__FILE__, 'extra_carrier.tpl');
    }

    /**
     * Everything the checkout template and order.js need for the "now / later" choice
     */
    protected function getPickupTemplateVars($current, $idLang)
    {
        $P = 'EverpsclickandcollectPickup';
        $settings = $P::getSettings();
        $now = time();
        $dates = array();
        $days = array();
        $minHour = 23;
        $maxHour = 0;
        foreach ($P::getBookableDates($settings, $now) as $date) {
            $ranges = $P::getDayRanges($date, $settings);
            $default = $P::defaultPeriod($date, $settings, $now);
            $dates[] = array('value' => $date, 'label' => $this->getPickupDayLabel($date, $idLang, $now));
            $days[$date] = array('ranges' => $ranges, 'start' => $default['start'], 'end' => $default['end']);
            $minHour = min($minHour, intdiv($ranges[0][0], 60));
            $maxHour = max($maxHour, intdiv($ranges[count($ranges) - 1][1], 60));
        }
        $hours = $dates ? range($minHour, $maxHour) : array();
        $minutes = range(0, 59, $settings['step']);
        $nowAvailable = $P::isNowAvailable($settings, $now);
        $toRow = function ($date, $start, $end) {
            return array(
                'date' => $date,
                'sh' => $start === null ? '' : (string) intdiv($start, 60),
                'sm' => $start === null ? '' : sprintf('%02d', $start % 60),
                'eh' => $end === null ? '' : (string) intdiv($end, 60),
                'em' => $end === null ? '' : sprintf('%02d', $end % 60),
            );
        };

        // Restore what the customer already chose for this cart
        $mode = '';
        $periods = array();
        $chosen = $current && isset($current['pickup_mode']) && in_array($current['pickup_mode'], array($P::MODE_NOW, $P::MODE_LATER));
        if ($chosen) {
            $mode = $current['pickup_mode'];
            if ($mode === $P::MODE_NOW && !$nowAvailable) {
                $mode = '';
            }
            foreach ($P::decodePeriods($current['pickup_periods']) as $p) {
                if (isset($days[$p['date']])) {
                    $periods[] = $toRow($p['date'], $p['start'], $p['end']);
                }
            }
        }
        if (!$periods && $dates) {
            // Today (or the first pickup day) from now to the end of the pickup hours
            $first = $dates[0]['value'];
            $periods[] = $toRow($first, $days[$first]['start'], $days[$first]['end']);
        }

        return array(
            'pickup_mode' => $mode,
            'pickup_periods' => array_slice($periods, 0, $P::MAX_PERIODS),
            'pickup_dates' => $dates,
            'pickup_hours' => $hours,
            'pickup_minutes' => $minutes,
            'pickup_now_available' => $nowAvailable,
            'pickup_now_limit' => $settings['now_limit'],
            'pickup_max_periods' => $P::MAX_PERIODS,
            'pickup_texts' => $this->getPickupTexts($idLang),
            'pickup_js' => json_encode(array(
                'today' => date('Y-m-d', $now),
                'nowMinutes' => $P::nowMinutes($now),
                'step' => $settings['step'],
                'days' => (object) $days,
                'maxPeriods' => $P::MAX_PERIODS,
                'spanOn' => $settings['span_on'],
                'maxSpan' => $settings['max_span'],
                'durationOn' => $settings['duration_on'],
                'maxDuration' => $settings['max_duration'],
            )),
        );
    }

    /**
     * CSS variables for the colors chosen in the settings
     */
    public function getColorStyle()
    {
        $vars = array(
            'EVERPSCLICKANDCOLLECT_COLOR_MAIN' => '--evercnc-primary',
            'EVERPSCLICKANDCOLLECT_COLOR_WARNING' => '--evercnc-warn',
            'EVERPSCLICKANDCOLLECT_COLOR_NOTES' => '--evercnc-notes',
        );
        $style = '';
        foreach ($vars as $key => $var) {
            $color = (string) Configuration::get($key);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $style .= $var . ':' . $color . ';';
            }
        }
        return $style;
    }

    /**
     * Customer texts as safe HTML, variables replaced, '' when switched off
     */
    public function getPickupTexts($idLang)
    {
        $settings = EverpsclickandcollectPickup::getSettings();
        $latest = EverpsclickandcollectPickup::toTime($settings['latest']);
        $closing = EverpsclickandcollectPickup::toTime($settings['closing']);
        $link = trim((string) Configuration::get('EVERPSCLICKANDCOLLECT_WHATSAPP'));
        $whatsapp = 'WhatsApp';
        if ($link !== '' && Validate::isAbsoluteUrl($link)) {
            $whatsapp = '<a href="' . Tools::safeOutput($link) . '" target="_blank" rel="noopener">WhatsApp</a>';
        }
        $vars = array(
            '{latest}' => $latest,
            '{closing}' => $closing,
            '{now_limit}' => (string) $settings['now_limit'],
        );
        $texts = array();
        foreach (array_keys($this->getDefaultTexts()) as $code) {
            $key = 'EVERPSCLICKANDCOLLECT_TEXT_' . $code;
            $text = Configuration::get($key . '_ON') === '0' ? '' : (string) Configuration::get($key, (int) $idLang);
            if ($code === 'NOTES') {
                // One note per line, the first line is the title
                $texts[$code] = array();
                foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
                    if (trim($line) !== '') {
                        $texts[$code][] = str_replace('{whatsapp}', $whatsapp, Tools::safeOutput(strtr(trim($line), $vars)));
                    }
                }
                continue;
            }
            $html = nl2br(Tools::safeOutput(strtr($text, $vars)));
            $texts[$code] = str_replace('{whatsapp}', $whatsapp, $html);
        }
        return $texts;
    }

    /**
     * Checkout date label: "Aujourd'hui" / "Demain", else "ven. 9 oct."
     */
    public function getPickupDayLabel($date, $idLang, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if ($date === date('Y-m-d', $now)) {
            return $this->l('Today');
        }
        if ($date === date('Y-m-d', strtotime('+1 day', strtotime(date('Y-m-d', $now) . ' 12:00:00')))) {
            return $this->l('Tomorrow');
        }
        return $this->formatPickupDate($date, $idLang);
    }

    /**
     * "ven. 9 oct." (fr), "Fri 9 Oct" (en)
     */
    public function formatPickupDate($date, $idLang = null)
    {
        $language = $idLang ? new Language((int) $idLang) : $this->context->language;
        $time = strtotime($date . ' 12:00:00');
        $iso = Validate::isLoadedObject($language) ? Tools::strtolower($language->iso_code) : 'en';
        $locale = (Validate::isLoadedObject($language) && !empty($language->locale)) ? $language->locale : $iso;
        if (class_exists('IntlDateFormatter')) {
            $pattern = 'EEE d MMM';
            $formatter = new IntlDateFormatter(
                str_replace('-', '_', $locale),
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                date_default_timezone_get(),
                IntlDateFormatter::GREGORIAN,
                $pattern
            );
            $formatted = $formatter->format($time);
            if ($formatted !== false) {
                return $formatted;
            }
        }
        $dayNames = $this->getDayNames();
        return $dayNames[(int) date('N', $time) - 1] . ' ' . date('d/m', $time);
    }

    /**
     * Checkout "Shipping method" step: block "Continue" until the pickup choice is valid.
     * Only called by PrestaShop when this module's carrier is selected.
     */
    public function hookActionValidateStepComplete($params)
    {
        if (!isset($params['step_name']) || $params['step_name'] !== 'delivery') {
            return;
        }
        $P = 'EverpsclickandcollectPickup';
        $request = isset($params['request_params']) ? (array) $params['request_params'] : array();
        $cart = $this->context->cart;
        $idStore = isset($request['everpsclickandcollect']) ? (int) $request['everpsclickandcollect'] : 0;
        if (!$idStore) {
            $current = $this->getCartPickup((int) $cart->id);
            $idStore = $current ? (int) $current['id_store'] : 0;
        }
        if (!$idStore || !$this->isAllowedStore($idStore)) {
            $this->addCheckoutError($this->l('Please choose the store where you will pick up your order.'));
            $params['completed'] = false;
            return;
        }
        if (!(bool) Configuration::get('EVERPSCLICKANDCOLLECT_ASK_DATE')) {
            if (!$this->savePickupChoice((int) $cart->id, $idStore, '', array())) {
                $this->addCheckoutError($this->getPeriodErrorMessage('save_failed'));
                $params['completed'] = false;
            }
            return;
        }
        $settings = $P::getSettings();
        $mode = isset($request['evercnc_mode']) ? (string) $request['evercnc_mode'] : '';
        if ($mode === $P::MODE_NOW) {
            if (!$P::isNowAvailable($settings)) {
                $this->addCheckoutError($this->l('"Pick up now" is not available at this time. Please choose "Pick up later".'));
                $params['completed'] = false;
                return;
            }
            if (!$this->savePickupChoice((int) $cart->id, $idStore, $P::MODE_NOW, array())) {
                $this->addCheckoutError($this->getPeriodErrorMessage('save_failed'));
                $params['completed'] = false;
            }
            return;
        }
        if ($mode !== $P::MODE_LATER) {
            $this->addCheckoutError($this->l('Please choose "Pick up now" or "Pick up later".'));
            $params['completed'] = false;
            return;
        }
        $raw = isset($request['evercnc_periods']) ? $request['evercnc_periods'] : array();
        list($valid, $error) = $P::validatePeriods($P::readPeriods($raw), $settings, true);
        if (!$error && !$valid) {
            // An empty time would mean "any time": at least one period is required
            $error = 'no_period';
        }
        if ($error) {
            $this->addCheckoutError($this->getPeriodErrorMessage($error));
            $params['completed'] = false;
            return;
        }
        if (!$this->savePickupChoice((int) $cart->id, $idStore, $P::MODE_LATER, $P::mergePeriods($valid))) {
            $this->addCheckoutError($this->getPeriodErrorMessage('save_failed'));
            $params['completed'] = false;
        }
    }

    public function getPeriodErrorMessage($code)
    {
        switch ($code) {
            case 'too_many':
                return sprintf($this->l('You can add up to %d time periods.'), EverpsclickandcollectPickup::MAX_PERIODS);
            case 'no_period':
                return $this->l('Please choose when you might come.');
            case 'incomplete':
                return $this->l('Please complete the start and end time of each time period, or leave it empty.');
            case 'end_before_start':
                return $this->l('The end time must be later than the start time.');
            case 'past':
                return $this->l('This time has already passed. Please choose a later time.');
            case 'bad_date':
                return $this->l('The selected date is not available. Please choose another date.');
            case 'save_failed':
                return $this->l('Your pickup choice could not be saved. Please try again in a moment or choose another delivery method.');
            default:
                return $this->l('The selected time is not available. Please choose another time.');
        }
    }

    protected function addCheckoutError($message)
    {
        if (isset($this->context->controller) && property_exists($this->context->controller, 'errors')) {
            $this->context->controller->errors[] = $message;
        }
    }

    /**
     * Translated week day names, 0 = Monday
     */
    public function getDayNames()
    {
        return array(
            $this->trans('Monday', [], 'Shop.Theme.Global'),
            $this->trans('Tuesday', [], 'Shop.Theme.Global'),
            $this->trans('Wednesday', [], 'Shop.Theme.Global'),
            $this->trans('Thursday', [], 'Shop.Theme.Global'),
            $this->trans('Friday', [], 'Shop.Theme.Global'),
            $this->trans('Saturday', [], 'Shop.Theme.Global'),
            $this->trans('Sunday', [], 'Shop.Theme.Global'),
        );
    }

    /**
     * Translated names used in "morning / afternoon" mode
     */
    public function getPeriodNames()
    {
        return array(
            'morning' => $this->l('Morning'),
            'afternoon' => $this->l('Afternoon'),
            'evening' => $this->l('Evening'),
            'day' => $this->l('All day'),
        );
    }

    public function getCartPickup($idCart)
    {
        if (!(int) $idCart) {
            return false;
        }
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'everpsclickandcollect` WHERE id_cart = ' . (int) $idCart
        );
    }

    /**
     * Save the pickup choice of a cart.
     *
     * @param string $mode '' (store only), 'now' or 'later'
     * @param array $merged periods from EverpsclickandcollectPickup::mergePeriods()
     */
    public function savePickupChoice($idCart, $idStore, $mode, array $merged)
    {
        if (!(int) $idCart) {
            return false;
        }
        $P = 'EverpsclickandcollectPickup';
        $data = array(
            'id_cart' => (int) $idCart,
            'id_store' => (int) $idStore,
            'delivery_date' => null,
            'delivery_hour' => null,
            'pickup_mode' => null,
            'pickup_periods' => null,
            'pickup_prepare' => null,
            'pickup_summary' => null,
        );
        if (in_array($mode, array($P::MODE_NOW, $P::MODE_LATER), true)) {
            $evaluation = $P::evaluate($mode, $merged, $P::getSettings());
            $dates = array_values(array_unique(array_column($merged, 'date')));
            $data['pickup_mode'] = pSQL($mode);
            $data['pickup_periods'] = pSQL($P::encodePeriods($merged));
            $data['pickup_prepare'] = $evaluation['prepare'] ? 1 : 0;
            $data['pickup_summary'] = pSQL($P::summary($merged));
            // Kept for the back office date filter
            $data['delivery_date'] = $mode === $P::MODE_NOW ? date('Y-m-d') : ($dates ? implode(',', $dates) : null);
            // Same periods in the 3.3.0 format, so that the pickup time is still shown after a downgrade
            if ($mode === $P::MODE_LATER && $merged) {
                $legacy = array();
                foreach ($merged as $period) {
                    $legacy[] = $period['date'] . ' ' . $P::toTime($period['start']) . '-' . $P::toTime($period['end']);
                }
                $data['delivery_hour'] = pSQL(implode(',', $legacy));
            }
        }
        try {
            $saved = (bool) Db::getInstance()->insert('everpsclickandcollect', $data, true, true, Db::REPLACE);
            $error = $saved ? '' : Db::getInstance()->getMsgError();
        } catch (Exception $e) {
            // e.g. files already updated but the upgrade not run yet: never break the checkout page
            $saved = false;
            $error = $e->getMessage();
        }
        if (!$saved) {
            PrestaShopLogger::addLog(
                'Click and collect: pickup choice of cart ' . (int) $idCart . ' not saved: ' . strip_tags((string) $error),
                3,
                null,
                'Cart',
                (int) $idCart
            );
        }
        return $saved;
    }

    /**
     * Translate a string of this module into a given language
     * (e.g. an email sent from the back office in the customer's language).
     */
    public function translateIn($string, $idLang = null)
    {
        static $cache = array();
        $language = $idLang ? new Language((int) $idLang) : $this->context->language;
        if (!Validate::isLoadedObject($language) || $language->id == $this->context->language->id) {
            return $this->l($string);
        }
        $iso = $language->iso_code;
        if (!isset($cache[$iso])) {
            $cache[$iso] = array();
            $file = $this->local_path . 'translations/' . $iso . '.php';
            if (file_exists($file)) {
                global $_MODULE;
                $backup = $_MODULE;
                include $file;
                $cache[$iso] = is_array($_MODULE) ? $_MODULE : array();
                $_MODULE = $backup;
            }
        }
        $key = '<{' . $this->name . '}prestashop>' . $this->name . '_' . md5($string);
        return !empty($cache[$iso][$key]) ? stripslashes($cache[$iso][$key]) : $string;
    }

    /**
     * Pickup information for confirmation page, back office and emails.
     *
     * @param array|false $row everpsclickandcollect row
     * @param int|null $idLang
     * @param string|null $orderDate order date_add, shown for "Pick up now"
     *
     * @return array [
     *   'mode' => 'now'|'later'|'legacy'|'',
     *   'title' => 'Pick up now' / 'Pick up later',
     *   'lines' => ['10月8日 周四 17:00–19:00', ...],
     *   'prepare' => bool,
     *   'text' => customer one-liner, 'admin_text' => "Pick up later · ..." one-liner with the "prepare on arrival" flag
     * ]
     */
    public function getPickupInfo($row, $idLang = null, $orderDate = null)
    {
        $P = 'EverpsclickandcollectPickup';
        $info = array('mode' => '', 'title' => '', 'lines' => array(), 'prepare' => true, 'text' => '', 'admin_text' => '');
        if (!$row) {
            return $info;
        }
        if (!empty($row['pickup_mode']) && in_array($row['pickup_mode'], array($P::MODE_NOW, $P::MODE_LATER))) {
            $info['mode'] = $row['pickup_mode'];
            $info['prepare'] = (bool) $row['pickup_prepare'];
            if ($row['pickup_mode'] === $P::MODE_NOW) {
                $info['title'] = $this->translateIn('Pick up now', $idLang);
                if ($orderDate) {
                    $time = strtotime($orderDate);
                    $info['lines'][] = sprintf(
                        $this->translateIn('Ordered at %s', $idLang),
                        $this->formatPickupDate(date('Y-m-d', $time), $idLang) . ' ' . date('H:i', $time)
                    );
                }
            } else {
                $info['title'] = $this->translateIn('Pick up later', $idLang);
                foreach ($P::decodePeriods($row['pickup_periods']) as $p) {
                    $info['lines'][] = $this->formatPickupDate($p['date'], $idLang) . ' '
                        . $P::toTime($p['start']) . '–' . $P::toTime($p['end']);
                }
                if (!$info['lines']) {
                    $info['lines'][] = $this->translateIn('No time given', $idLang);
                }
            }
            $info['text'] = $info['title'] . ($info['lines'] ? ' : ' . implode(' / ', $info['lines']) : '');
            $info['admin_text'] = $info['title'] . ($info['lines'] ? ' · ' . implode(' / ', $info['lines']) : '')
                . ($info['prepare'] ? '' : ' · ' . $this->translateIn('Prepare on arrival', $idLang));
            return $info;
        }
        // Orders made with 3.2.0 / 3.3.0 (time slots) or older versions (week day)
        $legacy = $this->getPickupLabels($row, $idLang);
        if ($legacy['lines']) {
            $info['mode'] = 'legacy';
            foreach ($legacy['lines'] as $line) {
                $info['lines'][] = trim($line['date'] . ' ' . $line['slots']);
            }
            $info['text'] = $info['admin_text'] = implode(' / ', $info['lines']);
        }
        return $info;
    }

    /**
     * Human readable pickup dates and slots of orders saved by 3.2.0 / 3.3.0
     *
     * @return array ['lines' => [['date' => 'Friday 09/10/2026', 'slots' => '10:00 - 11:00']], 'text' => '...']
     */
    public function getPickupLabels($clickncollect, $idLang = null)
    {
        $labels = array('lines' => array(), 'text' => '');
        if (!$clickncollect) {
            return $labels;
        }
        $entries = EverpsclickandcollectSlots::getRowEntries($clickncollect);
        if (!$entries) {
            // Orders made with version < 3.2.0 only stored a week day
            if ($clickncollect['delivery_date'] && !preg_match('/\d{4}-\d{2}-\d{2}/', $clickncollect['delivery_date'])) {
                $labels['lines'][] = array('date' => (string) $clickncollect['delivery_date'], 'slots' => '');
                $labels['text'] = (string) $clickncollect['delivery_date'];
            }
            return $labels;
        }
        $dayNames = $this->getDayNames();
        $language = $idLang ? new Language((int) $idLang) : $this->context->language;
        $format = (Validate::isLoadedObject($language) && $language->date_format_lite)
            ? $language->date_format_lite
            : 'Y-m-d';
        $parts = array();
        foreach (EverpsclickandcollectSlots::humanizeEntries($entries) as $date => $slots) {
            $weekday = (int) date('N', strtotime($date)) - 1;
            $line = array(
                'date' => $dayNames[$weekday] . ' ' . date($format, strtotime($date)),
                'slots' => $slots,
            );
            $labels['lines'][] = $line;
            $parts[] = $line['date'] . ' ' . $line['slots'];
        }
        $labels['text'] = implode(' ; ', $parts);
        return $labels;
    }

    public function hookDisplayReassurance($params)
    {
        if (!Tools::getValue('id_product')) {
            return;
        }
        $product = new Product(
            (int)Tools::getValue('id_product'),
            false,
            (int) Context::getContext()->language->id,
            (int) Context::getContext()->shop->id
        );
        $stores = $this->getTemplateVarStores();
        $shipping_stores = array();
        foreach ($stores as $key => $store) {
            if ((bool)$this->isAllowedStore((int) $store['id_store']) === false) {
                continue;
            }
            // Manage stock on each store and product if allowed
            if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_STOCK') === true) {
                // store stock depending on combinations
                if ($product->hasCombinations()) {
                    $attr_resumes = $product->getAttributesResume(
                        (int) Context::getContext()->language->id
                    );
                    $store['has_combinations'] = true;
                    foreach ($attr_resumes as $attr_resume) {
                        $product_stock = EverpsclickandcollectStoreStock::getStoreStockAvailableByProductId(
                            (int) $store['id'],
                            (int) $product->id,
                            (int) $attr_resume['id_product_attribute'],
                            (int) Context::getContext()->shop->id
                        );
                        $store['id_product_attribute'] = (int) $attr_resume['id_product_attribute'];
                        $store['attribute_designation'] = (string)$attr_resume['attribute_designation'];
                        $store['qty'] = $product_stock;
                        if ((int) $store['qty'] > 0) {
                            $store_hours = EverpsclickandcollectStore::getByIdStore(
                                (int) $store['id_store']
                            );
                            // Do not merge the hours object: its "id" would overwrite the store id
                            $obj_merged = $store;
                            $shipping_stores[] = $obj_merged;
                        }
                    }
                } else {
                    $product_stock = EverpsclickandcollectStoreStock::getStoreStockAvailableByProductId(
                        (int) $store['id'],
                        (int) $product->id,
                        0,
                        (int) Context::getContext()->shop->id
                    );
                    $store['qty'] = $product_stock;
                    if ((int) $store['qty'] > 0) {
                        $store_hours = EverpsclickandcollectStore::getByIdStore(
                            (int) $store['id_store']
                        );
                        // Do not merge the hours object: its "id" would overwrite the store id
                            $obj_merged = $store;
                        $shipping_stores[] = $obj_merged;
                    }
                }
                if ((int) $store['qty'] <= 0) {
                    continue;
                }
            } else {
                $store_hours = EverpsclickandcollectStore::getByIdStore(
                    (int) $store['id_store']
                );
                // Do not merge the hours object: its "id" would overwrite the store id
                            $obj_merged = $store;
                $shipping_stores[] = $obj_merged;
            }
        }
        $link = new Link();
        $ajax_url = $link->getModuleLink(
            $this->name,
            'ajaxEverShippingStore'
        );
        if (empty($shipping_stores)) {
            return;
        }
        if ($shipping_stores && count($shipping_stores) > 0) {
            $only_one = count($shipping_stores) > 1 ? false : true;
            $this->context->smarty->assign(
                array(
                    'has_combinations' => $product->hasCombinations(),
                    'only_one' => $only_one,
                    'manage_stock' => Configuration::get('EVERPSCLICKANDCOLLECT_STOCK'),
                    'shipping_stores' => $shipping_stores
                )
            );
            return $this->context->smarty->fetch(
                'module:everpsclickandcollect/views/templates/hook/reassurance.tpl'
            );
        }

    }

    public function hookDisplayProductExtraContent($params)
    {
        if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_TAB') === false) {
            return;
        }
        $product = new Product(
            (int) $params['product']->id,
            false,
            (int) Context::getContext()->language->id,
            (int) Context::getContext()->shop->id
        );
        $stores = $this->getTemplateVarStores();
        $shipping_stores = array();
        foreach ($stores as $key => $store) {
            if ((bool)$this->isAllowedStore((int) $store['id_store']) === false) {
                continue;
            }
            // Manage stock on each store and product if allowed
            if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_STOCK') === true) {
                // store stock depending on combinations
                if ($product->hasCombinations()) {
                    $attr_resumes = $product->getAttributesResume(
                        (int) Context::getContext()->language->id
                    );
                    $store['has_combinations'] = true;
                    foreach ($attr_resumes as $attr_resume) {
                        $product_stock = EverpsclickandcollectStoreStock::getStoreStockAvailableByProductId(
                            (int) $store['id'],
                            (int) $product->id,
                            (int) $attr_resume['id_product_attribute'],
                            (int) Context::getContext()->shop->id
                        );
                        $store['id_product_attribute'] = (int) $attr_resume['id_product_attribute'];
                        $store['attribute_designation'] = (string)$attr_resume['attribute_designation'];
                        $store['qty'] = $product_stock;
                        if ((int) $store['qty'] > 0) {
                            $store_hours = EverpsclickandcollectStore::getByIdStore(
                                (int) $store['id_store']
                            );
                            // Do not merge the hours object: its "id" would overwrite the store id
                            $obj_merged = $store;
                            $shipping_stores[] = $obj_merged;
                        }
                    }
                } else {
                    $product_stock = EverpsclickandcollectStoreStock::getStoreStockAvailableByProductId(
                        (int) $store['id'],
                        (int) $product->id,
                        0,
                        (int) Context::getContext()->shop->id
                    );
                    $store['qty'] = $product_stock;
                    if ((int) $store['qty'] > 0) {
                        $store_hours = EverpsclickandcollectStore::getByIdStore(
                            (int) $store['id_store']
                        );
                        // Do not merge the hours object: its "id" would overwrite the store id
                            $obj_merged = $store;
                        $shipping_stores[] = $obj_merged;
                    }
                }
                if ((int) $store['qty'] <= 0) {
                    continue;
                }
            } else {
                $store_hours = EverpsclickandcollectStore::getByIdStore(
                    (int) $store['id_store']
                );
                // Do not merge the hours object: its "id" would overwrite the store id
                            $obj_merged = $store;
                $shipping_stores[] = $obj_merged;
            }
        }
        $link = new Link();
        $ajax_url = $link->getModuleLink(
            $this->name,
            'ajaxEverShippingStore'
        );
        if (empty($shipping_stores)) {
            return;
        }
        if ($shipping_stores && count($shipping_stores) > 0) {
            $only_one = count($shipping_stores) > 1 ? false : true;
            $this->context->smarty->assign(
                array(
                    'has_combinations' => $product->hasCombinations(),
                    'only_one' => $only_one,
                    'manage_stock' => Configuration::get('EVERPSCLICKANDCOLLECT_STOCK'),
                    'shipping_stores' => $shipping_stores
                )
            );
            $content = $this->context->smarty->fetch(
                'module:everpsclickandcollect/views/templates/hook/reassurance.tpl'
            );
            $array = array();
            $array[] = (new PrestaShop\PrestaShop\Core\Product\ProductExtraContent())
                    ->setTitle($this->l('Click and collect'))
                    ->setContent($content);
            return $array;
        }
    }

    public function hookActionEmailSendBefore($params)
    {
        if (!isset($params['templateVars']['{id_order}'])
            || !isset($params['templateVars']['{carrier}'])
        ) {
            return;
        }
        $order = new Order((int) $params['templateVars']['{id_order}']);
        if (!Validate::isLoadedObject($order)
            || !EverpsclickandcollectCarrierManager::isModuleCarrier((int) $order->id_carrier)
        ) {
            return;
        }
        $clickncollect = $this->getCartPickup((int) $order->id_cart);
        if (!$clickncollect) {
            return;
        }
        $store = $this->getTemplateStore((int) $clickncollect['id_store']);
        if (!$store) {
            return;
        }
        $idLang = isset($params['idLang']) ? (int) $params['idLang'] : (int) $order->id_lang;
        $info = $this->getPickupInfo($clickncollect, $idLang, $order->date_add);
        $pickup = $store['name'] . ' ' . strip_tags(str_replace('<br />', ', ', $store['address']['formatted']));
        if ($info['text']) {
            $pickup .= ' - ' . $info['text'];
        }
        $params['templateVars']['{carrier}'] = $params['templateVars']['{carrier}'] . ' : ' . $pickup;
        if (isset($params['templateVars']['{shipping_number}'])) {
            $params['templateVars']['{shipping_number}'] = $params['templateVars']['{shipping_number}'] . ' - ' . $pickup;
        }
    }

    /**
     * Store presented like on the stores page, merged with its hours
     */
    protected function getTemplateStore($idStore)
    {
        foreach ($this->getTemplateVarStores() as $tpl_store) {
            if ((int) $tpl_store['id_store'] == (int) $idStore) {
                return $tpl_store;
            }
        }
        return false;
    }

    protected function renderPickupInfo($order, $template, $forStaff = false)
    {
        if (!Validate::isLoadedObject($order)
            || !EverpsclickandcollectCarrierManager::isModuleCarrier((int) $order->id_carrier)
        ) {
            return;
        }
        $clickncollect = $this->getCartPickup((int) $order->id_cart);
        if (!$clickncollect) {
            return;
        }
        $store = $this->getTemplateStore((int) $clickncollect['id_store']);
        if (!$store) {
            return;
        }
        // Language of the page being displayed
        $info = $this->getPickupInfo($clickncollect, (int) $this->context->language->id, $order->date_add);
        $this->context->smarty->assign(array(
            'store' => $store,
            'clickncollect' => $clickncollect,
            'pickup' => $info,
            'pickup_for_staff' => $forStaff,
        ));
        return $this->display(__FILE__, 'views/templates/hook/' . $template);
    }

    public function hookDisplayOrderConfirmation($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order) {
            return;
        }
        Context::getContext()->cookie->__unset('everclickncollect_id');
        Context::getContext()->cookie->__unset('everclickncollect_date');
        return $this->renderPickupInfo($order, 'order.tpl');
    }

    /**
     * Back office order page: pickup information and form to change it
     */
    public function hookDisplayAdminOrderMain($params)
    {
        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)
            || !EverpsclickandcollectCarrierManager::isModuleCarrier((int) $order->id_carrier)
        ) {
            return;
        }
        $P = 'EverpsclickandcollectPickup';
        $row = $this->getCartPickup((int) $order->id_cart);
        $settings = $P::getSettings();
        $first = 24 * 60;
        foreach ($settings['schedule'] as $ranges) {
            foreach ($ranges as $r) {
                $first = min($first, $r[0]);
            }
        }
        $first = $first === 24 * 60 ? 0 : $first;
        $times = array();
        for ($t = $first; $t <= max($settings['latest'], $first); $t += $settings['step']) {
            $times[] = $P::toTime($t);
        }
        $periods = array();
        if ($row) {
            foreach ($P::decodePeriods($row['pickup_periods']) as $p) {
                $periods[] = array('date' => $p['date'], 'start' => $P::toTime($p['start']), 'end' => $P::toTime($p['end']));
            }
        }
        while (count($periods) < $P::MAX_PERIODS) {
            $periods[] = array('date' => '', 'start' => '', 'end' => '');
        }
        $this->context->smarty->assign(array(
            'pickup_edit_url' => $this->context->link->getAdminLink('AdminEverPsClickAndCollectPickup', true, array(), array('id_order' => (int) $order->id)),
            'pickup_edit_mode' => $row && $row['pickup_mode'] ? $row['pickup_mode'] : '',
            'pickup_edit_periods' => $periods,
            'pickup_times' => $times,
            'pickup_flash' => Tools::getValue('evercnc_saved') ? $this->l('Pickup time updated.') : '',
            'pickup_flash_error' => Tools::getValue('evercnc_error')
                ? $this->getPeriodErrorMessage((string) Tools::getValue('evercnc_error'))
                : '',
        ));
        if (!$row) {
            return;
        }
        return $this->renderPickupInfo($order, 'admin_order.tpl', true);
    }

    public function hookDisplayAdminOrder($params)
    {
        // Kept for shops where the module was installed before 3.2.0
        return $this->hookDisplayAdminOrderMain($params);
    }

    /**
     * Delivery slip (used by the staff to prepare the order): pickup time and "prepare on arrival".
     * Not printed on invoices.
     */
    public function hookDisplayPDFDeliverySlip($params)
    {
        return $this->renderPickupInfo(new Order((int) $params['object']->id_order), 'delivery_slip.tpl', true);
    }

    /**
     * Back office order list: add a "Pickup" column, filterable by date (e.g. 2026-10-09)
     */
    public function hookActionOrderGridDefinitionModifier($params)
    {
        $definition = $params['definition'];
        $columnClass = class_exists('PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn')
            ? 'PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn'
            : 'PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn';
        $column = new $columnClass('evercnc_pickup');
        $column->setName($this->l('Pickup'))
            ->setOptions(array(
                'field' => 'evercnc_pickup',
                'sortable' => false,
            ));
        $definition->getColumns()->addAfter('date_add', $column);
        $filter = new PrestaShop\PrestaShop\Core\Grid\Filter\Filter(
            'evercnc_pickup',
            'Symfony\Component\Form\Extension\Core\Type\TextType'
        );
        $filter->setTypeOptions(array(
            'required' => false,
            'attr' => array('placeholder' => 'YYYY-MM-DD'),
        ))->setAssociatedColumn('evercnc_pickup');
        $definition->getFilters()->add($filter);
    }

    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        $filters = $params['search_criteria']->getFilters();
        foreach (array('search_query_builder', 'count_query_builder') as $key) {
            if (!isset($params[$key])) {
                continue;
            }
            $qb = $params[$key];
            $qb->leftJoin(
                'o',
                _DB_PREFIX_ . 'everpsclickandcollect',
                'evercnc',
                'evercnc.id_cart = o.id_cart AND o.id_carrier IN (' . EverpsclickandcollectCarrierManager::getIdsSql() . ')'
            );
            if ($key === 'search_query_builder') {
                $legacy = 'IF(evercnc.delivery_hour LIKE \'____-__-__ %\', '
                    . 'REPLACE(evercnc.delivery_hour, \',\', \' | \'), '
                    . 'TRIM(CONCAT(IFNULL(evercnc.delivery_date, \'\'), \' \', IFNULL(REPLACE(evercnc.delivery_hour, \',\', \' \'), \'\')))'
                    . ')';
                $qb->addSelect(
                    'CONCAT(CASE evercnc.pickup_mode'
                    . ' WHEN \'now\' THEN :evercnc_now'
                    . ' WHEN \'later\' THEN CONCAT(:evercnc_later, \' · \', IF(IFNULL(evercnc.pickup_summary, \'\') = \'\', :evercnc_none, evercnc.pickup_summary))'
                    . ' ELSE ' . $legacy . ' END,'
                    . ' IF(evercnc.pickup_prepare = 0, CONCAT(\' · \', :evercnc_flag), \'\')) AS evercnc_pickup'
                )
                    ->setParameter('evercnc_now', $this->l('Pick up now'))
                    ->setParameter('evercnc_later', $this->l('Pick up later'))
                    ->setParameter('evercnc_none', $this->l('No time given'))
                    ->setParameter('evercnc_flag', $this->l('Prepare on arrival'));
            }
            if (isset($filters['evercnc_pickup']) && $filters['evercnc_pickup'] !== '') {
                $qb->andWhere('evercnc.delivery_date LIKE :evercnc_pickup')
                    ->setParameter('evercnc_pickup', '%' . $filters['evercnc_pickup'] . '%');
            }
        }
    }

    public function getTemplateVarStores()
    {
        $stores = Store::getStores($this->context->language->id);

        $imageRetriever = new \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever($this->context->link);

        foreach ($stores as &$store) {
            unset($store['active']);
            // Prepare $store.address
            $address = new Address();
            $store['address'] = [];
            $attr = ['address1', 'address2', 'postcode', 'city', 'id_state', 'id_country'];
            foreach ($attr as $a) {
                $address->{$a} = $store[$a];
                $store['address'][$a] = $store[$a];
                unset($store[$a]);
            }
            $store['address']['formatted'] = AddressFormat::generateAddress($address, [], '<br />');

            // Prepare $store.business_hours
            // Required for trad
            $temp = json_decode($store['hours'], true);
            unset($store['hours']);
            $store['business_hours'] = array();
            if (!empty($temp[0][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Monday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[0],
                );
            }
            if (!empty($temp[1][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Tuesday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[1],
                );
            }
            if (!empty($temp[2][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Wednesday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[2],
                );
            }
            if (!empty($temp[3][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Thursday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[3],
                );
            }
            if (!empty($temp[4][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Friday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[4],
                );
            }
            if (!empty($temp[5][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Saturday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[5],
                );
            }
            if (!empty($temp[6][0])) {
                $store['business_hours'][] = array(
                    'day' => $this->trans('Sunday', [], 'Shop.Theme.Global'),
                    'hours' => $temp[6],
                );
            }
            $store['image'] = $imageRetriever->getImage(new Store($store['id_store']), $store['id_store']);
            if (is_array($store['image']) && isset($store['image']['legend']) && is_array($store['image']['legend'])) {
                $store['image']['legend'] = isset($store['image']['legend'][$this->context->language->id])
                    ? $store['image']['legend'][$this->context->language->id]
                    : '';
            }
        }
        unset($store);

        return $stores;
    }

    public function hookDisplayAdminProductsQuantitiesStepBottom($params)
    {
        if (!$params['id_product']) {
            return;
        }
        if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_STOCK') === false) {
            return;
        }
        $product = new Product(
            (int) $params['id_product'],
            false,
            (int) Context::getContext()->language->id,
            (int) Context::getContext()->shop->id
        );
        $shipping_stores = array();
        $stores = $this->getTemplateVarStores();
        foreach ($stores as $store) {
            if ((bool)$this->isAllowedStore((int) $store['id_store']) === false) {
                continue;
            }
            if ($product->hasCombinations()) {
                $attr_resumes = $product->getAttributesResume(
                    (int) Context::getContext()->language->id
                );
                $store['has_combinations'] = true;
                foreach ($attr_resumes as $attr_resume) {
                    $product_stock = EverpsclickandcollectStoreStock::getStoreStockAvailableByProductId(
                        (int) $store['id'],
                        (int) $params['id_product'],
                        (int) $attr_resume['id_product_attribute'],
                        (int) Context::getContext()->shop->id
                    );
                    $store['id_product_attribute'] = (int) $attr_resume['id_product_attribute'];
                    $store['attribute_designation'] = (string)$attr_resume['attribute_designation'];
                    $store['qty'] = $product_stock;
                    $shipping_stores[] = $store;
                }
            } else {
                $product_stock = EverpsclickandcollectStoreStock::getStoreStockAvailableByProductId(
                    (int) $store['id'],
                    (int) $params['id_product'],
                    0,
                    (int) Context::getContext()->shop->id
                );
                $store['qty'] = $product_stock;
            }
            $store['product_name'] = $product->name;
            $store['id_product'] = $params['id_product'];
            
            $shipping_stores[] = $store;
        }
        $this->smarty->assign(array(
            'shipping_stores' => (array)$shipping_stores,
            'default_language' => $this->context->employee->id_lang,
            'id_product' => (int) $params['id_product']
        ));
        return $this->display(__FILE__, 'views/templates/admin/product-tab.tpl');
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_STOCK') === false) {
            return;
        }
        $product = new Product(
            (int)Tools::getValue('id_product'),
            false,
            (int) Context::getContext()->language->id,
            (int) Context::getContext()->shop->id
        );
        $stores = $this->getTemplateVarStores();
        foreach ($stores as $store) {
            if ((bool)$this->isAllowedStore((int) $store['id_store']) === false) {
                continue;
            }
            if ($product->hasCombinations()) {
                $attr_resumes = $product->getAttributesResume(
                    (int) Context::getContext()->language->id
                );
                foreach ($attr_resumes as $attr_resume) {
                    $qty_value = 'everpsclickandcollect_qty_'
                    .(int) $store['id']
                    .(int) $attr_resume['id_product_attribute'];
                    EverpsclickandcollectStoreStock::setQuantity(
                        (int) $store['id'],
                        (int)Tools::getValue('id_product'),
                        (int) $attr_resume['id_product_attribute'],
                        (int)Tools::getValue($qty_value),
                        (int) Context::getContext()->shop->id
                    );
                }
            } else {
                $qty_value = 'everpsclickandcollect_qty_'.(int) $store['id'];
                EverpsclickandcollectStoreStock::setQuantity(
                    (int) $store['id'],
                    (int)Tools::getValue('id_product'),
                    0,
                    (int)Tools::getValue($qty_value),
                    (int) Context::getContext()->shop->id
                );
            }
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if ((bool)Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT') === false
            || (bool)Configuration::get('EVERPSCLICKANDCOLLECT_STOCK') === false
        ) {
            return;
        }
        $controllerTypes = array('front', 'modulefront');
        if (!in_array(Context::getContext()->controller->controller_type, $controllerTypes)) {
            return;
        }
        // If id store exists on customer cookie, let's lower stock
        $evercnc_id_store = Context::getContext()->cookie->__get('everclickncollect_id');
        if (empty($evercnc_id_store)) {
            $evercnc_id_store = (int)Configuration::get('EVERPSCLICKANDCOLLECT_DEFAULT_STORE');
        }
        EverpsclickandcollectStoreStock::setQuantity(
            (int) $evercnc_id_store,
            (int) $params['id_product'],
            (int) $params['id_product_attribute'],
            (int) $params['quantity'],
            (int) Context::getContext()->shop->id
        );
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        EverpsclickandcollectStoreStock::dropProductStock(
            (int) $params['object']->id
        );
    }

    public function hookActionObjectStoreDeleteAfter($params)
    {
        EverpsclickandcollectStoreStock::dropStoreStock(
            (int) $params['object']->id
        );
    }

    public function hookActionAttributeCombinationDelete($params)
    {
        // Only the stock of the deleted combination (hook not registered by default)
        if (!empty($params['id_product_attribute'])) {
            EverpsclickandcollectStoreStock::dropCombinationStock((int) $params['id_product_attribute']);
        }
    }

    public function hookActionOrderStatusUpdate($params)
    {
        if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_MAIL') === false) {
            return;
        }
        return $this->hookActionValidateOrder($params);
    }

    public function hookActionValidateOrder($params)
    {
        if ((bool)Configuration::get('EVERPSCLICKANDCOLLECT_MAIL') === false) {
            return;
        }
        if (isset($params['newOrderStatus'])) {
            $orderStatus = $params['newOrderStatus'];
        } else {
            $orderStatus = $params['orderStatus'];
        }
        if (isset($params['order'])) {
            $order = $params['order'];
        } else {
            $order = new Order((int) $params['id_order']);
        }
        if (!EverpsclickandcollectCarrierManager::isModuleCarrier((int) $order->id_carrier)) {
            return;
        }
        $cart = new Cart(
            (int) $order->id_cart
        );
        $customer = new Customer((int) $order->id_customer);
        $orderLanguage = new Language((int) $order->id_lang);
        $cartproducts = $cart->getProducts();
        $sql = new DbQuery;
        $sql->select('id_store');
        $sql->from(
            'everpsclickandcollect'
        );
        $sql->where(
            'id_cart = '.(int) $order->id_cart
        );
        $id_store = Db::getInstance()->getValue($sql);
        $store = new Store(
            (int) $id_store
        );
        // Change order : set store address as delivery
        if (Validate::isLoadedObject($store)) {
            $this->createStoreAddressForCustomer($store->id, $order->id);
        }
        if (Validate::isLoadedObject($store)
            && Validate::isEmail($store->email)
            && in_array($orderStatus->id, $this->getValidatedOrderStates())
        ) {
            $items = $this->getOrderDatasForEmail($order);
            // Send mail to store with all informations
            $subject = $this->l('An order has been placed on your store');
            $mail_dir = _PS_MODULE_DIR_ . $this->name.'/mails/';
            Mail::send(
                (int) $order->id_lang,
                $this->name,
                (string) $subject,
                array(
                    '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
                    '{shop_logo}' => _PS_IMG_DIR_ . Configuration::get(
                        'PS_LOGO',
                        null,
                        null,
                        (int) $order->id_shop
                    ),
                    '{message}' => $items,
                ),
                (string) $store->email,
                (string) Configuration::get('PS_SHOP_NAME'),
                (string) Configuration::get('PS_SHOP_EMAIL'),
                Configuration::get('PS_SHOP_NAME'),
                null,
                null,
                $mail_dir
            );
        }
    }

    private function exportStoreStockToCsv()
    {
        $objects = EverpsclickandcollectStoreStock::getStoresStocksObjects();
        $csv_datas = array();
        foreach ($objects as $obj) {
            $csv_datas[] = array(
                mb_convert_encoding((string) $obj->id_store, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->id_product, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->id_product_attribute, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->reference, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->product_name, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->attribute_designation, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->store_name, 'ISO-8859-1', 'UTF-8'),
                mb_convert_encoding((string) $obj->qty, 'ISO-8859-1', 'UTF-8'),
            );
        }
        // output headers so that the file is downloaded rather than displayed
        header('Content-type: text/csv');
        header('Content-Disposition: attachment; filename="store_stock.csv"');
         
        // do not cache the file
        header('Pragma: no-cache');
        header('Expires: 0');
         
        // create a file pointer connected to the output stream
        $file = fopen('php://output', 'w');
        // send the column headers
        fputcsv(
            $file,
            array(
                $this->l('ID store *'),
                $this->l('ID product *'),
                $this->l('ID product attribute *'),
                $this->l('Reference'),
                $this->l('Product name'),
                $this->l('Attribute designation'),
                $this->l('Store name'),
                $this->l('Quantity *')
            ),
            ';'
        );
         
        // output each row of the data
        foreach ($csv_datas as $row) {
            fputcsv($file, $row, ';');
        }
        exit();
    }

    private function importStockFromCsv()
    {
        if (isset($_FILES['store_stock_file'])
            && isset($_FILES['store_stock_file']['tmp_name'])
            && !empty($_FILES['store_stock_file']['tmp_name'])
        ) {
            $csvData = array_map('str_getcsv', file($_FILES['store_stock_file']['tmp_name']));
            foreach ($csvData as $key => $line) {
                if ($key == 0) {
                    continue;
                }
                $line_datas = explode(';', $line[0]);
                EverpsclickandcollectStoreStock::importStoreStock(
                    $line_datas,
                    (int) Context::getContext()->shop->id
                );
            }
        }
        $this->postSuccess[] = $this->l('Stores stock has been updated');
        return true;
    }

    private function getValidatedOrderStates()
    {
        $order_states = json_decode(
            Configuration::get(
                'EVERPSCLICKANDCOLLECT_VALID_STATES'
            )
        );
        if (!is_array($order_states)) {
            $order_states = array($order_states);
        }
        return $order_states;
    }

    /**
     * Price in the order currency (Tools::displayPrice() is deprecated since 1.7.6)
     */
    protected function formatOrderPrice($order, $amount)
    {
        $currency = new Currency((int) $order->id_currency);
        $locale = $this->context->getCurrentLocale();
        if ($locale && Validate::isLoadedObject($currency)) {
            return $locale->formatPrice((float) $amount, $currency->iso_code);
        }
        return number_format((float) $amount, 2, ',', ' ');
    }

    protected function getOrderDatasForEmail($order)
    {
        $cart = new Cart(
            (int) $order->id_cart
        );
        $customer = new Customer(
            (int) $order->id_customer
        );
        $address = new Address(
            (int) $order->id_address_delivery
        );
        $carrier = new Carrier(
            (int) $order->id_carrier
        );
        $tdStyle = 'style="padding:0.3rem 1rem 0.3rem 1rem;"';
        $tableStyle = 'style="border-collapse: collapse;width:100%;"';
        $items = '';
        $table = '<h4>'.$order->reference.'</h4>';
        $info = $this->getPickupInfo($this->getCartPickup((int) $order->id_cart), (int) $order->id_lang, $order->date_add);
        if ($info['admin_text']) {
            $table .= '<p><strong>'.$this->l('Pickup').' :</strong> '
                .Tools::safeOutput($info['admin_text']).'</p>';
        }
        // First global datas, as customer
        $table .= '<table '.$tableStyle.'>';
        // Global datas header
        $table .= '<tr style="background-color:#e3e3e3">';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Order reference');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Customer');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Address');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Postcode');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('City');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Phone');
        $table .=  '</td>';
        $table .=  '</tr>';
        // Global datas infos
        $table .= '<tr>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $order->reference;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $customer->firstname.' '.$customer->lastname;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $address->address1.' '.$address->address2;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $address->postcode;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $address->city;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $address->phone;
        $table .= '</td>';
        $table .= '</tr>';
        $table .= '</table>';
        // End global datas
        // Now products
        $table .= '<h4>'.$this->l('Ordered products').'</h4>';
        $table .= '<table '.$tableStyle.'>';
        // Products header
        $table .= '<tr style="background-color:#e3e3e3">';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Product name');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Product reference');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Product quantity');
        $table .=  '</td>';
        $table .=  '</tr>';
        // Products infos on a loop
        foreach ($cart->getProducts() as $product) {
            $table .= '<tr>';
            $table .=  '<td '.$tdStyle.'>';
            $table .= $product['name'];
            $table .= '</td>';
            $table .=  '<td '.$tdStyle.'>';
            $table .= $product['reference'];
            $table .= '</td>';
            $table .=  '<td '.$tdStyle.'>';
            $table .= $product['cart_quantity'];
            $table .= '</td>';
            $table .= '</tr>';
        }
        $table .= '</table>';
        // End products
        // Now others datas
        $table .= '<h4>'.$this->l('Order informations').'</h4>';
        $table .= '<table '.$tableStyle.'>';
        // Others datas header
        $table .= '<tr style="background-color:#e3e3e3">';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Payment method');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Order date add');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Total paid');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Shipping method');
        $table .=  '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->l('Total shipping');
        $table .=  '</td>';
        $table .=  '</tr>';
        // Others datas infos
        $table .= '<tr>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $order->payment;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= Tools::displayDate($order->date_add);
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->formatOrderPrice($order, $order->total_paid);
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $carrier->name;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $this->formatOrderPrice($order, $order->total_shipping);
        $table .= '</td>';
        $table .= '</tr>';
        $table .= '</table>';
        $table .= '<hr>';
        $table .= '<hr>';
        // End other datas
        $items .= $table;
        return $items;
    }

    public function createStoreAddressForCustomer($idStore, $idOrder)
    {
        $store = new Store(
            (int) $idStore
        );
        $order = new Order(
            (int) $idOrder
        );
        $customer = new Customer(
            (int) $order->id_customer
        );
        $deliveryAddress = new Address(
            (int) $order->id_address_delivery
        );
        if (!Validate::isLoadedObject($deliveryAddress)) {
            $deliveryAddress = new Address(
                (int) $order->id_address_invoice
            );
        }
        if (!Validate::isLoadedObject($store)
            || !Validate::isLoadedObject($order)
            || !Validate::isLoadedObject($customer)
        ) {
            return false;
        }
        // Already done (this runs again on every order status change): do not create another address
        if (Validate::isLoadedObject($deliveryAddress)
            && (int) $deliveryAddress->id === (int) $order->id_address_delivery
            && $deliveryAddress->deleted
            && (int) $deliveryAddress->id_customer === (int) $customer->id
            && $deliveryAddress->alias === $store->name[$customer->id_lang]
            && $deliveryAddress->address1 === $store->address1[$customer->id_lang]
            && (string) $deliveryAddress->postcode === (string) $store->postcode
        ) {
            return true;
        }
        try {
            $address = new Address();
            $address->id_customer = $customer->id;
            $address->alias = $store->name[$customer->id_lang];
            $address->firstname = $store->name[$customer->id_lang];
            $address->lastname = $store->name[$customer->id_lang];
            $address->address1 = $store->address1[$customer->id_lang];
            $address->address2 = $store->address2[$customer->id_lang];
            $address->postcode = $store->postcode;
            $address->city = $store->city;
            $address->id_country = $store->id_country;
            $address->id_state = $store->id_state;
            $address->deleted = true;
            $address->phone = $deliveryAddress->phone;
            $address->phone_mobile = $deliveryAddress->phone_mobile;
            $address->save();
            $order->id_address_delivery = $address->id;
            $order->update();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Unable to set store address on order ' . (int) $order->id);
            return false;
        }
    }


    /**
     * Get Stores by language.
     *
     * @param int $idLang
     *
     * @return array
     */
    protected function getStores($idLang)
    {
        return Db::getInstance()->executeS(
            'SELECT s.id_store AS `id`, s.*, sl.*
            FROM ' . _DB_PREFIX_ . 'store s  ' . Shop::addSqlAssociation('store', 's') . '
            LEFT JOIN ' . _DB_PREFIX_ . 'store_lang sl ON (sl.id_store = s.id_store AND sl.id_lang = ' . (int) $idLang . ')'
        );
    }

    protected function getConfigInMultipleLangs($key, $idShopGroup = null, $idShop = null)
    {
        $resultsArray = [];
        foreach (Language::getIDs() as $idLang) {
            $resultsArray[$idLang] = Configuration::get($key, $idLang, $idShopGroup, $idShop);
        }

        return $resultsArray;
    }
}
