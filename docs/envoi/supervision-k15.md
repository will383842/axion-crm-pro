# Supervision du serveur d'envoi (K15)

Référence : ADR K0 (`docs/adr/ADR-K0-serveur-envoi-propre.md`, homme mort l. 357, certificats l. 186) et plan 33 K15.

## Ce qui tourne
`envoi:supervision-sante` (toutes les 5 minutes) : pour chaque serveur d'envoi actif, une sonde `GET /v1/sante`, les seuils
chiffrés (`config/envoi.php`, clé `sante`), le silence, puis, **une fois par espace**, les contrôles DNS (K12), les listes noires (K13) et le
canal des retours. Elle n'envoie aucun message. Interrupteurs : `ENVOI_REEL_ACTIF`, `ENVOI_K1_ACTIF` et `ENVOI_SANTE_ACTIF` (faux par défaut).

## `envoi:sante` et `envoi:supervision-sante` : deux commandes, deux rôles
- `envoi:sante` (INF-025) appelle `sante()` du serveur d'envoi : c'est le **battement de l'homme mort**. Si le planificateur s'arrête,
  le **serveur** se met lui-même en pause 15 minutes plus tard.
- `envoi:supervision-sante` (K15) lit les mesures détaillées et décide **côté CRM** : alerte, pause, constats.
- Elles se complètent. Éteindre la seconde ne coupe pas la première, mais plus personne ne lit les seuils.

## Couplage avec l'ADR K0 (homme mort)
L'ADR veut qu'un serveur sans appel `sante` signé pendant 15 minutes soit mis en pause. Tant que la route `/v1/sante` n'existe pas côté
passerelle (dette K2-suite), allumer la supervision mettrait les files en pause : `ENVOI_SANTE_ACTIF` est donc faux par défaut. Garde-fou :
si `ENVOI_REEL_ACTIF` **et** `ENVOI_K1_ACTIF` sont vrais alors que `ENVOI_SANTE_ACTIF` est faux, la configuration est signalée : journal
critique au démarrage (`CouplageSante`), alerte du Socle, et la commande planifiée **échoue** au lieu de rester « inerte ».

## Décisions
- **Constats** : un constat par (espace, serveur, code) pour les mesures du serveur ; un constat par (espace, défaut) pour DNS, listes noires et
  retours (source fictive `(espace)`), quel que soit le nombre de serveurs.
- **Alerte** : à l'ouverture d'un constat, ou quand son **palier monte** (attention 1, attention renforcée 2, critique 3). Une redescente est notée
  sans alerte. Une alerte qui n'est pas partie (e-mail en échec, alertes du Socle éteintes, expéditeur refusé) laisse le constat « alerte non
  émise » : elle est **renvoyée à chaque passage** jusqu'au succès (ligne `alerte_emise`, ajout seul). Le titre porte l'espace : le
  regroupement du Socle (15 min) n'absorbe pas l'alerte d'un autre espace.
- **Pause** (critique « serveur » ou « espace ») : posée **avant** l'ouverture du constat. Si elle échoue, le constat est quand même ouvert et
  l'alerte part, puis le passage échoue ; la pause est **retentée à chaque passage** tant que le défaut critique dure et que la pause manque.
  **Si un humain lève la pause alors que le défaut critique persiste, la supervision la repose** (décision). Défaut guéri puis pause levée :
  elle n'est pas reposée. La supervision ne lève jamais une pause. Les critiques DNS et listes noires ne sont pas re-pausés (K12/K13 le font).
- **Un constat qui échoue ne saute pas les autres** ; le passage lève ensuite une exception, la commande compte l'échec et sort en erreur.
- **Silence** : attention dès 10 min sans réponse (tunnel coupé), critique + pause à 15 min (homme mort), avec une **marge de 60 s** (`silence_marge_s`) : le planificateur passe toutes les 5 min à quelques secondes près, sans marge l'alerte serait retardée de 5 min. Après un arrêt de la supervision (trou de plus de 15 min entre deux relevés), le silence se compte depuis la reprise : un seul échec ne déclenche rien. Un refus d'horloge (réponse signée) rétablit « serveur_muet ». Un refus de la passerelle pour
  horloge est « horloge » critique ; un secret ou une adresse absents de la configuration est « configuration_serveur » (attention), jamais « serveur_muet ».
- **Retours muets** : mesure la **vie de la relève IMAP** (`envoi_curseurs_imap.maj_le`), pas l'arrivée de retours : critique + pause si un message parti il y a 2 h ou plus l'a été après la dernière relève et que des messages partent encore. Une petite campagne saine sans aucun rebond ne déclenche rien. Dette : par boîte.
- **Pauses** : motif `sante:<code>:<source>` pour un défaut de serveur (chaque serveur reçoit sa commande de pause), `sante:<code>` pour un défaut d'espace. Un constat redescendu en « attention » dont la pause dure encore le dit dans son alerte ; une alerte non partie n'est jamais acquittée.
- **Couplage** : l'alerte « supervision éteinte alors que l'envoi réel est allumé » part au plus une fois par jour (la commande, elle, échoue à chaque passage).
- **Certificats** : alertes à 30 j (attention), 14 j (attention renforcée, nouvelle alerte) et 7 j (critique, pause).
- **Liste noire** : le constat reste ouvert tant que la pause K13 dure, même si la ressource n'est plus listée.
- **Accès** : `GET /api/v1/envoi/sante` exige `users.manage` (owner, admin) ; `operator` et `viewer` reçoivent 403.
- **Alerte** : jamais par une boîte ni un domaine de prospection. Refusée si l'expéditeur de l'application est une boîte, un domaine d'envoi, un
  sous-domaine d'un domaine d'envoi, ou partage son **domaine organisationnel** (même politique DMARC) avec un domaine d'envoi.

## Prérequis : la relève IMAP doit être planifiée (L8-F12)
Le constat `retours_muets` lit `envoi_curseurs_imap.maj_le` : sans relève qui tourne, il passe en critique et met tout en pause 2 h après le premier
envoi réel. La relève est donc planifiée : `envoi:releve-imap`, **chaque minute**, `withoutOverlapping` et `onOneServer` (`routes/console.php`).
Elle est inerte tant que **`ENVOI_RELEVE_ACTIF`** (faux par défaut) **et** `ENVOI_REEL_ACTIF` ne sont pas vrais : aucun lecteur n'est alors instancié,
aucune connexion IMAP. **À poser avant le premier envoi réel** : `ENVOI_RELEVE_ACTIF=true`, et le lecteur IMAP réel dans le conteneur
(`ReleveImap` / `LecteurBoite`, paramètres tirés du gestionnaire de secrets) — non livré ici : sans lui, la commande échoue (visible), jamais en silence.

## Tables (ajout seul)
`envoi_releves_sante` (relevés) et `envoi_constats_sante` (ouvert / rétabli / alerte_emise). Ces noms (préfixe `envoi_`, puis le sujet) ont été
posés dans la migration `2026_11_13_000150_envoi_sante.php` avant sa première exécution en production : pas de RENAME, rien perdu.
Index couvrants sur `(workspace_id, source, mesure_le)` pour `max`/`min(mesure_le)` (le second restreint aux relevés réussis).

## Dettes nommées
- **K2-suite** : route `GET /v1/sante` côté passerelle (calcul de `file_age_s`, pourcentage mémoire sans division par zéro) et `401` signé `horloge_hors_fenetre`.
- **K15-D1** : *(livré dans cette PR : canal des retours muet 2 h en campagne, constat `retours_muets`.)*
- **K15-D4** (M4) : un espace sans serveur actif n'a ni constat DNS ni constat de listes noires (la supervision est tenue par serveur).
- **K15-D2** (lot L8) : l'écran « Santé du serveur » du plan 33 n'est pas livré ; seul le point d'API `GET /api/v1/envoi/sante` existe.
- **K15-D3** : aucune purge des relevés (≈ 288 par jour et par serveur, ajout seul : le trigger refuse tout DELETE). Prévoir partitionnement ou archivage.
