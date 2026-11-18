# D1 — relève IMAP des réponses, et D2 — rattachement d'une réponse à son inscription (cahier de conception)

Conception Opus du 16/11/2026, vérifiée sur `main` (tête `7906511`) et sur la PR #616 (C1-1, tête `54da0b4`, ouverte).
Pour un développeur seul. Rien ici n'est du code à copier tel quel : ce sont des contrats. Chaque chemin cité a été relu.

Ne pas confondre avec `docs/envoi/lots/D1.md` (« dettes de la relève IMAP (M3) ») et `D2.md` (« dettes du feu vert ») :
ce sont d'anciens lots déjà livrés. Les PR de ce cahier s'appellent **D1-L1 … D1-L4** et **D2**.

Règles qui ne bougent pas :
- `ENVOI_REEL_ACTIF`, `ENVOI_RELEVE_ACTIF` restent faux ; `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`,
  `USINE_AGREGATS_CRM_ENABLED` ne sont pas touchés ; aucune nouvelle clé de configuration ni `env()` (NR-16 intact).
- **Aucune connexion IMAP réelle en test** : lecteur simulé (`LecteurBoiteMemoire`) ou faux protocole (fabrique injectée
  de `LecteurBoiteWebklex`, modèle `ReleveImapAuditTest.php`).
- **Relève éteinte tant que les drapeaux d'envoi sont éteints** : aucun accès lu, aucun secret déchiffré, aucun lecteur
  construit.
- **Secrets jamais en clair** : ni en base (chiffrés par `Crypt`), ni en argument de commande, ni dans un journal, une
  exception, une sortie console ou un instantané.
- Migrations en ajout seul, plage réservée `2026_12_05_000400` à `000490`. Aucune suppression ni purge de données.
- Adresses d'exemple en `*.example.test`. Aucun nom de personne.

---

## 0. Ordre de fusion (une fusion à la fois ; chaque PR de 250 à 400 lignes, tests compris ; branche depuis `main`)

| # | PR | Contenu | Préalable |
|---|----|---------|-----------|
| 1 | **D1-L1** « migration seule » | table des accès IMAP des boîtes (`000400`) + tests de schéma | — |
| 2 | **D1-L3** | commandes `envoi:boite-creer`, `envoi:boite-secret`, `envoi:boite-reprendre` + dépôt des accès | D1-L1 fusionnée ET déployée |
| 3 | **D1-L2** | fabrique réelle INERTE + relève des boîtes en pause | D1-L3 fusionnée |
| 4 | **D1-L4** | verrou par boîte + délai de grâce + délais réseau + borne de temps | D1-L2 fusionnée |
| 5 | **D2** | rattachement : bout en bout par la relève, rattachement manuel, compteur `reponses_7j` | C1-1 (#616) ET C1-2 fusionnées, D1-L2 fusionnée |

Vague du plan maître : D1(L1+L3) en V3, D1(L2+L4) et D2 en V4. Le déploiement remplace le code AVANT `migrate`
(`.github/workflows/deploy-direct-ssh.yml:443` puis `:561`) : d'où la migration seule d'abord et les gardes `hasTable`.

---

## 1. Constats vérifiés dans le code

### 1.1 La relève existe déjà, mais elle ne peut lire aucune vraie boîte
1. Commande `envoi:releve-imap` : `backend/app/Envoi/Imap/ReleveImapCommande.php:21`. Inerte si `envoi.reel_actif` ou
   `envoi.releve_actif` n'est pas vrai, AVANT toute résolution du lecteur (`:33-37`). Planifiée chaque minute, sans
   chevauchement, un seul serveur, derrière les deux drapeaux : `backend/routes/console.php:595-602`. Drapeaux :
   `backend/config/envoi.php:19` et `:24` (faux par défaut).
2. La fabrique liée par défaut lève toujours : `EnvoiServiceProvider.php:159` lie `FabriqueLecteurs` à
   `FabriqueLecteursNonFournie` (`app/Envoi/Imap/FabriqueLecteursNonFournie.php:16-19`, message fixe sans secret `:14`).
   Le test `tests/Feature/Envoi/ReleveImapBrancheeTest.php:178-187` fige ce comportement.
3. Le lecteur réel existe et est sûr : `app/Envoi/Imap/LecteurBoiteWebklex.php` — `EXAMINE` seulement, `BODY.PEEK[]`,
   chiffrement imposé `ssl|tls|starttls` (`:43`, `:61-63`), paramètres `#[SensitiveParameter]` (`:57`), un lecteur ne sert
   qu'une boîte (`:293-298`), panne = exception qui remonte (`:308-317`). **Aucun délai réseau n'est réglé** :
   `protocole()` (`:319-331`) appelle `connect` puis `login` sans régler de délai de connexion ni de lecture.
4. Le lecteur ne reçoit ses identifiants que par son constructeur (`$parametres`) ; **aucune table ne stocke l'accès IMAP
   d'une boîte d'envoi**. `envoi_boites` (`database/migrations/2026_11_10_000040_envoi_boites.php:18-35`) porte l'hôte,
   le port et les identifiants **SMTP** chiffrés (`identifiants_chiffres`, `Crypt::encryptString`), rien pour l'IMAP.
5. Modèle déjà en place pour des accès IMAP chiffrés (témoins, R16) :
   `database/migrations/2026_12_02_000160_envoi_temoins_acces.php` (ajout seul, RLS FORCÉE, CHECK de liste fermée
   d'hôtes, `SELECT, INSERT` seulement pour le rôle applicatif), `app/Envoi/Temoins/HotesTemoins.php:18-25` (liste
   fermée codée), `app/Envoi/Temoins/DepotAccesTemoins.php:22-29` (chiffrement, `#[SensitiveParameter]`) et
   `app/Console/Commands/EnvoiTemoinsAcces.php:56-65` (secret en invite masquée ou sur l'entrée standard, jamais en
   argument). **D1 réutilise ce modèle ; il ne le recopie pas au hasard.**

### 1.2 Défauts de la relève (plan X4, confirmés)
1. **Boîte en pause = plus relevée, donc STOP perdus** : `ReleveImapCommande.php:40-41` ne prend que
   `etat = 'active'`. Or une boîte passe `en_pause` toute seule après des refus SMTP (`app/Envoi/Smtp/AdaptateurSmtp.php:323`,
   `:335`) ou à la main (`:404`) ; les réponses et les STOP continuent d'arriver dans sa boîte de réception.
2. **Pas de verrou par boîte** : le seul verrou est `withoutOverlapping(5)` de la tâche (`routes/console.php:600`).
   `ReleveImap::rattraper` (`app/Envoi/Imap/ReleveImap.php:112-134`) lancé à la main peut tourner en même temps que la
   relève planifiée sur la même boîte et faire reculer le curseur (`poserCurseur(…, 0)` à `:127`).
3. **Désordre registre / relève** : le registre `envoi_messages_envoyes` est écrit au temps C, APRÈS le réseau
   (`app/Envoi/Sequence/Sequenceur.php:212` → `enregistrerEnvoye` `:446-476`). Un rebond ou une réponse automatique
   très rapide peut être relu avant ce COMMIT : `retrouver` (`RegistreMessagesEnvoyesDb.php:30-56`) ne trouve rien, le
   retour est noté « à rattacher » (`ReleveImap.php:318`), et comme l'événement est unique par message
   (`envoi_evenements` index `(workspace_id, boite, empreinte_message)`, migration `2026_11_10_000060…:41-42`),
   **il n'est jamais retenté**. Même problème par VERP (`retrouverParInjection` lit la même table, `:18-28`).
4. **Délais réseau** : voir 1.1-3. Une boîte qui ne répond pas bloque la tâche jusqu'au délai par défaut de la
   bibliothèque, et les autres boîtes attendent (boucle séquentielle `ReleveImapCommande.php:39-53`).

### 1.3 Rattachement (D2) : l'identifiant du serveur n'est pas l'UUID du registre
1. Sur `main`, le séquenceur écrit l'identifiant **rendu par le serveur** (`sim-N` du faux serveur, celui de Kumo) dans
   `envoi_messages.id_injection` (`Sequenceur.php:208`, `:431`), mais un **UUID local** dans le registre
   `envoi_messages_envoyes.id_injection` (`Sequenceur.php:456-470`, table `envoi_injections_locales`).
2. La relève rend au récepteur l'identifiant **du registre** (`ReleveImap.php:233`, `:262-264` ;
   `RegistreMessagesEnvoyesDb.php:51`).
3. `RecepteurRetoursSequence` cherche `envoi_messages.id_injection = <id du registre>` (`app/Envoi/Imap/RecepteurRetoursSequence.php:39-40`) :
   **il ne trouve rien ; une réponse humaine n'arrête pas la séquence.** Constat K4 du cahier C1
   (`docs/envoi/lots/C1-F2A-cahier.md`, branche `conception/composeur-variables`, tableau §0).
4. **Ce qui est déjà prévu ailleurs (à ne PAS refaire dans D2)** :
   - C1-1 (#616, ouverte) : le CRM choisit l'identifiant AVANT le réseau (`RegistreInjectionsSequence::reserver`, même
     clé ⇒ même UUID), l'écrit dans `envoi_messages.id_injection` et met celui du serveur dans
     `envoi_messages.id_injection_serveur` (colonne de C1-0, `database/migrations/2026_12_05_000200_envoi_messages_id_injection_serveur.php:28`).
   - C1-2 (pas encore écrite) : `RecepteurRetoursSequence` et `RegistreMessagesEnvoyesDb::retrouverParInjection` lisent
     les DEUX formes (cahier C1 §1.5 : `id_injection = :id OR id_injection_serveur = :id OR` clé `seq-<inscription>-<étape>`).
5. **Ce qui reste, et que D2 corrige** :
   - **Rattachement manuel refusé** : `app/Envoi/Reponses/ServiceReponses.php:220` n'accepte qu'un identifiant présent
     dans `envoi_injections_smtp`. Les identifiants du registre (faux serveur, Kumo) vivent dans `envoi_injections_locales` :
     l'écran répond `inconnue_injection`. Le déclencheur de base, lui, accepte déjà les deux tables
     (`database/migrations/2026_12_03_000080_envoi_injections_locales.php:9-10`).
   - **`reponses_7j` toujours 0** : `app/Envoi/Smtp/DepotBoites.php:147` filtre `envoi_evenements.boite = <adresse>`,
     alors que la relève écrit `boite = <UUID de la boîte>` (`ReleveImapCommande.php:41-44` passe `envoi_boites.id` ;
     `ReleveImap.php:313`).
   - **Commentaire faux** : `app/Envoi/Imap/MessageOrigine.php:8` et `:16` disent « UUID de `envoi_injections_smtp` » ;
     c'est l'UUID du registre (`envoi_injections_smtp` OU `envoi_injections_locales`).
   - **Aucun test ne passe par la relève entière** : le test existant pose le même identifiant des deux côtés à la main
     (`tests/Feature/Envoi/ReleveImapBrancheeTest.php:44-70`, constat K4).
6. Hors D2 (lots suivants) : `RecepteurRetoursSequence` ne traite que `ReponseHumaine` (`:34-36`) — STOP, plainte et
   rebond dur sur la séquence = lot **L5** ; les compteurs par boîte de la santé joignent `envoi_injections_smtp`
   (`app/Envoi/Sante/DepotSanteDb.php:131`, `:141`) et ignorent les identifiants du registre = lot **L6** (R04).

### 1.4 Points d'entrée préparés (lot V0)
- `backend/app/Providers/Envoi/ReleveRetoursServiceProvider.php` (vide, enregistré APRÈS la liaison par défaut :
  `EnvoiServiceProvider.php:159` puis `:184`, donc une liaison posée ici remplace `FabriqueLecteursNonFournie`).
- `backend/routes/console/envoi-releve.php` (vide, chargé par `routes/console.php:619`).

---

## 2. D1-L1 — accès IMAP chiffrés des boîtes (PR « migration seule »)

Branche `feat/d1-l1-acces-imap`. Titre : `feat(envoi): D1-L1 accès IMAP chiffrés des boîtes (migration seule)`.

### 2.1 Fichiers autorisés (et seulement eux)
1. `backend/database/migrations/2026_12_05_000400_envoi_boites_acces_imap.php` (nouveau)
2. `backend/tests/Feature/Envoi/BoitesAccesImapSchemaTest.php` (nouveau)
3. `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : UNE entrée ajoutée (retour arrière inerte voulu),
   ligne « Filet modifié : pourquoi » dans la PR
4. `docs/envoi/lots/D1-L1.md` (nouveau, 30 lignes au plus)

### 2.2 Table (ajout seul, même modèle que `envoi_temoins_acces`)
```sql
CREATE TABLE IF NOT EXISTS envoi_boites_acces_imap (
    id                  BIGSERIAL   PRIMARY KEY,
    workspace_id        UUID        NOT NULL REFERENCES workspaces(id),
    boite_id            UUID        NOT NULL,
    hote                TEXT        NULL CHECK (hote IS NULL OR hote IN (<liste fermée §2.3>)),
    port                INTEGER     NULL CHECK (port IS NULL OR port = 993),
    dossier             TEXT        NULL CHECK (dossier IS NULL OR dossier = 'INBOX'),
    identifiant_chiffre TEXT        NULL,
    actif               BOOLEAN     NOT NULL,
    cree_le             TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT envoi_boites_acces_imap_boite_fk FOREIGN KEY (workspace_id, boite_id)
        REFERENCES envoi_boites (workspace_id, id),
    CONSTRAINT envoi_boites_acces_imap_forme CHECK (
        (actif AND hote IS NOT NULL AND port IS NOT NULL AND dossier IS NOT NULL
             AND identifiant_chiffre IS NOT NULL AND char_length(identifiant_chiffre) > 0)
     OR (NOT actif AND identifiant_chiffre IS NULL))
);
CREATE INDEX IF NOT EXISTS envoi_boites_acces_imap_derniere ON envoi_boites_acces_imap (workspace_id, boite_id, id DESC);
```
- Clé étrangère composite : la contrainte unique `(workspace_id, id)` d'`envoi_boites` existe
  (`2026_11_10_000042_envoi_boites_durcissement_ssrf_fk_composite.php:34`). Un accès ne peut donc pas viser la boîte
  d'un autre espace.
- **La dernière ligne fait foi.** Retirer un accès = AJOUTER une ligne `actif = false` (aucun secret). Jamais d'UPDATE.
- RLS ENABLE + FORCE, politique `workspace_isolation` identique à `2026_12_02_000160…:24-29`.
- Déclencheur `ajout seul` (UPDATE, DELETE, TRUNCATE refusés), copie de `:30-38`.
- Rôle applicatif (bloc `pg_roles` quoté, `:41-47`) : `REVOKE UPDATE, DELETE, TRUNCATE`, `GRANT SELECT, INSERT`,
  `GRANT USAGE, SELECT ON SEQUENCE envoi_boites_acces_imap_id_seq`.
- `COMMENT ON COLUMN … identifiant_chiffre IS 'Crypt::encryptString(json {u, s}). Jamais en clair.'`
- `down()` INERTE (les accès posés sont conservés). Aucun `DROP`, aucun `DELETE`.

### 2.3 Liste fermée des hôtes (pas de SSRF)
Le CHECK porte EXACTEMENT les hôtes de la constante `HotesImapBoites::LISTE` qui sera écrite en D1-L3. Valeurs de départ :
les quatre hôtes publics déjà admis pour les témoins (`HotesTemoins.php:19-24`) : `imap.gmail.com`,
`outlook.office365.com`, `imap.mail.yahoo.com`, `imap.orange.fr`. Port 993 seul (TLS implicite). Tout autre hôte
(dont l'hébergeur de boîtes que choisira le fondateur, ESCALADE 1) = une petite PR qui ajoute la valeur dans la
constante ET dans le CHECK (nouvelle migration de la plage, `DROP CONSTRAINT IF EXISTS` puis `ADD … NOT VALID` puis
`VALIDATE` : ce n'est pas une suppression de données).

### 2.4 Tests (rouges d'abord : écrire, lancer, voir échouer faute de table, puis écrire la migration)
Modes `['app', 'proprio']` selon `tests/Feature/Envoi/SchemaLienCampagneSequenceTest.php:77-95` (`SET LOCAL ROLE` du
rôle `pgsql_app` sous `WorkspaceContext::set`, ou propriétaire).
- S1 : table, index, RLS FORCÉE (`pg_class.relforcerowsecurity`), déclencheurs présents.
- S2 (app ET proprio) : INSERT d'une ligne active valide passe ; hôte hors liste ⇒ `23514` ; port 143 ⇒ `23514` ;
  dossier `Spam` ⇒ `23514` ; ligne active sans identifiant ⇒ `23514` ; ligne inactive AVEC identifiant ⇒ `23514`.
- S3 (app ET proprio) : UPDATE et DELETE refusés (déclencheur, et en plus droits pour `app`).
- S4 (app) : boîte de l'Espace B depuis l'Espace A ⇒ refus (RLS `WITH CHECK` ou clé étrangère) ; lecture depuis A ne
  voit pas les lignes de B ; sans contexte ⇒ 0 ligne.
- S5 : `has_table_privilege(<rôle>, 'envoi_boites_acces_imap', 'UPDATE')` faux, `INSERT` vrai.
- S6 : `up()` joué deux fois sans erreur.
- NR-17 vert grâce à l'entrée ajoutée.

Mutations (chacune doit rougir au moins un test) : retirer `FORCE ROW LEVEL SECURITY` (S4 proprio-contexte) ; retirer
le CHECK d'hôte (S2) ; `GRANT UPDATE` au rôle (S3, S5) ; retirer le déclencheur (S3 proprio).

Commandes : `cd backend && vendor/bin/pint --dirty && vendor/bin/pest --filter='BoitesAccesImapSchema|NR-17'`.

---

## 3. D1-L3 — commandes (secrets jamais en argument)

Branche `feat/d1-l3-commandes-boites`. Après D1-L1 fusionnée ET déployée.

### 3.1 Fichiers autorisés
- `backend/app/Envoi/Imap/HotesImapBoites.php` (nouveau) : `LISTE` (même contenu que le CHECK), `hoteAutorise(string): bool`.
- `backend/app/Envoi/Imap/DepotAccesImap.php` (nouveau)
- `backend/app/Console/Commands/EnvoiBoiteCreer.php`, `EnvoiBoiteSecret.php`, `EnvoiBoiteReprendre.php` (nouveaux)
- `backend/app/Providers/Envoi/ReleveRetoursServiceProvider.php` : `singleton(DepotAccesImap::class)` seulement
- `backend/tests/Feature/Envoi/BoitesCommandesTest.php`, `DepotAccesImapTest.php` (nouveaux)
- `docs/envoi/lots/D1-L3.md` (nouveau)

Les commandes de `app/Console/Commands` sont découvertes automatiquement (comme `EnvoiTemoinsAcces`) : aucune ligne dans
`routes/console.php`. Aucune route HTTP (les routes d'ajout restent en 501 : `routes/envoi/boites.php`).

### 3.2 Signatures
```php
final class DepotAccesImap {
    /** Ajoute une ligne active (chiffrée). Refuse hôte hors liste, adresse/mot de passe vides : InvalidArgumentException (message fixe). */
    public function poser(string $ws, string $boiteId, string $hote, string $identifiant, #[SensitiveParameter] string $motDePasse): void;
    /** Ajoute une ligne inactive (aucun secret). */
    public function retirer(string $ws, string $boiteId): void;
    /** Dernière ligne de la boîte : null si aucune ou inactive. NE DÉCHIFFRE PAS. @return array{hote: string, port: int, dossier: string}|null */
    public function decrire(string $ws, string $boiteId): ?array;
    /** Seul endroit qui déchiffre. @return array{host: string, port: int, encryption: string, username: string, password: string}|null */
    public function parametres(string $ws, string $boiteId): ?array;
}
```
Règles : `WorkspaceContext::run($ws, …)` et `where workspace_id` explicite partout ; `WorkspaceContext::current() === $ws`
vérifié avant tout INSERT ; JSON chiffré `{u, s}` par `Crypt::encryptString` ; `encryption` toujours `'ssl'`, `port`
toujours 993 (jamais lus d'un argument) ; un échec de déchiffrement lève une exception dont le message est fixe
(« accès IMAP illisible ») — jamais le message d'origine.

### 3.3 Les trois commandes (même forme que `EnvoiTemoinsAcces.php`)
| Commande | Arguments | Lu en invite masquée ou sur l'entrée standard | Effet |
|---|---|---|---|
| `envoi:boite-creer` | `{workspace} {adresse} {hote_smtp} {port_smtp} {chiffrement}` | identifiant SMTP, puis mot de passe SMTP | `DepotBoites::creer` (`app/Envoi/Smtp/DepotBoites.php:24-44`, contrôle d'hôte `GardeHoteSmtp` compris) ; affiche l'UUID de la boîte |
| `envoi:boite-secret` | `{workspace} {boite} {hote_imap} {--retirer}` | identifiant IMAP, puis mot de passe IMAP (rien si `--retirer`) | `DepotAccesImap::poser` / `retirer` |
| `envoi:boite-reprendre` | `{workspace} {boite}` | — | `DepotBoites::reactiverSuspensionManuelle` (`:114`, même règle que l'écran `EnvoiBoitesController.php:46-55` : une pause automatique ou de frein n'est PAS levée, code 1 + message fixe) |

- `workspace` et `boite` : `Str::isUuid` sinon refus sans requête ; `hote_imap` : `HotesImapBoites::hoteAutorise` sinon
  refus avec la liste permise ; `adresse` de la boîte : `FILTER_VALIDATE_EMAIL`.
- Entrée standard : modèle `EnvoiTemoinsAcces.php:56-65` (liaison `envoi.flux_entree` en test, `fgets` bornés).
- Sortie : jamais l'identifiant ni le mot de passe ; `QueryException` ⇒ message fixe (`EnvoiTemoinsAcces.php:44-48`).
- Aucune connexion IMAP ou SMTP n'est tentée par ces commandes (aucun « test de connexion » : il passera par la fabrique
  de D1-L2, donc derrière les drapeaux). Seul `envoi:boite-creer` résout le DNS de l'hôte SMTP (garde anti-SSRF
  existante) : en test, résolveur simulé lié dans le conteneur.

### 3.4 Tests (rouges d'abord)
- C1 `envoi:boite-secret` par l'entrée standard ⇒ une ligne active ; `identifiant_chiffre` ne contient ni l'identifiant
  ni le mot de passe (recherche de sous-chaîne) ; `Crypt::decryptString` les rend.
- C2 la sortie console, `Log` (espion `Log::spy()`) et le texte des exceptions ne contiennent jamais le mot de passe
  `motdepasse-temoin-zz` ni l'identifiant `releve@societe-a.example.test`.
- C3 hôte hors liste (`imap.societe-a.example.test`) ⇒ code 1, 0 ligne.
- C4 `--retirer` ⇒ ligne inactive ; `decrire` rend null ; `parametres` rend null.
- C5 boîte de l'Espace B donnée avec le workspace A ⇒ code 1, 0 ligne (sous `pgsql_app` ET propriétaire).
- C6 `envoi:boite-reprendre` : pause manuelle ⇒ reprise ; pause automatique (`auto_3x5xx_10min`) ⇒ code 1, état inchangé.
- C7 `envoi:boite-creer` : secret SMTP lu sur l'entrée standard ; hôte privé (`10.0.0.1`) refusé par `GardeHoteSmtp`.
- C8 garde statique : la signature des trois commandes ne contient ni `password`, ni `secret`, ni `mot` (aucun
  secret en argument ou option).
- C9 aucune connexion : `Http::preventStrayRequests()` ; aucune classe `ImapProtocol` construite (espion sur la
  fabrique, ou garde statique : `app/Envoi/Imap/DepotAccesImap.php` ne référence pas `Webklex`).

Mutations : afficher l'identifiant après succès (C2) ; accepter un hôte hors liste (C3) ; lire la dernière ligne
active au lieu de la dernière ligne (C4) ; retirer le filtre `workspace_id` (C5 propriétaire) ; appeler `reprendre` au
lieu de `reactiverSuspensionManuelle` (C6).

---

## 4. D1-L2 — fabrique inerte + relève des boîtes en pause

Branche `feat/d1-l2-fabrique`. Après D1-L3.

### 4.1 Fichiers autorisés
- `backend/app/Envoi/Imap/FabriqueLecteursBoites.php` (nouveau)
- `backend/app/Envoi/Imap/AccesImapAbsent.php` (nouveau, `RuntimeException` à message fixe)
- `backend/app/Envoi/Imap/ReleveImapCommande.php` (sélection des boîtes, comptage `sans_acces`)
- `backend/app/Providers/Envoi/ReleveRetoursServiceProvider.php` : `bind(FabriqueLecteurs::class, FabriqueLecteursBoites::class)`
- `backend/tests/Feature/Envoi/ReleveImapBrancheeTest.php` : test `:178-187` adapté (voir 4.4, « Filet modifié : pourquoi »)
- `backend/tests/Feature/Envoi/FabriqueLecteursBoitesTest.php`, `ReleveBoitesEnPauseTest.php` (nouveaux)
- `docs/envoi/lots/D1-L2.md` (nouveau)

### 4.2 `FabriqueLecteursBoites implements FabriqueLecteurs`
```php
public function __construct(private readonly DepotAccesImap $acces, private readonly ?\Closure $protocole = null) {}
public function fabrique(string $boite): LecteurBoite;
```
Dans cet ordre, et rien avant :
1. Si `config('envoi.reel_actif') !== true || config('envoi.releve_actif') !== true` ⇒ `RuntimeException` à message fixe
   (« relève éteinte ») **avant toute lecture de base et tout déchiffrement**. C'est la 3ᵉ garde (après
   `routes/console.php:602` et `ReleveImapCommande.php:33`) : elle couvre un appel direct de `ReleveImap::rattraper`.
2. `$ws = WorkspaceContext::current()` (la relève appelle la fabrique sous `WorkspaceContext::run`, `ReleveImap.php:77-79`
   et `:114-115`) ; null ⇒ exception.
3. `$p = $this->acces->parametres($ws, $boite)` ; null ⇒ `AccesImapAbsent`.
4. Revérifier `HotesImapBoites::hoteAutorise($p['host'])` et `port === 993` (défense en profondeur), sinon exception.
5. `return new LecteurBoiteWebklex($p, $boite, 'INBOX', $this->protocole);` — `$this->protocole` reste null hors test.

Aucune connexion à la construction (la connexion n'a lieu qu'au premier `uidValidity`, `LecteurBoiteWebklex.php:319-331`).

### 4.3 `ReleveImapCommande` : relever AUSSI les boîtes en pause
- `:40-41` : retirer `->where('etat', 'active')` ; trier par `id` pour un ordre stable. Une boîte en pause n'envoie rien,
  mais ses STOP, plaintes et réponses doivent être lus.
- `AccesImapAbsent` : compté comme une panne (code 1, journal dédupliqué par `journaliserPanne`, `:63-71`) avec un
  message fixe « aucun accès IMAP posé pour une boîte ». Une boîte sans relève perd des STOP : ce n'est pas silencieux.
- Rien d'autre ne change (même garde, mêmes messages sans secret `:49-50`).

### 4.4 Tests (rouges d'abord ; lecteur simulé, jamais de réseau)
- F1 drapeaux éteints (les 4 combinaisons sauf vrai/vrai) : `fabrique()` lève, et un espion sur `DepotAccesImap`
  prouve 0 appel ; `Crypt::decryptString` jamais appelé (espion `Crypt`).
- F2 drapeaux allumés, accès posé ⇒ `LecteurBoiteWebklex` rendu ; aucune connexion (faux protocole injecté : 0 commande
  enregistrée tant qu'on ne lit pas).
- F3 accès absent ou retiré ⇒ `AccesImapAbsent` ; la commande rend 1, message fixe, 0 curseur, 0 événement.
- F4 accès de l'Espace B demandé sous le contexte A ⇒ `AccesImapAbsent` (sous `pgsql_app` ET propriétaire).
- F5 boîte `en_pause` avec un STOP déposé dans le lecteur simulé (`LecteurBoiteMemoire::deposer`) ⇒ relevée, événement
  écrit, curseur posé. Rouge sur `main` (`ReleveImapCommande.php:41`).
- F6 journaux : ni hôte, ni identifiant, ni mot de passe dans `Log` ni dans la sortie (espion), même quand le faux
  protocole lève une exception dont le message contient l'hôte.
- F7 adaptation de `ReleveImapBrancheeTest.php:178-187` : l'assertion `toBeInstanceOf(FabriqueLecteursNonFournie::class)`
  devient `toBeInstanceOf(FabriqueLecteursBoites::class)` ; le reste du test (code 1, aucun curseur, aucun événement)
  est GARDÉ et passe par `AccesImapAbsent`. Filet modifié : pourquoi — la fabrique par défaut change par construction ;
  l'exigence « échec visible et propre, sans réseau » reste identique. `FabriqueLecteursNonFournie` n'est pas supprimée.
- `ReleveImapPlanifieeTest.php:64-80` (drapeaux éteints) reste vert sans modification.

Mutations : retirer la garde de drapeaux de la fabrique (F1) ; remettre `where('etat','active')` (F5) ; lire l'accès
sans `workspace_id` (F4 propriétaire) ; journaliser `$e->getMessage()` (F6).

---

## 5. D1-L4 — verrou par boîte, délai de grâce, délais réseau

Branche `feat/d1-l4-verrou-grace`. Après D1-L2. Aucune migration.

### 5.1 Fichiers autorisés
- `backend/app/Envoi/Imap/ReleveImap.php` (verrou, délai de grâce)
- `backend/app/Envoi/Imap/ReleveImapCommande.php` (borne de temps, compteur `occupee`)
- `backend/app/Envoi/Imap/LecteurBoiteWebklex.php` (délais réseau seulement, dans `protocole()`)
- `backend/tests/Feature/Envoi/ReleveImapVerrouGraceTest.php` (nouveau)
- `docs/envoi/lots/D1-L4.md` (nouveau)

### 5.2 Verrou par boîte
- Dans `relever` ET `rattraper`, juste après `WorkspaceContext::run` : `pg_try_advisory_lock(hashtextextended(?, 0))`
  sur la clé `'envoi:releve:' . $ws . ':' . $boite` (même forme que `AdaptateurSmtp.php:136-144`), libéré dans un
  `finally`. Non obtenu ⇒ exception `BoiteOccupee` (nouvelle, message fixe) : rien n'est lu ni écrit.
- `ReleveImapCommande` attrape `BoiteOccupee` à part : compteur `occupee`, `Log::info` sans secret, **pas** de code 1
  (une relève manuelle en cours n'est pas une panne).
- Le verrou est de session : la relève tourne hors transaction englobante (chaque message a sa propre transaction,
  `ReleveImap.php:147`). Ne pas utiliser `pg_advisory_xact_lock`.

### 5.3 Délai de grâce (désordre registre / relève)
- Constante `ReleveImap::DELAI_GRACE_SECONDES = 180`.
- Dans `relever` seulement : après `lire` (`:90`), avant `traiter`, couper la liste au premier message dont
  `recuLe > maintenant - 180 s` ; le curseur est posé au plus sur l'UID qui le précède (`max($uidApres, uid - 1)`, même
  règle que `:94`). Ces messages seront relus au passage suivant (la tâche tourne chaque minute) : rien n'est noté
  « à rattacher » trop tôt.
- `rattraper` n'est pas touché (il rejoue une fenêtre passée).
- Horloge : `now()` (testable par `Carbon::setTestNow`). Une date `recuLe` dans le futur (horloge du serveur IMAP en
  avance) est traitée comme « récente » : relue plus tard, jamais perdue ; au-delà de 24 h dans le futur, le message est
  traité normalement (garde contre un blocage sans fin de la boîte) — test G4.

### 5.4 Délais réseau et borne de temps
- `LecteurBoiteWebklex::protocole()` : régler un délai de connexion de 15 s et de lecture de 30 s avant `connect`
  (méthode de `webklex/php-imap` 6.2.0 à vérifier dans `vendor/webklex/php-imap/src/Connection/Protocols/Protocol.php`
  — `setConnectionTimeout` — ; si la bibliothèque n'offre pas de délai de lecture, l'écrire dans la PR et ne régler que
  la connexion). Constantes de classe, aucune configuration.
- `ReleveImapCommande` : `BORNE_SECONDES = 45` (la tâche tourne chaque minute) ; au-delà, aucune nouvelle boîte n'est
  commencée, compteur `hors_borne`, code 0. Horloge injectable pour le test.

### 5.5 Tests (rouges d'abord)
- G1 verrou pris par une autre connexion (deuxième connexion PDO, `pg_advisory_lock` sur la même clé) ⇒ `relever` ne
  lit rien (le lecteur simulé compte 0 appel), curseur inchangé ; la commande rend 0 et compte `occupee`.
- G2 verrou libéré même si le lecteur lève (relève suivante possible).
- G3 message reçu il y a 60 s ⇒ non traité, curseur avant lui ; 4 min plus tard (`setTestNow`) ⇒ traité et
  **rattaché**, l'entrée du registre ayant été écrite entre-temps. Rouge sur `main` (noté « à rattacher » pour toujours).
- G4 `recuLe` à +2 jours ⇒ traité au premier passage (pas de blocage).
- G5 `rattraper` et `relever` sur la même boîte en même temps (verrou tenu) ⇒ le second ne pose pas de curseur.
- G6 borne de temps atteinte ⇒ la boîte suivante n'est pas commencée, `hors_borne` = 1.
- G7 sous `pgsql_app` ET propriétaire : G1 et G3 (les fonctions `pg_*advisory*` sont permises au rôle applicatif ;
  vérifier, sinon ESCALADE technique dans la PR).

Mutations : verrou sans `finally` (G2) ; délai de grâce à 0 (G3) ; clé de verrou sans la boîte (un verrou pour tout
l'espace : G1 faux positif, à montrer dans la PR) ; `<` au lieu de `>` sur `recuLe` (G3, G4).

---

## 6. D2 — rattachement d'une réponse à son inscription

Branche `feat/d2-rattachement`. **Préalables : C1-1 (#616) et C1-2 fusionnées, D1-L2 fusionnée.** Aucune migration.
Si C1-2 était abandonnée, D2 reprend tel quel le §1.5 du cahier C1 (récepteur et registre, lecture des deux formes) et
ajoute `app/Envoi/Imap/RecepteurRetoursSequence.php` et `RegistreMessagesEnvoyesDb.php` à ses fichiers autorisés ;
sinon ces deux fichiers sont INTERDITS dans D2 (pas de double correction).

### 6.1 Fichiers autorisés
- `backend/app/Envoi/Reponses/ServiceReponses.php` : `:220` accepte l'identifiant s'il existe pour l'espace dans
  `envoi_injections_smtp` **OU** `envoi_injections_locales` (même règle que le déclencheur
  `2026_12_03_000080…:9-10`) ; toujours sous `WorkspaceContext::run` et `where workspace_id`.
- `backend/app/Envoi/Smtp/DepotBoites.php` : `:147` compte `boite IN (<id de la boîte>, <adresse>)` (l'adresse est
  gardée pour des lignes éventuelles d'une ancienne forme ; la relève n'a jamais tourné en production, drapeau éteint).
- `backend/app/Envoi/Imap/MessageOrigine.php` : commentaire `:8` et message `:16` (« UUID du registre :
  `envoi_injections_smtp` ou `envoi_injections_locales` »). Aucun changement de règle.
- `backend/tests/Feature/Envoi/RattachementReponseD2Test.php` (nouveau)
- `docs/envoi/lots/D2-RATTACHEMENT.md` (nouveau ; ne pas écraser l'ancien `D2.md`)

### 6.2 Tests (rouges d'abord ; modes `['app', 'proprio']`)
Installation : séquence à une étape, inscription active, faux serveur derrière `PasserelleLocale` (modèle
`tests/Feature/Envoi/RegistreInjectionLocaleTest.php:33-60`), adresse `zoe.martin@societe-a.example.test`, boîte
`envoi@societe-a.example.test`. La relève passe par `ReleveImap` avec `LecteurBoiteMemoire` (aucun réseau).
- R1 **bout en bout par la relève** : un cycle du séquenceur (le faux serveur rend `sim-1`), puis une réponse humaine
  déposée dans le lecteur simulé avec `In-Reply-To` = le Message-ID envoyé, puis `ReleveImap::relever` ⇒ événement
  `rattache`, inscription `arretee` motif `reponse`, et les collègues de la même entreprise arrêtés comme le veut
  `Inscriptions`. (Rouge sur `main` ; vert après C1-1 + C1-2 : c'est la preuve de la chaîne entière.)
- R2 même chose par **VERP** : adresse `rb-…@bounce.societe-a.example.test` dans `adressesLocales`, `envoi.cle_empreinte`
  posée en test ⇒ rattaché ; la même réponse sans VERP ni `In-Reply-To` ⇒ « à rattacher », aucun arrêt.
- R3 **ancienne forme** (lignes posées comme l'ancien code : `envoi_messages.id_injection = 'sim-7'`, registre = UUID
  local de clé `seq-<inscription>-1`) ⇒ arrêt par la relève.
- R4 **cloisonnement** : même Message-ID et même identifiant dans l'Espace B ⇒ aucune inscription de A touchée ; relève
  de la boîte de B ne lit rien de A.
- R5 **rattachement manuel** : `ServiceReponses::rattacher` avec l'UUID du registre (table `envoi_injections_locales`)
  ⇒ `ok` (rouge sur `main` : `inconnue_injection`) ; UUID inconnu ⇒ `inconnue_injection` ; UUID de l'Espace B ⇒
  `inconnue_injection`.
- R6 **`reponses_7j`** : deux réponses relevées pour la boîte ⇒ `DepotBoites` rend 2 (rouge sur `main` : 0).
- R7 journaux et événements : aucune adresse en clair dans `envoi_evenements` (seulement des empreintes,
  `ReleveImap.php:320-321`) ni dans `Log`.

Mutations : `ServiceReponses` ne lit plus que `envoi_injections_smtp` (R5) ; filtre `reponses_7j` sur l'adresse seule
(R6) ; retirer `where workspace_id` dans `rattacher` (R5 propriétaire) ; dans un test local non poussé, remettre
l'identifiant serveur dans `envoi_messages.id_injection` (R1 rouge) — à décrire dans la PR.

---

## 7. Fichiers chauds : NE PAS TOUCHER (aucune PR de ce cahier)
`app/Providers/EnvoiServiceProvider.php` (les liaisons passent par `Providers/Envoi/ReleveRetoursServiceProvider.php`),
`routes/console.php` (la planification existante `:595-602` suffit ; si une tâche nouvelle était un jour nécessaire,
elle irait dans `routes/console/envoi-releve.php`), `config/envoi.php` et tout `config/*.php` (aucune clé, aucun `env()`),
`.env.example`, `app/Envoi/Sequence/Sequenceur.php`, `RegistreInjectionsSequence.php`, `Inscriptions.php`, `Fiche.php`,
`LecteurFicheDb.php`, `ComposeurSequenceMessage.php`, `app/Envoi/Gardes/EnvoiAutorise.php`, `app/Envoi/Imap/Verp.php`,
`Classificateur.php`, `RecepteurRetoursCrm.php`, `RecepteurRetoursFreins.php` (lots L5, L6, D4),
`app/Envoi/Temoins/**`, `app/Envoi/Smtp/AdaptateurSmtp.php`, `app/Envoi/Sante/**`, `app/Crm/Opposition/**`,
`tests/Support/SemeurTablesScopees.php`, instantanés `nr07`, `nr08`, `nr20` (aucune tâche, aucune route ajoutée),
`LISTE.json`, `infra/serveur-envoi/**`, toute migration existante. Aucun écran, aucune route HTTP.

---

## 8. Rôles : chaque test de sécurité deux fois
Sous `pgsql_app` (non propriétaire, RLS appliquée) ET sous le propriétaire (RLS contournée : seul le filtre explicite
`workspace_id` protège). Un test qui ne passe que sous l'un des deux est un défaut. Modèle :
`tests/Feature/Envoi/SchemaLienCampagneSequenceTest.php:77-95` ou `tests/Feature/Crm/ProvenanceTiersRoleApplicatifTest.php`
(sans `RefreshDatabase`, nettoyage propriétaire dans un `finally`, en AJOUT seul : le nettoyage ne touche que les
lignes créées par le test).

## 9. Conditions d'activation (rien ne lit une vraie boîte d'ici là)
Tout le lot est livré éteint. La relève réelle n'aura lieu que si, ensemble : `ENVOI_REEL_ACTIF=true`,
`ENVOI_RELEVE_ACTIF=true`, une boîte créée, un accès IMAP posé par `envoi:boite-secret` sur un hôte de la liste fermée.
Avant d'allumer : D2 fusionné (sinon les réponses n'arrêtent rien) et L5 (STOP, plainte, rebond sur la séquence).

---

## 10. ESCALADE (décisions du fondateur ; rien ne bloque l'écriture du code)
- **ESCALADE 1 (achat) — hébergeur des boîtes d'envoi** et donc l'hôte IMAP. Le code part avec la liste des témoins
  (Google, Microsoft, Yahoo, Orange). Un autre hébergeur = une petite PR (constante + CHECK).
- **ESCALADE 2 (production) — poser les accès** : créer les mots de passe d'application, les saisir avec
  `envoi:boite-secret` sur le serveur (jamais dans un ticket ni un dépôt). Clé de chiffrement = `APP_KEY` (même choix
  que `envoi_boites` et les témoins) : une rotation d'`APP_KEY` rend les accès illisibles ; prévoir de les reposer.
- **ESCALADE 3 (production) — allumer `ENVOI_RELEVE_ACTIF`** : seulement après la bascule d'`ENVOI_REEL_ACTIF`, D2 et L5.
- **ESCALADE 4 (juridique) — conservation des réponses** : le texte chiffré des réponses est gardé
  (`StockerReponse`, R12) ; durée de conservation à fixer (aucune purge n'est écrite ici).

## 11. Hors de ce cahier
L5 (STOP, plainte, rebond dur, opposition sur la séquence) ; L6 (compteurs par boîte de la santé, R04) ; L7 (absence du
bureau : +7 jours) ; D4 (relève hors espace commercial sans plantage, `RecepteurRetoursCrm.php:42-44`) ; D6 (écran
Réponses) ; retrait des branches « ancienne forme » de C1-2 (90 jours après la dernière ancienne ligne).
