# C4-A — `KumoHttp`, adaptateur de la passerelle vers l'API KumoMTA (faux serveur seulement)

Cahier : FINI-cahier.md, PR #680, section C4-A. Fichiers : `infra/serveur-envoi/passerelle/` `src/KumoHttp.php` (C),
`src/Stockage.php` (M : table `suspensions`, ajout seul), `tests/KumoHttpTest.php` (C), `tests/faux-kumomta/routeur.php` (C).
Non touchés : `public/index.php`, `src/Kumo.php` (câblage = C4-B). Inerte : rien ne construit `KumoHttp`.

## Écarts avec le cahier (à traiter dans C4-B)
- Le contrat réel est `Kumo::executer($ws, $operation, $params)` (pas de méthodes `injecter`/`pause`…).
- `Passerelle::injecter` n'envoie pas `id_injection` dans `$params` : `KumoHttp` l'utilise s'il est fourni
  (en-tête `X-Campaign`), sinon l'omet. **Tant que C4-B ne le passe pas, le retrait par `campaign` ne rebondit rien.**
- `delaiMaximal()` rend 5 (borne totale du client, < `DELAI_MAX_SECONDES`).
- Noms de champs de l'API (inject/suspend/bounce) : hypothèse ESCALADE E5 (non bloquante), à vérifier sur l'image épinglée.

## Tests rouges d'abord
Avant `KumoHttp` : `Tests: 12, Assertions: 1, Errors: 11, Failures: 1` (`Class "Passerelle\KumoHttp" not found`).
Après : `OK (12 tests, 35 assertions)`. Suite passerelle entière : 224 tests, 2 échecs hors lot
(`AuditK2Test:78`, `DurcissementTest:230`) : `public/index.php` exige `vendor/autoload.php`, absent ici
(`composer install` coupé par le proxy) ; tests lancés avec le phpunit de `backend/vendor`.

## Mutations
1. Filtre `workspace_id` retiré de `suspensionsActives` ⇒ `test_reprise_de_b_ne_leve_pas_la_suspension_de_a` rouge (1 échec).
2. Garde « cibles vides » retirée ⇒ `test_retrait_liste_vide_exception_zero_requete` rouge (1 échec). Mutations annulées, 12 verts.
(Une première version du test 2 laissait la mutation survivre : `fail()` de PHPUnit est une `RuntimeException` ; corrigé.)

## Notes
Aucun réseau réel : faux serveur sur 127.0.0.1, port libre. Pas de base Postgres (SQLite de la passerelle), donc pas de
double connexion `pgsql_app`/propriétaire ; le cloisonnement par espace est prouvé par le test A/B. `pint` : style
du backend, non appliqué à la passerelle.
- Correctif relecture : `pause` appelle TOUJOURS `POST /api/admin/suspend/v1` (le miroir local peut être périmé après 30 j ou un redémarrage de KumoMTA) ; deux pauses = deux suspensions, toutes levées par `reprise`.
