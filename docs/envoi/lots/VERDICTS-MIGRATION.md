# VERDICTS-MIGRATION — colonne `motif` et droits du cache DNS (migration seule)

## Fait
- Migration `2026_12_05_000500_envoi_verdicts_adresse_motif.php` : colonne `motif` (texte, nullable) sur `envoi_verdicts_adresse`, contrainte `envoi_verdicts_adresse_motif_ck` (10 motifs, `NOT VALID`), commentaire de colonne.
- `GRANT SELECT, INSERT, UPDATE` sur `email_domaines` au rôle applicatif (le `ON CONFLICT DO UPDATE` du cache DNS l'exige). Aucun droit nouveau sur `envoi_verdicts_adresse`.
- Rejouable (`IF NOT EXISTS`, bloc `DO` sur `pg_constraint`). Ajout seul. `down()` inerte (corps vide, comme 000122 ; NR-17 classe un COMMENT « réel »).
- NR-17 : une entrée dans `NR17_RETOUR_INERTE_VOULU`.

## Pourquoi une PR seule
Le déploiement remplace le code avant `migrate` : la migration part et se déploie d'abord (VERDICTS-CODE suivra).

## Hors lot
Aucun code applicatif, aucun drapeau, aucune suppression de données, pas d'index, pas de déclencheur, pas de contrainte sur `source`.

## Tests
- `VerdictsMotifMigrationTest` : M1 colonne nullable, M2 liste fermée, M3 `up()` rejouable, M4 rôle `pgsql_app`.
- Rouges d'abord (4 échecs : colonne et fichier absents), verts après la migration.
