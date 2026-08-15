# Import — Entreprises françaises implantées en Roumanie (2026-08-15)

Campagne : vendre formations / audits / implémentations IA **sur place en
Roumanie** aux entreprises françaises qui y opèrent. Contexte marché (DG
Trésor 2025) : ~4 150 entreprises à capitaux majoritairement français en
Roumanie, 125 000 salariés, 36 groupes du CAC 40 présents.

## Contenu

| Fichier | Contenu |
| --- | --- |
| `implantations-roumanie.jsonl` | **100 records pivot** — maison-mère française (SIREN vérifié via l'API publique recherche-entreprises data.gouv) + implantation(s) roumaine(s) en `company.implantations` (104 entités locales : Dacia→Renault, BRD+GSC→Société Générale, Apa Nova→Veolia…) |
| `implantations-roumanie-pending.jsonl` | **12 records SANS SIREN** (entités à forme française SAS/SASU/EURL membres CCIFER, non résolues) → partent en **file d'arbitrage** (`pending_match`), décision humaine |
| `resolution-report.json` | Rapport complet : 100 résolues, 305 en attente (PME roumaines membres CCIFER pour l'essentiel — voir `pending_list`), 159 exclues (ONG, cabinets d'avocats, universités, groupes non français) |
| `ccifer-raw.txt` | Moisson brute : 568 organisations, 57 pages **publiques** de l'annuaire CCIFER + ajouts DG Trésor |
| `resolve-siren.mjs` | Script de résolution (rejouable : `node resolve-siren.mjs` dans ce dossier) |

## Sources (publiques — base légale : intérêt légitime B2B, cf. registre)

- Annuaire public CCIFER : `ccifer.ro/adhesion/annuaire-des-membres/directory-page/N.html` (N∈1..57)
- DG Trésor, relations bilatérales Roumanie : `tresor.economie.gouv.fr/Pays/RO/relations-bilaterales`
- SIREN : API recherche-entreprises (data.gouv, open data, gratuite)

Aucune personne physique collectée (zéro PII) : l'enrichissement contacts se
fait ensuite par le waterfall habituel, sous ses propres garde-fous.

## Procédure d'exécution en PROD

⚠️ Prérequis : la PR `feat/prospection-fr-roumanie` mergée **et déployée**
(pivot `implantations`, source au registre par migration, AutoTagger).

```bash
scp _IMPORTS/2026-08-15-implantations-roumanie/implantations-roumanie.jsonl crm-prod:/tmp/
# 1. Répétition générale (rien n'est écrit) :
docker exec axion-crm-api php artisan scraping:ingest-file implantations-fr-etranger /tmp/implantations-roumanie.jsonl --dry-run
# 2. Réel :
docker exec axion-crm-api php artisan scraping:ingest-file implantations-fr-etranger /tmp/implantations-roumanie.jsonl
# 3. La file d'arbitrage (12 fiches sans SIREN), même commande avec le fichier -pending.
```

Rejouer est **idempotent** (dédup par `run_id`). Le run a été validé de bout
en bout sur base de test : `created: 100`, 100 × tag `implantation-ro`
(geo/auto) + 100 × `src:scraping-implantations-fr-etranger`.

## Retrouver le segment dans la console

- Filtre API/console : `filter[tag]=implantation-ro` (liste, export CSV et hub
  partagent la même query — l'export = exactement la liste affichée).
- Chaque fiche porte : tag `implantation-ro` (sky/geo), tag source, et dans
  `signals.implantations.RO` le nom local + la ville de la filiale roumaine.
- `relation_type=prospect`, `lifecycle_stage=nouveau`,
  `legal_basis=legitimate_interest_b2b` (un scrapé naît froid).

## Limites connues / suite

- L'annuaire CCIFER **complet** (6 797 contacts) est derrière le login membre :
  l'adhésion CCIFER (payante, cotisation annuelle) est LE levier pour la longue
  traîne + le réseau sur place — décision Will.
- 305 organisations restent au rapport sans fiche (majoritairement des PME
  roumaines membres CCIFER, hors cible « entreprise française ») — réservoir
  d'arbitrage si besoin.
- Extension à d'autres pays : même pipeline, il suffit d'un `ccifer-raw.txt`
  équivalent (CCI FI du pays) et du code pays ISO2.
