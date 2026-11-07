#!/usr/bin/env bash
# Bibliotheque PARTAGEE : l'installateur ET les tests sourcent ce fichier (une seule
# logique de rendu, jamais dupliquee). A sourcer ; ne rien executer a la source.
# Variables attendues : IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL
#                       IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN
# CLE_PRIVEE_FICHIER (defaut /etc/wireguard/cle-privee) : cle du serveur, lue PAR FICHIER.

# Rend un modele. Rien de secret en argument de commande : la cle privee du serveur
# est lue PAR FICHIER (awk getline), jamais via $(...) ni sur une ligne de commande.
rendre() { # rendre <modele> <sortie>
  local cle="${CLE_PRIVEE_FICHIER:-/etc/wireguard/cle-privee}"
  sed -e "s|<ip-envoi>|$IP_ENVOI|g" -e "s|<ip-gestion>|$IP_GESTION|g" \
      -e "s|<ip-crm-prod>|$IP_CRM_PROD|g" -e "s|<ip-resolveur>|$IP_RESOLVEUR|g" \
      -e "s|<ip-tunnel-admin>|$IP_TUNNEL_ADMIN|g" -e "s|<ip-tunnel>|$IP_TUNNEL|g" \
      -e "s|<port-wg>|$PORT_WG|g" -e "s|<cle-publique-admin>|$CLE_PUBLIQUE_ADMIN|g" "$1" \
    | CLE_FICHIER="$cle" awk 'BEGIN { if ((getline k < ENVIRON["CLE_FICHIER"]) <= 0) k = "<cle-privee-serveur>" }
           { gsub(/<cle-privee-serveur>/, k) } 1' >"$2"
  if grep -q '<[a-z-]\+>' "$2"; then echo "marqueur non remplace dans $2" >&2; return 1; fi
}
