# Journal des modifications

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0 (Academic Free License 3.0), voir LICENSE.

## 0.1.3-beta (non publiée)

Nouveautés

- Mise à jour sans désinstallation (#11) : scripts `upgrade/upgrade-X.Y.Z.php` lancés par PrestaShop (gestionnaire
  de modules ou `prestashop:module upgrade ucpbridge`), à partir de la 0.1.2. `upgrade-0.1.3.php` appelle
  `Ucpbridge::upgradeInPlace()` : tables, colonnes (`Ucpbridge::ADDED_COLUMNS`), hooks et clés de configuration
  manquants créés, lignes et valeurs existantes intactes (clé API, secret des liens signés, clients OAuth), règles
  `.htaccess` réinsérées ; rejouable. Nouvelle classe `UcpBridgeSchema`. La configuration d'installation est décrite
  une seule fois (`configDefaults()`), pour l'installation comme pour la mise à jour. Les corrections #1 à #4 ne
  changent aucune table : elles n'ajoutent que des clés au JSON `payload` des sessions
  (`charge_attempt`, `charge_unknown`), lues avec une valeur par défaut. Configuration : la clé unique
  `UCPBRIDGE_API_KEY` (#5) est migrée vers la plateforme `default` de `UCPBRIDGE_API_KEYS` en tête de la mise à jour,
  avant la création des clés manquantes (sinon une clé neuve aurait pris sa place). L'ancienne clé n'est supprimée
  qu'après l'enregistrement réussi de la nouvelle liste (`UcpBridgeApiKeys::migrateLegacy()`) : si l'écriture échoue,
  elle reste acceptée et la mise à jour échoue au lieu de couper la plateforme en place. Version du module : 0.1.3.
- Désinstallation (#12) : le journal de preuve (`ps_ucpbridge_proof`), les demandes de rétractation
  (`ps_ucpbridge_withdrawal`) et le journal des paiements (`ps_ucpbridge_payment`) sont conservés par défaut et
  retrouvés à la réinstallation (colonnes complétées si les tables datent d'une version plus ancienne). Suppression
  seulement sur choix explicite : case « Supprimer aussi... à la prochaine désinstallation » de la configuration
  (`UCPBRIDGE_UNINSTALL_PURGE`, 0 par défaut, effacée avec la configuration). Export CSV des preuves et rétractations
  depuis la configuration (`UcpBridgeExport`, jeton anti-CSRF du module) : UTF-8 avec BOM, séparateur `;`, une ligne
  par commande, champs JSON tels qu'enregistrés, valeurs commençant par `=`, `+`, `-` ou `@` neutralisées.
- RGPD (#13) : durées de conservation configurables par catégorie (`UCPBRIDGE_RETENTION_SESSIONS` 30 j,
  `_GUESTS` 30 j, `_OAUTH` 30 j, `_PAYMENTS` 3650 j, `_PROOFS` 3650 j ; 0 = jamais) et purge (`UcpBridgePurge`) en
  ligne de commande (`bin/purge.php`, `--dry-run`) et depuis la configuration (« Simuler la purge », « Purger
  maintenant »), avec compte rendu par catégorie, aussi consigné au journal PrestaShop. Sessions et paniers terminés ou
  expirés supprimés avec leur panier PrestaShop sans commande et leur adresse sans client ; sessions terminées par une
  commande gardées sans les données personnelles de l'acheteur ; clients invités créés par le module et sans aucune
  commande supprimés, ceux qui ont commandé jamais ; codes et jetons OAuth expirés ou révoqués ; journal des paiements,
  preuves et rétractations après 10 ans ; clés d'idempotence de plus de 24 h. Aucune commande touchée. Une session en
  échec (débit non remboursé ou incertain) et son acheteur invité suivent la durée du journal des paiements : jamais
  supprimés avant. Durées du journal des paiements et des preuves jamais sous 3650 jours (0 = jamais reste permis) :
  refus à l'enregistrement, et purge refusée sans rien supprimer si la configuration est en dessous. Compte rendu
  exact (paniers et adresses supprimés avec un invité comptés, simulation identique à la purge) ; sessions lues par
  lots, celles déjà minimisées écartées en SQL ; aucune commande vérifiée avant toute suppression. Nouvelle table
  `ps_ucpbridge_customer` (invités créés par le module, renseignée à la création de l'invité, reprise depuis les
  sessions à la mise à jour depuis la 0.1.2, conservée à la désinstallation avec les preuves).

Corrections

- API (#5) : une clé API par plateforme en mode invité (`UcpBridgeApiKeys`, configuration `UCPBRIDGE_API_KEYS`).
  L'identifiant de la plateforme entre dans l'identité de l'appelant (`platform:<id>`) : paniers, sessions de caisse,
  commandes et clés d'idempotence sont cloisonnés entre plateformes. Configuration du module : ajout, régénération et
  suppression des clés, sous jeton anti-CSRF. La clé unique `UCPBRIDGE_API_KEY` est migrée à la première lecture vers
  la plateforme `default`, qui garde l'identité `platform` (sessions en cours conservées). Le commentaire qui
  promettait ce cloisonnement sans le fournir est corrigé.
- OAuth (#4) : `redeemCode()` vérifie que le client authentifié est le propriétaire du code AVANT de le marquer
  utilisé. Un autre client enregistré qui détient le code reçoit `invalid_grant` (« Code inconnu »), sans brûler le
  code ni déclencher la révocation des jetons de son propriétaire ; le rejeu par le propriétaire révoque toujours.
- Paiement (#3) : la clé d'idempotence transmise au prestataire (`UcpBridgeIdempotency::chargeKey()`) inclut le
  montant en centimes, la devise et un numéro de tentative (`charge_attempt`, incrémenté après chaque refus
  définitif). Un panier modifié ou un nouvel essai avec la même carte après un refus n'est plus rejeté par Stripe
  (paramètres différents) ni ne reçoit le refus mis en cache 24 h.
- Paiement (#2) : un débit réussi est mémorisé (`$this->charged`) dès la réponse du prestataire, avant toute
  écriture au journal des paiements. Une erreur du journal (`ps_ucpbridge_payment`) n'interrompt plus la caisse :
  la commande est créée, l'échec du journal est signalé au journal PrestaShop (gravité 3) avec la référence du débit,
  et une exception ultérieure mène au remboursement automatique au lieu de faire croire que rien n'a été débité.
- Paiement (#1) : un débit au résultat inconnu (délai dépassé, connexion coupée, réponse 5xx sans charge, exception
  de l'adaptateur pendant le débit) n'est plus traité comme un refus. Nouveau statut `UcpBridgePaymentResult::UNKNOWN`
  (`UcpBridgePaymentResult::unknown()`) ; la session reste `complete_in_progress` avec `charge_started_at`, l'agent
  reçoit `payment_verification` et le rapprochement la classe en « débit incertain, à vérifier chez le prestataire ».
  Une erreur cURL survenue avant l'envoi (connexion refusée, nom d'hôte introuvable) reste un refus sans débit.

## 0.1.2-beta (2026-10-21) : première publication

- Licence AFL-3.0 (Academic Free License 3.0). Titulaire des droits : Friends of Presta. Auteur : Christophe Vidal,
  pour le podcast Contrib. Remplace la licence d'évaluation des versions de test.
- Auteur affiché dans le back-office : « Friends of Presta (Christophe Vidal) ».
- Code identique à la 0.1.2-test.

## 0.1.2-test (2026-10-21) : corrections de la deuxième relecture indépendante

Corrections

- Exception levée par `validateOrder` après la création de la commande (hook d'un module tiers, e-mail) : avant tout
  remboursement, le module cherche la commande (`currentOrder`, `Order::getIdByCartId`) ; si elle existe, aucun
  remboursement, la session est terminée (`completed`) avec cette commande et l'exception est journalisée en
  avertissement. Libellé de l'incident corrigé (« débit réussi mais commande non créée »).
- `update_checkout` concurrent de `complete` : la mise à jour réserve la session (`updating`, mise à jour
  conditionnelle) avant toute modification du panier PrestaShop ; une session réservée refuse la mise à jour en `409`
  avec son état réel (plus jamais de totaux d'une mise à jour non enregistrée) ; un `complete` pendant une mise à jour
  reçoit `409`. La session est relue après chaque réservation. Avant tout débit : panier non vide, conforme aux lignes
  de la session et montant strictement positif, sinon aucun débit.
- OAuth : rotation atomique du jeton de rafraîchissement (un seul gagnant sur N rafraîchissements simultanés) ; date
  de révocation par couple client OAuth / client final, enregistrée avant la révocation des jetons et vérifiée à
  chaque émission et à chaque lecture : un rafraîchissement concurrent ne fait plus survivre aucun jeton.
- Processus tué pendant la finalisation : commande de rapprochement `bin/reconcile.php` (ligne de commande, à
  planifier) et bouton « Rapprocher les sessions bloquées » dans la configuration : commande trouvée = session
  terminée ; débit sans commande = remboursement et incident ; débit incertain = incident à vérifier ; rien
  d'engagé = session rendue.
- Panier UCP (`create_cart`) figé côté API dès l'ouverture de son lien de reprise (`409 checkout_handed_off`), mise à
  jour du panier réservée elle aussi avant de toucher au panier PrestaShop.
- Licence : texte de licence d'évaluation cohérent, titulaire Christophe Vidal (personne physique), même mention dans
  tous les en-têtes ; auteur du module « Christophe Vidal ».
- Nom affiché : « UCP Bridge pour PrestaShop » (back-office, `config.xml`, pages du module, serveur MCP). Le nom
  technique `ucpbridge` ne change pas.

Changements internes

- Schéma : colonnes `issued_us` (jetons et codes OAuth), table `ps_ucpbridge_oauth_revocation` ; désinstaller puis
  réinstaller (pas de script `upgrade/`).
- Scénarios de la deuxième relecture ajoutés à la suite d'intégration (`tests/test_05_relecture2.py`, dépôt du banc).

## 0.1.1-test (2026-10-21) : corrections de la relecture indépendante

Décision en attente

- Licence : la licence MIT proposée est retirée. Le prototype est diffusé « tous droits réservés » (Christophe Vidal
  / 410 Gone) pour évaluation, sans redistribution ni usage en production, en attendant la licence ouverte en cours de
  définition avec Friends of Presta. En-têtes SPDX supprimés, `LICENSE` remplacé.

Corrections

- Appels `complete` simultanés : réservation atomique de la session (mise à jour conditionnelle vers
  `complete_in_progress`, une seule ligne modifiée) avant tout débit ; une seule commande et un seul débit, même avec
  des clés d'idempotence différentes.
- `Idempotency-Key` obligatoire sur toutes les opérations qui modifient l'état, en REST (`400
  idempotency_key_required`) comme en MCP (`meta["idempotency-key"]`).
- Débit sans commande : ouvrir le `continue_url` d'une session de caisse la sort de l'API (exposée `canceled`,
  message `checkout_handed_off`) ; contrôle du panier (pas de commande), du client et de la clé de sécurité avant le
  débit ; après un débit réussi, plus jamais de retour à `ready_for_complete` : état terminal, réponse conservée par
  l'idempotence, incident journalisé et remboursement automatique (`refund()` ajoutée à l'interface des adaptateurs,
  implémentée pour Stripe et pour le paiement de test). Journal des débits et remboursements `ps_ucpbridge_payment`.
- Cloisonnement : propriétaire enregistré dans chaque panier, session et commande (`platform` ou
  `user:{client_id}:{id_customer}`), égalité stricte exigée, `404` sinon ; commandes exposées par un identifiant opaque
  `ord_...` au lieu de l'`id_order`.
- Révocation : page « Mes accès agents » dans le compte client et liste des accès avec révocation dans la configuration
  du module ; l'écran de consentement renvoie vers cette page.
- `403 insufficient_scope` renvoyé avec le bon code HTTP (il sortait en 401) et sans bloquer la clé d'idempotence.
- MCP : en-tête `Origin` contrôlé, `isError` aligné sur `ucp.status`, `get_product` annonce une capacité du profil.
- Stripe : clé secrète jamais réaffichée en back-office, URL de l'API modifiable par script uniquement, erreur réseau
  détaillée au journal et message générique à l'agent.
- Journal de preuve : appelant, IP et navigateur de l'appel API consignés ; la case CGV cochée est présentée comme non
  probante.
- Compatibilité déclarée ramenée à ce qui est testé : PrestaShop 9.1.0 minimum.
- README : affirmations corrigées (concurrence, cloisonnement, appels sortants), limites complétées.

Changements internes

- Code mort retiré (constantes de gestionnaires dupliquées, `pending_transaction`).
- Scénarios de la relecture ajoutés à la suite d'intégration (`tests/test_04_relecture.py`, dépôt du banc).

## 0.1.0-test (2026-10-21) : première version de test

Ajouts

- CGV, rétractation, RGPD : `links` (terms_of_service, privacy_policy, refund_policy, shipping_policy) construits
  depuis les pages CMS et configurables dans le back-office ; mention `disclosure` du droit de rétractation (UE) ;
  option de relecture des CGV chez le marchand (`requires_buyer_review`, page signée, case à cocher, acceptation
  horodatée avec version et IP) ; journal de preuve par commande affiché sur la fiche commande ; extension
  `buyer_consent` (newsletter et partenaires sur consentement explicite uniquement) ; bloc légal et bouton
  « Se rétracter de cette commande » dans l'e-mail de confirmation, page de rétractation et accusé de réception.
- Transport MCP (`/ucp/v1/mcp`, JSON-RPC 2.0) : 12 outils du binding UCP, même logique que le REST, déclaré dans
  `/.well-known/ucp`. Route REST `POST /ucp/v1/catalog/product`.
- Tests : 60 tests unitaires PHPUnit, tests d'intégration pytest et Playwright sur le banc.
- Conventions de module : `config.xml`, `index.php` de protection, logo, jeton anti-CSRF sur la configuration,
  désinstallation complète (tables, configuration, hooks, hook d'extension, `.htaccess`).

Corrections

- Une session de caisse pouvait être créée par un appelant invité à partir du panier (`cart_id`) d'un client
  authentifié : la propriété du panier est maintenant contrôlée.
- L'Idempotency-Key était globale : deux appelants différents utilisant la même clé recevaient la réponse de
  l'autre. Les clés sont désormais cloisonnées par appelant.

Changements internes

- Logique pure extraite et testée : identifiants d'articles, totaux, idempotence, liens signés (l'usage fait partie
  de la signature), PKCE.
- Numéro de version ramené à 0.1.0 pour la première publication (les versions antérieures n'ont existé que sur le banc).
