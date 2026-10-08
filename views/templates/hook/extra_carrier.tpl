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
     data-evercnccarrier="{$everclickncollect_id|escape:'htmlall':'UTF-8'}"{if $evercnc_style} style="{$evercnc_style|escape:'htmlall':'UTF-8'}"{/if}>

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
	<div class="evercnc-booking" data-settings="{$pickup_js|escape:'htmlall':'UTF-8'}">
		<p class="evercnc-title">{l s='When will you pick up your order?' mod='everpsclickandcollect'}</p>

		<div class="evercnc-modes" role="radiogroup">
			{if $pickup_now_available}
			<label class="evercnc-mode{if $pickup_mode == 'now'} evercnc-mode--active{/if}">
				<input type="radio" name="evercnc_mode" value="now" {if $pickup_mode == 'now'}checked{/if}>
				<span class="evercnc-mode__body">
					<strong>{l s='Pick up now' mod='everpsclickandcollect'}</strong>
					<small>{l s='I am on my way, or I can come as soon as the order is ready (within %d minutes)' sprintf=[$pickup_now_limit|intval] mod='everpsclickandcollect'}</small>
				</span>
			</label>
			{/if}
			<label class="evercnc-mode{if $pickup_mode == 'later'} evercnc-mode--active{/if}">
				<input type="radio" name="evercnc_mode" value="later" {if $pickup_mode == 'later'}checked{/if}>
				<span class="evercnc-mode__body">
					<strong>{l s='Pick up later' mod='everpsclickandcollect'}</strong>
					<small>{l s='Tell us when you might come, so we can prepare your order' mod='everpsclickandcollect'}</small>
				</span>
			</label>
		</div>

		<div class="evercnc-later"{if $pickup_mode != 'later'} hidden{/if}>
			{if !$pickup_dates}
				<p class="alert alert-warning">{l s='No pickup date is available at the moment.' mod='everpsclickandcollect'}</p>
			{else}
			<p class="evercnc-label">{l s='When might you come? (optional, up to 3 time periods)' mod='everpsclickandcollect'}</p>
			<div class="evercnc-periods">
				{section name=row loop=$pickup_max_periods}
				{assign var=idx value=$smarty.section.row.index}
				{if isset($pickup_periods[$idx])}{assign var=p value=$pickup_periods[$idx]}{assign var=shown value=true}{else}{assign var=p value=['date' => '', 'sh' => '', 'sm' => '', 'eh' => '', 'em' => '']}{assign var=shown value=false}{/if}
				<div class="evercnc-period" data-index="{$idx}"{if !$shown} hidden{/if}>
					<select class="form-control evercnc-p-date" name="evercnc_periods[{$idx}][date]" aria-label="{l s='Date' mod='everpsclickandcollect'}"{if !$shown} disabled{/if}>
						{foreach from=$pickup_dates item=d}
						<option value="{$d.value|escape:'htmlall':'UTF-8'}"{if $d.value == $p.date} selected{/if}>{$d.label|escape:'htmlall':'UTF-8'}</option>
						{/foreach}
					</select>
					<span class="evercnc-time">
						<select class="form-control evercnc-p-h" data-part="sh" name="evercnc_periods[{$idx}][sh]" aria-label="{l s='From (hour)' mod='everpsclickandcollect'}"{if !$shown} disabled{/if}>
							<option value="">--</option>
							{foreach from=$pickup_hours item=h}<option value="{$h|intval}"{if $p.sh !== '' && $p.sh == $h} selected{/if}>{$h|string_format:'%02d'}</option>{/foreach}
						</select><span class="evercnc-colon">:</span><select class="form-control evercnc-p-m" data-part="sm" name="evercnc_periods[{$idx}][sm]" aria-label="{l s='From (minutes)' mod='everpsclickandcollect'}"{if !$shown} disabled{/if}>
							<option value="">--</option>
							{foreach from=$pickup_minutes item=m}{assign var=mm value=$m|string_format:'%02d'}<option value="{$mm}"{if $p.sm === $mm} selected{/if}>{$mm}</option>{/foreach}
						</select>
					</span>
					<span class="evercnc-sep">–</span>
					<span class="evercnc-time">
						<select class="form-control evercnc-p-h" data-part="eh" name="evercnc_periods[{$idx}][eh]" aria-label="{l s='To (hour)' mod='everpsclickandcollect'}"{if !$shown} disabled{/if}>
							<option value="">--</option>
							{foreach from=$pickup_hours item=h}<option value="{$h|intval}"{if $p.eh !== '' && $p.eh == $h} selected{/if}>{$h|string_format:'%02d'}</option>{/foreach}
						</select><span class="evercnc-colon">:</span><select class="form-control evercnc-p-m" data-part="em" name="evercnc_periods[{$idx}][em]" aria-label="{l s='To (minutes)' mod='everpsclickandcollect'}"{if !$shown} disabled{/if}>
							<option value="">--</option>
							{foreach from=$pickup_minutes item=m}{assign var=mm value=$m|string_format:'%02d'}<option value="{$mm}"{if $p.em === $mm} selected{/if}>{$mm}</option>{/foreach}
						</select>
					</span>
					{if $idx > 0}
					<button type="button" class="evercnc-p-remove" aria-label="{l s='Remove this time period' mod='everpsclickandcollect'}" title="{l s='Remove this time period' mod='everpsclickandcollect'}">&times;</button>
					{/if}
				</div>
				{/section}
			</div>
			<button type="button" class="evercnc-add">+ {l s='Add another time you might come' mod='everpsclickandcollect'}</button>
			<p class="evercnc-error alert alert-danger" hidden></p>
			{if $pickup_texts.T5}
			<div class="evercnc-alert" role="status" data-tip="T5" hidden>{$pickup_texts.T5 nofilter}</div>
			{/if}
			{if $pickup_texts.T6}
			<div class="evercnc-alert" role="status" data-tip="T6" hidden>{$pickup_texts.T6 nofilter}</div>
			{/if}
			{/if}
		</div>

		{if $pickup_texts.NOTE || $pickup_texts.T2 || $pickup_texts.T1}
		<div class="evercnc-notes">
			{if $pickup_texts.NOTE}<p class="evercnc-notes__title">{$pickup_texts.NOTE nofilter}</p>{/if}
			{if $pickup_texts.T2}<p>{$pickup_texts.T2 nofilter}</p>{/if}
			{if $pickup_texts.T1}<p>{$pickup_texts.T1 nofilter}</p>{/if}
		</div>
		{/if}
		<p class="evercnc-msg-end" hidden
			data-msg-order="{l s='The end time must be later than the start time.' mod='everpsclickandcollect'}"
			data-msg-incomplete="{l s='Please complete the start and end time of each time period, or leave it empty.' mod='everpsclickandcollect'}"></p>
	</div>
	{/if}
</div>
