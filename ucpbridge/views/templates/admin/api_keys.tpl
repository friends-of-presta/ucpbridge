{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
<div class="panel" id="ucpbridge-api-keys">
  <h3><i class="icon-key"></i> {l s='Platforms (API keys, Authorization: Bearer header)' d='Modules.Ucpbridge.Admin'}</h3>
  <p>{l s='One key per platform: each platform only sees its own carts, checkout sessions and orders, and its idempotency keys never mix with those of the others. Never give the same key to two platforms.' d='Modules.Ucpbridge.Admin'}</p>
  {if $ucp_keys}
    <table class="table">
      <thead><tr><th>{l s='Identifier' d='Modules.Ucpbridge.Admin'}</th><th>{l s='Platform' d='Modules.Ucpbridge.Admin'}</th><th>{l s='API key' d='Modules.Ucpbridge.Admin'}</th><th>{l s='Created on' d='Modules.Ucpbridge.Admin'}</th><th></th></tr></thead>
      <tbody>
      {foreach $ucp_keys as $id => $k}
        <tr>
          <td><code>{$id|escape:'html':'UTF-8'}</code></td>
          <td>{if isset($k.label)}{$k.label|escape:'html':'UTF-8'}{/if}</td>
          <td><code>{if isset($k.key)}{$k.key|escape:'html':'UTF-8'}{/if}</code></td>
          <td>{if isset($k.date_add)}{$k.date_add|escape:'html':'UTF-8'}{/if}</td>
          <td class="text-right">
            <form method="post" class="form-inline">
              <input type="hidden" name="ucp_csrf" value="{$ucp_csrf|escape:'html':'UTF-8'}">
              <input type="hidden" name="api_key_id" value="{$id|escape:'html':'UTF-8'}">
              <button type="submit" class="btn btn-default" name="submitUcpbridgeApiKeyRegen" value="1"><i class="icon-refresh"></i> {l s='Regenerate' d='Modules.Ucpbridge.Admin'}</button>
              <button type="submit" class="btn btn-default" name="submitUcpbridgeApiKeyDelete" value="1"
                onclick="return confirm('{l s='Delete the key of this platform?' d='Modules.Ucpbridge.Admin' js=1}')"><i class="icon-trash"></i> {l s='Delete' d='Modules.Ucpbridge.Admin'}</button>
            </form>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  {else}
    <p>{l s='No key: guest mode is closed.' d='Modules.Ucpbridge.Admin'}</p>
  {/if}
</div>
