#!/usr/bin/env bash
# Ferme l'accès INTERNET à Postgres (5432) et Redis (6379) sur le serveur de
# production, sans couper le service.
#
# ── Pourquoi ce script existe ───────────────────────────────────────────────
# Mesuré le 2026-08-19 : `docker-compose.yml` publie 55432 et 56379 pour le
# confort du poste de développement. En production ces ports écoutent sur
# 0.0.0.0. `ufw status` annonçait « 22/80/443 seulement » — et la chaîne DOCKER
# d'iptables, qui passe AVANT ufw, acceptait `0.0.0.0/0 -> 5432`.
# Vérifié depuis un poste extérieur : connexion SUPERUTILISATEUR à la base de
# production avec un mot de passe publié dans un dépôt public.
#
# Le correctif de fond est `ports: !override []` dans `docker-compose.prod.yml`
# (commit a064fd4) — il supprime la publication à la source. Ce script est le
# PANSEMENT à poser d'abord : il agit tout de suite, sans redéployer.
#
# ── Pourquoi `DOCKER-USER` ──────────────────────────────────────────────────
# C'est la seule chaîne qu'iptables consulte AVANT les règles que Docker
# réécrit lui-même, et elle survit au redémarrage des conteneurs. Une règle
# posée dans `ufw` n'aurait aucun effet : c'est exactement le piège d'origine.
#
# ── Pourquoi le service n'est pas coupé ─────────────────────────────────────
# `! -s <sous-réseau du bridge>` laisse passer le trafic entre conteneurs.
# L'API, Horizon et le scheduler joignent Postgres par le réseau Docker : ils
# ne sont pas concernés. Seul l'accès depuis l'extérieur tombe.
#
# Usage, EN ROOT sur le serveur de production :
#   bash fermer-ports-db-prod.sh
# Annulation :
#   bash fermer-ports-db-prod.sh --annuler
set -uo pipefail

ANNULER=0
[ "${1:-}" = "--annuler" ] && ANNULER=1

if [ "$(id -u)" -ne 0 ]; then
  echo "ERREUR : à lancer en root (iptables)." >&2
  exit 2
fi

# Le sous-réseau du bridge est LU, jamais supposé : le coder en dur ferait
# tomber le trafic entre conteneurs le jour où Docker en attribue un autre.
RESEAU="$(docker network inspect axion-crm --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null)"
if [ -z "$RESEAU" ]; then
  echo "ERREUR : réseau docker « axion-crm » introuvable — es-tu sur le bon serveur ?" >&2
  exit 2
fi
echo "Sous-réseau des conteneurs : $RESEAU"

PORTS="5432 6379"

if [ "$ANNULER" -eq 1 ]; then
  for port in $PORTS; do
    while iptables -C DOCKER-USER -p tcp --dport "$port" ! -s "$RESEAU" -j DROP 2>/dev/null; do
      iptables -D DOCKER-USER -p tcp --dport "$port" ! -s "$RESEAU" -j DROP
      echo "règle retirée : port $port"
    done
  done
else
  for port in $PORTS; do
    # Idempotent : `-C` teste la présence avant d'insérer. Rejouer le script ne
    # doit pas empiler dix fois la même règle.
    if iptables -C DOCKER-USER -p tcp --dport "$port" ! -s "$RESEAU" -j DROP 2>/dev/null; then
      echo "déjà en place : port $port"
    else
      iptables -I DOCKER-USER 1 -p tcp --dport "$port" ! -s "$RESEAU" -j DROP
      echo "règle posée : port $port"
    fi
  done
fi

echo
echo "=== état de la chaîne DOCKER-USER ==="
iptables -L DOCKER-USER -n -v --line-numbers

# ── Vérification, sur la machine ────────────────────────────────────────────
# Un script qui pose une règle et annonce « c'est fait » sans regarder ne prouve
# rien. On compte les règles réellement présentes.
if [ "$ANNULER" -eq 0 ]; then
  echo
  manquantes=""
  for port in $PORTS; do
    iptables -C DOCKER-USER -p tcp --dport "$port" ! -s "$RESEAU" -j DROP 2>/dev/null \
      || manquantes="$manquantes $port"
  done
  if [ -n "$manquantes" ]; then
    echo "ÉCHEC : règles absentes pour les ports :$manquantes" >&2
    exit 1
  fi

  # Le service doit continuer de répondre — le but est de fermer l'extérieur,
  # pas de casser la production.
  if curl -fsS --max-time 10 http://127.0.0.1/up > /dev/null 2>&1 \
     || curl -fsS --max-time 10 -k https://127.0.0.1/up > /dev/null 2>&1; then
    echo "OK : l'API répond toujours."
  else
    echo "⚠️  L'API ne répond pas à /up depuis le serveur — à vérifier À LA MAIN."
    echo "    Annulation immédiate si besoin : bash $0 --annuler"
  fi

  echo
  echo "Règles posées. Elles disparaîtront au REDÉMARRAGE du serveur."
  echo "Pour les rendre persistantes :"
  echo "    apt-get install -y iptables-persistent && netfilter-persistent save"
  echo
  echo "⚠️  Ceci n'est qu'un pansement. Le correctif de fond est le commit"
  echo "    a064fd4 (ports: !override [] dans docker-compose.prod.yml) : à déployer."
  echo
  echo "À VÉRIFIER DEPUIS UN POSTE EXTÉRIEUR, pas depuis ce serveur :"
  echo "    nc -zv -w5 46.62.248.239 55432   # doit échouer"
  echo "    nc -zv -w5 46.62.248.239 56379   # doit échouer"
fi
