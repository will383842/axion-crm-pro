# S1-C — Schéma de fin d'envoi, partie C (vague 3)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche S1-C. `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée existante modifiée.

## Fait
- `2026_12_03_000020_envoi_oppositions_imports.php` : table `envoi_oppositions_imports` (auteur, empreinte du fichier, `jeton_simulation UUID NOT NULL`, compteurs entiers, horodatage) en ajout seul, RLS forcée, SELECT + INSERT, aucun `@` dans les textes, aucune colonne JSON ; `UNIQUE (workspace_id, jeton_simulation)` et `UNIQUE (workspace_id, id)`.
- Colonne `envoi_oppositions_registre.import_id` (nullable) et clé étrangère composite `(workspace_id, import_id)` vers `envoi_oppositions_imports (workspace_id, id)` : un import d'un autre espace est refusé ; lignes existantes inchangées.
- Filet : `SemeurTablesScopees` (table avant le registre), `NR17_RETOUR_INERTE_VOULU`, `RlsTest` ; `SchemaFinEnvoiCTest` (8 tests). `nr17-migrations-sans-retour.json` inchangé.

## Reste
- P1-LN-B (vague 4) : consommateur de la table ; aucun code applicatif ne la lit encore.

## Risques
- `TRUNCATE envoi_oppositions_imports` seul est refusé par PostgreSQL (clé étrangère du registre) avant même le déclencheur ; le déclencheur reste là pour `CASCADE`.
- Les barrières de droits ne valent que si `CRM_DB_APP_ROLE_ENABLED=true` ; sinon seuls les déclencheurs protègent.

## Tests lancés
`SchemaFinEnvoiCTest`, `SchemaFinEnvoiATest`, `SchemaFinEnvoiBTest`, `Nr17MigrationsTest`, `RlsTest`, `tests/Feature/Rgpd`, `PrivilegesDeLaFonctionDefinerCouvertureTest`, `PortabiliteCompleteTest`, PHPStan, Pint.
