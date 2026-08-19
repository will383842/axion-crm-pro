#!/bin/sh
# Temoin negatif, garde par garde. Pour chaque constat :
#   1. on restaure dans la copie de travail la version `main` (= le code CASSE)
#      des fichiers de PRODUCTION concernes ;
#   2. on rejoue UNIQUEMENT les gardes de ce constat ;
#   3. on remet la version de la branche.
# Une garde qui reste VERTE a l'etape 2 ne prouve rien.
set -u
WT=/c/Users/willi/Documents/Projets/crmpro-wt-p5a35
OUT=/c/Users/willi/Documents/Projets/Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/p5-agent35

jouer() {
  CONSTAT="$1"; shift
  FILTRE="$1"; shift
  echo "############ $CONSTAT ############"
  for f in "$@"; do
    git -C "$WT" restore --source=main --worktree -- "$f" || echo "RESTORE ECHEC $f"
  done
  echo "--- fichiers ramenes a main :"; for f in "$@"; do echo "    $f"; done
  docker exec -e LOG_CHANNEL=null -e TELESCOPE_ENABLED=false p5a35 sh -c \
    "cd /var/www/html && php vendor/bin/pest -c phpunit-p5a35.xml tests/Feature/Auth/GardesAuthentificationAgent35Test.php --no-coverage --filter='$FILTRE'" 2>&1
  echo "--- restauration de la branche"
  for f in "$@"; do git -C "$WT" checkout -- "$f"; done
  echo
}

{
echo "TEMOIN NEGATIF — branche fix/a35-authentification (bdd25eb) contre main (e8924b8)"
echo "date : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "base de test dediee : axion_crm_test_p5a35 (59 migrations)"
echo

jouer "F35-001" "F35-001" backend/bootstrap/app.php
jouer "F35-002" "F35-002" backend/app/Services/Auth/TwoFactorService.php backend/app/Models/User.php backend/app/Http/Controllers/Api/UsersController.php
jouer "F35-003" "F35-003" backend/bootstrap/app.php
jouer "F35-004" "F35-004" backend/app/Services/Auth/HibpChecker.php backend/app/Rules/NotPwnedPassword.php
jouer "F35-005" "F35-005" backend/app/Http/Controllers/Api/Auth/PasswordResetController.php
jouer "F35-006" "F35-006" backend/app/Http/Controllers/Api/Auth/PasswordResetController.php
jouer "F35-009" "F35-009" backend/app/Services/Auth/AuthService.php
jouer "F35-010" "F35-010" backend/config/sanctum.php
jouer "F35-011" "F35-011" backend/app/Http/Requests/Auth/LoginRequest.php
jouer "F35-012" "F35-012" backend/app/Services/Auth/AuthService.php
jouer "F35-013" "F35-013" backend/app/Services/Auth/MagicLinkService.php

echo "############ ETAT FINAL DE LA COPIE DE TRAVAIL ############"
git -C "$WT" status --short
} > "$OUT/temoin-negatif-backend.txt" 2>&1
echo "ecrit : $OUT/temoin-negatif-backend.txt"
