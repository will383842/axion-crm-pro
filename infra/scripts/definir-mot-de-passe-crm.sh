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
# ── Le mot de passe ne transite NI par un argument, NI par une variable ─────
# Il est lu sur l'ENTRÉE STANDARD. Un mot de passe passé en argument apparaît
# dans `ps` pour tous les utilisateurs de la machine, et dans l'historique du
# shell. Ici il ne fait que traverser un tube.
#
#   Usage, en root sur le serveur :
#     printf '%s' 'le-mot-de-passe' | bash definir-mot-de-passe-crm.sh
#
#   Ou en le tapant soi-même, sans qu'il s'affiche :
#     read -rsp 'Mot de passe : ' P; echo; printf '%s' "$P" | bash definir-mot-de-passe-crm.sh; unset P
#
#   Compte visé : premier argument, par défaut le propriétaire.
set -uo pipefail

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
# `artisan tinker`. Il lit le mot de passe dans l'environnement du processus,
# jamais dans le code : un mot de passe interpolé dans un fichier resterait sur
# le disque si le script échouait avant le ménage.
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT

cat > "$TRAVAIL/definir.php" <<'PHP'
<?php
$email = getenv('CRM_COMPTE');
$mdp = getenv('CRM_MDP');

$u = \App\Models\User::where('email', $email)->first();
if (! $u) {
    echo "INTROUVABLE\n";
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
$u->locked_until = null;

$u->save();

// TÉMOIN : on ne se contente pas d'écrire, on VÉRIFIE que le hachage enregistré
// valide bien le mot de passe voulu. Un « c'est fait » sans contrôle ne prouve
// rien — et ici l'échec serait découvert à l'écran de connexion, plus tard.
$u->refresh();
echo \Illuminate\Support\Facades\Hash::check($mdp, $u->password_hash) ? "OK\n" : "ECHEC_VERIFICATION\n";
PHP

docker cp "$TRAVAIL/definir.php" "$CONTENEUR":/tmp/definir.php > /dev/null

SORTIE="$(docker exec -e CRM_COMPTE="$COMPTE" -e CRM_MDP="$MDP" \
  "$CONTENEUR" php artisan tinker /tmp/definir.php < /dev/null 2>&1 | tr -d '\r')"

docker exec "$CONTENEUR" rm -f /tmp/definir.php < /dev/null > /dev/null 2>&1

case "$SORTIE" in
  *OK*)
    echo "OK : mot de passe défini pour $COMPTE, et vérifié par un contrôle de hachage."
    echo
    echo "Se connecter : https://app.axion-crm-pro.com"
    echo
    echo "⚠️ Si ce mot de passe a transité par un canal non sûr (message, courriel,"
    echo "   conversation), le CHANGER depuis l'interface une fois connecté :"
    echo "   celui-ci devient alors sans valeur."
    ;;
  *INTROUVABLE*)
    echo "ÉCHEC : aucun compte « $COMPTE » dans cette base." >&2
    exit 1
    ;;
  *)
    echo "ÉCHEC : le mot de passe n'a pas été enregistré, ou la vérification a échoué." >&2
    echo "--- sortie brute ---" >&2
    echo "$SORTIE" >&2
    exit 1
    ;;
esac
