<?php
/**
 * Click & collect carrier of Ever PS Click And Collect.
 *
 * A carrier that orders refer to is NEVER physically deleted:
 *  - uninstall / disable  -> the carrier is deactivated (active = 0)
 *  - install / enable     -> the existing carrier is reused and reactivated, a new one is created only if none exists
 *  - "delete all data"    -> soft delete (deleted = 1), like the back office does
 * When the staff edits the carrier, PrestaShop creates a copy with a new id: every carrier whose
 * external_module_name is this module (current or older copies) is recognised as a click & collect carrier.
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EverpsclickandcollectCarrierManager
{
    const CONFIG_KEY = 'EVERPSCLICKANDCOLLECT_CARRIER_ID';
    /** Set when the module (not the merchant) deactivated the carrier: only then does enabling reactivate it */
    const OFF_BY_MODULE_KEY = 'EVERPSCLICKANDCOLLECT_CARRIER_OFF_BY_MODULE';
    const MODULE_NAME = 'everpsclickandcollect';

    /** @var array|null cache of getIds() */
    protected static $ids = null;

    /** @var string last error */
    public static $lastError = '';

    /**
     * Every carrier of this module, including deleted (edited) copies still referenced by old orders
     *
     * @return int[]
     */
    public static function getIds()
    {
        if (self::$ids === null) {
            self::$ids = array_map('intval', array_column((array) Db::getInstance()->executeS(
                'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
                WHERE `external_module_name` = \'' . pSQL(self::MODULE_NAME) . '\''
            ), 'id_carrier'));
        }
        return self::$ids;
    }

    public static function clearCache()
    {
        self::$ids = null;
    }

    /**
     * @return bool the carrier (current or an older copy) belongs to this module
     */
    public static function isModuleCarrier($idCarrier)
    {
        return (int) $idCarrier > 0 && in_array((int) $idCarrier, self::getIds(), true);
    }

    /**
     * SQL list for "id_carrier IN (...)"
     */
    public static function getIdsSql()
    {
        $ids = self::getIds();
        return $ids ? implode(',', $ids) : '0';
    }

    /**
     * Current (not deleted) carrier of the module, or null.
     * Uses the configured id when it is valid, else the most recent carrier of the module.
     *
     * @return Carrier|null
     */
    public static function getCurrent()
    {
        $id = (int) Configuration::getGlobalValue(self::CONFIG_KEY);
        if ($id) {
            $carrier = new Carrier($id);
            if (Validate::isLoadedObject($carrier) && !$carrier->deleted
                && $carrier->external_module_name === self::MODULE_NAME
            ) {
                return $carrier;
            }
        }
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE `external_module_name` = \'' . pSQL(self::MODULE_NAME) . '\' AND `deleted` = 0
            ORDER BY `id_carrier` DESC'
        );
        return $id ? new Carrier($id) : null;
    }

    /**
     * Reuse the existing carrier or create one. Idempotent.
     * When the merchant deleted every carrier of the module, a new one is only created on install.
     *
     * @param Module $module
     * @param bool $active state of a newly created carrier
     * @param bool $installing
     *
     * @return bool
     */
    public static function ensure($module, $active, $installing = false)
    {
        self::$lastError = '';
        try {
            $carrier = self::getCurrent();
            if (!$carrier && !$installing && self::getIds()) {
                // Deleted by the merchant in Shipping > Carriers: respected until the next install
                return true;
            }
            if (!$carrier) {
                $carrier = self::create($module, $active);
                if (!$carrier) {
                    return false;
                }
            }
            if ((int) Configuration::getGlobalValue(self::CONFIG_KEY) !== (int) $carrier->id) {
                Configuration::updateGlobalValue(self::CONFIG_KEY, (int) $carrier->id);
            }
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
        self::clearCache();
        return true;
    }

    /**
     * Deactivate every (not deleted) carrier of the module, e.g. duplicates created by older versions.
     * Never deletes them. Remembers that the module did it, so that enabling the module reactivates
     * the carrier but a carrier switched off by the merchant stays off.
     *
     * @return bool
     */
    public static function deactivate()
    {
        try {
            $db = Db::getInstance();
            $where = '`external_module_name` = \'' . pSQL(self::MODULE_NAME) . '\' AND `deleted` = 0';
            if (!$db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier` WHERE ' . $where . ' AND `active` = 1')) {
                return true;
            }
            // Direct update: Carrier::update() would also rewrite the carrier's other fields
            if (!$db->update('carrier', array('active' => 0), $where)) {
                return false;
            }
            Configuration::updateGlobalValue(self::OFF_BY_MODULE_KEY, 1);
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
        return true;
    }

    /**
     * Reactivate the current carrier if the module deactivated it (install / enable).
     *
     * @param bool $force true for a carrier that was just created
     *
     * @return bool
     */
    public static function reactivate($force = false)
    {
        try {
            if (!$force && !Configuration::getGlobalValue(self::OFF_BY_MODULE_KEY)) {
                return true;
            }
            $carrier = self::getCurrent();
            if ($carrier && !$carrier->active && !Db::getInstance()->update(
                'carrier',
                array('active' => 1),
                '`id_carrier` = ' . (int) $carrier->id
            )) {
                return false;
            }
            Configuration::updateGlobalValue(self::OFF_BY_MODULE_KEY, 0);
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
        return true;
    }

    /**
     * Soft delete every carrier of the module (deleted = 1, active = 0), like the back office.
     * Rows stay so that orders keep their carrier. Only for "delete all module data".
     *
     * @return bool
     */
    public static function softDeleteAll()
    {
        try {
            $ok = Db::getInstance()->update(
                'carrier',
                array('deleted' => 1, 'active' => 0),
                '`external_module_name` = \'' . pSQL(self::MODULE_NAME) . '\''
            );
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
        self::clearCache();
        return (bool) $ok;
    }

    /**
     * @return Carrier|false
     */
    protected static function create($module, $active)
    {
        $carrier = new Carrier();
        $carrier->name = 'Click and collect';
        $carrier->is_module = true;
        $carrier->active = $active ? 1 : 0;
        $carrier->need_range = 1;
        $carrier->shipping_external = true;
        $carrier->range_behavior = 0;
        $carrier->external_module_name = self::MODULE_NAME;
        $carrier->shipping_method = 2;
        foreach (Language::getLanguages(false) as $lang) {
            $carrier->delay[(int) $lang['id_lang']] = $module->l('Pick your order on store');
        }
        if (!$carrier->add()) {
            self::$lastError = 'Unable to create the carrier';
            return false;
        }
        @copy(
            _PS_MODULE_DIR_ . self::MODULE_NAME . '/views/img/carrier_image.jpg',
            _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg'
        );
        foreach (Zone::getZones() as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }
        $groups = array();
        foreach (Group::getGroups((int) Context::getContext()->language->id) as $group) {
            $groups[] = (int) $group['id_group'];
        }
        $carrier->setGroups($groups);
        $rangePrice = new RangePrice();
        $rangePrice->id_carrier = $carrier->id;
        $rangePrice->delimiter1 = '0';
        $rangePrice->delimiter2 = '10000';
        $rangePrice->add();
        $rangeWeight = new RangeWeight();
        $rangeWeight->id_carrier = $carrier->id;
        $rangeWeight->delimiter1 = '0';
        $rangeWeight->delimiter2 = '10000';
        $rangeWeight->add();
        Configuration::updateGlobalValue(self::CONFIG_KEY, (int) $carrier->id);
        self::clearCache();
        return $carrier;
    }
}
