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
- `ENVOI_REEL_ACTIF=false` inchangé ; aucune migration ; aucun fichier commun touché hors `trous/socle.php`.

## Tests
- `SocleRoutesTest` : contrat, cloisonnement par espace, idempotence, motif, 403, refus d'injection après l'arrêt, concurrence (3 processus réels), garde « aucune route de reprise ». Le test concurrent rougit si le verrou est retiré (vérifié).
