/**
 * Ever PS Click And Collect - pickup store, dates and time slots on checkout
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
(function ($) {
	'use strict';

	var root = '#everclickncollect_id';

	function activeStoreId() {
		var $wrap = $(root);
		var $radio = $wrap.find('.evercnc-store-radio:checked');
		if ($radio.length) {
			return String($radio.val());
		}
		return String($wrap.find('input[name="everpsclickandcollect"]').first().val() || '');
	}

	function activeBooking() {
		return $(root).find('.evercnc-booking[data-idstore="' + activeStoreId() + '"]');
	}

	function refresh($booking) {
		// Badges on day tabs + summary of all selected days
		var groups = {};
		var order = [];
		$booking.find('.evercnc-panel').each(function () {
			var $panel = $(this);
			var date = String($panel.data('date'));
			var $checked = $panel.find('input[type=checkbox]:checked');
			var $badge = $booking.find('.evercnc-tab[data-date="' + date + '"] .evercnc-tab__badge');
			if ($checked.length) {
				$badge.text($checked.length).prop('hidden', false);
				groups[date] = { label: $panel.data('daylabel'), slots: [] };
				order.push(date);
				$checked.each(function () {
					groups[date].slots.push($(this).data('label'));
				});
			} else {
				$badge.text('').prop('hidden', true);
			}
		});
		var $list = $booking.find('.evercnc-summary__list').empty();
		$.each(order, function (i, date) {
			$('<li>')
				.append($('<span class="evercnc-summary__day">').text(groups[date].label + ' : '))
				.append(document.createTextNode(groups[date].slots.join(', ')))
				.appendTo($list);
		});
		$booking.find('.evercnc-summary__empty').prop('hidden', order.length > 0);
	}

	function save() {
		var $wrap = $(root);
		var $booking = activeBooking();
		var idStore = activeStoreId();
		if (!$wrap.length || !idStore) {
			return;
		}
		var slots = [];
		$booking.find('input[type=checkbox]:checked').each(function () {
			slots.push($(this).val());
		});
		$.ajax({
			type: 'POST',
			url: $wrap.data('evercncurl'),
			cache: false,
			dataType: 'json',
			data: {
				action: 'SaveShippingStore',
				ajax: true,
				everclickncollect_id: idStore,
				everclickncollect_slots: slots
			},
			success: function (data) {
				var $warning = $booking.find('.evercnc-warning');
				if (data && data.warning) {
					$warning.text(data.warning).prop('hidden', false);
				} else {
					$warning.prop('hidden', true);
				}
			}
		});
	}

	function showDay($booking, date) {
		$booking.find('.evercnc-tab').each(function () {
			var active = String($(this).data('date')) === date;
			$(this).toggleClass('evercnc-tab--active', active).attr('aria-selected', active ? 'true' : 'false');
		});
		$booking.find('.evercnc-panel').each(function () {
			$(this).prop('hidden', String($(this).data('date')) !== date);
		});
	}

	function init() {
		$(root + ' .evercnc-booking').each(function () {
			refresh($(this));
		});
	}

	$(document).on('change', root + ' .evercnc-store-radio', function () {
		var id = activeStoreId();
		$(root + ' .evercnc-store').each(function () {
			$(this).toggleClass('evercnc-store--active', String($(this).data('idstore')) === id);
		});
		$(root + ' .evercnc-booking').each(function () {
			$(this).prop('hidden', String($(this).data('idstore')) !== id);
		});
		save();
	});

	$(document).on('click', root + ' .evercnc-tab', function (e) {
		e.preventDefault();
		var $tab = $(this);
		showDay($tab.closest('.evercnc-booking'), String($tab.data('date')));
		var list = $tab.closest('.evercnc-tabs__list')[0];
		if (list && this.scrollIntoView) {
			var left = this.offsetLeft - list.offsetLeft;
			if (left < list.scrollLeft || left + this.offsetWidth > list.scrollLeft + list.clientWidth) {
				list.scrollLeft = left - 8;
			}
		}
	});

	$(document).on('click', root + ' .evercnc-tabs__arrow', function (e) {
		e.preventDefault();
		var list = $(this).siblings('.evercnc-tabs__list')[0];
		if (list) {
			list.scrollLeft += parseInt($(this).data('dir'), 10) * Math.max(list.clientWidth * 0.8, 120);
		}
	});

	$(document).on('change', root + ' .evercnc-slots input[type=checkbox]', function () {
		var $wrap = $(root);
		var $booking = $(this).closest('.evercnc-booking');
		var max = parseInt($wrap.data('maxselected'), 10) || 0;
		if (max > 0 && $booking.find('input[type=checkbox]:checked').length > max) {
			$(this).prop('checked', false);
			$booking.find('.evercnc-warning').text($wrap.data('msg-max')).prop('hidden', false);
			return;
		}
		refresh($booking);
		save();
	});

	$(init);
	if (typeof prestashop !== 'undefined' && prestashop.on) {
		// The shipping step can be re-rendered without a page load
		prestashop.on('updatedDeliveryForm', init);
	}
})(jQuery);
