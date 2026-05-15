APP = docker compose exec app

# ── Help ──────────────────────────────────────────────────────────────────────
help:
	@echo ""
	@echo "  Docker"
	@echo "    up                 Start all containers"
	@echo "    down               Stop all containers"
	@echo "    build-image        Rebuild app image and restart container"
	@echo "    restart            Restart app + horizon containers"
	@echo "    logs               Tail app container logs"
	@echo "    shell              Open bash shell in app container"
	@echo ""
	@echo "  Database"
	@echo "    migrate            Run pending migrations"
	@echo "    migrate-fresh      Wipe DB and re-migrate (dev only)"
	@echo "    migrate-rollback   Rollback last migration batch"
	@echo ""
	@echo "  Testing"
	@echo "    test               Run full Pest suite"
	@echo "    test-unit          Unit tests only"
	@echo "    test-feature       Feature tests only"
	@echo "    test-filter        Run tests matching name  filter=\"ExportDownload\""
	@echo "    test-coverage      Run suite with coverage report"
	@echo ""
	@echo "  Queue"
	@echo "    queue-work         Start queue worker"
	@echo "    queue-flush        Flush failed jobs"
	@echo "    horizon-pause      Pause Horizon"
	@echo "    horizon-continue   Resume Horizon"
	@echo ""
	@echo "  Cache"
	@echo "    cache-clear        Clear app cache"
	@echo "    config-clear       Clear config cache"
	@echo "    config-cache       Rebuild config cache"
	@echo "    clear-all          Clear all caches (cache + config + route + view)"
	@echo ""
	@echo "  Assets"
	@echo "    build              Build frontend assets (prod)"
	@echo "    dev                Start Vite dev server"
	@echo ""
	@echo "  Misc"
	@echo "    tinker             Open Artisan REPL"
	@echo "    routes             List all registered routes"
	@echo ""

# ── Docker ────────────────────────────────────────────────────────────────────
up:
	docker compose up -d

build-image:
	docker compose build app
	docker compose up -d app

down:
	docker compose down

restart:
	docker compose restart app horizon

logs:
	docker compose logs -f app

# ── App shell ─────────────────────────────────────────────────────────────────
shell:
	docker compose exec app bash

# ── Database ──────────────────────────────────────────────────────────────────
migrate:
	$(APP) php artisan migrate

migrate-fresh:
	$(APP) php artisan migrate:fresh --seed

migrate-rollback:
	$(APP) php artisan migrate:rollback

# ── Testing ───────────────────────────────────────────────────────────────────
test:
	$(APP) ./vendor/bin/pest

test-unit:
	$(APP) ./vendor/bin/pest --testsuite=Unit

test-feature:
	$(APP) ./vendor/bin/pest --testsuite=Feature

test-filter:
	$(APP) ./vendor/bin/pest --filter="$(filter)"

test-coverage:
	$(APP) ./vendor/bin/pest --coverage

# ── Queue ─────────────────────────────────────────────────────────────────────
queue-work:
	$(APP) php artisan queue:work

queue-flush:
	$(APP) php artisan queue:flush

horizon-pause:
	$(APP) php artisan horizon:pause

horizon-continue:
	$(APP) php artisan horizon:continue

# ── Cache ─────────────────────────────────────────────────────────────────────
cache-clear:
	$(APP) php artisan cache:clear

config-clear:
	$(APP) php artisan config:clear

config-cache:
	$(APP) php artisan config:cache

clear-all: cache-clear config-clear
	$(APP) php artisan route:clear
	$(APP) php artisan view:clear

# ── Assets ────────────────────────────────────────────────────────────────────
build:
	$(APP) npm run build

dev:
	$(APP) npm run dev

# ── Misc ──────────────────────────────────────────────────────────────────────
tinker:
	$(APP) php artisan tinker

routes:
	$(APP) php artisan route:list

.PHONY: help up build-image down restart logs shell \
        migrate migrate-fresh migrate-rollback \
        test test-unit test-feature test-filter test-coverage \
        queue-work queue-flush horizon-pause horizon-continue \
        cache-clear config-clear config-cache clear-all \
        build dev tinker routes
