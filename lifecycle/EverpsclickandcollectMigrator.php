<?php
/**
 * Data migrations of Ever PS Click And Collect.
 *
 * Converts settings of older versions into the current ones. Each step only acts when old data is
 * present and the new data is not, so running the migrations again changes nothing.
 * Pickup rows of old orders are never rewritten: they are displayed as they were saved.
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EverpsclickandcollectMigrator
{
    /**
     * @return bool
     */
    public static function migrateSettings()
    {
        self::fromSlots();
        self::fromOpenDays();
        self::dropRemovedTexts();
        self::extractWhatsapp();
        self::mergeNotes();
        return true;
    }

    /**
     * 3.2.0 / 3.3.0 time slot settings are not used since 3.4.0
     */
    protected static function fromSlots()
    {
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
    }

    /**
     * 3.3.0 "business days" + earliest / latest time -> 3.4.0 pickup hours per week day
     */
    protected static function fromOpenDays()
    {
        $openDays = json_decode((string) Configuration::get('EVERPSCLICKANDCOLLECT_OPEN_DAYS'), true);
        $earliest = Configuration::get('EVERPSCLICKANDCOLLECT_PICKUP_EARLIEST');
        $latest = Configuration::get('EVERPSCLICKANDCOLLECT_PICKUP_LATEST');
        if (is_array($openDays) && $earliest && $latest && Configuration::get('EVERPSCLICKANDCOLLECT_SCHEDULE') === false) {
            $schedule = array();
            for ($day = 1; $day <= 7; ++$day) {
                $schedule[$day] = in_array($day, $openDays) ? $earliest . '-' . $latest : '';
            }
            Configuration::updateValue('EVERPSCLICKANDCOLLECT_SCHEDULE', json_encode($schedule));
        }
        foreach (array('EVERPSCLICKANDCOLLECT_OPEN_DAYS', 'EVERPSCLICKANDCOLLECT_PICKUP_EARLIEST', 'EVERPSCLICKANDCOLLECT_PICKUP_LATEST') as $key) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * T4 and T5 do not exist since 3.4.0 (times cannot be left empty)
     */
    protected static function dropRemovedTexts()
    {
        foreach (array('T4', 'T5') as $code) {
            Configuration::deleteByName('EVERPSCLICKANDCOLLECT_TEXT_' . $code);
            Configuration::deleteByName('EVERPSCLICKANDCOLLECT_TEXT_' . $code . '_ON');
        }
    }

    /**
     * 3.4.5: the 4 notes NOTE, T2, T3, T1 (one text each) become one text NOTES, one note per line
     * in the same order. A note that was switched off is left out. Runs only while the old texts exist.
     */
    protected static function mergeNotes()
    {
        $old = array('NOTE', 'T2', 'T3', 'T1');
        $names = array();
        foreach ($old as $code) {
            $names[] = "'EVERPSCLICKANDCOLLECT_TEXT_" . $code . "'";
            $names[] = "'EVERPSCLICKANDCOLLECT_TEXT_" . $code . "_ON'";
        }
        if (!Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (' . implode(',', $names) . ')')) {
            return;
        }
        if (Configuration::get('EVERPSCLICKANDCOLLECT_TEXT_NOTES_ON') === false) {
            $values = array();
            $anyOn = false;
            foreach (Language::getIDs(false) as $idLang) {
                $lines = array();
                foreach ($old as $code) {
                    if (Configuration::get('EVERPSCLICKANDCOLLECT_TEXT_' . $code . '_ON') === '0') {
                        continue;
                    }
                    $text = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', (string) Configuration::get('EVERPSCLICKANDCOLLECT_TEXT_' . $code, (int) $idLang)));
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
                $values[(int) $idLang] = implode("\n", $lines);
            }
            foreach ($old as $code) {
                $anyOn = $anyOn || Configuration::get('EVERPSCLICKANDCOLLECT_TEXT_' . $code . '_ON') !== '0';
            }
            Configuration::updateValue('EVERPSCLICKANDCOLLECT_TEXT_NOTES', $values);
            Configuration::updateValue('EVERPSCLICKANDCOLLECT_TEXT_NOTES_ON', $anyOn ? 1 : 0);
        }
        foreach ($old as $code) {
            Configuration::deleteByName('EVERPSCLICKANDCOLLECT_TEXT_' . $code);
            Configuration::deleteByName('EVERPSCLICKANDCOLLECT_TEXT_' . $code . '_ON');
        }
    }

    /**
     * Reuse the WhatsApp link of the old custom checkout message, if any
     */
    protected static function extractWhatsapp()
    {
        // Only once: a link the merchant removed later stays removed
        if (Configuration::get('EVERPSCLICKANDCOLLECT_WHATSAPP') !== false) {
            return;
        }
        foreach (Language::getIDs(false) as $idLang) {
            if (preg_match('#https?://(?:wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com)/[^"\'\s<]+#i', (string) Configuration::get('EVERPSCLICKANDCOLLECT_MSG', $idLang), $m)) {
                Configuration::updateValue('EVERPSCLICKANDCOLLECT_WHATSAPP', $m[0]);
                return;
            }
        }
    }
}
