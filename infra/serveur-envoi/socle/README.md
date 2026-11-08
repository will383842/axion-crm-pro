# Socle du serveur d'envoi (lot K6a)

Python 3.11, bibliothèque standard seule. **Aucun envoi réel, aucun dépôt de message** : le socle ne fait que **DÉCIDER** pour chaque message (`250` accepter, `451` reporter, `550` refuser). **KumoMTA dépose seul.** Le contenu des messages n'est jamais stocké (ni adresse, ni jeton en clair : empreintes seulement).

## Ce que décide le socle
- **Jeton à deux états** : `reserve` (écrit sur disque, fsync `synchronous=FULL`, AVANT le 250, avec sa place de plafond et un identifiant de message de 96 bits) puis `confirme` (posé plus tard par le log hook K7, hors de ce lot ; `Socle.confirmer(id)` est le point d'entrée local prévu, non exposé par `traiter`). Table `jetons` : empreinte SHA-256 du jeton, empreinte salée du message (pour détecter « même jeton, autre contenu » => 550 + alerte), espace, jour, état.
- **Rejeu** : un rejeu n'est **jamais un passe-droit** : pause (homme mort), mode témoins et liste blanche sont contrôlés à **chaque** réponse, y compris pour un jeton déjà `confirme`. Le jeton appartient à **son espace** (autre espace => 550 + alerte).
  - `confirme` (et contrôles passés) => `250`, même identifiant, **`rejoue=true`**. **Contrat : `rejoue=true` signifie « répondre 250 au client SANS mettre en file »** : le message est jeté (file nulle), KumoMTA ne dépose rien, aucune place de plafond n'est consommée. Le relais Lua DOIT lire `rejoue` (voir `kumomta/socle_adaptateur.lua.modele`).
  - `reserve` depuis **moins de 2 min** => 451 (un dépôt peut être en cours).
  - `reserve` depuis **plus de 2 min** sans confirmation => `250` de nouveau, **même identifiant, même place de plafond**, **`rejoue=false`** : rien n'est confirmé, le message est à **déposer**. Borné à **3 ré-acceptations** par jeton ; la 4e demande reçoit un **451 définitif** (`4.7.2 … refus definitif`) et une ligne `jeton-reacceptations-max` au journal. Pause, témoins et liste sont revérifiés à chaque ré-acceptation.
  - **K7 confirme à la RÉCEPTION du message par KumoMTA, pas à la livraison** au destinataire : `confirme` veut dire « KumoMTA l'a pris en charge », rien de plus.
- **Plafond global ET par espace**, par journée civile Europe/Paris (journées de 23 h et 25 h gérées par `zoneinfo`). Atteint => `451 ... report`, jamais de perte (le client rejoue le même jeton le lendemain).
- **Fermé par défaut** : fichier absent, illisible, disque plein, exception => 451, mode témoins considéré actif. `Stockage.creer()` (installation seulement) démarre **en pause** (seule la commande `reprise` ouvre), témoins allumés, liste vide. Fichier d'état 0600, dossier 0700 s'il est créé.

## Horloge
Les **durées** (purge des jetons à 7 jours, nonces, homme mort, fenêtre de 2 min) se mesurent à l'horloge **monotone** (compteur `duree` persisté). L'horloge **murale** ne sert qu'au jour Europe/Paris. Un saut de l'horloge murale (avant ou arrière, écart > 60 s avec la monotone, ou murale antérieure à la dernière santé) **ferme** : `pause_hm=1` enregistré sur disque, aucun jeton réservé n'est effacé, aucun plafond n'est compté sur un jour futur. La commande `reprise` est **refusée (409)** tant que l'horloge n'est pas corrigée (si le processus a une référence saine). `duree` ne compte que le temps d'exécution : un arrêt du processus ne vieillit rien (purge plus tardive, jamais plus tôt).
Limite connue : après un redémarrage, le processus n'a plus de référence ; un saut survenu pendant l'arrêt est détecté seulement s'il rend la dernière santé > 15 min ou postérieure à l'horloge.

## Homme mort
`tick()` (à appeler par un fil de 30 s ; `lancer_minuterie()` le fait, branché au service en K6b) : dernière santé plus vieille que 15 min, ou saut d'horloge => `pause_hm=1` sur disque. `sante` ne réarme **jamais** ; seule la commande signée `reprise` lève la pause. Test : 1 h sans trafic, puis santé valide, puis envoi => 451.

## Requêtes signées (contrat v2)
Aucune commande « maison » : `traiter()` / `verifier_requete()` vérifient exactement `SignatureEnvoi::chargeRequete` : `METHODE\nchemin\nhorodatage\nnonce\nworkspace_id\ncorps`, HMAC-SHA256 hex, en-tête `k=<id>,sha256=<hex>` (hex nu et `sha256=` lus aussi), fenêtre 5 min, nonce UUID gardé 10 min **sur disque**, `workspace_id` UUID, secret vide => 401, comparaison en temps constant. Statuts : 200, 401, 403, 404, 405, 409 (nonce rejoué), 422, 500 (exception interne : fermé, rien n'est ouvert), 503. Commandes : `POST /v1/commandes/sante|reprise|temoins|pause`.
- **`pause`** (envoyée par `ClientServeurEnvoiHmac`, corps `{"commande_id": <uuid>, "commande": "pause"}`) : arrêt local écrit sur disque (`pause_hm=1`), **restrictif donc toujours permis** et idempotent ; seule `reprise` rouvre. Testée via `traiter()` avec le vecteur `/v1/commandes/pause`.
- **`retrait`** (aussi émise par le CRM) **relève de la passerelle K2**, pas du socle : le socle répond 404 (nonce consommé, aucun effet).
- **Signature de réponse** (contrat v2, `REPONSE\nnonce\nworkspace_id\nstatut\ncorps`) : `signature.signer_reponse()` est implémentée et testée contre les vecteurs `reponses`. Son **appel à chaque réponse HTTP** est fait par la couche réseau, **dette K6b**.
La CI lit `envoi-serveur/contrat/vecteurs-hmac.json` (le même fichier que le test Pest du CRM) : si la charge change d'un seul côté, le test rougit.

## Mode témoins (liste blanche d'EMPREINTES)
Le CRM envoie `modeTemoins(actif, liste)` (corps JSON `{"actif": bool, "liste": [hex64…]}`) avec des **empreintes** (HMAC-SHA256 de l'adresse normalisée, clé `ENVOI_CLE_EMPREINTE` du CRM), **jamais d'adresse en clair** ; 10 au plus. **Le mode est PAR ESPACE**, comme dans le CRM : drapeau (`temoins:<workspace_id>`) et liste (`liste_temoins:<workspace_id>`) propres à chaque `workspace_id` (celui de la requête signée) ; un espace jamais configuré est **fermé** (mode actif, liste vide : seule la valeur `0` ouvre). **Un refus témoins est un `451` (report), jamais un `550`** : un message n'est jamais perdu, il repart quand l'espace sort du mode témoins. Normalisation identique à `backend/app/Envoi/EmpreinteTemoin.php` : minuscules, espaces retirés, domaine sans point final et en punycode. Vecteurs partagés testés des deux côtés (PHP et Python) : `envoi-serveur/contrat/vecteurs-empreintes-temoins.json`. Règle de casse : **par normalisation** (plus de comparaison à l'identique). **Comparaison ASCII stricte à la décision** : une adresse destinataire non ASCII n'a pas d'empreinte, donc n'est jamais « sur la liste » (le signe KELVIN U+212A se replie sur `k` en minuscules Unicode). Un témoin IDN se compare en punycode. Le contrat partagé (vecteurs) et les empreintes déjà stockées ne changent pas.
Écart connu : Python (`encodings.idna`, IDNA2003) et PHP (`idn_to_ascii` UTS46, traitement transitoire par défaut) donnent le même résultat sur tous les vecteurs IDN du fichier partagé (ß, ς, ZWJ, pleine chasse, point idéographique) ; si le CRM passait un jour au traitement non transitoire, les empreintes changeraient : il faudrait alors une **commande de recalcul** (dette nommée K6b, ESCALADE à l'architecte, rien n'est recalculé ici).
Quand le mode est **déjà actif**, une nouvelle liste du CRM ne peut que **réduire** (intersection) ; l'éteindre => 403 + alerte journalisée. **Agrandir ou éteindre** : console locale seulement (`axion_socle.local`, `definir_temoins_local`, `eteindre_temoins_local`, journal avec l'opérateur). `Socle.eteindre_temoins(...)` refuse toujours. `local.py` ne doit JAMAIS être importé par la couche réseau.

## Numérotation des scénarios (spec 26 l.265-267)
**S17 = homme mort, S18 = témoins, S19 = plafond.** (Le scénario S21 « arrêt brutal » est remplacé par le test du kill -9 entre réservation et 250 ; la preuve réelle reste une dette.)

## Dettes nommées (K6b / K4c / banc, volontairement NON codées ici)
0. **K6b** : appel de `signer_reponse` sur chaque réponse HTTP ; commande de recalcul des empreintes si la normalisation IDN change ; retrait = passerelle K2.
1. **Gel de la file KumoMTA** : la pause refuse les nouvelles acceptations mais ne suspend pas ce que KumoMTA a déjà en file. Appel de l'API d'administration `suspend` => **K6b**.
2. **Idempotence réelle prouvée au banc avec `kill -9`** : ici seul le point d'arrêt simulé est testé ; il faut tuer KumoMTA et le socle en pleine charge et vérifier zéro perte, zéro doublon.
3. **Persistance des suspensions KumoMTA après redémarrage** : à vérifier ; la pause doit être réaffirmée au démarrage.
4. **Format du jeton à 128 bits** : à imposer côté source en **K4c** (ici : 32 à 128 caractères hex/base64url, entropie minimale).
5. Branchement du service (socket Unix, fil `tick`) et log hook K7 (`confirmer`) : K6b / K7.

## Amendement d'ADR nécessaire (ADR K0)
1. Le socle devient un **service Python local** (décideur), pas un module Lua.
2. **KumoMTA dépose seul** ; le socle ne reçoit ni ne stocke le contenu.
3. **La pause est une suspension réaffirmée, jamais vidée** (la file n'est ni purgée ni supprimée).
4. **Règle de casse des témoins établie par normalisation** (minuscules, espaces, point final, punycode), pas par comparaison à l'identique.
À soumettre au responsable du projet : ce lot ne modifie pas l'ADR.

## Relais Lua
`kumomta/socle_adaptateur.lua.modele` est une **ESQUISSE pour K6b**, non exécutée, sans valeur réelle : relais appelé depuis le gestionnaire `smtp_server_message_received` de K4a, vers le socle par socket Unix 0660 ; socle injoignable => 451.

CI : `.github/workflows/serveur-envoi-socle.yml`. Tests : `cd infra/serveur-envoi/socle && python3 -m unittest discover -s tests -t .`
