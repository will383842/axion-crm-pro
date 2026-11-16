# CAHIER — LOT X1-L2-bis « filtre de campagne par réseau d'origine » (sélection en lecture seule)

**Pour le développeur :** dépôt PUBLIC `will383842/axion-crm-pro`. Dans le code, les tests, les commits et les PR : écrire « le fondateur », jamais de nom ; données fictives en `*.example.test`. Chaque PR part de `origin/main` (relevé : `d42beb06`, après #640 et #645), jamais empilée sur une autre. **Vérifier chaque numéro de ligne avant de s'y fier.**

**Drapeaux intouchés, dans TOUTES les PR :** `ORIGINES_LECTURE_ENABLED` (`config/origines.php:45`), `ORIGINES_V3_ENABLED`, `ENVOI_REEL_ACTIF` (`config/envoi.php`), `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED`. Ni `.env.example`, ni `config/*.php`, ni les fichiers de déploiement, ni `tests/Feature/Infra/DeploiementPoseLesInterrupteursDesOriginesTest.php`. Dans les tests, seul `config([...])` en mémoire (aide existante `OriginesJeu::allumer()`, `tests/Support/OriginesJeu.php:96`) est permis.

Ce que fait le lot : une campagne peut porter un **filtre « réseau d'origine »** (`{"reseaux": ["linkedin", …]}`). Seules les unités (contact ciblé ou entreprise) rattachées à une personne venue d'un de ces réseaux sont retenues. Le filtre **ne peut que restreindre** une cible : jamais l'élargir. Population `personnes` : toujours fermée (le filtre ne l'ouvre pas).

---

## 0. Ordre des PR

| Ordre | PR | Contenu | Préalables |
|---|---|---|---|
| 1 | **L2b-A** | migration SEULE : colonne `envoi_campagnes.filtre_origine` (CHECK, figée une fois reliée, droits) + tests de schéma | aucun |
| — | *déploiement* | L2b-A **déployée**, `migrate:status` sans `Pending` | obligatoire avant L2b-C |
| 2 | **L2b-B** | `FiltreOrigine` (forme, garde « actif ») + `RequetesOrigines::reseauxDesUnites` (lecture) + tests | aucun (peut partir en parallèle de L2b-A : n'utilise pas la nouvelle colonne) |
| 3 | **L2b-C** | branchement dans `SelectionDestinataires` (motif `hors_reseau`, refus `filtre_origine_inactif`) + une ligne de `MaterialisationCampagne` + tests | L2b-A déployée, L2b-B fusionnée |
| 4 | **L2b-D** | écriture du filtre : `DepotCampagnes` (champ modifiable), assistant étape 1, contrôle bloquant, contrat `creation.yaml` + tests | L2b-C fusionnée (**jamais avant** : un filtre écrit mais ignoré élargirait la cible) |
| 5 | **L2b-E** | écran : sélecteur de réseaux dans `EtapeCible.tsx`, récapitulatif + tests front | L2b-D fusionnée |

Chaque PR : 250 à 400 lignes, tests compris ; la description liste les fichiers autorisés et seulement ceux-là sont touchés.

---

## 1. Constats vérifiés dans le code (ils fixent la conception)

1. **Les origines sont portées par les PERSONNES, pas par les fiches.** `personnes.origines` (jsonb, ≤ 50 entrées, `2026_11_06_000010_personnes_origines_v3.php:23`) et, au-delà, `personne_origines_archive` (ajout seul, RLS forcée, rôle applicatif : SELECT + INSERT seulement, `:44-58`). Une entrée porte `reseau`, `canal`, `compte`, `famille`, `detail`, `mot`, `date`… (liste blanche `ContenusAccords.php:48-50`) ; `reseau` suit le jeton `^[a-z0-9][a-z0-9._-]{0,63}$` (`RequetesOrigines::JETON`, `RequetesOrigines.php:25`). Les entrées mises à l'écart (`masque_le`) sont exclues de toute lecture d'écran (`RequetesOrigines.php:39-63`).
2. **Le lien personne → fiche existe déjà :** `personnes.contact_id` et `personnes.company_id` (`2026_09_24_000001…:156-157`, index partiels `idx_personnes_contact`, `idx_personnes_company` `:186-187`). Il n'existe AUCUNE origine stockée sur `contacts` ni sur `companies` (seulement `field_origins`, qui dit d'où vient chaque CHAMP, pas d'où vient la personne). « Le réseau d'une entreprise » = le réseau des personnes rattachées à elle ou à un de ses contacts.
3. **`personnes` n'a pas de `deleted_at`** (choix délibéré, `2026_09_24_000001…:30`) ; elle est cloisonnée par la RLS générique (`tests/Support/SemeurTablesScopees.php`). `personnes.opposition_at` existe (P0 fusionné).
4. **Il y a déjà UNE source SQL de lecture des origines :** `RequetesOrigines::entrees()` (privée, `:39`), « SQL unique de LECTURE » (en-tête). L'écran Origines et l'export la partagent. Le filtre doit la réutiliser (mêmes entrées visibles = même vérité à l'écran et à l'envoi), pas la recopier.
5. **La lecture des origines a trois portes** (`OriginesController`, `GardeOrigines.php`) : `origines.lecture_enabled` (éteint → 404), fonction d'espace `origines.lecture` (`FonctionsEspace::ORIGINES_LECTURE`, `app/Socle/Espace/FonctionsEspace.php:20`, sans ligne = fermée ; table `fonctions_espace` sans RLS, c'est un réglage), et `origines.v3_enabled` + colonne `personnes.origines` présente (sinon `{"actif": false}`, `OriginesController.php:309-312`).
6. **`StatutOpposition` ne voit une personne opposée que par `email_hash`** (`app/Crm/Opposition/StatutOpposition.php:97`). Une personne opposée RATTACHÉE à un contact (`personnes.contact_id`) dont l'adresse de contact diffère n'est donc pas vue. Le filtre va lire ce rattachement : il doit fermer ce trou sur son propre chemin (§ 4.3, règle O2). Le généraliser au chemin sans filtre change le comportement actuel : hors lot (ESCALADE 4).
7. **`envoi_campagnes.cible` ne peut pas porter le filtre :** CHECK `(cible - 'type' - 'reference' - 'libelle') = '{}'` (`2026_12_02_000080_envoi_campagnes.php:28-35`). Le mettre dans `saisie` reproduirait ce que L2 refuse déjà (`contact_ids` dans la saisie → `cible_non_supportee`, `SelectionDestinataires.php:79-82` : « la colonne n'en garde pas trace, la sélection ne doit pas deviner »), sans CHECK ni gel propre. D'où une **colonne dédiée** (L2b-A).
8. **Gel une fois reliée :** la garde `envoi_campagnes_garde()` fige `cible`, `boites`, `nb_etapes`, `saisie` quand `sequence_id` est posé (`2026_12_04_000030…:88-90`). La nouvelle colonne doit être figée de la même façon, sinon un filtre pourrait changer entre deux lots d'une matérialisation. On n'édite PAS cette fonction (grosse, testée par la matrice des 49 couples) : un déclencheur à part, sur la seule colonne.
9. **Le code est remplacé AVANT la migration au déploiement** (constat 1 du cahier `X1-L4-P2-cahier.md`, PR #609). L2b-C lit la colonne par `to_jsonb(k) -> 'filtre_origine'` : colonne absente ⇒ `null` ⇒ « pas de filtre », ce qui est exact (aucun filtre n'a pu être écrit sans la colonne).
10. **`MaterialisationCampagne` (#640) convertit les refus de sélection** : `cible_introuvable` → `bloquee` ; `cible_non_supportee`, `population_non_ouverte` → refus non réessayable, rien écrit ; tout autre motif → `lecture_impossible` réessayable (`MaterialisationCampagne.php:124-131`). Les motifs de `bloquee` sont une liste FERMÉE en base (`2026_12_04_000020…:30-31`) : le nouveau refus n'y entre pas.
11. **Les compteurs de L2 sont écrits par L4** dans `envoi_campagnes_materialisations.compteurs` (nombres, 2 048 octets au plus). Ajouter une clé `hors_reseau` reste très en dessous. Les tests existants itèrent sur `SelectionDestinataires::MOTIFS` (`SelectionDestinatairesTest.php:139-147`) : ajouter un motif ne les casse pas.
12. **Numéros de migration :** aucun fichier, aucune branche distante ni PR ouverte n'utilise `2026_12_10_*` (vérifié sur les 303 branches). Réservé : `2026_12_10_000100`.

---

## 2. Décision : drapeau éteint et filtre demandé ⇒ REFUS EXPLICITE

Trois cas, tranchés :

| Situation | Comportement |
|---|---|
| Campagne **sans** filtre (cas de toutes les campagnes actuelles) | **Rien ne change**, drapeau allumé ou éteint : mêmes destinataires, mêmes compteurs, une clé `hors_reseau` à 0 en plus. Aucune requête sur `personnes` ni sur l'archive. |
| Campagne **avec** filtre, filtre **actif** (les trois portes du constat 5 ouvertes) | Le filtre restreint (§ 4). |
| Campagne **avec** filtre, filtre **inactif** (une porte fermée) | `SelectionRefusee('filtre_origine_inactif', réessayable: false)`, **aucun destinataire**, AVANT toute lecture des membres. |

**Pourquoi refuser plutôt qu'ignorer :** ignorer le filtre ÉLARGIT silencieusement la cible : des personnes que le fondateur n'a pas choisies recevraient un message, et un envoi ne se reprend pas. Refuser coûte un retard (la campagne attend), jamais un envoi de trop. C'est la règle déjà appliquée par L2 (« fermé en cas d'erreur », clé d'opposition absente = opposée). Côté matérialisation, le refus est **non réessayable et n'écrit rien** (comme `cible_non_supportee`) : la campagne reste `programmee` ; si le drapeau est rallumé, l'appel suivant repart du même curseur, sans doublon. Si le drapeau est éteint en cours de matérialisation, les lots déjà inscrits restent (ils ont été choisis filtre actif) et plus rien n'est ajouté.

À l'écriture (L2b-D) : poser ou changer un filtre quand il est inactif est refusé (422 `filtre_origine_inactif`). Le **retirer** (null) reste permis tant que la campagne n'est pas reliée : c'est un choix explicite du fondateur, qui débloque une campagne.

---

## 3. PR L2b-A — migration SEULE

### 3.1 Fichiers autorisés
- Créer `backend/database/migrations/2026_12_10_000100_envoi_campagnes_filtre_origine.php`
- Créer `backend/tests/Feature/Envoi/SchemaFiltreOrigineCampagneTest.php` (préfixe de fonctions `sfc`)
- Modifier `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : UNE ligne dans `NR17_RETOUR_INERTE_VOULU` (`:41`), précédée de `// Filet modifié : pourquoi — X1-L2-bis : colonne de filtre ajoutée, down() inerte (aucune donnée de campagne ne se perd)`.
- Créer `docs/envoi/lots/X1-L2-bis-A.md` (modèle `X1-L1.md` : fait, sortie rouge, mutations, limites)

### 3.2 À ne pas toucher
Toute autre migration (dont `2026_12_02_000080…` et `2026_12_04_000030…` : la fonction `envoi_campagnes_garde()` n'est PAS réécrite), tout fichier de `app/`, `routes/`, `config/`, les instantanés `tests/Feature/NonRegression/instantanes/*`, `CampagnesModeleDbTest.php`, `SchemaLienCampagneSequenceTest.php`.

### 3.3 Migration (dans une transaction ; ADDITIVE ; aucune ligne touchée)
```sql
SET LOCAL lock_timeout = '5s';
ALTER TABLE envoi_campagnes ADD COLUMN IF NOT EXISTS filtre_origine JSONB NULL;   -- NULL = pas de filtre ; pas de réécriture
ALTER TABLE envoi_campagnes DROP CONSTRAINT IF EXISTS envoi_campagnes_filtre_origine_forme;  -- rejouabilité de SA contrainte seulement
ALTER TABLE envoi_campagnes ADD CONSTRAINT envoi_campagnes_filtre_origine_forme CHECK (
  filtre_origine IS NULL OR (
        jsonb_typeof(filtre_origine) = 'object'
    AND filtre_origine ? 'reseaux'
    AND (filtre_origine - 'reseaux') = '{}'::jsonb
    AND jsonb_typeof(filtre_origine -> 'reseaux') = 'array'
    AND jsonb_array_length(filtre_origine -> 'reseaux') BETWEEN 1 AND 10
    AND NOT jsonb_path_exists(filtre_origine, '$.reseaux[*] ? (@.type() != "string" || !(@ like_regex "^[a-z0-9][a-z0-9._-]{0,63}$"))')
    AND octet_length(filtre_origine::text) <= 1024)
) NOT VALID;
ALTER TABLE envoi_campagnes VALIDATE CONSTRAINT envoi_campagnes_filtre_origine_forme;
COMMENT ON COLUMN envoi_campagnes.filtre_origine IS 'X1-L2-bis : {"reseaux": [...]} restreint la cible aux unités rattachées à une personne venue de ces réseaux. NULL = aucun filtre. Figé une fois la campagne reliée.';
```
(`jsonb_path_exists` sans `_tz` est IMMUTABLE : permis dans un CHECK. Le vérifier dans le test de structure.)

**Déclencheur de gel** `envoi_campagnes_filtre_origine_fige`, `BEFORE UPDATE OF filtre_origine ON envoi_campagnes FOR EACH ROW`, fonction `SET search_path = pg_catalog, public, pg_temp` (`pg_temp` EN DERNIER, leçon M1 de `X1-L1.md`), sans `SECURITY DEFINER` :
```sql
IF NEW.filtre_origine IS DISTINCT FROM OLD.filtre_origine AND (OLD.sequence_id IS NOT NULL OR NEW.sequence_id IS NOT NULL) THEN
  RAISE EXCEPTION 'envoi_campagnes : le filtre d''origine est figé une fois la campagne reliée';
END IF;
RETURN NEW;
```
Même règle que `cible` (constat 8) ; la garde existante continue d'imposer `version + 1` à toute modification.

**Droits** (rôle `pgsql_app`, s'il existe — modèle `2026_11_06_000010…:51-57`) : `GRANT INSERT (filtre_origine), UPDATE (filtre_origine) ON public.envoi_campagnes`. Aucun autre droit. Pas de nouvelle politique : la RLS de la table (`envoi_campagnes_workspace_isolation`) couvre la colonne.

`down()` : INERTE (commentaire seulement). On ne supprime jamais la colonne ni son contenu.

### 3.4 Tests (écrits AVANT, vus rouges ; PostgreSQL réel ; chaque test en modes `['app','proprio']` selon le patron `slcSous` de `SchemaLienCampagneSequenceTest.php:77-95`)
1. **Structure** : colonne `jsonb` NULL ; contrainte `envoi_campagnes_filtre_origine_forme` présente et VALIDE (`pg_constraint.convalidated`) ; déclencheur présent ; `proconfig` de la fonction = `search_path=pg_catalog, public, pg_temp` ; `has_column_privilege(app, 'envoi_campagnes', 'filtre_origine', 'UPDATE')` vrai, `INSERT` vrai.
2. **Formes acceptées** (UPDATE en `version + 1`, campagne brouillon non reliée) : `NULL` ; `{"reseaux":["linkedin"]}` ; 10 réseaux.
3. **Formes refusées (23514)** : `[]`, `{}`, `{"reseaux":[]}`, 11 réseaux, `{"reseaux":["LinkedIn"]}`, `{"reseaux":["a b"]}`, `{"reseaux":[1]}`, `{"reseaux":["x@example.test"]}`, clé en trop `{"reseaux":["x"],"canal":"y"}`, texte > 1 024 octets.
4. **Gel** : campagne reliée par le chemin légal (`tests/Support/LienCampagneSequence.php`) → changer `filtre_origine` (valeur → autre, valeur → NULL, NULL → valeur) : `P0001` ; même UPDATE sur une campagne non reliée : passe. Poser `sequence_id` ET changer le filtre dans le même UPDATE : `P0001`.
5. **Pas de trou par `pg_temp`** : une table temporaire `envoi_campagnes` du rôle applicatif ne change rien au gel (modèle M1 de X1-L1).
6. **Cloisonnement** : sous le rôle applicatif avec l'espace A, UPDATE du filtre d'une campagne de l'espace B → 0 ligne.
7. **Additif** : base garnie (campagnes reliées et non reliées) → `migrate` puis `migrate:rollback --step=1` puis `migrate` : mêmes comptes, colonnes existantes inchangées, `filtre_origine` NULL partout.
8. **Les gardes existantes tiennent** : modifier le filtre sans `version + 1` → `P0001` (garde R08 inchangée).

### 3.5 Mutations attendues (casser, voir rouge, restaurer ; tableau dans `X1-L2-bis-A.md`)
| Mutation | Test qui rougit |
|---|---|
| `like_regex` retiré | 3 |
| borne `BETWEEN 1 AND 10` retirée | 3 |
| `(filtre_origine - 'reseaux') = '{}'` retiré | 3 |
| condition `NEW.sequence_id IS NOT NULL` retirée du gel | 4 (dernier cas) |
| déclencheur de gel retiré | 4 |
| `pg_temp` retiré du `search_path` | 1 |
| `GRANT UPDATE (filtre_origine)` retiré | 2 (mode app) |

---

## 4. PR L2b-B — `FiltreOrigine` et lecture des réseaux des unités

### 4.1 Fichiers autorisés
- Créer `backend/app/Envoi/Campagnes/FiltreOrigine.php`
- Modifier `backend/app/Crm/Origines/RequetesOrigines.php` : AJOUT d'une méthode publique (rien d'autre ne change dans le fichier)
- Créer `backend/tests/Feature/Envoi/FiltreOrigineTest.php` (préfixe `fio`)
- Créer `docs/envoi/lots/X1-L2-bis-B.md`

### 4.2 À ne pas toucher
`OriginesController.php`, `GardeOrigines.php`, `FonctionsEspace.php`, `config/origines.php`, `SelectionDestinataires.php` (L2b-C), `app/Crm/Opposition/**`, les autres méthodes de `RequetesOrigines`, les routes.

### 4.3 Signatures et règles
```php
namespace App\Envoi\Campagnes;

final class FiltreOrigine
{
    public const RESEAUX_MAX = 10;

    /** @param list<string> $reseaux triés, sans doublon, chacun conforme à RequetesOrigines::JETON */
    private function __construct(public readonly array $reseaux) {}

    /** Depuis la valeur de la colonne (string JSON, array ou null). null => pas de filtre. Forme invalide => \InvalidArgumentException. */
    public static function depuisColonne(mixed $valeur): ?self;

    /** Depuis une saisie (écran, API) : trim, déduplique, trie ; 1..RESEAUX_MAX jetons ; sinon \InvalidArgumentException. */
    public static function depuisSaisie(mixed $reseaux): self;

    /** @return array{reseaux: list<string>} forme EXACTE écrite en base */
    public function enColonne(): array;

    /** Les trois portes du constat 5, lues à l'instant : lecture_enabled ET v3_enabled ET colonne personnes.origines ET fonction d'espace `origines.lecture` ouverte. */
    public static function actif(string $ws): bool;
}
```
`actif()` lit `config('origines.lecture_enabled')`, `config('origines.v3_enabled')`, `Schema::hasColumn('personnes', 'origines')`, `FonctionsEspace::ouverte($ws, FonctionsEspace::ORIGINES_LECTURE)`, **dans cet ordre, en s'arrêtant au premier faux** (drapeau éteint : zéro requête). Aucune écriture.

Méthode ajoutée à `RequetesOrigines` (réutilise `entrees()` : même exclusion de `masque_le`, même union fiche + archive) :
```php
/**
 * Unités rattachées à au moins une personne NON OPPOSÉE venue d'un de ces réseaux, et contacts rattachés à une personne OPPOSÉE.
 * @param list<int> $entreprises  @param list<int> $contacts  @param list<string> $reseaux
 * @return array{entreprises: array<int,true>, contacts: array<int,true>, contacts_opposes: array<int,true>}
 */
public static function reseauxDesUnites(string $espace, array $entreprises, array $contacts, array $reseaux): array;
```
Déroulé, TROIS requêtes au plus, toutes en bindings, jamais une par ligne :
1. **Personnes rattachées** (index `idx_personnes_company`, `idx_personnes_contact`, `idx_contacts_company`) :
   ```sql
   SELECT p.id, p.company_id, p.contact_id, k.company_id AS entreprise_du_contact, (p.opposition_at IS NOT NULL) AS opposee
     FROM personnes p
     LEFT JOIN contacts k ON k.id = p.contact_id AND k.workspace_id = p.workspace_id
    WHERE p.workspace_id = ?::uuid
      AND (p.company_id = ANY(?::bigint[])
           OR p.contact_id = ANY(?::bigint[])
           OR p.contact_id IN (SELECT c.id FROM contacts c WHERE c.workspace_id = ?::uuid AND c.company_id = ANY(?::bigint[])))
   ```
   (Le développeur colle le `EXPLAIN` dans la PR : aucun parcours séquentiel de `personnes` sur une base de 10 000 personnes.)
2. **Entrées visibles** de ces seules personnes NON opposées : `entrees($espace, $ids)` puis `WHERE x.o ->> 'reseau' = ANY(?::text[])`, `SELECT DISTINCT x.personne_id`. Aucun appel si la liste est vide.
3. Construction en PHP :
   - **R1 (entreprise)** : l'entreprise E est « du réseau » si une personne retenue en 2 a `company_id = E` ou `entreprise_du_contact = E`.
   - **R2 (contact ciblé)** : le contact C est « du réseau » si une personne retenue en 2 a `contact_id = C`, OU si l'entreprise de C est du réseau selon R1 (même règle pour les deux formes de cible ; variante stricte : ESCALADE 1). L'appelant (L2b-C) passe l'entreprise de C dans `$entreprises`.
   - **O1** : une personne OPPOSÉE ne fait JAMAIS entrer une unité dans le réseau (ses origines ne servent pas à cibler).
   - **O2** : `contacts_opposes` = contacts C tels qu'une personne opposée a `contact_id = C`. L2b-C les compte `opposition` (constat 6).

`RequetesOrigines::entrees()` exige `personnes.opposition_at` et lit `p.workspace_id` explicitement ET sous RLS : rien à changer.

### 4.4 Tests (rouges d'abord ; PostgreSQL réel ; modes `['app','proprio']` ; jeux de `OriginesJeu`)
1. `depuisSaisie` : `[' linkedin ', 'facebook', 'linkedin']` → `['facebook', 'linkedin']` ; refus : `[]`, 11 jetons, `'LinkedIn'`, `'x@example.test'`, non-liste, élément non chaîne. `enColonne()` accepté par le CHECK de L2b-A (aller-retour en base, si L2b-A est déjà fusionnée ; sinon comparaison à la forme littérale).
2. `depuisColonne` : `null` → `null` ; JSON valide → objet ; JSON hors forme → exception.
3. `actif` : chacune des quatre conditions fausse à son tour → faux ; toutes vraies → vrai. **Drapeau éteint : `DB::listen` ne voit AUCUNE requête.** `config('origines.lecture_enabled')` relu à la fin du test : inchangé par rapport au début.
4. R1 : personne `company_id = E` venue de `linkedin` → E ; personne rattachée à un contact de E → E ; venue de `facebook` seulement, filtre `linkedin` → pas E.
5. R2 : personne `contact_id = C` → C ; personne rattachée à l'entreprise de C → C.
6. Archive : origine `linkedin` seulement dans `personne_origines_archive` → compte ; archive avec `masque_le` posé, ou entrée de fiche avec `masque_le` → NE compte PAS.
7. O1/O2 : personne opposée venue de `linkedin`, rattachée à E et à C → E et C absents du réseau ; C dans `contacts_opposes`.
8. Cloisonnement : une personne `linkedin` de l'espace B, rattachée à une fiche de B, et la même liste d'identifiants passée depuis A → rien dans A. En mode app la RLS suffit ; en mode proprio seul le filtre explicite `workspace_id` protège (c'est ce que prouve la mutation « `p.workspace_id = ?` retiré »).
9. Lecture seule : `DB::listen` ne voit ni `INSERT`, ni `UPDATE`, ni `DELETE` ; au plus 3 requêtes pour 50 unités.

### 4.5 Mutations attendues
| Mutation | Test qui rougit |
|---|---|
| filtre `masque_le` de l'archive retiré (en appelant une copie de la requête au lieu de `entrees()`) | 6 |
| O1 retirée (personnes opposées gardées) | 7 |
| R1 sans le chemin « contact de E » | 4 |
| `= ANY(reseaux)` remplacé par « au moins une origine » | 4 |
| ordre de `actif()` : `FonctionsEspace::ouverte` avant le drapeau | 3 (zéro requête) |
| `p.workspace_id = ?` retiré (mode proprio) | 8 |

---

## 5. PR L2b-C — branchement dans `SelectionDestinataires`

### 5.1 Fichiers autorisés
- Modifier `backend/app/Envoi/Campagnes/SelectionDestinataires.php`
- Modifier `backend/app/Envoi/Campagnes/SelectionRefusee.php` (deux fabriques)
- Modifier `backend/app/Envoi/Campagnes/MaterialisationCampagne.php` : UNE ligne (`:128`, liste des refus non réessayables)
- Créer `backend/tests/Feature/Envoi/SelectionFiltreOrigineTest.php` (préfixe `sfo`)
- Modifier `backend/tests/Feature/Envoi/MaterialisationCampagneTest.php` : AJOUT de deux tests (aucun test existant modifié)
- Créer `docs/envoi/lots/X1-L2-bis-C.md`

### 5.2 À ne pas toucher
`SelectionDestinatairesTest.php` (il doit rester vert SANS modification : c'est la preuve que « sans filtre, rien ne change »), `RequetesOrigines.php` (L2b-B), `app/Crm/Opposition/**`, `DepotCampagnes.php`, `AssistantCreation.php`, `Inscriptions.php`, `Sequenceur.php`, `ConstructionSequence.php`, `ReetalementStock.php`, `EnvoiServiceProvider.php`, les migrations, les routes, `phpstan-baseline.neon` (s'il faut y toucher : ESCALADE à l'architecte).

### 5.3 Contrat exact
- `MOTIFS` gagne `'hors_reseau'` (en dernier). `PRIORITE` est **inchangée**.
- `SelectionRefusee::filtreOrigineInactif()` : motif `filtre_origine_inactif`, non réessayable, message « Le filtre par réseau d'origine est inactif dans cet espace : rien n'est sélectionné. » ; `SelectionRefusee::lectureOrigineImpossible(\Throwable)` : motif `lecture_origine_impossible`, réessayable.
- Signature de `lot()` **inchangée**.

Déroulé, dans `calculer()` (`SelectionDestinataires.php:68-91`) :
1. La lecture de la campagne devient :
   `SELECT cible, saisie, to_jsonb(k) -> 'filtre_origine' AS filtre_origine FROM envoi_campagnes k WHERE k.workspace_id = ? AND k.id = ?` (constat 9).
2. `$filtre = FiltreOrigine::depuisColonne(...)`. Forme invalide (impossible avec le CHECK, mais défense) → `filtreOrigineInactif()` (fermé).
3. Si `$filtre !== null && ! FiltreOrigine::actif($ws)` → `filtreOrigineInactif()`, **avant** la lecture des membres (zéro requête sur les tables de la cible, sur `personnes`, sur l'archive).
4. Le contrôle `contact_ids` existant reste avant (inchangé).
5. Dans `choisir()` (`:175`), quand `$filtre !== null` seulement : après la construction des unités (`:190-200`) et AVANT `lireExclusions` (`:213`), UN appel `RequetesOrigines::reseauxDesUnites($ws, $entreprisesDesUnites, $contactsCibles, $filtre->reseaux)` (entreprises = celles des unités E7 non tranchées + l'entreprise de chaque contact ciblé). Exception → `lectureOrigineImpossible` (`57014` → `lectureTropLongue`), aucun destinataire.
6. **Motif `hors_reseau`** : une unité qui n'a pas déjà un motif direct (`fiche_supprimee`, `sans_entreprise`, `sans_adresse`, `generique_cible`, `entreprise_deja_couverte`) et qui n'est pas « du réseau » (R1/R2) est comptée `hors_reseau` puis sautée : elle n'est PAS envoyée aux lectures d'exclusion. Une entreprise sans contact mais du réseau garde `sans_contact` (inchangé).
7. **O2** : un candidat dont le `contact_id` est dans `contacts_opposes` a le drapeau `opposition` vrai dans `motifPrioritaire` (`:328`), en plus de la réponse de `StatutOpposition`. L'ordre de `PRIORITE` décide comme aujourd'hui.
8. Sans filtre (`$filtre === null`) : **aucune** de ces étapes ne s'exécute. Destinataires et compteurs identiques à aujourd'hui, `hors_reseau = 0`.
9. Invariant inchangé : `membres = retenus + somme(MOTIFS)` (désormais avec `hors_reseau`).

`MaterialisationCampagne.php:128` : `['cible_non_supportee', 'population_non_ouverte', 'filtre_origine_inactif']` → `MaterialisationRefusee('filtre_origine_inactif', false)`, **rien écrit** (pas d'état `bloquee` : motif absent de la liste fermée, constat 10). `lecture_origine_impossible` tombe dans `lecture_impossible` (réessayable) sans changement de code.

### 5.4 Tests (rouges d'abord ; PostgreSQL réel ; modes `['app','proprio']` ; campagnes via `DepotCampagnes::creer`, filtre posé en `version + 1` par une aide `sfoFiltrer()` qui écrit la colonne en propriétaire ; personnes via `OriginesJeu::personne` / `archive` ; invariant vérifié dans CHAQUE test)
1. **Sans filtre, rien ne change** : même jeu que le test 2 et le test 3 de `SelectionDestinatairesTest`, drapeau éteint PUIS allumé : destinataires et compteurs identiques (hors `hors_reseau = 0`) ; `DB::listen` ne voit aucune requête sur `personnes`, `personne_origines_archive`, `fonctions_espace`.
2. **Filtre + drapeau éteint** (`origines.lecture_enabled = false`, valeur par défaut) : `SelectionRefusee` `filtre_origine_inactif`, non réessayable, aucun destinataire ; aucune requête sur `listes_manuelles*`, `contacts`, `companies`, `personnes` après la lecture de la campagne.
3. **Filtre + chaque autre porte fermée** (v3 éteint ; fonction d'espace absente ; fonction fermée) : même refus.
4. **Liste mixte filtrée `linkedin`** : entreprise E1 (personne `linkedin` rattachée), E2 (personne `facebook`), contact ciblé C3 (personne `linkedin` sur le contact), C4 (aucune personne) → retenus E1 et C3 ; `hors_reseau = 2`.
5. **Filtre à deux réseaux** `['facebook','linkedin']` → E1, E2, C3 retenus.
6. **Personne opposée jamais sélectionnée** : C5 rattaché à une personne opposée venue de `linkedin`, adresse du contact DIFFÉRENTE de celle de la personne (donc invisible pour `StatutOpposition`) ; E5 dont le seul lien `linkedin` est cette personne → C5 : `hors_reseau` (O1) ; ajouter une seconde personne `linkedin` non opposée rattachée à E5 → le contact C5 de E5 est compté `opposition` (O2), un autre nominatif de E5 est retenu.
7. **Les exclusions existantes restent** pour une unité du réseau : opposition (`envoi_oppositions`), E6 `pending`, presse, client console, déjà en séquence → mêmes motifs qu'en L2.
8. **Archive et `masque_le`** : origine seulement dans l'archive → retenue ; seulement masquée → `hors_reseau`.
9. **Cloisonnement** : la même origine `linkedin` dans l'espace B, rattachée à des identifiants de l'espace B → aucun effet dans A ; campagne de B lue depuis A → `campagne_introuvable` (inchangé).
10. **Curseur** : 5 membres, `taille` 2, filtre actif → 3 lots ; somme des compteurs = un lot de 500.
11. **Lecture seule** : aucun `INSERT`/`UPDATE`/`DELETE` ; `SET LOCAL statement_timeout` avant le premier `SELECT` (inchangé).
12. **Erreur de lecture des origines** (table `personne_origines_archive` rendue illisible pour le test, par ex. `REVOKE SELECT` en propriétaire dans la transaction de test en mode app) → `lecture_origine_impossible`, réessayable, aucun destinataire.
13. **Colonne absente simulée** : impossible en base de test (migration jouée) ; test de source : la requête de campagne utilise `to_jsonb(k) -> 'filtre_origine'` (lecture du fichier, comme `CampagnesModeleDbTest.php:277-278`).

Ajouts dans `MaterialisationCampagneTest.php` :
14. Campagne `programmee` avec filtre, drapeau éteint → `MaterialisationRefusee` `filtre_origine_inactif` non réessayable ; AUCUNE inscription, AUCUNE ligne de matérialisation créée au-delà de ce qui existait, campagne toujours `programmee`.
15. Même campagne, drapeau allumé par `config()` → seules les unités du réseau sont inscrites ; compteurs `hors_reseau` écrits dans `envoi_campagnes_materialisations.compteurs` ; invariants de L4 tenus.

### 5.5 Mutations attendues
| Mutation | Test qui rougit |
|---|---|
| filtre ignoré quand inactif (sélection élargie au lieu du refus) | 2, 3, 14 |
| refus placé APRÈS la lecture des membres | 2 (requêtes vues) |
| `hors_reseau` non compté (unité simplement sautée) | 4 (invariant) |
| O2 retirée | 6 |
| lecture des origines faite aussi sans filtre | 1 |
| exception de lecture avalée (unité considérée « du réseau ») | 12 |
| `filtre_origine_inactif` retiré de la liste de `MaterialisationCampagne` | 14 (devient réessayable) |
| R2 : contact ciblé jugé sur son seul contact (pas son entreprise) | 4 |

---

## 6. PR L2b-D — écriture du filtre (API de l'assistant)

### 6.1 Fichiers autorisés
- Modifier `backend/app/Envoi/Campagnes/DepotCampagnes.php` : `CHAMPS_MODIFIABLES` (`:48`) gagne `filtre_origine` ; `valider()` le passe par `FiltreOrigine::depuisSaisie` (ou `null`).
- Modifier `backend/app/Envoi/Creation/AssistantCreation.php` : étape 1 (`:94-98`), `presenter()` (`:129`), `controles()` (`:400`).
- Modifier `backend/openapi/envoi/creation.yaml` : `Cible` (`:568`) gagne `origine: { type: object, properties: { reseaux: { type: array, maxItems: 10, items: { type: string, pattern: '^[a-z0-9][a-z0-9._-]{0,63}$' } } } }` (nullable)  ; `Brouillon` (`:725`, `:770`) expose `cible.origine`.
- Créer `backend/tests/Feature/Envoi/CreationFiltreOrigineTest.php` (préfixe `cfo`)
- Créer `docs/envoi/lots/X1-L2-bis-D.md`

### 6.2 À ne pas toucher
`SelectionDestinataires.php`, `MaterialisationCampagne.php`, `RequetesOrigines.php`, `OriginesController.php`, les routes (aucune route nouvelle : la liste des réseaux vient de `GET /api/v1/crm/origines/par-reseau`, déjà gardée), `routes/envoi/*.php`, les instantanés de routes, `CreationAssistantTest.php` (doit rester vert sans modification).

### 6.3 Comportement
- `PUT /envoi/brouillons/{id}/etapes/1`, corps `{"cible": {..., "origine": {"reseaux": ["linkedin"]}}}` :
  - filtre présent et `FiltreOrigine::actif($ws)` faux → **422** `filtre_origine_inactif`, rien écrit (version inchangée) ;
  - filtre valide et actif → `changes['filtre_origine'] = $filtre->enColonne()` ; la saisie garde `contenu.cible.origine` (pour l'écran seulement : **la colonne fait foi**, la sélection ne lit jamais la saisie) ;
  - `"origine": null` → colonne remise à NULL (permis même filtre inactif, voir § 2) ;
  - clé `origine` absente → colonne inchangée ;
  - forme invalide → 422 `validation` ;
  - campagne non brouillon → 409 `campagne_figee` (inchangé).
- `presenter()` : `cible.origine` = valeur de la COLONNE (`{"reseaux": [...]}` ou `null`), jamais celle de la saisie.
- `controles()` : nouvel élément `filtre_origine` — absent si pas de filtre ; `ok` « Filtre par réseau : linkedin » si actif ; `bloquant` « Le filtre par réseau d'origine est inactif : retirez-le ou attendez son ouverture » si inactif. Plus un élément `attention` « L'estimation affichée ne tient pas compte du filtre ; le compte exact (hors réseau) apparaît à la préparation de l'envoi » (voir ESCALADE 3).
- `DepotCampagnes::modifier` : `filtre_origine` accepté seulement par ce chemin (la garde « version + 1 » et le gel de L2b-A s'appliquent).

### 6.4 Tests (rouges d'abord ; requêtes HTTP réelles avec l'utilisateur propriétaire de l'espace ; PostgreSQL réel ; les écritures de `DepotCampagnes` aussi en mode `app` via `SET LOCAL ROLE`)
1. Filtre actif → 200, colonne = `{"reseaux":["linkedin"]}`, version + 1 ; `GET /envoi/brouillons/{id}` rend `cible.origine`.
2. Drapeau éteint (défaut) → 422 `filtre_origine_inactif`, colonne NULL, version inchangée.
3. Fonction d'espace fermée → 422 ; espace B ouvert, espace A fermé → 422 dans A.
4. `origine: null` avec drapeau éteint → 200, colonne NULL.
5. Formes invalides (11 réseaux, majuscules, `@`) → 422 `validation`.
6. Contrôles : filtre posé puis drapeau éteint (`config`) → contrôle `filtre_origine` `bloquant` et la validation de la campagne est refusée comme pour tout contrôle bloquant.
7. La saisie seule ne suffit pas : écrire `saisie.contenu.cible.origine` sans la colonne (propriétaire) → la sélection (L2b-C) ne filtre pas et `presenter` rend `origine: null`.
8. Campagne reliée : tentative de changer le filtre par `DepotCampagnes::modifier` → `CampagneRefusee` (la base répond `P0001`, convertie, jamais d'erreur brute).
9. Réponses conformes à `creation.yaml` (validateur de contrat déjà utilisé par `CreationAssistantTest`).

### 6.5 Mutations attendues
| Mutation | Test qui rougit |
|---|---|
| contrôle `actif()` retiré à l'écriture | 2, 3 |
| filtre écrit dans la saisie seulement | 1, 7 |
| `presenter` lit la saisie | 7 |
| contrôle `filtre_origine` jamais `bloquant` | 6 |
| retrait refusé quand inactif | 4 |

---

## 7. PR L2b-E — écran de campagne

### 7.1 Fichiers autorisés
- Modifier `frontend/src/features/envoi/creation/EtapeCible.tsx`, `saisie.ts`, `Recapitulatif.tsx`, `api.ts`
- Créer `frontend/src/features/envoi/creation/FiltreReseau.tsx` et `frontend/src/features/envoi/creation/FiltreReseau.test.tsx`
- Régénérer `frontend/src/features/envoi/api/creation.d.ts` depuis `creation.yaml` (commande du dépôt, aucune édition à la main)

### 7.2 À ne pas toucher
`frontend/src/features/origines/**`, `routeTree.tsx`, `Sidebar.tsx`, `frontend/tests/non-regression/routes.test.ts`, tout le backend.

### 7.3 Comportement
- Bloc replié « Restreindre par réseau d'origine » sous le choix de la cible. La liste des réseaux vient de `GET /api/v1/crm/origines/par-reseau` (réseaux distincts, non nuls, triés par nombre de personnes). **404 ou `{"actif": false}` : le bloc n'est PAS affiché** et, si le brouillon porte déjà un filtre, un bandeau dit « Filtre par réseau inactif : cette campagne ne partira pas tant qu'il est posé » avec un bouton « Retirer le filtre » (envoie `origine: null`).
- Cases à cocher (10 au plus) ; envoi `cible.origine.reseaux` à l'étape 1 ; erreur 422 `filtre_origine_inactif` affichée telle quelle, sans réessai automatique.
- Le récapitulatif affiche « Uniquement les personnes venues de : LinkedIn, Facebook » (libellés de `features/origines/libelles.ts`, importés en lecture seule).
- L'estimation garde son chiffre, avec la mention du contrôle `attention` (§ 6.3).

### 7.4 Tests (vitest, rouges d'abord)
1. API en 404 → bloc absent. 2. `{"actif": false}` + brouillon filtré → bandeau et bouton de retrait qui envoie `origine: null`. 3. Deux réseaux cochés → corps exact `{"reseaux": ["facebook","linkedin"]}`. 4. Onze réseaux proposés → la onzième case est désactivée. 5. 422 `filtre_origine_inactif` → message affiché, pas de nouvel appel. 6. Récapitulatif : libellés attendus.

### 7.5 Mutations attendues
bloc affiché malgré 404 → 1 ; tri/dédoublonnage retiré → 3 ; borne de 10 retirée → 4 ; réessai automatique → 5.

---

## 8. Erreurs et motifs (liste fermée)
- `SelectionRefusee` : + `filtre_origine_inactif` (non réessayable), + `lecture_origine_impossible` (réessayable). Les autres inchangés.
- `MaterialisationRefusee` : + `filtre_origine_inactif` (non réessayable, rien écrit).
- Compteurs : + `hors_reseau`.
- API de l'assistant : 422 `filtre_origine_inactif`, 422 `validation`.
- Jamais de `QueryException` brute (règle de L2 inchangée).

## 9. Ce que le lot ne fait PAS
Il n'allume aucun drapeau, n'ouvre aucune fonction d'espace, n'ouvre pas la population `personnes`, n'écrit rien dans `personnes` ni dans l'archive, ne supprime aucune donnée, n'ajoute aucune route, n'envoie rien (`ENVOI_REEL_ACTIF` reste faux). Il ne lit pas les origines v1/v2 (`personnes.origine_contenu`, ESCALADE 2).

---

## 10. ESCALADE — décisions du fondateur (le reste du lot avance sans attendre, avec les valeurs par défaut indiquées)

1. **Portée du rattachement (produit).** Défaut retenu : une entreprise est « du réseau » si une personne venue de ce réseau est rattachée à l'entreprise OU à un de ses contacts ; un contact ciblé l'est aussi via son entreprise. Variante plus stricte : un contact ciblé seulement par sa propre personne, et dans une entreprise on n'écrirait qu'au contact venu du réseau (cela toucherait la règle E7 : lot séparé).
2. **Origines anciennes (produit).** Défaut : seules les origines v3 (`personnes.origines` + archive) comptent, comme l'écran Origines. Les origines v2 (`personnes.origine_contenu.reseau`) sont ignorées. Les inclure = un lot de plus.
3. **Estimation à l'écran (produit).** Défaut : l'estimation ignore le filtre et le dit (contrôle `attention`). Un vrai compte filtré à l'écran = un lot de plus (lecture coûteuse sur grosse cible).
4. **Trou d'opposition hors filtre (conformité).** Constat 6 : sans filtre, un contact rattaché à une personne opposée dont l'adresse diffère n'est pas exclu par `StatutOpposition`. Ce lot le ferme sur le chemin filtré seulement (« drapeau éteint, rien ne change »). Recommandation : un lot dédié dans `app/Crm/Opposition/**` pour l'appliquer partout. À trancher.
5. **Juridique.** Cibler une prospection à partir du réseau social par lequel une personne est arrivée est un nouvel usage d'une donnée collectée par le site (base légale, information art. 13/14, compatibilité de finalité). Avis juridique avant d'allumer `ORIGINES_LECTURE_ENABLED` pour cet usage. Le lot reste inerte tant que le drapeau est éteint.
6. **Allumage (production).** Rallumer le drapeau, ouvrir `origines.lecture` pour un espace : décision et déploiement du fondateur, hors lot.
