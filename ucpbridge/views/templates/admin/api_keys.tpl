{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
<div class="panel" id="ucpbridge-api-keys">
  <h3><i class="icon-key"></i> Plateformes (clés API, en-tête Authorization: Bearer)</h3>
  <p>Une clé par plateforme : chaque plateforme ne voit que ses propres paniers, sessions de caisse et commandes, et ses clés
    d'idempotence ne se mélangent pas à celles des autres. Ne jamais remettre la même clé à deux plateformes.</p>
  {if $ucp_keys}
    <table class="table">
      <thead><tr><th>Identifiant</th><th>Plateforme</th><th>Clé API</th><th>Créée le</th><th></th></tr></thead>
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
              <button type="submit" class="btn btn-default" name="submitUcpbridgeApiKeyRegen" value="1"><i class="icon-refresh"></i> Régénérer</button>
              <button type="submit" class="btn btn-default" name="submitUcpbridgeApiKeyDelete" value="1"
                onclick="return confirm('Supprimer la clé de cette plateforme ?')"><i class="icon-trash"></i> Supprimer</button>
            </form>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  {else}
    <p>Aucune clé : le mode invité est fermé.</p>
  {/if}
</div>
