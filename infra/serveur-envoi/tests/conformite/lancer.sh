#!/usr/bin/env bash
# Lance le kit de conformité contre la passerelle LOCALE de test (php -S, faux KumoMTA, base jetable).
# Contre une autre passerelle : PASSERELLE_URL=... PASSERELLE_WORKSPACE=... PASSERELLE_CLE=... phpunit -c tests/conformite/phpunit.xml
set -euo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PH="$D/../../passerelle"
tmp="$(mktemp -d)"; trap 'kill "${pid:-0}" 2>/dev/null || true; rm -rf "${tmp:?}"' EXIT
ws="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"; cle="cle-de-test-conformite-0123456789abcdef"; port="${PASSERELLE_PORT:-18099}"
export PASSERELLE_BASE="$tmp/p.sqlite" PASSERELLE_CLES="{\"$ws\":\"$cle\"}" ENVOI_REEL_ACTIF=false
curseur_purge="$(php "$D/preparer-base.php" "$PASSERELLE_BASE" "$ws" | tail -1)"
(( curseur_purge >= 1 )) || { echo "préparation de la base : borne de purge attendue >= 1" >&2; exit 1; }
php -S "127.0.0.1:$port" "$D/serveur-local.php" >"$tmp/serveur.log" 2>&1 & pid=$!
for _ in $(seq 1 50); do (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && break; sleep 0.1; done
export PASSERELLE_URL="http://127.0.0.1:$port" PASSERELLE_WORKSPACE="$ws" PASSERELLE_CLE="$cle" PASSERELLE_CURSEUR_PURGE=$((curseur_purge - 1))
"${PHPUNIT:-$PH/vendor/bin/phpunit}" -c "$D/phpunit.xml" --no-progress --fail-on-skipped
