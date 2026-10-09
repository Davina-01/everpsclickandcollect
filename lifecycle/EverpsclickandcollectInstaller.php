<?php
/**
 * Lifecycle of Ever PS Click And Collect: install, repair (= upgrade target), enable, disable,
 * uninstall and the explicit "delete all module data".
 *
 *   install()    after PrestaShop registered the module: repair()
 *   repair()     brings any state (fresh, old version, half installed, failed upgrade) to the current
 *                one. Idempotent, never deletes data. Steps: structure, data migration, settings,
 *                hooks, back office page, carrier.
 *   uninstall()  carrier deactivated, back office pages removed. Tables, settings and carrier KEPT.
 *   purge()      DESTRUCTIVE, explicit action only: tables and settings deleted, carriers soft deleted.
 *   onEnable()   repair() + carrier activated
 *   onDisable()  carrier deactivated when the module is not active in any shop
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EverpsclickandcollectInstaller
{
    const HOOKS = array(
        'displayHeader',
        'displayBackOfficeHeader',
        'displayCarrierExtraContent',
        'displayOrderConfirmation',
        'displayAdminOrderMain',
        'displayPDFDeliverySlip',
        'actionValidateStepComplete',
        'actionOrderGridDefinitionModifier',
        'actionOrderGridQueryBuilderModifier',
        'actionEmailSendBefore',
        'actionUpdateQuantity',
        'actionCarrierUpdate',
        'displayAdminProductsQuantitiesStepBottom',
        'actionObjectProductUpdateAfter',
        'displayProductExtraContent',
        'actionObjectProductDeleteAfter',
        'actionOrderStatusUpdate',
        'actionValidateOrder',
    );

    /** Hooks of older versions that must not stay registered */
    const OBSOLETE_HOOKS = array(
        'displayAdminOrder', // replaced by displayAdminOrderMain (3.2.0)
        'displayPDFInvoice', // pickup information is printed on delivery slips only (3.4.0)
    );

    /** Hidden back office page used to change the pickup time of an order */
    const PICKUP_TAB = 'AdminEverPsClickAndCollectPickup';
    /** Store list page of the original module (registered by PrestaShop) */
    const STORE_TAB = 'AdminEverPsClickAndCollect';

    /** @var Everpsclickandcollect */
    protected $module;

    /** @var string[] */
    protected $errors = array();

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function install()
    {
        return $this->repair(true);
    }

    /**
     * @param bool $installing install (or reinstall): the carrier is created if needed and reactivated
     *
     * @return bool
     */
    public function repair($installing = false)
    {
        $this->errors = array();
        $steps = array(
            'database structure' => function () {
                return EverpsclickandcollectSchema::ensure() && EverpsclickandcollectSchema::syncStoreRows();
            },
            'settings migration' => function () {
                return EverpsclickandcollectMigrator::migrateSettings();
            },
            'default settings' => function () {
                return $this->module->installSlotDefaults();
            },
            'hooks' => function () {
                return $this->registerHooks();
            },
            'back office page' => function () {
                return $this->installTab();
            },
            'carrier' => function () use ($installing) {
                // Never switches on a carrier the merchant switched off: only reactivates it when the
                // module itself had deactivated it (uninstall / disable) or when it was just created.
                $active = $this->isActiveInAnyShop();
                $existed = (bool) EverpsclickandcollectCarrierManager::getCurrent();
                if (!EverpsclickandcollectCarrierManager::ensure($this->module, $active, $installing)) {
                    return false;
                }
                if (!$active) {
                    return EverpsclickandcollectCarrierManager::deactivate();
                }
                return ($installing || !$existed) ? EverpsclickandcollectCarrierManager::reactivate(!$existed) : true;
            },
        );
        foreach ($steps as $name => $step) {
            if (!$this->runStep($name, $step)) {
                return false;
            }
        }
        return true;
    }

    /**
     * repair() for upgrade scripts.
     * PrestaShop 8 / 9 record the new version even when an upgrade function returns false
     * (ModuleManager::upgradeMigration), so the failed steps would never run again.
     * An exception keeps the old version: the upgrade can be run again once the cause is fixed.
     * (Enabling the module also runs repair().)
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function repairForUpgrade($version)
    {
        if (!$this->repair()) {
            throw new PrestaShopException('everpsclickandcollect ' . $version . ' upgrade failed: ' . implode('; ', $this->errors));
        }
        return true;
    }

    /**
     * Keeps tables, settings and the carrier (deactivated) so that a reinstall finds everything back
     *
     * @return bool
     */
    public function uninstall()
    {
        $this->errors = array();
        return $this->runStep('carrier', function () {
            return EverpsclickandcollectCarrierManager::deactivate();
        }) && $this->runStep('back office pages', function () {
            return $this->uninstallTab(self::PICKUP_TAB) && $this->uninstallTab(self::STORE_TAB);
        });
    }

    /**
     * DESTRUCTIVE. Deletes the pickup choices of all orders, the store stock, the store hours and every
     * setting of the module. Carriers are only soft deleted: orders keep their carrier.
     * Never called by install / upgrade / uninstall / reset.
     *
     * @return bool
     */
    public function purge()
    {
        $this->errors = array();
        return $this->runStep('carriers', function () {
            return EverpsclickandcollectCarrierManager::softDeleteAll();
        }) && $this->runStep('tables', function () {
            return EverpsclickandcollectSchema::drop();
        }) && $this->runStep('settings', function () {
            foreach ((array) Db::getInstance()->executeS(
                'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'EVERPSCLICKANDCOLLECT\_%\''
            ) as $row) {
                Configuration::deleteByName($row['name']);
            }
            return true;
        }) && $this->runStep('back office pages', function () {
            return $this->uninstallTab(self::PICKUP_TAB) && $this->uninstallTab(self::STORE_TAB);
        });
    }

    public function onEnable()
    {
        return $this->repair() && $this->runStep('carrier', function () {
            return EverpsclickandcollectCarrierManager::reactivate();
        });
    }

    public function onDisable()
    {
        $this->errors = array();
        if ($this->isActiveInAnyShop()) {
            return true;
        }
        return $this->runStep('carrier', function () {
            return EverpsclickandcollectCarrierManager::deactivate();
        });
    }

    /**
     * @return bool the module is enabled in at least one shop
     */
    public function isActiveInAnyShop()
    {
        if (!$this->module->id) {
            return false;
        }
        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . (int) $this->module->id
        );
    }

    protected function registerHooks()
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->module->isRegisteredInHook($hook) && !$this->module->registerHook($hook)) {
                $this->errors[] = 'hook ' . $hook;
                return false;
            }
        }
        foreach (self::OBSOLETE_HOOKS as $hook) {
            if ($this->module->isRegisteredInHook($hook)) {
                $this->module->unregisterHook($hook);
            }
        }
        return true;
    }

    protected function installTab()
    {
        if (Tab::getIdFromClassName(self::PICKUP_TAB)) {
            return true;
        }
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = self::PICKUP_TAB;
        $tab->id_parent = -1;
        $tab->module = $this->module->name;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Click & collect pickup';
        }
        return (bool) $tab->add();
    }

    protected function uninstallTab($className)
    {
        $idTab = (int) Tab::getIdFromClassName($className);
        if (!$idTab) {
            return true;
        }
        $tab = new Tab($idTab);
        return (bool) $tab->delete();
    }

    protected function runStep($name, callable $step)
    {
        try {
            if ($step()) {
                return true;
            }
            $detail = EverpsclickandcollectSchema::$lastError ?: EverpsclickandcollectCarrierManager::$lastError;
        } catch (Throwable $e) {
            $detail = $e->getMessage();
        }
        $message = 'Click and collect: ' . $name . ' failed' . (!empty($detail) ? ' (' . strip_tags($detail) . ')' : '');
        $this->errors[] = $message;
        PrestaShopLogger::addLog($message, 3, null, 'Module', (int) $this->module->id);
        return false;
    }
}
