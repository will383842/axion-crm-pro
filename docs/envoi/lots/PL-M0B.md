# PL-M0B — migration seule : tables du module de prospection LinkedIn (`pl_*`)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), section PL-M0B.
Rien ne lit ni n'écrit ces tables : migration seule, inerte. PL-M0A (#694) est en ligne.

## Fichiers
- `backend/database/migrations/2026_12_09_000130_pl_prospects_journal.php` (`pl_prospects`, `pl_journal`)
- `backend/database/migrations/2026_12_09_000140_pl_plafonds_arret.php` (`pl_plafonds`, `pl_arret`)
- `backend/tests/Feature/ProspectionLinkedIn/SchemaProspectionLinkedinTest.php` (9 tests, rôle applicatif ET propriétaire)
- `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` (2 lignes commentées dans `NR17_RETOUR_INERTE_VOULU`)
- `backend/tests/Support/SemeurTablesScopees.php` (filet d'étanchéité : 4 tables déclarées, semées, `APPEND_SEUL_AVEC_FK` ; ajout exigé comme pour PL-M0A)
- `backend/tests/Feature/Rgpd/colonnes-json/2026-12-09-pl-m0b-journal.json` (colonne JSONB `pl_journal.details`)
- `docs/envoi/lots/PL-M0B.md`

Horodatages `000130` et `000140` du 2026-12-09 : libres sur `main` (vérifié). Aucun DROP, aucun DELETE de données ; `down()` inerte ; `up()` rejouable.
Aucune table `linkedin_*`, aucun `contacts` / `companies` touché.

## Écarts avec le cahier (défaut sans risque)
- `expediteur_user_id` est un **UUID** (et non `BIGINT`) : `users.id` est un UUID, la clé étrangère l'impose.
- Plafonds : colonnes `NOT NULL` avec pour défaut les valeurs prudentes de ESCALADE E5 (10 / 50 / 20 / 5, jours ouvrés) ; bornes du CHECK inchangées (0..25, 0..100, 0..40, 0..5).
- `contact_id BIGINT NULL` sans clé étrangère (aucune contrainte sur `contacts`, table chaude ; rapprochement par lecture, LI-B1).
- Écarté définitif : tout `UPDATE` d'une ligne `ecarte` qui change autre chose que `maj_le` est refusé (comparaison `to_jsonb(NEW) - 'maj_le'`).
- Rôle applicatif : `SELECT, INSERT` + `UPDATE` des seules colonnes `etat, score, variante, relance_le, contact_id, maj_le` sur `pl_prospects` ; `USAGE, SELECT` sur les séquences.
- Taille de la PR au-dessus de 400 lignes : deux migrations + 9 tests de sécurité exigés par le cahier ; aucun autre fichier.

## Preuve (CI de la PR, le test local complet dépasse 10 minutes dans ce bac à sable)
- ROUGE : commit `d6e71ac12ac9` (tests seuls, sans migrations) : CI de la PR #715, « Pest part 1/4 », « 3/4 », « 4/4 » et « Filet de non-régression » en échec.
- VERT : commit suivant (avec les migrations) — CI de la PR : voir le commentaire de la PR.

## Mutations (à jouer en CI si besoin ; chacune doit rougir)
| Mutation | Test qui rougit |
|---|---|
| gel `ecarte` retiré du déclencheur | 4 prospect écarté |
| `pl_journal_une_cle_par_espace` retirée | 6 journal |
| `FORCE ROW LEVEL SECURITY` retiré | 1 structure (RLS forcée) |
