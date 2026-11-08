# Socle du serveur d'envoi (lot K6a)

Python 3.11, bibliothèque standard seule. **Aucun envoi réel, aucun dépôt de message** : le socle ne fait que **DÉCIDER** pour chaque message (`250` accepter, `451` reporter, `550` refuser). **KumoMTA dépose seul.** Le contenu des messages n'est jamais stocké (ni adresse, ni jeton en clair : empreintes seulement).

## Ce que décide le socle
- **Jeton à deux états** : `reserve` (écrit sur disque, fsync `synchronous=FULL`, AVANT le 250, avec sa place de plafond et un identifiant de message de 96 bits) puis `confirme` (posé plus tard par le log hook K7, hors de ce lot ; `Socle.confirmer(id)` est le point d'entrée local prévu, non exposé par `traiter`). Table `jetons` : empreinte SHA-256 du jeton, empreinte salée du message (pour détecter « même jeton, autre contenu » => 550 + alerte), espace, jour, état.
- **Rejeu** : `confirme` => 250, même identifiant, `rejoue=True`. `reserve` depuis **moins de 2 min** => 451 (un dépôt peut être en cours). `reserve` depuis **plus de 2 min** sans confirmation => accepté de nouveau, **même identifiant, même place de plafond** (pas de seconde place) ; pause, témoins et liste sont revérifiés à ce moment-là.
- **Plafond global ET par espace**, par journée civile Europe/Paris (journées de 23 h et 25 h gérées par `zoneinfo`). Atteint => `451 ... report`, jamais de perte (le client rejoue le même jeton le lendemain).
- **Fermé par défaut** : fichier absent, illisible, disque plein, exception => 451, mode témoins considéré actif. `Stockage.creer()` (installation seulement) démarre **en pause** (seule la commande `reprise` ouvre), témoins allumés, liste vide. Fichier d'état 0600, dossier 0700 s'il est créé.

## Horloge
Les **durées** (purge des jetons à 7 jours, nonces, homme mort, fenêtre de 2 min) se mesurent à l'horloge **monotone** (compteur `duree` persisté). L'horloge **murale** ne sert qu'au jour Europe/Paris. Un saut de l'horloge murale (avant ou arrière, écart > 60 s avec la monotone, ou murale antérieure à la dernière santé) **ferme** : `pause_hm=1` enregistré sur disque, aucun jeton réservé n'est effacé, aucun plafond n'est compté sur un jour futur. La commande `reprise` est **refusée (409)** tant que l'horloge n'est pas corrigée (si le processus a une référence saine). `duree` ne compte que le temps d'exécution : un arrêt du processus ne vieillit rien (purge plus tardive, jamais plus tôt).
Limite connue : après un redémarrage, le processus n'a plus de référence ; un saut survenu pendant l'arrêt est détecté seulement s'il rend la dernière santé > 15 min ou postérieure à l'horloge.

## Homme mort
`tick()` (à appeler par un fil de 30 s ; `lancer_minuterie()` le fait, branché au service en K6b) : dernière santé plus vieille que 15 min, ou saut d'horloge => `pause_hm=1` sur disque. `sante` ne réarme **jamais** ; seule la commande signée `reprise` lève la pause. Test : 1 h sans trafic, puis santé valide, puis envoi => 451.

## Requêtes signées (contrat v2)
Aucune commande « maison » : `traiter()` / `verifier_requete()` vérifient exactement `SignatureEnvoi::chargeRequete` : `METHODE\nchemin\nhorodatage\nnonce\nworkspace_id\ncorps`, HMAC-SHA256 hex, en-tête `k=<id>,sha256=<hex>` (hex nu et `sha256=` lus aussi), fenêtre 5 min, nonce UUID gardé 10 min **sur disque**, `workspace_id` UUID, secret vide => 401, comparaison en temps constant. Statuts : 200, 401, 404, 405, 409 (nonce rejoué), 422, 403, 503. Commandes : `POST /v1/commandes/sante|reprise|temoins`.
La CI lit `envoi-serveur/contrat/vecteurs-hmac.json` (le même fichier que le test Pest du CRM) : si la charge change d'un seul côté, le test rougit.

## Mode témoins (liste blanche d'EMPREINTES)
Le CRM envoie `modeTemoins(actif, liste)` (corps JSON `{"actif": bool, "liste": [hex64…]}`) avec des **empreintes** (HMAC-SHA256 de l'adresse normalisée, clé `ENVOI_CLE_EMPREINTE` du CRM), **jamais d'adresse en clair** ; 10 au plus. Normalisation identique à `backend/app/Envoi/EmpreinteTemoin.php` : minuscules, espaces retirés, domaine sans point final et en punycode. Vecteurs partagés testés des deux côtés (PHP et Python) : `envoi-serveur/contrat/vecteurs-empreintes-temoins.json`. Règle de casse : **par normalisation** (plus de comparaison à l'identique).
Quand le mode est **déjà actif**, une nouvelle liste du CRM ne peut que **réduire** (intersection) ; l'éteindre => 403 + alerte journalisée. **Agrandir ou éteindre** : console locale seulement (`axion_socle.local`, `definir_temoins_local`, `eteindre_temoins_local`, journal avec l'opérateur). `Socle.eteindre_temoins(...)` refuse toujours. `local.py` ne doit JAMAIS être importé par la couche réseau.

## Numérotation des scénarios (spec 26 l.265-267)
**S17 = homme mort, S18 = témoins, S19 = plafond.** (Le scénario S21 « arrêt brutal » est remplacé par le test du kill -9 entre réservation et 250 ; la preuve réelle reste une dette.)

## Dettes nommées (K6b / K4c / banc, volontairement NON codées ici)
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
