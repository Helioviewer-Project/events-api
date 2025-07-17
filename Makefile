.PHONY: composer-install composer-require up down build shell migrate-status migrate-create migrate-run migrate-rollback seed-run collect
.DEFAULT_GOAL := help

composer-install:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm composer install

composer-require:
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm composer require $(PACKAGE)

up:
	docker compose -f docker/docker-compose.yml up

down:
	docker compose -f docker/docker-compose.yml down

build:
	docker compose -f docker/docker-compose.yml build --no-cache

shell:
	docker compose -f docker/docker-compose.yml exec --user 1000:1000 phpfpm bash

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
	docker compose -f docker/docker-compose.yml run --rm --user 1000:1000 phpfpm php bin/collect.php $(ARGS)

help:
	@echo "Available commands:"
	@echo "  up                    - Start the Docker containers"
	@echo "  down                  - Stop the Docker containers"
	@echo "  build                 - Build the Docker images"
	@echo "  shell                 - Open a bash shell in the PHP container"
	@echo "  composer-install      - Install PHP dependencies via Composer"
	@echo "  composer-require      - Add a new package (use: make composer-require PACKAGE=package-name)"
	@echo "  migrate-status        - Check migration status"
	@echo "  migrate-create        - Create a new migration (use: make migrate-create NAME=MigrationName)"
	@echo "  migrate-run           - Run pending migrations"
	@echo "  migrate-rollback      - Rollback the last migration"
	@echo "  seed-run              - Run database seeders"
	@echo "  collect               - Collect events from sources (use: make collect ARGS='2024-01-01 2024-01-31')"
