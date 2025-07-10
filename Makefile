.PHONY: composer-install up down build shell

composer-install:
	docker compose -f docker/docker-compose.yml run --rm phpfpm composer install

up:
	docker compose -f docker/docker-compose.yml up -d

down:
	docker compose -f docker/docker-compose.yml down

build:
	docker compose -f docker/docker-compose.yml build

shell:
	docker compose -f docker/docker-compose.yml exec phpfpm bash