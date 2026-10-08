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
<div class="card mt-2 everpsclickandcollect-order">
    <div class="card-header">
        <h3 class="card-header-title mb-0">
            <i class="material-icons">store</i>
            {l s='Click & collect pickup' mod='everpsclickandcollect'}
        </h3>
    </div>
    <div class="card-body">
        <table class="table">
            <tbody>
                <tr>
                    <th>{l s='Store name' mod='everpsclickandcollect'}</th>
                    <td><strong>{$store.name|escape:'htmlall':'UTF-8'}</strong></td>
                </tr>
                <tr>
                    <th>{l s='Store address' mod='everpsclickandcollect'}</th>
                    <td>{$store.address.formatted nofilter}</td>
                </tr>
                {if $pickup.mode}
                <tr>
                    <th>{l s='Pickup time' mod='everpsclickandcollect'}</th>
                    <td>
                        {if $pickup.title}<strong>{$pickup.title|escape:'htmlall':'UTF-8'}</strong>{/if}
                        {foreach from=$pickup.lines item=line}
                        <div>{$line|escape:'htmlall':'UTF-8'}</div>
                        {/foreach}
                    </td>
                </tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>
