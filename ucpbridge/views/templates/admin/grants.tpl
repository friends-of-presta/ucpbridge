{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
<div class="panel" id="ucpbridge-grants">
  <h3><i class="icon-link"></i> Accès agents actifs</h3>
  {if $ucp_grants}
    <table class="table">
      <thead><tr><th>Assistant</th><th>Client</th><th>Relié le</th><th>Valable jusqu'au</th><th></th></tr></thead>
      <tbody>
      {foreach $ucp_grants as $g}
        <tr>
          <td>{$g.client_name|escape:'html':'UTF-8'} <code>{$g.client_id|escape:'html':'UTF-8'}</code></td>
          <td>{$g.email|escape:'html':'UTF-8'}</td>
          <td>{$g.first_grant|escape:'html':'UTF-8'}</td>
          <td>{$g.expires_at|escape:'html':'UTF-8'}</td>
          <td class="text-right">
            <form method="post">
              <input type="hidden" name="ucp_csrf" value="{$ucp_csrf|escape:'html':'UTF-8'}">
              <input type="hidden" name="revoke_client" value="{$g.client_id|escape:'html':'UTF-8'}">
              <input type="hidden" name="revoke_customer" value="{$g.id_customer|intval}">
              <button type="submit" class="btn btn-default" name="submitUcpbridgeRevoke" value="1"><i class="icon-ban"></i> Révoquer</button>
            </form>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  {else}
    <p>Aucun assistant relié à un compte client.</p>
  {/if}
</div>
