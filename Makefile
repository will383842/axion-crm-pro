# Axion CRM Pro — cibles dev / test / deploy

.PHONY: help up down restart build logs ps shell-api shell-app shell-pg \
        migrate seed fresh test test-backend test-frontend test-workers test-e2e \
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

logs: ## Tail des logs API + workers
	docker compose logs -f --tail=100 api horizon worker-google-maps worker-pages-jaunes

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

# --- Tests ------------------------------------------------------------------
test: test-backend test-frontend test-workers ## Lance tous les tests unit/integration

test-backend:  ; docker exec axion-crm-api composer test
test-frontend: ; docker exec axion-crm-app pnpm test
test-workers:  ; docker exec axion-crm-worker-google-maps pnpm test

test-e2e: ## Lance les E2E Playwright (3 projets : chromium/firefox/mobile-safari)
	cd frontend && pnpm e2e

# --- Quality gates ---------------------------------------------------------
lint:
	docker exec axion-crm-api composer lint
	docker exec axion-crm-app pnpm lint
	docker exec axion-crm-worker-google-maps pnpm lint

typecheck:
	docker exec axion-crm-app pnpm typecheck
	docker exec axion-crm-worker-google-maps pnpm typecheck

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
