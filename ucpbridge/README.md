# UCP Bridge pour PrestaShop (module ucpbridge)

> **Version bêta 0.1.2, prototype : ne pas utiliser en production.** Écrit pour le podcast Contrib. (épisode 15,
> Google Universal Cart). Aucune garantie : paiement réel non éprouvé, pas de signatures HTTP, module non validé par
> Google, Universal Cart pas encore ouvert en Europe. Relectures, tests et contributions bienvenus.

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. (Thomas Rayrat et
Christophe Vidal). Publié sous licence **AFL-3.0** (Academic Free License 3.0), la licence habituelle des modules
PrestaShop : texte complet dans `LICENSE`.

Nom affiché : « UCP Bridge pour PrestaShop ». Nom technique (dossier, classe, tables, configuration, routes) :
`ucpbridge`.

Implémente le protocole UCP (Universal Commerce Protocol, version `2026-04-08`, https://ucp.dev)
sur PrestaShop 8.0 à 9.2 (PHP 8.1 minimum, voir « Compatibilité et code »), écrit à partir de la spécification.
**Appels sortants** : le module ne télécharge jamais le profil des agents. Le seul appel réseau est celui de
l'adaptateur Stripe (débit et remboursement) : vers `https://api.stripe.com` dès qu'une clé `sk_test_` est
enregistrée avec l'URL par défaut, vers le conteneur stripe-mock sur le banc. Sans clé Stripe, aucun appel sortant.

## Ce que fait le module

- **Profil** `GET /.well-known/ucp` : service `dev.ucp.shopping` en **REST** (endpoint `/ucp/v1`) et en **MCP**
  (endpoint `/ucp/v1/mcp`), capacités `catalog.search`, `cart`, `checkout`, `fulfillment`, `order`, `discount`,
  `buyer_consent`, `dev.ucp.common.identity_linking`,
  gestionnaires de paiement actifs.
- **Déclinaisons partout** : un article s'identifie par
  1. sa référence de déclinaison (`demo_1-M-BLANC`),
  2. la référence produit + `variant_id` (`{"id": "demo_1", "variant_id": "1-3"}`),
  3. le format des flux Google `{id_product}-{id_product_attribute}` (`1-3`).
  Sans déclinaison sur un produit qui en a : refus `variant_required` avec la liste des variantes
  (le module ne choisit jamais à la place du client). Stock contrôlé par déclinaison (`out_of_stock`),
  prix TTC de la déclinaison (impact de prix et prix spécifiques inclus).
- **Transfert de panier** `POST /carts` (+ `GET`, `PUT`, `/cancel`) : crée un vrai panier PrestaShop invité
  et renvoie un `continue_url` signé (HMAC SHA-256, 24 h). Ce lien rattache le panier à la session du
  visiteur (cookie `id_cart`) et l'envoie sur la page panier de la boutique, pré-remplie.
- **Caisse native** `POST /checkout-sessions`, `GET`/`PUT /checkout-sessions/{id}`, `/complete`, `/cancel` :
  statuts `incomplete`, `ready_for_complete`, `completed`, `canceled` ; client invité et adresse depuis
  `fulfillment.methods[].destinations` ; transporteurs réels de la boutique en options ; TVA selon le pays de
  livraison ; totaux cohérents (somme des lignes = total). Une session peut aussi naître d'un panier (`cart_id`).
- **Codes promo** (`dev.ucp.shopping.discount`, étend `cart` et `checkout`) : l'agent envoie `discounts.codes`
  (remplacement intégral, insensible à la casse, `[]` pour vider). Le module pose les **règles panier PrestaShop
  existantes** (`CartRule` par code) sur le vrai panier, sans calcul parallèle, puis relit ce que PrestaShop en déduit.
  Réponse : `discounts.codes` (écho), `discounts.applied[]` (`code` ou `automatic: true`, `title`, `amount` positif,
  `method`, `priority`, `provisional` pour une livraison offerte tant que le transporteur n'est pas choisi), et totaux
  recalculés avec une ligne `discount` négative. Rejets en `messages[]` de type `warning`, codes de la spécification :
  `discount_code_invalid`, `discount_code_expired`, `discount_code_user_not_logged_in`, `discount_code_user_ineligible`,
  `discount_code_already_applied`, `discount_code_combination_disallowed`, avec le message PrestaShop en clair
  (montant minimum, pays, transporteur...). Les **règles automatiques sans code** (ex. livraison offerte) passent par
  `CartRule::autoAddToCart` / `autoRemoveFromCart`, comme dans le tunnel classique, et sont revalidées avant le débit.
  Montants TTC dans le panier, HT dans la caisse (alignés sur les totaux HT de la caisse, somme des lignes = total).
- **Paiement par adaptateurs** : la caisse ne connaît aucun prestataire. Elle demande au registre
  (`UcpBridgePaymentRegistry`) l'adaptateur qui supporte le `handler_id` choisi, lui confie le montant, et ne crée la
  commande (`PaymentModule::validateOrder`) que sur un débit réussi, avec la référence de transaction du prestataire
  (`order_payment.transaction_id`). Interface `UcpBridgePaymentProvider` :
  `getName()`, `handlers()` (déclarations UCP), `supports($handlerName, $handlerId)`,
  `charge($credential, $amount, $currency, $context)` qui renvoie un `UcpBridgePaymentResult`
  (`succeeded` + référence, `failed` + message, `requires_action` + URL : la session passe alors en
  `requires_escalation` avec `continue_url`, prévu pour 3-D Secure, ou `unknown` + message quand le prestataire a pu
  débiter sans réponse exploitable : délai dépassé, connexion coupée, erreur 5xx), et `refund($transactionId, $amount, $currency,
  $context)`, appelée automatiquement si la commande ne peut pas être créée après un débit réussi.
  Chaque débit et chaque remboursement est consigné dans `ps_ucpbridge_payment` (session, adaptateur, opération,
  statut, référence, montant). Une écriture impossible dans ce journal n'interrompt jamais la caisse (le débit est
  mémorisé avant) : elle est signalée au journal PrestaShop (gravité 3) avec la référence de l'opération.
  - `fr.410gone.ucpbridge.mock` : paiement de test, actif seulement si un secret de simulation est configuré
    (référence `mock_...` propre à chaque débit, remboursement simulé).
  - `com.google.pay` version `2026-01-23` (adaptateur Stripe) : `environment: TEST`, `merchant_info`
    (`merchant_id` de test configurable), `allowed_payment_methods` CARD, `tokenization_specification`
    `PAYMENT_GATEWAY` gateway `stripe` (`stripe:version`, `stripe:publishableKey`). L'agent renvoie
    `credential: {type: PAYMENT_GATEWAY, token: "<jeton Stripe sérialisé en JSON par Google Pay>"}` ; l'adaptateur en
    extrait l'id `tok_...` et débite par l'API Charges (`source`), `Idempotency-Key` transmise à Stripe (session,
    numéro de tentative, empreinte de l'instrument, du montant et de la devise : un panier modifié ou un nouvel essai
    après un refus part avec une nouvelle clé), montant
    vérifié au retour ; remboursement par l'API Refunds. URL de base `https://api.stripe.com` par défaut, modifiable
    par script uniquement (banc d'essai), pas depuis le back-office. **Clés live refusées** : le gestionnaire n'est
    annoncé qu'avec une clé `sk_test_`.
- **Comptes clients** (`dev.ucp.common.identity_linking`) : serveur d'autorisation OAuth 2.0 dans le module.
  - `GET /.well-known/oauth-authorization-server` (RFC 8414), `/ucp/oauth/authorize`, `/ucp/oauth/token`,
    `/ucp/oauth/revoke` (RFC 7009).
  - Code d'autorisation, **PKCE S256 obligatoire**, `redirect_uri` en correspondance exacte, `state` renvoyé, code à
    usage unique 5 min (un code rejoué par son client révoque les jetons déjà émis ; présenté par un autre client, il
    est refusé sans être consommé ni rien révoquer), scopes `dev.ucp.shopping.checkout:manage` et
    `dev.ucp.shopping.order:read` présentés en un seul consentement.
  - Clients enregistrés en configuration (`UCPBRIDGE_OAUTH_CLIENTS`, secret stocké haché SHA-256) ; authentification
    `client_secret_basic` (et `client_secret_post`). `private_key_jwt`, recommandé par Google, n'est pas implémenté.
  - La page d'autorisation réutilise la connexion client PrestaShop : non connecté, redirection vers la page de
    connexion de la boutique (`back=`) puis retour ; écran de consentement en français (anti-CSRF HMAC,
    `X-Frame-Options: DENY`).
  - Jetons opaques (`ucpat_` accès 1 h, `ucprt_` rafraîchissement 30 jours), stockés **hachés** en base
    (`ps_ucpbridge_oauth_token`), révocables, refusés si le client est désactivé. **Rotation atomique** du jeton de
    rafraîchissement (mise à jour conditionnelle, une ligne exigée) : sur N rafraîchissements simultanés du même jeton,
    un seul obtient une nouvelle paire, les autres reçoivent `invalid_grant`.
  - **Révocation sans contournement** : chaque révocation d'un couple client OAuth / client final enregistre d'abord
    une date de révocation (microsecondes, table `ps_ucpbridge_oauth_revocation`), puis révoque jetons et codes. Toute
    émission (rafraîchissement, échange de code) revérifie cette date après l'insertion des nouveaux jetons et les
    annule si leur parent est antérieur à la révocation ; un jeton émis avant la révocation est refusé à la lecture.
    Un rafraîchissement concurrent ne fait donc survivre aucun jeton ; une nouvelle liaison après révocation fonctionne.
  - **Révocation par l'acheteur** : page « Mes accès agents » dans le compte client PrestaShop (lien sur « Mon compte »,
    `module/ucpbridge/access`) qui liste les assistants reliés et révoque d'un clic tous leurs jetons et codes. Le
    marchand voit la même liste (assistant, client, date) dans la configuration du module et peut révoquer aussi.
    La plateforme garde `/ucp/oauth/revoke` (RFC 7009).
  - Avec un jeton client, la session de caisse est rattachée au vrai `Customer` : identité du compte (l'agent ne peut
    pas la remplacer), première adresse du carnet proposée par défaut (jamais modifiée : une adresse envoyée par l'agent
    crée une nouvelle adresse), commande au nom du client (pas d'invité), codes réservés à ce client acceptés.
- **Cloisonnement strict par appelant** : chaque panier, session de caisse et commande enregistre son propriétaire,
  `platform` (clé API) ou `user:{client_id}:{id_customer}` (jeton OAuth). Lecture, modification, conversion `cart_id`
  et `GET /orders/{id}` exigent l'égalité stricte : la clé plateforme ne voit pas les ressources d'un jeton client et
  réciproquement, deux clients OAuth d'un même acheteur ne partagent rien. Ressource d'autrui ou inconnue : `404`.
  Les commandes sont exposées par un identifiant opaque aléatoire (`ord_` + 24 caractères hexadécimaux), jamais par
  l'`id_order` PrestaShop.
- **Idempotency-Key** : **obligatoire** sur toute opération qui modifie l'état (`POST`/`PUT` sur `carts` et
  `checkout-sessions`, `complete`, `cancel` ; la recherche et la fiche produit en sont dispensées), sinon `400
  idempotency_key_required`. Table dédiée, 24 h, clé cloisonnée par appelant ; même clé et même corps : même réponse
  (`Idempotent-Replayed: true`) ; même clé et corps différent : `409` ; même clé encore en cours : `409`. Un refus
  d'autorisation (`403 insufficient_scope`) ne réserve pas la clé.
- **Finalisation (`complete`) protégée contre la concurrence** : la session est réservée par une mise à jour
  conditionnelle (`ready_for_complete` vers `complete_in_progress`, une seule ligne modifiée) avant tout débit. Des
  appels simultanés, même avec des clés d'idempotence différentes, donnent une seule commande et un seul débit ; les
  autres reçoivent l'état courant (`complete_in_progress` ou `completed`). Après la réservation, la session est relue ;
  juste avant le débit, le module vérifie que le panier n'a pas déjà de commande, que client et clé de sécurité n'ont
  pas changé, que le panier n'est pas vide, que son contenu correspond aux lignes de la session et que le montant est
  strictement positif (sinon : aucun débit, session rendue).
- **Mise à jour (`PUT`) réservée elle aussi** : `update_checkout` (et `PUT /carts/{id}`) pose une réservation
  `updating` (même mise à jour conditionnelle) **avant** de toucher au panier PrestaShop. Une session déjà réservée
  (finalisation, autre mise à jour, rapprochement) refuse la mise à jour en `409` avec son état réel : une mise à jour
  non enregistrée ne renvoie jamais de nouveaux totaux. Un `complete` qui arrive pendant une mise à jour reçoit `409`
  et peut être relancé.
- **Reprise par l'acheteur** : ouvrir le `continue_url` d'une session de caisse la sort du circuit API (statut interne
  `handed_off`, exposé `canceled` avec le message `checkout_handed_off`) ; `complete` et `PUT` sont alors refusés sans
  débit. Si l'agent a déjà engagé la finalisation, c'est le lien qui est refusé. Même chose pour un **panier UCP**
  (`create_cart`) : une fois son lien ouvert, il est figé côté API (`PUT /carts/{id}` refusé en `409` avec
  `checkout_handed_off`, conversion `cart_id` refusée) ; l'acheteur garde seul la main dans son navigateur.
- **Débit réussi puis commande impossible** : la session passe dans un état terminal (exposé `canceled`, messages
  `order_failed` puis `payment_refunded` ou `refund_pending`), jamais de retour à `ready_for_complete` ; la réponse
  est enregistrée par l'idempotence (une nouvelle tentative ne redébite pas) ; incident journalisé (journal PrestaShop,
  gravité 4) et remboursement automatique par l'adaptateur. **Avant tout remboursement**, le module cherche une
  commande sur le panier (`currentOrder`, puis `Order::getIdByCartId`) : `validateOrder` crée la commande puis exécute
  les hooks des modules tiers et les e-mails, une exception à ce stade laisse une commande valide. Dans ce cas, rien
  n'est remboursé, la session est terminée (`completed`) avec cette commande et l'exception est journalisée en
  avertissement (« commande conservée, aucun remboursement »).
- **Débit au résultat inconnu** : `failed` signifie « aucun débit » (refus du prestataire, requête jamais partie :
  connexion refusée, nom d'hôte introuvable). Un résultat `unknown` (délai dépassé, connexion coupée après l'envoi,
  erreur 5xx sans charge, exception levée par l'adaptateur pendant le débit) n'est jamais traité comme un refus : la
  session reste `complete_in_progress` avec la trace du débit engagé, l'agent reçoit le message
  `payment_verification` (ne pas relancer avec un autre moyen de paiement), un nouvel essai ne redébite pas, et le
  rapprochement la classe en « débit incertain, à vérifier chez le prestataire ».
- **Rapprochement des sessions bloquées** : si le processus PHP meurt pendant la finalisation (erreur fatale,
  `max_execution_time`, processus tué), la session reste `complete_in_progress`. La commande de rapprochement la
  traite dès qu'elle est réservée depuis plus de N minutes (15 par défaut) : commande trouvée sur le panier = session
  terminée par elle ; débit réussi sans commande au journal `ps_ucpbridge_payment` = remboursement par l'adaptateur et
  incident (gravité 4) ; débit engagé sans résultat connu (processus mort pendant l'appel au prestataire) = session
  terminée et incident « à vérifier chez le prestataire » avec la clé d'idempotence du débit ; rien d'engagé = session
  rendue finalisable. Une mise à jour interrompue (`updating`) est rendue de la même façon. Deux points d'entrée :

  ```sh
  # ligne de commande (dans le conteneur ou sur le serveur), à planifier toutes les 5 à 15 minutes
  su www-data -s /bin/sh -c "php /var/www/html/modules/ucpbridge/bin/reconcile.php --minutes=15"
  ```

  et le bouton « Rapprocher les sessions bloquées » de la configuration du module (avec le nombre de sessions en
  attente). Le script refuse toute exécution hors ligne de commande.
- **Authentification, deux jetons dans le même en-tête.** La spécification UCP (et le guide Google) placent le jeton
  utilisateur dans `Authorization: Bearer` (RFC 6750), l'en-tête déjà utilisé par la clé API. Choix du prototype :
  le **jeton client est prioritaire** et se reconnaît à son préfixe (`ucpat_`). Il authentifie à la fois l'acheteur et
  la plateforme (le client OAuth qui l'a obtenu). La plateforme peut joindre sa clé API en `X-UCP-Api-Key` ; si elle
  est présente, elle est vérifiée. Sans jeton client, `Authorization: Bearer <clé API ucpk_...>` ouvre la caisse en
  invité. **Une clé API par plateforme** (configuration du module, `UCPBRIDGE_API_KEYS`) : l'identifiant de la
  plateforme fait partie de l'identité de l'appelant, donc chaque plateforme ne voit que ses propres paniers, sessions
  de caisse et commandes, et ses `Idempotency-Key` ne rejouent jamais la réponse d'une autre. Ne jamais remettre la
  même clé à deux plateformes : elles partageraient tout. La clé unique des versions 0.1.2 et antérieures
  (`UCPBRIDGE_API_KEY`) devient automatiquement la plateforme `default`, sans perte des sessions en cours. Jeton client invalide, expiré ou révoqué : `401 identity_required` avec
  `WWW-Authenticate: Bearer realm=..., error="invalid_token"` ; scope manquant : `403 insufficient_scope`.
  En production, la clé API serait remplacée par les signatures HTTP (RFC 9421) de la plateforme.
- **Commande** `GET /orders/{id}` (identifiant opaque `ord_...`, commandes passées via UCP par le même appelant
  uniquement) et **recherche** `POST /catalog/search`.

### CGV, rétractation, RGPD

- **Liens légaux** (`links`) dans chaque réponse de panier et de caisse, construits depuis les pages CMS de la
  boutique, `title` dans la langue de la boutique : `terms_of_service`, `privacy_policy`, `refund_policy`,
  `shipping_policy`. Pages choisies dans le back-office du module (sélecteurs). À l'installation, le module les devine :
  CGV = la page des conditions du tunnel de commande (`PS_CONDITIONS_CMS_ID`, sur la démo « Conditions
  d'utilisation »), puis mots-clés (confidentialité, livraison, retours) ; à défaut de page dédiée, les retours pointent
  vers les CGV.
- **Mention de rétractation** pour les livraisons dans l'Union européenne : message `type: warning`,
  `presentation: disclosure`, `code: withdrawal_right` (14 jours, fonction de rétractation en ligne), avec `url` vers la
  politique de retour. Pays de livraison s'il est connu, sinon pays de la boutique. Aucune mention hors UE.
- **Relecture des CGV chez le marchand** (option, désactivée par défaut) : une session complète mais sans acceptation
  passe en `requires_escalation` avec un message `terms_review_required` de sévérité `requires_buyer_review` et un
  `continue_url` signé (HMAC, durée de la session) vers la page `module/ucpbridge/terms`. Cette page affiche le
  récapitulatif, les CGV et la case « J'ai lu et j'accepte les CGV » (jeton anti-CSRF lié au cookie). La case cochée
  (date UTC, version des CGV, IP, navigateur) est enregistrée dans la session, qui redevient `ready_for_complete`.
  **Ce n'est pas une preuve d'acceptation par l'acheteur humain** : rien n'empêche l'agent d'ouvrir lui-même le lien et
  de cocher la case. Le journal consigne donc aussi l'IP et le navigateur de l'appel API qui a finalisé, et le
  back-office signale « même IP que l'appel API de l'agent » quand elles coïncident. Une vraie preuve demanderait une
  confirmation par e-mail ou une session client PrestaShop connectée (non fait).
  Version des CGV : `cms{id}-{12 premiers caractères du SHA-256 du contenu}`, elle change dès que le texte change.
- **Journal de preuve** (`ps_ucpbridge_proof`) pour chaque commande UCP : session, agent appelant (en-tête
  `UCP-Agent`, ou `meta.ucp-agent` en MCP), appelant authentifié, IP et navigateur de l'appel API, liens présentés, version des CGV, acceptation éventuelle, mentions
  (disclosures), totaux présentés au moment du paiement, consentements, date. Affiché sur la fiche commande du
  back-office (hook `displayAdminOrderMain`, bloc « Preuve UCP »), avec l'état de la rétractation.
- **buyer_consent** (`dev.ucp.shopping.buyer_consent`, étend `checkout`) : seuls les booléens explicites sont retenus
  (`"true"` en texte est ignoré). `consent.marketing: true` coche la newsletter du client PrestaShop (date et IP
  d'inscription), `false` la décoche, absent : rien ne change (un invité créé par UCP part toujours à 0).
  L'option « offres des partenaires » n'est cochée que si `marketing` **et** `sale_of_data` valent `true`.
  `analytics` et `preferences` sont consignés dans le journal de la commande ; une catégorie non exprimée y vaut
  `null`, jamais une valeur présumée.
- **E-mail de confirmation** : c'est l'e-mail `order_conf` standard de PrestaShop. Le module y ajoute (hooks
  `actionEmailAddAfterContent` et `actionGetExtraMailTemplateVars`, commandes UCP uniquement) un bloc « Informations
  légales » : liens CGV et politiques, rappel des 14 jours et bouton **« Se rétracter de cette commande »**, lien signé
  (120 jours) vers `module/ucpbridge/withdraw`. Cette page récapitule la commande ; la confirmation (jeton anti-CSRF)
  enregistre la demande (`ps_ucpbridge_withdrawal`, une par commande, date et IP) et envoie un **accusé de réception par
  e-mail** (modèle `mails/fr/ucp_withdrawal`), support durable.
- **Durées de conservation et purge** (RGPD, limitation de la conservation) : configurables par catégorie, en jours
  (0 = jamais purgé). Valeurs par défaut :

  | Catégorie | Défaut | Point de départ | Traitement |
  |---|---|---|---|
  | Sessions de caisse et paniers UCP terminés ou expirés | 30 j | dernière activité | supprimés avec leur panier PrestaShop sans commande (visiteur sans compte) et leur adresse sans client ; une session terminée par une commande est gardée sans les données personnelles de l'acheteur (`buyer`, `consent`, `destination`, `terms_acceptance`, `context`) |
  | Clients invités créés par le module, sans aucune commande | 30 j | création | supprimés (`Customer::delete` : adresses inutilisées, paniers sans commande) ; jamais tant qu'ils ont une session en échec (acheteur peut-être à rembourser) |
  | Codes et jetons OAuth expirés ou révoqués | 30 j | expiration, ou émission pour un jeton révoqué | supprimés |
  | Journal des paiements | 3650 j (minimum) | opération | supprimé ; sessions en échec (incident de paiement : débit non remboursé ou incertain) et leur acheteur invité gardés aussi longtemps |
  | Journal de preuve et demandes de rétractation | 3650 j (minimum) | enregistrement | supprimés |

  10 ans pour les paiements (pièces comptables, article L123-22 du Code de commerce) et pour les preuves (contrats
  conclus par voie électronique à partir de 120 euros, articles L213-1 et D213-2 du Code de la consommation). Les clés
  d'idempotence (24 h) sont purgées au passage. Ces deux durées légales ne descendent jamais sous 3650 jours (0, jamais
  purgé, reste permis) : une durée plus courte est refusée à l'enregistrement, et si elle est écrite en base par un autre
  moyen, la purge refuse de tourner (message d'erreur au back-office, code de sortie 2 en ligne de commande) sans rien
  supprimer. Jamais touchés : les commandes passées, leurs clients (même invités) et
  leurs adresses, les sessions en cours de finalisation ou de rapprochement, les paniers et adresses des clients avec
  compte. Les invités créés par le module sont enregistrés dans `ps_ucpbridge_customer` (ceux de la 0.1.2 sont repris
  depuis les sessions à la mise à jour) : un client créé autrement n'est jamais purgé par le module.
  Purge : `php modules/ucpbridge/bin/purge.php` (tâche cron quotidienne ; `--dry-run` pour une simulation) ou boutons
  « Simuler la purge » et « Purger maintenant » de la configuration. Compte rendu chiffré par catégorie, aussi consigné
  au journal PrestaShop (« ucpbridge purge RGPD ») : il compte aussi les paniers et adresses supprimés avec un invité,
  et la simulation donne les mêmes nombres que la purge. Sessions lues par lots de 500, sans relire celles dont les
  données personnelles sont déjà retirées.

### Transport MCP

- `POST /ucp/v1/mcp` : JSON-RPC 2.0 sur HTTP streamable, réponses JSON (pas de flux SSE, pas de session ; `GET`
  renvoie 405). Méthodes `initialize` (versions MCP 2025-06-18, 2025-03-26, 2024-11-05), `notifications/initialized`,
  `ping`, `tools/list`, `tools/call`.
- Outils du binding MCP UCP (`mcp.openrpc.json`) : `search_catalog`, `get_product`, `create_cart`, `get_cart`,
  `update_cart`, `cancel_cart`, `create_checkout`, `get_checkout`, `update_checkout`, `complete_checkout`,
  `cancel_checkout`, `get_order`. `arguments.meta["ucp-agent"].profile` est obligatoire, `meta["idempotency-key"]`
  aussi pour tout outil qui modifie l'état (`create_*`, `update_*`, `cancel_*`, `complete_checkout`), comme
  l'en-tête `Idempotency-Key` en REST.
- En-tête `Origin` contrôlé (protection contre le rebinding DNS) : une requête venue d'une autre origine que la boutique
  reçoit `403`. Les clients serveur, sans `Origin`, ne sont pas concernés. `isError` vaut `true` quand le document UCP
  renvoyé a `ucp.status: error`.
- **Aucune duplication de logique** : `UcpBridgeMcp` traduit chaque outil vers la même route que l'API REST
  (`tools/call create_cart` équivaut à `POST /ucp/v1/carts`), avec la même authentification, la même idempotence et le
  même journal de preuve. Réponse UCP dans `structuredContent` et sérialisée dans `content[0].text`. Les résultats
  métier (rupture, `variant_required`) restent des résultats ; les erreurs protocolaires (401, conflit d'idempotence
  409) sont des erreurs JSON-RPC `-32000`.
- Déclaré dans `/.well-known/ucp` à côté du REST (`transport: mcp`, schéma `mcp.openrpc.json`).
- Route REST ajoutée pour l'outil `get_product` : `POST /ucp/v1/catalog/product` `{"id": "demo_1"}` (produit et toutes
  ses variantes, par référence produit, référence de déclinaison ou `{id_produit}-{id_declinaison}`). La réponse
  annonce la capacité `dev.ucp.shopping.catalog.search`, déclarée au profil (pas de capacité `lookup` séparée).

## Brancher un module de paiement tiers (Stripe officiel, Adyen, Mollie, PayPlug)

Le module déclare le hook `actionUcpBridgeRegisterPaymentProviders`, appelé une fois par requête avec
`['registry' => UcpBridgePaymentRegistry]`. Un module de paiement s'y greffe et inscrit son adaptateur :

```php
// dans le module tiers (ex. mollie), après registerHook('actionUcpBridgeRegisterPaymentProviders')
public function hookActionUcpBridgeRegisterPaymentProviders(array $params)
{
    require_once __DIR__ . '/src/Ucp/MollieUcpProvider.php'; // implements UcpBridgePaymentProvider
    $params['registry']->register(new MollieUcpProvider($this));
}
```

L'adaptateur annonce ses gestionnaires (`handlers()`, ex. `com.google.pay` avec `gateway: "mollie"`, ou son propre
handler), réutilise les identifiants déjà saisis dans la configuration du module tiers, et implémente `charge()` et
`refund()` (indispensable : c'est lui qui rembourse si la commande échoue après le débit).
Le module tiers garde la main sur ce qu'il sait faire (3-D Secure, remboursements, webhooks) ; ucpbridge garde le
protocole, le panier, les remises et la création de commande. Un adaptateur inscrit sous le même nom qu'un
adaptateur du module (`stripe`, `mock`) le remplace : c'est ainsi que le module Stripe officiel prendrait la relève
de l'adaptateur de démonstration.

## Banc d'essai : ce qui est simulé

- **E-mails** : conteneur `axllent/mailpit` (SMTP `mailpit:1025` depuis la boutique, interface http://localhost:8025) ;
  `seed-demo.php` règle PrestaShop en SMTP vers lui. Aucun e-mail ne sort du poste.

- **Stripe** : conteneur `stripe/stripe-mock` (image officielle, port 12111), URL `http://stripe-mock:12111`, clé
  factice `sk_test_contrib`. stripe-mock valide la forme des requêtes (paramètres, authentification) et renvoie une
  charge `succeeded` avec une référence `ch_...` propre à chaque appel et le montant demandé. Il ne vérifie ni le
  jeton ni la carte, ne garde aucun état, ne déclenche jamais 3-D Secure. L'API PaymentIntents de stripe-mock renvoie
  un statut figé (`requires_payment_method`), d'où le choix de l'API Charges pour un parcours crédible.
- **Google Pay** : aucune feuille de paiement réelle. L'agent fabrique le jeton que Google Pay renverrait pour la
  passerelle stripe (un objet token Stripe sérialisé, id `tok_visa`, carte de test publique de Stripe).
- **Identity linking** : le client `google-agent-test` et son secret de test sont créés par `seed-demo.php` ;
  `redirect_uri` `http://localhost:8765/callback` (petit serveur lancé par l'agent).

## Limites connues (à lire avant toute démonstration)

Paiement et caisse
- **Google Pay en production** : compte marchand Google Pay & Wallet Console, intégration « passerelle », merchant ID
  de production, `environment: PRODUCTION`, clés Stripe live (refusées par le prototype), tests avec la vraie feuille.
- **3-D Secure réel** : le chemin `requires_action` / `requires_escalation` est câblé mais non éprouvé (stripe-mock
  ne le déclenche pas). Le `continue_url` mène alors à la reprise du panier, ce qui sort la session de l'API : le
  paiement en attente n'est ni repris ni annulé. En réel, passer par PaymentIntents et une page de finalisation.
- **Processus tué pendant la finalisation** : rien n'est automatique tant que la commande de rapprochement n'est pas
  planifiée (tâche cron) ou lancée depuis la configuration ; jusque-là la session reste `complete_in_progress` et
  l'acheteur ne peut ni annuler ni reprendre le panier. Si le processus meurt pendant l'appel au prestataire, le module
  ne sait pas si le débit a eu lieu : l'interface des adaptateurs n'a pas de méthode de consultation d'un paiement,
  le rapprochement lève donc un incident à vérifier à la main (clé d'idempotence fournie) au lieu de décider seul.
  Testé par simulation (processus arrêté juste après le débit, ou juste après la création de la commande).
- Remboursement automatique éprouvé sur l'adaptateur de test et sur stripe-mock seulement. Si l'adaptateur ne peut pas
  rembourser, la session le dit (`refund_pending`) et l'incident est journalisé : le remboursement reste à faire à la
  main. Traitement de la rétractation (retour, remboursement) laissé au marchand.
- AP2, A2A, embarqué, fidélité, allocations de remise par ligne, multi-transporteurs, point relais, multiboutique,
  limitation de débit.

Protocole et sécurité
- **Signatures HTTP** (RFC 9421, requêtes et réponses) et `signing_keys` (liste vide). **HTTPS** : la spécification,
  OAuth et les connecteurs MCP l'imposent ; le banc tourne en HTTP local. Validation et certification par Google et par
  les plateformes d'assistants : aucune.
- MCP : pas d'autorisation OAuth propre au protocole MCP (métadonnées RFC 9728) ; l'authentification se fait par la clé
  API ou le jeton client en `Authorization: Bearer`. Pas de flux SSE ni de session MCP.
- `private_key_jwt` pour le client OAuth, Google Streamlined Linking.
- La chaîne signée des liens (`usage|k=v|...`) n'est pas encodée : sans exploit connu (noms de paramètres fixes),
  mais un format canonique serait plus sûr.
- Révoquer un seul jeton de rafraîchissement par `/ucp/oauth/revoke` ne révoque pas les jetons d'accès déjà émis
  (la page « Mes accès agents » et le back-office, eux, révoquent tout). Pas de détection de rejeu d'un jeton de
  rafraîchissement déjà tourné (le rejeu est refusé, la famille de jetons n'est pas révoquée pour autant).
- Ordre émission / révocation fondé sur l'horloge du serveur PHP (microsecondes) : sur plusieurs serveurs web, les
  horloges doivent être synchronisées.
- `buyer` et `context` du panier sont stockés et renvoyés sans filtrage de clés ni borne de taille ; nombre de lignes
  non limité.
- Un produit en visibilité « nulle part » reste commandable par sa référence (la recherche, elle, le filtre).
- Recherche : l'échappement des jokers `%` et `_` dans le LIKE est imparfait (fonctionnel, pas une injection).
- Le message d'adresse refusée renvoie à l'agent le texte de validation de PrestaShop (nom du champ fautif).
- Ressource inconnue ou d'un autre appelant : `404` ; à vérifier contre la spécification UCP pour les autres erreurs.

Preuve, données personnelles, conformité
- **Acceptation des CGV non probante** (voir plus haut) : la case peut être cochée par l'agent.
- Purge RGPD : rien n'est automatique tant que `bin/purge.php` n'est pas planifié (ou lancé depuis la configuration).
  Les données d'un invité qui a commandé (client, adresses, commande) relèvent de la politique de conservation de la
  boutique PrestaShop, pas du module ; aucune anonymisation des clients n'est faite. Les durées par défaut sont une
  proposition à valider par le marchand et son conseil (registre des traitements).
- Tables de preuve conservées après une désinstallation : elles restent en base sans module pour les lire (export CSV
  à faire avant, ou réinstaller le module pour les consulter). La 0.1.2 et les versions antérieures, elles, les
  suppriment à la désinstallation : passer à la 0.1.3 par mise à jour, jamais par désinstallation.
- Aucune chaîne traduisible (`$this->l()` / `trans()`) : messages aux agents en français, modèle `mails/en/` en français.

Compatibilité et code
- Versions de PrestaShop éprouvées par la suite complète (images Docker officielles `prestashop/prestashop`, une par
  branche mineure) :

  | PrestaShop | Image | PHP |
  |---|---|---|
  | 8.0 | `8.0.5-8.1-apache` | 8.1 |
  | 8.1 | `8.1.7-8.1-apache` | 8.1 |
  | 8.2 | `8.2.8-8.1-apache` | 8.1 |
  | 9.0 | `9.0.3` | 8.4 |
  | 9.1 | `9.1.5` | 8.5 |
  | 9.2 | `9.2.0` | 8.5 |

  Minimum déclaré (`ps_versions_compliancy`) : 8.0.0. PrestaShop 1.7 n'est pas pris en charge.
- **PHP 8.1 minimum**, vérifié à l'installation : sous PHP 7.2 à 8.0 (que PrestaShop 8 accepte encore), l'installation
  est refusée avec un message qui donne la version trouvée. PHP 7 n'est plus maintenu depuis fin 2022 : le module n'est
  pas testé dessus. Les tests unitaires exigent PHPUnit 12, donc PHP 8.3 : sur une image PrestaShop 8 (PHP 8.1),
  `run-unit.sh` les lance avec le PHP de l'hôte (tests de logique pure, sans PrestaShop).
- Mise à jour en place (scripts `upgrade/`) éprouvée sur toutes ces versions. Sur PrestaShop 8, aucune boutique n'a pu
  installer la 0.1.2 (elle exigeait 9.1.0) : le test de mise à jour y installe la 0.1.2 en abaissant ce seul minimum,
  pour éprouver le mécanisme, pas un chemin qu'un marchand aurait suivi.
- Mise à jour en place éprouvée à partir de la 0.1.2 seulement (première version publiée) : une version de test
  antérieure se désinstalle puis s'installe.
- Concurrence entre le lien de reprise et une mise à jour : si l'acheteur ouvre le lien pendant qu'une mise à jour de
  l'agent est en cours (fraction de seconde), le lien est refusé avec « réessayez dans quelques secondes ».
- Back-office et fiche commande en HTML construit à la main (pas de `HelperForm` ni de gabarits Smarty) ;
  `UcpBridgeCart` concentre encore panier, caisse, client, paiement, preuve et commande.
- Lecture du profil de l'agent (`UCP-Agent`) : volontairement aucun appel sortant, l'en-tête est seulement consigné.

## Installation

Archive `ucpbridge-0.1.2-beta.zip` : back-office > Modules > Gestionnaire de modules > Téléverser un module. Ou :

```sh
cp -r ucpbridge /var/www/html/modules/ && chown -R www-data:www-data /var/www/html/modules/ucpbridge
su www-data -s /bin/sh -c "php bin/console prestashop:module install ucpbridge"
rm -rf var/cache/*
```

À l'installation, le module génère la clé API de la plateforme par défaut (`ucpk_...`) et le secret HMAC des liens signés ; aucun secret,
mot de passe ni clé n'est livré dans le code. Il crée ses tables (`ps_ucpbridge_*`), ses hooks et le hook d'extension
`actionUcpBridgeRegisterPaymentProviders`. La désinstallation supprime les tables de travail (sessions, idempotence,
OAuth), la configuration (`UCPBRIDGE_*`), les inscriptions aux hooks, le hook d'extension (s'il ne sert à aucun autre
module) et le bloc `.htaccess`. Elle **conserve par défaut** le journal de preuve (`ps_ucpbridge_proof`), les demandes
de rétractation (`ps_ucpbridge_withdrawal`), le journal des paiements (`ps_ucpbridge_payment`) et la liste des clients
invités créés par le module (`ps_ucpbridge_customer`, identifiants seulement, pour la purge RGPD) : une réinstallation
les retrouve tels quels. Pour les supprimer aussi, cocher dans la configuration « Supprimer aussi le journal de
preuve... à la prochaine désinstallation » (après un export CSV). Ce choix est effacé avec la configuration : il ne
vaut que pour la désinstallation qui suit.

**Mise à jour** (à partir de la 0.1.2) : téléverser la nouvelle archive par le gestionnaire de modules, ou remplacer
les fichiers puis lancer `php bin/console prestashop:module upgrade ucpbridge`, sans désinstaller. PrestaShop exécute
alors les scripts `upgrade/upgrade-X.Y.Z.php` des versions intermédiaires. Chacun appelle `Ucpbridge::upgradeInPlace()`,
qui crée les tables, colonnes (`Ucpbridge::ADDED_COLUMNS`), hooks et clés de configuration manquants, sans modifier
aucune ligne ni aucune valeur déjà configurée (clé API, secret des liens signés, clients OAuth, pages légales). Le
script est rejouable. S'il échoue, PrestaShop désactive le module et garde l'ancienne version en base, sans perte.
Une nouvelle colonne s'ajoute à la fois dans son `CREATE TABLE` et dans `Ucpbridge::ADDED_COLUMNS` : le test
`tests/test_11_mise_a_jour.py` compare le schéma d'une boutique mise à jour depuis la 0.1.2 à celui d'une
installation fraîche.

Le module ajoute en tête du `.htaccess` (et y revient à chaque régénération) les règles `/.well-known/ucp`,
`/ucp/v1/*` (dont `/ucp/v1/mcp`), `/.well-known/oauth-authorization-server` et `/ucp/oauth/*`, car le routeur
PrestaShop ne sert pas `/.well-known/`. Apache uniquement ; sous nginx, réécrire ces chemins vers
`index.php?fc=module&module=ucpbridge&controller=api&ucp_path=...` (et `controller=oauth&ucp_oauth=...`).

## Configuration

Modules > UCP Bridge pour PrestaShop (formulaire protégé par un jeton anti-CSRF propre au module) :

- plateformes et clés API : une clé par plateforme (identifiant, nom affiché), ajout, régénération (même identité,
  sessions conservées) et suppression (clé refusée aussitôt) ;
- pages CMS des quatre liens légaux, option « relecture des CGV chez le marchand » ;
- secret de simulation du paiement de test (vide = gestionnaire de test désactivé) ;
- Google Pay TEST : clé Stripe de test `sk_test_` (champ masqué, jamais réaffichée ; vide = inchangée, `-` = effacée),
  clé publiable, merchant_id de test ; l'URL de l'API Stripe est affichée, modifiable par script uniquement ;
- clients OAuth : enregistrement par script (`UcpBridgeOAuth::registerClient`), secret haché en base ;
- accès agents actifs (assistant, client, date de liaison, échéance), avec révocation ;
- rapprochement des sessions bloquées (bouton, seuil en minutes), voir plus haut ;
- journal de preuve et rétractations : nombre d'enregistrements, export CSV (bouton « Exporter les preuves et
  rétractations », jeton anti-CSRF du module) ; option de suppression à la désinstallation (décochée par défaut).
  Format du fichier `ucpbridge-preuves-AAAAMMJJ-HHMMSS.csv` : UTF-8 avec BOM, séparateur `;`, fins de ligne CRLF, une
  ligne par commande (preuve et rétractation éventuelle) puis les rétractations sans preuve ; colonnes `id_commande`,
  `reference_commande`, `date_preuve`, `session`, `agent_ucp`, `appelant`, `ip_appel_api`, `user_agent_appel_api`,
  `version_cgv`, `acceptation_cgv`, `liens_legaux`, `mentions`, `totaux`, `consentements` (JSON tels qu'enregistrés),
  `retractation_date`, `retractation_email`, `retractation_ip`, `retractation_accuse_envoye`. Une valeur qui
  commencerait par `=`, `+`, `-` ou `@` est préfixée d'une apostrophe (pas de formule exécutée par le tableur) ;
- données personnelles : durées de conservation par catégorie (jours, 0 = jamais), « Simuler la purge », « Purger
  maintenant », avec le compte rendu (voir « Durées de conservation et purge »).

Les e-mails sont ceux de PrestaShop (Paramètres avancés > E-mail) : vérifier que l'envoi est actif.

## Ajouter la boutique comme connecteur MCP dans un assistant

1. Exposer la boutique en **HTTPS** : les assistants (Claude, ChatGPT, Gemini...) refusent un connecteur en HTTP.
   En local, il faut un tunnel HTTPS (ngrok, Cloudflare Tunnel, Tailscale Funnel...) qui publie
   `http://localhost:8092` ; le banc n'en configure aucun. Mettre alors le domaine du tunnel dans la configuration de
   la boutique (`PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL`), sinon les URL renvoyées (`continue_url`) restent en localhost.
2. Adresse du serveur MCP : `https://<domaine>/ucp/v1/mcp` (elle figure aussi dans `/.well-known/ucp`).
3. Authentification : en-tête `Authorization: Bearer <clé API ucpk_...>` (ou un jeton client `ucpat_...` obtenu par
   identity linking). Un assistant qui n'accepte qu'un connecteur sans en-tête ou avec OAuth MCP ne peut pas encore
   se brancher : c'est une limite du prototype (voir plus haut).
4. Vérifier avec l'inspecteur officiel :

```sh
npx @modelcontextprotocol/inspector@2.9.0 --cli https://<domaine>/ucp/v1/mcp --transport http \
  --header "Authorization: Bearer <clé API>" --method tools/list
```

## Tests

Les scripts et tests ci-dessous vivent à la racine du dépôt, pas dans l'archive du module (`seed-demo.php`,
`run-tests.sh`, `tests/` et `ucpbridge/tests/` n'y figurent pas). Ils pilotent un docker compose « bench-ucp-bridge »
(prestashop, mysql, stripe-mock, mailpit). `./tools/get-phpunit.sh` dépose `phpunit.phar` (PHPUnit 12) dans `tools/`
avant le premier lancement. Version de PrestaShop du banc : variable `PS_IMAGE` (étiquette de l'image officielle,
9.1.5 par défaut), sur un banc à part ou après `docker compose down -v` (voir le README du dépôt).

```sh
docker compose -p bench-ucp-bridge up -d
./run-tests.sh          # lint PHP, installation fraîche + jeu de démo, PHPUnit, pytest, bilan
FROM_ZIP=1 ./run-tests.sh   # idem en installant depuis dist/ucpbridge-0.1.2-beta.zip
./run-unit.sh           # tests unitaires seuls (PHPUnit dans le conteneur PrestaShop)
cd tests && python3 -m pytest -q -k mcp    # une partie des tests d'intégration
python3 mcp_client.py   # client MCP minimal : « t-shirt colibri taille M » jusqu'au continue_url
```

- **Unitaires** (`ucpbridge/tests/Unit`, PHPUnit 12, sans base de données) : identifiants d'articles, totaux,
  idempotence, liens signés, PKCE (vecteur RFC 7636), consentements, liens légaux et mention UE, dispatcher MCP.
- **Intégration** (`tests/`, pytest + Playwright, contre http://localhost:8092) : installation et désinstallation,
  profil, catalogue et déclinaisons, `variant_required`, stock par déclinaison, panier et `continue_url` dans le
  navigateur, caisse native, TVA FR et BE, idempotence, codes promo, Google Pay TEST via stripe-mock, OAuth compte
  client, CGV et mentions, relecture des CGV, journal de preuve (back-office), consentements, e-mail de confirmation
  et rétractation (mailpit), MCP (client Python et inspecteur officiel), et les scénarios de la relecture
  0.1.0 (`tests/test_04_relecture.py`) : 4 et 8 `complete` simultanés, `continue_url` ouvert puis `complete`,
  commande passée hors API, échec de commande après débit puis remboursement, énumération des commandes, lecture et
  modification des ressources d'un autre appelant, cloisonnement entre deux clients OAuth, révocation par le client
  et par le marchand, `Origin` MCP ; et ceux de la relecture 0.1.1 (`tests/test_05_relecture2.py`) : exception
  d'un hook après création de la commande (pas de remboursement), 10 tours `PUT` et `complete` simultanés, débit
  refusé sur panier vide, 5 rafraîchissements simultanés (une seule paire), 40 tours révocation et rafraîchissement
  simultanés (aucun jeton survivant), processus arrêté après le débit ou après la commande puis rapprochement en ligne
  de commande, bouton de rapprochement, panier UCP figé après ouverture de son lien ; mise à jour depuis la 0.1.2
  publiée (`tests/test_11_mise_a_jour.py`) : données, configuration et jetons conservés, schéma identique à une
  installation fraîche, script rejouable ; conservation des preuves (`tests/test_12_conserver_preuves.py`) :
  désinstallation puis réinstallation sans perte, suppression sur choix explicite, export CSV (contenu, formule
  neutralisée, téléchargement depuis le back-office, jeton refusé) ; purge RGPD (`tests/test_13_rgpd_purge.py`) :
  durées par défaut, invité sans commande purgé avec adresse, panier et session, invité qui a commandé jamais purgé
  (session gardée sans données personnelles, commande relue), session en cours jamais touchée, codes, jetons,
  paiements, preuves et rétractations selon leur durée, durée 0, simulation, back-office (jeton, durée invalide),
  adresse orpheline, commandes de la boutique inchangées après chaque purge.

## Architecture

```
ucpbridge.php                 module (PaymentModule pour validateOrder), installation, hooks, liens signés, configuration
controllers/front/api.php     REST /ucp/v1/* et MCP /ucp/v1/mcp : authentification, idempotence, routage (dispatch commun)
controllers/front/handoff.php continue_url du panier : rattache le panier au visiteur
controllers/front/terms.php   relecture et acceptation des CGV (requires_buyer_review)
controllers/front/withdraw.php rétractation en ligne et accusé de réception
controllers/front/oauth.php   serveur d'autorisation OAuth 2.0 (identity linking)
controllers/front/access.php  « Mes accès agents » : liste et révocation des assistants reliés (compte client)
classes/UcpBridgeCart.php     coeur métier : panier, caisse, commande, consentements, journal de preuve
classes/UcpBridgeCatalog.php  résolution des articles et déclinaisons, prix, stock, recherche
classes/UcpBridgeDiscount.php codes promo sur les règles panier PrestaShop
classes/UcpBridgeMcp.php      JSON-RPC 2.0 / MCP, traduit chaque outil vers la route REST (logique pure)
classes/UcpBridgeLegal.php, UcpBridgeConsent.php, UcpBridgeTotals.php, UcpBridgeItemRef.php, UcpBridgeSigner.php,
UcpBridgePkce.php             logique pure, couverte par les tests unitaires
classes/UcpBridgeProof.php    tables du journal de preuve, des rétractations et des paiements (débits, remboursements)
classes/UcpBridgeExport.php   export CSV des preuves et rétractations (configuration du module)
classes/payment/              adaptateurs de paiement (interface, registre, mock, Stripe pour Google Pay)
classes/UcpBridgeSchema.php   évolution du schéma en place (colonnes et configuration manquantes), mise à jour
upgrade/upgrade-X.Y.Z.php     scripts de mise à jour lancés par PrestaShop (appellent Ucpbridge::upgradeInPlace)
bin/reconcile.php             rapprochement des sessions bloquées (ligne de commande uniquement)
bin/purge.php                 purge RGPD selon les durées de conservation (ligne de commande uniquement, --dry-run)
classes/UcpBridgeRetention.php durées de conservation, données personnelles d'une session, compte rendu (logique pure)
classes/UcpBridgePurge.php    purge RGPD (sessions, invités sans commande, OAuth, paiements, preuves)
mails/{fr,en}/                modèle de l'accusé de réception de rétractation
```


## Licence

Copyright (c) 2026 Friends of Presta. Auteur : Christophe Vidal, pour le podcast Contrib. Licence AFL-3.0
(Academic Free License 3.0, texte complet dans `LICENSE`). Code écrit à partir de la spécification UCP publique,
sans reprise de code d'autres modules.

## Contribuer et signaler une faille

Les issues et pull requests sont les bienvenues sur le dépôt de Friends of Presta. Une faille de sécurité ne se
signale pas dans une issue publique : voir `SECURITY.md`.
