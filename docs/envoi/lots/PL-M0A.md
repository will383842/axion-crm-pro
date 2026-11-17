# PL-M0A — migration seule : filtre de campagne par mot / guide, registre d'opposition LinkedIn

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), section PL-M0A.
Rien ne lit ni n'écrit ces objets : migration seule, inerte.

## Fichiers
- `backend/database/migrations/2026_12_09_000110_envoi_campagnes_filtre_origine_contenu.php` (colonne JSONB `filtre_origine_contenu`, CHECK forme, gel dès que `sequence_id` est posé, droits colonne)
- `backend/database/migrations/2026_12_09_000120_envoi_oppositions_linkedin.php` (table en ajout seul, RLS ENABLE + FORCE, SELECT + INSERT seulement)
- `backend/tests/Feature/Envoi/SchemaPontOriginesLinkedinTest.php` (7 tests, rôle applicatif ET propriétaire)
- `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` (2 lignes dans `NR17_RETOUR_INERTE_VOULU`)
- `backend/tests/Support/SemeurTablesScopees.php` (filet d'étanchéité : table déclarée, semée, `APPEND_SEUL_AVEC_FK` ; ajout exigé par la CI)
- `backend/tests/Feature/Rgpd/colonnes-json/2026-12-09-pl-m0a-filtre-origine-contenu.json`
- `docs/envoi/lots/PL-M0A.md`

Horodatages `000110` et `000120` du 2026-12-09 : libres sur `main` et sur les PR ouvertes (vérifié). Aucun DROP de donnée, aucun DELETE ; `down()` inerte.
La contrainte de `filtre_origine` n'est pas touchée ; `envoi_campagnes_garde()` et `envoi_oppositions_registre` non plus.

## Tests rouges d'abord (avant les migrations)
```
⨯ 1 structure …            relation/colonne absentes
⨯ 2 formes acceptées       42703 column "filtre_origine_contenu" of relation "envoi_campagnes" does not exist
⨯ 2b formes refusées
⨯ 3 gel
⨯ 4 registre LinkedIn      42P01 relation "envoi_oppositions_linkedin" does not exist
⨯ 5 cloisonnement
Tests: 6 failed
```
Après les migrations : `Tests: 7 passed (88 assertions)`.

## Mutations (chacune retirée, le test rougit, puis annulée)
| Mutation | Test rouge |
|---|---|
| `like_regex` retiré du CHECK | 2b formes refusées |
| `OLD.sequence_id IS NOT NULL OR NEW.sequence_id IS NOT NULL` retiré du gel | 3 gel |
| `FORCE ROW LEVEL SECURITY` retiré | 1 structure (RLS forcée) |
| refus `UPDATE` du registre retiré | 4 registre |

## Autres vérifications
- `Nr17MigrationsTest` : 5 passés. `SchemaFiltreOrigineCampagneTest` : 8 passés. `PortabiliteCompleteTest` (B10-004) : passé.
- `EtancheiteParTableTest` : 5 échecs, tous et seulement sur `audit_logs` (pg_partman absent de la base locale ; identique sans ce lot) ; la nouvelle table y est semée et vue.
- Note : la taille 2 048 octets est une garde de principe ; 20 jetons valides font au plus ~1 400 octets, donc non atteignable par un jeu valide.
