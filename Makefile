# Axion CRM Pro — cibles dev / test / deploy

.PHONY: help up down restart build logs ps shell-api shell-app shell-pg \
        migrate seed fresh db-rebuild-local db-rebuild-check \
        test test-backend test-frontend test-workers test-e2e \
        lint typecheck audit pentest dr-drill ign-import-2026 \
        cache-clear queue-flush keys-rotate stop-all

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# --- Cycle de vie stack ----------------------------------------------------
up: ## Démarre toute la stack (Postgres + Redis + Caddy + api + workers + app)
	docker compose up -d

down: ## Stoppe et retire les conteneurs (volumes conservés)
	docker compose down

restart: down up ## Restart complet
	@true

build: ## Rebuild images (multi-stage)
	docker compose build --pull

logs: ## Tail des logs API + Horizon
	# Workers Playwright retirés du compose (2026-08-14, lot L3 décision 1).
	docker compose logs -f --tail=100 api horizon

ps: ## Liste services + healthchecks
	docker compose ps

# --- Shells dans les conteneurs --------------------------------------------
shell-api: ; docker exec -it axion-crm-api sh
shell-app: ; docker exec -it axion-crm-app sh
shell-pg:  ; docker exec -it axion-crm-postgres psql -U axion -d axion_crm

# --- Base de données -------------------------------------------------------
migrate: ## Applique migrations en cours
	docker exec axion-crm-api php artisan migrate

seed: ## Seeds référentiels + démo
	docker exec axion-crm-api php artisan db:seed

fresh: ## migrate:fresh --seed (reset DB complet local)
	docker exec axion-crm-api php artisan migrate:fresh --seed

# --- Reconstruction de la base ---------------------------------------------
#
# 🔴 POURQUOI CETTE CIBLE EXISTE — deux pannes historiques, distinctes, qui
# rendaient la base NON RECONSTRUCTIBLE. Les deux ont été reproduites puis
# corrigées le 2026-08-18 ; le détail (sorties rouges verbatim, mesures) est
# dans `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`.
#
# CAUSE 1 — `part_config` bloquait le DROP TABLE global.
#   `PostgresBuilder::dropAllTables()` (Laravel) énumère les tables des schémas
#   du `search_path` et émet UN SEUL `DROP TABLE … CASCADE`, en n'excluant que
#   la clé de connexion `dont_drop` (`['spatial_ref_sys']` par défaut).
#   `pg_partman` était créé sans clause `SCHEMA`, donc dans `public` : ses tables
#   internes `part_config` / `part_config_sub` entraient dans le lot et
#   PostgreSQL refusait la commande ENTIÈRE —
#     SQLSTATE[2BP01] cannot drop table part_config because extension
#     pg_partman requires it
#   Aucune migration ne s'exécutait ensuite. `RefreshDatabase` (toute la suite
#   Pest) meurt par le même chemin : d'où la consigne « recréer axion_crm_test
#   avant chaque suite complète » qui traînait dans les rapports E2E.
#   ⚠️ Piège : `migrate:fresh` ne lance le DROP QUE si la table `migrations`
#   existe déjà (`FreshCommand::handle()`, `repositoryExists()`). La panne était
#   donc INVISIBLE au premier passage et sur une base neuve de CI — elle
#   n'apparaissait qu'à la DEUXIÈME reconstruction.
#   Correctif : l'extension vit dans son propre schéma `partman`.
#
# CAUSE 2 — réinjection des lignes avant l'existence d'une partition.
#   `2026_05_17_000011` sauvegardait `audit_logs`, la recréait en
#   `PARTITION BY RANGE (created_at)`, puis faisait
#   `INSERT INTO audit_logs SELECT * FROM audit_logs_old` — sans qu'aucune
#   partition n'existe encore —
#     SQLSTATE[23514] no partition of relation "audit_logs" found for row
#   Toute base portant au moins une ligne d'audit tombait dessus. Le correctif
#   de juillet (de5e684) ne traitait PAS ce point : il ne couvrait que l'échec
#   de `create_parent`. Correctif : partitions d'abord, `INSERT` ensuite.
#
# Les deux gardes qui rougissent si l'une des causes revient :
#   backend/tests/Feature/Database/ReconstructionBaseTest.php       (cause 1)
#   backend/tests/Feature/Database/AuditLogsPartitionnementTest.php (cause 2)
# Elles tournent dans le job `backend` de la CI (Pest, bloquant).

db-rebuild-local: ## Reconstruit la base locale de zéro (migrate:fresh + seeds), pg_partman compris
	docker exec -e TELESCOPE_ENABLED=false axion-crm-api php artisan migrate:fresh --seed --force
	@echo "--- extensions de la base reconstruite ---"
	docker exec axion-crm-postgres psql -U axion -d axion_crm \
	  -c "select extname, extversion, n.nspname as schema from pg_extension e join pg_namespace n on n.oid = e.extnamespace order by 1;"

db-rebuild-check: ## GARDE — exige que migrate:fresh passe DEUX FOIS DE SUITE (c'est le vrai critère)
	@echo "== reconstruction n°1 =="
	docker exec -e TELESCOPE_ENABLED=false axion-crm-api php artisan migrate:fresh --force
	@echo "== reconstruction n°2 (celle qui échouait) =="
	docker exec -e TELESCOPE_ENABLED=false axion-crm-api php artisan migrate:fresh --force
	@echo "== pg_partman doit être HORS de public, et gérer réellement audit_logs =="
	@docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc \
	  "select n.nspname from pg_extension e join pg_namespace n on n.oid = e.extnamespace where e.extname = 'pg_partman'" \
	  | grep -qx partman \
	  || { echo "ÉCHEC : pg_partman n'est pas dans le schéma 'partman' — la prochaine reconstruction échouera (cause 1)."; exit 1; }
	@docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc \
	  "select count(*) from partman.part_config where parent_table = 'public.audit_logs'" \
	  | grep -qx 1 \
	  || { echo "ÉCHEC : pg_partman ne gère pas audit_logs (create_parent est retombé sur la partition DEFAULT)."; exit 1; }
	docker exec axion-crm-postgres psql -U axion -d axion_crm \
	  -c "select parent_table, partition_type, partition_interval, premake, retention from partman.part_config;"
	@echo "OK — la base est reconstructible."

# --- Tests ------------------------------------------------------------------
test: test-backend test-frontend test-workers ## Lance tous les tests unit/integration

test-backend:  ; docker exec axion-crm-api composer test
test-frontend: ; docker exec axion-crm-app pnpm test
# Workers désactivés en prod (2026-08-14, lot L3 décision 1) : le code est
# conservé et testé HORS conteneur (même chose que le job CI `workers`).
test-workers:  ; cd workers && pnpm test

test-e2e: ## Lance les E2E Playwright (3 projets : chromium/firefox/mobile-safari)
	cd frontend && pnpm e2e

# --- Quality gates ---------------------------------------------------------
lint:
	docker exec axion-crm-api composer lint
	docker exec axion-crm-app pnpm lint
	cd workers && pnpm lint

typecheck:
	docker exec axion-crm-app pnpm typecheck
	cd workers && pnpm typecheck

# --- Sécurité --------------------------------------------------------------
audit: ## audit:verify-chain
	docker exec axion-crm-api php artisan audit:verify-chain

pentest: ## OWASP self-check
	docker exec axion-crm-api php artisan app:pentest-self-check

dr-drill: ## DR drill (RPO ≤ 1h, RTO ≤ 4h)
	bash infra/scripts/dr-drill.sh

# --- Imports + maintenance -------------------------------------------------
ign-import-2026: ## Import IGN AdminExpress COG 2026
	docker exec axion-crm-api php artisan ign:import-admin-express --year=2026 --layer=all

cache-clear:
	docker exec axion-crm-api php artisan config:clear
	docker exec axion-crm-api php artisan route:clear
	docker exec axion-crm-api php artisan cache:clear

queue-flush:
	docker exec axion-crm-api php artisan queue:flush

# --- Opérations dangereuses ------------------------------------------------
stop-all: ## ARRÊT D'URGENCE (down + volumes — perd données locales)
	@echo "ATTENTION : ceci supprime les volumes locaux Postgres + Redis"
	@read -p "Confirmer ? [y/N] " ans; [ "$$ans" = "y" ] && docker compose down -v || echo "annulé"
