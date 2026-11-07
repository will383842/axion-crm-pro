# Runbook — serveur d'envoi (lot K3)

Serveur Hetzner **distinct** de la prod du CRM. Dossier public : aucune IP, aucun secret ici, seulement des marqueurs (`<ip-gestion>`, `<ip-envoi>`, `<ip-crm-prod>`, `<ip-tunnel>`…).

## Décision D7 (ADR) — à respecter
**Aucun tunnel WireGuard vers la prod du CRM, jamais.** Le lien CRM → serveur d'envoi est un **HTTPS signé HMAC**, ouvert par le CRM vers `<ip-gestion>:443` (la passerelle). WireGuard ne sert **qu'à l'administration** : son seul pair est le poste d'administration.

## Deux IP
- `<ip-gestion>` : passerelle HTTPS 443 (depuis `<ip-crm-prod>` uniquement) + WireGuard d'administration (UDP).
- `<ip-envoi>` : **aucune entrée** ; sortie limitée au port 25 TCP (SMTP vers les MX).

## Installer (une fois)
1. Créer `/etc/axion-envoi/valeurs.env` (hors dépôt) : `IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN`.
2. `./installer.sh` (à blanc), relire, puis `./installer.sh --oui`. Relançable sans danger. L'image Docker est tirée **avant** le chargement du pare-feu.
3. Donner au poste d'administration la clé publique du serveur : `wg pubkey < /etc/wireguard/cle-privee` (la clé privée ne quitte jamais le serveur, n'est jamais passée en argument de commande).
4. **Garder la console de secours Hetzner ouverte** tant que SSH-par-tunnel n'est pas vérifié.

### SSH et Ubuntu 24.04 (`ssh.socket`)
Sur Ubuntu 24.04, sshd est lancé par **`ssh.socket`**, qui ignore `ListenAddress`. L'installateur désactive `ssh.socket` et active `ssh.service` (une fois `wg0` monté), et pose un drop-in `After=wg-quick@wg0.service` pour que l'IP du tunnel existe avant le bind de sshd. Vérifier : `ss -ltnp | grep ':22 '` ne doit montrer que `<ip-tunnel>`.

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

## Hors périmètre K3 (restant)
Passerelle HTTPS HMAC elle-même (écoute 443), injection CRM→KumoMTA, DKIM/SPF/DMARC, réputation et chauffe de l'IP, supervision.
