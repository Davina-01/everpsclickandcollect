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

	/* Enable only the hours / minutes between min and max */
	function limitOptions($hour, $minute, min, max) {
		$hour.find('option').each(function () {
			if (this.value === '') {
				return;
			}
			var h = parseInt(this.value, 10);
			var ok = false;
			$minute.find('option').each(function () {
				if (this.value !== '') {
					var t = h * 60 + parseInt(this.value, 10);
					if (t >= min && t <= max) {
						ok = true;
					}
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
			if (hour === '' || hour === null) {
				this.disabled = false;
				return;
			}
			var t = parseInt(hour, 10) * 60 + parseInt(this.value, 10);
			this.disabled = t < min || t > max;
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

	function updateRowOptions($row, s) {
		var date = String($row.find('.evercnc-p-date').val() || '');
		var minStart = s.earliest;
		if (date === s.today) {
			minStart = Math.max(minStart, nowMinutes(s));
		}
		limitOptions(part($row, 'sh'), part($row, 'sm'), minStart, s.latest - s.step);
		var start = minutesOf(part($row, 'sh').val() || '', part($row, 'sm').val() || '');
		limitOptions(part($row, 'eh'), part($row, 'em'), (start === null ? minStart : start) + s.step, s.latest);
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

	$(document).on('change', root + ' input[name="evercnc_mode"], ' + root + ' .evercnc-period select', function () {
		evaluate($(this).closest('.evercnc-booking'));
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
		$row.find('select[data-part]').val('');
		$row.find('.evercnc-p-date').prop('selectedIndex', 0);
		evaluate($booking);
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
