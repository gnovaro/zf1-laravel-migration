PHP = docker compose run --rm php php artisan

.PHONY: build up down shell analyze migrate-all migrate-models migrate-controllers migrate-views migrate-routes

# --- Docker ---

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

shell:
	docker compose run --rm php bash

# --- Analysis ---

analyze:
	$(PHP) zf1:analyze $(path) $(if $(json),--json) $(if $(output),--output=$(output))

# --- Migration Commands ---

migrate-all:
	$(PHP) zf1:migrate-all $(path) --target=$(target) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-models:
	$(PHP) zf1:migrate-models $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-controllers:
	$(PHP) zf1:migrate-controllers $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-views:
	$(PHP) zf1:migrate-views $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-routes:
	$(PHP) zf1:migrate-routes $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(force),--force)

# --- Utility ---

artisan:
	$(PHP) $(cmd)
