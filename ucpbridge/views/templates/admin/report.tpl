{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
{if $ucp_report}
<ul id="{$ucp_report_id|escape:'html':'UTF-8'}"{if isset($ucp_report_mode)} data-ucp-mode="{$ucp_report_mode|escape:'html':'UTF-8'}"{/if}>
  {foreach $ucp_report as $line}
    <li data-ucp-code="{$line.key|escape:'html':'UTF-8'}">{if $line.code}<code>{$line.code|escape:'html':'UTF-8'}</code> : {/if}{$line.text|escape:'html':'UTF-8'}</li>
  {/foreach}
</ul>
{/if}
