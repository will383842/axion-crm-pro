#!/usr/bin/env bash
# Définit le mot de passe d'un compte du CRM, sur le serveur de production.
#
# ── Pourquoi ce script existe ───────────────────────────────────────────────
# Mesuré le 2026-08-19 : le compte propriétaire du CRM existe depuis le
# 2026-05-17, et **personne ne s'est jamais connecté** — 0 session, 0 jeton.
# Deux causes qui se renforcent :
#   - `OWNER_INITIAL_PASSWORD` était vide à l'installation, donc le seeder a
#     généré un mot de passe aléatoire et l'a écrit dans `storage/logs`, un
#     fichier qui pèse aujourd'hui 263 Mo ;
#   - `MAIL_MAILER=log` : aucun e-mail ne part du CRM, donc ni la
#     réinitialisation ni le lien magique n'arrivent jamais à destination.
#
# Conséquence : la console est inaccessible à son propre propriétaire. Et cela
# bloque plus que le confort — l'audit 360° exige (§11) d'ouvrir les 39 écrans
# à la main dans un vrai navigateur : sans identifiants, un tiers de son travail
# est impossible.
#
# ⚠️ CE MOT DE PASSE NE SUFFIT PAS À OUVRIR LA CONSOLE. Mesuré le 2026-08-19
# (audit 360, A07-001 / F35-002) : l'enrôlement 2FA écrivait trois colonnes
# inexistantes, `first_login_completed_at` ne pouvait donc jamais être posé, et
# `EnforceFirstLoginSetup` répondait 403 sur tout écran métier. Ce script rend le
# mot de passe ; c'est le correctif de A07-001 qui rend l'usage.
#
# ── Le mot de passe ne transite NI par un argument, NI par une variable ─────
# Il est lu sur l'ENTRÉE STANDARD, et il est REMIS au conteneur sur l'entrée
# standard elle aussi. Un mot de passe passé en argument apparaît dans `ps` pour
# tous les utilisateurs de la machine, et dans l'historique du shell.
#
# ⚠️ CORRIGÉ LE 2026-08-19 (audit 360, F35-007). La version précédente le
# transmettait par `docker exec -e CRM_MDP="$MDP"` — c'est-à-dire, très
# exactement, comme ARGUMENT de la commande `docker`. Mesuré : le mot de passe
# apparaissait en clair dans la ligne de commande du processus, lisible par tout
# utilisateur du serveur via `ps -ef` ou `/proc/<pid>/cmdline`, pendant toute la
# durée du `docker exec`. L'en-tête affirmait le contraire, ce qui est pire qu'un
# silence : l'opérateur croyait la protection acquise.
#
#   Usage, en root sur le serveur :
#     printf '%s' 'le-mot-de-passe' | bash definir-mot-de-passe-crm.sh
#
#   Ou en le tapant soi-même, sans qu'il s'affiche :
#     read -rsp 'Mot de passe : ' P; echo; printf '%s' "$P" | bash definir-mot-de-passe-crm.sh; unset P
#
#   Compte visé : premier argument, par défaut le propriétaire.
set -euo pipefail

COMPTE="${1:-williamsjullin@gmail.com}"
CONTENEUR="${CONTENEUR_API:-axion-crm-api}"

if [ "$(id -u)" -ne 0 ]; then
  echo "ERREUR : à lancer en root." >&2
  exit 2
fi

if [ -t 0 ]; then
  echo "ERREUR : ce script lit le mot de passe sur l'entrée standard." >&2
  echo "  printf '%s' 'mot-de-passe' | bash $0" >&2
  exit 2
fi

MDP="$(cat)"
if [ ${#MDP} -lt 12 ]; then
  echo "ERREUR : mot de passe de moins de 12 caractères — refusé." >&2
  exit 2
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTENEUR"; then
  echo "ERREUR : conteneur « $CONTENEUR » introuvable ou arrêté." >&2
  exit 2
fi

# Le code PHP est écrit dans un fichier DANS le conteneur, puis exécuté par
# `artisan tinker`. Il lit le mot de passe sur son ENTRÉE STANDARD, jamais dans
# le code ni dans son environnement : un mot de passe interpolé dans un fichier
# resterait sur le disque si le script échouait avant le ménage, et un mot de
# passe passé en `-e` apparaîtrait dans `ps`.
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT

cat > "$TRAVAIL/definir.php" <<'PHP'
<?php
$email = getenv('CRM_COMPTE');

// Le mot de passe arrive par l'ENTRÉE STANDARD, jamais par l'environnement :
// `docker exec -e CRM_MDP=...` l'exposerait dans `ps` (F35-007).
$mdp = stream_get_contents(STDIN);
if ($mdp === false || $mdp === '') {
    echo "A35_ECHEC_MDP_VIDE\n";
    exit(1);
}

$u = \App\Models\User::where('email', $email)->first();
if (! $u) {
    echo "A35_INTROUVABLE\n";
    exit(1);
}

// 🔴 LA COLONNE NE S'APPELLE PAS `password`, MAIS `password_hash`.
// Le modèle le documente en toutes lettres, et `getAuthPassword()` renvoie
// `password_hash`. Écrire `$u->password` ne lève AUCUNE erreur côté PHP —
// Eloquent crée un attribut dynamique — et l'échec n'apparaît qu'à
// l'enregistrement, sur une colonne inexistante. Constaté au premier essai le
// 2026-08-19. `password_hash` n'est PAS casté en `hashed` : le hachage est à
// notre charge.
$u->password_hash = \Illuminate\Support\Facades\Hash::make($mdp);

// Un compte jamais utilisé peut porter un e-mail non vérifié : sans cela, la
// connexion réussirait puis buterait sur le mur de vérification.
$u->email_verified_at = $u->email_verified_at ?? now();

// Un compte peut aussi être VERROUILLÉ par des tentatives échouées. Redonner un
// mot de passe sans lever le verrou laisserait la connexion impossible, et la
// cause serait cherchée du mauvais côté.
$u->failed_login_count = 0;
$u->last_failed_login_at = null;
$u->locked_until = null;

$u->save();

// TÉMOIN : on ne se contente pas d'écrire, on VÉRIFIE que le hachage enregistré
// valide bien le mot de passe voulu. Un « c'est fait » sans contrôle ne prouve
// rien — et ici l'échec serait découvert à l'écran de connexion, plus tard.
$u->refresh();
echo \Illuminate\Support\Facades\Hash::check($mdp, $u->password_hash) ? "A35_OK\n" : "A35_ECHEC_VERIFICATION\n";
PHP

docker cp "$TRAVAIL/definir.php" "$CONTENEUR":/tmp/definir.php > /dev/null

# Le mot de passe traverse un TUBE jusqu'à l'entrée standard du conteneur.
# `-i` est indispensable pour que `docker exec` relaie stdin.
# 🔴 `|| true` — constat P5-35-004 (S1).
#
# Le script tourne sous `set -e`. Le code PHP appelle `exit(1)` sur
# `A35_INTROUVABLE` et sur `A35_ECHEC_MDP_VIDE` : sous `set -e`, une
# affectation dont la substitution de commande rend un code non nul TUE le
# script sur-le-champ. Le `case "$VERDICT"` plus bas, et tous ses messages,
# n'étaient alors JAMAIS atteints.
#
# Mesuré avant correctif : sur un compte inexistant — une faute de frappe sur
# l'adresse suffit — l'opérateur ne voyait RIEN. Ni « aucun compte », ni le
# bloc de diagnostic prévu pour ce cas. Juste un code 1 muet, sur le geste même
# par lequel l'exploitant reprend l'accès à son produit.
#
# Le code de retour est ici redondant : le verdict se lit sur la DERNIÈRE
# ligne, à l'égalité stricte. On garde donc `set -e` pour tout le reste du
# script, et on l'écarte sur cette seule affectation.
SORTIE="$(printf '%s' "$MDP" | docker exec -i -e CRM_COMPTE="$COMPTE" "$CONTENEUR" php artisan tinker /tmp/definir.php 2>&1 | tr -d '\r')" || true

docker exec "$CONTENEUR" rm -f /tmp/definir.php < /dev/null > /dev/null 2>&1

# Le verdict se lit sur la DERNIÈRE ligne, à l'égalité stricte.
# `case "$SORTIE" in *OK*)` testait la sous-chaîne « OK » sur la sortie ENTIÈRE
# de `artisan tinker` : un avertissement PHP, une bannière ou un message de
# paquet contenant ces deux lettres déclenchait la branche « succès ». Sur le
# geste qui rend l'accès au produit, un faux « c'est fait » envoie l'opérateur
# chercher la panne du mauvais côté (audit 360, F35-014).
VERDICT="$(printf '%s' "$SORTIE" | tail -n 1)"

case "$VERDICT" in
  A35_OK)
    echo "OK : mot de passe défini pour $COMPTE, et vérifié par un contrôle de hachage."
    echo
    echo "Se connecter : https://app.axion-crm-pro.com"
    echo
    echo "⚠️ Si ce mot de passe a transité par un canal non sûr (message, courriel,"
    echo "   conversation), le CHANGER depuis l'interface une fois connecté :"
    echo "   celui-ci devient alors sans valeur."
    ;;
  A35_INTROUVABLE)
    echo "ÉCHEC : aucun compte « $COMPTE » dans cette base." >&2
    exit 1
    ;;
  A35_ECHEC_MDP_VIDE)
    echo "ÉCHEC : le conteneur n'a reçu aucun mot de passe sur son entrée standard." >&2
    exit 1
    ;;
  *)
    echo "ÉCHEC : le mot de passe n'a pas été enregistré, ou la vérification a échoué." >&2
    echo "--- dernière ligne ---" >&2
    echo "$VERDICT" >&2
    echo "--- sortie brute ---" >&2
    echo "$SORTIE" >&2
    exit 1
    ;;
esac
