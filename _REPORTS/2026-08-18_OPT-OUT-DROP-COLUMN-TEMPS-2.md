# `opt_out` / `email_suppressions` — la migration de suppression à écrire (temps 2)

> **Date** : 2026-08-18 · **Contexte** : décision 3 de
> `_REPORTS/2026-08-18_ARBITRAGES-PREALABLES-SECTION-4.md`, ligne 10 du §3.
> **Statut** : le temps 1 est livré dans cette PR. Le temps 2 **n'est pas écrit**,
> et il ne doit pas l'être avant que sa condition d'entrée soit constatée.

---

## Ce que le temps 1 a fait (dans cette PR)

- Migration `2026_08_18_000001_backfill_empreintes_oppositions.php` : calcule
  `email_hash` pour toute ligne d'`opt_out` et d'`email_suppressions` qui porte
  une adresse sans empreinte, **en PHP** via `ListeSuppression::empreinte()`.
- La même migration pose sur chaque table la contrainte
  `<table>_empreinte_obligatoire_check` : `email IS NULL OR email_hash IS NOT NULL`.
  Une opposition ne peut donc **plus naître** sous la seule forme lisible.
- Plus aucun point d'écriture ne consigne l'adresse en clair.
- Plus aucun point de lecture ne l'interroge.

## Ce que le temps 2 doit faire

Une migration **séparée**, dans un **déploiement séparé** :

```php
DB::statement('ALTER TABLE opt_out DROP CONSTRAINT IF EXISTS opt_out_empreinte_obligatoire_check');
DB::statement('DROP INDEX IF EXISTS idx_opt_out_email');
DB::statement('ALTER TABLE opt_out DROP COLUMN email');

DB::statement('ALTER TABLE email_suppressions DROP CONSTRAINT IF EXISTS email_suppressions_empreinte_obligatoire_check');
DB::statement('ALTER TABLE email_suppressions DROP CONSTRAINT IF EXISTS email_suppressions_identifiable_check');
DB::statement('DROP INDEX IF EXISTS email_suppressions_scope_email_key');
DB::statement('ALTER TABLE email_suppressions DROP COLUMN email');
// La table doit rester « identifiable » : l'empreinte devient obligatoire.
DB::statement('ALTER TABLE email_suppressions ALTER COLUMN email_hash SET NOT NULL');
```

⚠️ Les deux contraintes d'`email_suppressions` mentionnent la colonne et
tomberaient avec elle en `CASCADE` : on les retire **explicitement**, sinon le
`DROP COLUMN` emporterait en silence `email_suppressions_identifiable_check`,
c'est-à-dire la garde « une ligne qui n'identifie personne ne sert à rien ».

⚠️ `opt_out` ne peut PAS recevoir `email_hash NOT NULL` : une opposition par
**téléphone seul** est légitime et n'a rien à hacher (`addOptOut(null, $phone, …)`).

## Condition d'entrée — à constater EN PRODUCTION, avant d'écrire quoi que ce soit

```sql
select count(*) from opt_out            where email is not null and email_hash is null;  -- doit rendre 0
select count(*) from email_suppressions where email is not null and email_hash is null;  -- doit rendre 0
```

Et, en confirmation que le temps 1 a bien été déployé :

```sql
select conname from pg_constraint
 where conname in ('opt_out_empreinte_obligatoire_check',
                   'email_suppressions_empreinte_obligatoire_check');  -- doit rendre 2 lignes
```

Si le premier constat ne rend pas `0`, **le temps 2 ne doit pas être joué** : la
migration de remplissage aurait échoué, ou une ligne porte une adresse vide.
Le message de refus de la migration nomme les identifiants et donne le `DELETE`.

Une suppression de colonne est irréversible pour les données qu'elle emporte.
La faire dans le même déploiement que le remplissage qui la rend sûre, c'est se
priver du seul moment où l'on peut encore vérifier.

---

## 🔴 Un écart mesuré, à trancher AVANT ou APRÈS le temps 2 — mais à trancher

Le système hache l'adresse d'opposition à deux endroits, dans deux langages :

| | formule | où |
|---|---|---|
| PHP (SSOT) | `sha256(mb_strtolower(trim($email)))` | `ListeSuppression::empreinte()`, `SiteSyncEvent::emailHash()`, et le SITE, indépendamment |
| SQL | `encode(digest(btrim(lower(<col>)), 'sha256'), 'hex')` | `EligibiliteCampagne::appliquerPortes()`, appliqué à la colonne du SUJET |

**Mesuré le 2026-08-18** sur l'image Postgres du projet (base initialisée en
`--lc-collate=C --lc-ctype=C`, cf. `docker-compose.yml`) :

- ils coïncident sur **toutes les formes ASCII** — majuscules, espaces de
  bordure, sous-adressage, chaîne vide (11 cas mesurés, tous identiques) ;
- ils **divergent** dès qu'une **majuscule non-ASCII** apparaît :

```
SQL   lower('ÉRIC@ACME.FR')          → 'Éric@acme.fr'      (le É reste)
PHP   mb_strtolower('ÉRIC@ACME.FR')  → 'éric@acme.fr'
```

Cet écart **préexiste** au retrait de la colonne en clair : `citext` se compare
lui aussi via `lower()`, donc l'ancien repli sur l'adresse lisible était aveugle
au même endroit. Il n'est ni créé ni aggravé par le temps 1. Mais il n'était
écrit **nulle part**, et la garde repose désormais entièrement sur ces deux
formules.

`tests/Feature/Rgpd/EmpreinteSqlEtPhpTest.php` le fige : il est vert tant que
l'état mesuré est celui qu'on croit, et rouge le jour où quelqu'un change la
collation, la formule SQL ou la normalisation PHP.

**Trois issues possibles, aucune n'est neutre :**

1. **Ne rien faire.** Aucune adresse à majuscule accentuée n'a été observée dans
   les données du CRM. Le risque reste théorique.
2. **Poser la collation sur la formule SQL** —
   `lower(<col>::text COLLATE "C.UTF-8")`. Vérifié disponible sur l'image du
   projet. ⚠️ change le comportement d'une garde en production, et la collation
   doit exister sur **tous** les environnements (à vérifier sur le serveur
   HETZNER avant, pas après).
3. **Cesser de hacher en SQL** : porter sur `companies` / `contacts` une colonne
   d'empreinte calculée en PHP à l'écriture, et comparer empreinte à empreinte.
   C'est la seule issue qui supprime la classe entière de divergences ; c'est
   aussi la plus lourde (deux migrations et une reprise sur 4,29 M de fiches).

**Décision Will.** Elle n'a pas à précéder le temps 2 : les deux sujets sont
indépendants.
