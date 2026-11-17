# X5-A — attestation humaine « serveur réel » pour les 22 points

Cahier : `docs/envoi/lots/FINI-cahier.md` (PR #680). Dépend de FINI-M0B (#685, table `envoi_attestations_serveur_reel`) et de X5-C (#690, même fichier chaud). Aucune migration ici.

## Fichiers (seuls ceux-là sont touchés)
- C `backend/app/Envoi/FeuVert/DepotAttestationServeurReel.php`
- C `backend/app/Console/Commands/EnvoiServeurReelAttester.php`
- M `backend/app/Envoi/FeuVert/ListeFeuVert.php`
- C `backend/tests/Feature/Envoi/FeuVertAttestationServeurReelTest.php`
- C `docs/envoi/lots/X5-A.md`

## Comportement
- Commande locale `envoi:serveur-reel-attester {workspace} {geste : atteste|retire} --auteur= --motif=` (aucune route). `--auteur` et `--motif` obligatoires, motif sans adresse e-mail. Elle écrit une ligne d'attestation, une ligne `attestation_serveur_reel` au journal des commandes et une alerte critique.
- `ListeFeuVert` : attestation active = dernière ligne de l'espace avec `geste = 'atteste'`. Active, un point « serveur réel » est fait seulement si sa dernière preuve est « fait » ET postérieure à l'attestation (comparaison en base) ; une preuve antérieure reste « à revalider ». Sans attestation ou après `retire` : comportement de `main` (attend le serveur). Erreur de lecture ⇒ exception (échec fermé).
- L'attestation entre dans l'empreinte via l'état des points.
- `auto.separation_lettre_prospection` reste rouge (X5-B) : « accordé » reste impossible même attesté (test dédié).

## Preuve du rouge puis du vert (CI de la PR)
- Rouge : commit `cf200c2` (tests écrits avant le branchement).
- Corrections de CI sur mon propre code, dans l'ordre : collision de noms de fonctions d'aide du test (`4cc2043`, erreur fatale PHP) ; types PHPStan du dépôt (`861e40c`) ; test de commande bloqué par la transaction du test (`8d8050d`) ; capture explicite de l'exception d'échec fermé (`4d15748`).
- Vert : commit `9d74920`, les 24 contrôles verts ou ignorés. Le test de plan SQL `QuarantaineSitesNonVerifiesTest` (zone non touchée) a échoué une fois puis réussi à la relance, sans changement de code.
- Essai local : impossible dans ce bac à sable (connexion PostgreSQL refusée), non poursuivi.

## Mutations demandées (jouées sur la CI de la PR, commit `76b363a`, annulé par un commit de revert)
- Filtre « preuve postérieure » (`p.cree_le > att.le`) retiré ⇒ « attestation + preuve antérieure » échoue, sous `pgsql` ET `pgsql_app` (2 échecs).
- Lecture de la dernière attestation sans filtre d'espace ⇒ « l'attestation de l'espace B est sans effet sur A » échoue (sous `pgsql`).
- Total : 3 échecs sur 1 911 tests ; aucun autre test touché. Mutations annulées, code de production rétabli à l'identique de `9d74920`.
