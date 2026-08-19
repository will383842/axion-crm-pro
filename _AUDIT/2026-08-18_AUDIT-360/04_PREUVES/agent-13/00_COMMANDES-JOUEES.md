# AGENT 13 — commandes jouées

Référence : `git log --oneline -1` → `1145473`.
`git diff --stat c0c453d HEAD -- backend/app/Crm/ backend/app/Support/HmacSignature.php backend/app/Http/Controllers/Internal/ backend/routes/api.php` → **vide** (périmètre identique à `c0c453d`).

## Atelier

Base jetable, créée puis détruite pour cet audit — la base de travail `axion_crm` n'a pas été touchée :

    docker exec axion-crm-postgres psql -U axion -d postgres -c "CREATE DATABASE axion_crm_audit13;"
    docker exec -e DB_DATABASE=axion_crm_audit13 axion-crm-api php artisan migrate --force

Le harnais (`harness.php`) amorce le noyau HTTP de Laravel, bascule la connexion sur `axion_crm_audit13`,
pose `crm.ingest.{enabled,candidates_enabled,hmac_secret}` **en mémoire** (aucun fichier du produit modifié,
aucun `.env` touché), puis dispatche de vraies requêtes HTTP à travers la route, les middlewares et le
contrôleur.

    docker cp harness.php scenario_*.php axion-crm-api:/tmp/
    docker exec axion-crm-api php /tmp/harness.php all     → 01_…txt
    docker exec axion-crm-api php /tmp/harness.php fix     → 02_…txt
    docker exec axion-crm-api php /tmp/harness.php tem     → 04_…txt
    docker exec axion-crm-api php /tmp/harness.php siren   → 05_…txt

## Pièces

| Fichier | Contenu |
|---|---|
| `01_grille-signature-rejeu-dedup-consentement.txt` | signature (témoins + et −), rejeu octet pour octet, fenêtre ±400 s, dédup, vocabulaire, horodatage +7 200 s, consentement v2 (4 cas) |
| `02_reprise-piege10-tags-workspace-pertes.txt` | reprise des cellules polluées : événement inconnu, tags silencieux, **piège 10 avec témoin ASCII**, perte de la personne sans nom, workspace absent |
| `03_locale-journal-idempotence.txt` | `lower()` en `C` vs `en_US.utf8`, colonnes et volumétrie d'`audit_logs`, index UNIQUE d'idempotence |
| `04_temoins-negatifs.txt` | namespace de tag non gouverné → 422 ; la sonde d'écart rend 0 ; `SHOW TimeZone` = `Etc/UTC` |
| `05_lead-reel-sans-siren-100pc-arbitrage.txt` | 6 leads calqués sur le contrat réel du site → 6 `pending_match`, 0 fiche |
| `harness.php`, `scenario_*.php` | le harnais et les scénarios, rejouables tels quels |

## Nettoyage

    docker exec axion-crm-postgres psql -U axion -d postgres -c "DROP DATABASE axion_crm_audit13;"
    docker exec axion-crm-api rm -f /tmp/harness.php /tmp/scenario_*.php /tmp/out_*.txt /tmp/mig13.txt
