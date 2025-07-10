.PHONY: composer-install up down build shell

composer-install:
	docker compose -f docker/docker-compose.yml run --rm --user 33:33 phpfpm composer install

up:
	docker compose -f docker/docker-compose.yml up

down:
	docker compose -f docker/docker-compose.yml down

build:
	docker compose -f docker/docker-compose.yml build --no-cache

shell:
	docker compose -f docker/docker-compose.yml exec --user 33:33 phpfpm bash
