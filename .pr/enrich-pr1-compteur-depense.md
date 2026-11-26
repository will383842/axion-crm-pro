# Correctifs de relecture (PR #809)

- Test « plafond du jour » : on sème 14 M sur le jour courant, 1 M + 1 refusé, 1 M accepté (ne dépend plus du plafond d'exécution).
- Verrou : test `DB::listen` qui vérifie que `pg_advisory_xact_lock` est demandé à chaque réservation et ajustement.
- Interrupteur : `reserver()` lit `config('crm.enrichissement.payant_actif', false)` et refuse si fermé. La clé n'est PAS ajoutée à `config/crm.php` (elle arrive avec la PR #807) ; défaut fermé = aucun appel payant. Test du refus ajouté.
- `ajuster()` : `Log::warning` si l'UPDATE ne touche aucune ligne ; test ajouté.
- Coût réel > maximum réservé : accepté et compté (l'argent est dépensé), documenté et testé. Ensuite toute nouvelle réservation est refusée.
- Choix prudent : en cas de doute sur la clé, le refus l'emporte.

Vérifications : `php -l` OK. `vendor/` absent dans cet environnement : Pint, PHPStan et Pest n'ont pas pu tourner ici ; à vérifier par la CI.
