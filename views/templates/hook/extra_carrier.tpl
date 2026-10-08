{*
 * 2019-2023 Team Ever
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 *  @author    Team Ever <https://www.team-ever.com/>
 *  @copyright 2019-2023 Team Ever
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*}
<div class="evercnc col-12" id="everclickncollect_id"
     data-evercncurl="{$ajax_url|escape:'htmlall':'UTF-8'}"
     data-evercnccarrier="{$everclickncollect_id|escape:'htmlall':'UTF-8'}"
     data-maxselected="{$max_selected|intval}"
     data-msg-max="{l s='You can choose up to %d time slots.' sprintf=[$max_selected|intval] mod='everpsclickandcollect'}">
	{if isset($custom_msg) && $custom_msg}
	<div id="everclickncollect_msg" class="evercnc-msg">
		{$custom_msg nofilter}
	</div>
	{/if}

	{foreach from=$stores item=store}
	{assign var=sid value=$store.id_store|intval}
	<div class="evercnc-store{if $store.selected} evercnc-store--active{/if}" data-idstore="{$sid}">
		<label class="evercnc-store__head">
			{if $only_one}
				<input type="hidden" name="everpsclickandcollect" value="{$sid}">
			{else}
				<input type="radio" name="everpsclickandcollect" class="evercnc-store-radio" value="{$sid}" {if $store.selected}checked{/if}>
			{/if}
			{if isset($show_store_img) && $show_store_img && isset($store.image.bySize.stores_default.url)}
				<img class="evercnc-store__img" src="{$store.image.bySize.stores_default.url|escape:'htmlall':'UTF-8'}" alt="{$store.image.legend|escape:'htmlall':'UTF-8'}">
			{/if}
			<span class="evercnc-store__info">
				<strong>{$store.name|escape:'htmlall':'UTF-8'}</strong><br>
				<small>{$store.address.formatted nofilter}</small>
			</span>
		</label>

		{if $ask_date}
		<div class="evercnc-booking"{if !$store.selected} style="display:none"{/if}>
			{if !$store.pickup_days}
				<p class="alert alert-warning">{l s='No pickup time slot is available for this store at the moment.' mod='everpsclickandcollect'}</p>
			{else}
				{assign var=current_date value=''}
				{foreach from=$store.pickup_days item=day}
					{if $store.selected && $day.date == $selected_date}{assign var=current_date value=$day.date}{/if}
				{/foreach}
				{if !$current_date}{assign var=current_date value=$store.pickup_days[0].date}{/if}

				<label class="evercnc-label" for="evercnc_date_{$sid}">{l s='Pickup date' mod='everpsclickandcollect'}</label>
				<select class="form-control evercnc-date" id="evercnc_date_{$sid}" name="evercnc_date[{$sid}]">
					{foreach from=$store.pickup_days item=day}
					<option value="{$day.date|escape:'htmlall':'UTF-8'}" {if $day.date == $current_date}selected{/if}>{$day.label|escape:'htmlall':'UTF-8'}</option>
					{/foreach}
				</select>

				<p class="evercnc-label">{l s='Pickup time (you can select several slots)' mod='everpsclickandcollect'}</p>
				{foreach from=$store.pickup_days item=day}
				<div class="evercnc-slots" data-date="{$day.date|escape:'htmlall':'UTF-8'}"{if $day.date != $current_date} style="display:none"{/if}>
					{foreach from=$day.slots item=slot}
					<label class="evercnc-slot{if $slot.full} evercnc-slot--full{/if}">
						<input type="checkbox"
							name="evercnc_slots[{$sid}][]"
							value="{$slot.value|escape:'htmlall':'UTF-8'}"
							{if $slot.full || $day.date != $current_date}disabled{/if}
							{if $slot.full}data-full="1"{/if}
							{if $store.selected && $day.date == $selected_date && isset($selected_slots[$slot.value])}checked{/if}>
						<span><span class="evercnc-slot__time">{$slot.label|escape:'htmlall':'UTF-8'}</span>{if $slot.full} <em>{l s='full' mod='everpsclickandcollect'}</em>{/if}</span>
					</label>
					{/foreach}
				</div>
				{/foreach}
				<p class="evercnc-warning alert alert-danger" style="display:none"></p>
			{/if}
		</div>
		{/if}
	</div>
	{/foreach}
</div>
