# X1-L2-bis, lot L2b-A — colonne `envoi_campagnes.filtre_origine` (migration seule)

Cahier : PR #648 (`docs/envoi/lots/X1-L2-bis-cahier.md`, § 3). Aucun code applicatif. `ENVOI_REEL_ACTIF`, `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` : intouchés.

## Fait
- `2026_12_10_000100_envoi_campagnes_filtre_origine.php` : colonne JSONB NULL ; CHECK `envoi_campagnes_filtre_origine_forme` (NOT VALID puis VALIDATE) ; déclencheur `envoi_campagnes_filtre_origine_fige` (gel dès que `sequence_id` est posé, `search_path = pg_catalog, public, pg_temp`, sans SECURITY DEFINER) ; `GRANT INSERT (filtre_origine), UPDATE (filtre_origine)` au rôle applicatif ; `down()` inerte. `envoi_campagnes_garde()` n'est pas réécrite.
- Écart de forme : le cahier écrit `filtre_origine ? 'reseaux'` ; le `?` est pris pour un paramètre par PDO, on utilise `jsonb_exists(filtre_origine, 'reseaux')` (équivalent exact).
- Numéro `2026_12_10_000100` : absent de `main` et de toutes les PR ouvertes (#607 à #650 vérifiées).

## Sortie rouge (avant la migration)
`Tests: 8 failed` (colonne inexistante), rôle applicatif ET propriétaire.

## Tests (`SchemaFiltreOrigineCampagneTest.php`, 8 cas, rôle applicatif ET propriétaire)
Structure, formes acceptées, formes refusées (23514), gel (4 cas), table temporaire, cloisonnement, additif (down puis up deux fois), garde `version + 1`. Résultat : 8 verts ; `Nr17MigrationsTest` vert.

## Mutations (cassé, rouge vu, restauré)
| Mutation | Test rouge |
|---|---|
| `like_regex` retiré | 3 |
| `BETWEEN 1 AND 10` élargi | 3 |
| `(filtre_origine - 'reseaux') = '{}'` retiré | 3 |
| `NEW.sequence_id IS NOT NULL` retiré du gel | 4 |
| déclencheur renommé/absent | 1 et 7 |
| `pg_temp` déplacé en tête du `search_path` | 1 |
| `GRANT UPDATE (filtre_origine)` retiré | 1, 2, 3, 4, 5, 6, 8 (mode app) |

## Limites
Le plafond de 1 024 octets est atteint avant par la regex/les bornes pour toute forme valide (10 × 64 caractères < 1 024) : il ne sert que de filet. Suite complète non jouée en local (CI).

## Ajout hors liste du cahier (exigé par la CI)
`backend/tests/Feature/Rgpd/colonnes-json/2026-12-10-x1-l2b-a-filtre-origine.json` : un NOUVEAU fragment d'inventaire des colonnes JSON (le test B10-004 de `PortabiliteCompleteTest` rougit sinon). Aucun fragment existant modifié.
