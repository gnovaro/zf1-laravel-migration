PHP = docker compose run --rm php php artisan

.DEFAULT_GOAL = help

.PHONY: help build up down ssh analyze migrate-all migrate-models migrate-controllers migrate-views migrate-routes artisan

help: ## List available targets
	@echo "Usage: make <target> [args]"
	@echo ""
	@echo "TARGETS:"
	@sed -n 's/^\([a-z][a-z-]*\):.*## \(.*\)/  \1\t\2/p' $(MAKEFILE_LIST) | \
		awk -F'\t' '{printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "COMMON ARGS:"
	@echo "  path=          ZF1 project path (source)"
	@echo "  target=        Output Laravel project path"
	@echo "  app=           Filter by app (gps, clinosweb, corazon)"
	@echo "  module=        Filter by module"
	@echo "  force=true     Skip confirmation prompts"
	@echo "  json=true      JSON output (analyze only)"
	@echo "  output=        Save analysis to file (analyze only)"
	@echo ""
	@echo "EXAMPLES:"
	@echo "  make analyze path=/var/www/zf1"
	@echo "  make migrate-all path=/var/www/zf1 target=/var/www/laravel app=gps"
	@echo "  make migrate-models path=/var/www/zf1 target=/var/www/laravel force=true"

# --- Docker ---

build: ## Build the Docker image
	docker compose build

up: ## Start the container in background
	docker compose up -d

down: ## Stop the container
	docker compose down

ssh: ## Open a shell inside the container
	docker compose run --rm php bash

# --- Analysis ---

analyze: ## Analyze a ZF1 project: make analyze path=/var/www/zf1
	$(PHP) zf1:analyze $(path) $(if $(json),--json) $(if $(output),--output=$(output))

# --- Migration Commands ---

migrate-all: ## Run full migration wizard: make migrate-all path= target=
	$(PHP) zf1:migrate-all $(path) --target=$(target) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-models: ## Migrate ZF1 models to Eloquent: make migrate-models path= target=
	$(PHP) zf1:migrate-models $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-controllers: ## Migrate ZF1 controllers to Laravel: make migrate-controllers path= target=
	$(PHP) zf1:migrate-controllers $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-views: ## Migrate .phtml views to Blade: make migrate-views path= target=
	$(PHP) zf1:migrate-views $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(module),--module=$(module)) $(if $(force),--force)

migrate-routes: ## Generate routes from ZF1 structure: make migrate-routes path= target=
	$(PHP) zf1:migrate-routes $(path) $(if $(target),--target=$(target)) $(if $(app),--app=$(app)) $(if $(force),--force)

# --- Utility ---

artisan: ## Run any artisan command: make artisan cmd="route:list"
	$(PHP) $(cmd)
