# C4-B — câblage de `KumoHttp` dans la passerelle (inerte par défaut) et mesure `file_age_s`

Cahier : FINI-cahier.md, PR #680, section C4-B. Dossier `infra/serveur-envoi/passerelle/` : `public/index.php` (M),
`src/Kumo.php` (M, docblock), `src/Sante/MesureurSysteme.php` (M), `tests/CablageKumoTest.php` (C), ce compte rendu (C).

## Comportement
- `choisirKumo(array $env, \Closure $stockage): Kumo` (dans `index.php`) : `KumoHttp` seulement si `PASSERELLE_KUMO_URL`
  est non vide ET `ENVOI_REEL_ACTIF === 'true'` exactement (lecture seule). Sinon `KumoInactif` (comportement de `main`).
  Hôte non local ⇒ `InvalidArgumentException` ⇒ l'index répond 500 `{"erreur":"configuration_kumo"}` (échec fermé).
  Le second paramètre (fabrique du stockage) s'ajoute à la signature du cahier : `KumoHttp` exige un `Stockage`.
- Constante `PASSERELLE_SANS_EXECUTION` : les tests chargent `index.php` pour la seule fonction (aucun effet en production).
- `MesureurSysteme` : nouveau paramètre final `?string $kumoBase`. `file_age_s` lu sur `GET /metrics.json` (127.0.0.1 /
  localhost seulement, 1 s + 2 s) ; toute erreur, valeur non numérique, négative ou > 1 an ⇒ clé omise.
  `KumoHttp.php` n'étant pas un fichier autorisé, la lecture est faite par le mesureur lui-même ; l'index ne lui donne
  l'URL que si `KumoHttp` est choisi.
- ESCALADE E5 (défaut appliqué) : clé lue `oldest_message_age_s`, à confirmer sur l'image KumoMTA épinglée.
- Le faux KumoMTA de C4-A ne sert pas `metrics.json` (fichier non autorisé) : le test écrit son propre mini-routeur temporaire.

## Tests rouges d'abord
Avant le code : `CablageKumoTest` s'arrête net (`index.php` s'exécute et répond `{"erreur":"non_autorise"}`, la fonction
`choisirKumo` n'existe pas) : rouge. Après : `OK (9 tests, 29 assertions)` ; suite passerelle entière : `OK (234 tests, 873 assertions)`.

## Mutation
`ENVOI_REEL_ACTIF=1` accepté ⇒ `test_url_posee_mais_envoi_reel_pas_exactement_true_kumo_inactif` rouge
(`Tests: 9, Assertions: 23, Failures: 1`). Mutation annulée, 9 verts.

## Notes
Pas de base Postgres (SQLite de la passerelle) : pas de double connexion `pgsql_app`/propriétaire ; aucun test Pest
local nécessaire. Aucun réseau réel (127.0.0.1). Aucun drapeau touché (`ENVOI_REEL_ACTIF` seulement lu).
Local : `vendor/` de la passerelle absent ; tests lancés avec le phpunit de `backend/vendor` et un autoload jetable
(hors dépôt). La CI (`passerelle-envoi.yml`) fait foi. Aucun test long : CI = preuve du vert.
