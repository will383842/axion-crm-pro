#!/usr/bin/env bash
# vendor-cloud.sh — télécharge le dossier `backend/vendor` fabriqué par la CI
# (workflow vendor-cloud.yml), pour les SESSIONS CLOUD de Claude Code seulement.
#
# Pourquoi : dans le cloud, `composer install` échoue (le proxy GitHub répond 403
# aux archives codeload). La CI fabrique vendor et le publie ; la session le prend.
# La CI de test, elle, lance son propre `composer install` et n'utilise JAMAIS ceci.
#
# RISQUE ACCEPTÉ (décision du responsable) : le .sha256 est publié dans la MÊME pré-version
# mutable que l'archive. Il prouve l'intégrité de transfert (pas de corruption), PAS
# l'authenticité : qui peut écrire dans la pré-version peut remplacer les deux. Cette surface
# équivaut à un accès en écriture au dépôt ; elle ne concerne que les sessions cloud, jamais la CI.
#
# Usage :  bash infra/scripts/vendor-cloud.sh            # télécharge + installe
#          bash infra/scripts/vendor-cloud.sh verifier   # compare seulement vendor/ et composer.lock
#
# Sorties : 0 = fait, hors cloud, ou archive absente (dit clairement) ;
#           1 = somme de contrôle fausse, vendor périmé, ou erreur réseau autre qu'« absente ».
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
    $ref = function ($p) { return ($p["dist"]["reference"] ?? "") . "|" . ($p["source"]["reference"] ?? ""); };
    $attendu = []; $refA = [];
    foreach (array_merge($l["packages"] ?? [], $l["packages-dev"] ?? []) as $p) { $attendu[$p["name"]] = $p["version"]; $refA[$p["name"]] = $ref($p); }
    $present = []; $refP = [];
    foreach (($i["packages"] ?? $i) as $p) { $present[$p["name"]] = $p["version"]; $refP[$p["name"]] = $ref($p); }
    $ecarts = [];
    foreach ($attendu as $nom => $v) {
      if (!isset($present[$nom])) { $ecarts[] = "$nom : absent du vendor (lock $v)"; }
      elseif ($present[$nom] !== $v) { $ecarts[] = "$nom : vendor {$present[$nom]} != lock $v"; }
      elseif (strpos($v, "dev-") === 0 && $refP[$nom] !== $refA[$nom]) { $ecarts[] = "$nom : référence vendor {$refP[$nom]} != lock {$refA[$nom]}"; }
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
BASE="${VENDOR_CLOUD_URL_BASE:-https://github.com/$DEPOT/releases/download/vendor-cloud}"
URL="$BASE/$NOM"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Message de refus : stderr (journal) ET stdout (visible dans la session).
refuser() { echo "$1" >&2; echo "$1"; exit 1; }

# $1 = URL, $2 = fichier de sortie. Pose CODE (HTTP) et RC (code de sortie de curl).
telecharger() {
  RC=0
  CODE="$(curl -sSL --fail --max-time 120 --retry 2 -o "$2" -w '%{http_code}' "$1")" || RC=$?
  # file:// (tests) ne renvoie pas de code HTTP : un succès vaut 200.
  if [ "$RC" = "0" ] && [ "$CODE" = "000" ]; then CODE=200; fi
  return 0
}

echo "vendor-cloud : téléchargement de $NOM ..."
telecharger "$URL" "$TMP/$NOM"
if [ "$CODE" = "404" ] || [ "$RC" = "37" ]; then
  echo "vendor-cloud : l'archive $NOM n'existe pas (pas encore fabriquée pour ce composer.lock)."
  echo "  Lancer le workflow « vendor-cloud » (Actions > Run workflow), puis relancer ce script."
  exit 0
fi
[ "$RC" = "0" ] && [ "$CODE" = "200" ] || refuser "vendor-cloud : téléchargement impossible (HTTP $CODE, curl $RC) : $URL"

telecharger "$URL.sha256" "$TMP/$NOM.sha256"
[ "$RC" = "0" ] && [ "$CODE" = "200" ] || refuser "vendor-cloud : fichier .sha256 introuvable (HTTP $CODE, curl $RC)."
ATTENDU="$(awk '{print $1}' "$TMP/$NOM.sha256")"
REEL="$(sha256sum "$TMP/$NOM" | awk '{print $1}')"
if [ "$ATTENDU" != "$REEL" ]; then
  refuser "vendor-cloud : sha256 différent (attendu $ATTENDU, reçu $REEL), archive REFUSÉE. Ce contrôle prouve l'intégrité de transfert, pas l'authenticité de l'archive."
fi

mkdir "$TMP/extrait"
tar -xzf "$TMP/$NOM" -C "$TMP/extrait"
[ ! -L "$TMP/extrait/vendor" ] || refuser "vendor-cloud : vendor/ est un lien symbolique dans l'archive, REFUSÉE."
[ -d "$TMP/extrait/vendor" ] || refuser "vendor-cloud : l'archive ne contient pas vendor/."

# Refuse un vendor périmé AVANT de toucher à celui de la session.
verifier_vendor "$TMP/extrait/vendor" || refuser "vendor-cloud : vendor de l'archive périmé, archive REFUSÉE."

# Échange atomique : ancien renommé, nouveau déplacé, ancien supprimé ; restauration si échec.
rm -rf "$BACKEND/vendor.ancien"
[ ! -e "$BACKEND/vendor" ] || mv "$BACKEND/vendor" "$BACKEND/vendor.ancien"
if ! mv "$TMP/extrait/vendor" "$BACKEND/vendor"; then
  [ ! -e "$BACKEND/vendor.ancien" ] || mv "$BACKEND/vendor.ancien" "$BACKEND/vendor"
  refuser "vendor-cloud : échange du vendor impossible, ancien vendor restauré."
fi

# Régénère l'autoload sans réseau, sans scripts et SANS plugins (aucun PHP de l'archive n'est exécuté).
if ! ( cd "$BACKEND" && composer dump-autoload --no-scripts --no-plugins --no-interaction ); then
  rm -rf "$BACKEND/vendor"
  [ ! -e "$BACKEND/vendor.ancien" ] || mv "$BACKEND/vendor.ancien" "$BACKEND/vendor"
  refuser "vendor-cloud : dump-autoload en échec, ancien vendor restauré."
fi
rm -rf "$BACKEND/vendor.ancien"
echo "vendor-cloud : backend/vendor installé depuis $NOM."
