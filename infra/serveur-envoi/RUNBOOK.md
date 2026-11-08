# Runbook — serveur d'envoi (lot K3)

Serveur Hetzner **distinct** de la prod du CRM. Dossier public : aucune IP, aucun secret ici, seulement des marqueurs (`<ip-gestion>`, `<ip-envoi>`, `<ip-crm-prod>`, `<ip-tunnel>`…).

## Décision D7 (ADR) — à respecter
**Aucun tunnel WireGuard vers la prod du CRM, jamais.** Le lien CRM → serveur d'envoi est un **HTTPS signé HMAC**, ouvert par le CRM vers `<ip-gestion>:443` (la passerelle). WireGuard ne sert **qu'à l'administration** : son seul pair est le poste d'administration.

## D5 — quelle IP est la « gestion » (source par défaut)
`<ip-gestion>` est l'**IPv4 principale** du serveur : c'est l'adresse **source par défaut** de tout le trafic de l'hôte (DNS, NTP, HTTPS 443 pour les images et les paquets, WireGuard). Les règles de sortie sont écrites avec `ip saddr <ip-gestion>` : si `<ip-gestion>` n'était pas l'IP source par défaut, ces flux seraient bloqués. L'**IP flottante d'envoi** (`<ip-envoi>`) est **ajoutée en alias sur l'interface** (jamais en route par défaut) : KumoMTA (`network_mode: host`) s'y lie explicitement pour le SMTP sortant. Vérifier : `ip -4 route get <ip-resolveur>` doit afficher `src <ip-gestion>` (jamais `<ip-envoi>`).

## Deux IP
- `<ip-gestion>` : passerelle HTTPS 443 (depuis `<ip-crm-prod>` uniquement) + WireGuard d'administration (UDP).
- `<ip-envoi>` : **aucune entrée** ; sortie limitée au port 25 TCP (SMTP vers les MX).

## Installer (une fois)
**Ubuntu 24.04 uniquement** : l'installateur refuse un autre système (le paquet `docker-compose-v2` n'existe pas sous Debian).
1. Créer `/etc/axion-envoi/valeurs.env` (hors dépôt) : `IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN`. **Forme exigée** (sinon refus, rien n'est modifié) : une **IPv4 unique** par valeur (jamais de `/masque`, de plage, de liste, ni adresse générique), `PORT_WG` entier 1–65535, `CLE_PUBLIQUE_ADMIN` clé WireGuard base64 de 44 caractères ; `IP_ENVOI` ≠ `IP_GESTION`.
2. `./installer.sh` (à blanc : valide la forme, **rend les 4 modèles** dans un dossier temporaire et repasse le pare-feu rendu par `tests/verifier-nftables.sh --rendu`), relire, puis `./installer.sh --oui` (qui ajoute `nft -c` avant toute charge). Relançable sans danger : sur relance, `kumomta-envoi` est **redémarré** si `init.lua`, `docker-compose.yml` ou l'unité ont changé, et `wg-quick@wg0` est **rechargé** (sans couper le tunnel) si `wg0.conf` a changé. L'image Docker est tirée **avant** le chargement du pare-feu.
3. Donner au poste d'administration la clé publique du serveur : `wg pubkey < /etc/wireguard/cle-privee` (la clé privée ne quitte jamais le serveur, n'est jamais passée en argument de commande).
4. **Garder la console de secours Hetzner ouverte** tant que SSH-par-tunnel n'est pas vérifié.

### SSH et Ubuntu 24.04 (`ssh.socket`)
Sur Ubuntu 24.04, sshd est lancé par **`ssh.socket`**, qui ignore `ListenAddress`. L'installateur désactive `ssh.socket` et active `ssh.service` (une fois `wg0` monté), et pose un drop-in `After=wg-quick@wg0.service` pour que l'IP du tunnel existe avant le bind de sshd. Vérifier : `ss -ltnp | grep ':22 '` ne doit montrer que `<ip-tunnel>`.

## APT et port 80 sortant (fermé)
Le **port 80 sortant est fermé** : les dépôts Ubuntu sont par défaut en `http://`, donc `apt` et `unattended-upgrades` seraient bloqués (et une relance de `installer.sh` échouerait). L'installateur passe donc les sources APT en **https** (`/etc/apt/sources.list` et `sources.list.d/*.list|*.sources`) **avant** tout `apt-get update`, de façon idempotente. Si une source tierce n'offre pas https, ne PAS ouvrir le 80 : la retirer. Les miroirs Ubuntu officiels et ceux de l'hébergeur servent le https sur 443, déjà ouvert depuis `<ip-gestion>`. Vérifier : `grep -rE '^(deb|URIs:).*http://' /etc/apt` ne doit rien afficher.

## IPv6 et résolveur DNS
- **IPv6** : le jeu de règles (`table inet`, politique `drop`, aucune règle ICMPv6/ND) rend **IPv6 inutilisable** : pas de découverte de voisins, donc aucune adresse IPv6 n'est joignable ni utilisable. C'est **voulu** (surface minimale ; IPv4 seul). Ne pas activer l'IPv6 sur l'interface sans ajouter, dans une PR dédiée, seulement l'ICMPv6/ND nécessaire (types 133–137, entrée et sortie).
- **Résolveur** : seul `IP_RESOLVEUR` est joignable en DNS (UDP/TCP 53, depuis `<ip-gestion>`). Faire pointer le résolveur du système dessus : `systemd-resolved` avec `DNS=<ip-resolveur>` et `Domains=~.` dans `/etc/systemd/resolved.conf.d/10-axion.conf` (le stub local de `systemd-resolved` passe par `lo`, déjà accepté), puis `resolvectl status` doit montrer cette seule IP. Un résolveur non listé serait bloqué en silence.

## Surface exposée
Entrée : `<ip-crm-prod>` → `<ip-gestion>:443` ; UDP WireGuard vers `<ip-gestion>` ; SSH (22) uniquement via `wg0` ; **rien** vers `<ip-envoi>`. API KumoMTA : `127.0.0.1:8000` (`trusted_hosts` = localhost).
Sortie : port 25 seulement depuis `<ip-envoi>` ; depuis `<ip-gestion>` seulement WireGuard, DNS, HTTPS 443 et NTP (UDP 123).

**Horloge (NTP)** : la signature HMAC tolère 5 min d'écart. Garder `systemd-timesyncd` (ou chrony) actif ; l'UDP 123 sortant est ouvert pour cela. Vérifier : `timedatectl show -p NTPSynchronized`.

## Sonde externe (à faire après chaque changement de pare-feu)
Depuis un hôte HORS du serveur, aucun port ne doit répondre sauf ceux prévus :
```
# depuis <ip-crm-prod> : seul 443 sur <ip-gestion> ; rien sur <ip-envoi>
nmap -Pn -p- <ip-gestion>      # attendu : 443/tcp seulement
nmap -Pn -p- <ip-envoi>        # attendu : tout filtré
# depuis n'importe où ailleurs : <ip-gestion> ne doit rien montrer en TCP
nmap -Pn -p- <ip-gestion>      # attendu : aucun port TCP ouvert
nmap -Pn -sU -p <port-wg> <ip-gestion>   # WireGuard ne répond pas sans clé (open|filtered)
```

## Redémarrer proprement
`systemctl restart kumomta-envoi` : arrêt (`compose down`, 60 s de grâce pour vider les files), puis relance. L'unité est `Type=simple` (compose au premier plan) avec `Restart=on-failure`, et le conteneur `restart: unless-stopped` : deux niveaux cohérents. Ordre garanti après `wg0` et `nftables`. Vérifier : `curl -s http://127.0.0.1:8000/api/check-liveness/`.

## Vérifier le durcissement (`cap_drop: ALL`)
Au premier démarrage : `docker inspect --format '{{.HostConfig.CapDrop}}' <conteneur>` doit afficher `[ALL]`, puis la liveness ci-dessus doit répondre. Si KumoMTA refuse de démarrer sans une capacité précise, la nommer dans une PR dédiée (jamais `privileged`).

## `flush ruleset`
`nftables.conf` commence par `flush ruleset` : il efface **tout** le jeu de règles, y compris celles de Docker (iptables-nft). Sans impact ici (`network_mode: host`, aucun port publié). Si un port est publié un jour, relancer docker après le chargement du pare-feu.

## Changer l'image
Relever la nouvelle empreinte `sha256:`, la remplacer dans `docker-compose.yml`, relancer `tests/lancer-tests.sh`, PR, puis `installer.sh --oui`.

## DKIM (K5) : clés, groupe, rotation

Le serveur signe seul (`kumomta/dkim.lua`, chargé par `init.lua`) ; le CRM ne détient aucune clé privée. Sans clé lisible, le message est refusé en 451 (jamais envoyé non signé). Un message injecté qui porte déjà une `DKIM-Signature` est refusé en 550 par l'écouteur d'injection.

- **Préparer** : `install -d -m 0755 /etc/axion-envoi` (l'installeur le fait). Créer un groupe dédié de GID `10025` (le même que `group_add` dans `docker-compose.yml`) : `groupadd -g 10025 axion-dkim`.
- **Créer** : `dkim/generer-dkim.sh --domaine D --selecteur S --secours S2 --groupe axion-dkim`. Clés `0640 root:axion-dkim`, dossiers `0750` ; sans `--groupe` : `0600` / `0700`. Les TXT publics (noms absolus, point final) sont affichés ; publier les DEUX avant d'envoyer.
- **Dossier** : le script ne change les droits QUE de ce qu'il crée. Un `--repertoire` existant doit être vide ou déjà géré par le script (marqueur `.axion-dkim`), sinon refus. Un nom de dossier commençant par « - » s'écrit `./-nom`.
- **Rotation** : `dkim/generer-dkim.sh --rotation --domaine D --secours NOUVEAU [--groupe axion-dkim]`. Le secours déjà publié devient principal, UN nouveau secours est généré (publier son TXT), le principal actuel n'est pas régénéré. L'ancienne clé principale reste sur disque : la supprimer à la main après expiration du TTL DNS.
- **Concurrence / panne** : création de dossier atomique (un seul lancement gagne), écriture sans écrasement (`noclobber`), en cas d'échec tout ce que le lancement a créé est retiré (aucune clé ni fichier temporaire).
- **Banc** : l'accepteur de la liste `h=` répétée (sur-signature de From, Subject, List-Unsubscribe) dépend de la version de KumoMTA : à vérifier au banc avec un vérificateur DKIM indépendant avant tout envoi.

## Hors périmètre K3 (restant)
Passerelle HTTPS HMAC elle-même (écoute 443) et son **AC privée** (autorité de certification interne qui signe le certificat de la passerelle et que le CRM épingle : non livrée ici), injection CRM→KumoMTA, DKIM/SPF/DMARC, réputation et chauffe de l'IP, supervision.
