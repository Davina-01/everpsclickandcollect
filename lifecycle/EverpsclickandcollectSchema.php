<?php
/**
 * Database structure of Ever PS Click And Collect.
 *
 * Every method is idempotent: it only creates what is missing and never removes or rewrites data.
 * The only destructive method, drop(), is used by the explicit "delete all module data" action.
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EverpsclickandcollectSchema
{
    /** Pickup choice of each cart / order */
    const TABLE_PICKUP = 'everpsclickandcollect';
    /** Stock per store */
    const TABLE_STOCK = 'everpsclickandcollect_store_stock';
    /** Store hours of the original module */
    const TABLE_STORE = 'everpsclickandcollect_store';

    /** @var string last database error */
    public static $lastError = '';

    /**
     * CREATE statements of the current structure (without prefix / engine)
     */
    public static function getTables()
    {
        $engine = ' ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';
        return array(
            self::TABLE_PICKUP => 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_PICKUP . '` (
                `id_cart` int(11) NOT NULL,
                `id_store` int(11) NOT NULL,
                `delivery_date` varchar(255) DEFAULT NULL,
                `delivery_hour` text DEFAULT NULL,
                `pickup_mode` varchar(10) DEFAULT NULL,
                `pickup_periods` text DEFAULT NULL,
                `pickup_prepare` tinyint(1) DEFAULT NULL,
                `pickup_summary` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id_cart`)
            )' . $engine,
            self::TABLE_STOCK => 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_STOCK . '` (
                `id_everpsclickandcollect_store_stock` int(11) NOT NULL AUTO_INCREMENT,
                `id_store` int(11) NOT NULL,
                `id_product` int(11) NOT NULL,
                `id_product_attribute` int(11) NOT NULL,
                `id_shop` int(11) NOT NULL,
                `qty` varchar(255) NOT NULL,
                PRIMARY KEY (`id_everpsclickandcollect_store_stock`)
            )' . $engine,
            self::TABLE_STORE => 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_STORE . '` (
                `id_everpsclickandcollect_store` int(11) NOT NULL AUTO_INCREMENT,
                `id_store` int(11) NOT NULL,
                `monday_open` varchar(255) DEFAULT NULL,
                `monday_close` varchar(255) DEFAULT NULL,
                `tuesday_open` varchar(255) DEFAULT NULL,
                `tuesday_close` varchar(255) DEFAULT NULL,
                `wednesday_open` varchar(255) DEFAULT NULL,
                `wednesday_close` varchar(255) DEFAULT NULL,
                `thursday_open` varchar(255) DEFAULT NULL,
                `thursday_close` varchar(255) DEFAULT NULL,
                `friday_open` varchar(255) DEFAULT NULL,
                `friday_close` varchar(255) DEFAULT NULL,
                `saturday_open` varchar(255) DEFAULT NULL,
                `saturday_close` varchar(255) DEFAULT NULL,
                `sunday_open` varchar(255) DEFAULT NULL,
                `sunday_close` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`id_everpsclickandcollect_store`, `id_store`)
            )' . $engine,
        );
    }

    /**
     * Columns added after the first version of the pickup table (3.4.0)
     */
    public static function getAddedColumns()
    {
        return array(
            'pickup_mode' => 'varchar(10) DEFAULT NULL',
            'pickup_periods' => 'text DEFAULT NULL',
            'pickup_prepare' => 'tinyint(1) DEFAULT NULL',
            'pickup_summary' => 'varchar(255) DEFAULT NULL',
        );
    }

    /**
     * Create missing tables and columns. Never drops or rewrites anything.
     *
     * @return bool
     */
    public static function ensure()
    {
        self::$lastError = '';
        try {
            $db = Db::getInstance();
            foreach (self::getTables() as $sql) {
                if (!$db->execute($sql)) {
                    return self::fail($db->getMsgError());
                }
            }
            $pickup = _DB_PREFIX_ . self::TABLE_PICKUP;
            $existing = self::getColumns($pickup);
            foreach (self::getAddedColumns() as $name => $definition) {
                if (!isset($existing[$name])
                    && !$db->execute('ALTER TABLE `' . $pickup . '` ADD `' . bqSQL($name) . '` ' . $definition)
                ) {
                    return self::fail($db->getMsgError());
                }
            }
            // 3.2.0: several time slots are stored in delivery_hour
            if (isset($existing['delivery_hour']) && stripos($existing['delivery_hour'], 'text') === false
                && !$db->execute('ALTER TABLE `' . $pickup . '` MODIFY `delivery_hour` text DEFAULT NULL')
            ) {
                return self::fail($db->getMsgError());
            }
        } catch (Exception $e) {
            return self::fail($e->getMessage());
        }
        return self::isUpToDate() ? true : self::fail('structure check failed after update');
    }

    /**
     * @return bool all tables and columns exist
     */
    public static function isUpToDate()
    {
        try {
            foreach (array_keys(self::getTables()) as $table) {
                if (!Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL(_DB_PREFIX_ . $table) . '\'')) {
                    return false;
                }
            }
            $existing = self::getColumns(_DB_PREFIX_ . self::TABLE_PICKUP);
            foreach (array_keys(self::getAddedColumns()) as $name) {
                if (!isset($existing[$name])) {
                    return false;
                }
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * One row of store hours per store, only for the stores that do not have one yet
     *
     * @return bool
     */
    public static function syncStoreRows()
    {
        try {
            $db = Db::getInstance();
            $known = array_map('intval', array_column(
                (array) $db->executeS('SELECT `id_store` FROM `' . _DB_PREFIX_ . self::TABLE_STORE . '`'),
                'id_store'
            ));
            foreach (Store::getStores((int) Context::getContext()->language->id) as $store) {
                if (!in_array((int) $store['id_store'], $known, true)
                    && !$db->insert(self::TABLE_STORE, array('id_store' => (int) $store['id_store']))
                ) {
                    return self::fail($db->getMsgError());
                }
            }
        } catch (Exception $e) {
            return self::fail($e->getMessage());
        }
        return true;
    }

    /**
     * DESTRUCTIVE: drops every table of the module. Only for the explicit "delete all module data" action.
     *
     * @return bool
     */
    public static function drop()
    {
        try {
            foreach (array_keys(self::getTables()) as $table) {
                if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . bqSQL($table) . '`')) {
                    return self::fail(Db::getInstance()->getMsgError());
                }
            }
        } catch (Exception $e) {
            return self::fail($e->getMessage());
        }
        return true;
    }

    /**
     * @return array column name => type
     */
    protected static function getColumns($table)
    {
        $columns = array();
        if (!Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL($table) . '\'')) {
            return $columns;
        }
        foreach ((array) Db::getInstance()->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '`') as $row) {
            $columns[$row['Field']] = $row['Type'];
        }
        return $columns;
    }

    protected static function fail($message)
    {
        self::$lastError = (string) $message;
        return false;
    }
}
