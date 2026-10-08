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

class Everpsclickandcollect extends CarrierModule
{
    private $html;
    private $postErrors = array();
    private $postSuccess = array();
    public $siteUrl;
    public $isSeven;

    public function __construct()
    {
        $this->name = 'everpsclickandcollect';
        $this->tab = 'shipping_logistics';
        $this->version = '3.2.0';
        $this->author = 'Team Ever';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Ever PS Click And Collect');
        $this->description = $this->l('Click and Collect delivery method for Prestashop');
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->siteUrl = Tools::getHttpHost(true).__PS_BASE_URI__;
        $this->isSeven = Tools::version_compare(_PS_VERSION_, '1.7', '>=') ? true : false;
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        // Install SQL
        include(dirname(__FILE__).'/sql/install.php');
        $this->addCarrier();

        return parent::install() &&
            // $this->installModuleTab(
            //     'AdminEverPsClickAndCollect',
            //     'AdminParentStores',
            //     $this->l('Click & collect')
            // ) &&
            $this->registerHook('displayHeader') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('displayCarrierExtraContent') &&
            $this->registerHook('displayOrderConfirmation') &&
            $this->registerHook('displayPDFDeliverySlip') &&
            $this->registerHook('displayPDFInvoice') &&
            $this->registerHook('displayAdminOrderMain') &&
            $this->registerHook('actionValidateStepComplete') &&
            $this->registerHook('actionOrderGridDefinitionModifier') &&
            $this->registerHook('actionOrderGridQueryBuilderModifier') &&
            $this->registerHook('actionEmailSendBefore') &&
            $this->registerHook('actionUpdateQuantity') &&
            $this->registerHook('actionCarrierUpdate') &&
            $this->registerHook('displayAdminProductsQuantitiesStepBottom') &&
            $this->registerHook('actionObjectProductUpdateAfter') &&
            $this->registerHook('displayProductExtraContent') &&
            $this->registerHook('actionObjectProductDeleteAfter') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionValidateOrder') &&
            $this->installSlotDefaults();
    }

    public function installSlotDefaults()
    {
        $defaults = array(
            'EVERPSCLICKANDCOLLECT_SLOT_DURATION' => EverpsclickandcollectSlots::DEFAULT_DURATION,
            'EVERPSCLICKANDCOLLECT_LEAD_TIME' => EverpsclickandcollectSlots::DEFAULT_LEAD_TIME,
            'EVERPSCLICKANDCOLLECT_DAYS_AHEAD' => EverpsclickandcollectSlots::DEFAULT_DAYS_AHEAD,
            'EVERPSCLICKANDCOLLECT_SLOT_MAX' => 0,
            'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT' => 0,
        );
        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        return true;
    }

    public function uninstall()
    {
        // Install SQL
        include(dirname(__FILE__).'/sql/uninstall.php');
        $carrier = new Carrier(
            (int)Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')
        );
        $carrier->delete();
        Configuration::deleteByName('EVERPSCLICKANDCOLLECT_CARRIER_ID');
        foreach (array(
            'EVERPSCLICKANDCOLLECT_ASK_DATE',
            'EVERPSCLICKANDCOLLECT_SLOT_DURATION',
            'EVERPSCLICKANDCOLLECT_LEAD_TIME',
            'EVERPSCLICKANDCOLLECT_DAYS_AHEAD',
            'EVERPSCLICKANDCOLLECT_SLOT_MAX',
            'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT',
            'EVERPSCLICKANDCOLLECT_CLOSED_DATES',
        ) as $key) {
            Configuration::deleteByName($key);
        }
        $this->uninstallModuleTab('AdminEverPsClickAndCollect');
        return parent::uninstall();
    }

    private function installModuleTab($tabClass, $parent, $tabName)
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $tabClass;
        $tab->id_parent = (int)Tab::getIdFromClassName($parent);
        $tab->position = Tab::getNewLastPosition($tab->id_parent);
        $tab->module = $this->name;
        if ($tabClass == 'AdminEverPsBlog' && $this->isSeven) {
            $tab->icon = 'icon-team-ever';
        }

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $tabName;
        }

        return $tab->add();
    }

    private function uninstallModuleTab($tabClass)
    {
        $idTab = (int)Tab::getIdFromClassName($tabClass);
        if (!$idTab) {
            // Tab is not installed by this module anymore
            return true;
        }
        $tab = new Tab($idTab);

        return $tab->delete();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $this->registerHook('actionEmailSendBefore');
        $this->registerHook('actionObjectProductDeleteAfter');
        $cron = $this->context->link->getModuleLink(
            $this->name,
            'cron',
            array(
                'token' => Tools::encrypt($this->name.'/cron')
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
        $helper->default_form_language = $this->context->language->id;
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

        return $helper->generateForm(array($this->getConfigForm()));
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
                        'label' => $this->l('Ask for pickup date and time slots'),
                        'desc' => $this->l('Customer must choose a pickup date and one or more time slots during checkout'),
                        'hint' => $this->l('Slots are built from the store opening hours (Shop parameters > Contact > Stores)'),
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
                        'type' => 'text',
                        'label' => $this->l('Time slot length (minutes)'),
                        'desc' => $this->l('30 = one slot every half hour'),
                        'name' => 'EVERPSCLICKANDCOLLECT_SLOT_DURATION',
                        'class' => 'fixed-width-sm',
                        'suffix' => 'min',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Minimum preparation time (minutes)'),
                        'desc' => $this->l('Slots starting sooner than this after ordering are hidden. 120 = 2 hours'),
                        'name' => 'EVERPSCLICKANDCOLLECT_LEAD_TIME',
                        'class' => 'fixed-width-sm',
                        'suffix' => 'min',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Bookable days ahead'),
                        'desc' => $this->l('How many days in advance customers can book (max 60)'),
                        'name' => 'EVERPSCLICKANDCOLLECT_DAYS_AHEAD',
                        'class' => 'fixed-width-sm',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Maximum orders per time slot'),
                        'desc' => $this->l('0 = unlimited. Full slots cannot be selected anymore'),
                        'name' => 'EVERPSCLICKANDCOLLECT_SLOT_MAX',
                        'class' => 'fixed-width-sm',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Maximum slots a customer can select'),
                        'desc' => $this->l('0 = unlimited'),
                        'name' => 'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT',
                        'class' => 'fixed-width-sm',
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Closed dates (holidays)'),
                        'desc' => $this->l('One date per line, format YYYY-MM-DD, e.g. 2026-12-25'),
                        'name' => 'EVERPSCLICKANDCOLLECT_CLOSED_DATES',
                        'autoload_rte' => false,
                        'rows' => 5,
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
                        'hint' => $this->l('Will be shown before stores list'),
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
            'EVERPSCLICKANDCOLLECT_SLOT_DURATION' => Tools::getValue('EVERPSCLICKANDCOLLECT_SLOT_DURATION', Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_DURATION')),
            'EVERPSCLICKANDCOLLECT_LEAD_TIME' => Tools::getValue('EVERPSCLICKANDCOLLECT_LEAD_TIME', Configuration::get('EVERPSCLICKANDCOLLECT_LEAD_TIME')),
            'EVERPSCLICKANDCOLLECT_DAYS_AHEAD' => Tools::getValue('EVERPSCLICKANDCOLLECT_DAYS_AHEAD', Configuration::get('EVERPSCLICKANDCOLLECT_DAYS_AHEAD')),
            'EVERPSCLICKANDCOLLECT_SLOT_MAX' => Tools::getValue('EVERPSCLICKANDCOLLECT_SLOT_MAX', Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_MAX')),
            'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT' => Tools::getValue('EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT', Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT')),
            'EVERPSCLICKANDCOLLECT_CLOSED_DATES' => Tools::getValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES', Configuration::get('EVERPSCLICKANDCOLLECT_CLOSED_DATES')),
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
        );
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
            $duration = Tools::getValue('EVERPSCLICKANDCOLLECT_SLOT_DURATION');
            if (!Validate::isUnsignedInt($duration) || (int) $duration < 5 || (int) $duration > 240) {
                $this->postErrors[] = $this->l('Error : time slot length must be between 5 and 240 minutes');
            }
            foreach (array(
                'EVERPSCLICKANDCOLLECT_LEAD_TIME' => $this->l('Minimum preparation time'),
                'EVERPSCLICKANDCOLLECT_DAYS_AHEAD' => $this->l('Bookable days ahead'),
                'EVERPSCLICKANDCOLLECT_SLOT_MAX' => $this->l('Maximum orders per time slot'),
                'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT' => $this->l('Maximum slots a customer can select'),
            ) as $key => $label) {
                if (!Validate::isUnsignedInt(Tools::getValue($key))) {
                    $this->postErrors[] = sprintf($this->l('Error : "%s" must be a positive number'), $label);
                }
            }
            foreach (preg_split('/[\s,;]+/', (string) Tools::getValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES')) as $line) {
                $line = trim($line);
                if ($line !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
                    $this->postErrors[] = sprintf($this->l('Error : closed date "%s" must use the YYYY-MM-DD format'), $line);
                }
            }
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
        foreach (array(
            'EVERPSCLICKANDCOLLECT_SLOT_DURATION',
            'EVERPSCLICKANDCOLLECT_LEAD_TIME',
            'EVERPSCLICKANDCOLLECT_DAYS_AHEAD',
            'EVERPSCLICKANDCOLLECT_SLOT_MAX',
            'EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT',
        ) as $key) {
            Configuration::updateValue($key, (int) Tools::getValue($key));
        }
        $posted = array();
        foreach (preg_split('/[\s,;]+/', (string) Tools::getValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES')) as $line) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($line))) {
                $posted[] = trim($line);
            }
        }
        Configuration::updateValue('EVERPSCLICKANDCOLLECT_CLOSED_DATES', implode("\n", array_unique($posted)));
        $this->postSuccess[] = $this->l('All settings have been saved');
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        return 0;
    }

    public function getOrderShippingCostExternal($params)
    {
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

    protected function addCarrier()
    {
        $result = false;
        $carrier = new Carrier();
        $carrier->name = 'Click and collect';
        $carrier->is_module = true;
        $carrier->active = 1;
        $carrier->range_behavior = 1;
        $carrier->need_range = 1;
        $carrier->shipping_external = true;
        $carrier->range_behavior = 0;
        $carrier->external_module_name = $this->name;
        $carrier->shipping_method = 2;

        foreach (Language::getLanguages() as $lang) {
            $carrier->delay[$lang['id_lang']] = $this->l('Pick your order on store');
        }

        if ($carrier->add() == true) {
            // Copy logo img as carrier logo
            @copy(
                dirname(__FILE__).'/views/img/carrier_image.jpg',
                _PS_SHIP_IMG_DIR_.'/'.(int) $carrier->id.'.jpg'
            );
            Configuration::updateValue(
                'EVERPSCLICKANDCOLLECT_CARRIER_ID',
                (int) $carrier->id
            );
            $result &= $this->addZones($carrier);
            $result &= $this->addGroups($carrier);
            $result &= $this->addRanges($carrier);
        }
        return $result;
    }

    protected function addGroups($carrier)
    {
        $groups_ids = array();
        $groups = Group::getGroups(Context::getContext()->language->id);
        foreach ($groups as $group) {
            $groups_ids[] = $group['id_group'];
        }

        $carrier->setGroups($groups_ids);
    }

    protected function addRanges($carrier)
    {
        $range_price = new RangePrice();
        $range_price->id_carrier = $carrier->id;
        $range_price->delimiter1 = '0';
        $range_price->delimiter2 = '10000';
        $range_price->add();

        $range_weight = new RangeWeight();
        $range_weight->id_carrier = $carrier->id;
        $range_weight->delimiter1 = '0';
        $range_weight->delimiter2 = '10000';
        $range_weight->add();
    }

    protected function addZones($carrier)
    {
        $zones = Zone::getZones();

        foreach ($zones as $zone) {
            $carrier->addZone($zone['id_zone']);
        }
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
        if ((int) $params['id_carrier'] == (int)Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')) {
            Configuration::updateValue(
                'EVERPSCLICKANDCOLLECT_CARRIER_ID',
                $params['carrier']->id
            );
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
            if ($askDate) {
                $store['pickup_days'] = EverpsclickandcollectSlots::getAvailableDays(
                    (int) $store['id_store'],
                    $idLang,
                    (int) $cart->id,
                    $this->getDayNames()
                );
            } else {
                $store['pickup_days'] = array();
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
        $selectedDate = '';
        $selectedSlots = array();
        if ($current && (int) $current['id_store'] === $selectedStoreId) {
            $selectedDate = (string) $current['delivery_date'];
            $selectedSlots = EverpsclickandcollectSlots::splitSlots($current['delivery_hour']);
        } else {
            $this->savePickup((int) $cart->id, $selectedStoreId, null, array());
        }
        $this->context->cookie->__set('everclickncollect_id', $selectedStoreId);
        foreach ($shipping_stores as &$store) {
            $store['selected'] = (int) $store['id_store'] === $selectedStoreId;
        }
        unset($store);
        $settings = EverpsclickandcollectSlots::getSettings();
        $this->smarty->assign(
            array(
                'custom_msg' => $custom_msg,
                'ask_date' => $askDate,
                'show_store_img' => Configuration::get('EVERPSCLICKANDCOLLECT_IMG'),
                'only_one' => count($shipping_stores) === 1,
                'ajax_url' => $this->context->link->getModuleLink($this->name, 'ajaxEverShippingStore'),
                'stores' => $shipping_stores,
                'selected_store_id' => $selectedStoreId,
                'selected_date' => $selectedDate,
                'selected_slots' => array_fill_keys($selectedSlots, true),
                'max_selected' => $settings['max_selected'],
                'everclickncollect_id' => Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')
            )
        );
        return $this->display(__FILE__, 'extra_carrier.tpl');
    }

    /**
     * Checkout "Shipping method" step: block "Continue" until a valid date and slots are chosen.
     * Only called by PrestaShop when this module's carrier is selected.
     */
    public function hookActionValidateStepComplete($params)
    {
        if (!isset($params['step_name']) || $params['step_name'] !== 'delivery') {
            return;
        }
        $request = isset($params['request_params']) ? (array) $params['request_params'] : array();
        $cart = $this->context->cart;
        $idLang = (int) $this->context->language->id;
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
            $this->savePickup((int) $cart->id, $idStore, null, array());
            return;
        }
        $date = isset($request['evercnc_date'][$idStore]) ? (string) $request['evercnc_date'][$idStore] : '';
        $slots = isset($request['evercnc_slots'][$idStore]) ? (array) $request['evercnc_slots'][$idStore] : array();
        $slots = EverpsclickandcollectSlots::splitSlots($slots);
        $errors = EverpsclickandcollectSlots::validateSelection($idStore, $idLang, (int) $cart->id, $date, $slots);
        if ($errors) {
            $this->addCheckoutError($this->getSlotErrorMessage($errors[0]));
            $params['completed'] = false;
            return;
        }
        $this->savePickup((int) $cart->id, $idStore, $date, $slots);
    }

    public function getSlotErrorMessage($code)
    {
        switch ($code) {
            case 'no_date':
                return $this->l('Please choose a pickup date.');
            case 'no_slot':
                return $this->l('Please choose at least one pickup time slot.');
            case 'too_many':
                return sprintf(
                    $this->l('You can choose up to %d time slots.'),
                    (int) Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT')
                );
            case 'full_slot':
                return $this->l('Sorry, one of the selected time slots is now full. Please choose another one.');
            default:
                return $this->l('The selected pickup date or time slot is no longer available. Please choose another one.');
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

    public function getCartPickup($idCart)
    {
        if (!(int) $idCart) {
            return false;
        }
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'everpsclickandcollect` WHERE id_cart = ' . (int) $idCart
        );
    }

    public function savePickup($idCart, $idStore, $date, array $slots)
    {
        if (!(int) $idCart) {
            return false;
        }
        return Db::getInstance()->insert(
            'everpsclickandcollect',
            array(
                'id_cart' => (int) $idCart,
                'id_store' => (int) $idStore,
                'delivery_date' => $date ? pSQL($date) : null,
                'delivery_hour' => $slots ? pSQL(implode(',', EverpsclickandcollectSlots::splitSlots($slots))) : null,
            ),
            true,
            true,
            Db::REPLACE
        );
    }

    /**
     * Human readable pickup date and slots for templates, PDF and emails
     */
    public function getPickupLabels($clickncollect, $idLang = null)
    {
        $labels = array('date' => '', 'slots' => '');
        if (!$clickncollect) {
            return $labels;
        }
        $date = (string) $clickncollect['delivery_date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $dayNames = $this->getDayNames();
            $weekday = (int) date('N', strtotime($date)) - 1;
            $language = $idLang ? new Language((int) $idLang) : $this->context->language;
            $format = (Validate::isLoadedObject($language) && $language->date_format_lite)
                ? $language->date_format_lite
                : 'Y-m-d';
            $labels['date'] = $dayNames[$weekday] . ' ' . date($format, strtotime($date));
        } else {
            // Orders made with version < 3.2.0 only stored a week day
            $labels['date'] = $date;
        }
        $labels['slots'] = EverpsclickandcollectSlots::humanizeSlots($clickncollect['delivery_hour']);
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
            || (int) $order->id_carrier != (int)Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')
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
        $labels = $this->getPickupLabels($clickncollect, (int) $order->id_lang);
        $pickup = $store['name'] . ' ' . strip_tags(str_replace('<br />', ', ', $store['address']['formatted']));
        if ($labels['date']) {
            $pickup .= ' - ' . $labels['date'];
            if ($labels['slots']) {
                $pickup .= ' ' . $labels['slots'];
            }
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

    protected function renderPickupInfo($order, $template)
    {
        if (!Validate::isLoadedObject($order)
            || (int) $order->id_carrier != (int)Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')
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
        $labels = $this->getPickupLabels($clickncollect, (int) $order->id_lang);
        $this->context->smarty->assign(array(
            'store' => $store,
            'clickncollect' => $clickncollect,
            'pickup_date' => $labels['date'],
            'pickup_slots' => $labels['slots'],
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

    public function hookDisplayAdminOrderMain($params)
    {
        return $this->renderPickupInfo(new Order((int) $params['id_order']), 'order.tpl');
    }

    public function hookDisplayAdminOrder($params)
    {
        // Kept for shops where the module was installed before 3.2.0
        return $this->hookDisplayAdminOrderMain($params);
    }

    public function hookDisplayPDFDeliverySlip($params)
    {
        return $this->hookDisplayPDFInvoice($params);
    }

    public function hookDisplayPDFInvoice($params)
    {
        return $this->renderPickupInfo(new Order((int) $params['object']->id_order), 'invoice.tpl');
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
                'evercnc.id_cart = o.id_cart AND o.id_carrier = ' . (int) Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID')
            );
            if ($key === 'search_query_builder') {
                $qb->addSelect(
                    'TRIM(CONCAT(IFNULL(evercnc.delivery_date, \'\'), \' \', IFNULL(REPLACE(evercnc.delivery_hour, \',\', \' \'), \'\'))) AS evercnc_pickup'
                );
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
        EverpsclickandcollectStoreStock::dropStoreStock(
            (int) $params['object']->id
        );
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
        if ((int)Configuration::get('EVERPSCLICKANDCOLLECT_CARRIER_ID') != $order->id_carrier) {
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
        $labels = $this->getPickupLabels($this->getCartPickup((int) $order->id_cart), (int) $order->id_lang);
        if ($labels['date']) {
            $table .= '<p><strong>'.$this->l('Pickup').' :</strong> '
                .Tools::safeOutput($labels['date'].' '.$labels['slots']).'</p>';
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
        $table .= Tools::displayPrice($order->total_paid);
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= $carrier->name;
        $table .= '</td>';
        $table .=  '<td '.$tdStyle.'>';
        $table .= Tools::displayPrice($order->total_shipping);
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
