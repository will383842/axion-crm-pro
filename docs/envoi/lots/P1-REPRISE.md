# P1-REPRISE — Reprise après panne, preuve par arrêt brutal (vague 5)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche P1-REPRISE (D7, D9, D11, D12). `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune migration, données fictives (`*.example.test`).

## Fait
- `RepriseApresPanneTest` : des processus réels (même méthode que `PlafondConcurrenceTest`) font tourner la vraie chaîne `ChefOrchestre` → `Sequenceur` → `PasserelleLocale` → faux serveur persistant, et se tuent eux-mêmes par `SIGKILL` à quatre points nommés ; un second processus reprend (+2 h, la réservation est « ancienne »), puis un troisième (+3 h : rien ne doit bouger). Trois inscriptions, plafond du jour à 2.
  1. après le temps A : le message réservé par le séquenceur part une fois à la reprise, celui jamais réservé aussi, le troisième attend (plafond) ;
  2. pendant l'appel (rien n'est parti) : issue incertaine, inscription arrêtée, alerte, jamais réinjecté ;
  3. après l'acceptation du faux serveur : jamais réinjecté, issue incertaine, inscription arrêtée, alerte ;
  4. pendant le temps C (tué dans la transaction de conclusion, avant validation) : conclu une seule fois (`envoye`, registre), serveur non rappelé.
  Invariants communs : aucune clé injectée deux fois, plafond respecté (registre du serveur et `envoi_compteur_espace`), troisième cycle sans effet.
- **Témoin obligatoire** : la mémoire durable de la reprise est effacée après la mort du processus ⇒ le même scénario injecte deux fois et le détecteur d'invariants rougit (le test vérifie qu'il rougit).
- `ouvrier_cycle.php` : la chaîne est construite dans l'ouvrier ; `ResolveurFournisseur` lié à `ResolveurEnMemoire` ; `APP_ENV=testing` ; la porte « envoi réel » est ouverte dans ce seul processus, en mémoire (jamais écrite). Le conteneur du test n'est pas partagé avec les fils.
- `FauxServeurPersistant` : registre dans une table de test créée puis retirée par le test (aucune migration), connexion propriétaire en auto-commit. Comme `AiguilleurServeurEnvoi` (D11), il n'implémente pas `RenvoiParMemeCle` : un rejeu de clé `reservee` / `a_verifier` n'est jamais renvoyé.

## Défauts révélés
Aucun : `Sequenceur.php` et `ChefOrchestre.php` ne sont pas modifiés. Preuve ROUGE : en désactivant temporairement le rejeu dans `PasserelleLocale` (lecture de l'état de la clé forcée à « inconnue »), trois des quatre scénarios rougissent (clé injectée deux fois) ; le quatrième (après le temps A) ne dépend pas de cette garde. Mutation retirée, rien de commité.

## Reste
- Un cycle tué laisse son verrou `Cache::lock` jusqu'à son expiration (600 s au plus) : le cycle suivant attend ce délai. Borné, non testé ici (le fils utilise un cache en mémoire, chaque processus a son verrou).
- « Accepté puis tué avant la conclusion de la passerelle » (points 2 et 3) n'est pas distinguable d'un message perdu : l'inscription est arrêtée avec alerte et demande une vérification humaine (perdre plutôt que doubler).
- Le test écrit pour de bon (auto-commit) puis retire ses lignes avec les déclencheurs d'ajout seul suspendus pour la session propriétaire ; il suppose un rôle de base de test super-utilisateur (comme la CI).

## Tests lancés
`pint --test` ; `pest tests/Feature/Envoi/RepriseApresPanneTest.php`, `ChefOrchestreTest`, `PlafondButeeConcurrenceTest` ; PHPStan.
