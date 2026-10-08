/**
 * Ever PS Click And Collect - pickup store and pickup time on checkout (3.4.0)
 *
 * A. "Pick up now"
 * B. "Pick up later" with up to 3 optional periods "date HH:MM - HH:MM"
 * Messages T4 / T5 / T6 follow the customer's choice live; the same rules are checked
 * again on the server when the customer presses "Continue".
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
(function ($) {
	'use strict';

	var root = '#everclickncollect_id';
	var loadedAt = Date.now();
	var saveTimer = null;

	function settings($booking) {
		var s = $booking.data('evercncSettings');
		if (!s) {
			try {
				s = JSON.parse($booking.attr('data-settings') || '{}');
			} catch (e) {
				s = {};
			}
			$booking.data('evercncSettings', s);
		}
		return s;
	}

	/* Current time in minutes, following the server clock */
	function nowMinutes(s) {
		return s.nowMinutes + Math.floor((Date.now() - loadedAt) / 60000);
	}

	function activeStoreId() {
		var $wrap = $(root);
		var $radio = $wrap.find('.evercnc-store-radio:checked');
		if ($radio.length) {
			return String($radio.val());
		}
		return String($wrap.find('input[name="everpsclickandcollect"]').first().val() || '');
	}

	function minutesOf(h, m) {
		if (h === '' || m === '' || h === null || m === null) {
			return null;
		}
		return parseInt(h, 10) * 60 + parseInt(m, 10);
	}

	function part($row, name) {
		return $row.find('select[data-part="' + name + '"]');
	}

	function rowData($row) {
		var v = {};
		$.each(['sh', 'sm', 'eh', 'em'], function (i, name) {
			v[name] = part($row, name).val() || '';
		});
		var filled = 0;
		$.each(v, function (k, val) {
			if (val !== '') {
				filled++;
			}
		});
		var start = minutesOf(v.sh, v.sm);
		var end = minutesOf(v.eh, v.em);
		var status = filled === 0 ? 'empty' : (start === null || end === null ? 'incomplete' : 'complete');
		return { date: String($row.find('.evercnc-p-date').val() || ''), start: start, end: end, status: status, values: v };
	}

	function dayInfo(s, date) {
		return (s.days && s.days[date]) || { ranges: [], start: null, end: null };
	}

	function validStart(t, ranges, step, min) {
		if (t % step !== 0 || t < min) {
			return false;
		}
		for (var i = 0; i < ranges.length; i++) {
			if (t >= ranges[i][0] && t + step <= ranges[i][1]) {
				return true;
			}
		}
		return false;
	}

	function validEnd(t, ranges, step, start) {
		if (start !== null && t <= start) {
			return false;
		}
		for (var i = 0; i < ranges.length; i++) {
			if (t > ranges[i][0] && t <= ranges[i][1] && (t % step === 0 || t === ranges[i][1])) {
				return true;
			}
		}
		return false;
	}

	/* Enable only the hours / minutes accepted by isValid(minutes) */
	function limitOptions($hour, $minute, isValid) {
		$hour.find('option').each(function () {
			if (this.value === '') {
				return;
			}
			var h = parseInt(this.value, 10);
			var ok = false;
			$minute.find('option').each(function () {
				if (this.value !== '' && isValid(h * 60 + parseInt(this.value, 10))) {
					ok = true;
				}
			});
			this.disabled = !ok;
		});
		if ($hour.val() !== '' && $hour.find('option:selected').prop('disabled')) {
			$hour.val('');
		}
		var hour = $hour.val();
		$minute.find('option').each(function () {
			if (this.value === '') {
				return;
			}
			this.disabled = hour !== '' && hour !== null && !isValid(parseInt(hour, 10) * 60 + parseInt(this.value, 10));
		});
		if ($minute.val() !== '' && $minute.find('option:selected').prop('disabled')) {
			$minute.val('');
		}
		// Hour chosen first: take the first possible minute
		if (hour !== '' && hour !== null && ($minute.val() === '' || $minute.val() === null)) {
			var $first = $minute.find('option').filter(function () {
				return this.value !== '' && !this.disabled;
			}).first();
			if ($first.length) {
				$minute.val($first.val());
			}
		}
	}

	function minStartOf(s, date) {
		return date === s.today ? Math.ceil(nowMinutes(s) / s.step) * s.step : 0;
	}

	function updateRowOptions($row, s) {
		var date = String($row.find('.evercnc-p-date').val() || '');
		var info = dayInfo(s, date);
		var min = minStartOf(s, date);
		limitOptions(part($row, 'sh'), part($row, 'sm'), function (t) {
			return validStart(t, info.ranges, s.step, min);
		});
		var start = minutesOf(part($row, 'sh').val() || '', part($row, 'sm').val() || '');
		limitOptions(part($row, 'eh'), part($row, 'em'), function (t) {
			return validEnd(t, info.ranges, s.step, start);
		});
	}

	function setTimes($row, start, end) {
		var pad = function (n) {
			return (n < 10 ? '0' : '') + n;
		};
		part($row, 'sh').val(start === null ? '' : String(Math.floor(start / 60)));
		part($row, 'sm').val(start === null ? '' : pad(start % 60));
		part($row, 'eh').val(end === null ? '' : String(Math.floor(end / 60)));
		part($row, 'em').val(end === null ? '' : pad(end % 60));
	}

	/* Default times of a date: from now (today) or the first pickup time, to the end of the day's pickup hours */
	function applyDefaults($row, s) {
		var date = String($row.find('.evercnc-p-date').val() || '');
		var info = dayInfo(s, date);
		var start = info.start;
		if (date === s.today) {
			var min = minStartOf(s, date);
			start = null;
			for (var i = 0; i < info.ranges.length && start === null; i++) {
				var t = Math.max(info.ranges[i][0], min);
				if (t + s.step <= info.ranges[i][1]) {
					start = t;
				}
			}
		}
		setTimes($row, start, start === null ? null : info.end);
	}

	function merge(periods) {
		periods.sort(function (a, b) {
			return a.date < b.date ? -1 : (a.date > b.date ? 1 : a.start - b.start);
		});
		var out = [];
		$.each(periods, function (i, p) {
			var last = out[out.length - 1];
			if (last && last.date === p.date && p.start <= last.end) {
				last.end = Math.max(last.end, p.end);
			} else {
				out.push({ date: p.date, start: p.start, end: p.end });
			}
		});
		return out;
	}

	function dayDiff(a, b) {
		var pa = a.split('-');
		var pb = b.split('-');
		return Math.round((Date.UTC(pb[0], pb[1] - 1, pb[2]) - Date.UTC(pa[0], pa[1] - 1, pa[2])) / 86400000);
	}

	function evaluate($booking) {
		var s = settings($booking);
		var mode = $booking.find('input[name="evercnc_mode"]:checked').val() || '';
		$booking.find('.evercnc-mode').each(function () {
			$(this).toggleClass('evercnc-mode--active', $(this).find('input').is(':checked'));
		});
		$booking.find('[data-tip="T4"]').prop('hidden', mode !== 'now');
		$booking.find('.evercnc-later').prop('hidden', mode !== 'later');

		var periods = [];
		$booking.find('.evercnc-period:not([hidden])').each(function () {
			var $row = $(this);
			updateRowOptions($row, s);
			var d = rowData($row);
			if (d.status === 'complete' && d.end > d.start) {
				periods.push(d);
			}
		});
		var merged = merge(periods);
		var empty = merged.length === 0;
		var wide = false;
		if (!empty) {
			if (s.spanOn && dayDiff(merged[0].date, merged[merged.length - 1].date) > s.maxSpan) {
				wide = true;
			}
			var total = 0;
			$.each(merged, function (i, p) {
				total += p.end - p.start;
			});
			if (s.durationOn && total > s.maxDuration) {
				wide = true;
			}
		}
		$booking.find('[data-tip="T5"]').prop('hidden', !(mode === 'later' && empty));
		$booking.find('[data-tip="T6"]').prop('hidden', !(mode === 'later' && wide));

		var visible = $booking.find('.evercnc-period:not([hidden])').length;
		$booking.find('.evercnc-add').prop('hidden', visible >= (s.maxPeriods || 3));
	}

	function collect($booking) {
		var periods = [];
		$booking.find('.evercnc-period:not([hidden])').each(function () {
			var d = rowData($(this));
			periods.push($.extend({ date: d.date }, d.values));
		});
		return periods;
	}

	function save() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			var $wrap = $(root);
			var $booking = $wrap.find('.evercnc-booking');
			var idStore = activeStoreId();
			if (!$wrap.length || !idStore) {
				return;
			}
			$.ajax({
				type: 'POST',
				url: $wrap.data('evercncurl'),
				cache: false,
				dataType: 'json',
				data: {
					action: 'SaveShippingStore',
					ajax: true,
					everclickncollect_id: idStore,
					evercnc_mode: $booking.find('input[name="evercnc_mode"]:checked').val() || '',
					evercnc_periods: collect($booking)
				}
			});
		}, 300);
	}

	function init() {
		$(root + ' .evercnc-booking').each(function () {
			evaluate($(this));
		});
	}

	$(document).on('change', root + ' .evercnc-store-radio', function () {
		var id = activeStoreId();
		$(root + ' .evercnc-store').each(function () {
			$(this).toggleClass('evercnc-store--active', String($(this).data('idstore')) === id);
		});
		save();
	});

	$(document).on('change', root + ' input[name="evercnc_mode"], ' + root + ' .evercnc-period select[data-part]', function () {
		evaluate($(this).closest('.evercnc-booking'));
		save();
	});

	/* New date: keep the times when they are still possible that day, else take the day's default times */
	$(document).on('change', root + ' .evercnc-p-date', function () {
		var $booking = $(this).closest('.evercnc-booking');
		var s = settings($booking);
		var $row = $(this).closest('.evercnc-period');
		var d = rowData($row);
		if (d.status === 'complete') {
			var info = dayInfo(s, d.date);
			if (!validStart(d.start, info.ranges, s.step, minStartOf(s, d.date)) || !validEnd(d.end, info.ranges, s.step, d.start)) {
				applyDefaults($row, s);
			}
		}
		evaluate($booking);
		save();
	});

	$(document).on('click', root + ' .evercnc-add', function (e) {
		e.preventDefault();
		var $booking = $(this).closest('.evercnc-booking');
		var $row = $booking.find('.evercnc-period[hidden]').first();
		if (!$row.length) {
			return;
		}
		$row.prop('hidden', false).find('select').prop('disabled', false);
		// Default: the day after the last chosen day, whole pickup hours of that day
		var last = '';
		$booking.find('.evercnc-period:not([hidden])').not($row).each(function () {
			var date = String($(this).find('.evercnc-p-date').val() || '');
			if (date > last) {
				last = date;
			}
		});
		var $options = $row.find('.evercnc-p-date option');
		var $next = $options.filter(function () {
			return this.value > last;
		}).first();
		$row.find('.evercnc-p-date').val(($next.length ? $next : $options.last()).val());
		applyDefaults($row, settings($booking));
		evaluate($booking);
		save();
		$row.find('.evercnc-p-date').trigger('focus');
	});

	$(document).on('click', root + ' .evercnc-p-remove', function (e) {
		e.preventDefault();
		var $booking = $(this).closest('.evercnc-booking');
		var $row = $(this).closest('.evercnc-period');
		$row.find('select[data-part]').val('');
		$row.prop('hidden', true).find('select').prop('disabled', true);
		evaluate($booking);
		save();
	});

	$(init);
	if (typeof prestashop !== 'undefined' && prestashop.on) {
		// The shipping step can be re-rendered without a page load
		prestashop.on('updatedDeliveryForm', init);
	}
})(jQuery);
