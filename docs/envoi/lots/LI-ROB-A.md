# LI-ROB-A — dépôt des prospects LinkedIn (relectures de #725 et #741)

Plan : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680.

## Ce qui change (`DepotProspects.php` seul)
- `ajouter` : l'insertion du prospect et la ligne de journal `ajout` sont dans UNE transaction. Si `ecrire()` rend faux ou lève, tout est annulé : aucun prospect sans journal.
- `appliquerGeste` : si `ecrire()` rend faux, exception (le journal vient avant le changement d'état, dans la même transaction) : aucun état changé sans ligne de journal.
- `appliquerGeste` relit l'opposition SOUS le verrou du prospect pour `invitation_faite` et `message_fait`, par `ControlesProspect::opposes` (lecture seule : rien n'est écarté ni journalisé ici). Opposé ou lecture impossible ⇒ exception, rien d'écrit. Un geste déjà journalisé (rejeu) est rendu tel quel, avant cette relecture.
- `ProspectionLinkedInController.php` (#761) n'est pas touché : il lève déjà ces exceptions en 503 (`indisponible`).

## Tests
`backend/tests/Feature/ProspectionLinkedIn/DepotProspectsRelectureTest.php` : 7 tests, chacun sous `pgsql_app` ET sous le propriétaire. Journal rendu faux (déclencheur temporaire qui avale l'écriture) ou en panne, opposition posée avant l'écriture, rejeu, espace B.
Mutations rouges : sans le test de retour de `ecrire` (ajout) ⇒ 3 rouges ; (geste) ⇒ 2 rouges ; sans relecture d'opposition ⇒ 1 rouge ; sans transaction ⇒ 2 rouges ; relecture rendue inopérante ⇒ 1 rouge.
