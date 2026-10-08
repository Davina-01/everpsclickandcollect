<?php
/**
 * Pickup time choice for Ever PS Click And Collect (3.4.0)
 *
 * The customer chooses:
 *   A. "now"   - pick up right away (within NOW_LIMIT minutes after ordering)
 *   B. "later" - up to 3 periods "date HH:MM - HH:MM" when they may come (prefilled, can be cleared)
 *
 * Pickup hours are set per week day (one or several ranges, empty = no pickup that day),
 * independently from the store opening hours. Exceptions can close a whole date or part of it.
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

    /** Who collects the order */
    const BY_SELF = 'self';
    const BY_COURIER = 'courier';

    const REASON_EMPTY = 'empty';
    const REASON_SPAN = 'span';
    const REASON_DURATION = 'duration';

    /** Allowed minute steps */
    public static $steps = array(5, 10, 15, 20, 30, 60);

    /** Pickup hours per week day, 1 = Monday ... 7 = Sunday */
    public static $defaultSchedule = array(
        1 => '10:30-19:00',
        2 => '10:30-19:00',
        3 => '10:30-19:00',
        4 => '10:30-19:00',
        5 => '10:30-19:00',
        6 => '10:30-19:00',
        7 => '',
    );

    public static $defaults = array(
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
        if (!is_string($time) || !preg_match('/^(\d{1,2})[:hH.](\d{2})$/', trim($time), $m)) {
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

    /**
     * "10:30-14:00, 16:00-19:00" => [[630, 840], [960, 1140]]
     *
     * @return array|null null when the text is not valid
     */
    public static function parseRanges($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return array();
        }
        $ranges = array();
        foreach (preg_split('/\s*[,;\/]\s*/', $text) as $part) {
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^(\d{1,2}[:hH.]\d{2})\s*[-–]\s*(\d{1,2}[:hH.]\d{2})$/u', $part, $m)) {
                return null;
            }
            $start = self::toMinutes($m[1]);
            $end = self::toMinutes($m[2]);
            if ($start === null || $end === null || $end <= $start) {
                return null;
            }
            $ranges[] = array($start, $end);
        }
        usort($ranges, function ($a, $b) {
            return $a[0] - $b[0];
        });
        for ($i = 1; $i < count($ranges); ++$i) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                return null; // overlapping ranges
            }
        }

        return $ranges;
    }

    public static function formatRanges(array $ranges)
    {
        $parts = array();
        foreach ($ranges as $r) {
            $parts[] = self::toTime($r[0]) . '-' . self::toTime($r[1]);
        }

        return implode(', ', $parts);
    }

    /**
     * Exceptions, one per line: "2026-12-25" (whole day) or "2026-12-24 14:00-19:00" (part of the day)
     *
     * @return array|null ['Y-m-d' => true (whole day) | [[start, end], ...]], null when a line is not valid
     */
    public static function parseExceptions($text, &$badLine = null)
    {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(.+))?$/', $line, $m)
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ) {
                $badLine = $line;

                return null;
            }
            $date = $m[1] . '-' . $m[2] . '-' . $m[3];
            if (!isset($m[4]) || trim($m[4]) === '') {
                $out[$date] = true;
                continue;
            }
            $ranges = self::parseRanges($m[4]);
            if (!$ranges) {
                $badLine = $line;

                return null;
            }
            if (!isset($out[$date]) || $out[$date] !== true) {
                $out[$date] = array_merge(isset($out[$date]) ? $out[$date] : array(), $ranges);
            }
        }

        return $out;
    }

    public static function readCollector($value)
    {
        return $value === self::BY_COURIER ? self::BY_COURIER : self::BY_SELF;
    }

    public static function getSettings()
    {
        $get = function ($key) {
            $value = Configuration::get($key);

            return ($value === false || $value === null || $value === '') ? self::$defaults[$key] : $value;
        };
        $stored = json_decode((string) Configuration::get('EVERPSCLICKANDCOLLECT_SCHEDULE'), true);
        $schedule = array();
        $latest = 0;
        for ($day = 1; $day <= 7; ++$day) {
            $text = is_array($stored) && isset($stored[$day]) ? $stored[$day] : self::$defaultSchedule[$day];
            $ranges = self::parseRanges($text);
            $schedule[$day] = $ranges ?: array();
            foreach ($schedule[$day] as $r) {
                $latest = max($latest, $r[1]);
            }
        }
        $step = (int) $get('EVERPSCLICKANDCOLLECT_MINUTE_STEP');
        if (!in_array($step, self::$steps)) {
            $step = 15;
        }
        $closing = self::toMinutes($get('EVERPSCLICKANDCOLLECT_PICKUP_CLOSING'));
        $exceptions = self::parseExceptions(Configuration::get('EVERPSCLICKANDCOLLECT_CLOSED_DATES'));

        return array(
            'schedule' => $schedule,
            'exceptions' => $exceptions ?: array(),
            'latest' => $latest, // latest pickup time of the week, used in message T1
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
     * Remove $cut from $ranges
     */
    protected static function subtract(array $ranges, array $cut)
    {
        foreach ($cut as $c) {
            $next = array();
            foreach ($ranges as $r) {
                if ($c[1] <= $r[0] || $c[0] >= $r[1]) {
                    $next[] = $r;
                    continue;
                }
                if ($c[0] > $r[0]) {
                    $next[] = array($r[0], $c[0]);
                }
                if ($c[1] < $r[1]) {
                    $next[] = array($c[1], $r[1]);
                }
            }
            $ranges = $next;
        }

        return $ranges;
    }

    /**
     * Pickup ranges of a date, after exceptions
     */
    public static function getDayRanges($date, array $settings)
    {
        $time = strtotime($date . ' 12:00:00');
        if ($time === false) {
            return array();
        }
        $ranges = $settings['schedule'][(int) date('N', $time)];
        if (isset($settings['exceptions'][$date])) {
            if ($settings['exceptions'][$date] === true) {
                return array();
            }
            $ranges = self::subtract($ranges, $settings['exceptions'][$date]);
        }

        return array_values($ranges);
    }

    /**
     * Can a period start at $t? (at least one step before the end of a range)
     */
    public static function isValidStart($t, array $ranges, $step)
    {
        if ($t % $step !== 0) {
            return false;
        }
        foreach ($ranges as $r) {
            if ($t >= $r[0] && $t + $step <= $r[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Can a period end at $t?
     */
    public static function isValidEnd($t, array $ranges, $step)
    {
        if ($t % $step !== 0 && !self::isRangeEnd($t, $ranges)) {
            return false;
        }
        foreach ($ranges as $r) {
            if ($t > $r[0] && $t <= $r[1]) {
                return true;
            }
        }

        return false;
    }

    protected static function isRangeEnd($t, array $ranges)
    {
        foreach ($ranges as $r) {
            if ($r[1] === $t) {
                return true;
            }
        }

        return false;
    }

    public static function nowMinutes($now)
    {
        return (int) date('G', $now) * 60 + (int) date('i', $now);
    }

    /**
     * First possible start of a date: now (rounded up to the step) for today, else the first range start
     *
     * @return int|null
     */
    public static function firstStart($date, array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $ranges = self::getDayRanges($date, $settings);
        $min = $date === date('Y-m-d', $now) ? self::nowMinutes($now) : 0;
        foreach ($ranges as $r) {
            $t = max($r[0], (int) (ceil($min / $settings['step']) * $settings['step']));
            if ($t % $settings['step'] !== 0) {
                $t = (int) (ceil($t / $settings['step']) * $settings['step']);
            }
            if ($t + $settings['step'] <= $r[1]) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Default period for a date: from the first possible start (now for today) to the end of the day's pickup hours
     *
     * @return array|null ['date', 'start', 'end']
     */
    public static function defaultPeriod($date, array $settings, $now = null)
    {
        $start = self::firstStart($date, $settings, $now);
        if ($start === null) {
            return null;
        }
        $ranges = self::getDayRanges($date, $settings);

        return array('date' => $date, 'start' => $start, 'end' => $ranges[count($ranges) - 1][1]);
    }

    /**
     * Days the customer can choose: today (when a period can still start) and the next ones with pickup hours,
     * BOOKABLE_DAYS days in total.
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
            if (self::firstStart($date, $settings, $now) !== null) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Option A is only offered today, during today's pickup hours
     */
    public static function isNowAvailable(array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $minutes = self::nowMinutes($now);
        foreach (self::getDayRanges(date('Y-m-d', $now), $settings) as $r) {
            if ($minutes >= $r[0] && $minutes <= $r[1]) {
                return true;
            }
        }

        return false;
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
     * Validate periods. Customers ($strict) may only choose bookable dates, pickup hours and future times;
     * the back office can set any date and time on the minute step.
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
        foreach ($filled as $p) {
            if ($p['status'] === 'incomplete') {
                return array(array(), 'incomplete');
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $p['date'], $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return array(array(), 'bad_date');
            }
            if ($p['end'] <= $p['start']) {
                return array(array(), 'end_before_start');
            }
            if ($strict) {
                if (!in_array($p['date'], $bookable)) {
                    return array(array(), 'bad_date');
                }
                if ($p['date'] === $today && $p['start'] < self::nowMinutes($now) - $settings['step']) {
                    return array(array(), 'past');
                }
                $ranges = self::getDayRanges($p['date'], $settings);
                if (!self::isValidStart($p['start'], $ranges, $settings['step'])
                    || !self::isValidEnd($p['end'], $ranges, $settings['step'])
                ) {
                    return array(array(), 'bad_time');
                }
            } elseif ($p['start'] % $settings['step'] !== 0 || $p['end'] > 24 * 60) {
                return array(array(), 'bad_time');
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
