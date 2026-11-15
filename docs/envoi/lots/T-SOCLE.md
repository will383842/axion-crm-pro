# T-SOCLE — `GET /envoi/etat`, `GET /envoi/compteurs`, `POST /envoi/arret-general`

## Fait
- `EnvoiSocleController` : `etat`, `compteurs`, `arretGeneral`. Lectures en base seulement ; `ServeurEnvoi` n'est jamais résolu.
- `arretGeneral` (D1) : lecture de `toutArreter` puis `armerToutArreter` dans une transaction, sous `pg_advisory_xact_lock(hashtextextended('envoi_arret_general|<espace>', 0))` (clé distincte des verrous du feu vert). Deux appels simultanés : une seule ligne de journal `tout_arreter`, une seule alerte (émise après validation, seulement si l'arrêt vient d'être armé). Un second appel répond 200 sans écriture ni alerte.
- Permission `users.manage` (propriétaire, administrateur ; opérateur et lecteur : 403). Aucune pause globale `ServeurEnvoi::pause()` : seul le drapeau de l'espace courant est armé.
- Motif : texte de 200 caractères au plus, sans « @ » (sinon 422, rien n'est écrit). Il n'est recopié ni dans le journal ni dans l'alerte ; `motif` vaut donc `null` dans la réponse.
- Aucune route ne lève l'arrêt : la reprise reste la commande locale `envoi:reprendre`.
- `trous/socle.php` vidé ; instantanés `etat.json`, `compteurs.json`, `arret-general.json`.

## Choix de lecture
- `etat` : `arrete`/`rouge` si le drapeau est armé (depuis et par = dernière ligne `tout_arreter` du journal) ; sinon `actif` avec le feu de `VueSante`.
- `compteurs` : envoyés du jour = injections comptées (`injectesLe`), plafond = `plafondEspace`, en file = réservations du jour sans issue, réponses à traiter = réponses sans classement, alertes ouvertes = notifications Envoi non lues, campagnes `en_cours`, boîtes `active` / total.

## Risques
- `ENVOI_REEL_ACTIF=false` inchangé ; aucune migration. Deux fichiers hors liste du plan, déclarés ici : `backend/tests/Feature/Envoi/SocleFichiersCommunsTest.php` (test modifié, voir ci-dessous) et `backend/tests/Support/arret-general-concurrent.php` (sous-processus du test concurrent exigé, même modèle que `campagne-valider-concurrent.php`). Fichier commun touché en plus de `trous/socle.php` : `SocleFichiersCommunsTest.php`.

Test modifié : pourquoi — dans `SocleFichiersCommunsTest`, l'assertion transitoire « au moins un couple R19 est encore un trou » tombe, car T-SOCLE comble les derniers trous R19 (`trous/socle.php` vidé). Elle est remplacée par un témoin plus fort : chaque couple est un trou OU une route réelle, avec un témoin factorisé dans `couplesR19Perdus` qui rougit si un couple disparaît. Rien n'est affaibli.

## Tests
- `SocleRoutesTest` : contrat, cloisonnement par espace, idempotence, motif, 403, refus d'injection après l'arrêt, concurrence (3 processus réels), garde « aucune route de reprise ». Le test concurrent rougit si le verrou est retiré (vérifié).

## Reste (mineurs de l'audit et de la relecture, non traités ici)
- (a) `leverToutArreter` (`DepotSocleDb`, hors liste) doit prendre le même verrou consultatif `envoi_arret_general|<espace>` que l'armement, pour qu'un arrêt simultané d'une levée ne soit pas perdu.
- (b) L'alerte d'arrêt ne nomme pas l'espace, est écrite dans l'espace du destinataire principal, et `Regroupement::dejaVue` (clé produit|gravité|titre) peut absorber l'alerte d'un second espace.
- (c) Compteurs `en_file`, `reponses_a_traiter`, `alertes_ouvertes`, `campagnes_en_cours` peu testés ; `alertes_ouvertes` compte toutes les notifications non lues de l'espace.
- (d) Taille ~414 lignes (plafond 400), justifiée par les instantanés JSON.
- (e) Le test de refus d'injection n'appelle pas `retraitEnFileBranche`.
- (f) Le nettoyage du test concurrent utilise `session_replication_role=replica` sur `pgsql_owner` : ne pas recopier hors des tests.

## Tests lancés (local, PostgreSQL 16 + postgis + pgvector ; `pg_partman` absent)
- `vendor/bin/pest --filter='SocleRoutesTest|SocleFichiersCommunsTest|AucuneRouteTemoinSingulierTest'` : 17 passés (1121 assertions).
- `vendor/bin/pint --test` : passed.
- `vendor/bin/phpstan analyse` : [OK] No errors.

## Témoin des gardes
Ajout TEMPORAIRE de `Route::post('/envoi/reprendre', [EnvoiSocleController::class, 'arretGeneral']);` dans `routes/envoi/socle.php`, puis `AucuneRouteTemoinSingulierTest` : ROUGE.
```
FAIL  Tests\Feature\Envoi\Routes\AucuneRouteTemoinSingulierTest
Expecting 'api/v1/envoi/reprendre app\ht…eneral' not to contain 'reprendre'.
at tests/Feature/Envoi/Routes/AucuneRouteTemoinSingulierTest.php:14
Tests: 1 failed
```
Changement ANNULÉ (`git checkout routes/envoi/socle.php`) avant le commit.
