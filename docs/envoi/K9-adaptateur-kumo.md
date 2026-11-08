# K9 — adaptateur CRM `ServeurEnvoiKumo`

Fichiers : `backend/app/Envoi/Serveur/ServeurEnvoiKumo.php`, `backend/app/Envoi/Serveur/Kumo/*`, tests `backend/tests/Unit/Envoi/Kumo/*` et `backend/tests/Feature/Envoi/` (`TransportSmtpTunnelTest`, `ServeurEnvoiKumoTransactionTest`).

## Règles
- Verrou : sans `ENVOI_REEL_ACTIF` ET `ENVOI_K1_ACTIF`, zéro connexion (injection reportée, interrogation = `inconnu`).
- Machine à états de L4 : `injection_en_cours` écrit AVANT le réseau ; 4xx = report (une tentative par appel, jamais de boucle) ;
  coupure/délai de 30 s après DATA = `a_verifier`, JAMAIS renvoyé (`etatEnvoi` d'abord, sort inconnu = reste `a_verifier`) ;
  3 × 5xx en 10 min = commande `pause`.
- `pause` / `reprise` / `retrait` : `ClientServeurEnvoiHmac::commander` (contrat v2, déjà derrière le verrou).
- Liste blanche des témoins vérifiée avant tout état.
- `TransportSmtpTunnel` : STARTTLS exigé, AUTH après TLS, en-têtes `X-Envoi-Jeton` et `X-Envoi-Source`, chemin de retour VERP
  `rb-<jeton>@bounce.<domaine>` (si `envoi.cle_empreinte` est posée). Il ne se connecte qu'à `envoyer()`.
- Classement : enveloppe invalide refusée AVANT tout état ; 5xx numérique répondu à MAIL, RCPT ou DATA = `echec` + compté pour la pause ;
  5xx de CONNEXION (bannière, EHLO, STARTTLS : avant `MAIL FROM`, ex. IP non autorisée) = `RefusConnexion` : alerte critique + pause + report, JAMAIS `echec` ;
  4xx = report ; coupure après DATA = `a_verifier` ; AUTH 535 ou certificat refusé = `EchecAuthentification` (alerte critique sans
  secret, pause, aucun réessai périodique) ; refus local du transport (jeton, source, transaction, flux) = report + alerte critique, jamais `echec`. `source` est validée à la construction (`[A-Za-z0-9._-]{1,64}`) ; le jeton fait exactement 32 hexadécimaux (sinon refus : le VERP ne se pose qu'à 32).
- Registre cloisonné par espace (clés, pause, compteur de 5xx, séquence) ; `lireEvenements` pagine par numéro de séquence stable
  assigné à l'acceptation. `RegistreMemoire` refuse de se construire hors environnement `testing`.
- Rejeu des commandes : AUCUNE garantie ici. Sur `main`, `RejoueurCommandes` rejoue toute commande non acquittée, y compris une `pause` périmée par une `reprise` acquittée (dette n° 9).

## Écart à l'ADR K0 D7 (à trancher par le responsable du projet)
La consigne du lot demande l'injection SMTP par un tunnel. L'ADR K0 D7 retient au contraire HTTPS signé (`POST /v1/injections`)
et écarte tout tunnel vers la prod (modification de la prod = ESCALADE). L'adaptateur est donc écrit derrière l'interface
`TransportInjection` : le transport SMTP fourni ne sert que si le tunnel est décidé ; un transport HTTPS se branche sans toucher la machine à états.
**Aucun branchement, aucune configuration, aucune route n'est ajoutée.**

## Dettes nommées
1. **Kit de conformité réel** (26 § 3.5) reporté (K3/K15). Ici : le kit D4 existant + un faux transport scripté.
2. **Registre durable** : seul `RegistreMemoire` existe. Une table en AJOUT SEUL (hors fichiers autorisés de K9) est à écrire avant toute ouverture du verrou ; sans elle, `a_verifier` ne survit pas au processus.
3. `ServeurEnvoiCommandes` (D4) n'existe pas encore : `retirer()` est une méthode de l'adaptateur ; le test de bout en bout « opposition → retrait < 5 min » est à écrire sur le banc.
4. `etatEnvoi` distant : pas de route de passerelle (K2) ; `InterrogeurEtat` n'a pas d'implémentation réelle.
5. `lireEvenements` local : ordre = ordre du registre ; les vrais événements viennent du tirage K1.
6. **ComposeurMessage non utilisé** par le transport : ni `List-Unsubscribe` ni pied art. 14 ; seuls sujet/corps bruts, `X-Envoi-Jeton`, `X-Envoi-Source` et le VERP sont posés. À brancher avant toute ouverture du verrou.
7. **`reconcilierOrphelines()` n'est appelée par aucun appel planifié** : tant qu'elle n'est pas planifiée (toutes les 5 minutes), une injection tuée en cours reste `injection_en_cours` (jamais renvoyée, mais jamais rapprochée).
8. Séquence des événements : l'attribution `lire` puis `ecrire` du `seq` n'est atomique que dans un registre durable (à garantir par `INSERT … ON CONFLICT`).
9. `commande_id` neuf à chaque appel : le rejeu des `pause` non acquittées après une reprise acquittée reste une dette (lot K1-suite) ; une idempotence par intention (même `commande_id` tant que l'état voulu est inchangé) reste à décider avec le journal.
10. **ESCALADE I1 (architecte, non tranchée) — VERP incompatible avec l'écouteur K4** : le transport pose `MAIL FROM:<rb-…@bounce.<domaine>>` ; l'écouteur K4 (`session.py`, ~l.217-220) répond 550 hors registre. À trancher : QUI pose le VERP (K4 à la sortie, ou KumoMTA) et ce que le CRM envoie en `MAIL FROM`. Tant que ce n'est pas tranché, le VERP du transport ne doit pas être branché.
11. **ESCALADE I2 (architecte, non tranchée) — transaction englobante** : l'adaptateur refuse toute transaction ouverte (`refuserTransactionEnglobante`, transport idem), or `PasserelleLocale::injecter` appelle `serveur->injecter` dans `DB::transaction` (`PasserelleLocale.php`, `DepotSocleDb.php`). Une fois branché, rien ne partirait. Décision requise : sortir l'appel réseau de la transaction (état `injection_en_cours` commité avant) ou autre.
12. **M5 — en-têtes internes** : K4 ne retire pas `X-Envoi-Jeton` / `X-Envoi-Source` avant la sortie (ADR K0, ~l.211) : fuite d'en-têtes internes vers les destinataires. À corriger côté K4 / KumoMTA.
13. **M3** : jeton exigé à 32 hexadécimaux exactement (corrigé ici). **L4** (`AdaptateurSmtp::classer`, `app/Envoi/Smtp/AdaptateurSmtp.php`) a le MÊME défaut que B1 : tout 5xx (bannière comprise) y donne `echec` terminal. Non corrigé ici (hors fichiers autorisés de K9) : dette à traiter dans un lot L4.
14. **M1, M4, M6** (constats mineurs de la revue) : leur libellé n'a pas été fourni à ce lot ; à consigner par le relecteur avant fusion.
