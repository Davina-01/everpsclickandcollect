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

	{if !$ask_date && isset($custom_msg) && $custom_msg}
	<div class="evercnc-msg">{$custom_msg nofilter}</div>
	{/if}

	<p class="evercnc-title">{l s='Please choose a pickup location' mod='everpsclickandcollect'}</p>
	<div class="evercnc-stores">
		{foreach from=$stores item=store}
		{assign var=sid value=$store.id_store|intval}
		<label class="evercnc-store{if $store.selected} evercnc-store--active{/if}" data-idstore="{$sid}">
			{if $only_one}
				<input type="hidden" name="everpsclickandcollect" value="{$sid}">
			{else}
				<input type="radio" name="everpsclickandcollect" class="evercnc-store-radio" value="{$sid}" {if $store.selected}checked{/if}>
			{/if}
			{if isset($show_store_img) && $show_store_img && isset($store.image.bySize.stores_default.url)}
				<img class="evercnc-store__img" src="{$store.image.bySize.stores_default.url|escape:'htmlall':'UTF-8'}" alt="{$store.image.legend|escape:'htmlall':'UTF-8'}">
			{/if}
			<span class="evercnc-store__info">
				<strong>{$store.name|escape:'htmlall':'UTF-8'}</strong>
				<small>{$store.address.formatted|replace:'<br />':', ' nofilter}</small>
			</span>
		</label>
		{/foreach}
	</div>

	{if $ask_date}
	{foreach from=$stores item=store}
	{assign var=sid value=$store.id_store|intval}
	<div class="evercnc-booking" data-idstore="{$sid}"{if !$store.selected} hidden{/if}>
		<p class="evercnc-title">{l s='Please choose date and time to pick up' mod='everpsclickandcollect'}</p>
		{if !$store.pickup_days}
			<p class="alert alert-warning">{l s='No pickup time slot is available for this store at the moment.' mod='everpsclickandcollect'}</p>
		{else}
			{* Open the first day that already has a selection, else the first day *}
			{assign var=active_date value=$store.pickup_days[0].date}
			{assign var=found value=false}
			{foreach from=$store.pickup_days item=day}
				{if !$found && $store.selected}
					{foreach from=$day.slots item=slot}
						{assign var=entry_key value="`$day.date`|`$slot.value`"}
						{if !$found && isset($selected_entries[$entry_key])}{assign var=active_date value=$day.date}{assign var=found value=true}{/if}
					{/foreach}
				{/if}
			{/foreach}

			<div class="evercnc-tabs">
				<button type="button" class="evercnc-tabs__arrow" data-dir="-1" aria-label="{l s='Previous days' mod='everpsclickandcollect'}">&lsaquo;</button>
				<div class="evercnc-tabs__list" role="tablist">
					{foreach from=$store.pickup_days item=day}
					<button type="button" role="tab" class="evercnc-tab{if $day.date == $active_date} evercnc-tab--active{/if}"
						data-date="{$day.date|escape:'htmlall':'UTF-8'}" aria-selected="{if $day.date == $active_date}true{else}false{/if}">
						<span class="evercnc-tab__day">{$day.weekday|escape:'htmlall':'UTF-8'}</span>
						<span class="evercnc-tab__date">{$day.short|escape:'htmlall':'UTF-8'}</span>
						<span class="evercnc-tab__badge" hidden></span>
					</button>
					{/foreach}
				</div>
				<button type="button" class="evercnc-tabs__arrow" data-dir="1" aria-label="{l s='Next days' mod='everpsclickandcollect'}">&rsaquo;</button>
			</div>

			{foreach from=$store.pickup_days item=day}
			<div class="evercnc-panel" role="tabpanel" data-date="{$day.date|escape:'htmlall':'UTF-8'}"
				data-daylabel="{$day.label|escape:'htmlall':'UTF-8'}"{if $day.date != $active_date} hidden{/if}>
				<div class="evercnc-slots{if $period_mode} evercnc-slots--periods{/if}">
					{foreach from=$day.slots item=slot}
					{assign var=entry_key value="`$day.date`|`$slot.value`"}
					<label class="evercnc-slot{if $slot.past} evercnc-slot--past{elseif $slot.full} evercnc-slot--full{/if}">
						<input type="checkbox"
							name="evercnc_slots[{$sid}][]"
							value="{$entry_key|escape:'htmlall':'UTF-8'}"
							data-label="{if $slot.name}{$slot.name|escape:'htmlall':'UTF-8'} {/if}{$slot.label|escape:'htmlall':'UTF-8'}"
							{if $slot.full || $slot.past}disabled{/if}
							{if $store.selected && !$slot.full && !$slot.past && isset($selected_entries[$entry_key])}checked{/if}>
						<span>
							{if $slot.name}<strong class="evercnc-slot__name">{$slot.name|escape:'htmlall':'UTF-8'}</strong>{/if}
							<span class="evercnc-slot__time">{$slot.label|escape:'htmlall':'UTF-8'}</span>
						</span>
					</label>
					{/foreach}
				</div>
			</div>
			{/foreach}

			<ul class="evercnc-legend">
				<li><i class="evercnc-dot evercnc-dot--available"></i>{l s='Available' mod='everpsclickandcollect'}</li>
				<li><i class="evercnc-dot evercnc-dot--selected"></i>{l s='Selected' mod='everpsclickandcollect'}</li>
				<li><i class="evercnc-dot evercnc-dot--full"></i>{l s='Full' mod='everpsclickandcollect'}</li>
				<li><i class="evercnc-dot evercnc-dot--past"></i>{l s='Not available' mod='everpsclickandcollect'}</li>
			</ul>

			<div class="evercnc-summary">
				<strong>{l s='Your pickup time:' mod='everpsclickandcollect'}</strong>
				<span class="evercnc-summary__empty">{l s='No time slot selected yet.' mod='everpsclickandcollect'}</span>
				<ul class="evercnc-summary__list"></ul>
				<p class="evercnc-summary__hint">{l s='You can select slots on several days, for example today afternoon and tomorrow morning.' mod='everpsclickandcollect'}</p>
			</div>
			<p class="evercnc-warning alert alert-danger" hidden></p>
		{/if}

		{if isset($custom_msg) && $custom_msg}
		<div class="evercnc-msg evercnc-msg--below">{$custom_msg nofilter}</div>
		{/if}
	</div>
	{/foreach}
	{/if}
</div>
