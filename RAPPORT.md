# Rapport — lot O14 : IDCC et OPCO des entreprises du CRM

Branche à relire : `opco/crm-idcc-opco` (depuis `origin/main` à jour du 03/10/2026, `1604a35`).
Tête : `4110cd8bf08d2b360185ca4ec3d25d9040b940af`. **Rien n'est fusionné** : la relecture et la fusion reviennent à la session qui tient le CRM.

## Ce qui est fait

1. **Table dédiée `companies_opco`** (migration `2026_10_05_000010`, la dernière du dépôt). `companies` n'est ni modifiée ni réécrite.
   - Colonnes demandées : `workspace_id`, `company_id` (FK), `siret CHAR(14)`, `idcc CHAR(4) CHECK ^[0-9]{4}$`, `opco` et `opco_gestion` (liste fermée des 11 OPCO), `source IN ('siro','saisie')`, `releve_le DATE`, `created_at`, `updated_at`, `UNIQUE (workspace_id, company_id)`.
   - RLS ENABLE + FORCE, politique stricte par espace, sur le patron de `2026_10_03_000080_provenance_tiers` (la migration récente la plus proche qui crée une table sous RLS). Un déclencheur vérifie que l'entreprise est du même espace.
   - FK en `ON DELETE RESTRICT` ; `axion_app` : `GRANT SELECT, INSERT, UPDATE` et `REVOKE DELETE, TRUNCATE`. Le `down()` refuse de jeter des lignes.
   - Une **seconde table** `companies_opco_passages` (journal des passages : ressource, mois de DSN, curseur, bilan chiffré, statut). Il faut bien un endroit pour garder le curseur de reprise ; même patron que `insee_mises_a_jour`, mêmes droits, même RLS.
2. **Commande `crm:enrichir-opco`** (`CrmEnrichirOpco` + `App\Crm\Opco\EnrichissementOpco`), sur le patron de `CrmInseeMiseAJourMensuelle` et de #320 :
   - refuse de partir hors du mardi→samedi, 08:00-19:00 heure de Paris, et les 1er, 2 et 3 du mois (`FenetreOpco`, message clair) ;
   - **pas planifiée** (aucune entrée dans `routes/console.php`, un test le vérifie) ;
   - `--dry-run` (bilan chiffré, aucune écriture, pas même le journal), `--limite=N`, `--workspace=`, `--releve-le=AAAA-MM` ;
   - curseur = numéro de la dernière ligne traitée, écrit **dans la même transaction** que le paquet ; reprise automatique sur la même ressource ; un passage inachevé sur une ressource remplacée est clos (`echouee`), jamais effacé ;
   - mémoire constante : téléchargement en flux (`sink`) vers `sys_get_temp_dir()`, lecture `fgetcsv` ligne par ligne, un seul paquet de 1000 à la fois, journal des requêtes coupé, fichier supprimé dans un `finally` ;
   - ressource résolue par `https://www.data.gouv.fr/api/1/datasets/table-siret-opco/` : la dernière ressource CSV en https (les ressources principales passent en premier). Aucun identifiant figé. Le mois de DSN est lu dans le titre, la description ou le nom du fichier ;
   - téléchargement soumis à la garde SSRF (`SsrfGuard` : port 443, IP épinglée, redirections revérifiées, 1 Go au plus) ;
   - jointure `SIRET du fichier = companies.siret`, en passant par l'index unique `(workspace_id, siren)` (le SIREN est le préfixe du SIRET) puis en comparant le SIRET exact. Fiches `deleted_at` exclues ;
   - `INSERT … ON CONFLICT (workspace_id, company_id) DO UPDATE … WHERE companies_opco.source = 'siro'` : une ligne `saisie` n'est jamais écrasée, et la base le revérifie elle-même. Une ligne identique n'est pas réécrite, donc un second passage n'écrit rien ;
   - séparateur détecté sur l'en-tête (`|`, `;`, `,`, tabulation), colonnes repérées par leur nom (sans tenir compte de la casse, du BOM, des guillemets ni des accents) ;
   - rejets comptés par motif : SIRET malformé, IDCC vide, IDCC malformé, OPCO propriétaire inconnu, OPCO de gestion inconnu, ligne au mauvais nombre de colonnes. Les zéros de tête d'un IDCC sont rétablis (`16` → `0016`) ;
   - traduction fermée des libellés SIRO vers l'enum (`Opco::LIBELLES_SIRO`). `OPCO_PROPRIETAIRE` va dans `opco`, `OPCO_GESTION` dans `opco_gestion` ;
   - bilan final : lues, rapprochées, non rapprochées, doublons dans un paquet, écrites (« à écrire » en dry-run), inchangées, ignorées pour saisie, rejetées par motif, curseur.
3. **Fiche entreprise en lecture seule** : `GET /api/v1/companies/{id}` porte un champ `opco` (`null` si rien n'est connu) avec `idcc`, `opco`, `opco_libelle`, `opco_gestion`, `opco_gestion_libelle`, `source`, `releve_le` et `mention` (« source : France compétences (DSN de juillet 2026) »). La console affiche IDCC, OPCO (et l'OPCO de gestion s'il est différent) ainsi que la mention. Le seul changement d'API est l'ajout de ce champ. `EligibiliteAdresse`, `ResolveurDestinataires` et `CrmCampagneDestinataires` ne sont **pas touchés**.
4. **RGPD** : il n'existe dans le dépôt ni registre exécutable (`data_processing_log` n'est seedé nulle part) ni texte d'information art. 14 propre au CRM. Le registre vit dans `spec/17_rgpd_aiact_owasp.md` §1 : j'y ai ajouté le « Traitement 4 », avec la source France compétences (table SIRO), la finalité « orienter le financement de la formation », le SIRET d'entreprise individuelle signalé comme donnée personnelle, la conservation et les droits. J'ai aussi ajouté une mention art. 14 prête à reprendre dans le texte d'information.
5. **Tests** (`tests/Feature/Crm/EnrichirOpcoTest.php`, 18 tests dont un jeu de 4 cas, avec des SIRET **fictifs** : SIREN 94xxxxxxx et SIRET dont la clé de Luhn est volontairement fausse) :
   - fenêtre horaire : refus le lundi, le dimanche, le 2 du mois et à 20:00 ; bornes 08:00 et 19:00 ; lecture UTC → heure de Paris ;
   - aucune planification ;
   - dry-run sans écriture, avec suppression du fichier ;
   - écriture correcte et `companies` intacte ; idempotence (même état, second passage à 0 écriture) ;
   - une saisie n'est jamais écrasée ; une ligne siro est mise à jour ;
   - rejets comptés (libellé inconnu, IDCC vide ou malformé, SIRET malformé, OPCO de gestion inconnu) ; traduction fermée ;
   - `--limite` puis reprise par le curseur (bilan cumulé) ;
   - erreur en cours de passage : statut `echouee`, fichier supprimé ; en-tête invalide ;
   - séparateur `;` et BOM ; choix de la ressource et mois de DSN ; `--releve-le` ;
   - mention de source ; exposition par l'API.
   - Côté console, `CompanyDetailPage.test.tsx` gagne 2 tests.
   - Mis à jour : `SemeurTablesScopees` (les deux nouvelles tables sont semées pour le contrôle d'étanchéité) et `PortabiliteCompleteTest` (colonnes JSONB 44 → 45, hors de portée 30 → 31 : `companies_opco_passages.bilan` ne contient que des compteurs).

## Fichiers

- `backend/database/migrations/2026_10_05_000010_create_companies_opco_table.php`
- `backend/app/Console/Commands/CrmEnrichirOpco.php`
- `backend/app/Crm/Opco/{Opco,FenetreOpco,SourceSiro,EnrichissementOpco,LectureOpco}.php`
- `backend/app/Http/Controllers/Api/CompaniesController.php` (méthode `show` : + 3 lignes)
- `backend/tests/Feature/Crm/EnrichirOpcoTest.php`, `backend/tests/Support/SemeurTablesScopees.php`, `backend/tests/Feature/Rgpd/PortabiliteCompleteTest.php`
- `frontend/src/features/companies/CompanyDetailPage.tsx`, `frontend/tests/screens/CompanyDetailPage.test.tsx`
- `spec/17_rgpd_aiact_owasp.md`

## Vérifications faites / non faites

- `php -l` sur chaque fichier PHP : aucune erreur de syntaxe. Les fonctions pures (`separateur`, `colonnes`, `moisDsn`, `depuisLibelleSiro`, `fgetcsv` sur ligne vide) ont été exécutées à la main.
- **Non lancés**, comme demandé : `composer install`, Pest, Pint, PHPStan, `pnpm typecheck/lint/test`. C'est la CI de la PR qui jugera.

## Limites (à lire avant fusion)

1. **Format réel du fichier non vérifié** : data.gouv.fr est bloqué par le proxy de cette session (403). Le séparateur et l'en-tête sont donc détectés à l'exécution plutôt que figés. Si l'en-tête ne contient pas `SIRET`, `IDCC` et `OPCO_PROPRIETAIRE`, la commande échoue proprement en listant les colonnes lues. Si le fichier est compressé (zip/gz), il faudra ajouter la décompression.
2. **Libellés SIRO** : la table `LIBELLES_SIRO` couvre les noms connus des 11 OPCO, plus quelques variantes (« OPCO Cohésion sociale » → `uniformation`, « L'OPCOMMERCE »…). Un libellé réel absent est rejeté et compté (`OPCO inconnu`) : **lancer d'abord `--dry-run`**, puis compléter la table si ce compteur n'est pas nul.
3. **Mois de DSN** : il est déduit du titre, de la description ou du nom de fichier. S'il est introuvable, la commande le signale et `--releve-le=AAAA-MM` l'impose.
4. **Jointure** : seules les fiches dont `companies.siret` est renseigné et égal au SIRET du fichier sont rapprochées. Une fiche sans SIRET, ou dont le SIRET n'est pas celui que publie France compétences, n'est pas enrichie. Aucun index n'a été créé sur `companies.siret` (disque) : la recherche passe par `(workspace_id, siren)`, une requête indexée par paquet de 1000 lignes, soit environ 3 600 requêtes pour le fichier entier.
5. **Doublons** : un SIRET répété dans le même paquet est compté (`doublons`) et c'est la dernière ligne qui l'emporte. Entre deux paquets, il est réécrit à l'identique, ou par la dernière valeur.
6. **Pas de saisie dans l'interface** : l'affichage est en lecture seule, comme demandé. La source `saisie` existe dans le schéma et est respectée, mais aucun formulaire ne l'écrit encore.
7. Le même espace est traité en un seul passage, séquentiellement (rien en parallèle), sur l'espace de prospection par défaut ou celui de `--workspace`.
8. Relancer un jour le `GRANT … ON ALL TABLES` de `2026_08_14_000001` rendrait DELETE/TRUNCATE à `axion_app` sur les deux tables. C'est rappelé dans le commentaire SQL, comme pour `contacts_provenances_tiers`.

## Mise en production conseillée

```
php artisan migrate
php artisan crm:enrichir-opco --dry-run          # lire le bilan : OPCO inconnu, mois de DSN
php artisan crm:enrichir-opco --limite=500000    # puis relancer sans option : reprise
```

---

## Corps de PR prêt à coller

**Titre :** `O14 — IDCC et OPCO des entreprises (table SIRET → OPCO de France compétences)`

```markdown
## Résumé

Lot O14 du chantier OPCO : connaître la convention collective (IDCC) et l'OPCO de chaque entreprise du CRM d'après la table officielle SIRET → OPCO de France compétences (data.gouv.fr, licence ouverte 2.0).

- **Table dédiée `companies_opco`** (migration `2026_10_05_000010`) : `companies` n'est pas touchée. RLS ENABLE + FORCE par espace, FK en RESTRICT, `axion_app` sans DELETE ni TRUNCATE. Journal des passages et curseur dans `companies_opco_passages`.
- **`php artisan crm:enrichir-opco`** : fenêtre mardi→samedi 08:00-19:00 Paris (jamais les 1er/2/3), **non planifiée**, `--dry-run`, `--limite`, reprise par curseur. Mémoire constante : téléchargement en flux, `fgetcsv`, paquets de 1000, fichier supprimé même en erreur. Ressource résolue par l'API data.gouv, mois de DSN noté. Upsert `ON CONFLICT … WHERE source = 'siro'` : une **saisie n'est jamais écrasée**. Rejets comptés par motif.
- **Fiche entreprise** : IDCC et OPCO en lecture seule, avec « source : France compétences (DSN de <mois>) » (`GET /companies/{id}` → champ `opco`).
- **RGPD** : registre art. 30 (traitement 4) et mention art. 14 dans `spec/17_rgpd_aiact_owasp.md`. Le SIRET d'une entreprise individuelle y est signalé comme donnée personnelle.
- `EligibiliteAdresse`, `ResolveurDestinataires` et `CrmCampagneDestinataires` ne sont pas modifiés.

## Tests

- `tests/Feature/Crm/EnrichirOpcoTest.php` (SIRET fictifs, clé de Luhn fausse) : fenêtre (lundi, dimanche, le 2, 20:00), dry-run, idempotence, saisie jamais écrasée, rejets comptés, reprise par curseur, fichier supprimé, ressource data.gouv, API.
- `CompanyDetailPage.test.tsx` : affichage IDCC/OPCO et mention de source.
- Semeur d'étanchéité et compte des colonnes JSONB mis à jour.

## Limites

- Format réel du CSV non vérifié (data.gouv inaccessible depuis la session de préparation) : séparateur et colonnes détectés à l'exécution. **Lancer `--dry-run` d'abord** et regarder les compteurs « OPCO inconnu » et le mois de DSN.
- Rapprochement par `companies.siret` exact seulement ; aucun index ajouté sur `companies`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01N6DF9gfuvzUTkoGHWRjkd5
```
