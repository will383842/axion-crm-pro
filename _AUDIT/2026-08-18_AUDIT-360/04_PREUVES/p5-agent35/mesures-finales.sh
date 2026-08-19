#!/bin/sh
# Mesures finales P5 : attend la fin de la suite complete, puis
#  [A] rejoue CoverageControllerTest SEUL (le 2e rouge de la suite est-il du a la branche
#      ou a l'ordre d'execution ?) ;
#  [B] temoin negatif de OwnerUserSeederTest (F35-008), le seul qui me manquait.
set -u
WT=/c/Users/willi/Documents/Projets/crmpro-wt-p5a35
OUT=/c/Users/willi/Documents/Projets/Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/p5-agent35
SUITE="$OUT/suite-complete-46848d4.txt"

while ! grep -q "Tests:" "$SUITE" 2>/dev/null; do sleep 20; done

{
echo "=== [A] CoverageControllerTest joue SEUL, sur la branche 46848d4 ==="
echo "    (dans la suite complete il rend 1 rouge : 'POST /coverage/launch accepte body valide')"
docker exec -e LOG_CHANNEL=null p5a35 sh -c \
  "cd /var/www/html && php vendor/bin/pest -c phpunit-p5a35.xml tests/Feature/Controllers/CoverageControllerTest.php --no-coverage" 2>&1
echo
echo "=== [B] TEMOIN NEGATIF F35-008 : OwnerUserSeeder ramene a main ==="
git -C "$WT" restore --source=main --worktree -- backend/database/seeders/OwnerUserSeeder.php
docker exec -e LOG_CHANNEL=null p5a35 sh -c \
  "cd /var/www/html && php vendor/bin/pest -c phpunit-p5a35.xml tests/Feature/Seeders/OwnerUserSeederTest.php --no-coverage" 2>&1
echo "--- restauration de la branche"
git -C "$WT" checkout -- backend/database/seeders/OwnerUserSeeder.php
echo
echo "=== [B bis] TEMOIN POSITIF : le meme fichier avec le correctif ==="
docker exec -e LOG_CHANNEL=null p5a35 sh -c \
  "cd /var/www/html && php vendor/bin/pest -c phpunit-p5a35.xml tests/Feature/Seeders/OwnerUserSeederTest.php --no-coverage" 2>&1
echo
echo "=== etat final de la copie de travail ==="
git -C "$WT" status --short
} > "$OUT/mesures-finales.txt" 2>&1
echo TERMINE
