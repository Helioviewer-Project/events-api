.PHONY: composer-install composer-require composer-dump up down build shell shell-root nginx-reload migrate-status migrate-create migrate-run migrate-rollback seed-run collect purge-path recents reset stats logs db-shell db-backup cache-flush fix-regions distribution-build reprocess reprocess-uuid backfill-hpc build-failure-report retry-failures help
.DEFAULT_GOAL := help

# Set compose file based on ENV
ifeq ($(ENV),production)
    DOCKER_COMPOSE = docker compose -f docker/docker-compose.production.yml --env-file=.env
else
    DOCKER_COMPOSE = docker compose -f docker/docker-compose.yml --env-file=.env 
endif

composer-install:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm composer install

composer-require:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm composer require $(PACKAGE)

composer-dump:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm composer dump-autoload

up:
	$(DOCKER_COMPOSE) up

down:
	$(DOCKER_COMPOSE) down

build:
	$(DOCKER_COMPOSE) build --no-cache

shell:
	$(DOCKER_COMPOSE) exec --user $(shell id -u):$(shell id -g) phpfpm bash

shell-root:
	$(DOCKER_COMPOSE) exec --user 0:0 phpfpm bash

db-shell:
	$(DOCKER_COMPOSE) exec postgres sh -c 'psql -U $$POSTGRES_USER -d $$POSTGRES_DB'

db-backup:
	@echo "Creating database backup..."
	@$(DOCKER_COMPOSE) exec phpfpm mkdir -p /u/apps/data/backups
	@$(DOCKER_COMPOSE) exec postgres sh -c 'pg_dump -U $$POSTGRES_USER -d $$POSTGRES_DB' | $(DOCKER_COMPOSE) exec -T phpfpm sh -c 'cat > /u/apps/data/backups/backup_$$(date +%Y%m%d_%H%M%S).sql'
	@echo "Backup saved to storage/backups/"

# Nginx commands
nginx-reload:
	@echo "Reloading nginx configuration..."
	@$(DOCKER_COMPOSE) exec nginx nginx -s reload
	@echo "Nginx configuration reloaded successfully"

# Database Migration Commands
migrate-status:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx status

migrate-create:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx create $(NAME)

migrate-run:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx migrate

migrate-rollback:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx rollback

seed-run:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx seed:run

collect:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/collect.php $(filter-out $@,$(MAKECMDGOALS))

# Wipe an event path and everything attached to it: sidecar JSONs, event rows,
# region links, distribution buckets, regions left with no events, and the
# failure records of whichever sources feed that path. Dry run unless APPLY=1.
#   make purge-path PATHS='CCMC>>Solar Flare Predictions>>ASSA'
#   make purge-path PATHS='CCMC>>Solar Flare Predictions>>ASSA' APPLY=1
purge-path:
	PATHS='$(PATHS)' APPLY='$(APPLY)' CHUNK='$(CHUNK)' $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -e PATHS -e APPLY -e CHUNK phpfpm php bin/purge-path.php

recents:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/recents.php $(filter-out $@,$(MAKECMDGOALS))

stats:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/stats.php

fix-regions:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/fix-regions.php $(filter-out $@,$(MAKECMDGOALS))

distribution-build:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/build-distribution.php

reprocess:
	PATHS='$(PATHS)' APPLY='$(APPLY)' $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -e PATHS -e APPLY phpfpm php bin/reprocess.php

reprocess-uuid:
	UUID='$(UUID)' APPLY='$(APPLY)' $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -e UUID -e APPLY phpfpm php bin/reprocess-uuid.php

backfill-hpc:
	PATHS='$(PATHS)' APPLY='$(APPLY)' FORCE='$(FORCE)' CHUNK='$(CHUNK)' $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -e PATHS -e APPLY -e FORCE -e CHUNK phpfpm php bin/backfill-hpc.php

build-failure-report:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/build-failure-report.php

retry-failures:
	TYPES='$(TYPES)' SOURCES='$(SOURCES)' HASHES='$(HASHES)' LIMIT='$(LIMIT)' APPLY='$(APPLY)' $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -e TYPES -e SOURCES -e HASHES -e LIMIT -e APPLY phpfpm php bin/retry-failures.php

logs:
	$(DOCKER_COMPOSE) exec phpfpm sh -c 'tail -f /u/apps/data/logs/*.log'

cache-flush:
	@echo "Flushing Redis cache..."
	@$(DOCKER_COMPOSE) exec redis redis-cli FLUSHALL
	@echo "Redis cache flushed!"

# Rollback every migration, re-migrate, re-seed. This DROPS ALL TABLES — every
# source goes, not one path — so it is a dry run unless APPLY=1.
# Sidecar JSONs under storage/ are left behind; use purge-path for scoped work.
reset:
	@if [ "$(APPLY)" != "1" ]; then \
	  echo ""; \
	  echo "make reset drops EVERY table (phinx rollback -t 0), then re-migrates and re-seeds."; \
	  echo "It is not scoped to a source or a path — all of this goes:"; \
	  echo ""; \
	  counts=`$(DOCKER_COMPOSE) exec -T postgres sh -c 'psql -U $$POSTGRES_USER -d $$POSTGRES_DB -tA -F" " -c "SELECT (SELECT count(*) FROM events), (SELECT count(*) FROM regions), (SELECT count(*) FROM distributions)"' 2>/dev/null`; \
	  set -- $$counts; \
	  echo "  events:        $${1:-unreadable (is postgres up?)}"; \
	  echo "  regions:       $${2:-?}"; \
	  echo "  distributions: $${3:-?}"; \
	  echo ""; \
	  echo "Only the re-seeded sources come back. Everything collected from an API"; \
	  echo "has to be re-collected, and sidecar JSONs under storage/ are NOT removed,"; \
	  echo "so they are left orphaned."; \
	  echo ""; \
	  echo "Nothing done. To go ahead: make reset APPLY=1"; \
	  echo ""; \
	else \
	  echo "Resetting database (rollback all + migrate + seed)..."; \
	  $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx rollback -t 0 && \
	  $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx migrate && \
	  $(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx seed:run && \
	  echo "Database reset complete!"; \
	fi


# Handle extra arguments for collect command
%:
	@:


help:
	@echo "Available commands:"
	@echo ""
	@echo "Usage:"
	@echo "  Development:  make up"
	@echo "  Production:   ENV=production make up"
	@echo ""
	@echo "Docker Management:"
	@echo "  up                    - Start the Docker containers"
	@echo "  down                  - Stop the Docker containers"
	@echo "  build                 - Build the Docker images"
	@echo "  shell                 - Open a bash shell in the PHP container"
	@echo "  shell-root            - Open a bash shell in the PHP container as root"
	@echo "  nginx-reload          - Reload nginx configuration"
	@echo "  logs                  - Follow application logs (tail -f)"
	@echo ""
	@echo "Composer Management:"
	@echo "  composer-install      - Install PHP dependencies via Composer"
	@echo "  composer-require      - Add a new package (use: make composer-require PACKAGE=package-name)"
	@echo "  composer-dump         - Regenerate Composer autoloader"
	@echo ""
	@echo "Database Management:"
	@echo "  db-shell              - Connect to PostgreSQL database"
	@echo "  db-backup             - Create a database backup (saves to storage/backups/)"
	@echo "  migrate-status        - Check migration status"
	@echo "  migrate-create        - Create a new migration (use: make migrate-create NAME=MigrationName)"
	@echo "  migrate-run           - Run pending migrations"
	@echo "  migrate-rollback      - Rollback the last migration"
	@echo "  seed-run              - Run database seeders"
	@echo "  reset                 - Drop ALL tables, re-migrate, re-seed (every source, not scoped)"
	@echo "                          Dry run: make reset"
	@echo "                          Apply:   make reset APPLY=1"
	@echo ""
	@echo "Event Collection:"
	@echo "  collect               - Collect events from all sources"
	@echo "                          Examples: make collect                         (today)"
	@echo "                                   make collect 2024-01-01              (single day)"
	@echo "                                   make collect 2024-01-01 2024-01-31   (date range)"
	@echo "                                   make collect 2024-01-01 2024-01-31 5 (5-day chunks)"
	@echo ""
	@echo "Data Analysis & Maintenance:"
	@echo "  purge-path            - Delete an event path and everything attached to it: sidecar"
	@echo "                          JSONs, event rows, region links, distribution buckets,"
	@echo "                          orphaned regions and the sources' failure records."
	@echo "                          Matches the path and everything nested under it."
	@echo "                          Dry run: make purge-path PATHS=\"WSA\""
	@echo "                          Apply:   make purge-path PATHS=\"WSA\" APPLY=1"
	@echo "  recents               - Show recent events (use: make recents 10)"
	@echo "  stats                 - Show database statistics"
	@echo "  fix-regions           - Fix NOAA region IDs (add +10000 to IDs < 9000)"
	@echo "                          Dry run: make fix-regions"
	@echo "                          Apply:   make fix-regions apply"
	@echo "  distribution-build    - Build distribution aggregations from events"
	@echo "  reprocess             - Reprocess events from stored sources (dry run by default)"
	@echo "                          Optional: PATHS=\"HEK,HEK>>Flare\" APPLY=1"
	@echo "  reprocess-uuid        - Reprocess specific event(s) by UUID (dry run by default)"
	@echo "                          Usage: UUID=\"uuid1,uuid2\" APPLY=1"
	@echo "  backfill-hpc          - Fill x_hpc/y_hpc/footprint_hpc on existing events (dry run by default)"
	@echo "                          Optional: PATHS=\"HEK,CCMC>>Solar Flare Predictions\" APPLY=1 FORCE=1 CHUNK=200"
	@echo "  build-failure-report  - Rebuild aggregated failure report (powers /exceptions page)"
	@echo "  retry-failures        - Retry stored failure JSONs through processors (dry run by default)"
	@echo "                          Optional: TYPES=\"coordinate_errors\" SOURCES=\"name1,name2\" HASHES=\"sha,sha\" LIMIT=50 APPLY=1"
	@echo ""
	@echo "Cache Management:"
	@echo "  cache-flush           - Flush Redis cache"
