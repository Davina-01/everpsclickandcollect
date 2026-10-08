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
    const MODE_FIXED = 'fixed';
    const MODE_PERIOD = 'period';
    /** A period stays bookable while at least this many minutes are left after the preparation time */
    const PERIOD_MIN_WINDOW = 15;

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
            'mode' => Configuration::get('EVERPSCLICKANDCOLLECT_SLOT_MODE') === self::MODE_PERIOD ? self::MODE_PERIOD : self::MODE_FIXED,
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
     * A booking entry is a pickup date plus a time slot.
     * Stored as "2026-10-09 10:00-10:30" (several separated by commas).
     *
     * @param array|string $value list of "Y-m-d HH:MM-HH:MM" / "Y-m-d|HH:MM-HH:MM", or a stored string
     * @param string $legacyDate date applied to entries without date (rows saved by 3.2.0)
     *
     * @return array sorted unique list of ['date' => 'Y-m-d', 'slot' => 'HH:MM-HH:MM']
     */
    public static function parseEntries($value, $legacyDate = '')
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $legacyDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $legacyDate) ? $legacyDate : '';
        $entries = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ |](\d{2}:\d{2}-\d{2}:\d{2})$/', $part, $m)) {
                $entries[$m[1] . ' ' . $m[2]] = ['date' => $m[1], 'slot' => $m[2]];
            } elseif ($legacyDate && preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $part)) {
                $entries[$legacyDate . ' ' . $part] = ['date' => $legacyDate, 'slot' => $part];
            }
        }
        ksort($entries);

        return array_values($entries);
    }

    /**
     * @return array ['delivery_date' => '2026-10-08,2026-10-09', 'delivery_hour' => '2026-10-08 18:00-18:30,...']
     */
    public static function serializeEntries(array $entries)
    {
        $dates = [];
        $values = [];
        foreach (self::parseEntries(array_map(function ($e) {
            return $e['date'] . ' ' . $e['slot'];
        }, $entries)) as $entry) {
            $dates[$entry['date']] = true;
            $values[] = $entry['date'] . ' ' . $entry['slot'];
        }

        return [
            'delivery_date' => implode(',', array_keys($dates)),
            'delivery_hour' => implode(',', $values),
        ];
    }

    /**
     * Entries saved for a cart row (handles rows saved by version 3.2.0)
     */
    public static function getRowEntries($row)
    {
        if (!$row) {
            return [];
        }

        return self::parseEntries((string) $row['delivery_hour'], (string) $row['delivery_date']);
    }

    protected static function slotBounds($slot)
    {
        list($start, $end) = explode('-', $slot);
        list($sh, $sm) = explode(':', $start);
        list($eh, $em) = explode(':', $end);

        return [(int) $sh * 60 + (int) $sm, (int) $eh * 60 + (int) $em];
    }

    /**
     * Bookings of validated orders for a store and date.
     * Each order is counted once per slot it overlaps, so bookings made with
     * another slot length or mode still use the capacity.
     *
     * @return array list of booked [start, end] minute ranges per order
     */
    public static function getBookedRanges($idStore, $date, $excludeIdCart = 0)
    {
        $excludedStates = array_filter([
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_ERROR'),
        ]);
        $sql = 'SELECT e.`delivery_date`, e.`delivery_hour`
            FROM `' . _DB_PREFIX_ . 'everpsclickandcollect` e
            INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.id_cart = e.id_cart)
            WHERE e.id_store = ' . (int) $idStore . '
            AND e.delivery_date LIKE \'%' . pSQL($date) . '%\'
            AND e.id_cart != ' . (int) $excludeIdCart .
            ($excludedStates ? ' AND o.current_state NOT IN (' . implode(',', $excludedStates) . ')' : '') . '
            GROUP BY e.id_cart';
        $orders = [];
        foreach ((array) Db::getInstance()->executeS($sql) as $row) {
            $ranges = [];
            foreach (self::getRowEntries($row) as $entry) {
                if ($entry['date'] === $date) {
                    $ranges[] = self::slotBounds($entry['slot']);
                }
            }
            if ($ranges) {
                $orders[] = $ranges;
            }
        }

        return $orders;
    }

    protected static function countOverlaps(array $orders, $start, $end)
    {
        $count = 0;
        foreach ($orders as $ranges) {
            foreach ($ranges as $range) {
                if ($range[0] < $end && $range[1] > $start) {
                    ++$count;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Name of a period: morning, afternoon, evening or day
     */
    public static function getPeriodKey($start, $end)
    {
        if ($end <= 13 * 60) {
            return 'morning';
        }
        if ($start >= 18 * 60) {
            return 'evening';
        }
        if ($start >= 12 * 60) {
            return 'afternoon';
        }

        return 'day';
    }

    /**
     * Bookable days and slots for a store.
     *
     * @param int $idStore
     * @param int $idLang
     * @param int $idCart current cart, its own booking is not counted
     * @param array $dayNames 0 => 'Monday' ... 6 => 'Sunday' (translated)
     * @param array $periodNames ['morning' => ..., 'afternoon' => ..., 'evening' => ..., 'day' => ...]
     * @param int|null $now timestamp, for tests
     *
     * @return array [[
     *     'date' => 'Y-m-d', 'label' => 'Monday 12/10', 'weekday' => 'Monday', 'short' => '12/10',
     *     'slots' => [['value' => '10:00-10:30', 'label' => '10:00 - 10:30', 'name' => '', 'full' => false, 'past' => false]]
     * ]]
     */
    public static function getAvailableDays($idStore, $idLang, $idCart = 0, array $dayNames = [], array $periodNames = [], $now = null)
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
            $booked = $settings['max_per_slot'] > 0 ? self::getBookedRanges($idStore, $date, $idCart) : [];
            $slots = [];
            foreach ($ranges as $range) {
                if ($settings['mode'] === self::MODE_PERIOD) {
                    $pieces = [[$range[0], $range[1]]];
                } else {
                    $pieces = [];
                    for ($start = $range[0]; $start + $settings['duration'] <= $range[1]; $start += $settings['duration']) {
                        $pieces[] = [$start, $start + $settings['duration']];
                    }
                }
                foreach ($pieces as $piece) {
                    list($start, $end) = $piece;
                    $startTime = (clone $day)->setTime(intdiv($start, 60), $start % 60)->getTimestamp();
                    $endTime = (clone $day)->setTime(intdiv($end, 60), $end % 60)->getTimestamp();
                    if ($settings['mode'] === self::MODE_PERIOD) {
                        // The customer can still come later in the period
                        $past = $endTime - self::PERIOD_MIN_WINDOW * 60 < $earliest;
                    } else {
                        $past = $startTime < $earliest;
                    }
                    $periodKey = self::getPeriodKey($start, $end);
                    $slots[] = [
                        'value' => self::formatMinutes($start) . '-' . self::formatMinutes($end),
                        'label' => self::formatMinutes($start) . ' - ' . self::formatMinutes($end),
                        'name' => $settings['mode'] === self::MODE_PERIOD && isset($periodNames[$periodKey]) ? $periodNames[$periodKey] : '',
                        'full' => !$past && $settings['max_per_slot'] > 0
                            && self::countOverlaps($booked, $start, $end) >= $settings['max_per_slot'],
                        'past' => $past,
                    ];
                }
            }
            $hasFree = false;
            foreach ($slots as $slot) {
                if (!$slot['full'] && !$slot['past']) {
                    $hasFree = true;
                    break;
                }
            }
            if (!$hasFree) {
                continue;
            }
            $days[] = [
                'date' => $date,
                'weekday' => isset($dayNames[$weekday]) ? $dayNames[$weekday] : $day->format('l'),
                'short' => $day->format('d/m'),
                'label' => (isset($dayNames[$weekday]) ? $dayNames[$weekday] . ' ' : '') . $day->format('d/m'),
                'slots' => $slots,
            ];
        }

        return $days;
    }

    /**
     * Check a customer selection against what is bookable right now.
     *
     * @param array $entries list of ['date' => 'Y-m-d', 'slot' => 'HH:MM-HH:MM']
     *
     * @return array list of error codes, empty when valid: 'no_slot', 'bad_slot', 'full_slot', 'too_many'
     */
    public static function validateSelection($idStore, $idLang, $idCart, array $entries)
    {
        $settings = self::getSettings();
        if (!$entries) {
            return ['no_slot'];
        }
        if ($settings['max_selected'] > 0 && count($entries) > $settings['max_selected']) {
            return ['too_many'];
        }
        $available = [];
        foreach (self::getAvailableDays($idStore, $idLang, $idCart) as $day) {
            foreach ($day['slots'] as $slot) {
                $available[$day['date'] . ' ' . $slot['value']] = $slot;
            }
        }
        foreach ($entries as $entry) {
            $key = $entry['date'] . ' ' . $entry['slot'];
            if (!isset($available[$key]) || $available[$key]['past']) {
                return ['bad_slot'];
            }
            if ($available[$key]['full']) {
                return ['full_slot'];
            }
        }

        return [];
    }

    /**
     * Group entries by date and merge consecutive slots:
     * 2026-10-09 10:00-10:30, 2026-10-09 10:30-11:00 => ['2026-10-09' => '10:00 - 11:00']
     *
     * @return array ['Y-m-d' => '10:00 - 11:00, 14:00 - 14:30']
     */
    public static function humanizeEntries(array $entries)
    {
        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[$entry['date']][] = $entry['slot'];
        }
        $result = [];
        foreach ($byDate as $date => $slots) {
            sort($slots);
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
            $result[$date] = implode(', ', array_map(function ($r) {
                return $r[0] . ' - ' . $r[1];
            }, $merged));
        }
        ksort($result);

        return $result;
    }
}
