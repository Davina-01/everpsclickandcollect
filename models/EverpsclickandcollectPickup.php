<?php
/**
 * Pickup time choice for Ever PS Click And Collect (3.4.0)
 *
 * The customer chooses:
 *   A. "now"   - pick up right away (within NOW_LIMIT minutes after ordering)
 *   B. "later" - optionally up to 3 periods "date HH:MM - HH:MM" when they may come
 *
 * Orders are prepared in advance unless (B only):
 *   1. no period was given,
 *   2. the first and last dates are more than MAX_SPAN days apart (optional rule),
 *   3. the merged periods last more than MAX_DURATION hours in total (optional rule).
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EverpsclickandcollectPickup
{
    const MODE_NOW = 'now';
    const MODE_LATER = 'later';
    const MAX_PERIODS = 3;

    const REASON_EMPTY = 'empty';
    const REASON_SPAN = 'span';
    const REASON_DURATION = 'duration';

    /** Allowed minute steps */
    public static $steps = array(5, 10, 15, 20, 30, 60);

    public static $defaults = array(
        'EVERPSCLICKANDCOLLECT_OPEN_DAYS' => '[1,2,3,4,5,6]',
        'EVERPSCLICKANDCOLLECT_PICKUP_EARLIEST' => '10:30',
        'EVERPSCLICKANDCOLLECT_PICKUP_LATEST' => '19:00',
        'EVERPSCLICKANDCOLLECT_PICKUP_CLOSING' => '19:30',
        'EVERPSCLICKANDCOLLECT_MINUTE_STEP' => 15,
        'EVERPSCLICKANDCOLLECT_NOW_LIMIT' => 30,
        'EVERPSCLICKANDCOLLECT_BOOKABLE_DAYS' => 6,
        'EVERPSCLICKANDCOLLECT_SPAN_ON' => 1,
        'EVERPSCLICKANDCOLLECT_MAX_SPAN' => 1,
        'EVERPSCLICKANDCOLLECT_DURATION_ON' => 1,
        'EVERPSCLICKANDCOLLECT_MAX_DURATION' => 6,
    );

    /**
     * "HH:MM" => minutes since midnight, null when invalid
     */
    public static function toMinutes($time)
    {
        if (!is_string($time) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 24 || $i > 59 || ($h === 24 && $i > 0)) {
            return null;
        }

        return $h * 60 + $i;
    }

    public static function toTime($minutes)
    {
        return sprintf('%02d:%02d', intdiv((int) $minutes, 60), (int) $minutes % 60);
    }

    public static function getSettings()
    {
        $get = function ($key) {
            $value = Configuration::get($key);

            return ($value === false || $value === null || $value === '') ? self::$defaults[$key] : $value;
        };
        $openDays = json_decode((string) $get('EVERPSCLICKANDCOLLECT_OPEN_DAYS'), true);
        if (!is_array($openDays)) {
            $openDays = json_decode(self::$defaults['EVERPSCLICKANDCOLLECT_OPEN_DAYS'], true);
        }
        $step = (int) $get('EVERPSCLICKANDCOLLECT_MINUTE_STEP');
        if (!in_array($step, self::$steps)) {
            $step = 15;
        }
        $earliest = self::toMinutes($get('EVERPSCLICKANDCOLLECT_PICKUP_EARLIEST'));
        $latest = self::toMinutes($get('EVERPSCLICKANDCOLLECT_PICKUP_LATEST'));
        $closing = self::toMinutes($get('EVERPSCLICKANDCOLLECT_PICKUP_CLOSING'));

        return array(
            'open_days' => array_values(array_map('intval', $openDays)), // 1 = Monday ... 7 = Sunday
            'closed_dates' => self::getClosedDates(),
            'earliest' => $earliest === null ? 630 : $earliest,
            'latest' => $latest === null ? 1140 : $latest,
            'closing' => $closing === null ? 1170 : $closing,
            'step' => $step,
            'now_limit' => max(1, (int) $get('EVERPSCLICKANDCOLLECT_NOW_LIMIT')),
            'bookable_days' => max(1, min(60, (int) $get('EVERPSCLICKANDCOLLECT_BOOKABLE_DAYS'))),
            'span_on' => (bool) $get('EVERPSCLICKANDCOLLECT_SPAN_ON'),
            'max_span' => max(0, (int) $get('EVERPSCLICKANDCOLLECT_MAX_SPAN')),
            'duration_on' => (bool) $get('EVERPSCLICKANDCOLLECT_DURATION_ON'),
            'max_duration' => (int) round((float) $get('EVERPSCLICKANDCOLLECT_MAX_DURATION') * 60), // minutes
        );
    }

    /**
     * @return array of 'Y-m-d'
     */
    public static function getClosedDates()
    {
        $raw = (string) Configuration::get('EVERPSCLICKANDCOLLECT_CLOSED_DATES');
        $dates = array();
        foreach (preg_split('/[\s,;]+/', $raw) as $line) {
            $line = trim($line);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $line, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $dates[] = $line;
            }
        }

        return $dates;
    }

    public static function isOpenDay($date, array $settings)
    {
        $time = strtotime($date . ' 12:00:00');
        if ($time === false) {
            return false;
        }

        return in_array((int) date('N', $time), $settings['open_days'])
            && !in_array($date, $settings['closed_dates']);
    }

    /**
     * Current time rounded up to the next step, in minutes
     */
    public static function nowMinutesRoundedUp($now, $step)
    {
        $minutes = (int) date('G', $now) * 60 + (int) date('i', $now) + ((int) date('s', $now) > 0 ? 1 : 0);

        return (int) (ceil($minutes / $step) * $step);
    }

    /**
     * Business days the customer can choose: today (when a period can still start) and the next ones,
     * BOOKABLE_DAYS business days in total.
     *
     * @return array of 'Y-m-d'
     */
    public static function getBookableDates(array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $dates = array();
        $day = strtotime(date('Y-m-d', $now) . ' 12:00:00');
        for ($i = 0; $i < 400 && count($dates) < $settings['bookable_days']; ++$i) {
            $date = date('Y-m-d', strtotime('+' . $i . ' day', $day));
            if (!self::isOpenDay($date, $settings)) {
                continue;
            }
            if ($i === 0) {
                // Today only when a period can still start before the latest pickup time
                $firstStart = max($settings['earliest'], self::nowMinutesRoundedUp($now, $settings['step']));
                if ($firstStart + $settings['step'] > $settings['latest']) {
                    continue;
                }
            }
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * Option A is only offered today, on a business day, between the earliest and latest pickup time
     */
    public static function isNowAvailable(array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $minutes = (int) date('G', $now) * 60 + (int) date('i', $now);

        return self::isOpenDay(date('Y-m-d', $now), $settings)
            && $minutes >= $settings['earliest']
            && $minutes <= $settings['latest'];
    }

    /**
     * Read periods posted by the checkout form or the back office form.
     * Accepted shapes: ['date' => .., 'sh' => '10', 'sm' => '30', 'eh' => .., 'em' => ..]
     *               or ['date' => .., 'start' => '10:30', 'end' => '12:00']
     *
     * @return array list of ['date', 'start' (int|null), 'end' (int|null), 'status' => empty|complete|incomplete]
     */
    public static function readPeriods($raw)
    {
        $periods = array();
        if (!is_array($raw)) {
            return $periods;
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = isset($row['date']) ? trim((string) $row['date']) : '';
            if (isset($row['start']) || isset($row['end'])) {
                $startRaw = isset($row['start']) ? trim((string) $row['start']) : '';
                $endRaw = isset($row['end']) ? trim((string) $row['end']) : '';
                $parts = array($startRaw, $endRaw);
                $start = $startRaw === '' ? null : self::toMinutes($startRaw);
                $end = $endRaw === '' ? null : self::toMinutes($endRaw);
                $invalid = ($startRaw !== '' && $start === null) || ($endRaw !== '' && $end === null);
            } else {
                $parts = array();
                foreach (array('sh', 'sm', 'eh', 'em') as $key) {
                    $parts[] = isset($row[$key]) ? trim((string) $row[$key]) : '';
                }
                $invalid = false;
                foreach ($parts as $part) {
                    if ($part !== '' && !ctype_digit($part)) {
                        $invalid = true;
                    }
                }
                $start = ($parts[0] !== '' && $parts[1] !== '') ? (int) $parts[0] * 60 + (int) $parts[1] : null;
                $end = ($parts[2] !== '' && $parts[3] !== '') ? (int) $parts[2] * 60 + (int) $parts[3] : null;
            }
            $filled = count(array_filter($parts, function ($p) {
                return $p !== '';
            }));
            if ($filled === 0) {
                $status = 'empty';
            } elseif ($invalid || $start === null || $end === null) {
                $status = 'incomplete';
            } else {
                $status = 'complete';
            }
            $periods[] = array('date' => $date, 'start' => $start, 'end' => $end, 'status' => $status);
        }

        return $periods;
    }

    /**
     * Validate periods. Customers ($strict) may only choose bookable dates and future times;
     * the back office can set any date.
     *
     * @return array [list of valid periods, error code or null]
     *               error codes: too_many, incomplete, bad_date, bad_time, end_before_start, past
     */
    public static function validatePeriods(array $periods, array $settings, $strict = true, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $valid = array();
        $filled = array_values(array_filter($periods, function ($p) {
            return $p['status'] !== 'empty';
        }));
        if (count($filled) > self::MAX_PERIODS) {
            return array(array(), 'too_many');
        }
        $bookable = $strict ? self::getBookableDates($settings, $now) : array();
        $today = date('Y-m-d', $now);
        $nowMinutes = (int) date('G', $now) * 60 + (int) date('i', $now);
        foreach ($filled as $p) {
            if ($p['status'] === 'incomplete') {
                return array(array(), 'incomplete');
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $p['date'], $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return array(array(), 'bad_date');
            }
            if ($strict && !in_array($p['date'], $bookable)) {
                return array(array(), 'bad_date');
            }
            foreach (array($p['start'], $p['end']) as $t) {
                if ($t < $settings['earliest'] || $t > $settings['latest'] || $t % $settings['step'] !== 0) {
                    return array(array(), 'bad_time');
                }
            }
            if ($p['end'] <= $p['start']) {
                return array(array(), 'end_before_start');
            }
            if ($strict && $p['date'] === $today && $p['start'] < $nowMinutes) {
                return array(array(), 'past');
            }
            $valid[] = array('date' => $p['date'], 'start' => (int) $p['start'], 'end' => (int) $p['end']);
        }

        return array($valid, null);
    }

    /**
     * Sort periods and merge overlapping or touching periods of the same day.
     * 14:00-16:00 + 15:00-17:00 => 14:00-17:00
     */
    public static function mergePeriods(array $periods)
    {
        usort($periods, function ($a, $b) {
            return strcmp($a['date'], $b['date']) ?: ($a['start'] - $b['start']);
        });
        $merged = array();
        foreach ($periods as $p) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['date'] === $p['date'] && $p['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $p['end']);
            } else {
                $merged[] = array('date' => $p['date'], 'start' => (int) $p['start'], 'end' => (int) $p['end']);
            }
        }

        return $merged;
    }

    /**
     * Should the order be prepared before the customer arrives?
     *
     * @param array $merged periods returned by mergePeriods()
     *
     * @return array ['prepare' => bool, 'reasons' => list of REASON_*]
     */
    public static function evaluate($mode, array $merged, array $settings)
    {
        if ($mode === self::MODE_NOW) {
            return array('prepare' => true, 'reasons' => array());
        }
        if (!$merged) {
            return array('prepare' => false, 'reasons' => array(self::REASON_EMPTY));
        }
        $reasons = array();
        if ($settings['span_on']) {
            $first = new DateTime($merged[0]['date']);
            $last = new DateTime($merged[count($merged) - 1]['date']);
            if ((int) $first->diff($last)->days > $settings['max_span']) {
                $reasons[] = self::REASON_SPAN;
            }
        }
        if ($settings['duration_on']) {
            $total = 0;
            foreach ($merged as $p) {
                $total += $p['end'] - $p['start'];
            }
            if ($total > $settings['max_duration']) {
                $reasons[] = self::REASON_DURATION;
            }
        }

        return array('prepare' => !$reasons, 'reasons' => $reasons);
    }

    /**
     * Stored form: [{"date":"2026-10-09","start":"10:30","end":"12:00"}]
     */
    public static function encodePeriods(array $merged)
    {
        $out = array();
        foreach ($merged as $p) {
            $out[] = array('date' => $p['date'], 'start' => self::toTime($p['start']), 'end' => self::toTime($p['end']));
        }

        return json_encode($out);
    }

    public static function decodePeriods($json)
    {
        $data = json_decode((string) $json, true);
        $out = array();
        if (!is_array($data)) {
            return $out;
        }
        foreach ($data as $p) {
            if (!isset($p['date'], $p['start'], $p['end'])) {
                continue;
            }
            $start = self::toMinutes($p['start']);
            $end = self::toMinutes($p['end']);
            if ($start !== null && $end !== null) {
                $out[] = array('date' => (string) $p['date'], 'start' => $start, 'end' => $end);
            }
        }

        return $out;
    }

    /**
     * Language neutral text for the back office order list and its filter:
     * "2026-10-08 17:00-19:00 / 2026-10-09 10:30-12:00"
     */
    public static function summary(array $merged)
    {
        $parts = array();
        foreach ($merged as $p) {
            $parts[] = $p['date'] . ' ' . self::toTime($p['start']) . '-' . self::toTime($p['end']);
        }

        return implode(' / ', $parts);
    }
}
