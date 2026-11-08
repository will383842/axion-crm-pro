# Configuration du serveur d'envoi générée par le CRM (lot K18)

Ce dossier ne contient que des MODÈLES : aucune valeur réelle (dépôt public).

## Où tourne la commande

`php artisan envoi:config-appliquer {workspace} {parametres}` tourne **sur le CRM, jamais sur le serveur d'envoi**.
Le CRM ne pose aucun fichier sur le serveur d'envoi (autre machine). Il génère un paquet **par espace** depuis sa
base et le remet au serveur par l'adaptateur `ServeurEnvoiConfiguration` (contrat D4), c'est-à-dire par la
passerelle en HTTPS signé (ADR 34, D7). Jamais d'API publique, jamais de route : la commande est locale.

- `workspace` : UUID (minuscules) d'un espace présent dans `workspaces`.
- `parametres` : fichier JSON local, hors dépôt : par domaine, exactement `debit_heure` et `plafond_jour`
  (voir `modeles/espace.json.modele` pour la forme du paquet remis au serveur).
- Un domaine de la base sans paramètres est ignoré (listé dans la sortie, compté dans le motif) ; un paramètre pour
  un domaine absent de la base de l'espace est refusé.
- Un verrou par espace (`pg_try_advisory_lock`) empêche deux applications en parallèle.

## Ce que fait la commande

1. bornes de structure (profondeur 3, 50 domaines), garde du socle, validation stricte (liste blanche) ;
2. `validerConfiguration` : le serveur valide à côté, sans rien appliquer ;
3. `appliquerConfiguration` : succès = `appliquee` ; refus du serveur = `retour_arriere` (le serveur garde sa version
   précédente) ; toute autre erreur (transport, délai) = `incertaine` + alerte critique : l'état du serveur est à vérifier.

Chaque tentative laisse une ligne (empreinte + statut) dans `envoi_config_versions` (ajout seul).
Le retour arrière appartient au serveur : le CRM n'a pas de `retablir()`.

Le socle local (plafond dur, homme mort, mode témoins, liste blanche, registre, domaine principal) ne passe PAS par
ce canal : tout paquet qui le vise est refusé et déclenche une alerte de dérive.

## Pas de DKIM dans le paquet

Le paquet ne contient ni sélecteur ni clé. L'autorité DKIM est le serveur (fichier principal + `.pem`), pas le
paquet. Écart assumé avec la ligne K18 du plan 33, à faire acter.

## Dette nommée

K18 n'applique rien pour de vrai tant que K9-suite et K2-suite ne sont pas faits : la commande sort en échec
volontairement (« adaptateur de configuration absent (K9-suite) »).

- **K2-suite, passerelle** : routes `POST /v1/configuration/valider` et `POST /v1/configuration/appliquer`, signées HMAC ;
  un fichier par espace `/etc/axion-envoi/config/espaces/<uuid>.json` avec son `.precedent` ; écriture atomique :
  fichier temporaire dans le même dossier, fsync, copie du courant vers `.precedent` AVANT un rename unique ; ne
  rétablir que ce qui a été sauvegardé pendant cette tentative ; verrou par espace ; refus d'un domaine déjà tenu par
  un autre espace ou du domaine principal ; refus des clés du socle avec alerte de dérive.
- **K9-suite** : `ServeurEnvoiKumo` implémente et lie `ServeurEnvoiConfiguration` ; `clesPubliquesDkim()` validée comme
  clé PUBLIQUE RSA d'au moins 2048 bits (`openssl_pkey_get_public` / `openssl_pkey_get_details`) ; erreurs de transport =
  `incertaine`, refus du serveur = `ConfigurationRefusee`.
- **K4b** : `config.py` construit `registre` et `domaines_envoi` depuis les fichiers par espace ; un domaine présent
  dans deux fichiers bloque tout.
- **K8 (#477)** : le shaping lit `debit_heure` et `plafond_jour` des fichiers par espace ; plafond effectif = min(souhait, socle).
- **K5 / K12** : la seule source du sélecteur est le fichier principal ; le champ sélecteur de `ENVOI_DNS_DOMAINES` viendra de
  `clesPubliquesDkim()`.
