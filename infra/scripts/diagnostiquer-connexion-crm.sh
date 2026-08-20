#!/usr/bin/env bash
# Dit POURQUOI un compte n'arrive pas à ouvrir la console CRM. Ne change RIEN.
#
# ── Pourquoi ce script existe ───────────────────────────────────────────────
# Le 2026-08-20, l'exploitant a signalé qu'il ne pouvait pas se connecter « avec
# le bon identifiant et le bon mot de passe ». C'est cohérent avec ce que
# l'audit 360° a mesuré le 2026-08-19 : le compte propriétaire existe depuis le
# 2026-05-17 et **personne ne s'est jamais connecté** — 0 session, 0 jeton.
#
# Le symptôme « mes identifiants sont bons et ça refuse » a AU MOINS CINQ causes
# distinctes dans ce produit, et elles ne se distinguent pas depuis le
# navigateur. Ce script les sépare, en lecture seule, en une passe.
#
#   1. Le compte n'existe pas sous cette adresse (casse, alias, faute de frappe)
#   2. Le compte est VERROUILLÉ — `F35-012` : le verrou n'est vérifié qu'APRÈS
#      le contrôle du mot de passe, et `failed_login_count` s'accumule. Passé
#      dix échecs, le bon mot de passe est refusé lui aussi. Réessayer aggrave.
#   3. Le mot de passe est correct, la session s'ouvre, et TOUT écran métier
#      répond 403 — `A07-001 / F35-002` : l'enrôlement 2FA écrivait trois
#      colonnes inexistantes, `first_login_completed_at` ne pouvait donc jamais
#      être posé, et `EnforceFirstLoginSetup` refuse tant qu'il est nul.
#   4. Le serveur exige un enrôlement 2FA qu'AUCUN écran ne permet de déclencher
#      — `D22-001`.
#   5. Les deux portes de secours du formulaire sont mortes — `F40-002` :
#      `MAIL_MAILER` n'est défini nulle part, donc `config/mail.php` retombe sur
#      `log`. Ni le lien magique ni la réinitialisation ne partent JAMAIS.
#
# ⚠️ Les correctifs de 3, 4 et 5 vivent sur la branche `fix/a35-authentification`
# (PR #191) et **ne sont pas déployés**. Ce script ne les remplace pas : il dit
# lequel vous bloque, pour que vous sachiez si un déverrouillage suffit ou s'il
# faut déployer.
#
# ── Ce script N'ÉCRIT RIEN, et c'est vérifiable ─────────────────────────────
# Aucun UPDATE, INSERT, DELETE, ALTER ni artisan mutateur. Le seul `docker exec`
# lancé est un `psql` en lecture, et un `docker inspect`. Une garde du dépôt
# (`backend/tests/Feature/Infra/DiagnosticConnexionSansEcritureTest.php`) le
# vérifie, avec son témoin négatif.
#
#   Usage, en root sur le serveur :
#     bash diagnostiquer-connexion-crm.sh 'adresse@exemple.fr'
#
set -uo pipefail

COMPTE="${1:-}"
if [ -z "$COMPTE" ]; then
  echo "Usage : bash $0 'adresse@exemple.fr'" >&2
  exit 2
fi

CONTENEUR_DB="${CONTENEUR_DB:-axion-crm-postgres}"
CONTENEUR_API="${CONTENEUR_API:-axion-crm-api}"
BASE="${BASE:-axion_crm}"
UTILISATEUR_DB="${UTILISATEUR_DB:-axion}"

# `|| true` sur toutes les substitutions : sous `set -e`, une commande qui échoue
# tuerait le script AVANT d'avoir rien affiché. C'est le défaut P5-35-004, payé
# sur le script voisin le 2026-08-19 : ses branches d'erreur étaient
# inatteignables, et l'opérateur ne voyait RIEN. On ne le refait pas ici.

dit() { printf '%s\n' "$*"; }
titre() { printf '\n===== %s =====\n' "$*"; }

psql_lecture() {
  docker exec -i "$CONTENEUR_DB" psql -U "$UTILISATEUR_DB" -d "$BASE" -t -A -F '|' -c "$1" 2>&1
}

titre "0. Le banc voit-il ce qu'il croit voir"
if ! docker inspect "$CONTENEUR_DB" >/dev/null 2>&1; then
  dit "🔴 Le conteneur « $CONTENEUR_DB » n'existe pas sur cette machine."
  dit "   Ce diagnostic ne mesurerait rien. Vérifiez le nom, ou lancez-le sur le"
  dit "   bon serveur. (Un rapport rendu sans base est le pire des rapports.)"
  exit 3
fi
dit "conteneur base ... $CONTENEUR_DB (présent)"
docker inspect "$CONTENEUR_API" >/dev/null 2>&1 \
  && dit "conteneur API .... $CONTENEUR_API (présent)" \
  || dit "conteneur API .... ABSENT — les points 3 et 5 seront incomplets"

titre "1. Le compte existe-t-il, et sous quelle forme exacte"
# On cherche SANS tenir compte de la casse ni des espaces : une adresse saisie
# « William@… » ne trouve pas « william@… », et c'est une cause de refus à part
# entière qu'il ne faut pas confondre avec un mauvais mot de passe.
LIGNES="$(psql_lecture "
  SELECT email,
         id,
         (current_workspace_id IS NOT NULL) AS a_un_espace,
         (first_login_completed_at IS NOT NULL) AS premiere_connexion_faite,
         (totp_enabled_at IS NOT NULL) AS totp_actif,
         COALESCE(failed_login_count, 0) AS echecs,
         COALESCE(locked_until::text, '-') AS verrouille_jusqu_a,
         left(COALESCE(password_hash,''), 4) AS algo_hash,
         length(COALESCE(password_hash,'')) AS long_hash
    FROM users
   WHERE lower(btrim(email)) = lower(btrim('$COMPTE'));
" || true)"

if [ -z "$(printf '%s' "$LIGNES" | tr -d '[:space:]')" ]; then
  dit "🔴 AUCUN compte sous « $COMPTE »."
  dit ""
  dit "   Les adresses réellement présentes (10 premières) :"
  psql_lecture "SELECT email FROM users ORDER BY created_at LIMIT 10;" | sed 's/^/     /'
  dit ""
  dit "   → Ce n'est pas un problème de mot de passe. Vérifiez l'adresse."
  exit 0
fi

printf '%s\n' "$LIGNES" | while IFS='|' read -r email id espace premiere totp echecs verrou algo long; do
  dit "adresse .................. $email"
  dit "espace de travail ........ $espace   (f = aucun : la console n'a rien à afficher)"
  dit "première connexion faite . $premiere"
  dit "2FA active ............... $totp"
  dit "échecs de connexion ...... $echecs"
  dit "verrouillé jusqu'à ....... $verrou"
  dit "empreinte du mot de passe  $algo (longueur $long)"
  dit ""

  if [ "$verrou" != "-" ]; then
    dit "🔴 CAUSE 2 — LE COMPTE EST VERROUILLÉ jusqu'à $verrou."
    dit "   Constat F35-012 : le verrou n'est vérifié qu'APRÈS le contrôle du mot"
    dit "   de passe. Le bon mot de passe est donc refusé lui aussi, et CHAQUE"
    dit "   nouvel essai repousse l'échéance. Cesser d'essayer est la première"
    dit "   chose à faire."
  elif [ "${echecs:-0}" -ge 5 ] 2>/dev/null; then
    dit "⚠️  $echecs échecs déjà comptés. Le verrou tombe à 10 : n'insistez pas."
  fi

  if [ "$premiere" = "f" ]; then
    dit ""
    dit "🔴 CAUSE 3 — `first_login_completed_at` est NUL."
    dit "   `EnforceFirstLoginSetup` répond 403 sur TOUT écran métier tant qu'il"
    dit "   l'est. Même avec le bon mot de passe, la session s'ouvre sur un mur."
    dit "   Constat A07-001 / F35-002 : l'enrôlement 2FA écrivait trois colonnes"
    dit "   inexistantes, il ne pouvait donc jamais poser cette date."
    dit "   → Le correctif est sur `fix/a35-authentification`, NON DÉPLOYÉ."
  fi

  if [ "$algo" != '$2y$' ] && [ "$algo" != '$2a$' ] && [ "$algo" != '$2b$' ]; then
    dit ""
    dit "🔴 L'empreinte du mot de passe ne commence pas par un préfixe bcrypt"
    dit "   ($algo). Aucun mot de passe ne peut correspondre. C'est un compte"
    dit "   dont l'empreinte n'a jamais été posée, ou l'a été par un autre"
    dit "   algorithme."
  fi
done

titre "2. Les portes de secours du formulaire fonctionnent-elles"
# `MAIL_MAILER` est lu dans l'environnement du PROCESSUS, pas dans le `.env` :
# `docker compose restart` ne relit pas `env_file` (constat A07-003), donc c'est
# `docker inspect` qui dit la vérité, pas le fichier.
if docker inspect "$CONTENEUR_API" >/dev/null 2>&1; then
  VALEUR_MAIL="$(docker inspect "$CONTENEUR_API" \
    --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null \
    | grep -E '^MAIL_MAILER=' | head -1 | cut -d= -f2- || true)"

  if [ -z "$VALEUR_MAIL" ]; then
    dit "🔴 CAUSE 5 — MAIL_MAILER n'est PAS défini dans l'environnement du"
    dit "   conteneur. `config/mail.php` retombe alors sur son défaut, `log` :"
    dit "   **ni « Recevoir un lien magique » ni « Mot de passe oublié » ne"
    dit "   partent jamais.** Les deux boutons de secours du formulaire sont"
    dit "   morts, en silence. Constat F40-002 (S0)."
  elif [ "$VALEUR_MAIL" = "log" ]; then
    dit "🔴 CAUSE 5 — MAIL_MAILER = log : les courriels sont ÉCRITS DANS UN"
    dit "   FICHIER, jamais envoyés. Les deux portes de secours sont mortes."
  else
    dit "MAIL_MAILER = $VALEUR_MAIL — les courriels partent, en principe."
    dit "   (Le vérifier reste un geste distinct : « configuré » n'est pas « remis ».)"
  fi
else
  dit "conteneur API absent : impossible de lire MAIL_MAILER."
fi

titre "3. Ce qu'il faut faire, dans l'ordre"
dit "1. NE PLUS ESSAYER de se connecter : chaque échec rapproche du verrou."
dit "2. Si le compte est verrouillé, le déverrouiller — c'est une écriture, donc"
dit "   un geste délibéré :"
dit ""
dit "     docker exec -i $CONTENEUR_DB psql -U $UTILISATEUR_DB -d $BASE -c \\"
dit "       \"UPDATE users SET locked_until = NULL, failed_login_count = 0\\"
dit "        WHERE lower(email) = lower('$COMPTE');\""
dit ""
dit "3. Reposer le mot de passe, SANS le mettre dans la ligne de commande :"
dit ""
dit "     printf '%s' 'le-mot-de-passe' | bash infra/scripts/definir-mot-de-passe-crm.sh"
dit ""
dit "4. ⚠️ Si `première connexion faite` est à `f`, les trois gestes ci-dessus"
dit "   NE SUFFIRONT PAS : la session s'ouvrira sur un 403 partout. Il faut"
dit "   déployer `fix/a35-authentification`. C'est le vrai correctif."
dit ""
dit "Ce script n'a rien écrit."
