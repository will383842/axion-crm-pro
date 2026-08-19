# AGENT 29 — commandes jouées

Référence relue au démarrage : `git log --oneline -1` → **`e8924b8`**.
Base de mesure dédiée : **`axion_crm_a29`** (créée par moi, jamais `axion_crm_test`).
Aucun fichier du produit modifié. Aucune écriture en production. Worktree `crmpro-wt-etape1a` non touché.

## 1. Internationalisation

| # | Commande | Sortie |
|---|---|---|
| 1 | `node scan-hardcoded.cjs frontend/src --detail` (AST TypeScript, passe 1 : `JsxText` + attributs textuels) | `chaines-en-dur-detail.txt` — **741** |
| 2 | `node scan2.cjs frontend/src` (passe 2 : propriétés d'objet, maps `*LABEL*`, `toast()`, `confirm()`) | `chaines-hors-jsx.txt` — **676** |
| 3 | Croisement `routeTree.tsx` × passes 1 et 2 | `par-ecran.md` — **37 écrans**, 665 + 322 = **987** |
| 4 | `node -e` aplatissement + diff des deux dictionnaires | 27 clés / 27 clés, **0 manquante dans chaque sens**, 3 valeurs identiques |
| 5 | `grep -rho "t('[a-zA-Z0-9_.]*')" \| sort -u \| wc -l` | 15 clés brutes → **12 réelles** (3 faux positifs identifiés à la lecture) |
| 6 | `grep -rln "useTranslation" features components app` | **5 fichiers sur 84** |
| 7 | `grep -rn "toLocaleDateString\|toLocaleString\|toLocaleTimeString\|Intl\."` | **63** appels, **0** `Intl.`, **0** option `timeZone`, **0** `i18n.language` |
| 8 | `grep -rn "> 1 ? 's'\|> 1 ? \"s\""` | **8** pluriels par concaténation |
| 9 | `grep -rn "€\|toFixed(2)"` | 5 `€`, 2 rendus calculés en `toFixed(2)` |
| 10 | **Piège 17** — fabrication d'un témoin U+202F, `od -c`, puis `grep $' '` et `grep -c ' '` | `temoin-202f.txt` — le contrôle rend la ligne A seule pour U+202F et 2 pour l'espace ordinaire → **méthode validée dans les deux sens** avant de conclure « 0 occurrence » |
| 11 | `grep -rn "changeLanguage\|i18n.language"` ; `grep -n "lang=" frontend/index.html` | aucun sélecteur ; `<html lang="fr">` figé |
| 12 | `grep -rlin "..." backend/tests/` (recherche de tests i18n / bascule) | **0 test d'i18n**, **0 test de bascule horaire** |

## 2. Temps

| # | Commande | Sortie |
|---|---|---|
| 13 | `psql -d axion_crm` sur `information_schema.columns` restreint aux `BASE TABLE` | `colonnes-types-resume.txt`, `colonnes-dates-detail.txt` — **203 `timestamptz`, 0 `without time zone`, 3 `date`** |
| 14 | `docker exec axion-crm-api php /tmp/a29-etat.php` | `local-fuseaux.txt` — `app.timezone=Europe/Paris`, `DB_TIMEZONE=NULL`, `SHOW TimeZone=Etc/UTC`, `getDateFormat=Y-m-d H:i:s` |
| 15 | `docker exec axion-crm-api php /tmp/a29-serial.php` | `serialisation-dates.txt` — Carbon `+02:00` → **chaîne SQL nue** `2026-03-29 14:00:00` ; JSON API zoulou `…T12:00:00.000000Z` |
| 16 | `grep -n 'function typeTimestamp' -A6` dans le vendor Laravel 12 | `'timestamp … without time zone'` → les 203 colonnes `timestamptz` **ne viennent pas** du constructeur Laravel |
| 17 | `CREATE DATABASE axion_crm_a29` ; `php artisan migrate --force` | `migrate-a29.txt` — **58 migrations** |
| 18 | **CRITÈRE 16, mesure (a)** : `docker exec -e DB_DATABASE=axion_crm_a29 php /tmp/a29-bascules.php` | `bascules-SANS-db-timezone.txt` — **6/6 décalés** (+7 200 s été, +3 600 s hiver) |
| 19 | **CRITÈRE 16, témoin négatif (b)** : idem `-e DB_TIMEZONE=Europe/Paris` | `bascules-AVEC-db-timezone.txt` — **6/6 à 0 s**, y compris l'heure inexistante et l'heure ambiguë |
| 20 | `psql` : heures nues aux deux bascules, session `Europe/Paris` | `postgres-heures-nues-bascules.txt` — Postgres et PHP résolvent **identiquement** l'heure inexistante et l'heure ambiguë |
| 21 | `node` : énumération minute par minute des deux journées, traduite en heure de Paris | `planificateur-bascules.txt` — `dailyAt('02:00')` atteint **0 fois** le 29/03 et **2 fois** le 25/10 ; les onze autres heures : **1 fois** (témoin négatif) |
| 22 | `node` : comportement de `strcmp` sur les chaînes de la timeline aux deux offsets | `tri-timeline-bascule.txt` — **ordre inversé** dans l'heure rejouée ; **correct** hors bascule (témoin négatif) |
| 23 | `docker exec -e DB_TIMEZONE= …` (chaîne vide) | `db-timezone-vide.txt` — `SQLSTATE[22023] invalid value for parameter "TimeZone": ""`, l'API tombe |
| 24 | `psql` : rendu d'un même `timestamptz` en session `Etc/UTC` puis `Europe/Paris` | `affichage-brut-timeline.txt` — `12:00:00+00` vs `14:00:00+02` |
| 25 | `grep -n "Schedule::" routes/console.php` ; `grep -n "timezone"` sur le planificateur | **35 tâches**, **0** appel à `->timezone()` ; `Event.php:297-306` confirme `Date::now()` |

## 3. Ménage

`DROP DATABASE axion_crm_a29` joué en fin d'audit. `axion_crm`, `axion_crm_test`, `axion_crm_perf*`
non touchées. Les fichiers déposés dans le conteneur (`/tmp/a29-*.php`) sont volatils.

## 4. Ce qui a expiré (A-009 / A-010)

Cinq commandes `docker exec … php` ont dépassé leur délai (120 s à 400 s) sur un atelier servi par
`php -S` mono-processus. Chacune a été rejouée en arrière-plan et a fini par rendre. Aucune mesure
de ce rapport ne repose sur une commande expirée : quand une sonde a expiré, la valeur cherchée a
été obtenue par une **seconde** sonde indépendante (cf. la note en pied de `db-timezone-vide.txt`).
