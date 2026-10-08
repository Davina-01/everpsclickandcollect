<?php
/**
 * Pickup time slots for Ever PS Click And Collect
 *
 * Builds bookable pickup slots (e.g. every 30 minutes) from the PrestaShop
 * store opening hours, validates customer selections and counts bookings.
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EverpsclickandcollectSlots
{
    const DEFAULT_DURATION = 30;
    const DEFAULT_LEAD_TIME = 60;
    const DEFAULT_DAYS_AHEAD = 7;

    /**
     * Parse PrestaShop store opening hours for one day into minute ranges.
     * Store hours are free text, so several formats are accepted:
     * "09:00 - 19:00", "9h-12h / 14h-18h30", "09:00-12:00, 14:00-19:00", "9.30 - 18".
     *
     * @param array|string $hours lines entered in the back office for that day
     *
     * @return array list of [start_minute, end_minute]
     */
    public static function parseRanges($hours)
    {
        if (is_array($hours)) {
            $hours = implode(' / ', array_filter(array_map('strval', $hours)));
        }
        $hours = (string) $hours;
        $ranges = [];
        $time = '(\d{1,2})(?:\s*[:hH.]\s*(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?\s*h?';
        $separator = '\s*(?:-|–|—|~|à|to|au|a)\s*';
        if (!preg_match_all('/' . $time . $separator . $time . '/iu', $hours, $matches, PREG_SET_ORDER)) {
            return $ranges;
        }
        foreach ($matches as $m) {
            $start = self::toMinutes($m[1], isset($m[2]) ? $m[2] : '', isset($m[3]) ? $m[3] : '');
            $end = self::toMinutes($m[4], isset($m[5]) ? $m[5] : '', isset($m[6]) ? $m[6] : '');
            if ($start >= 0 && $end <= 24 * 60 && $end > $start) {
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }

    /**
     * "9", "30", "pm" => 21:30 in minutes
     */
    protected static function toMinutes($hour, $minute, $meridiem)
    {
        $hour = (int) $hour;
        $meridiem = strtolower(str_replace('.', '', (string) $meridiem));
        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return $hour * 60 + ($minute !== '' ? (int) $minute : 0);
    }

    public static function formatMinutes($minutes)
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function getSettings()
    {
        $duration = (int) Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_DURATION');
        $daysAhead = (int) Configuration::get('EVERPSCLICKANDCOLLECT_DAYS_AHEAD');
        $leadTime = Configuration::get('EVERPSCLICKANDCOLLECT_LEAD_TIME');

        return [
            'duration' => $duration >= 5 ? $duration : self::DEFAULT_DURATION,
            'lead_time' => ($leadTime === false || $leadTime === '') ? self::DEFAULT_LEAD_TIME : max(0, (int) $leadTime),
            'days_ahead' => $daysAhead > 0 ? min($daysAhead, 60) : self::DEFAULT_DAYS_AHEAD,
            'max_per_slot' => max(0, (int) Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_MAX')),
            'max_selected' => max(0, (int) Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_MAX_SELECT')),
            'closed_dates' => self::getClosedDates(),
        ];
    }

    /**
     * @return array of 'Y-m-d'
     */
    public static function getClosedDates()
    {
        $raw = (string) Configuration::get('EVERPSCLICKANDCOLLECT_CLOSED_DATES');
        $dates = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $line) {
            $line = trim($line);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $line, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $dates[] = $line;
            }
        }

        return $dates;
    }

    /**
     * Raw opening hours of a store, indexed 0 (Monday) to 6 (Sunday).
     */
    public static function getStoreWeekHours($idStore, $idLang)
    {
        $json = Db::getInstance()->getValue(
            'SELECT `hours` FROM `' . _DB_PREFIX_ . 'store_lang`
            WHERE id_store = ' . (int) $idStore . ' AND id_lang = ' . (int) $idLang
        );
        $hours = json_decode((string) $json, true);

        return is_array($hours) ? $hours : [];
    }

    /**
     * Number of bookings per slot for a store and date (validated orders only).
     *
     * @return array ['10:00-10:30' => 2, ...]
     */
    public static function countBookings($idStore, $date, $excludeIdCart = 0)
    {
        $excludedStates = array_filter([
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_ERROR'),
        ]);
        $sql = 'SELECT e.`delivery_hour`
            FROM `' . _DB_PREFIX_ . 'everpsclickandcollect` e
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.id_cart = e.id_cart)
            WHERE e.id_store = ' . (int) $idStore . '
            AND e.delivery_date = \'' . pSQL($date) . '\'
            AND e.id_cart != ' . (int) $excludeIdCart .
            ($excludedStates ? ' AND o.current_state NOT IN (' . implode(',', $excludedStates) . ')' : '') . '
            GROUP BY e.id_cart';
        $counts = [];
        foreach ((array) Db::getInstance()->executeS($sql) as $row) {
            foreach (self::splitSlots($row['delivery_hour']) as $slot) {
                $counts[$slot] = isset($counts[$slot]) ? $counts[$slot] + 1 : 1;
            }
        }

        return $counts;
    }

    public static function splitSlots($value)
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string) $value);
        }
        $slots = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $part)) {
                $slots[] = $part;
            }
        }
        $slots = array_values(array_unique($slots));
        sort($slots);

        return $slots;
    }

    /**
     * Bookable days and slots for a store.
     *
     * @param int $idStore
     * @param int $idLang
     * @param int $idCart current cart, its own booking is not counted
     * @param array $dayNames 0 => 'Monday' ... 6 => 'Sunday' (translated)
     * @param int|null $now timestamp, for tests
     *
     * @return array [['date' => 'Y-m-d', 'label' => 'Monday 12/10', 'slots' => [['value' => '10:00-10:30', 'label' => '10:00 - 10:30', 'full' => false]]]]
     */
    public static function getAvailableDays($idStore, $idLang, $idCart = 0, array $dayNames = [], $now = null)
    {
        $settings = self::getSettings();
        $now = $now === null ? time() : (int) $now;
        $weekHours = self::getStoreWeekHours($idStore, $idLang);
        $earliest = $now + $settings['lead_time'] * 60;
        $days = [];
        $today = new DateTime('@' . $now);
        $today->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $today->setTime(0, 0, 0);

        for ($i = 0; $i <= $settings['days_ahead']; ++$i) {
            $day = clone $today;
            $day->modify('+' . $i . ' day');
            $date = $day->format('Y-m-d');
            if (in_array($date, $settings['closed_dates'])) {
                continue;
            }
            $weekday = (int) $day->format('N') - 1; // 0 = Monday
            $ranges = self::parseRanges(isset($weekHours[$weekday]) ? $weekHours[$weekday] : []);
            if (!$ranges) {
                continue;
            }
            $counts = $settings['max_per_slot'] > 0 ? self::countBookings($idStore, $date, $idCart) : [];
            $slots = [];
            foreach ($ranges as $range) {
                for ($start = $range[0]; $start + $settings['duration'] <= $range[1]; $start += $settings['duration']) {
                    $end = $start + $settings['duration'];
                    $slotTime = (clone $day)->setTime(intdiv($start, 60), $start % 60)->getTimestamp();
                    if ($slotTime < $earliest) {
                        continue;
                    }
                    $value = self::formatMinutes($start) . '-' . self::formatMinutes($end);
                    $booked = isset($counts[$value]) ? $counts[$value] : 0;
                    $slots[] = [
                        'value' => $value,
                        'label' => self::formatMinutes($start) . ' - ' . self::formatMinutes($end),
                        'full' => $settings['max_per_slot'] > 0 && $booked >= $settings['max_per_slot'],
                    ];
                }
            }
            $hasFree = false;
            foreach ($slots as $slot) {
                if (!$slot['full']) {
                    $hasFree = true;
                    break;
                }
            }
            if (!$hasFree) {
                continue;
            }
            $days[] = [
                'date' => $date,
                'label' => (isset($dayNames[$weekday]) ? $dayNames[$weekday] . ' ' : '') . $day->format('d/m'),
                'slots' => $slots,
            ];
        }

        return $days;
    }

    /**
     * Check a customer selection against what is bookable right now.
     *
     * @return array list of error codes, empty when valid:
     *               'no_date', 'bad_date', 'no_slot', 'bad_slot', 'full_slot', 'too_many'
     */
    public static function validateSelection($idStore, $idLang, $idCart, $date, array $slots)
    {
        $settings = self::getSettings();
        if (!$date) {
            return ['no_date'];
        }
        if (!$slots) {
            return ['no_slot'];
        }
        if ($settings['max_selected'] > 0 && count($slots) > $settings['max_selected']) {
            return ['too_many'];
        }
        foreach (self::getAvailableDays($idStore, $idLang, $idCart) as $day) {
            if ($day['date'] !== $date) {
                continue;
            }
            $byValue = [];
            foreach ($day['slots'] as $slot) {
                $byValue[$slot['value']] = $slot;
            }
            foreach ($slots as $slot) {
                if (!isset($byValue[$slot])) {
                    return ['bad_slot'];
                }
                if ($byValue[$slot]['full']) {
                    return ['full_slot'];
                }
            }

            return [];
        }

        return ['bad_date'];
    }

    /**
     * Merge consecutive slots for display: 10:00-10:30,10:30-11:00 => 10:00 - 11:00
     */
    public static function humanizeSlots($value)
    {
        $slots = self::splitSlots($value);
        $merged = [];
        foreach ($slots as $slot) {
            list($start, $end) = explode('-', $slot);
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last][1] === $start) {
                $merged[$last][1] = $end;
            } else {
                $merged[] = [$start, $end];
            }
        }
        $parts = [];
        foreach ($merged as $range) {
            $parts[] = $range[0] . ' - ' . $range[1];
        }

        return implode(', ', $parts);
    }
}
