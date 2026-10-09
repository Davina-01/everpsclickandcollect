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
<div class="card mt-2 everpsclickandcollect-order" id="evercnc-pickup">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-header-title mb-0">
            <i class="material-icons">store</i>
            {l s='Click & collect pickup' mod='everpsclickandcollect'}
        </h3>
        {if $pickup.mode && !$pickup.prepare}
        <span class="badge badge-warning" style="font-size:0.85rem;">{l s='Prepare on arrival' mod='everpsclickandcollect'}</span>
        {/if}
    </div>
    <div class="card-body">
        {if $pickup_flash}<div class="alert alert-success">{$pickup_flash|escape:'htmlall':'UTF-8'}</div>{/if}
        {if $pickup_flash_error}<div class="alert alert-danger">{$pickup_flash_error|escape:'htmlall':'UTF-8'}</div>{/if}
        <table class="table">
            <tbody>
                <tr>
                    <th style="width:30%;">{l s='Store name' mod='everpsclickandcollect'}</th>
                    <td><strong>{$store.name|escape:'htmlall':'UTF-8'}</strong></td>
                </tr>
                <tr>
                    <th>{l s='Pickup time' mod='everpsclickandcollect'}</th>
                    <td>
                        {if $pickup.admin_text}
                            <strong>{$pickup.admin_text|escape:'htmlall':'UTF-8'}</strong>
                        {else}
                            <span class="text-muted">{l s='Not chosen' mod='everpsclickandcollect'}</span>
                        {/if}
                    </td>
                </tr>
            </tbody>
        </table>

        <details class="mt-2">
            <summary class="btn btn-outline-secondary btn-sm">{l s='Change pickup time' mod='everpsclickandcollect'}</summary>
            <form method="post" action="{$pickup_edit_url|escape:'htmlall':'UTF-8'}" class="mt-3">
                <div class="form-group">
                    <label class="mr-3"><input type="radio" name="evercnc_mode" value="now"{if $pickup_edit_mode == 'now'} checked{/if}> {l s='Pick up now' mod='everpsclickandcollect'}</label>
                    <label><input type="radio" name="evercnc_mode" value="later"{if $pickup_edit_mode != 'now'} checked{/if}> {l s='Pick up later' mod='everpsclickandcollect'}</label>
                </div>
                <p class="text-muted small mb-2">{l s='For "Pick up later": 1 to 3 time periods, leave a line empty to remove it.' mod='everpsclickandcollect'}</p>
                {foreach from=$pickup_edit_periods item=p key=i}
                <div class="form-inline mb-2">
                    <input type="date" class="form-control mr-2" name="evercnc_periods[{$i|string_format:'%d'}][date]" value="{$p.date|escape:'htmlall':'UTF-8'}">
                    <select class="form-control mr-1" name="evercnc_periods[{$i|string_format:'%d'}][start]">
                        <option value="">--:--</option>
                        {foreach from=$pickup_times item=t}<option value="{$t|escape:'htmlall':'UTF-8'}"{if $t == $p.start} selected{/if}>{$t|escape:'htmlall':'UTF-8'}</option>{/foreach}
                    </select>
                    <span class="mx-1">–</span>
                    <select class="form-control" name="evercnc_periods[{$i|string_format:'%d'}][end]">
                        <option value="">--:--</option>
                        {foreach from=$pickup_times item=t}<option value="{$t|escape:'htmlall':'UTF-8'}"{if $t == $p.end} selected{/if}>{$t|escape:'htmlall':'UTF-8'}</option>{/foreach}
                    </select>
                </div>
                {/foreach}
                <button type="submit" name="submitEvercncPickup" value="1" class="btn btn-primary btn-sm">{l s='Save' mod='everpsclickandcollect'}</button>
            </form>
        </details>
    </div>
</div>
