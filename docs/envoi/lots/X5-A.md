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

## Preuve du rouge puis du vert
Les tests ont été écrits d'abord (commit `cf200c2`, rouge : le branchement dans `ListeFeuVert` n'existait pas). Dans ce bac à sable, la suite Pest sur PostgreSQL dépasse 10 minutes par fichier : la preuve est donc fournie par la CI de la PR (commit rouge, puis commit du branchement), plus un essai local borné à 9 minutes (résultat dans la PR).

## Mutations demandées
Voir le commentaire de la PR (résultats consignés après exécution).
- Retirer le filtre « preuve postérieure » (`p.cree_le > att.le`) ⇒ le test « preuve antérieure » doit échouer.
- Lire l'attestation sans filtre d'espace ⇒ le test « espace B sans effet sur A » doit échouer.
