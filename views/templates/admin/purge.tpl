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
<div class="panel" id="evercnc-purge">
    <h3><i class="icon icon-trash"></i> {l s='Delete all module data' mod='everpsclickandcollect'}</h3>
    <p>{l s='Uninstalling the module keeps its data (pickup choices of all orders, store stock, settings) and deactivates its carrier, so that a reinstall or an upgrade finds everything back.' mod='everpsclickandcollect'}</p>
    <p class="text-danger"><strong>{l s='This action uninstalls the module and permanently deletes the pickup store, date and time of every order, the store stock and every setting of the module. It cannot be undone. Orders and stores themselves are not deleted, and the carrier is only marked as deleted so that orders keep it.' mod='everpsclickandcollect'}</strong></p>
    <p>{l s='Make a database backup first.' mod='everpsclickandcollect'}</p>
    <form method="post" action="{$evercnc_purge_url|escape:'htmlall':'UTF-8'}">
        <div class="checkbox">
            <label><input type="checkbox" name="evercnc_purge_confirm" value="1"> {l s='I understand that this data will be deleted permanently.' mod='everpsclickandcollect'}</label>
        </div>
        <div class="form-group">
            <label for="evercnc_purge_word">{l s='Type DELETE to confirm' mod='everpsclickandcollect'}</label>
            <input type="text" id="evercnc_purge_word" name="evercnc_purge_word" value="" autocomplete="off" class="form-control fixed-width-lg">
        </div>
        <button type="submit" name="submitEvercncPurge" value="1" class="btn btn-danger">{l s='Delete all module data and uninstall' mod='everpsclickandcollect'}</button>
    </form>
</div>
