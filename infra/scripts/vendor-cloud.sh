#!/usr/bin/env bash
# vendor-cloud.sh — télécharge le dossier `backend/vendor` fabriqué par la CI
# (workflow vendor-cloud.yml), pour les SESSIONS CLOUD de Claude Code seulement.
#
# Pourquoi : dans le cloud, `composer install` échoue (le proxy GitHub répond 403
# aux archives codeload). La CI fabrique vendor et le publie ; la session le prend.
# La CI de test, elle, lance son propre `composer install` et n'utilise JAMAIS ceci.
#
# Usage :  bash infra/scripts/vendor-cloud.sh            # télécharge + installe
#          bash infra/scripts/vendor-cloud.sh verifier   # compare seulement vendor/ et composer.lock
#
# Sorties : 0 = fait, hors cloud, ou archive absente (dit clairement) ;
#           1 = archive corrompue, vendor périmé, ou erreur réseau autre qu'« absente ».
set -euo pipefail

DEPOT="will383842/axion-crm-pro"
RACINE="${VENDOR_CLOUD_RACINE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BACKEND="$RACINE/backend"
LOCK="$BACKEND/composer.lock"

# Ne jamais tourner sur un poste de travail.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  echo "vendor-cloud : hors session cloud (CLAUDE_CODE_REMOTE != true), rien à faire."
  exit 0
fi

# Compare vendor/composer/installed.json à composer.lock, nom par nom, version par version.
# $1 = dossier « vendor » à contrôler. Code 1 si périmé.
verifier_vendor() {
  local vendor="$1"
  php -r '
    [$lock, $installed] = [$argv[1], $argv[2]];
    $l = json_decode((string) @file_get_contents($lock), true);
    $i = json_decode((string) @file_get_contents($installed), true);
    if (!is_array($l) || !is_array($i)) { fwrite(STDERR, "vendor-cloud : composer.lock ou installed.json illisible.\n"); exit(1); }
    $attendu = [];
    foreach (array_merge($l["packages"] ?? [], $l["packages-dev"] ?? []) as $p) { $attendu[$p["name"]] = $p["version"]; }
    $present = [];
    foreach (($i["packages"] ?? $i) as $p) { $present[$p["name"]] = $p["version"]; }
    $ecarts = [];
    foreach ($attendu as $nom => $v) {
      if (!isset($present[$nom])) { $ecarts[] = "$nom : absent du vendor (lock $v)"; }
      elseif ($present[$nom] !== $v) { $ecarts[] = "$nom : vendor {$present[$nom]} != lock $v"; }
    }
    foreach ($present as $nom => $v) { if (!isset($attendu[$nom])) { $ecarts[] = "$nom : en trop dans le vendor ($v)"; } }
    if ($ecarts) { fwrite(STDERR, "vendor-cloud : vendor périmé, REFUSÉ :\n  - " . implode("\n  - ", $ecarts) . "\n"); exit(1); }
    echo "vendor-cloud : vendor à jour (" . count($attendu) . " paquets identiques à composer.lock).\n";
  ' "$LOCK" "$vendor/composer/installed.json"
}

if [ "${1:-}" = "verifier" ]; then
  verifier_vendor "$BACKEND/vendor"
  exit $?
fi

[ -f "$LOCK" ] || { echo "vendor-cloud : $LOCK introuvable." >&2; exit 1; }

HASH="$(sha256sum "$LOCK" | cut -c1-16)"
NOM="vendor-$HASH.tar.gz"
URL="https://github.com/$DEPOT/releases/download/vendor-cloud/$NOM"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "vendor-cloud : téléchargement de $NOM ..."
CODE="$(curl -sSL -o "$TMP/$NOM" -w '%{http_code}' "$URL" || true)"
if [ "$CODE" = "404" ]; then
  echo "vendor-cloud : l'archive $NOM n'existe pas (pas encore fabriquée pour ce composer.lock)."
  echo "  Lancer le workflow « vendor-cloud » (Actions > Run workflow), puis relancer ce script."
  exit 0
fi
if [ "$CODE" != "200" ]; then
  echo "vendor-cloud : téléchargement impossible (HTTP $CODE) : $URL" >&2
  exit 1
fi

CODE="$(curl -sSL -o "$TMP/$NOM.sha256" -w '%{http_code}' "$URL.sha256" || true)"
[ "$CODE" = "200" ] || { echo "vendor-cloud : fichier .sha256 introuvable (HTTP $CODE)." >&2; exit 1; }
ATTENDU="$(awk '{print $1}' "$TMP/$NOM.sha256")"
REEL="$(sha256sum "$TMP/$NOM" | awk '{print $1}')"
if [ "$ATTENDU" != "$REEL" ]; then
  echo "vendor-cloud : sha256 différent (attendu $ATTENDU, reçu $REEL), archive REFUSÉE." >&2
  exit 1
fi

mkdir "$TMP/extrait"
tar -xzf "$TMP/$NOM" -C "$TMP/extrait"
[ -d "$TMP/extrait/vendor" ] || { echo "vendor-cloud : l'archive ne contient pas vendor/." >&2; exit 1; }

# Refuse un vendor périmé AVANT de toucher à celui de la session.
verifier_vendor "$TMP/extrait/vendor"

rm -rf "$BACKEND/vendor"
mv "$TMP/extrait/vendor" "$BACKEND/vendor"

# Régénère l'autoload sans réseau et sans scripts.
( cd "$BACKEND" && composer dump-autoload --no-scripts --no-interaction )
echo "vendor-cloud : backend/vendor installé depuis $NOM."
