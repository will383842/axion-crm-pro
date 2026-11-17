# FINI-M0B — migration seule : attestation serveur réel, boîte d'une inscription, codes d'ajout de boîte

Cahier : `docs/envoi/lots/FINI-cahier.md` (PR #680). Migration seule : rien ne lit ces objets. Ajout seul, aucun `DROP`/`DELETE`.
Horodatages `2026_12_07_000120`, `000130`, `000140` : libres sur `main` et sur les PR ouvertes (vérifié le 2026-11-17).

## Fichiers
- C `backend/database/migrations/2026_12_07_000120_envoi_attestations_serveur_reel.php`
- C `backend/database/migrations/2026_12_07_000130_envoi_inscriptions_boite.php`
- C `backend/database/migrations/2026_12_07_000140_envoi_boites_codes_ajout.php`
- C `backend/tests/Feature/Envoi/MigrationsFiniTest.php`
- C `docs/envoi/lots/FINI-M0B.md`

## Contenu
- `000120` : table `envoi_attestations_serveur_reel` (geste atteste/retire, motif 10–500 sans « @ »), ajout seul (UPDATE/DELETE/TRUNCATE refusés), RLS forcée, droits SELECT+INSERT.
- `000130` : `envoi_inscriptions.boite_id` NULL + clé composite `(workspace_id, boite_id)` vers `envoi_boites` ; déclencheur « boîte figée » (une fois posée, plus de changement). `UNIQUE (workspace_id, id)` de `envoi_inscriptions` déjà présent (000033) : rien d'autre ajouté.
- `000140` : table `envoi_boites_codes_ajout` (empreintes seules, tentatives 0–5), DELETE/TRUNCATE refusés, droit UPDATE limité à `tentatives` et `consomme_le`, RLS forcée.

## Tests rouges d'abord (sur `main`, migrations absentes)
`MigrationsFiniTest` : 9 échecs (objets absents : « Expecting null not to be null 'envoi_attestations_serveur_reel' », etc.).

## Tests verts (rôle applicatif ET propriétaire)
`vendor/bin/pest tests/Feature/Envoi/MigrationsFiniTest.php` : 9 réussis, 44 assertions.

## Mutations
- déclencheur « boîte figée » neutralisé ⇒ 2 échecs (test « inscription », applicatif et propriétaire) ; annulée.
- `CHECK` du « @ » retiré ⇒ 2 échecs (test « attestation ») ; annulée.

## ESCALADE (filets hors liste autorisée)
Les trois migrations font rougir, en local, des filets qui ne sont PAS dans les fichiers autorisés :
1. `NonRegression/Nr17MigrationsTest.php` : la liste `NR17_RETOUR_INERTE_VOULU` doit recevoir les 3 fichiers (`down()` inerte voulu). Fichier aussi touché par la PR ouverte #675 ⇒ non modifié ici. De plus, son aller-retour d'une ancienne migration (`DROP TABLE envoi_boites`) est bloqué par la clé composite de `000130` voulue par le cahier.
2. `EtancheiteParTableTest` / `Support/SemeurTablesScopees.php` : les deux nouvelles tables à `workspace_id` doivent être semées (`ORDRE` + charge utile).
Demande : décision du relecteur (étendre la liste autorisée, ou lot « filets » dédié après #675).
