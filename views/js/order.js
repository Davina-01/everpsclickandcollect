/**
 * Ever PS Click And Collect - pickup store, date and time slots on checkout
 *
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
(function ($) {
	'use strict';

	var root = '#everclickncollect_id';

	function activeStore() {
		var $wrap = $(root);
		var $radio = $wrap.find('.evercnc-store-radio:checked');
		if ($radio.length) {
			return $wrap.find('.evercnc-store[data-idstore="' + $radio.val() + '"]');
		}
		return $wrap.find('.evercnc-store').first();
	}

	function save() {
		var $wrap = $(root);
		var $store = activeStore();
		if (!$wrap.length || !$store.length) {
			return;
		}
		var slots = [];
		$store.find('.evercnc-slots:visible input[type=checkbox]:checked').each(function () {
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
				everclickncollect_id: $store.data('idstore'),
				everclickncollect_date: $store.find('.evercnc-date').val() || '',
				everclickncollect_slots: slots
			},
			success: function (data) {
				var $warning = $store.find('.evercnc-warning');
				if (data && data.warning) {
					$warning.text(data.warning).show();
				} else {
					$warning.hide();
				}
			}
		});
	}

	function showDate($store) {
		var date = $store.find('.evercnc-date').val();
		$store.find('.evercnc-slots').each(function () {
			var $slots = $(this);
			var visible = $slots.data('date') === date;
			$slots.toggle(visible);
			// Hidden days must not be submitted with the checkout form
			$slots.find('input[type=checkbox]').each(function () {
				$(this).prop('disabled', !visible || $(this).data('full') === 1);
				if (!visible) {
					$(this).prop('checked', false);
				}
			});
		});
	}

	$(document).on('change', root + ' .evercnc-store-radio', function () {
		$(root + ' .evercnc-store').removeClass('evercnc-store--active').find('.evercnc-booking').hide();
		var $store = activeStore();
		$store.addClass('evercnc-store--active').find('.evercnc-booking').show();
		save();
	});

	$(document).on('change', root + ' .evercnc-date', function () {
		showDate($(this).closest('.evercnc-store'));
		save();
	});

	$(document).on('change', root + ' .evercnc-slots input[type=checkbox]', function () {
		var $wrap = $(root);
		var max = parseInt($wrap.data('maxselected'), 10) || 0;
		var $store = $(this).closest('.evercnc-store');
		if (max > 0 && $store.find('.evercnc-slots input[type=checkbox]:checked').length > max) {
			$(this).prop('checked', false);
			$store.find('.evercnc-warning').text($wrap.data('msg-max')).show();
			return;
		}
		save();
	});
})(jQuery);
