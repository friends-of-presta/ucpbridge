{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
<div class="panel" id="ucpbridge-evidence">
  <h3><i class="icon-file-text"></i> {l s='Evidence log and withdrawals' d='Modules.Ucpbridge.Admin'}</h3>
  <p>{l s='%proofs% order evidence record(s), %withdrawals% withdrawal request(s). Kept on uninstallation (with the payment log) and found again on reinstallation, unless chosen otherwise in the Uninstallation section.' d='Modules.Ucpbridge.Admin' sprintf=['%proofs%' => $ucp_proofs|intval, '%withdrawals%' => $ucp_withdrawals|intval]}</p>
  <p><a id="ucpbridge-export" class="btn btn-default" href="{$ucp_export_url|escape:'html':'UTF-8'}"><i class="icon-download"></i>
    {l s='Export the evidence and withdrawals (CSV)' d='Modules.Ucpbridge.Admin'}</a></p>
</div>
