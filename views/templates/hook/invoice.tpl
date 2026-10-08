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
<table style="width:100%; border:1px solid #ccc;" cellpadding="4">
    <tr>
        <td colspan="2" style="background-color:#f0f0f0; font-weight:bold;">{l s='Click & collect pickup' mod='everpsclickandcollect'}</td>
    </tr>
    <tr>
        <td style="width:30%;">{l s='Store name' mod='everpsclickandcollect'}</td>
        <td style="width:70%;">{$store.name|escape:'htmlall':'UTF-8'}<br>{$store.address.formatted nofilter}</td>
    </tr>
    {if $pickup_lines}
    <tr>
        <td>{l s='Pickup time' mod='everpsclickandcollect'}</td>
        <td>{foreach from=$pickup_lines item=line}<b>{$line.date|escape:'htmlall':'UTF-8'}</b>{if $line.slots} : {$line.slots|escape:'htmlall':'UTF-8'}{/if}<br>{/foreach}</td>
    </tr>
    {/if}
</table>
