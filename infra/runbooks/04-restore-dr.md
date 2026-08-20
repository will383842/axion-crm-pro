# Runbook — Restauration disaster recovery

**Cible :** RPO ≤ 1h, RTO ≤ 4h.

> ## 🔴 AVANT TOUTE COMMANDE DE CE RUNBOOK — l'overlay de production
>
> **Sur l'hôte de production, exporte ceci une fois, dans le shell d'où tu joues
> ce runbook :**
>
> ```bash
> cd /opt/axion-crm-pro
> export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
> ```
>
> **Pourquoi ce n'est pas un détail.** `docker-compose.yml` publie Postgres sur
> `55432` et Redis sur `56379` — le confort du poste de développement. C'est
> l'overlay `docker-compose.prod.yml` qui retire ces publications, avec
> `ports: !override []`.
>
> Un `docker compose up -d` lancé **sans** l'overlay repart du seul fichier de
> base : Compose voit une configuration différente de celle des conteneurs en
> place, et **il les recrée — ports compris**. Ces deux ports ont été trouvés
> ouverts depuis l'extérieur le 2026-08-19, et Redis n'a **aucun mot de passe**.
>
> Et le piège se referme même si tu ne nommes pas la base : `api`, `horizon` et
> `scheduler` portent tous `depends_on: [postgres, redis]`, et Compose monte les
> dépendances sauf si on lui dit `--no-deps`.
>
> Constat `F38-007` (S0). Un runbook qui prescrit le défaut le reproduira aussi
> sûrement qu'un script qui l'exécute.


## Sources de backup
1. **Hetzner Object Storage** (chiffré AES-256) — chaque heure full + WAL streaming
2. **Backblaze B2 off-site** (rule 3-2-1) — réplication asynchrone toutes les 6h

## 1. Provisionner le serveur de remplacement
```bash
hcloud server create --type cpx42 --image ubuntu-24.04 --location fsn1 --name axion-crm-dr
# Installer docker + restaurer infra :
#   git clone … && cd axion-crm-pro
#   export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
#   docker compose up -d
# ⚠️ La machine est NEUVE : aucun shell n'a l'export du preambule. Il doit
#    etre refait ICI, sinon la reprise apres sinistre remonte la pile avec
#    Postgres et Redis publies sur internet -- en urgence, et sans que
#    personne ne le regarde. C'est le pire moment pour rouvrir F38-007.
```

## 2. Restaurer Postgres
```bash
# Récupérer le dernier full + WAL
s3cmd get s3://axion-crm-backups/postgres/$(date +%F)/full.tar.gz - | tar xz -C /tmp/restore
docker exec axion-crm-postgres pg_basebackup -D /tmp/restore -X stream

# Point-in-time recovery jusqu'à T-5min de l'incident
echo "restore_command = 's3cmd get s3://axion-crm-backups/wal/%f %p'" >> postgresql.conf
echo "recovery_target_time = 'YYYY-MM-DD HH:MM:00'" >> recovery.signal
docker compose restart postgres
```

## 3. Restaurer Redis (cache + queues)
Redis est volatile par design — ne pas restaurer, laisser warm-up naturel.
Toutefois, les `magic_links` actifs et `email_validations` non expirés sont perdus → broadcast user (Sprint 8).

## 4. Réindex + warm caches
```bash
docker exec -it axion-crm-api php artisan coverage:refresh-matrix --concurrent
docker exec -it axion-crm-api php artisan cache:clear
```

## 5. Vérification post-restore
```bash
# Vérif chaîne audit
docker exec axion-crm-api php artisan audit:verify-chain

# Vérif RLS effective
docker exec axion-crm-postgres psql -U axion -d axion_crm -c "
  SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000';
  SELECT COUNT(*) FROM companies;  -- doit retourner 0
"
```

## 6. Bascule DNS Cloudflare
```bash
cf-cli zone:set-record axion-crm-pro.com A <new-ip> --proxied
```

**Test trimestriel obligatoire** via `infra/scripts/dr-drill.sh`.
