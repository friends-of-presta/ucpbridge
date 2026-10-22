{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
{if $ucp_report}
<ul id="{$ucp_report_id|escape:'html':'UTF-8'}">
  {foreach $ucp_report as $line}
    <li>{if $line.code}<code>{$line.code|escape:'html':'UTF-8'}</code> : {/if}{$line.text|escape:'html':'UTF-8'}</li>
  {/foreach}
</ul>
{/if}
