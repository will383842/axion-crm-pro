# P1-PLAFOND — Butée en base de 300 par jour et par espace (vague 2)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche P1-PLAFOND, décision D7. Aucune migration : la table et les fonctions viennent de S1-A (`2026_12_03_000010`).

## Fait
- `DepotSocle` : deux méthodes, `plafondEspace(ws)` (réglage R14 `regles.plafond_jour`, à défaut `ENVOI_PLAFOND_JOUR`) et `reserverPlaceEspace(ws, jour, plafond)` (appel de `envoi_compteur_espace_reserver`). `DepotSocleDb` les implémente ; `DepotMemoire` aussi (et rend la place à la libération).
- `PasserelleLocale` : la butée est le DERNIER contrôle avant `reserverInjection` (après opposition, rejeu, plafonds PHP, chauffe), dans la même transaction. Plafond effectif = `min(plafond de la passerelle, réglage R14, 300)`. Un refus rend `reporte(+1 jour)` sans lever : ni ligne de journal, ni compteur global, et les péremptions de réservations (et leurs alertes) de la même transaction sont conservées. `noterInjection` n'appelle rien (pas de double comptage). La libération passe par `envoi_injection_liberer` (déjà en place).
- Tests : `PlafondButeeBaseTest` (6), `PlafondButeeConcurrenceTest` (8 processus, `n = 295` ⇒ 5 succès), script `tests/Support/ouvrier_butee.php`.

## Test modifié : pourquoi
`ChauffeFreinsTest` « MAJEUR 3 » : assertions identiques ; seule la mise en place change (`config envoi.plafond_jour = 300`). Le plafond d'espace effectif vaut désormais min(R14, `ENVOI_PLAFOND_JOUR` = 20 par défaut, 300) et masquait le quota de domaine (30) que ce test vérifie. Ce fichier n'est pas dans la liste fermée de la fiche : écart signalé, nécessaire pour garder le test vert sans l'affaiblir.

## Reste
- Réservations antérieures à P1-PLAFOND : aucune place d'espace comptée (aucune n'existe en production). Pour une réservation faite entre S1-A et P1-PLAFOND, `envoi_injection_liberer` décrémente quand même : le plancher à 0 évite toute valeur négative, l'effet se limite à une place rendue à tort ce jour-là.
- Garde `nettoyerEspaces()` de `PlafondConcurrenceTest` : inchangée et verte ; l'absence de clé étrangère est déjà gardée par `SchemaFinEnvoiATest`.

## Risques
- Un réglage R14 illisible donne un plafond 0 (le plus prudent : aucune réservation).
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel.

## Tests lancés
`pint --dirty` ; Pest : `tests/Feature/Envoi`, `tests/Unit/Envoi` verts ; NonRegression : seul `NR-09` (partitions) rouge, aussi rouge sur `main` dans cet environnement (extension pg_partman absente). PHPStan : 2 erreurs déjà présentes sur `main` (DepotMemoire l.269, motif ignoré non utilisé).
