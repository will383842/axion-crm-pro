# Cahier des charges — C1 (transport : composeur, registre, domaine de rebond) puis F2a (variables des messages)

Conception seule : aucun code de l'application n'est modifié par ce document. Rédigé le 16/11/2026 sur `main` (11bce50b).
Chaque affirmation renvoie au code (`fichier:ligne`, chemins relatifs à `backend/` sauf mention). Données d'exemple :
`*.example.test` seulement. Aucun drapeau n'est touché (`ENVOI_REEL_ACTIF`, `ENVOI_K1_ACTIF`, `ENVOI_SANTE_ACTIF` restent tels quels).

**Ordre obligatoire : C1 (toutes ses PR) fusionné et déployé AVANT F2a.** Les deux lots modifient
`app/Envoi/Sequence/Sequenceur.php` et `app/Envoi/Sequence/ComposeurSequenceMessage.php` (fichiers chauds).

---

## 0. Constats vérifiés dans le code

| N° | Constat | Preuve |
|----|---------|--------|
| K1 | Le séquenceur écrit dans `envoi_messages.id_injection` l'identifiant RENDU par le serveur (`sim-N` pour le faux, l'identifiant Kumo sinon). | `Sequenceur.php:208` et `:431` |
| K2 | Le registre `envoi_messages_envoyes` reçoit, lui, un UUID LOCAL tiré dans `envoi_injections_locales` (clé `seq-<inscription>-<étape>`), dont l'adaptateur est DEVINÉ (`sim-` ⇒ faux, sinon kumo). | `Sequenceur.php:448-476`, `:463` |
| K3 | La relève rend au récepteur l'identifiant du REGISTRE (VERP ou Message-ID). | `Imap/ReleveImap.php:221-233`, `:262-265` ; `Imap/RegistreMessagesEnvoyesDb.php:18-28`, `:30-56` |
| K4 | `RecepteurRetoursSequence` cherche `envoi_messages.id_injection = <id du registre>` : avec K1, il ne trouve jamais rien. Une réponse humaine n'arrête donc PAS la séquence. Le test existant pose à la main le même identifiant des deux côtés et ne voit pas le défaut. | `Imap/RecepteurRetoursSequence.php:39-40` ; `tests/Feature/Envoi/ReleveImapBrancheeTest.php:44-70` |
| K5 | Le VERP n'est pas calculé par le CRM : seul le transport Kumo par tunnel le pose, à partir de SON jeton (HMAC d'un secret de l'adaptateur), absent du registre. Un rebond portant ce VERP reste « à rattacher ». | `Serveur/ServeurEnvoiKumo.php:116-117`, `:178-191` ; `Serveur/Kumo/TransportSmtpTunnel.php:27-28` ; `Imap/ReleveImap.php:347-357` |
| K6 | `ComposeurMessage` émet `X-Envoi-Jeton` dans le message (décision E2 du plan transport : il ne doit plus le faire ; E6 : aucun `X-Envoi-*` en sortie). | `Message/ComposeurMessage.php:80-82`, `:121` ; test `tests/Unit/Envoi/Message/ComposeurMessageTest.php:100-103` |
| K7 | Le plan DNS ne publie ni MX ni SPF pour `bounce.<domaine>` ; aucun point de contrôle ne les vérifie. Or `Verp` fabrique `rb-…@bounce.<domaine>`. | `Dns/PlanDns.php:16-43` ; `Dns/ControleurDns.php:12-84` ; `Imap/Verp.php:16-27` |
| K8 | Sans clé `envoi.cle_empreinte`, `Verp::adressePour` signe avec une clé vide : le VERP produit ne se relit jamais (`idInjectionDepuis` rend null sans clé). | `Imap/Verp.php:18-27`, `:32-35`, `:48-51` |
| V1 | Le séquenceur passe l'objet et le corps BRUTS de la version figée. | `Sequenceur.php:320-330` |
| V2 | `ComposeurSequenceMessage` ne transmet aucune variable : `DemandeMessage::$variables` reste `[]`, donc chaque `{{prenom|repli}}` devient le repli pour TOUS. | `Sequence/ComposeurSequenceMessage.php:33-49` ; `Message/DemandeMessage.php:37` ; `Message/ComposeurMessage.php:99-101`, `:144-157` |
| V3 | `Fiche` / `LecteurFicheDb` ne lisent ni prénom, ni nom, ni organisation, ni ville, ni fonction. | `Sequence/Fiche.php:71-83` ; `Sequence/LecteurFicheDb.php:137` |
| V4 | Défaut HTML : le corps est échappé EN ENTIER avant le rendu, puis la valeur rendue est ré-échappée ; un repli contenant `'` ou `&` sort doublement échappé (`&amp;#039;`). | `ComposeurSequenceMessage.php:40` puis `ComposeurMessage.php:101`, `:156`, `:238-241` |
| V5 | Le contrôle « repli obligatoire » existe déjà ; aucune liste fermée de noms. | `Message/ControleurContenu.php:66-76` |
| V6 | Le contrôle du contenu est rejoué sur le message RENDU : une valeur venue de la base qui contient un mot ou un lien interdit (ex. ville « Grenoble », organisation avec une adresse web) ferait refuser le message entier, puis l'inscription tomberait en échec répété. | `ComposeurMessage.php:103` ; `ControleurContenu.php:78-95` ; `Sequenceur.php:367-389` (échec répété) |
| V7 | `POST /envoi/creation/champs` répond toujours `manquants: 0`. | `Http/Controllers/Api/EnvoiMessagesController.php:127-160` (ligne 157) |
| V8 | L'aperçu d'un brouillon répond 501 ; l'aperçu de message type rend les replis seulement ; les « destinataires exemples » sont les 3 premiers contacts de l'ESPACE, pas de la cible. | `Http/Controllers/Envoi/CreationController.php:69-75` ; `Envoi/Creation/ServiceMessagesTypes.php:81-90` ; `Envoi/Creation/AssistantCreation.php:503-517` |
| V9 | La valeur de repli saisie à l'étape 2 de l'assistant est stockée mais jamais appliquée à l'envoi (la construction de séquence ne la lit pas). | `AssistantCreation.php:24` ; `EnvoiMessagesController.php:132`, `:155` ; branche `feat/x1-l3-construction` (#604) : aucune lecture de `repli` |

---

## 1. Lot C1 — transport : TR-COMPOSEUR + TR-REGISTRE + TR-DNS-BOUNCE

### 1.1 But
1. Un **identifiant d'injection unique et stable**, choisi par le CRM AVANT le réseau, enregistré au registre, écrit dans
   `envoi_messages.id_injection`, porté par le message (`MessageAEnvoyer`) et par le chemin de retour VERP.
2. Pendant la transition, **lire les deux formes** d'identifiant (ancienne : identifiant du serveur dans `envoi_messages` ;
   nouvelle : identifiant du registre).
3. Le message composé ne porte plus `X-Envoi-Jeton`.
4. Le plan DNS publie et contrôle le **domaine de rebond** `bounce.<domaine>` (MX + SPF).

### 1.2 Découpage en PR (une à la fois, chacune 250 à 400 lignes tests compris)
| PR | Contenu | Dépend de |
|----|---------|-----------|
| C1-0 « migration seule » | migrations `2026_12_05_000200` et `000210` + tests de schéma + instantanés | — |
| C1-1 | registre stable, VERP, écriture des deux colonnes, fin de `X-Envoi-Jeton` | C1-0 fusionnée ET déployée (le déploiement remplace le code AVANT `migrate` : `.github/workflows/deploy-direct-ssh.yml:443` puis `:561`) |
| C1-2 | lecture des deux formes (récepteur et registre) | C1-1 |
| C1-3 | DNS du domaine de rebond | C1-1 (indépendant du séquenceur, mais même lot) |

Plage de migrations réservée : `2026_12_05_000200` à `000290`. C1 n'en utilise que deux.

### 1.3 C1-0 — migrations (additives, aucune donnée touchée)

**`database/migrations/2026_12_05_000200_envoi_messages_id_injection_serveur.php`**
```sql
ALTER TABLE envoi_messages ADD COLUMN IF NOT EXISTS id_injection_serveur TEXT;
ALTER TABLE envoi_messages DROP CONSTRAINT IF EXISTS envoi_messages_id_injection_serveur_longueur;
ALTER TABLE envoi_messages ADD CONSTRAINT envoi_messages_id_injection_serveur_longueur
    CHECK (id_injection_serveur IS NULL OR char_length(id_injection_serveur) BETWEEN 1 AND 200);
COMMENT ON COLUMN envoi_messages.id_injection_serveur IS
    'C1 : identifiant rendu par le serveur quand il differe de l''identifiant du registre (envoi_messages.id_injection).';
```
- `down()` INERTE (retirer la colonne effacerait des données) : ajouter le fichier à `NR17_RETOUR_INERTE_VOULU`
  (`tests/Feature/NonRegression/Nr17MigrationsTest.php:41`).
- Droits : rien à ajouter. Le rôle applicatif a déjà `SELECT, INSERT, UPDATE` au niveau de la TABLE
  (`2026_11_10_000032_envoi_messages.php:45`), ce qui couvre une colonne ajoutée. Le test le prouve (voir 1.7).
- RLS : inchangée (`FORCE`, `2026_11_10_000032_envoi_messages.php:34-40`).
- Le `DROP CONSTRAINT IF EXISTS` ne vise que la contrainte créée ici (rejouabilité), jamais une donnée.

**`database/migrations/2026_12_05_000210_envoi_messages_index_injection.php`** (modèle : `2026_10_03_000060_companies_index_site_non_verifie.php`)
```php
public $withinTransaction = false;
// up()
CREATE INDEX CONCURRENTLY IF NOT EXISTS envoi_messages_injection
    ON envoi_messages (workspace_id, id_injection) WHERE id_injection IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS envoi_messages_injection_serveur
    ON envoi_messages (workspace_id, id_injection_serveur) WHERE id_injection_serveur IS NOT NULL;
// down() : DROP INDEX CONCURRENTLY IF EXISTS … (un index n'est pas une donnée ; aller-retour-aller NR-17)
```
Aujourd'hui aucun index ne sert la recherche de `RecepteurRetoursSequence.php:39-40`.

Instantanés : NR-07, NR-08, NR-17, NR-20 à régénérer (`backend/AGENTS.md`, « Nouvelle migration ») ; « Filet modifié : pourquoi » dans la PR.

### 1.4 C1-1 — registre stable, VERP, composeur

**Nouveau fichier `app/Envoi/Sequence/RegistreInjectionsSequence.php`**
```php
final class RegistreInjectionsSequence
{
    /** Adaptateurs pour lesquels le CRM choisit l'identifiant. smtp garde le sien (envoi_injections_smtp, adaptateur non branché : décision E4). */
    public const ADAPTATEURS = ['faux', 'kumo'];

    /**
     * Réserve (ou relit) l'identifiant de la clé `seq-<inscription>-<étape>`. Stable : même clé => même UUID, à vie
     * (clé primaire (workspace_id, cle_idempotence), table en ajout seul). null si le domaine est inconnu de l'espace ou sur smtp.
     * @return array{id: string, adaptateur: string, cheminRetour: ?string}|null
     */
    public function reserver(string $ws, string $cle, string $domaine): ?array;

    /** Clé `seq-<uuid>-<1..3>` => [inscription_id, étape] ; sinon null. Sert à la lecture de l'ancienne forme. */
    public static function inscriptionDeCle(string $cle): ?array;
}
```
SQL de `reserver` (dans la transaction du temps A, sous le contexte d'espace) :
```sql
SELECT adaptateur FROM envoi_domaines WHERE workspace_id = :ws AND nom = :domaine;          -- comme AiguilleurServeurEnvoi.php:241-246
INSERT INTO envoi_injections_locales (workspace_id, cle_idempotence, adaptateur)
     VALUES (:ws, :cle, :adaptateur) ON CONFLICT (workspace_id, cle_idempotence) DO NOTHING;
SELECT id_injection, adaptateur FROM envoi_injections_locales WHERE workspace_id = :ws AND cle_idempotence = :cle;
```
- Si la ligne existante porte un AUTRE adaptateur que le domaine aujourd'hui (domaine basculé entre deux tentatives) :
  l'identifiant reste le même (stabilité d'abord) et une alerte `envoi.registre_adaptateur_change` part APRÈS le COMMIT
  (espace, inscription, étape ; aucune adresse). Limite documentée : `AiguilleurServeurEnvoi::etatEnvoi` interrogera l'ancien adaptateur.
- `cheminRetour = Verp::adressePour($id, $domaine)` SEULEMENT si `config('envoi.cle_empreinte')` est non vide et le domaine
  en ASCII (`[a-z0-9.-]+`) ; sinon `null` (K8). Le refus d'injecter un message réel sans chemin de retour appartient à
  TR-TRANSPORT-HTTPS / TR-AIG-BRANCHE, pas à C1.
- Droits : `SELECT, INSERT` sur `envoi_injections_locales` (`2026_12_03_000080_envoi_injections_locales.php:59-60`) et
  `SELECT` sur `envoi_domaines` existent déjà pour le rôle applicatif.

**`app/Envoi/Contrat/MessageAEnvoyer.php`** — deux paramètres FACULTATIFS en fin (aucun appelant cassé) :
```php
public readonly ?string $idInjection = null,   // UUID choisi par le CRM ; InvalidArgumentException si non UUID
public readonly ?string $cheminRetour = null,  // rb-<32 hex><10 hex>@bounce.<domaine> ; InvalidArgumentException sinon
```
Aucun adaptateur n'est modifié dans C1 (le faux serveur continue de rendre `sim-N`, Kumo son identifiant) : c'est
exactement le cas « identifiant serveur différent » que C1 doit tenir. TR-TRANSPORT-HTTPS consommera ces deux champs.

**`app/Envoi/Sequence/Sequenceur.php`** (fichier chaud)
- Constructeur : 5ᵉ paramètre facultatif `?RegistreInjectionsSequence $registre = null` (défaut `new RegistreInjectionsSequence`)
  pour ne pas toucher les 7 fichiers qui construisent un séquenceur.
- Temps A (`reserverEtPreparer`, après `reserver()` `:290` et la porte `:300-318`, avant la composition `:321`) :
  `$inj = $this->registre->reserver($ws, 'seq-' . $ins->id . '-' . $rang, $this->expedition->domaine);`
  puis `new MessageAEnvoyer(…, 'seq-…', $inj['id'] ?? null, $inj['cheminRetour'] ?? null)` (`:334`). Le plan porte `idRegistre`.
- Temps C (`:205-212`) et reprise `dejaParti` (`:430-434`) :
  `id_injection = $idRegistre ?? $res->idInjection` ;
  `id_injection_serveur = ($res->idInjection !== null && $res->idInjection !== $idRegistre) ? $res->idInjection : null`.
  Dans `dejaParti`, `$idRegistre` est relu par `reserver()` (ligne déjà présente, aucun nouvel aléa).
- `enregistrerEnvoye` (`:448-476`) : si `$idRegistre` est connu, l'écrire tel quel dans `envoi_messages_envoyes`
  (plus de devinette `sim-`/`kumo` de `:463`) ; sinon chemin actuel inchangé (smtp, domaine inconnu).
- Le commentaire `:440-446` est réécrit (l'identifiant du registre est désormais celui de `envoi_messages`).

**`app/Envoi/Message/ComposeurMessage.php`** : retirer l'en-tête `X-Envoi-Jeton` (`:121`). Le paramètre
`DemandeMessage::$jetonEnvoi` reste (contrôle de forme `:80-82` conservé) pour ne casser aucun appelant ; docblock
« n'est plus émis (C1, décision E2) ».
**`app/Envoi/Sequence/ComposeurSequenceMessage.php`** : docblock seulement dans C1 (le jeton reste dérivé du Message-ID `:41`).

### 1.5 C1-2 — lecture des deux formes pendant la transition

**`app/Envoi/Imap/RecepteurRetoursSequence.php`** (`:39-40` remplacé) : un identifiant `$id` reçu rattache les messages où
1. `envoi_messages.id_injection = $id` (nouvelle forme, et ancienne forme quand `$id` vient d'un VERP Kumo ancien) ;
2. OU `envoi_messages.id_injection_serveur = $id` ;
3. OU (si `$id` est un UUID) la ligne `envoi_injections_locales` d'`id_injection = $id` a une clé `seq-<inscription>-<étape>`
   (`RegistreInjectionsSequence::inscriptionDeCle`) et `envoi_messages` a une ligne `(workspace_id, inscription_id, etape)` :
   c'est l'ANCIENNE forme (registre local, serveur dans `envoi_messages`).
```sql
SELECT DISTINCT m.inscription_id FROM envoi_messages m
 WHERE m.workspace_id = :ws
   AND (m.id_injection = :id OR m.id_injection_serveur = :id
        OR (m.inscription_id = :inscription_de_cle AND m.etape = :etape_de_cle))   -- 3ᵉ branche seulement si la clé est lue
 ORDER BY m.inscription_id;
```
Toujours sous `WorkspaceContext::run($workspaceId, …)` et `where workspace_id` explicite (RLS + filtre).

**`app/Envoi/Imap/RegistreMessagesEnvoyesDb.php::retrouverParInjection`** (`:18-28`) : si aucune ligne du registre pour
`$id` (VERP signé posé par le tunnel Kumo = identifiant serveur), chercher `envoi_messages` par `id_injection = $id OR
id_injection_serveur = $id` (même espace), prendre son `message_id`, retrouver la ligne du registre par l'empreinte
`sha256('mid:' . …)` et rendre `MessageOrigine(<id du registre>, <adresse>)`. Plusieurs destinataires ⇒ null (règle `:20-21` conservée).
Le VERP est signé (`Verp.php:41`) : ce repli n'affaiblit pas la règle « ARF seulement par VERP » (`ReleveImap.php:218-230`).

Fin de la transition : un lot ultérieur retirera les branches 2-3 quand plus aucun message de l'ancienne forme n'a moins
de 90 jours (requête de comptage fournie dans la PR C1-2). Ne PAS les retirer dans C1.

### 1.6 C1-3 — domaine de rebond (TR-DNS-BOUNCE)
- `app/Envoi/Dns/PlanDns.php` : deux lignes de plan (modèles, jetons à remplir hors dépôt) :
  `MX bounce.<d> → "10 {HOTE_RECEPTION_REBONDS}."` (libellé `MX rebonds`) et
  `TXT bounce.<d> → "v=spf1 ip4:{IP_ENVOI} -all"` (ou `ip6:`, libellé `SPF rebonds`). Points ajoutés à
  `codesPointsDeControle()` : `bounce_mx_present`, `bounce_spf_autorise_ip` (22 points ; corriger le docblock « 20 »).
- `app/Envoi/Dns/ResolveurDns.php` : `public function mx(string $nom): array;` (liste d'hôtes). Implémentations :
  `ResolveurDnsSysteme.php` (`dns_get_record(DNS_MX)`, même gestion d'échec que `txt`), `tests/Unit/Envoi/Dns/FauxResolveurDns.php`,
  `tests/Support/ResolveurDnsSimule.php`, `tests/Feature/Envoi/Routes/InfrastructureEcrituresTest.php:19`.
- `app/Envoi/Dns/ControleurDns.php` : `bounce_mx_present` = au moins un MX ; `bounce_spf_autorise_ip` = SPF unique de
  `bounce.<d>` qui autorise l'IP (réutiliser `mecanismesSpf` et `spfAutorise`). Les deux BLOQUANTS (`ko`) : sans MX ni A,
  de nombreux destinataires refusent un MAIL FROM dont le domaine n'existe pas, et les rebonds sont perdus.
  Conséquence voulue : un domaine aujourd'hui « prêt » devient non prêt tant que ces deux enregistrements manquent
  (`RapportControle.php:25-34`), donc l'aiguilleur reporte (`AiguilleurServeurEnvoi.php` : `dns_non_vert`). Porte fermée : aucun effet réel.
- `app/Envoi/Infrastructure/EcrituresInfrastructure.php:35-46` : rôles `bounce_mx`, `bounce_spf` et leurs points.
- Contrat : `openapi/envoi/infrastructure.yaml:623` (énumération des rôles) ; `frontend/src/features/envoi/api/infrastructure.d.ts`
  (régénéré) ; libellés `frontend/src/features/envoi/infrastructure/composants/BlocsDomaine.tsx:16` (« Rebonds : MX », « Rebonds : SPF ») ;
  `frontend/src/demo/handlers/envoi/infrastructure.handlers.ts:201` (exemples `*.example.test`).

### 1.7 Tests ROUGES d'abord (à voir échouer sur `main`, puis verts)
Nouveau fichier `tests/Feature/Envoi/RegistreStableC1Test.php` (modèle d'installation : `tests/Feature/Envoi/RegistreInjectionLocaleTest.php:33-60`, faux serveur derrière `PasserelleLocale`).
1. **Réponse humaine arrête la séquence** : cycle séquenceur (faux serveur ⇒ `sim-1`), puis `RecepteurRetoursSequence::recevoir`
   avec l'identifiant RENDU par `RegistreMessagesEnvoyesDb::retrouver([<Message-ID envoyé>])` ⇒ inscription `arretee`, motif `reponse`.
   Rouge sur `main` (K4).
2. `envoi_messages.id_injection` = `envoi_messages_envoyes.id_injection` = `envoi_injections_locales.id_injection` ; `id_injection_serveur = 'sim-1'`.
3. Stabilité : report par la passerelle puis nouveau cycle ⇒ même identifiant ; reprise d'une ligne `reserve` ancienne (`dejaParti`) ⇒ même identifiant.
4. `MessageAEnvoyer` reçu par un serveur espion (`tests/Support/ServeurEspion.php`) porte `idInjection` = registre et
   `cheminRetour` = `Verp::adressePour(id, domaine)` ; `Verp::idInjectionDepuis(cheminRetour)` rend l'identifiant.
5. Sans `envoi.cle_empreinte` : `cheminRetour` null, envoi du faux serveur inchangé.
6. Domaine sur `smtp` : aucune ligne `envoi_injections_locales` créée par la réservation.
7. Adaptateur exact : domaine `kumo` (posé par `envoi_domaine_adaptateur_changer`) ⇒ ligne `adaptateur = 'kumo'` même si le serveur rend `sim-…`.
8. **Ancienne forme** (lignes posées comme l'ancien code : `envoi_messages.id_injection = 'sim-7'`, registre = UUID local de clé `seq-<ins>-1`) :
   le récepteur arrête l'inscription. Idem forme « VERP Kumo ancien » via `RegistreMessagesEnvoyesDb::retrouverParInjection`.
9. **Cloisonnement** : même identifiant dans l'espace B ⇒ aucune inscription de A touchée.
10. **Rôle applicatif** : tests 1, 8 et 9 rejoués sur `pgsql_app` (modèle `tests/Feature/Envoi/AiguilleurServeurEnvoiTest.php:530-564`),
    et le test de schéma vérifie `has_column_privilege(<rôle>, 'envoi_messages', 'id_injection_serveur', 'UPDATE')` vrai,
    et les deux index présents (`pg_indexes`). Les mêmes tests passent sous le propriétaire (SUPERUSER, RLS contournée : le filtre explicite protège).
11. `tests/Unit/Envoi/Message/ComposeurMessageTest.php:100-103` « pose X-Envoi-Jeton » est REMPLACÉ par son contraire, plus strict :
    « n'émet aucun en-tête X-Envoi-* ». Ce n'est pas un affaiblissement : décision E2/E6 ; à écrire tel quel dans « Filet modifié : pourquoi ».
12. DNS : `tests/Unit/Envoi/Dns/PlanDnsTest.php` (2 lignes de plus, jetons non remplacés), `ControleurDnsTest.php`
    (MX absent ⇒ `ko`, SPF de rebond sans l'IP ⇒ `ko`, tout présent ⇒ prêt), `InfrastructureEcrituresTest.php` (rôles exposés),
    contrat OpenAPI (`tests/Support/ContratOpenApiEnvoi.php`).

### 1.8 Mutations à montrer dans la PR (chacune doit rougir au moins un test, puis être annulée)
- `Sequenceur` : écrire `$res->idInjection` au lieu de `$idRegistre` dans `id_injection` ⇒ tests 1 et 2.
- `RegistreInjectionsSequence::reserver` : `insert` au lieu de `insertOrIgnore`/relecture (nouvel UUID à chaque passage) ⇒ test 3.
- Retirer la 3ᵉ branche du récepteur ⇒ test 8 ; retirer le filtre `workspace_id` ⇒ test 9 sous propriétaire.
- Remettre `X-Envoi-Jeton` ⇒ test 11. Passer `bounce_mx_present` en `avert` ⇒ test 12.

### 1.9 Ne PAS toucher dans C1
`AiguilleurServeurEnvoi.php` (lot TR-AIG-BRANCHE, sauf rien), `ServeurEnvoiKumo.php`, `Kumo/TransportSmtpTunnel.php`
(TR-TRANSPORT-HTTPS / TR-K4), `PasserelleLocale.php`, `Simulateur/FauxServeurEnvoi.php`, `Imap/Verp.php`, `Sequence/Fiche.php`,
`Sequence/LecteurFicheDb.php`, `Gardes/EnvoiAutorise.php`, `app/Crm/Opposition/**` (autre session), `config/envoi.php`,
tout `infra/serveur-envoi/**` (dont `passerelle/src/Passerelle.php` et le contrat du serveur : TR-CONTRAT v3), les migrations
existantes, les tables en ajout seul (aucun UPDATE/DELETE de `envoi_injections_locales` ni de `envoi_messages_envoyes`).

---

## 2. Lot F2a — variables des messages (après C1)

### 2.1 But
Les `{{nom|repli}}` reçoivent la VRAIE valeur du destinataire, pour une LISTE FERMÉE de champs, lue au plus juste ;
l'écran montre l'aperçu rendu sur 3 vrais destinataires de la cible et le vrai compte des champs manquants.
Sans intelligence artificielle (la route `suggestion` reste 501 : `EnvoiMessagesController.php:117-120`), sans tirage
aléatoire (ordre stable : plus petites clés d'abord).

### 2.2 Liste fermée (`app/Envoi/Message/VariablesMessage.php`, nouveau)
| Nom | Source (lecture seule) | Longueur max | Casse |
|-----|------------------------|--------------|-------|
| `prenom` | `contacts.first_name` | 40 | tout majuscule ou tout minuscule ⇒ casse de titre (`mb_convert_case`, règle fixe) |
| `nom` | `contacts.last_name` | 40 | idem |
| `fonction` | `contacts.title` | 60 | inchangée |
| `organisation` | `COALESCE(NULLIF(btrim(companies.enseigne), ''), companies.denomination)` | 80 | inchangée |
| `ville` | `companies.city` | 60 | inchangée |

```php
final class VariablesMessage
{
    public const NOMS = ['prenom', 'nom', 'organisation', 'ville', 'fonction'];
    /** @return list<string> noms trouvés dans les gabarits (triés, sans doublon) */
    public static function utilisees(string ...$gabarits): array;
    /** @return list<string> motifs « Variable {{x}} inconnue : champs permis : prenom, nom, organisation, ville, fonction. » */
    public static function inconnues(string ...$gabarits): array;
}
```
Le repli reste obligatoire (contrôle existant `ControleurContenu.php:66-76`, inchangé). La liste fermée est exigée à
l'ÉCRITURE et à la VALIDATION d'un message type (`ServiceMessagesTypes::motifs`, `:39-44` ; code de contrôle
`valeurs_repli` via `codeDuMotif` `:65-73`). Elle n'est PAS ajoutée à `ControleurContenu` : une version déjà figée
contenant un nom inconnu ne doit pas faire échouer une séquence en cours ; à l'envoi, un nom inconnu prend son repli.

### 2.3 Valeurs : nettoyage déterministe (`app/Envoi/Message/ValeursVariables.php`, nouveau)
```php
/** @param array<string, ?string> $brutes @return array{valeurs: array<string,string>, ecartees: list<string>} */
public static function nettoyer(array $brutes, ControleurContenu $controleur = new ControleurContenu): array;
```
Pour chaque nom de la liste fermée : NFC, retrait des caractères de contrôle et sauts de ligne (protège l'en-tête Subject),
espaces fusionnés, `trim`. La valeur est ÉCARTÉE (le repli s'applique, le nom va dans `ecartees`) si : vide ; plus longue que
le maximum (jamais tronquée) ; contient `{{`, `}}`, `@`, `<`, `>`, `://` ou `www.` ; ou si
`$controleur->analyser([$valeur])` rend un motif (mot interdit, lien traqueur, domaine principal : V6). Résultat : une
valeur de la base ne peut plus faire refuser le message entier. Aucune valeur n'est jamais journalisée.

### 2.4 Lecture minimale (`app/Envoi/Message/LecteurVariables.php` interface + `LecteurVariablesDb.php`, nouveaux)
```php
interface LecteurVariables
{
    /** @param list<int> $contactIds @param list<string> $noms ⊆ VariablesMessage::NOMS @return array<int, array<string, ?string>> */
    public function pourContacts(string $ws, array $contactIds, array $noms): array;
}
```
- Ne lit QUE les colonnes des noms demandés (table de correspondance fermée, jamais de nom de colonne venu de l'appelant).
  Gabarit sans variable ⇒ AUCUNE requête.
```sql
-- exemple pour noms = [prenom, organisation]
SELECT c.id, c.first_name AS prenom,
       COALESCE(NULLIF(btrim(o.enseigne), ''), o.denomination) AS organisation
  FROM contacts c JOIN companies o ON o.id = c.company_id
 WHERE c.workspace_id = :ws AND o.workspace_id = :ws
   AND c.id = ANY(:ids) AND c.deleted_at IS NULL;      -- mêmes jointures que LecteurFicheDb.php:131-136
```
- Exécuté dans `WorkspaceContext::run($ws, …)` : RLS sous `pgsql_app`, filtre explicite sous le propriétaire.
- Droits : `SELECT` sur `contacts` et `companies` déjà accordé au rôle applicatif (lu par `LecteurFicheDb`). Aucune migration F2a.
- Choix de séparer de `Fiche`/`LecteurFicheDb` (fichiers chauds) : la porte d'envoi ne reçoit aucune donnée nominative inutile,
  et l'aperçu utilise EXACTEMENT le même lecteur que l'envoi.

### 2.5 Branchement à l'envoi
- `app/Envoi/Sequence/DemandeComposition.php` : paramètre final `public readonly array $variables = []` (`array<string,string>`).
- `Sequenceur.php` (chaud) : 6ᵉ paramètre facultatif `?LecteurVariables $variables = null` (défaut `new LecteurVariablesDb`).
  Au temps A, juste avant `:321` : `$noms = VariablesMessage::utilisees($sujet, $corps)` ; si non vide,
  `ValeursVariables::nettoyer($this->variables->pourContacts($ws, [$fiche->contactId], $noms)[$fiche->contactId] ?? [])['valeurs']`
  passé à `DemandeComposition`. Rien d'autre ne change (porte, fenêtre, registre C1).
- `ComposeurSequenceMessage.php` (chaud) : transmet `variables: $d->variables` à `DemandeMessage` ; corrige V4 : le HTML est
  construit en échappant le texte HORS des `{{…}}` et en laissant les `{{nom|repli}}` intacts (le rendu `ComposeurMessage::rendre`
  échappe déjà valeur et repli une fois, `:156`).
- `ComposeurMessage.php` : inchangé (le rendu `:144-157` sait déjà substituer).

### 2.6 Aperçu sur 3 vrais destinataires et vrai compte (PR F2a-2)
Nouveau `app/Envoi/Creation/ApercuReel.php` (lecture seule, aucune écriture, aucun envoi) :
```php
/** 3 premiers destinataires RETENUS de la cible du brouillon (ordre des clés, sans tirage). @return list<array{id:int, libelle:string}> */
public function exemples(string $ws, string $brouillonId): array;
/** Rendu objet + corps de l'étape pour ces 3 destinataires. @return list<array{id:int, libelle:string, objet:string, corps:string, replis:list<string>}> */
public function rendre(string $ws, string $brouillonId, int $etape): array;
/** @return array<string, array{manquants:int, sur:int, partiel:bool}> */
public function compter(string $ws, string $brouillonId, array $noms): array;
```
- Destinataires : `SelectionDestinataires::lot($ws, $brouillonId, 'entreprises', $apres, 50)` (lot X1-L2, #603 : DOIT être
  fusionné avant F2a-2), curseur suivi jusqu'à 3 retenus ou 10 lots. Aucune adresse rendue (`libelle` = « Destinataire 1 »).
- Gabarits de l'étape : `saisie.contenu.sequence[etape-1].message_id` (`AssistantCreation.php:99-100`, `:176-177`) puis
  `ServiceMessagesTypes::derniere(lire(...))`, rendus par `ComposeurMessage::rendre` avec les valeurs de `LecteurVariablesDb` + `ValeursVariables` :
  le même chemin que l'envoi. Le pied (art. 14, désinscription) n'est pas rendu ici.
- Compte : lots de 500 jusqu'à 10 lots (5 000 destinataires) ; `manquants` = destinataires dont la valeur est vide OU écartée
  (le repli partira) ; au-delà, `partiel: true` et l'écran écrit « au moins N sur les 5 000 premiers ».
- Routes (existantes, `routes/envoi/creation.php`, `users.manage`) :
  `POST /envoi/brouillons/{id}/apercu` rend `{destinataires: [...]}` au lieu du 501 (`CreationController.php:69-75`) ;
  `GET /envoi/creation/destinataires-exemples?brouillon_id=` utilise `ApercuReel::exemples` (sans `brouillon_id` : liste vide,
  plus les 3 premiers contacts de l'espace — `AssistantCreation.php:503-517` reste en place, non appelé) ;
  `POST /envoi/creation/champs` accepte `brouillon_id` facultatif : présent ⇒ vrais comptes ; absent ⇒ `manquants_connus: false`.
  Champs de nom inconnu ⇒ `bloquant: true`.
- V9 : la valeur `repli` renvoyée devient celle ÉCRITE dans le message (celle qui partira vraiment) ; la saisie d'un repli
  à l'écran reste sans effet à l'envoi : à traiter dans F2a-bis (déjà au plan, vague 7). Le dire dans la PR.
- Contrat : `openapi/envoi/creation.yaml:117-140` (réponse de l'aperçu) et `:613-628` (`ChampUtilise` : `sur`, `partiel`,
  `manquants_connus`) ; types `frontend/src/features/envoi/api/creation.d.ts:469` ; affichage
  `frontend/src/features/envoi/creation/EtapeMessage.tsx:31`, `:178-181` ; démo `frontend/src/demo/handlers/envoi/creation.handlers.ts:327-330`.
  Nouvelle route appelée par l'écran ⇒ régénérer `nr20-routes.json` (chaud).

### 2.7 Découpage F2a
| PR | Contenu |
|----|---------|
| F2a-1 | liste fermée, nettoyage, lecteur, branchement séquenceur + composeur (V2, V4, V6), contrôle à l'écriture |
| F2a-2 | `ApercuReel`, 3 routes, contrat, écran (après #603 fusionnée) |

### 2.8 Tests ROUGES d'abord
`tests/Feature/Envoi/VariablesMessagesF2aTest.php` (données fictives, `*.example.test`) :
1. Contact « Jeanne Exemple », entreprise « Exemple SAS » : sujet `Bonjour {{prenom|Madame, Monsieur}}` ⇒ le message
   composé contient « Bonjour Jeanne ». Rouge sur `main` (V2).
2. Prénom vide ⇒ repli. Prénom « JEANNE » ⇒ « Jeanne ». Enseigne vide ⇒ dénomination.
3. Ville « Grenoble » ⇒ repli de `ville`, message composé, inscription `envoye` (pas d'échec) ; organisation
   « exemple.example.test/x » ⇒ repli. Rouge sur `main` une fois F2a-1 partiellement codé sans nettoyage (V6).
4. Repli `l'équipe` ⇒ HTML contient `l&#039;équipe` une seule fois échappé (V4, rouge sur `main`).
5. Valeur avec saut de ligne dans le sujet ⇒ aucun en-tête injecté.
6. Gabarit sans variable ⇒ zéro requête du lecteur (lecteur espion).
7. Message type avec `{{societe|x}}` ⇒ 422 `contenu_refuse` à l'écriture et à la validation ; version figée ancienne
   avec `{{societe|x}}` ⇒ envoyée avec « x ».
8. Cloisonnement : `pourContacts(B, [id d'un contact de A])` ⇒ rien, sous `pgsql_app` ET sous propriétaire.
9. Rôle applicatif : tests 1, 3, 8 rejoués sur `pgsql_app`.
10. F2a-2 : brouillon dont la cible a 5 contacts (2 sans prénom, 1 opposé) ⇒ aperçu de 3 destinataires dans l'ordre des
    clés, deux appels successifs identiques ; `champs` ⇒ `prenom.manquants = 2, sur = 4` (rouge sur `main` : 0, V7) ;
    l'opposé n'apparaît jamais ; réponse sans aucune adresse ; 403 sans `users.manage`.

### 2.9 Mutations
Retirer `variables:` dans `ComposeurSequenceMessage` ⇒ 1 ; retirer le contrôle `analyser` du nettoyage ⇒ 3 ;
réintroduire `htmlspecialchars` du corps entier ⇒ 4 ; lire toutes les colonnes quel que soit `$noms` ⇒ 6 ;
retirer `o.workspace_id = :ws` ⇒ 8 (propriétaire) ; remettre `manquants => 0` ⇒ 10.

### 2.10 Ne PAS toucher dans F2a
`Sequence/Fiche.php`, `Sequence/LecteurFicheDb.php`, `Creation/AssistantCreation.php` (chauds), `Message/ControleurContenu.php`
(aucun motif nouveau), `Gardes/**`, `app/Crm/Opposition/**`, le registre et le VERP de C1, les adaptateurs, `config/envoi.php`,
`infra/serveur-envoi/**`. Aucune migration. Pas de suivi d'ouverture ni de clic.

---

## 3. Fichiers chauds touchés (un seul lot à la fois dessus)
`Sequenceur.php` (C1-1, F2a-1), `ComposeurSequenceMessage.php` (C1-1 docblock, F2a-1), instantanés NR-07/08/17/20 (C1-0, F2a-2),
`Nr17MigrationsTest.php` (C1-0, une ligne). Ne pas lancer C1 en même temps que P5 (séquenceur/porte) ni TR-AIG-BRANCHE.

## 4. ESCALADE (décisions du fondateur ; le reste continue)
- Hébergeur de réception des rebonds (cible du MX `bounce.<domaine>`) : déjà dans la liste du plan transport. C1-3 publie un jeton, pas une valeur.
- Mise en place de la clé `envoi.cle_empreinte` en production (secret) : sans elle, aucun VERP n'est posé (K8).
- Aucune autre décision n'est requise : liste fermée, casse, choix enseigne/dénomination, plafond de 5 000 pour le compte sont des recommandations de conception, réversibles.

## 5. Avant de pousser (chaque PR)
`cd backend && vendor/bin/pint --dirty` ; `vendor/bin/pest --filter='RegistreStableC1|VariablesMessagesF2a|ComposeurMessage|ControleurDns|PlanDns'` ;
filet `php vendor/bin/pest --group=non-regression --no-coverage` ; PHPStan niveau 8 sur les fichiers touchés. Si `vendor/` manque, le dire dans la PR.
