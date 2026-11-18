# Cahier des charges — C2 (passerelle : injection, transport HTTPS, santé) puis C3 (retraits, aiguilleur branché)

Conception seule : aucun code de l'application n'est modifié par ce document. Rédigé le 16/11/2026 sur `main` (7906511).
Chaque affirmation renvoie au code (`fichier:ligne`). Chemins relatifs à `backend/` sauf ceux qui commencent par `infra/`,
`envoi-serveur/` ou `docs/`. Données d'exemple : `*.example.test` seulement.

**Règles qui ne bougent pas.** `ENVOI_REEL_ACTIF`, `ENVOI_K1_ACTIF`, `ENVOI_SANTE_ACTIF` restent faux. Les drapeaux
`ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` ne sont pas touchés. Aucune suppression ni
purge de données de personnes. Tout reste sur le **faux** serveur d'envoi : la passerelle de production garde `KumoInactif`
(`infra/serveur-envoi/passerelle/public/index.php:33`), les tests utilisent `FauxKumo`
(`infra/serveur-envoi/passerelle/src/FauxKumo.php:34-61`) côté passerelle et `Http::fake` côté CRM. Aucun vrai serveur, aucune
IP, aucun domaine : le jour J (vrai serveur, DNS, port 25, client KumoMTA réel) est décrit à part, lot X9.

**Ordre imposé par #616.** Aucun lot de ce cahier ne modifie `app/Envoi/Sequence/Sequenceur.php`. Mais TR-TRANSPORT-HTTPS
et TR-RETRAIT-CRM LISENT ce que C1-1 (#616, branche `feat/c1-1-registre`) ajoute : `MessageAEnvoyer::$idInjection` et
`$cheminRetour` (absents de `main` : `app/Envoi/Contrat/MessageAEnvoyer.php:8-15`) et la réservation
`envoi_injections_locales` AVANT le réseau (`RegistreInjectionsSequence`, absent de `main`). Ces deux lots ne partent
qu'après la fusion ET le déploiement de #616. Les lots côté passerelle (C2-1 à C2-3) et la migration C3-0 peuvent partir avant.

---

## 0. Constats vérifiés dans le code

| N° | Constat | Preuve |
|----|---------|--------|
| P1 | La passerelle ne connaît que deux routes : `GET /v1/evenements` et `POST /v1/commandes/<op>`. Ni `/v1/sante`, ni `/v1/injections`. | `infra/serveur-envoi/passerelle/src/Passerelle.php:153-163` |
| P2 | Le contrat demande `GET /v1/sante` et un `401` **signé** `horloge_hors_fenetre` ; la dette est écrite dans le contrat lui-même. Aujourd'hui l'écart d'horloge rend un `401` NON signé. | `envoi-serveur/contrat/README.md:23-67` ; `Passerelle.php:113-115`, `:137-140` |
| P3 | Le CRM sait déjà lire `/v1/sante` (y compris le 401 signé) : seule la passerelle manque. | `app/Envoi/Serveur/ClientServeurEnvoiHmac.php:128-137` ; `app/Envoi/Sante/SondeSanteK1.php:13-23` ; `app/Envoi/Serveur/SignatureEnvoi.php:18` |
| P4 | Avant même l'authentification, un corps de plus de 2 × 65 536 octets est refusé `401` : un message RFC 5322 réel ne passerait pas. | `Passerelle.php:14`, `:95` |
| P5 | Une commande exige EXACTEMENT `{commande_id, commande}` ; un `retrait` ne porte donc aucune cible (il viderait « la file » sans dire laquelle). | `Passerelle.php:186-192` ; `app/Envoi/Serveur/ServeurEnvoiKumo.php:413-417` ; `ClientServeurEnvoiHmac.php:139-161` |
| P6 | Les commandes sont réservées AVANT l'appel KumoMTA (une seule exécution) ; un client KumoMTA plus lent que la réservation est refusé. Modèle à reprendre pour l'injection. | `Passerelle.php:196-227`, `:57-60` ; `src/Stockage.php:20`, `:184-239` ; `src/Kumo.php:15` |
| T1 | Le seul transport d'injection existant passe par un tunnel SMTP (décision E1 du plan : à remplacer par HTTPS signé `POST /v1/injections`, ADR K0 D7). | `app/Envoi/Serveur/Kumo/TransportSmtpTunnel.php:33-52` ; `docs/adr/ADR-K0-serveur-envoi-propre.md:156`, `:225` |
| T2 | Ce tunnel RECONSTRUIT un courriel à partir de `sujet` + `corps` en texte : le message composé (List-Unsubscribe, pied art. 14) part comme texte brut (dette 6), et il ajoute `X-Envoi-Jeton`. Or `corps` est déjà le message RFC 5322 complet rendu par le composeur. | `TransportSmtpTunnel.php:163-177` (`:174`, `:175`) ; `app/Envoi/Sequence/Sequenceur.php:321-335` |
| T3 | L'adaptateur Kumo dérive son identifiant d'un jeton HMAC, sans lire l'identifiant choisi par le CRM (C1). | `ServeurEnvoiKumo.php:115-117`, `:178-191` |
| T4 | Le registre d'état de l'adaptateur Kumo n'a QU'une implémentation mémoire, qui refuse de se construire hors tests : l'adaptateur ne peut pas être lié en production sans registre durable (dette nommée K9-registre-durable). | `app/Envoi/Serveur/Kumo/RegistreInjections.php:26-43` ; `Kumo/RegistreMemoire.php:26-35` |
| T5 | `ServeurEnvoiKumo` ne se connecte à rien tant que `reel_actif` ET `k1_actif` ne sont pas vrais ; le client HMAC refuse tout appel réseau dans une transaction et sans les deux interrupteurs. | `ServeurEnvoiKumo.php:93-95`, `:340-343` ; `ClientServeurEnvoiHmac.php:163-176` |
| R1 | Le retrait côté CRM est lié à l'implémentation « absente » (rend 0). Tant qu'elle l'est, la porte d'envoi réel reste fermée et le point de feu vert `auto.retrait_file` est rouge. | `app/Providers/EnvoiServiceProvider.php:171` ; `app/Envoi/Desinscription/RetraitEnvoisEnFileAbsent.php:20-26` ; `app/Envoi/Orchestration/PorteEnvoiReelle.php:45-50` ; `app/Envoi/FeuVert/ListeFeuVert.php:65` |
| R2 | L'événement `OppositionEnregistree` est émis DANS une transaction (verrou de ligne sur l'opposition). Un retrait qui appellerait le réseau à cet endroit serait refusé par le client HMAC (T5). Il faut une **boîte d'envoi** (demande écrite, envoyée hors transaction par une tâche planifiée). | `app/Envoi/Desinscription/TerminerEffetsOpposition.php:40-52` ; `EffetsOpposition.php:71-87` |
| R3 | L'empreinte de l'événement est la même que celle des inscriptions et du registre des messages envoyés (`ListeSuppression::empreinte`). | `OppositionEnregistree.php:11-17` ; `Sequenceur.php:301`, `:471` ; `app/Envoi/Imap/ReleveImap.php:321` |
| R4 | Le journal des commandes garde un champ `details` (TEXT, JSON) déjà écrit à chaque commande : les cibles d'un retrait peuvent y vivre SANS migration. Le rejoueur renvoie le même `commande_id` : il doit renvoyer les mêmes cibles. | `database/migrations/2026_11_10_000011_envoi_journal_commandes.php:22` ; `app/Envoi/Serveur/JournalCommandes.php:20-38`, `:63-76` ; `app/Envoi/Serveur/RejoueurCommandes.php:29-39` |
| A1 | Réserve M1 de #594 : `pause()` appelle chaque fabrique avec l'espace COURANT ou `''` et le domaine `''` : une pause dans l'espace A ne touche pas le serveur de l'espace B. TODO dans le code et test en `skip`. | `app/Envoi/Serveur/AiguilleurServeurEnvoi.php:134-150` (`:136-137`) ; `tests/Feature/Envoi/AiguilleurServeurEnvoiTest.php:777-779` |
| A2 | Réserve M2 de #594 : le test « une fabrique réelle instanciée ne fait aucune connexion » est en `skip`. | `tests/Feature/Envoi/BranchementInerteTest.php:179-183` |
| A3 | La fonction `envoi_adaptateurs_reels_portes()` rend les NOMS d'adaptateurs, pas les couples (espace, domaine) : elle ne suffit pas pour M1. | `database/migrations/2026_12_03_000040_envoi_domaines_adaptateur.php:77-80` ; `AiguilleurServeurEnvoi.php:232-239` |
| A4 | Réserve F2 : `injecter` ne compare pas l'espace du message à `WorkspaceContext::current()` (seul l'adaptateur Kumo compare à SON espace). | `AiguilleurServeurEnvoi.php:52-79` ; `ServeurEnvoiKumo.php:88-90` |
| A5 | Réserve F3 : `sante()` recopie le `detail` de chaque adaptateur tel quel. | `AiguilleurServeurEnvoi.php:190-206` |
| A6 | Le commentaire sur le registre Kumo est périmé (relecture 2 de #594, point a). | `AiguilleurServeurEnvoi.php:85-86` |
| A7 | La liste des fabriques est vide : aucun adaptateur réel n'est lié. | `app/Providers/Envoi/AiguillageServiceProvider.php:108-113` |
| A8 | Aucune `GardeConformiteSortie` n'existe (décision E6 : List-Unsubscribe https + mailto, One-Click, aucun `X-Envoi-*` à la sortie). | recherche `class GardeConformiteSortie` dans `app/` : aucun résultat |
| E1 | Les points d'entrée préparés sont vides : `app/Providers/Envoi/TransportServiceProvider.php:13-22`, `routes/console/envoi-transport.php:1-5`. Ils sont enregistrés après les fournisseurs existants. | `EnvoiServiceProvider.php:180-185` |

---

## 1. Découpage et ordre (une PR à la fois, 250 à 400 lignes tests compris)

| PR | Lot | Contenu | Dépend de |
|----|-----|---------|-----------|
| C2-1 | C-SANTE | `GET /v1/sante` + `401` signé « horloge » dans la passerelle | — (premier sur `Passerelle.php`) |
| C2-2 | TR-PASSERELLE-INJ (1/2) | `POST /v1/injections` : schéma, idempotence, réservation, `FauxKumo` | C2-1 fusionnée (même fichier chaud) ; C-CONFIG fusionnée s'il est parti avant |
| C2-3 | TR-PASSERELLE-INJ (2/2) | `GET /v1/injections/{id}`, traduction des codes, kit de conformité | C2-2 |
| C2-4 | TR-TRANSPORT-HTTPS | `TransportHttpsSigne` + `InterrogeurEtatHttps` côté CRM, adaptateur Kumo sur l'identifiant C1 | C2-3 ET #616 fusionnée et déployée |
| C3-0 | migration seule | `2026_12_05_000300` à `000320` (boîte de retraits, registre Kumo durable, couples portés) | — (peut partir dès maintenant) |
| C3-1 | TR-RETRAIT-SRV | contrat v3 du retrait (cibles), liste de retrait 30 jours, `409 retire` à l'injection | C2-3 (même fichier chaud) |
| C3-2 | TR-REGISTRE-KUMO | `RegistreInjectionsDb` (implémentation durable de `RegistreInjections`) | C3-0 fusionnée ET déployée |
| C3-3 | TR-RETRAIT-CRM | retrait réel lié : boîte de retraits + tâche planifiée + commande v3 | C3-0 déployée, C3-1, #616 |
| C3-4 | TR-AIG-BRANCHE (1/2) | pause globale (M1), F2, F3, garde de conformité E6, commentaire A6 | C3-0 déployée |
| C3-5 | TR-AIG-BRANCHE (2/2) | fabrique `kumo` liée (inerte porte fermée), test M2 bloquant, test kumo+smtp | C2-4, C3-2, C3-3, C3-4 |

Plage de migrations réservée : `2026_12_05_000300` à `000390`. Ce cahier en utilise trois.
Règle de déploiement : le code est remplacé AVANT `migrate` (`.github/workflows/deploy-direct-ssh.yml:443` puis `:561`) ;
toute PR qui lit une table nouvelle attend que C3-0 soit fusionnée ET déployée, et toute tâche planifiée garde `Schema::hasTable`.

Vague conseillée (une fusion à la fois) : C2-1 et C3-0 tout de suite ; puis C2-2, C2-3 ; dès #616 fusionnée : C2-4 ;
puis C3-1, C3-2 (parallèle possible : aucun fichier commun), C3-3, C3-4, C3-5.

---

## 2. Vague C2

### 2.1 C2-1 — C-SANTE (`GET /v1/sante`)

**But.** Fermer la dette P2 : la route existe, répond signée, sans aucune écriture.

Fichiers autorisés :
- `infra/serveur-envoi/passerelle/src/Passerelle.php` (chaud)
- `infra/serveur-envoi/passerelle/src/Sante/Mesureur.php` (interface, nouveau), `src/Sante/MesureurSysteme.php` (nouveau),
  `src/Sante/MesureurFixe.php` (nouveau, pour les tests)
- `infra/serveur-envoi/passerelle/public/index.php` (construction du mesureur seulement)
- `infra/serveur-envoi/passerelle/tests/SanteTest.php` (nouveau)
- `envoi-serveur/contrat/README.md` (chaud : retirer le paragraphe « Dette K2-suite », `:65-67`) et `docs/envoi/supervision-k15.md:17`, `:60`

Conception :
```php
interface Mesureur { /** @return array<string, int|float|bool> clés du contrat seulement ; une clé inconnue est OMISE */ public function mesurer(): array; }
```
- `Passerelle::__construct` reçoit un 7ᵉ paramètre facultatif `?Mesureur $mesureur = null` (défaut : aucun mesureur ⇒
  `mesures: {"horloge_unix": <now>}` seulement). Aucun appelant cassé.
- `router` : `if ($chemin === '/v1/sante') return GET ? $this->sante($requete) : 405`. Chaîne de requête NON vide ⇒ `400 requete`.
- `sante()` : `Reponse::json(200, ['mesures' => $m])` ; `horloge_unix` toujours lu au moment de la réponse ; aucune écriture
  (le nonce est retenu comme pour toute route : `:127`). Toute exception du mesureur ⇒ la clé fautive est omise, jamais un 500.
- `MesureurSysteme` (lecture seule, aucun `exec`, aucun réseau) : `disque_libre_pct` = `disk_free_space/disk_total_space` du
  dossier de la base ; `memoire_libre_pct` = `MemAvailable/MemTotal` de `/proc/meminfo` (division interdite si total ≤ 0 :
  clé omise, contrat `README.md:47`) ; `certificat_jours` depuis le fichier nommé par `PASSERELLE_CERTIFICAT` (clé omise sinon) ;
  `ntp_synchro` = présence de `/run/systemd/timesync/synchronized`. `file_age_s` et `tunnel_ok` sont OMIS : ils demandent
  l'API d'administration KumoMTA (lot C4 TR-KUMO-ADMIN). Le CRM les classe alors « mesure absente » (attention), jamais vert.
- **401 signé « horloge »** (`:113-115`) : après une signature VALIDE, un horodatage hors fenêtre rend
  `$this->signer(Reponse::json(401, ['erreur' => 'horloge_hors_fenetre']), $cle, $ws, $nonce, $cleId)`, pour TOUTE route,
  SANS ouvrir la base et SANS retenir le nonce. Une signature invalide reste un `401` non signé (`:110-112`). Ce 401 signé
  ne révèle rien : il est lié au nonce et à l'espace de la requête, que l'appelant connaît déjà.

Tests ROUGES d'abord (`tests/SanteTest.php`, modèle `tests/PasserelleTestCase.php`) :
1. GET signé `/v1/sante` ⇒ 200 signé, `workspace_id` présent, `mesures.horloge_unix` entier. Rouge sur `main` (404).
2. `MesureurFixe` avec `memoire_libre_pct` absent ⇒ clé absente (pas 0, pas NaN) ; mesureur qui lève ⇒ 200 avec `horloge_unix`.
3. Horodatage décalé de 301 s, signature valide ⇒ 401 SIGNÉ `horloge_hors_fenetre` (rouge sur `main` : non signé) ; même
   requête avec signature fausse ⇒ 401 NON signé ; aucun nonce retenu (rejouer la requête à l'heure ⇒ 200).
4. POST `/v1/sante` ⇒ 405 ; `/v1/sante?x=1` ⇒ 400 ; aucune ligne écrite (compter les tables du `Stockage`).
5. Côté CRM (aucun fichier CRM modifié) : `tests/Feature/Envoi/Sante/SanteConformeContratTest.php` reste vert.

Mutations : signer le 401 AVANT la vérification de signature ⇒ test 3 ; écrire `0` quand la mémoire est illimitée ⇒ test 2.

### 2.2 C2-2 — TR-PASSERELLE-INJ (1/2) : `POST /v1/injections`

Fichiers autorisés : `infra/serveur-envoi/passerelle/src/Passerelle.php` (chaud), `src/Stockage.php` (table ajoutée),
`src/Injection/Validateur.php` (nouveau), `src/FauxKumo.php`, `src/Kumo.php` (docblock), `tests/InjectionTest.php` (nouveau),
`envoi-serveur/contrat/README.md` (section « POST /v1/injections »).

Corps (JSON, l'espace vient de la SIGNATURE, jamais du corps) :
```json
{ "id_injection": "<uuid C1>", "mail_from": "rb-<42 hex>@bounce.exemple.example.test", "rcpt_to": "contact@client.example.test",
  "message": "<RFC 5322 en base64>" }
```
Validation (`Injection\Validateur`, tout refus = `400 <motif>`, rien n'est stocké) :
- clés exactement ces quatre ; `id_injection` UUID canonique ; `mail_from` de forme VERP (`/^rb-[0-9a-f]{42}@bounce\.[a-z0-9.-]+$/`,
  même règle que `MessageAEnvoyer` de #616) ; `rcpt_to` UNE adresse ASCII, sans CR/LF, sans virgule (anti-relais : un seul RCPT) ;
- `message` base64 strict, décodé ≤ 1 048 576 octets ; lignes terminées par CRLF seulement (un LF nu ⇒ `400 fin_de_ligne`,
  anti « SMTP smuggling ») ;
- en-têtes du message : aucun `X-Envoi-*` (E2/E6), aucun `DKIM-Signature` (D3), `List-Unsubscribe` ET
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click` présents (E6) sinon `422 conformite` ; le domaine de `From:` doit être
  le parent de `bounce.<domaine>` de `mail_from` (alignement) sinon `422 alignement`.
- Plafond AVANT authentification (`:95`) : remplacé par `TAILLE_MAX_INJECTION_BRUTE = 1_500_000` pour le seul chemin
  `/v1/injections`, `TAILLE_MAX_CORPS * 2` ailleurs (inchangé).

Idempotence et réservation (même schéma que les commandes, P6) — table SQLite ajoutée dans `Stockage::migrer` :
```sql
CREATE TABLE IF NOT EXISTS injections (
  workspace_id TEXT NOT NULL, id_injection TEXT NOT NULL, empreinte TEXT NOT NULL,
  etat TEXT NOT NULL CHECK (etat IN ('en_cours','en_file','refuse','incertain','retire')),
  statut INTEGER, reponse TEXT, instant INTEGER NOT NULL,
  PRIMARY KEY (workspace_id, id_injection)) WITHOUT ROWID;
```
- `empreinte = sha256(corps exact)`. Même id + même empreinte ⇒ réponse rejouée ; même id + autre empreinte ⇒ `409 id_injection_deja_utilise`.
- Réservation `en_cours` AVANT l'appel KumoMTA ; une réservation de plus de `DELAI_RESERVATION` se reprend (comme `reserverCommande`).
- Appel : `$this->kumo->executer($ws, 'injecter', ['mail_from' => …, 'rcpt_to' => …, 'message' => <octets>])`. `FauxKumo` enregistre
  l'appel et rend `['accepte' => true]`. `'injecter'` n'est PAS ajouté à `OPERATIONS` (`:19`) : `POST /v1/commandes/injecter` reste 404.
- Réponse acceptée : `202 {"id_injection": …, "etat": "en_file"}`. **Le corps du message n'est jamais journalisé ni rendu ;**
  la table ne garde ni adresse ni message (seulement l'empreinte du corps).
- La table `injections` n'est jamais vidée par ce lot (aucune purge ajoutée ; `purger()` existant, `Stockage.php:555`, n'y touche pas).

Tests ROUGES d'abord (`tests/InjectionTest.php`, `FauxKumo`) : 202 et un appel `FauxKumo` (rouge sur `main` : 404) ; rejeu
identique ⇒ même réponse, un seul appel ; même id autre corps ⇒ 409 ; deux requêtes concurrentes (crochet `FauxKumo::$pendant`)
⇒ un seul appel ; chaque refus de validation ci-dessus (un test par motif) ; espace B qui rejoue l'id de A ⇒ traité comme
nouveau pour B, jamais la réponse de A ; corps de 1,2 Mo signé ⇒ accepté, 3 Mo ⇒ 401 avant lecture ; `KumoInactif` ⇒ `503`
et réservation rendue (rejouable).
Mutations : retirer la comparaison d'empreinte ⇒ test 409 ; accepter un LF nu ⇒ test fin de ligne ; lire l'espace dans le
corps ⇒ test espace B.

### 2.3 C2-3 — TR-PASSERELLE-INJ (2/2) : état et traduction

Fichiers autorisés : `src/Passerelle.php` (chaud), `src/Stockage.php`, `src/Injection/RefusKumo.php` (nouveau : exception
portant le code SMTP), `src/FauxKumo.php` (réglage du code rendu), `tests/InjectionEtatTest.php` (nouveau),
`infra/serveur-envoi/tests/conformite/ConformiteTest.php` (cas « injection »), `envoi-serveur/contrat/README.md`.

- Traduction (ADR K0 D7, `docs/adr/ADR-K0-serveur-envoi-propre.md:156`) : `RefusKumo` 4xx ⇒ `429` + `Retry-After: 900` +
  `{"code_smtp": 451}`, état `en_file` NON écrit, réservation rendue ; `RefusKumo` 5xx ⇒ `422 {"erreur":"refus_definitif","code_smtp":550}`,
  état `refuse` ; `\RuntimeException` AVANT acceptation (injoignable) ⇒ `503 indisponible`, réservation rendue ; dépassement
  de `Kumo::DELAI_MAX_SECONDES` après envoi ⇒ `504 issue_incertaine`, état `incertain` (jamais renvoyé).
- `GET /v1/injections/{uuid}` (chaîne de requête vide) ⇒ `200 {"id_injection", "etat"}` avec `etat` ∈ `en_file | refuse |
  incertain | retire | inconnu` (ligne absente ⇒ `inconnu`, jamais 404 : ne révèle pas l'existence dans un autre espace).
- Kit de conformité : un cas « injection acceptée, rejouée, refusée 422 » contre `php -S` + faux KumoMTA (`.github/workflows/passerelle-envoi.yml:48`).

Tests ROUGES d'abord : chaque traduction (FauxKumo réglé sur 451, 550, panne, lenteur) ; GET d'un id de l'espace A signé par B
⇒ `inconnu` ; `incertain` jamais ré-exécuté au rejeu. Mutation : traduire 4xx en 422 ⇒ test 429.

### 2.4 C2-4 — TR-TRANSPORT-HTTPS (côté CRM, après #616)

Fichiers autorisés :
- `app/Envoi/Serveur/Kumo/TransportHttpsSigne.php` (nouveau, implémente `TransportInjection`)
- `app/Envoi/Serveur/Kumo/InterrogeurEtatHttps.php` (nouveau, implémente `InterrogeurEtat`)
- `app/Envoi/Serveur/ClientServeurEnvoiHmac.php` (deux méthodes publiques ajoutées, rien d'autre)
- `app/Envoi/Serveur/SignatureEnvoi.php` (constante `CHEMIN_INJECTIONS = '/v1/injections'`)
- `app/Envoi/Serveur/ServeurEnvoiKumo.php` (identifiant C1, retrait 409)
- `tests/Feature/Envoi/TransportHttpsTest.php`, `tests/Unit/Envoi/Kumo/ServeurEnvoiKumoTest.php`
- `docs/envoi/K9-adaptateur-kumo.md` (dette 6 soldée)

Interdits : `Sequenceur.php`, `ComposeurSequenceMessage.php`, `AiguilleurServeurEnvoi.php`, `AiguillageServiceProvider.php`,
`EnvoiServiceProvider.php`, `TransportSmtpTunnel.php` (laissé tel quel, plus utilisé : retrait dans un lot de nettoyage), `config/envoi.php`.

Conception :
```php
// ClientServeurEnvoiHmac — mêmes contrôles locaux que commander() (preparer(), :203-233), réponse signée exigée
/** @return array{statut: int, json: array<string, mixed>} statuts lus : 202, 409, 422, 429, 503, 504 */
public function injecter(string $workspaceId, string $source, string $corpsJson): array;
/** @return array{statut: int, json: array<string, mixed>} */
public function etatInjection(string $workspaceId, string $source, string $idInjection): array;
```
- `TransportHttpsSigne::envoyer($message, $jeton, $source)` : exige `$message->idInjection` et `$message->cheminRetour`
  (sinon `ServeurEnvoiRefuse::motif('identifiant ou chemin de retour absent')`, AVANT tout réseau) ; corps JSON
  `{id_injection, mail_from: cheminRetour, rcpt_to: destinataire, message: base64_encode(corps)}` : le message part TEL QUEL
  (fin de la dette 6, T2). Aucune transaction ouverte (règle `:163-167`).
- Traduction en code SMTP pour `ServeurEnvoiKumo` : 202 ⇒ 250 ; 429 ⇒ `code_smtp` (4xx) ; 422 ⇒ `code_smtp` (5xx) ;
  503 ⇒ `CoupureTransport(false)` ; 504 ou délai dépassé après envoi ⇒ `CoupureTransport(true)` ; 401 non signé ⇒
  `EchecAuthentification` ; 401 signé `horloge_hors_fenetre` ⇒ `CoupureTransport(false)` + journal critique ;
  409 `id_injection_deja_utilise` ⇒ `CoupureTransport(true)` (incertain : jamais renvoyé) ; 409 `retire` ⇒ exception
  `Kumo\InjectionRetiree` (nouvelle, 10 lignes).
- `ServeurEnvoiKumo` : `$id = $message->idInjection ?? ($ligne['id'] ?? self::idDepuisJeton($jeton))` (`:117`) ; si
  `idInjection` est présent, le jeton devient `str_replace('-', '', $idInjection)` (aucun secret nouveau). `InjectionRetiree`
  ⇒ état `echec` SANS `noter5xx` puis `throw EnvoiRefuse::opposition()` (le séquenceur arrête déjà l'inscription en
  `garde:opposition` : `Sequenceur.php:167-170` ; la passerelle locale relaie : `PasserelleLocale.php:171-174`).
- `InterrogeurEtatHttps::etat($id)` : `en_file` ⇒ `EnFile`, `refuse` ⇒ `Refuse`, `retire` ⇒ `Refuse`, `incertain`/`inconnu` ⇒ `Inconnu`.

Tests ROUGES d'abord (`tests/Feature/Envoi/TransportHttpsTest.php`, réponses signées avec `SignatureEnvoi`, modèle
`tests/Feature/Envoi/ClientEtJournalEnvoiTest.php:106-130`, `config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true])`
DANS le test seulement, `Http::fake`, `Http::preventStrayRequests()`) :
1. Le corps posté contient `message` = base64 du `corps` EXACT (List-Unsubscribe présent), aucun `X-Envoi-Jeton`. Rouge sur `main` (classe absente).
2. Chaque statut ⇒ bon code ou bonne exception ; 409 `retire` ⇒ `EnvoiRefuse::opposition`, compteur 5xx inchangé.
3. Sans `idInjection` ⇒ refus local, AUCUNE requête (`Http::assertNothingSent`).
4. Interrupteurs faux (valeurs par défaut) ⇒ aucune requête, injection reportée (`ServeurEnvoiKumo.php:93-95`).
5. Réponse signée avec un autre espace ⇒ refus (`ClientServeurEnvoiHmac.php:284-287`).
6. Sous `pgsql_app` ET sous le propriétaire : `preparer()` lit `envoi_serveurs` de l'espace A seulement (source de B ⇒ « source inconnue »).
Mutations : envoyer `text(corps)` reconstruit ⇒ test 1 ; compter 409 `retire` comme 5xx ⇒ test 2 ; retirer la garde `idInjection` ⇒ test 3.

---

## 3. Vague C3

### 3.0 C3-0 — migration seule (ajouts seuls)

Fichiers autorisés : les trois migrations ci-dessous, `tests/Feature/Envoi/SchemaTransportC3Test.php` (nouveau),
`tests/Feature/NonRegression/Nr17MigrationsTest.php` (liste `NR17_RETOUR_INERTE_VOULU`, `:41`), instantanés NR-07, NR-08,
NR-17, NR-20 (chauds) avec « Filet modifié : pourquoi » dans la PR.

**`2026_12_05_000300_envoi_retraits_demandes.php`** — boîte d'envoi des retraits (R2) :
```sql
CREATE TABLE IF NOT EXISTS envoi_retraits_demandes (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  workspace_id UUID NOT NULL REFERENCES workspaces(id),
  empreinte_adresse CHAR(64) NOT NULL CHECK (empreinte_adresse ~ '^[0-9a-f]{64}$'),
  statut TEXT NOT NULL DEFAULT 'en_attente' CHECK (statut IN ('en_attente','envoye','sans_objet')),
  commande_id UUID, cibles_nb INT CHECK (cibles_nb IS NULL OR cibles_nb BETWEEN 0 AND 100),
  tentatives INT NOT NULL DEFAULT 0, cree_le TIMESTAMPTZ NOT NULL DEFAULT now(), traite_le TIMESTAMPTZ);
CREATE INDEX IF NOT EXISTS envoi_retraits_demandes_attente ON envoi_retraits_demandes (workspace_id, cree_le) WHERE statut = 'en_attente';
```
RLS `ENABLE` + `FORCE` (politique identique à `2026_11_10_000032_envoi_messages.php:34-40`) ; déclencheur qui refuse DELETE
et TRUNCATE, et toute sortie d'un état final (`envoye`, `sans_objet` figés) ; rôle applicatif : `SELECT, INSERT` +
`UPDATE (statut, commande_id, cibles_nb, tentatives, traite_le)`. Aucune adresse en clair.

**`2026_12_05_000310_envoi_registre_kumo.php`** — registre durable de l'adaptateur Kumo (T4) :
```sql
CREATE TABLE IF NOT EXISTS envoi_kumo_injections (
  workspace_id UUID NOT NULL REFERENCES workspaces(id), cle_idempotence TEXT NOT NULL CHECK (char_length(cle_idempotence) BETWEEN 1 AND 200),
  id_injection UUID NOT NULL, etat TEXT NOT NULL CHECK (etat IN ('a_injecter','injection_en_cours','accepte','a_verifier','reporte','echec')),
  reporte_au BIGINT, instant BIGINT NOT NULL, jeton CHAR(32) NOT NULL CHECK (jeton ~ '^[0-9a-f]{32}$'),
  code SMALLINT CHECK (code IS NULL OR code BETWEEN 200 AND 599), seq BIGINT,
  PRIMARY KEY (workspace_id, cle_idempotence));
CREATE TABLE IF NOT EXISTS envoi_kumo_etat (workspace_id UUID PRIMARY KEY REFERENCES workspaces(id),
  en_pause BOOLEAN NOT NULL DEFAULT false, derniere_seq BIGINT NOT NULL DEFAULT 0, remise_5xx_le BIGINT NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS envoi_kumo_5xx (id BIGSERIAL PRIMARY KEY, workspace_id UUID NOT NULL REFERENCES workspaces(id), instant BIGINT NOT NULL);
```
RLS FORCE sur les trois ; DELETE/TRUNCATE refusés partout ; `envoi_kumo_5xx` en ajout seul (la « remise à zéro » écrit
`remise_5xx_le`, elle n'efface rien) ; une fois `seq` posé, il ne change plus (déclencheur) ; droits : `SELECT, INSERT, UPDATE`
sur `envoi_kumo_injections` et `envoi_kumo_etat`, `SELECT, INSERT` sur `envoi_kumo_5xx` (+ séquence).

**`2026_12_05_000320_envoi_couples_reels_portes.php`** — pour M1 (A3) :
```sql
CREATE OR REPLACE FUNCTION envoi_couples_reels_portes() RETURNS TABLE (workspace_id UUID, domaine TEXT, adaptateur TEXT) AS $f$
  SELECT workspace_id, nom, adaptateur FROM public.envoi_domaines WHERE adaptateur <> 'faux' ORDER BY workspace_id, adaptateur, nom;
$f$ LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public;
REVOKE ALL ON FUNCTION envoi_couples_reels_portes() FROM PUBLIC;  -- puis GRANT EXECUTE au rôle applicatif
```
Ne rend aucune adresse, seulement des noms de domaine d'envoi (comme la fonction existante, `2026_12_03_000040:77-89`).

`down()` INERTE pour les deux tables (les retirer effacerait des données) ; la fonction peut être retirée (pas une donnée).

Tests ROUGES d'abord (`SchemaTransportC3Test.php`, sous `pgsql_app` ET sous le propriétaire) : tables et colonnes présentes ;
`has_table_privilege` / `has_column_privilege` exacts (UPDATE de `empreinte_adresse` refusé) ; DELETE refusé ; `envoye ⇒ en_attente`
refusé ; RLS : une ligne de A invisible depuis B sous `pgsql_app` ; `envoi_couples_reels_portes()` voit A et B depuis un
contexte vide sous `pgsql_app` ; aller-retour-aller NR-17.

### 3.1 C3-1 — TR-RETRAIT-SRV (passerelle, contrat v3)

Fichiers autorisés : `infra/serveur-envoi/passerelle/src/Passerelle.php` (chaud), `src/Stockage.php`, `tests/RetraitTest.php`
(nouveau), `tests/CommandesTest.php` (cas « retrait sans cible » : son attendu change, voir plus bas), `envoi-serveur/contrat/README.md`.

- Corps de `retrait` (v3) : `{commande_id, commande: "retrait", cibles: [uuid, …]}`, 1 à 100 UUID distincts. Pour `pause` et
  `reprise`, `cibles` reste interdit (`:188`). `retrait` SANS `cibles` ⇒ `400 cibles_exigees` (aujourd'hui accepté : P5).
  Ce n'est pas un affaiblissement : un retrait sans cible n'a aucun sens sûr. Le test existant qui l'accepte est remplacé
  par son contraire, à écrire dans « Filet modifié : pourquoi ».
- Empreinte de la commande (`Passerelle::empreinte`, `:78-81`) : pour `retrait`, `sha256(op \n id \n cibles triées jointes par ",")`
  ⇒ même `commande_id` avec d'autres cibles = `409 commande_id_deja_utilise`.
- Liste de retrait : table SQLite `retraits (workspace_id, id_injection, retire_le, PRIMARY KEY(workspace_id, id_injection))`.
  Chaque cible y est inscrite (ajout seul), puis `kumo->executer($ws, 'retrait', ['cibles' => …])` pour la file du moteur.
  Une injection dont l'id est dans la liste (moins de 30 jours) ⇒ `409 {"erreur":"retire"}` AVANT tout appel KumoMTA, et `GET
  /v1/injections/{id}` ⇒ `retire`. La liste est inscrite AVANT l'appel KumoMTA : un moteur en panne n'empêche pas le refus des
  injections à venir. Résultat : `{"retirees": <n inscrites>}`.
- Le retrait RÉEL dans la file KumoMTA (API d'administration) reste « À VÉRIFIER » (plan transport, E3) : lot C4 TR-KUMO-ADMIN.

Tests ROUGES d'abord : retrait de 2 cibles puis injection de l'une ⇒ 409 `retire` (rouge sur `main`) ; sans cibles ⇒ 400 ; 101
cibles ⇒ 400 ; même `commande_id` autres cibles ⇒ 409 ; cibles de A n'affectent pas B ; `FauxKumo` en échec ⇒ cibles quand même
inscrites, réponse 502 rejouable. Mutation : inscrire la liste APRÈS l'appel KumoMTA ⇒ test « moteur en panne ».

### 3.2 C3-2 — TR-REGISTRE-KUMO (registre durable)

Fichiers autorisés : `app/Envoi/Serveur/Kumo/RegistreInjectionsDb.php` (nouveau), `app/Providers/Envoi/TransportServiceProvider.php`
(liaison `RegistreInjections::class => RegistreInjectionsDb::class`), `tests/Feature/Envoi/RegistreInjectionsDbTest.php` (nouveau).

- Chaque méthode sous `WorkspaceContext::run($ws, …)` ET `where workspace_id` explicite.
- `ecrireSi` : `INSERT … ON CONFLICT DO NOTHING` si `etatAttendu === null`, sinon `UPDATE … WHERE workspace_id = ? AND
  cle_idempotence = ? AND etat = ? AND instant = ?` ; vrai si une ligne est touchée (CAS, `RegistreInjections.php:51-59`).
- `prochaineSequence` : `INSERT … ON CONFLICT (workspace_id) DO UPDATE SET derniere_seq = envoi_kumo_etat.derniere_seq + 1 RETURNING derniere_seq`.
- `reinitialiser5xx` : `remise_5xx_le = now` ; `compter5xx` compte `instant >= max($depuis, remise_5xx_le)`. Rien n'est effacé.
- `tout($ws)` : borné aux 30 derniers jours (`instant`), trié par `cle_idempotence` (limite documentée).
- Garde `Schema::hasTable` : absente ⇒ exception claire, jamais une `QueryException` brute.

Tests ROUGES d'abord : la suite de contrat de `RegistreMemoire` (tests existants de `tests/Unit/Envoi/Kumo/ServeurEnvoiKumoTest.php`)
rejouée avec `RegistreInjectionsDb` ; deux connexions concurrentes sur la même clé ⇒ un seul `ecrireSi` vrai ; cloisonnement
A/B sous `pgsql_app` ET propriétaire ; remise à zéro sans DELETE (compter les lignes). Mutation : retirer `AND instant = ?` ⇒ test ABA.

### 3.3 C3-3 — TR-RETRAIT-CRM (après #616 et C3-1)

Fichiers autorisés :
- `app/Envoi/Desinscription/RetraitEnvoisEnFileServeur.php` (nouveau, implémente `RetraitEnvoisEnFile`)
- `app/Envoi/Desinscription/EnvoiRetraitsDemandes.php` (nouveau : envoi des demandes)
- `app/Console/Commands/EnvoiRetraitsEnvoyer.php` (nouveau, `envoi:retraits:envoyer`)
- `app/Providers/Envoi/TransportServiceProvider.php` (liaison `RetraitEnvoisEnFile`)
- `routes/console/envoi-transport.php` (tâche `everyMinute`, `withoutOverlapping`, garde `hasTable`)
- `app/Envoi/Serveur/ClientServeurEnvoiHmac.php` et `Contrat/CommandeurServeur.php` (paramètre `cibles`), `JournalCommandes.php`
  et `RejoueurCommandes.php` (cibles dans `details`), `ServeurEnvoiKumo.php` (`retirer(array $cibles)`)
- tests : `tests/Feature/Envoi/RetraitEnvoisEnFileTest.php` (nouveau), instantané NR-07 (chaud)

**Interdits :** `EnvoiServiceProvider.php` (la liaison par défaut `:171` reste ; celle de `TransportServiceProvider`, enregistré
après, la remplace : le prouver par un test comme `BranchementInerteTest.php:161`), `TerminerEffetsOpposition.php`,
`EffetsOpposition.php`, `ListeFeuVert.php`, `PorteEnvoiReelle.php`, `Sequenceur.php`, `app/Crm/Opposition/**` (autre session).

Conception :
1. `retirerPour($ws, $empreinte)` (appelé DANS la transaction de l'opposition, R2) : AUCUN réseau. Insère une ligne
   `envoi_retraits_demandes (en_attente)` et rend le nombre de messages **à retirer** trouvés :
   ```sql
   SELECT l.id_injection FROM envoi_injections_locales l
     JOIN envoi_inscriptions i ON i.workspace_id = l.workspace_id
          AND l.cle_idempotence = 'seq-' || i.id::text || '-' || <étape>          -- clé posée par C1-1
    WHERE l.workspace_id = :ws AND i.empreinte_adresse = :empreinte
      AND l.adaptateur = 'kumo' AND l.cree_le > now() - interval '30 days'
    ORDER BY l.id_injection LIMIT 100;
   ```
   (la jointure exacte sur la clé se fait par `RegistreInjectionsSequence::inscriptionDeCle`, #616 ; aucune adresse lue).
   Ces identifiants incluent les injections RÉSERVÉES mais pas encore postées : grâce à la liste de retrait (C3-1), une
   injection postée APRÈS le retrait reçoit `409 retire`. C'est ce qui ferme la course « opposition pendant l'envoi ».
2. `envoi:retraits:envoyer` (chaque minute, hors transaction, par espace sous contexte) : pour chaque demande `en_attente`,
   relit les identifiants (même requête) ; aucun ⇒ `sans_objet` ; sinon `ServeurEnvoiKumo::retirer($ids)` par la source active
   de l'espace, `commande_id` écrit AVANT l'appel ⇒ `envoye`. Interrupteurs faux ⇒ la demande reste `en_attente` (aucune
   connexion, `ClientServeurEnvoiHmac.php:163-176`) ; `tentatives + 1`. Alerte critique « retrait en retard » si une demande
   attend plus de 5 minutes ET `reel_actif` est vrai (cible ADR : retrait en moins de 5 minutes).
3. Contrat v3 côté CRM : `commander($ws, $source, $id, $commande, array $cibles = [])` ; `retrait` sans cibles refusé localement ;
   `details` du journal = `{"corps_sha256": sha256(corps JSON exact), "cibles": [...]}` ; `RejoueurCommandes` relit `cibles` et
   renvoie LE MÊME corps (sinon `409` côté passerelle). `ServeurEnvoiKumo::retirer()` sans argument disparaît (un seul
   appelant : aucun dans `app/`, vérifié par recherche).

Effet attendu et voulu : avec le retrait réel lié, le point `auto.retrait_file` devient vert (`ListeFeuVert.php:65`). La porte
reste FERMÉE tant que `ENVOI_REEL_ACTIF` est faux (`PorteEnvoiReelle.php:47`) ; le test `BranchementInerteTest` (1) le prouve déjà
(`:92`). Le dire dans la PR.

Tests ROUGES d'abord (`RetraitEnvoisEnFileTest.php`, faux serveur, `Http::fake`) :
1. Opposition enregistrée pour une adresse ayant 2 injections kumo réservées (clés `seq-…`) ⇒ une demande `en_attente`, aucune
   requête HTTP pendant la transaction (rouge sur `main` : rien n'est créé).
2. Commande planifiée, interrupteurs vrais DANS le test ⇒ un POST `/v1/commandes/retrait` avec exactement ces 2 cibles ;
   demande `envoye` ; rejeu de la commande ⇒ même corps.
3. Interrupteurs faux ⇒ zéro requête, demande toujours `en_attente`, `tentatives = 1`.
4. Adresse sans injection kumo (faux serveur seulement) ⇒ `sans_objet`, zéro requête.
5. Cloisonnement : injections de la même empreinte dans l'espace B jamais envoyées pour A — sous `pgsql_app` ET propriétaire.
6. La liaison de `TransportServiceProvider` gagne sur celle de `EnvoiServiceProvider:171` ; `PorteEnvoiReelle::ouverte()` reste faux.
7. Aucune ligne de personne supprimée ni modifiée (compter `envoi_inscriptions`, `envoi_oppositions` avant/après).
Mutations : appeler le réseau dans `retirerPour` ⇒ test 1 ; oublier le filtre `workspace_id` ⇒ test 5 sous propriétaire ;
rejouer sans les cibles ⇒ test 2.

### 3.4 C3-4 — TR-AIG-BRANCHE (1/2) : réserves de #594

Fichiers autorisés : `app/Envoi/Serveur/AiguilleurServeurEnvoi.php`, `app/Envoi/Serveur/GardeConformiteSortie.php` (nouveau),
`tests/Feature/Envoi/AiguilleurServeurEnvoiTest.php`, `tests/Feature/Envoi/GardeConformiteSortieTest.php` (nouveau),
`docs/envoi/lots/X2-T0-A1.md` (section « Avant de lier une fabrique réelle »).

- **M1 pause globale** (`:134-150`) : `pause()` lit `envoi_couples_reels_portes()` (C3-0) et appelle
  `fabrique(adaptateur)(workspace_id, domaine)->pause()` pour CHAQUE couple, quel que soit le contexte courant, porte fermée
  comprise (sens sûr inchangé). Le stop gagne : chaque couple est tenté, la première erreur est relancée à la fin (comme
  `:139-149`). Le lecteur reste injectable (paramètre `$adaptateursPortes`, `:49`, remplacé par un lecteur de couples ; le
  TODO `:136-137` est retiré). Une seule pause par couple (espace, adaptateur) suffit si deux domaines partagent la même source :
  dédoublonner par (espace, adaptateur, domaine) seulement — jamais moins.
- **F2** : `injecter` refuse `EnvoiRefuse::autreEspace()` si `WorkspaceContext::current() !== $message->workspaceId`
  (contexte absent compris), AVANT toute lecture.
- **F3** : `sante()` ne rend que `"<adaptateur> : ok|ko|en pause"` ; le `detail` de l'adaptateur n'est jamais recopié.
- **E6** : `GardeConformiteSortie::verifier(string $rfc5322): void` appelée dans `injecter` avant `$this->adaptateur(...)`
  (`:78`), pour les adaptateurs RÉELS seulement : exige `List-Unsubscribe` avec une URL `https:` ET un `mailto:`,
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, le bloc d'information art. 14 (marqueur posé par le composeur), aucun
  en-tête `X-Envoi-*`. Échec ⇒ `EnvoiRefuse::conditionNonRemplie('conformite_sortie')` (rien n'est parti).
- **A6** : commentaire `:81-90` réécrit : depuis C1 l'identifiant du registre (`envoi_injections_locales`) EST l'identifiant posté
  à Kumo (`id_injection` de `/v1/injections`).

Tests ROUGES d'abord : le test `skip` de `AiguilleurServeurEnvoiTest.php:777-779` est REMPLI et rendu bloquant : deux
espaces sur kumo, `pause()` sans contexte sous `pgsql_app` ⇒ les fabriques reçoivent (A, domA) ET (B, domB) (rouge sur `main` :
reçoivent `''`) ; même chose appelé depuis le contexte A ; F2 : message de B dans le contexte A ⇒ refus, fabrique jamais appelée ;
F3 : un adaptateur espion dont `detail` contient `hote.example.test` ⇒ absent de la réponse ; E6 : message sans `mailto:` ⇒
refus, avec ⇒ transmis ; faux serveur : garde NON appliquée (aucun changement du parcours actuel).
Mutations : boucler sur l'espace courant seulement ⇒ test M1 ; recopier `detail` ⇒ test F3.

### 3.5 C3-5 — TR-AIG-BRANCHE (2/2) : fabrique `kumo` liée, inerte

Fichiers autorisés : `app/Providers/Envoi/AiguillageServiceProvider.php`, `app/Envoi/Serveur/FabriqueServeurKumo.php` (nouveau),
`tests/Feature/Envoi/BranchementInerteTest.php`, `tests/Feature/Envoi/AiguilleurServeurEnvoiTest.php`, `docs/envoi/lots/X2-T0-A1.md`.

- `FabriqueServeurKumo::__invoke(string $ws, string $domaine): ServeurEnvoi` : source = la ligne `envoi_serveurs` ACTIVE de
  l'espace (exactement une, sinon `EnvoiRefuse::conditionNonRemplie('source_ambigue')`) ; `config('envoi.cle_empreinte')` non vide
  sinon `EnvoiRefuse::cleEmpreinteAbsente()` ; construit `ServeurEnvoiKumo(ws, source, TransportHttpsSigne, InterrogeurEtatHttps,
  RegistreInjectionsDb, ClientServeurEnvoiHmac, DepotSocle, cle)`. **Aucune connexion, aucune lecture de secret HMAC à la
  construction.** Le domaine `''` (ancienne pause) n'existe plus depuis C3-4.
- `AiguillageServiceProvider` : `['kumo' => app(FabriqueServeurKumo::class)]`. `smtp` reste NON lié (décision E4).
- Relecture 2 de #594 : (b) test « kumo ET smtp actifs : chaque identifiant va à son adaptateur » (fabrique smtp ESPIONNE dans
  le test seulement) ; (c) smtp : l'aiguilleur exige que le domaine actif soit celui de la boîte (`:119-125`) — test.
- Porte fermée (réalité aujourd'hui) : la fabrique n'est jamais appelée sauf par `pause()` (sens sûr), et `ServeurEnvoiKumo`
  ne se connecte pas (T5).

Tests ROUGES d'abord :
1. **M2** : `BranchementInerteTest` (4) devient BLOQUANT (`skip` retiré, `:183`) : instancier la fabrique réelle pour (A, domA)
   avec `Http::preventStrayRequests()` ⇒ `Http::assertNothingSent()`, et appeler `pause()`, `etat()`, `sante()` porte fermée ⇒
   toujours aucune requête (interrupteurs faux). Retirer un `skip` renforce le filet : à écrire dans « Filet modifié : pourquoi ».
2. (2) et (2 ter) existants restent verts avec la fabrique réelle liée (aucun compteur à changer sauf (2 bis) : documenter).
3. Fabrique sans `cle_empreinte` ⇒ refus clair ; deux sources actives ⇒ `source_ambigue`.
4. Bout en bout sur le FAUX : interrupteurs forcés à vrai DANS le test, porte simulée ouverte, feu vert simulé, DNS vert du jour,
   `Http::fake` qui répond comme la passerelle (202 signé) ⇒ `envoi_messages.id_injection` = id du registre C1 = `id_injection`
   posté ; puis retrait (C3-3) de cette adresse ⇒ POST retrait avec ce même id.
Mutation : ouvrir une connexion dans le constructeur (appel `lireSante`) ⇒ test 1.

---

## 4. Fichiers chauds (un seul lot à la fois dessus)
`infra/serveur-envoi/passerelle/src/Passerelle.php` (C2-1, C2-2, C2-3, C3-1, et C-CONFIG hors cahier : en série strict),
`envoi-serveur/contrat/README.md` (mêmes lots), instantanés NR-07/08/17/20 (C3-0, C3-3), `Nr17MigrationsTest.php` (C3-0).
Jamais touchés ici : `Sequenceur.php`, `ComposeurSequenceMessage.php`, `Fiche.php`, `EnvoiServiceProvider.php`, `DepotSocleDb.php`,
`ListeFeuVert.php`, `routes/console.php`, `routes/api.php`, `tests/Support/SemeurTablesScopees.php`, `config/envoi.php`,
`app/Crm/Opposition/**`, toute migration existante. Ne pas lancer C3-4/C3-5 en même temps que P5 (séquenceur/porte).

## 5. ESCALADE (décisions du fondateur ; le code continue sans elles)
- Durée de la liste de retrait côté serveur (30 jours proposés, comme la rétention des événements `Stockage.php:15`) : réversible.
- Mise en place de `ENVOI_CLE_EMPREINTE` en production (secret) : sans elle la fabrique `kumo` refuse (C3-5) — voulu.
- Jour J (lot X9, hors de ce cahier) : vrai serveur, certificat, AC privée, secrets HMAC par espace, client KumoMTA réel
  remplaçant `KumoInactif`, API d'administration (C4), puis feu vert écrit avant toute bascule de `ENVOI_REEL_ACTIF`.
- Aucune autre décision n'est requise : schéma d'injection, traduction des codes, pause globale, boîte de retraits sont des
  choix de conception réversibles.

## 6. Avant de pousser (chaque PR)
CRM : `cd backend && vendor/bin/pint --dirty` ; `vendor/bin/pest --filter='TransportHttps|SchemaTransportC3|RegistreInjectionsDb|RetraitEnvoisEnFile|AiguilleurServeurEnvoi|BranchementInerte|GardeConformiteSortie|ServeurEnvoiKumo|Sante'` ;
filet `vendor/bin/pest --group=non-regression --no-coverage` ; PHPStan niveau 8 sur les fichiers touchés.
Passerelle : `cd infra/serveur-envoi/passerelle && vendor/bin/phpunit` puis `bash infra/serveur-envoi/tests/conformite/lancer.sh`.
Si `vendor/` manque, le dire dans la PR. Chaque PR montre ses tests ROUGES (sortie) puis verts, et ses mutations annulées.
