# PR 2 — clés Claude, bascule, client Haiku

## Correctifs de relecture
- Interrupteur `crm.enrichissement.payant_actif` lu avec `config(..., false)` (la clé arrive avec la PR #807, non ajoutée ici) : fermé par défaut, aucun appel payant (`ErreurClaude::PAYANT_INACTIF`).
- Réservation rendue (`$regler(..., 0)`) sur pause 429/529 et erreur API ; gardée seulement pour la coupure réseau.
- `ErreurClaude` déplacée dans `app/Crm/Claude/ErreurClaude.php` (PSR-4, `--classmap-authoritative`).
- Boucle de bascule bornée au nombre d'alias valides.
- Réserve de la recherche web portée à 50 000 jetons (option la plus prudente pour l'argent).
- Tests : assertions réelles sur `->type`, plafond `->toBe($alias)`, noms globaux préfixés `claude…`, nouveaux tests (interrupteur fermé, réservation rendue).
- Migration : à confirmer en CI, instantanés NR-17/NR-20. Mentionner « Filet modifié » si l'un bouge.

## Fichiers autorisés
`backend/app/Crm/Claude/ClientClaude.php`, `backend/app/Crm/Claude/ErreurClaude.php`, `backend/tests/Unit/Claude/ClientClaudeTest.php`, ce fichier.

## Non vérifié
Pas de `vendor/` dans cet environnement : Pint, PHPStan et Pest n'ont pas pu être lancés (seulement `php -l`).
