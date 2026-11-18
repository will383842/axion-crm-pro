# CAHIER — P2 « inscriptions typées » (migration seule) et X1-L4 « MaterialisationCampagne »

**Pour le développeur :** dépôt public. Dans le code, les tests, les commits et les PR : écrire « le fondateur », jamais de nom ; données fictives en `*.example.test`. Aucun drapeau touché (`ENVOI_REEL_ACTIF` reste faux, `config/envoi.php` inchangé). Base relevée : `main` 11bce50b (P0 opposition #602 fusionné). Cahiers voisins : `X1-L2-cahier.md` (PR #603) et `X1-L3-cahier.md` (PR #604). **Vérifier chaque numéro de ligne avant de s'y fier.**

## 0. Ordre de fusion (une fusion à la fois, chaque PR ≤ 400 lignes tests compris)

| Ordre | Lot | Contenu | Condition pour fusionner |
|---|---|---|---|
| 1 | **P2-A** | migrations `000100`, `000110`, `000120` (colonnes, CHECK, déclencheurs) + tests | rien |
| 2 | **P2-B** | migration `000180` (index unique « une inscription active par adresse ») + adaptation d'un test | P2-A fusionnée |
| — | *déploiement* | P2-A et P2-B **déployées**, `migrate:status` sans `Pending` | obligatoire avant L4-B |
| 3 | **L4-A** | `EtalementEcheances` (pur, sans base) + tests | peut partir dès maintenant (aucune dépendance) |
| 4 | **L4-B** | `MaterialisationCampagne` + tests | P2 déployée, #603 (L2) et #604 (L3) fusionnées, L4-A fusionnée |
| 5 | **L4-C** | commande planifiée + re-étalement du stock + fichier `routes/console/envoi-campagnes.php` | L4-B fusionnée |

Pourquoi deux PR pour P2 et trois pour L4 : la règle du dépôt (250 à 400 lignes). Le plan disait « un lot » : écart assumé, le contenu est le même.

---

## 1. Constats vérifiés dans le code (ils changent la conception)

1. **Le déploiement remplace le code AVANT de migrer.** `deploy-direct-ssh.yml:443` (`up -d --build --force-recreate ... api app horizon scheduler`) puis `:561` (`migrate --force --database=pgsql_owner`, bloquant). Pendant quelques secondes à minutes, le **nouveau code tourne sur l'ancien schéma**. Donc : P2 est livrée SEULE ; L4 ne fusionne qu'après le déploiement de P2 ; la commande de L4 vérifie le schéma (garde `hasTable`/`hasColumn` + index valide).
2. **Les migrations tournent sous le propriétaire, et la table est en `FORCE ROW LEVEL SECURITY`** (`2026_11_10_000031…:61-62`, `000034:18`). Un `SELECT count(*)` du propriétaire sans `app.current_workspace_id` voit **zéro ligne** (s'il n'est pas superutilisateur). Le contrôle des doublons doit donc se faire avec `row_security = off` (précédent : `2026_10_06_000010…:81`, `2026_10_05_000010…:179-182` : PostgreSQL LÈVE une erreur si le rôle est soumis à la RLS, donc échec fermé). *Remarque hors lot :* le compte des orphelins de `2026_12_04_000030…:27-36` n'a pas cette précaution ; il reste fermé grâce à la validation de la clé étrangère, mais son message peut être muet. À noter pour un audit.
3. **Aucune unicité « une inscription active par adresse » en base.** Seulement `UNIQUE (workspace_id, sequence_id, contact_id)` (`000031:37`), l'index d'échéance (`:41`), l'index par entreprise (`:42`) et l'index NON unique par contact actif (`2026_12_04_000040…:28`).
4. **Un test existant crée deux inscriptions actives pour la même adresse.** `tests/Feature/Envoi/SequenceurTest.php:1270-1281` inscrit le même contact dans une seconde séquence (`seqInscrire($this, $sid2, $c)`). Avec l'index unique, `insertOrIgnore` (`Inscriptions.php:40`) ignore la ligne et `inscrire()` rend **une chaîne vide** (`(string) null`, `Inscriptions.php:48-49`). Le test casserait. Voir P2-B.
5. **Lecteurs actuels de `contact_id` / `entreprise_id` d'une inscription :** `Sequenceur.php:270` (`(int) $ins->entreprise_id`), `:300` (`(int) $ins->contact_id`), `:343-360` ; `ArretParEntrepriseL1.php:38-40` (`pluck('entreprise_id')`, `whereIn`) ; `Inscriptions.php:76` et `:89` (copie de `entreprise_id` dans la trace). `envoi_inscriptions_evenements.entreprise_id` est **NOT NULL** (`2026_11_30_000320…:29`) : une inscription « personnes » (entreprise NULL) ferait échouer `Inscriptions::tracer` et donc un STOP. **Cela ne peut pas arriver après P2** : aucune ligne « personnes » n'est acceptée (déclencheur). Le lot P5 devra traiter ce point AVANT d'ouvrir l'interrupteur.
6. **L'interrupteur doit vivre en base.** `envoi_reglages` (clé/valeur par espace, RLS, `2026_12_02_000143…`) accepte la clé `envoi.population_personnes` (motif `^[a-z0-9_.]{1,60}$`). Mais le rôle applicatif peut y écrire n'importe quelle clé (`GRANT SELECT, INSERT, UPDATE`, `:34-35`) et `DepotReglages::poser` est public, sans liste de clés (`DepotReglages.php:71-78`). Il faut donc une garde sur cette clé.
7. **En production, l'application peut tourner sous le propriétaire.** `CRM_DB_APP_ROLE_ENABLED` est faux par défaut (`config/database.php:37`, `:130`) : la connexion par défaut utilise alors `DB_USERNAME`. Une garde « refusé au rôle applicatif » ne protège donc qu'une fois G4 fait. D'où, en plus, un test de source (aucune écriture de la clé dans `app/`).
8. **`personnes` n'a pas de `UNIQUE (workspace_id, id)`** (`2026_09_24_000001…:128-129`, index `:180-187`) : pas de clé étrangère composite possible sans toucher cette table. Comme `contact_id` et `entreprise_id` (sans clé étrangère, `000031:22-23`), `personne_id` reste sans clé étrangère ; la cohérence d'espace sera vérifiée par l'écrivain (P4/P5).
9. **Aujourd'hui toute l'échéance tombe à l'ouverture.** `Inscriptions.php:44` : `$fenetre->echeance($maintenant, $premier)`, et `FenetreHoraire::prochain` rend l'heure d'ouverture pile (`FenetreHoraire.php:55-61`). Un report garde l'heure du moment du report (`Sequenceur.php:614-620` → `JoursOuvres::ajouter`, qui conserve l'heure : `JoursOuvres.php:74-86`).
10. **Le jour où la porte s'ouvre sur un stock.** Porte fermée, le cycle ne tourne pas (`ChefOrchestre.php:65-67`, et la tâche n'est même pas due : `routes/console/envoi-orchestration.php:14`). Les échéances passent donc dans le passé. À l'ouverture, `executer` prend 100 inscriptions échues par minute (`Sequenceur.php:101-104`). Au-delà du plafond, la passerelle reporte ; chaque report est daté de l'heure du cycle : tout le stock se retrouve le lendemain **à la même minute**. D'où le re-étalement du stock (L4-C).
11. **Les boîtes sont toujours choisies.** Le contrôle `boites_pretes` est bloquant tant qu'aucune boîte n'est choisie (`AssistantCreation.php:431-435`). Toute campagne validée par l'assistant porte donc des boîtes, que le transport n'applique pas encore. Avec la règle « réglage non appliqué → refus », **aucune campagne de l'assistant ne sera matérialisée avant le lot transport**. C'est voulu (inerte) ; les tests créent des campagnes sans boîte via `DepotCampagnes::creer`.
12. **Les motifs de blocage d'une matérialisation sont une liste fermée** : `jours_non_applicables`, `objet_b_non_applicable`, `sequence_incoherente`, `cible_introuvable`, `etapes_invalides`, `espace_non_emetteur` (`2026_12_04_000020…:30-31`). `reglage_non_applique` n'y est pas : L4 ne l'écrit donc **pas** en état `bloquee` (état terminal) ; il saute la campagne et la compte (voir L4-B). Avantage : quand le transport appliquera les boîtes, ces campagnes repartiront sans duplication.
13. **Deux verrous d'espace différents.** `DepotCampagnes::sousVerrou` prend `envoi_campagnes_espace:<ws>` puis `envoi_campagne:<id>` (`DepotCampagnes.php:51-54`, `64-75`) ; `GestesCampagne::sousVerrou` prend `envoi:campagnes:<ws>` (`GestesCampagne.php:118-124`). L4 utilise celui de `DepotCampagnes` (le même que L3 et l'annulation).

---

## 2. LOT P2 — inscriptions typées (migration SEULE et INERTE)

**Numéros réservés :** `2026_12_05_000100` à `000190` (aucun fichier existant ni branche distante ne les utilise ; vérifié). Aucun code applicatif. Aucun fichier de `app/` touché.

### 2.1 P2-A — fichiers

- Créer `backend/database/migrations/2026_12_05_000100_envoi_inscriptions_population.php`
- Créer `backend/database/migrations/2026_12_05_000110_envoi_inscriptions_personne_index.php`
- Créer `backend/database/migrations/2026_12_05_000120_envoi_reglages_interrupteur_population.php`
- Créer `backend/tests/Feature/Envoi/SchemaInscriptionsPopulationTest.php` (préfixe `ipp`)
- Modifier `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : ajouter les fichiers à `NR17_RETOUR_INERTE_VOULU` (sur le modèle des lignes 131-137), avec la ligne « Filet modifié : pourquoi » dans la PR.
- Créer `docs/envoi/lots/P2.md` (sur le modèle de `X1-L1.md`).

### 2.2 Migration `000100` (dans une transaction)

```sql
SET LOCAL lock_timeout = '5s';  -- ne jamais faire la queue derrière le séquenceur ; échec = déploiement rouge, on relance
ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS population TEXT NOT NULL DEFAULT 'entreprises'; -- défaut constant : pas de réécriture
ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS personne_id BIGINT NULL;

ALTER TABLE envoi_inscriptions DROP CONSTRAINT IF EXISTS envoi_inscriptions_population_valeur;
ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_population_valeur
  CHECK (population IN ('entreprises','personnes')) NOT VALID;
ALTER TABLE envoi_inscriptions VALIDATE CONSTRAINT envoi_inscriptions_population_valeur;

ALTER TABLE envoi_inscriptions DROP CONSTRAINT IF EXISTS envoi_inscriptions_population_coherence;
ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_population_coherence CHECK (
     (population = 'entreprises' AND contact_id IS NOT NULL AND entreprise_id IS NOT NULL AND personne_id IS NULL)
  OR (population = 'personnes'   AND personne_id IS NOT NULL AND contact_id IS NULL AND entreprise_id IS NULL)
) NOT VALID;
ALTER TABLE envoi_inscriptions VALIDATE CONSTRAINT envoi_inscriptions_population_coherence;

-- SEULEMENT APRÈS les deux VALIDATE (même transaction) :
ALTER TABLE envoi_inscriptions ALTER COLUMN contact_id DROP NOT NULL;
ALTER TABLE envoi_inscriptions ALTER COLUMN entreprise_id DROP NOT NULL;
```
`DROP CONSTRAINT IF EXISTS` ne sert qu'à rendre `up()` rejouable sur SES propres contraintes (précédent `2026_11_10_000022…:17-18`). Aucune donnée touchée. « personnes » = jamais les deux à la fois (une personne rattachée à une entreprise reste dans la population « entreprises »).

**Déclencheur `envoi_inscriptions_population_garde`** (`BEFORE INSERT OR UPDATE OF population, personne_id, contact_id, entreprise_id`, `SET search_path = pg_catalog, public, pg_temp`, `pg_temp` EN DERNIER : leçon M1 de `X1-L1.md`) :
```sql
CREATE OR REPLACE FUNCTION envoi_inscriptions_population_garde() RETURNS trigger AS $f$
DECLARE ouvert BOOLEAN;
BEGIN
  IF TG_OP = 'UPDATE' AND (NEW.population IS DISTINCT FROM OLD.population OR NEW.personne_id IS DISTINCT FROM OLD.personne_id
      OR NEW.contact_id IS DISTINCT FROM OLD.contact_id OR NEW.entreprise_id IS DISTINCT FROM OLD.entreprise_id) THEN
    RAISE EXCEPTION 'envoi_inscriptions : population, personne, contact et entreprise sont figés';
  END IF;
  IF NEW.population = 'personnes' THEN
    SELECT (r.valeur = 'true') INTO ouvert FROM public.envoi_reglages r
     WHERE r.workspace_id = NEW.workspace_id AND r.cle = 'envoi.population_personnes';
    IF ouvert IS NOT TRUE THEN   -- absent, faux, illisible, ou invisible (RLS) : REFUS
      RAISE EXCEPTION 'envoi_inscriptions : population personnes fermée dans cet espace (envoi.population_personnes)';
    END IF;
  END IF;
  RETURN NEW;
END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;
```
Fonction **sans** `SECURITY DEFINER` : elle lit `envoi_reglages` avec les droits et la RLS de l'appelant (échec fermé si le contexte d'espace manque). Le rôle applicatif a déjà `SELECT` sur `envoi_reglages` (`000143:35`). Les droits sur `envoi_inscriptions` sont posés au niveau de la table (`000031:71`) : les nouvelles colonnes sont couvertes sans nouveau `GRANT`.

### 2.3 Migration `000110` (hors transaction, modèle exact de `2026_12_04_000040`)
`$withinTransaction = false`. Index invalide de CE nom seulement supprimé (`DROP INDEX CONCURRENTLY`), puis :
`CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS envoi_inscriptions_personne_unique ON envoi_inscriptions (workspace_id, sequence_id, personne_id) WHERE personne_id IS NOT NULL;` (dans une transaction de test : sans `CONCURRENTLY`). C'est l'équivalent, pour les personnes, de `UNIQUE (workspace_id, sequence_id, contact_id)`. Aucune ligne n'a de `personne_id` : aucun doublon possible.

### 2.4 Migration `000120` — l'interrupteur ne s'allume pas par l'application
Déclencheur `envoi_reglages_interrupteur_population` (`BEFORE INSERT OR UPDATE`, même `search_path`) :
```sql
IF (NEW.cle = 'envoi.population_personnes' OR (TG_OP = 'UPDATE' AND OLD.cle = 'envoi.population_personnes'))
   AND NOT pg_has_role(current_user, (SELECT c.relowner FROM pg_catalog.pg_class c WHERE c.oid = 'public.envoi_reglages'::regclass), 'MEMBER') THEN
  RAISE EXCEPTION 'envoi_reglages : envoi.population_personnes ne se pose que par le propriétaire (commande du fondateur)';
END IF;
IF NEW.cle = 'envoi.population_personnes' AND NEW.valeur NOT IN ('true','false') THEN
  RAISE EXCEPTION 'envoi_reglages : envoi.population_personnes vaut true ou false';
END IF;
```
Aucune ligne n'est créée : l'absence de ligne = éteint. Aucune commande pour l'allumer dans ce lot (ce sera P5/P6, et seulement après la décision du fondateur, voir ESCALADE).

### 2.5 P2-B — index « une inscription active par adresse »

- Créer `backend/database/migrations/2026_12_05_000180_envoi_inscriptions_adresse_active_unique.php`
- Ajouter ses tests dans `SchemaInscriptionsPopulationTest.php` (ou un second fichier `SchemaInscriptionAdresseActiveTest.php` si la PR P2-A est déjà fusionnée)
- Modifier `Nr17MigrationsTest.php` (une ligne) et `tests/Feature/Envoi/SequenceurTest.php:1270-1281` (voir 2.7)

Migration, `$withinTransaction = false` :
1. **Contrôle préalable, HORS RLS**, dans une transaction courte :
   ```php
   DB::transaction(function () { DB::statement('SET LOCAL row_security = off'); $n = DB::selectOne(
     "SELECT count(*) AS n FROM (SELECT 1 FROM envoi_inscriptions WHERE statut = 'active'
        GROUP BY workspace_id, empreinte_adresse HAVING count(*) > 1) d")->n; ... });
   ```
   Dans une transaction englobante (tests) : `set_config('row_security','off',true)` puis remise à la valeur lue avant (ne pas laisser `off` dans la transaction du test).
   Si `n > 0` : `throw new RuntimeException("envoi_inscriptions : {$n} adresse(s) ont plusieurs inscriptions actives dans un même espace ; la migration s'arrête, rien n'est modifié. À traiter à la main avant de relancer.")`. **Aucune adresse ni empreinte dans le message.** Aucune ligne modifiée. (Les migrations P2-A, déjà validées, restent : elles sont inertes.)
2. Si un index `envoi_inscriptions_adresse_active_unique` existe **invalide** : le supprimer, lui seul (`DROP INDEX CONCURRENTLY IF EXISTS public.envoi_inscriptions_adresse_active_unique`, précédent `000040:24-26`). Important : un index unique invalide **continue d'imposer l'unicité** aux écritures ; il ne doit pas rester.
3. `CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS envoi_inscriptions_adresse_active_unique ON envoi_inscriptions (workspace_id, empreinte_adresse) WHERE statut = 'active';`
4. Si l'étape 3 échoue (`23505` : un doublon écrit par l'ancien code entre l'étape 1 et l'étape 3) : supprimer l'index invalide (étape 2), puis relancer l'exception avec le même message que l'étape 1. Ne JAMAIS laisser un index invalide.
`down()` inerte. Tous rôles, toutes populations : une adresse = une inscription active par espace.

**ESCALADE (données, au fondateur — seulement si la migration s'arrête en production) :** quelles inscriptions en double arrêter. Aucune correction automatique.

### 2.6 Risque « deux conteneurs » (P2)
- Fenêtre `:443` → `:561` : le code est inchangé (P2 n'a que des migrations). Rien à craindre.
- Après la migration, l'ANCIEN code continue :
  - `Inscriptions::inscrire` n'écrit pas `population` → défaut `entreprises`, CHECK satisfait (contact et entreprise toujours fournis, `Inscriptions.php:41-42`). `SemeurTablesScopees.php:606-610` idem.
  - `Sequenceur` lit `(int) $ins->contact_id` / `entreprise_id` (`:270`, `:300`) : jamais NULL, car aucune ligne « personnes » ne peut exister (déclencheur, interrupteur absent).
  - `ArretParEntrepriseL1` (`:38-40`) : idem.
  - Les `UPDATE` du séquenceur (`statut`, `prochaine_echeance`, `reports`, `echecs`, `etape_courante`, `fil_*`) ne nomment pas les colonnes figées : le déclencheur `UPDATE OF …` ne se déclenche même pas (pas de coût sur le chemin chaud).
  - `Inscriptions::evenement` arrête « les autres inscriptions de la même adresse » (`:67-73`) : après l'index, il n'y en a plus d'active ; le code reste juste.
  - Une inscription d'une adresse déjà active ailleurs est ignorée en silence et `inscrire()` rend `''`. Seul appelant en production : `EssaiABlanc.php:170`, `:209`, avec des adresses tirées au hasard (`:169`) : pas de collision. Corrigé dans L4-B (exception claire).
- Retour arrière du code (image précédente) après P2 : même analyse, sans risque.
- Verrou : `ALTER TABLE` prend un verrou exclusif bref ; `lock_timeout = '5s'` évite de bloquer le séquenceur (qui ne tourne pas, porte fermée). L'index unique est construit `CONCURRENTLY`.

### 2.7 Tests P2 (écrits AVANT, vus rouges ; modes `['app','proprio']` selon `SchemaLienCampagneSequenceTest.php:77-95`)
1. **Structure :** colonnes, défaut `'entreprises'`, `contact_id`/`entreprise_id` nullables, deux CHECK validés (`convalidated`), deux déclencheurs, `search_path` des deux fonctions finissant par `pg_temp`, index `envoi_inscriptions_personne_unique` et (P2-B) `envoi_inscriptions_adresse_active_unique` valides (`indisvalid`), uniques, partiels.
2. **Ancien chemin :** `Inscriptions::inscrire` (sans `population`) → ligne `entreprises` ; sous le rôle applicatif ET le propriétaire.
3. **CHECK :** `entreprises` + `contact_id` NULL → `23514` ; `entreprises` + `personne_id` → `23514` ; `personnes` + `contact_id` → `23514` (après réglage vrai) ; `population = 'xyz'` → `23514`.
4. **Interrupteur :** `personnes` sans réglage → `P0001` ; réglage `false` → `P0001` ; réglage `true` posé par le propriétaire → accepté (rôle applicatif ET propriétaire, contexte d'espace posé) ; réglage `true` d'un AUTRE espace → `P0001` ; propriétaire sans contexte d'espace → `P0001` (RLS = fermé).
5. **Table temporaire homonyme** `envoi_reglages` contenant `true` (rôle applicatif) → toujours `P0001`.
6. **Figé :** `UPDATE` de `population`, `personne_id`, `contact_id`, `entreprise_id` → `P0001` ; `UPDATE` de `statut` / `prochaine_echeance` → accepté.
7. **Garde de la clé :** rôle applicatif : `INSERT` de `envoi.population_personnes` → `P0001` ; `UPDATE` d'une autre clé renommée en celle-ci → `P0001` ; `DepotReglages::poser($ws,'regles.plafond_jour',…)` → accepté. Propriétaire : `true`/`false` acceptés, `oui` refusé.
8. **Test de source :** aucune occurrence de `envoi.population_personnes` dans `backend/app/**` (sauf, plus tard, une lecture explicitement listée).
9. **(P2-B) Unicité :** deux inscriptions `active` de même `(espace, empreinte)` dans deux séquences → `23505` ; `active` + `terminee` → accepté ; même empreinte dans deux espaces → accepté ; arrêter la première puis en créer une nouvelle → accepté.
10. **(P2-B) Doublons existants :** dans la transaction du test, supprimer l'index, semer deux lignes actives de même adresse (propriétaire), appeler `up()` de la migration : `RuntimeException` dont le message contient « 1 adresse(s) » et AUCUNE empreinte ; comptes identiques avant/après ; index toujours absent ; `row_security` revenu à sa valeur d'avant.
11. **(P2-B) Ancien code :** `SequenceurTest`, `SequencePauseSequenceurTest`, `SequenceurConcurrenceTest`, `ArretParEntrepriseL1Test`, `EssaiABlancTest`, `RepriseApresPanneTest`, `ChefOrchestreTest`, `ReleveImapBrancheeTest`, `DesinscriptionTest` : verts sans modification, **sauf** `SequenceurTest.php:1270-1281`.
   **Adaptation (sans affaiblir) :** la seconde inscription de la même adresse est créée `terminee` (insertion directe : `statut='terminee'`, même empreinte, `sid2`), puis la trace STOP est posée comme avant. Le scénario prouvé reste le même : « un STOP reçu sur une AUTRE inscription de la même adresse arrête l'envoi » (`Sequenceur.php:353-360` lit la trace, quel que soit le statut). C'est même le seul cas réel après l'index. Ajouter à côté un test « l'ancienne forme (deux actives) est désormais refusée par la base (`23505`) ». Si d'autres tests rougissent, même méthode, chacun listé dans `P2.md`.

**Mutations à prouver (P2) :**
| Mutation | Test qui rougit |
|---|---|
| CHECK de cohérence retiré | 3 |
| déclencheur : branche `personnes` retirée | 4 |
| `ouvert IS NOT TRUE` remplacé par `NOT FOUND` (valeur ignorée) | 4 (réglage `false`) |
| `pg_temp` retiré du `search_path` | 5, 1 |
| gel des colonnes retiré | 6 |
| garde de la clé retirée | 7 |
| `WHERE statut = 'active'` retiré de l'index | 9 |
| contrôle préalable des doublons retiré | 10 (erreur `23505` brute au lieu du message) |
| `row_security = off` retiré | 10 — **seulement si** la suite ne tourne pas en superutilisateur ; sinon le dire honnêtement dans `P2.md` |

### 2.8 À ne pas toucher (P2)
Tout `backend/app/**` ; `EnvoiServiceProvider.php` ; `routes/console.php` ; instantanés `nr07`/`nr08`/`nr20` ; `SemeurTablesScopees.php` (aucune table nouvelle ; la ligne `:606-610` reste valable) ; `colonnes-json` (aucune colonne JSON nouvelle) ; `config/envoi.php` et tous les drapeaux ; la table `personnes`.

---

## 3. LOT L4-A — `EtalementEcheances` (pur, sans base)

**Fichiers :** créer `backend/app/Envoi/Campagnes/EtalementEcheances.php`, `backend/tests/Unit/Envoi/EtalementEcheancesTest.php`. Rien d'autre.

```php
namespace App\Envoi\Campagnes;

final class EtalementEcheances
{
    public const HORIZON_JOURS_OUVRES = 20;  // jamais d'inscription plus loin : le reste attend (curseur)
    public const MARGE_MINUTES = 5;          // aujourd'hui : aucun créneau à moins de 5 min
    public const ALEA_MIN = 0.10;            // décalage irrégulier dans le créneau : [10 %, 90 %[
    public const ALEA_MAX = 0.90;

    /**
     * @param array<string,int> $dejaParJour  'Y-m-d' (jour civil de Paris) => inscriptions actives déjà prévues ce jour-là
     * @param list<string>      $cles         clés stables des destinataires (ex. "<campagne>|<contact>")
     * @return array<string, \DateTimeImmutable> clé => échéance ; les clés qui ne tiennent pas dans l'horizon sont ABSENTES
     */
    public static function repartir(\App\Envoi\Sequence\FenetreHoraire $fenetre, \DateTimeImmutable $maintenant,
        \DateTimeImmutable $auPlusTot, int $plafondJour, array $dejaParJour, array $cles, string $graine): array;

    /** Nombre de places libres sur l'horizon (pour régler la taille du lot AVANT de lire la cible). */
    public static function capacite(\App\Envoi\Sequence\FenetreHoraire $fenetre, \DateTimeImmutable $maintenant,
        \DateTimeImmutable $auPlusTot, int $plafondJour, array $dejaParJour): int;
}
```
**Règle :**
- Jours : à partir du jour civil de Paris de `max($maintenant, $auPlusTot)`, jours ouvrés seulement (`JoursOuvres::estOuvre`), pendant `HORIZON_JOURS_OUVRES` jours.
- Places du jour J : `P - dejaParJour[J]` (jamais négatif). `P = $plafondJour` ; si `P <= 0` : `\InvalidArgumentException` (l'appelant convertit).
- Créneau `k` (de `dejaParJour[J]` à `P-1`) : début de fenêtre + `(fin - debut) × (k + u) / P` heures, avec `u = ALEA_MIN + (ALEA_MAX - ALEA_MIN) × fraction(sha256($graine . '|' . $cle))`, à la seconde, en heure de Paris. La fenêtre est celle de `FenetreHoraire` (déjà rognée à 8 h – 19 h, `FenetreHoraire.php:26-35`). Résultat : écart irrégulier, jamais deux échéances identiques, jamais à l'ouverture pile.
- Aujourd'hui : un créneau calculé avant `maintenant + MARGE_MINUTES` est sauté (la place est perdue pour aujourd'hui, pas reportée sur le même jour).
- Déterministe : mêmes entrées → mêmes sorties (reprise idempotente). Aucune base, aucune horloge interne.

**Tests (rouges d'abord) :** 1. 50 clés, P = 20, lundi 7 h → 20 lundi, 20 mardi, 10 mercredi, toutes dans [8 h, 19 h[ (ou la fenêtre de la séquence), aucune à `HH:00:00` pile. 2. `dejaParJour` lundi = 18 → 2 le lundi seulement. 3. Vendredi 18 h 58 → rien le vendredi, rien le week-end, lundi d'abord ; un jour férié (1er mai) sauté. 4. Changement d'heure (dernier dimanche d'octobre) : le lundi suivant reste dans la fenêtre locale. 5. `auPlusTot` dans 3 jours (départ différé) respecté. 6. Écarts : sur 20 échéances d'un jour, les intervalles ne sont pas tous égaux et le plus petit dépasse `(fin-debut)/P × 0,2`. 7. Déterminisme : deux appels → mêmes valeurs ; autre graine → autres valeurs. 8. Horizon : 1 000 clés, P = 20 → 400 rendues, `capacite` = 400. 9. P = 0 → exception.

**Mutations :** `u` constant (0) → 1 et 6 ; marge du jour retirée → 3 ; `estOuvre` retiré → 3 ; `dejaParJour` ignoré → 2 ; horizon retiré → 8.

---

## 4. LOT L4-B — `MaterialisationCampagne`

**Préalables :** P2 déployée ; #603 (`SelectionDestinataires`) et #604 (`ConstructionSequence`) fusionnées ; L4-A fusionnée.

### 4.1 Fichiers
- Créer `backend/app/Envoi/Campagnes/MaterialisationCampagne.php`
- Créer `backend/app/Envoi/Campagnes/MaterialisationRefusee.php`
- Créer `backend/app/Envoi/Campagnes/ResultatMaterialisation.php`
- Créer `backend/tests/Feature/Envoi/MaterialisationCampagneTest.php` (préfixe `mat`)
- Créer `backend/tests/Support/materialisation-concurrente.php` (modèle : `lien-campagne-concurrent.php`, première instruction `require __DIR__ . '/garde_environnement_ouvrier.php'`, connexion `pgsql_app`)
- Modifier `backend/app/Envoi/Sequence/Inscriptions.php:48-49` : si l'identifiant relu est `null`, lever `InvalidArgumentException('Adresse déjà inscrite à une séquence active dans cet espace.')` au lieu de rendre `''` (+ un test dans `SequenceurTest` ou un nouveau fichier).
- Créer `docs/envoi/lots/X1-L4-B.md`

### 4.2 Signatures
```php
final class MaterialisationRefusee extends \RuntimeException {
    public function __construct(public readonly string $motif, public readonly bool $reessayable, string $message, ?\Throwable $prev = null) {}
    // motifs : reglage_non_applique, statut_non_materialisable, cible_non_supportee, population_non_ouverte, plafond_nul,
    //          horizon_plein (réessayables : concurrence, lecture_impossible, construction_a_reessayer) ; erreur_base (non)
}
final class ResultatMaterialisation {
    /** @param array<string,int> $compteurs */
    public function __construct(public readonly string $etat, public readonly int $inscrits, public readonly int $curseur,
                                public readonly array $compteurs, public readonly ?string $motif) {}
}
final class MaterialisationCampagne {
    public const TAILLE_LOT = 200;
    public const LOTS_PAR_APPEL = 5;
    public const ESSAIS_MAX = 3;
    public function __construct(private readonly DepotCampagnes $depot, private readonly SelectionDestinataires $selection,
        private readonly ConstructionSequence $construction, private readonly DepotSocle $socle) {}
    /** Hors de toute transaction (la sélection ouvre sa propre transaction REPEATABLE READ, READ ONLY). */
    public function materialiser(string $ws, string $campagneId, \DateTimeImmutable $maintenant): ResultatMaterialisation;
}
```

### 4.3 Déroulé de `materialiser`
0. `DB::transactionLevel() === 0` sinon `LogicException` (en test, l'aide `cnsHorsTransaction` de L3 sert de modèle).
1. **Lecture de la campagne** (contexte d'espace) : statut ∈ {`programmee`, `en_cours`} sinon `statut_non_materialisable` (rien écrit). Matérialisation déjà `terminee` ou `bloquee` → rendre son état, rien écrit (idempotent).
2. **Réglage non appliqué** : `boites <> '{}'` OU `saisie.contenu.plafond_jour` non nul → `MaterialisationRefusee('reglage_non_applique', false)`. **Rien n'est écrit** (ni séquence, ni ligne de matérialisation) : la campagne reste `programmee` et repartira quand le transport appliquera ces réglages (constat 12). Contrôle AVANT la construction, pour ne jamais relier une campagne qui ne partira pas.
3. **Construction (L3)** si `sequence_id` est NULL : `$this->construction->construire($ws, $id)`.
   - `ConstructionRefusee` avec `motifMaterialisation() !== null` (E8 : `jours_non_applicables`, `objet_b_non_applicable` ; et `etapes_invalides`, `sequence_incoherente`) → sous `sousVerrou` : `INSERT` de la matérialisation (naît `en_cours`, curseur 0) puis `UPDATE … SET etat='bloquee', motif=:m` → état `bloquee`. Terminal.
   - autre motif réessayable (`fenetre_annulation_ouverte`, `concurrence`) → `construction_a_reessayer` (réessayable, rien écrit) ; non réessayable → relancé tel quel, compté par la commande.
4. **Ligne de matérialisation** : si absente, `INSERT … (campagne_id, workspace_id, sequence_id) VALUES (…) ON CONFLICT (campagne_id) DO NOTHING` sous `sousVerrou`.
5. **Boucle**, au plus `LOTS_PAR_APPEL` fois :
   a. **Plafond du jour** `P = min((int) config('envoi.plafond_jour'), $socle->plafondEspace($ws), PasserelleLocale::PLAFOND_MAX)` (`config/envoi.php:21`, `DepotSocleDb.php:148-157`, `PasserelleLocale.php:54`). `P <= 0` → `plafond_nul` (réessayable, rien écrit).
   b. **Déjà prévu par jour** (toutes séquences, toutes étapes, prudent) :
      ```sql
      SELECT to_char(prochaine_echeance AT TIME ZONE 'Europe/Paris','YYYY-MM-DD') AS jour, count(*) AS n
      FROM envoi_inscriptions WHERE workspace_id = :ws AND statut = 'active'
        AND prochaine_echeance >= :debut AND prochaine_echeance < :fin_horizon GROUP BY 1
      ```
      (index `idx_envoi_inscriptions_echeance`, `000031:41`). `auPlusTot` = `max(maintenant, saisie.contenu.debut_le)` (`AssistantCreation.php:192`), puis `JoursOuvres::ajouter(…, délai de l'étape 1)`.
   c. `capacite = EtalementEcheances::capacite(...)` ; 0 → `horizon_plein` (réessayable, on s'arrête là ce tour-ci).
   d. **Sélection (L2), HORS transaction d'écriture :** `$lot = $this->selection->lot($ws, $id, 'entreprises', $curseurLu, min(TAILLE_LOT, $capacite))`. `SelectionRefusee` : `cible_introuvable` → `bloquee` (motif autorisé) ; `cible_non_supportee` / `population_non_ouverte` → refus du même nom, **rien écrit** (motifs absents de la liste fermée) ; réessayables → `lecture_impossible`.
   e. **Écriture**, `ConstructionSequence::relancer`-like (même règle : `40P01`/`40001` hors transaction → nouvel essai 20-120 ms, au plus `ESSAIS_MAX` ; `55P03`/`57014` → `concurrence` ; jamais de `QueryException` brute), dans `$this->depot->sousVerrou($ws, $id, …)` :
      1. `SET LOCAL lock_timeout = '5s'`, `SET LOCAL statement_timeout = '15000'` ;
      2. verrou consultatif `envoi_etalement:<ws>` (TOUJOURS après les deux verrous de `sousVerrou` ; L4-C prend ce seul verrou : pas d'interblocage) ;
      3. `SELECT statut, sequence_id FROM envoi_campagnes WHERE workspace_id=? AND id=? FOR SHARE` : statut toujours permis, même `sequence_id` ;
      4. `SELECT etat, curseur, compteurs FROM envoi_campagnes_materialisations WHERE workspace_id=? AND campagne_id=? FOR UPDATE` : `etat = 'en_cours'` ET `curseur = $curseurLu`, sinon `concurrence` (un autre a avancé : on ne réécrit rien) ;
      5. relire « déjà prévu par jour » (sous le verrou d'étalement) et calculer `EtalementEcheances::repartir(..., graine: $id)` avec la clé `"<campagne>|<contact>"` ;
      6. version figée : lue UNE fois (séquence + étapes), **au même format exact** que `Inscriptions.php:30-36` ;
      7. une seule requête :
         ```sql
         INSERT INTO envoi_inscriptions (workspace_id, sequence_id, population, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
         SELECT :ws, :seq, 'entreprises', x.contact_id, x.entreprise_id, x.empreinte, x.echeance, :version::jsonb
         FROM jsonb_to_recordset(:lignes::jsonb) AS x(contact_id bigint, entreprise_id bigint, empreinte char(64), echeance timestamptz)
         ON CONFLICT DO NOTHING
         RETURNING contact_id
         ```
         `ON CONFLICT DO NOTHING` SANS cible couvre les deux unicités (`(ws, séquence, contact)` et l'index partiel « adresse active » de P2). Les destinataires sans échéance (hors horizon) ne sont PAS insérés et le curseur n'avance PAS au-delà d'eux (on a réglé la taille du lot sur la capacité, donc ce cas ne doit pas arriver ; s'il arrive : `concurrence`, rien écrit).
      8. compteurs = anciens + ceux du lot (toutes les clés de L2 : `MOTIFS`, `membres`, `retenus`, `sans_contact_generique_disponible`) + `inscrits` + `ecartes_insertion` (= retenus − insérés : course avec une autre campagne). Invariants testés : `membres = retenus + Σ MOTIFS` et `retenus = inscrits + ecartes_insertion`. Taille JSON < 2 048 octets (`000020:26-29`).
      9. `UPDATE envoi_campagnes_materialisations SET curseur = :nouveau, compteurs = :c, maj_le = now() [, etat='terminee', terminee_le = now()] WHERE workspace_id=? AND campagne_id=? AND curseur = :curseurLu AND etat='en_cours'` ; `curseurSuivant === null` → `terminee` (le curseur garde sa dernière valeur : il ne décroît jamais) ; 0 ligne → `concurrence` (tout le lot est annulé : même transaction).
   f. Si `terminee` : sortir.
6. Rendre `ResultatMaterialisation`.

**Ce que L4-B ne fait PAS :** aucun passage à `en_cours`, aucun geste `reprise` (L5), aucun envoi, aucune ligne `envoi_messages`. La séquence est née en pause (`2026_12_04_000010…:68-74`) et sa reprise exige campagne `en_cours` ET matérialisation `terminee` (`2026_12_04_000030…:125`). Les inscriptions écrites sont donc doublement inertes.

### 4.4 Tests L4-B (rouges d'abord ; modes `['app','proprio']`)
Aide `matProgrammee(...)` = aide `cnsProgrammee` de L3 (campagne SANS boîte, sans `plafond_jour`), fiches comme `CreationOpposeesTest.php`.
1. **Nominal** : 5 entreprises, P = 20, lundi 7 h : 5 inscriptions `entreprises`, `version_figee` identique à celle que produit `Inscriptions::inscrire` pour le même contact, échéances lundi entre 8 h et 17 h et jamais `HH:00:00` ; matérialisation `terminee`, `sequence_id` posé, compteurs exacts (invariants).
2. **Idempotence** : second appel → aucun changement (comptes, échéances, compteurs).
3. **Reprise par curseur** : `TAILLE_LOT` réduit (constante surchargée par une sous-classe de test ou paramètre interne) et `LOTS_PAR_APPEL = 1` : trois appels → curseurs croissants, même résultat final que d'un trait.
4. **Plafond par jour** : 45 destinataires, P = 20, `dejaParJour` lundi = 5 (inscription d'une autre campagne) → 15 lundi, 20 mardi, 10 mercredi.
5. **Horizon** : P = 1 et 30 destinataires → 20 inscrits, `en_cours`, curseur arrêté au bon endroit ; le jour suivant (horloge +1 jour ouvré) → un de plus.
6. **E8** : jours `[lun..sam]` → matérialisation `bloquee` / `jours_non_applicables`, aucune inscription ; `objet_b` → `objet_b_non_applicable`.
7. **Réglage non appliqué** : boîtes choisies, ou `plafond_jour` saisi → `reglage_non_applique`, AUCUNE ligne (ni séquence, ni matérialisation, ni inscription).
8. **Statut** brouillon / `a_valider` / `arretee` / `terminee` → `statut_non_materialisable`, rien écrit.
9. **Cible introuvable** (liste mise à la corbeille après validation) → `bloquee` / `cible_introuvable`.
10. **Une adresse active ailleurs** : même adresse déjà inscrite (autre campagne, `active`) → comptée par L2 en `deja_en_sequence` ; course simulée (inscription posée entre la sélection et l'écriture, via un lecteur de sélection injecté) → `ecartes_insertion = 1`, pas d'erreur.
11. **Concurrence en processus réels** (`materialisation-concurrente.php`) : trois `materialiser` simultanés sur la même campagne → chaque contact inscrit une seule fois, compteurs justes, curseur jamais décroissant ; perdants : `concurrence`.
12. **Pas d'envoi** : après matérialisation, `Sequenceur::executer` (faux serveur) → 0 appel (séquence en pause), `envoi_messages` vide.
13. **`Inscriptions::inscrire`** d'une adresse déjà active ailleurs → `InvalidArgumentException` (plus de `''`).
14. **Jamais de `QueryException` brute** : fermeture levant `40P01` deux fois puis réussite → résultat ; trois fois → `concurrence`.

**Mutations :** contrôle de réglage retiré → 7 ; `curseur = :curseurLu` retiré → 11 ; `ON CONFLICT DO NOTHING` retiré → 10 ; étalement remplacé par `FenetreHoraire::echeance` → 1, 4 ; `dejaParJour` non relu sous verrou → 4 (ou 11) ; passage `terminee` dès le premier lot → 3 ; E8 non converti en `bloquee` → 6.

### 4.5 À ne pas toucher (L4-B)
`Sequenceur.php`, `Fiche.php`, `ComposeurSequenceMessage.php`, `LecteurFicheDb.php`, `AssistantCreation.php`, `StatutOpposition.php` et `backend/app/Crm/Opposition/**`, `DepotCampagnes.php` (L3 seulement), `SelectionDestinataires.php` et `ConstructionSequence.php` (L2/L3 : s'il faut les changer, ESCALADE à l'architecte), `EnvoiServiceProvider.php` (classes `final` résolues automatiquement ; `DepotSocle` est déjà lié), les migrations, `config/envoi.php`, les drapeaux, les routes et l'instantané `nr20`.

---

## 5. LOT L4-C — commande planifiée et re-étalement du stock

### 5.1 Fichiers
- Créer `backend/app/Console/Commands/EnvoiCampagnesMaterialiser.php` (modèle `EnvoiCycle.php`, constante `SIGNATURE_PLANIFIEE = 'envoi:campagnes:materialiser'`)
- Créer `backend/app/Envoi/Campagnes/ReetalementStock.php`
- Créer `backend/routes/console/envoi-campagnes.php` (si le lot préparatoire V0 l'a déjà créé vide : le remplir)
- Modifier `backend/routes/console.php` : UNE ligne après `:615`, `require __DIR__ . '/console/envoi-campagnes.php';` (fichier chaud : rien d'autre)
- Modifier les instantanés `tests/Feature/NonRegression/instantanes/nr07-taches-planifiees.json` et `nr08-taches-sous-rls.json` (une entrée chacun, « Filet modifié : pourquoi »)
- Créer `backend/tests/Feature/Envoi/MaterialisationCommandeTest.php` et `ReetalementStockTest.php`
- Créer `docs/envoi/lots/X1-L4-C.md`

### 5.2 Planification (`routes/console/envoi-campagnes.php`)
```php
Schedule::command(EnvoiCampagnesMaterialiser::SIGNATURE_PLANIFIEE)
    ->everyMinute()->withoutOverlapping(10)->onOneServer()
    ->when(fn (): bool => EnvoiCampagnesMaterialiser::schemaPret())
    ->onFailure(fn () => Log::critical('[ENVOI] envoi:campagnes:materialiser : au moins une campagne en erreur.'));
```
**PAS** de `when(porte ouverte)` : la matérialisation et le re-étalement sont inertes et doivent tourner porte fermée (sinon le stock arriverait en bloc à l'ouverture).
`schemaPret()` (statique, aussi appelée au début de `handle`) : `Schema::hasTable('envoi_campagnes_materialisations') && Schema::hasColumn('envoi_inscriptions','population')` ET index `envoi_inscriptions_adresse_active_unique` présent et `indisvalid`. Faux → `handle` écrit « schéma P2 absent : rien à faire » et rend `SUCCESS` (jamais d'exception SQL pendant la fenêtre `:443`→`:561`).

### 5.3 `handle`
- Bornes : `BORNE_SECONDES = 50` (aucun nouvel espace ni campagne commencé au-delà), `CAMPAGNES_PAR_TOUR = 20`.
- Espaces : `workspaces` hors corbeille, triés (modèle `ChefOrchestre.php:76`) ; chaque espace sous `WorkspaceContext::run`.
- Par espace : (1) `ReetalementStock::reetaler($ws, $maintenant)` ; (2) campagnes `statut IN ('programmee','en_cours')` sans matérialisation terminale, triées par `id`, `materialiser()` pour chacune.
- `MaterialisationRefusee` réessayable ou `reglage_non_applique` : compté, pas d'erreur. Autre exception : comptée en erreur, journal **sans adresse** (identifiants de campagne seulement), l'espace suivant continue. Code de sortie `FAILURE` s'il y a au moins une erreur.
- Sortie : compteurs seulement (`espaces`, `campagnes`, `inscrits`, `bloquees`, `reglage_non_applique`, `reetalees`, `erreurs`, `hors_borne`).

### 5.4 `ReetalementStock` — le jour où la porte s'ouvre sur un stock
```php
final class ReetalementStock {
    public const SEUIL_PORTE_OUVERTE_MINUTES = 30;
    public const LIMITE = 500;
    public function __construct(private readonly PorteEnvoiReelle $porte, private readonly DepotSocle $socle) {}
    public function reetaler(string $ws, \DateTimeImmutable $maintenant): int; // nombre d'échéances déplacées
}
```
Candidates (une transaction, verrou consultatif `envoi_etalement:<ws>`, `lock_timeout = '5s'`) :
```sql
SELECT i.id, i.sequence_id, i.contact_id, i.version_figee->'fenetre' AS fenetre
FROM envoi_inscriptions i
JOIN envoi_sequences s ON s.workspace_id = i.workspace_id AND s.id = i.sequence_id AND s.campagne_id IS NOT NULL
WHERE i.workspace_id = :ws AND i.statut = 'active' AND i.etape_courante = 1 AND i.reports = 0 AND i.echecs = 0
  AND i.prochaine_echeance < :seuil
  AND NOT EXISTS (SELECT 1 FROM envoi_messages m WHERE m.workspace_id = i.workspace_id AND m.inscription_id = i.id)
ORDER BY i.prochaine_echeance, i.id
LIMIT 500
FOR UPDATE OF i SKIP LOCKED
```
- `:seuil` = `maintenant` si la porte est FERMÉE ; `maintenant − 30 min` si elle est ouverte (on ne vole jamais au séquenceur une inscription qui vient d'échoir).
- Seulement les inscriptions nées d'une campagne, jamais touchées (aucun message, aucun report, aucun échec). Les séquences libres et l'essai à blanc ne sont jamais déplacés.
- Nouvelles échéances par `EtalementEcheances::repartir` (même plafond `P`, même `dejaParJour`, graine = identifiant de séquence, clé = identifiant d'inscription), puis `UPDATE envoi_inscriptions SET prochaine_echeance = … WHERE id = … AND statut = 'active'` (seule colonne écrite ; `version_figee` jamais touchée, déclencheur `000031:45-55`). Celles qui ne tiennent pas dans l'horizon : déplacées au premier jour ouvré APRÈS l'horizon, même heure irrégulière (elles seront reprises).
- **Course avec le séquenceur** : il verrouille l'inscription (`Sequenceur.php:259`) et ignore une échéance future (`:260`). `SKIP LOCKED` : on ne l'attend jamais. Résultat : porte fermée, aucune échéance ne reste dans le passé ; le jour où la porte s'ouvre, seules les échéances des dernières minutes sont dues (au plus quelques-unes), le reste est déjà étalé jour par jour sous le plafond.

### 5.5 Tests L4-C (rouges d'abord)
1. `schemaPret()` faux (colonne absente simulée par un double, ou index invalide) → 0 requête d'écriture, sortie « schéma P2 absent », `SUCCESS`.
2. NR07 : la tâche existe, `* * * * *`, verrou 10, un seul serveur, **une** condition ; aucune condition de porte.
3. Bornes : horloge injectée qui dépasse 50 s → `hors_borne` > 0, aucune campagne commencée après.
4. Une campagne en erreur n'empêche pas la suivante ni l'autre espace ; code `FAILURE` ; le journal ne contient aucune adresse ni empreinte.
5. **Stock, porte fermée** : 60 inscriptions échues depuis 3 jours (P = 20) → après `reetaler` : aucune dans le passé, ≤ 20 par jour, heures irrégulières ; une inscription avec un `envoi_messages` ou `reports > 0` n'a PAS bougé ; une séquence libre n'a PAS bougé.
6. **Ouverture de la porte** (`config(['envoi.reel_actif' => true])` en mémoire de test seulement, et liaisons de `RetraitEnvoisEnFile` / `ArretParEntreprise` remplacées dans le conteneur DU TEST) : échues depuis 10 min → non déplacées ; depuis 40 min → déplacées.
7. Ligne verrouillée par une autre connexion → sautée (`SKIP LOCKED`), pas d'attente.
8. **Rien ne part** (voir § 6) : commande jouée, puis `envoi:cycle` → `porte fermée : rien à faire`, 0 `envoi_messages`.

**Mutations :** `when(schemaPret)` retiré → 1 (avec un schéma simulé absent) ; `NOT EXISTS envoi_messages` retiré → 5 ; filtre `campagne_id IS NOT NULL` retiré → 5 ; seuil porte ouverte = `maintenant` → 6 ; `SKIP LOCKED` retiré → 7 (`lock_timeout`).

### 5.6 À ne pas toucher (L4-C)
`routes/console.php` hors de la ligne `require` ; `routes/console/envoi-orchestration.php` ; `EnvoiServiceProvider.php` (la commande et `ReetalementStock` se résolvent seuls) ; `Sequenceur.php`, `ChefOrchestre.php`, `PasserelleLocale.php` ; `SemeurTablesScopees.php` (aucune table nouvelle) ; `nr20-routes.json` (aucune route) ; `config/envoi.php`, `.env*`, tous les drapeaux.

---

## 6. Conditions d'activation : rien ne part tant que `ENVOI_REEL_ACTIF` est faux

Vérifié dans le code. Chaque verrou suffit seul ; aucun n'est levé par ces lots.
1. **`ENVOI_REEL_ACTIF` faux** : `config/envoi.php:19` ; `PorteEnvoiReelle::ouverte()` le lit en premier (`PorteEnvoiReelle.php:21`).
2. **Porte fermée même si le drapeau était vrai** : `RetraitEnvoisEnFile` est lié à `RetraitEnvoisEnFileAbsent` (`EnvoiServiceProvider.php:166`) et la porte exige qu'il ne le soit pas (`PorteEnvoiReelle.php:22`). `ArretParEntreprise` est déjà réel (`:167`).
3. **Le cycle ne tourne pas** : tâche non due porte fermée (`routes/console/envoi-orchestration.php:14`) ; et `ChefOrchestre::cycle` sort aussitôt (`ChefOrchestre.php:65-67`).
4. **La passerelle refuse** : même prédicat (`EnvoiServiceProvider.php:126-130`), « zéro appel au serveur, zéro accès base » (`PasserelleLocale.php:32`) ; le client du serveur refuse aussi (`ClientServeurEnvoiHmac.php:170`).
5. **Mode témoins** allumé par défaut (`2026_11_10_000010…:19`) : seules les adresses témoins passeraient.
6. **Feu vert** : un espace n'est traité qu'avec une dernière décision « accorde » et aucun point rouge (`ChefOrchestre.php:26-27`) ; `auto.reel_actif_faux` est listé (`ListeFeuVert.php:55`).
7. **Séquence née en pause** (`2026_12_04_000010…:68-74`) ; reprise seulement si campagne `en_cours` ET matérialisation `terminee` (`2026_12_04_000030…:125`) : L5, pas ces lots. Le séquenceur saute une séquence en pause (`Sequenceur.php:103`, `:128-131`).
8. **Verdict d'adresse exigé** par la porte d'envoi (`Gardes/EnvoiAutorise.php:260-281`, lot « verdicts » à concevoir).
9. **Personnes** : aucune ligne possible tant que l'interrupteur de l'espace n'est pas posé par le propriétaire (P2) ; aucune commande ne le pose.

Test à ajouter dans L4-C (§ 5.5, test 8) et à garder vert jusqu'à I1.

---

## 7. Fichiers chauds (une seule PR à la fois)

| Fichier | P2-A | P2-B | L4-A | L4-B | L4-C |
|---|---|---|---|---|---|
| `EnvoiServiceProvider.php` | non | non | non | non | non |
| `routes/console.php` | non | non | non | non | 1 ligne |
| instantanés `nr07` / `nr08` | non | non | non | non | 1 entrée chacun |
| instantané `nr20` | non | non | non | non | non |
| `SemeurTablesScopees.php` | non | non | non | non | non |
| `Nr17MigrationsTest.php` | 3 lignes | 1 ligne | non | non | non |
| `SequenceurTest.php` | non | 1 test adapté + 1 ajouté | non | 1 test ajouté | non |
| `Inscriptions.php` | non | non | non | lignes 48-49 | non |
| `Sequenceur.php`, `Fiche.php`, `ComposeurSequenceMessage.php`, `AssistantCreation.php`, `StatutOpposition.php` | non | non | non | non | non |

## 8. Rôles : chaque test de sécurité deux fois
Rôle applicatif `pgsql_app` (`SET LOCAL ROLE` + `WorkspaceContext`) ET propriétaire, comme `SchemaLienCampagneSequenceTest.php:77-95`. Les scripts de concurrence se connectent en `pgsql_app` ; données semées et nettoyées par `pgsql_owner` dans un `try/finally` (et `session_replication_role` lui-même dans un `try/finally`).

## 9. ESCALADE (décisions du fondateur ; rien ne bloque les lots d'ici là)
1. **D1 / D7 / D8 (juridique)** : le CRM écrit-il lui-même aux personnes sans entreprise, et avec quelle preuve d'accord ? Tant que ce n'est pas tranché, l'interrupteur `envoi.population_personnes` reste absent (= éteint) et aucune commande ne le pose.
2. **Doublons en production** (seulement si la migration `000180` s'arrête) : quelles inscriptions actives en double arrêter. Aucune correction automatique.
3. **E7, rappel** : inclure ou non les adresses génériques (contact@, info@) en B2B ; L4 garde le choix fermé de L2 (`sans_contact_generique_disponible` compté et montré).
4. **Plafond d'étalement** : L4 étale sur `min(ENVOI_PLAFOND_JOUR, plafond de l'espace, 300)` (aujourd'hui 20 par défaut, `config/envoi.php:21`) et 20 jours ouvrés d'avance. Ce sont des valeurs prudentes ; le fondateur pourra les changer par les réglages existants, sans nouveau lot.

## 10. Hors périmètre (écrit pour éviter les malentendus)
- Population « personnes » de bout en bout (P3 à P7), dont `envoi_inscriptions_evenements.entreprise_id NOT NULL` (constat 5) et l'effacement RGPD d'une personne inscrite.
- Passage `programmee → en_cours` et geste `reprise` (L5) ; compteurs à l'écran (L6) ; écran « bloquée, pourquoi » (B8).
- Report du séquenceur qui garde l'heure du cycle (`Sequenceur.php:614-620`) : au-delà du stock initial, un report de masse par la passerelle se regroupe encore ; à traiter dans le lot qui touchera `Sequenceur.php` (C1), pas ici.
