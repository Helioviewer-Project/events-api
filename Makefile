.PHONY: composer-install composer-require composer-dump up down build shell migrate-status migrate-create migrate-run migrate-rollback seed-run collect recents reset stats
.DEFAULT_GOAL := help

composer-install:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm composer install

composer-require:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm composer require $(PACKAGE)

composer-dump:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm composer dump-autoload

up:
	docker compose -f docker/docker-compose.yml up

down:
	docker compose -f docker/docker-compose.yml down

build:
	docker compose -f docker/docker-compose.yml build --no-cache

shell:
	docker compose -f docker/docker-compose.yml exec --user 1000:1000 phpfpm bash

shell-root:
	docker compose -f docker/docker-compose.yml exec phpfpm bash

# Nginx commands
nginx-reload:
	@echo "Reloading nginx configuration..."
	@docker compose -f docker/docker-compose.yml exec nginx nginx -s reload
	@echo "Nginx configuration reloaded successfully"

# Database Migration Commands
migrate-status:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx status

migrate-create:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx create $(NAME)

migrate-run:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx migrate

migrate-rollback:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx rollback

seed-run:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx seed:run

collect:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm php bin/collect.php $(filter-out $@,$(MAKECMDGOALS))

recents:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm php bin/recents.php $(filter-out $@,$(MAKECMDGOALS))

stats:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm php bin/stats.php


reset:
	@echo "Resetting database (rollback all + migrate + seed)..."
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx rollback -t 0
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx migrate
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm vendor/bin/phinx seed:run
	@echo "Database reset complete!"

# Handle extra arguments for collect command
%:
	@:


help:
	@echo "Available commands:"
	@echo "  up                    - Start the Docker containers"
	@echo "  down                  - Stop the Docker containers"
	@echo "  build                 - Build the Docker images"
	@echo "  shell                 - Open a bash shell in the PHP container"
	@echo "  composer-install      - Install PHP dependencies via Composer"
	@echo "  composer-require      - Add a new package (use: make composer-require PACKAGE=package-name)"
	@echo "  composer-dump         - Regenerate Composer autoloader"
	@echo "  migrate-status        - Check migration status"
	@echo "  migrate-create        - Create a new migration (use: make migrate-create NAME=MigrationName)"
	@echo "  migrate-run           - Run pending migrations"
	@echo "  migrate-rollback      - Rollback the last migration"
	@echo "  seed-run              - Run database seeders"
	@echo "  collect               - Collect events from all sources"
	@echo "                          Examples: make collect                         (today, daily chunks)"
	@echo "                                   make collect 2024-01-01              (single day)"
	@echo "                                   make collect 2024-01-01 2024-01-31   (date range, daily chunks)"
	@echo "                                   make collect 2024-01-01 2024-01-31 5 (date range, 5-day chunks)"
	@echo "  recents               - Show the most recent events from the database (use: make recents 10)"
	@echo "  stats                 - Show database statistics grouped by source and path"
	@echo "  reset                 - Reset database (rollback all migrations, migrate, and seed)"
