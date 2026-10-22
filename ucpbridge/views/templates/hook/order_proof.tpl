{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
<div class="card mt-2" id="ucpbridge-proof">
  <div class="card-header"><h3 class="card-header-title">Preuve UCP : ce qui a été présenté à l'acheteur</h3></div>
  <div class="card-body">
    <table class="table">
      <tbody>
      {foreach $ucp_rows as $r}
        <tr><th style="width:30%">{$r.label|escape:'html':'UTF-8'}</th><td>{$r.value|escape:'html':'UTF-8'}</td></tr>
      {/foreach}
        <tr><th>Liens légaux</th><td><ul>
          {foreach $ucp_links as $l}
            <li>{$l.type|escape:'html':'UTF-8'} : <a href="{$l.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{$l.title|escape:'html':'UTF-8'}</a></li>
          {/foreach}
        </ul></td></tr>
        <tr><th>Mentions</th><td><ul>
          {foreach $ucp_disclosures as $d}
            <li>{$d.code|escape:'html':'UTF-8'} : {$d.content|escape:'html':'UTF-8'}</li>
          {/foreach}
        </ul></td></tr>
        <tr><th>Totaux présentés</th><td><ul>
          {foreach $ucp_totals as $t}
            <li>{$t.label|escape:'html':'UTF-8'} : {$t.amount|escape:'html':'UTF-8'}</li>
          {/foreach}
        </ul></td></tr>
      </tbody>
    </table>
  </div>
</div>
