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

## Filet modifié : pourquoi
`Nr17MigrationsTest.php` : les 3 migrations sont inscrites à `NR17_RETOUR_INERTE_VOULU` (retour inerte voulu, ajout seul) ; un test témoin est ajouté (un retour réel reste bloqué par une clé étrangère OUBLIÉE, SQLSTATE 2BP01, sans `CASCADE`) ; après la boucle, la clé `envoi_inscriptions_boite_workspace_fk` doit exister. Aucune exception par nom de fichier. Le `down()` de `000040` et de `000042` retire d'abord cette seule clé (sous garde `to_regclass`), la colonne et les lignes restent. Décision de pilotage sur #680 (3e ESCALADE).

## Preuves
- Avant correctif : « toute migration NOUVELLE… » rouge (2BP01). Après : Nr17, `MigrationsFiniTest`, `EtancheiteParTableTest` verts (26 tests).
- Témoin : `DROP TABLE … CASCADE` à la place de la ligne ajoutée dans 000040 ⇒ le témoin rougit ; annulé.
