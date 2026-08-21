#!/usr/bin/env bash
# Amorce la PRÉPRODUCTION d'Axion CRM Pro sur le serveur de production.
#
# À lancer UNE FOIS, en root, sur `46.62.248.239`. Les déploiements suivants
# passent par `.github/workflows/deploy-staging.yml`, qui suppose que ce script
# a tourné.
#
# ── Pourquoi une préproduction ──────────────────────────────────────────────
# §28.1 du cahier des charges : « un lot qui n'a pas vécu en préproduction n'est
# pas livrable ». Elle n'existait pas au 2026-08-19.
#
# ── Ce que ce script fait, et ce qu'il ne fait PAS ──────────────────────────
# Il crée un SECOND checkout (`/opt/axion-crm-pro-staging`), son fichier
# d'environnement, et démarre la pile via `docker-compose.staging.yml`.
#
# Il ne copie **jamais** la base de production. 16 Go de données personnelles
# réelles dans un environnement moins surveillé, c'est précisément ce que le §21
# interdit de faire à la légère. La préproduction se remplit avec le jeu
# synthétique versionné (`backend/database/perf/seed_reference_50k.sql`) — plus
# sûr, 25 fois plus petit, et c'est le volume de référence du §29.
#
# Il ne réutilise **aucune** clé d'API tierce de la production. Une
# préproduction qui consomme le quota INSEE ou envoie de vrais e-mails n'est pas
# une préproduction, c'est une seconde production mal surveillée.
#
# Usage :
#   bash bootstrap-preprod.sh            # amorçage complet
#   bash bootstrap-preprod.sh --etat     # ne fait rien, affiche l'état
set -uo pipefail

CHEMIN="/opt/axion-crm-pro-staging"
PROJET="axion-crm-staging"
DEPOT="https://github.com/will383842/axion-crm-pro.git"
COMPOSE=(-p "$PROJET" -f docker-compose.yml -f docker-compose.staging.yml)
SERVICES=(postgres redis api app horizon scheduler)

if [ "$(id -u)" -ne 0 ]; then
  echo "ERREUR : à lancer en root." >&2
  exit 2
fi

etat() {
  echo "=== état de la préproduction ==="
  if [ -d "$CHEMIN/.git" ]; then
    echo "checkout : $CHEMIN ($(git -C "$CHEMIN" rev-parse --short HEAD 2>/dev/null || echo '?'))"
  else
    echo "checkout : ABSENT"
  fi
  docker ps -a --filter "label=com.docker.compose.project=$PROJET" \
    --format '{{.Names}} :: {{.Status}} :: {{.Ports}}' 2>/dev/null || true
}

if [ "${1:-}" = "--etat" ]; then
  etat
  exit 0
fi

# ── 1. Le checkout ──────────────────────────────────────────────────────────
if [ ! -d "$CHEMIN/.git" ]; then
  echo ">>> clonage de $DEPOT dans $CHEMIN"
  git clone --depth 50 "$DEPOT" "$CHEMIN" || { echo "ERREUR : clonage impossible." >&2; exit 1; }
else
  echo ">>> checkout déjà présent, mise à jour"
  git -C "$CHEMIN" fetch --depth 50 origin main && git -C "$CHEMIN" reset --hard origin/main
fi

cd "$CHEMIN" || exit 1
echo "    HEAD = $(git rev-parse --short HEAD)"

# ── 2. L'environnement ──────────────────────────────────────────────────────
# Bâti depuis `.env.example`, JAMAIS copié de la production : copier le `.env`
# de production embarquerait ses clés d'API tierces et ses secrets dans un
# environnement moins surveillé.
if [ ! -f .env ]; then
  echo ">>> création de .env"
  cp .env.example .env

  # `APP_KEY` : une clé PROPRE à la préproduction. Partager celle de la
  # production permettrait de forger des cookies valides pour la production
  # depuis un environnement de test.
  CLE="base64:$(head -c 32 /dev/urandom | base64)"

  # `sed -i` sur des valeurs contenant `/` et `+` (base64) : on utilise `|` en
  # séparateur, et on échappe le motif de remplacement.
  regler() {
    local cle="$1" valeur="$2"
    if grep -q "^${cle}=" .env; then
      sed -i "s|^${cle}=.*|${cle}=${valeur}|" .env
    else
      printf '%s=%s\n' "$cle" "$valeur" >> .env
    fi
  }

  regler APP_ENV staging
  regler APP_KEY "$CLE"
  # 🔴 CORRIGÉ LE 2026-08-20 — constat F37-003 (S1). Valait `true`.
  #
  # SITE JUMEAU de `docker-compose.staging.yml:125` (patron A-011, 23 cas
  # mesurés dans ce dépôt) : réparer l'overlay sans réparer ce script aurait
  # rouvert la porte au prochain amorçage, dans le `.env` du serveur, là où
  # personne ne relit.
  #
  # La préproduction est servie PUBLIQUEMENT par le Caddy de production
  # (`infra/caddy/Caddyfile`, lignes 244 et 279), sans basic_auth ni liste
  # d'adresses autorisées. Une page de débogage Laravel y afficherait
  # DB_PASSWORD, APP_KEY et les jetons tiers à quiconque provoque une 500.
  #
  # La trace reste lisible où il faut : `docker logs axion-crm-staging-api`.
  regler APP_DEBUG false
  regler APP_URL https://staging.axion-crm-pro.com
  regler DB_DATABASE axion_crm_staging
  regler SESSION_DOMAIN ''
  regler SESSION_SECURE_COOKIE true
  regler SANCTUM_STATEFUL_DOMAINS staging.axion-crm-pro.com
  regler SESSION_COOKIE axion_crm_staging_session
  # Aucun envoi réel ne part de la préproduction. Un compte rendu de test
  # expédié à un vrai contact est une faute qu'on ne rattrape pas.
  regler MAIL_MAILER log
  # Clés tierces NEUTRALISÉES : la préproduction ne consomme aucun quota, ne
  # déclenche aucun webhook, n'appelle aucun service payant.
  for cle in INSEE_API_KEY ZEPTOMAIL_TOKEN TELEGRAM_BOT_TOKEN STRIPE_SECRET \
             OPENAI_API_KEY MISTRAL_API_KEY ANTHROPIC_API_KEY SENTRY_LARAVEL_DSN; do
    regler "$cle" ''
  done

  chmod 600 .env
  echo "    .env créé (APP_KEY propre, clés tierces vidées)"
else
  echo ">>> .env déjà présent, laissé intact"
fi

# ── 3. La pile ──────────────────────────────────────────────────────────────
# ⚠️ Les services sont NOMMÉS explicitement. Un `up -d` global démarrerait aussi
# le service `caddy` hérité de `docker-compose.yml` — qui prendrait 80 et 443 et
# tuerait le Caddy de PRODUCTION. L'overlay le met sous profil pour cette
# raison, ce nommage explicite est la seconde ceinture.
echo ">>> construction et démarrage (${SERVICES[*]})"
docker compose "${COMPOSE[@]}" up -d --build "${SERVICES[@]}" || {
  echo "ERREUR : la pile n'a pas démarré." >&2
  docker compose "${COMPOSE[@]}" ps
  exit 1
}

echo ">>> attente de la base"
for _ in $(seq 1 30); do
  docker exec axion-crm-staging-postgres pg_isready -U axion > /dev/null 2>&1 && break
  sleep 2
done

# ── 4. Le schéma ────────────────────────────────────────────────────────────
echo ">>> migrations"
docker exec axion-crm-staging-api php artisan migrate --force --database=pgsql_owner < /dev/null || {
  echo "ERREUR : migrations en échec." >&2
  exit 1
}

# ── 5. Vérifications — un script qui annonce « c'est fait » sans regarder ne
#      prouve rien.
echo
echo "=== VÉRIFICATIONS ==="

echo -n "API de préproduction (127.0.0.1:8082/up) : "
if curl -fsS --max-time 10 http://127.0.0.1:8082/up > /dev/null 2>&1; then
  echo "répond"
else
  echo "NE RÉPOND PAS" >&2
fi

echo -n "Frontend de préproduction (127.0.0.1:8081) : "
if curl -fsS --max-time 10 -o /dev/null http://127.0.0.1:8081 2>&1; then
  echo "répond"
else
  echo "NE RÉPOND PAS" >&2
fi

# TÉMOINS POSITIFS — la production ne doit pas avoir bougé d'un pouce.
echo -n "PRODUCTION toujours debout (127.0.0.1:80) : "
if curl -fsS --max-time 10 -o /dev/null -H 'Host: app.axion-crm-pro.com' http://127.0.0.1/ 2>&1; then
  echo "oui"
else
  echo "NON — VÉRIFIER IMMÉDIATEMENT" >&2
fi

echo -n "Caddy de PRODUCTION intact : "
docker ps --filter 'name=axion-crm-caddy' --format '{{.Names}} {{.Status}}' | head -1

echo
echo "=== ce qui reste à faire à la main ==="
echo "  1. Recréer le Caddy de production pour qu'il résolve host.docker.internal :"
echo "       cd /opt/axion-crm-pro && COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml \\"
echo "         docker compose up -d --force-recreate --no-deps caddy"
echo "     (quelques secondes d'interruption du site)"
echo "  2. Remplir la préproduction avec le jeu SYNTHÉTIQUE, jamais un export de prod :"
echo "       docker exec -i axion-crm-staging-postgres psql -U axion -d axion_crm_staging \\"
echo "         < backend/database/perf/seed_reference_50k.sql"
echo "  3. Vérifier depuis l'extérieur : https://staging.axion-crm-pro.com"
echo
etat
