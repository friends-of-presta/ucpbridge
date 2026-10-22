{* Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE. *}
<div class="panel" id="ucpbridge-evidence">
  <h3><i class="icon-file-text"></i> Journal de preuve et rétractations</h3>
  <p>{$ucp_proofs|intval} preuve(s) de commande, {$ucp_withdrawals|intval} demande(s) de rétractation. Conservés à la désinstallation
    (avec le journal des paiements) et retrouvés à la réinstallation, sauf choix contraire dans la section Désinstallation.</p>
  <p><a id="ucpbridge-export" class="btn btn-default" href="{$ucp_export_url|escape:'html':'UTF-8'}"><i class="icon-download"></i>
    Exporter les preuves et rétractations (CSV)</a></p>
</div>
