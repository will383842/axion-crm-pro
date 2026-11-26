# Correctifs de relecture (PR #809)

- Test « plafond du jour » : on sème 14 M sur le jour courant, 1 M + 1 refusé, 1 M accepté (ne dépend plus du plafond d'exécution).
- Verrou : test `DB::listen` qui vérifie que `pg_advisory_xact_lock` est demandé à chaque réservation et ajustement.
- Interrupteur : `reserver()` lit `config('crm.enrichissement.payant_actif', false)` et refuse si fermé. La clé n'est PAS ajoutée à `config/crm.php` (elle arrive avec la PR #807) ; défaut fermé = aucun appel payant. Test du refus ajouté.
- `ajuster()` : `Log::warning` si l'UPDATE ne touche aucune ligne ; test ajouté.
- Coût réel > maximum réservé : accepté et compté (l'argent est dépensé), documenté et testé. Ensuite toute nouvelle réservation est refusée.
- Choix prudent : en cas de doute sur la clé, le refus l'emporte.

Vérifications : `php -l` OK. `vendor/` absent dans cet environnement : Pint, PHPStan et Pest n'ont pas pu tourner ici ; à vérifier par la CI.

## Correctifs CI (filet de non-régression)

- NR-17 : le `down()` de `2026_12_13_000100_depenses_claude_jour.php` REFUSE (exception `RuntimeException`) si `depenses_claude_jour` ou les quatre colonnes de `llm_usage` contiennent des données, sur le modèle de `brave_quota_mensuel` ; vides, il les supprime pour de vrai. Aucune dépense n'est jamais effacée.
- Filet modifié : pourquoi → `brave_quota_mensuel` passe NR-17 parce qu'elle est ANCIENNE : son retour « refuse » est dans l'instantané `nr17-migrations-sans-retour.json`, qui ne peut que baisser. La nôtre est NOUVELLE (exige un retour réel). Ajout dans `Nr17MigrationsTest.php` d'une liste fermée `NR17_RETOUR_REFUSE_SI_DONNEES` (un seul fichier) : on exige le classement « refuse » ET l'aller-retour-aller réel sur base vide, plus un test de fermeture de la liste. L'instantané n'est pas modifié.
- NR-16 : instantané `nr16-drapeaux.json` mis à jour, 6 ajouts `CRM_CLAUDE_PLAFOND_*_USD`. Ils n'ont aucun défaut (`null` = plafond codé en dur, qu'ils ne peuvent qu'abaisser) : rien de payant ne s'ouvre.
- Filet modifié : pourquoi → ajout de variables de config voulu par ce lot, instantané régénéré en local (`CI=` vidé pour le geste), diff en clair.
- NR-17 aller-retour-aller non joué ici : pas de base PostgreSQL disponible.
