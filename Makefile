.PHONY: composer-install composer-require composer-dump up down build shell shell-root nginx-reload migrate-status migrate-create migrate-run migrate-rollback seed-run collect recents reset stats logs help
.DEFAULT_GOAL := help

# Set compose file based on ENV
ifeq ($(ENV),production)
    DOCKER_COMPOSE = docker compose -f docker/docker-compose.production.yml --env-file=.env
else
    DOCKER_COMPOSE = docker compose -f docker/docker-compose.yml
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
	$(DOCKER_COMPOSE) exec phpfpm bash

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

recents:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/recents.php $(filter-out $@,$(MAKECMDGOALS))

stats:
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm php bin/stats.php

logs:
	tail -f storage/logs/*.log

reset:
	@echo "Resetting database (rollback all + migrate + seed)..."
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx rollback -t 0
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx migrate
	$(DOCKER_COMPOSE) run --rm --user $(shell id -u):$(shell id -g) phpfpm vendor/bin/phinx seed:run
	@echo "Database reset complete!"


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
	@echo "  shell                 - Open a bash shell in the PHP container (user 1000:1000)"
	@echo "  shell-root            - Open a bash shell in the PHP container as root"
	@echo "  nginx-reload          - Reload nginx configuration"
	@echo ""
	@echo "Composer Management:"
	@echo "  composer-install      - Install PHP dependencies via Composer"
	@echo "  composer-require      - Add a new package (use: make composer-require PACKAGE=package-name)"
	@echo "  composer-dump         - Regenerate Composer autoloader"
	@echo ""
	@echo "Database Management:"
	@echo "  migrate-status        - Check migration status"
	@echo "  migrate-create        - Create a new migration (use: make migrate-create NAME=MigrationName)"
	@echo "  migrate-run           - Run pending migrations"
	@echo "  migrate-rollback      - Rollback the last migration"
	@echo "  seed-run              - Run database seeders"
	@echo "  reset                 - Reset database (rollback all migrations, migrate, and seed)"
	@echo ""
	@echo "Event Collection:"
	@echo "  collect               - Collect events from all sources"
	@echo "                          Examples: make collect                         (today, daily chunks)"
	@echo "                                   make collect 2024-01-01              (single day)"
	@echo "                                   make collect 2024-01-01 2024-01-31   (date range, daily chunks)"
	@echo "                                   make collect 2024-01-01 2024-01-31 5 (date range, 5-day chunks)"
	@echo ""
	@echo "Data Analysis:"
	@echo "  recents               - Show the most recent events from the database (use: make recents 10)"
	@echo "  stats                 - Show database statistics grouped by source and path"
	@echo "  logs                  - Follow application logs (tail -f)"
