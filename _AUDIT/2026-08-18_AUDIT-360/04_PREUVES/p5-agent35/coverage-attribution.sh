#!/bin/sh
# ATTRIBUTION du rouge `CoverageControllerTest > POST /coverage/launch accepte body valide`.
# Il est reproductible en isolation sur la branche. Est-il present sur `main` AUSSI ?
# Si oui : preexistant, pas imputable a la branche. Si non : regression du lot.
set -u
WT=/c/Users/willi/Documents/Projets/crmpro-wt-p5a35
OUT=/c/Users/willi/Documents/Projets/Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/p5-agent35

while ! grep -q "etat final de la copie de travail" "$OUT/mesures-finales.txt" 2>/dev/null; do sleep 20; done

{
echo "=== bascule du worktree sur main (e8924b8) ==="
git -C "$WT" checkout -- backend/tests/bootstrap.php
git -C "$WT" checkout --detach main 2>&1 | tail -2
sed -i "s/const TEST_DATABASE_NAME = 'axion_crm_test';/const TEST_DATABASE_NAME = 'axion_crm_test_p5a35';/" "$WT/backend/tests/bootstrap.php"
git -C "$WT" log --oneline -1
grep -n "TEST_DATABASE_NAME =" "$WT/backend/tests/bootstrap.php"
echo
echo "=== CoverageControllerTest sur main ==="
docker exec -e LOG_CHANNEL=null p5a35 sh -c \
  "cd /var/www/html && php vendor/bin/pest -c phpunit-p5a35.xml tests/Feature/Controllers/CoverageControllerTest.php --no-coverage" 2>&1
echo
echo "=== retour sur 46848d4 ==="
git -C "$WT" checkout -- backend/tests/bootstrap.php
git -C "$WT" checkout --detach 46848d4 2>&1 | tail -2
git -C "$WT" log --oneline -1
git -C "$WT" status --short
} > "$OUT/coverage-attribution.txt" 2>&1
echo TERMINE
