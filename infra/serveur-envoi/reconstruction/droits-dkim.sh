#!/usr/bin/env bash
# K16 : droits des cles DKIM. Source par reconstruire.sh (et par ses tests), jamais lance seul.
# KumoMTA (docker-compose.yml : group_add 10025) lit les cles via le groupe dedie : cles 0640,
# dossiers 0750, groupe axion-dkim (GID 10025). Des cles 0600 root lui sont ILLISIBLES :
# tout serait refuse en 451 (voir RUNBOOK.md, « DKIM (K5) »).
GROUPE_DKIM_DEFAUT=axion-dkim
GID_DKIM=10025

# groupe_dkim_assurer <groupe> <reel 0|1> : cree le groupe de GID 10025 s'il manque ; refuse tout conflit.
groupe_dkim_assurer() {
  local g="$1" reel="$2" gid_actuel nom_du_gid
  gid_actuel="$(getent group -- "$g" | cut -d: -f3 || true)"
  if [[ -n "$gid_actuel" ]]; then
    [[ "$g" != "$GROUPE_DKIM_DEFAUT" || "$gid_actuel" == "$GID_DKIM" ]] \
      || { echo "REFUS : le groupe $g existe avec le GID $gid_actuel (attendu $GID_DKIM, voir docker-compose.yml)" >&2; return 1; }
    echo "= groupe $g deja present (GID $gid_actuel)"
    return 0
  fi
  nom_du_gid="$(getent group "$GID_DKIM" | cut -d: -f1 || true)"
  [[ -z "$nom_du_gid" ]] || { echo "REFUS : le GID $GID_DKIM est deja pris par « $nom_du_gid »" >&2; return 1; }
  echo "+ groupadd -g $GID_DKIM $g"
  if [[ "$reel" == 1 ]]; then groupadd -g "$GID_DKIM" "$g"; fi
}

# droits_dkim_corriger <repertoire> <groupe> <reel 0|1> : verifie TOUTES les cles et dossiers deja presents,
# corrige (reel) ou signale (a blanc) tout ecart. Code 0 = rien a corriger ou corrige ; 3 = ecart signale a blanc.
droits_dkim_corriger() {
  local rep="$1" g="$2" reel="$3" f attendu ecart=0 mode grp
  [[ -d "$rep" ]] || return 0
  while IFS= read -r -d '' f; do
    if [[ -d "$f" ]]; then attendu=750; else
      case "$f" in *.pem) attendu=640 ;; *) continue ;; esac
    fi
    mode="$(stat -c %a -- "$f")"; grp="$(stat -c %G -- "$f")"
    if [[ "$mode" != "$attendu" || "$grp" != "$g" ]]; then
      ecart=1
      echo "+ droits a corriger : $f ($mode $grp -> $attendu $g)"
      if [[ "$reel" == 1 ]]; then chgrp -- "$g" "$f"; chmod -- "0$attendu" "$f"; fi
    fi
  done < <(find "$rep" \( -type d -o -name '*.pem' \) -print0)
  [[ $ecart == 0 || "$reel" == 1 ]] || return 3
  return 0
}
