# L6-A — `CompteursCampagne` (lecture seule)

Cahier : `docs/envoi/lots/L5-L6-cahier.md` § 6 (PR #649). Préalable : L6-0 (#651) fusionnée.

## Fait
Un service `App\Envoi\Campagnes\CompteursCampagne` (`pour`, `pourListe`) : **une seule instruction SQL** (CTE) par appel, sous `WorkspaceContext::run`, filtre d'espace sur CHAQUE table.
Aucune écriture, aucune migration, aucune route, aucun contrat ; `LectureCampagnes.php` n'est pas touché (branchement = L6-B). Aucun drapeau lu ni écrit.
Toutes les clés « inscription » sont des comptes DISTINCTS ; `issues` range chaque inscription dans une seule case (somme = `inscrits`).
`ouverts` vaut `null` et `suivi_ouvertures` `false` (aucun suivi d'ouverture). Campagne sans séquence, inconnue ou d'un autre espace : mêmes zéros (pas d'oracle).

## Écart au cahier (à relire)
Sous le rôle applicatif, la politique RLS rend les estimations du planificateur aveugles (1 ligne attendue) : il choisit des boucles imbriquées sur des milliers de lignes
et l'instruction dépasse la minute (mesuré sur 20 000 inscriptions / 40 000 messages / 5 000 événements ; 250 ms en propriétaire). Le service retire donc les boucles imbriquées
pour CETTE instruction seulement (`set_config('enable_nestloop', 'off', true)`, portée de transaction, remis à `on` ensuite) : jointures par hachage, ~240 ms dans les deux rôles.
Ces deux `set_config` ne sont pas des lectures de données ; le test 11 les exclut du compte avec le contexte d'espace.
Conséquence : le test 10 vérifie que le planificateur PEUT n'utiliser que l'index de L6-0 (`enable_seqscan` et `enable_nestloop` coupés le temps du `EXPLAIN`, même convention que
`SchemaEvenementsInjectionIndexTest`) ; l'exécution réelle lit une fois les événements rattachés de l'espace. À revoir si `envoi_evenements` devient très gros.

## Tests
`tests/Feature/Envoi/CompteursCampagneTest.php` : cahier § 6.4, 1 à 12 (+ préparation), chacun sous `pgsql_app` ET sous le propriétaire, PostgreSQL réel.
Mutations jouées (chaque test rouge attendu) : sans DISTINCT ⇒ 4, 5, 10 ; priorité dur/mou retirée ⇒ 5 ; forme `id_injection_serveur` retirée ⇒ 4, 5 ; filtre d'espace de la jointure
`envoi_evenements` retiré ⇒ 8 ; `reserve` compté envoyé ⇒ 2 ; motif seul d'opposition ignoré ⇒ 6, 7.
**Mutant équivalent :** « filtre `statut = 'rattache'` retiré » ne peut pas être tué : la contrainte `envoi_evenements_rattache_coherent` impose `rattache ⇔ id_injection non nul`, donc un
événement `a_rattacher` n'a jamais d'identifiant à rattacher. Le filtre est gardé (défense en profondeur) ; le test 8 vérifie le comportement.
