# FINI-M0A — migration seule : inventaire des IP d'envoi (X5, X6)

Cahier : `docs/envoi/lots/FINI-cahier.md` (PR brouillon #680), section « FINI-M0A ». Rien ne lit ces objets : aucun code applicatif touché.

## Fichiers
- `backend/database/migrations/2026_12_07_000100_envoi_ips.php` : table `envoi_ips`, RLS forcée, ajout seul (seul `retiree_le` NULL → non NULL permis). Le rôle applicatif a `SELECT`, `INSERT` et `UPDATE (retiree_le)` ; ni `DELETE` ni `TRUNCATE`.
- `backend/database/migrations/2026_12_07_000110_envoi_domaines_ip.php` : colonne `envoi_domaines.ip_id`, clé composite `(workspace_id, ip_id)`, fonction `SECURITY DEFINER` `envoi_domaine_ip_poser` ; aucun `GRANT` de colonne sur `ip_id`.
- `backend/tests/Feature/Envoi/MigrationsIpEnvoiTest.php` : 11 cas (pgsql_app ET propriétaire).

## Tests rouges d'abord (sur `main`, avant les migrations)
```
Tests:    11 failed (3 assertions)
SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "envoi_ips" does not exist
```
Après les migrations : 11 verts, 49 assertions.

## Mutations (annulées ensuite)
1. Condition « même flux » retirée de la fonction ⇒ `Tests: 2 failed` (test 4, les deux connexions).
2. `FORCE ROW LEVEL SECURITY` retiré ⇒ `Tests: 1 failed` : c'est le test 1 (présence de la RLS forcée) qui rougit. Le test 5 ne rougit PAS : le rôle applicatif n'est pas propriétaire de la table, donc la RLS le tient avec ou sans `FORCE` ; `FORCE` protège un propriétaire non superutilisateur, que la suite ne simule pas. Écart au cahier, signalé ici.

## Notes
- Le message du déclencheur (`envoi_ips :`) n'est atteint que sous le propriétaire ; le rôle applicatif est arrêté avant par le droit (`permission denied`), ce qui est plus strict.
- Exécution locale : Postgres 16 avec postgis et pgvector ; `pg_partman` absent (avertissement de migration normal).
- Déploiement des migrations requis avant X6-A et X5-B. `down()` inerte. Aucun drapeau touché.
