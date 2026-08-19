#!/usr/bin/env bash
# Vérifie que la pile de production, TELLE QU'ELLE TOURNE, ne publie sur
# l'extérieur que 80 et 443.
#
# ── Pourquoi ce script existe ───────────────────────────────────────────────
# Le 2026-08-19, la garde CI `config-prod` était VERTE, le déploiement était
# VERT, et `docker ps` sur la production montrait :
#
#   axion-crm-postgres :: 0.0.0.0:55432->5432/tcp
#   axion-crm-redis    :: 0.0.0.0:56379->6379/tcp
#
# La configuration fusionnée, elle, ne publiait bien que 80 et 443 : le
# correctif `ports: !override []` était juste. Mais
# `.github/workflows/deploy-direct-ssh.yml` ne recrée que
# `api app horizon scheduler`, avec `--no-deps` — choix délibéré pour ne pas
# redémarrer la base à chaque mise en production. Conséquence jamais tirée :
# une modification de `docker-compose*.yml` portant sur `postgres`, `redis` ou
# `reverb` est INAPPLICABLE par le déploiement. Ces conteneurs gardent la
# configuration de leur création, indéfiniment.
#
# `config-prod` prouve que le FICHIER est juste. Ce script prouve que le RÉEL
# l'est. Entre les deux il y a un déploiement qui, pour ces services, ne fait
# rien — et c'est dans cet écart qu'une base de 4,29 M de fiches est restée
# ouverte sur internet.
#
# Règle à retenir : une garde ne vaut que si elle rougit SUR L'OBJET QUI CASSE.
#
# Usage, sur le serveur de production :
#   bash verifier-ports-publies.sh
# Sortie 0 = conforme · 1 = un port interdit est publié · 2 = mesure impossible
set -uo pipefail

PROJET="${1:-axion-crm-pro}"
# Second argument : la liste des ports autorisés, pour pouvoir ÉPROUVER ce
# script sur une pile jetable sans réquisitionner 80 et 443 de la machine.
# Sans lui, ce contrôle ne serait testable qu'en production — c'est-à-dire
# jamais avant qu'il soit trop tard.
# ⚠️ `${2-...}` et NON `${2:-...}`. Avec les deux-points, bash retombe sur le
# défaut quand l'argument est vide AUTANT que quand il est absent — et
# « aucun port public autorisé », qui est le cas de la préproduction, devenait
# silencieusement « 80 et 443 autorisés ». Mesuré : la garde rougissait sur la
# préproduction en annonçant « ports attendus absents : 80 443 ».
AUTORISES="${2-80 443}"

if ! command -v docker > /dev/null 2>&1; then
  echo "ERREUR : docker introuvable — mesure impossible." >&2
  exit 2
fi

# `docker ps` du projet Compose. On lit les ports RÉELLEMENT publiés par le
# démon, pas ce que le fichier déclare : c'est tout l'objet du contrôle.
LIGNES="$(docker ps --filter "label=com.docker.compose.project=${PROJET}" \
  --format '{{.Names}}|{{.Ports}}' 2>/dev/null)"

if [ -z "$LIGNES" ]; then
  # Témoin négatif : « aucun port interdit » ne vaut rien si la mesure n'a rien
  # regardé. Sans conteneur trouvé, on échoue au lieu de rassurer.
  echo "ERREUR : aucun conteneur du projet « ${PROJET} » — la mesure n'a rien vu." >&2
  echo "         (un résultat « conforme » sur zéro conteneur serait un mensonge)" >&2
  exit 2
fi

echo "=== ports publiés par la pile « ${PROJET} » ==="
echo "$LIGNES" | tr '|' ' '
echo

# On ne retient que les publications ATTEIGNABLES DEPUIS INTERNET. Trois cas à
# distinguer, et les confondre rendrait ce contrôle inutilisable :
#
#   1. `9000/tcp` — port simplement EXPOSÉ entre conteneurs. Ne sort pas de la
#      machine. Hors sujet.
#   2. `172.17.0.1:8081->5173/tcp` — publication sur une adresse PRIVEE (boucle
#      locale ou passerelle de pont Docker). Joignable par l'hote et par les
#      conteneurs, JAMAIS depuis internet : ces plages ne sont pas routables.
#      C'est le mecanisme retenu pour la preproduction (2026-08-19) : le Caddy
#      de production l'atteint par `host.docker.internal`, personne d'autre.
#      ⚠️ Sans cette exception, ce script rougirait sur un montage légitime — et
#      la réaction naturelle serait de l'affaiblir. Une garde qu'on est tenté de
#      désarmer finit désarmée.
#   3. `0.0.0.0:55432->5432/tcp` — publication sur TOUTES les interfaces. C'est
#      la faille du 2026-08-19, et le seul cas que ce contrôle doit attraper.
#
# Plages traitees comme non routables : `127.0.0.0/8` et `::1` (boucle locale),
# `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` (RFC 1918 — ponts Docker et
# reseaux locaux). `0.0.0.0` et `[::]` n'en font PAS partie : ils ecoutent aussi
# sur l'adresse publique du serveur, et c'est exactement le cas a attraper.
PUBLIES="$(echo "$LIGNES" \
  | grep -oE '(([0-9]{1,3}\.){3}[0-9]{1,3}|\[::\]|\[::1\]):[0-9]+->' \
  | grep -vE '^(127\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}|10\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}|192\.168\.[0-9]{1,3}\.[0-9]{1,3}|172\.(1[6-9]|2[0-9]|3[01])\.[0-9]{1,3}\.[0-9]{1,3}|\[::1\]):' \
  | sed -E 's/.*:([0-9]+)->/\1/' \
  | sort -un)"

# Ce qui est publie sur une adresse privee est AFFICHE, jamais taire : un
# controle qui masque ce qu'il a decide d'ignorer empeche de verifier sa propre
# decision.
LOOPBACK="$(echo "$LIGNES" \
  | grep -oE '(([0-9]{1,3}\.){3}[0-9]{1,3}|\[::1\]):[0-9]+->' \
  | grep -E '^(127\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}|10\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}|192\.168\.[0-9]{1,3}\.[0-9]{1,3}|172\.(1[6-9]|2[0-9]|3[01])\.[0-9]{1,3}\.[0-9]{1,3}|\[::1\]):' \
  | sed -E 's/.*:([0-9]+)->/\1/' \
  | sort -un | tr '\n' ' ')"
if [ -n "$LOOPBACK" ]; then
  echo "Ports sur adresse privee (hors internet, autorises) : $LOOPBACK"
fi

# TÉMOIN DE MESURE. Il ne porte PAS sur les ports publics : une pile
# entièrement privée est un cas LÉGITIME — c'est exactement celui de la
# préproduction, dont les deux seules publications sont sur 172.17.0.1.
# La première version exigeait au moins un port public et aurait donc rougi sur
# une pile parfaitement conforme, en annonçant « la mesure est suspecte ».
# Ce qu'il faut prouver, c'est qu'on a mesuré QUELQUE CHOSE.
if [ -z "$PUBLIES" ] && [ -z "$LOOPBACK" ]; then
  echo "ERREUR : aucune publication d'aucune sorte — la mesure n'a rien vu." >&2
  echo "         Une pile qui ne publie RIEN ne sert personne : contrôle suspect." >&2
  exit 2
fi

echo "Ports publiés sur l'hôte : $(echo "$PUBLIES" | tr '\n' ' ')"

INTERDITS=""
for port in $PUBLIES; do
  case " $AUTORISES " in
    *" $port "*) ;;
    *) INTERDITS="$INTERDITS $port" ;;
  esac
done

# Témoin POSITIF : si 80 et 443 ne sont pas là, le contrôle ne mesure pas ce
# qu'il croit (mauvais projet, pile arrêtée, filtre cassé). Sans lui, une pile
# éteinte passerait au VERT — c'est exactement le défaut qu'avait la première
# version de la garde `config-prod`.
# `AUTORISES` vide = « rien ne doit être public ». C'est le cas de la
# préproduction. Le témoin positif ci-dessous n'a alors pas d'objet — celui de
# la mesure, plus haut, a déjà fait son travail.
MANQUANTS=""
for attendu in $AUTORISES; do
  echo "$PUBLIES" | grep -qx "$attendu" || MANQUANTS="$MANQUANTS $attendu"
done

if [ -n "$MANQUANTS" ]; then
  echo >&2
  echo "ÉCHEC (témoin positif) : ports attendus absents :$MANQUANTS" >&2
  echo "  La pile ne publie pas ce qu'elle doit publier. Le contrôle ne prouve" >&2
  echo "  rien sur les ports interdits tant que celui-ci ne passe pas." >&2
  exit 2
fi

if [ -n "$INTERDITS" ]; then
  echo >&2
  echo "ÉCHEC : ports publiés sur internet alors qu'ils ne devraient pas :$INTERDITS" >&2
  echo >&2
  echo "  Le fichier a probablement raison et le conteneur a tort : le" >&2
  echo "  déploiement ne recrée que « api app horizon scheduler » (--no-deps)." >&2
  echo "  Un changement de « ports » sur postgres, redis ou reverb exige donc" >&2
  echo "  une recréation explicite :" >&2
  echo >&2
  echo "    docker compose -f docker-compose.yml -f docker-compose.prod.yml \\" >&2
  echo "      up -d --force-recreate --no-deps postgres redis" >&2
  echo >&2
  echo "  ⚠️ Quelques secondes d'interruption de la base." >&2
  exit 1
fi

if [ -z "$AUTORISES" ]; then
  echo "OK : la pile ne publie RIEN sur internet — mesuré sur les conteneurs, pas sur le fichier."
else
  echo "OK : la pile ne publie que $AUTORISES — mesuré sur les conteneurs, pas sur le fichier."
fi
