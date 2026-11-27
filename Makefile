DEV  = HOST_UID=$(shell id -u) HOST_GID=$(shell id -g) docker compose -f compose.yaml -f compose.dev.yaml
TEST = docker compose -f compose.yaml -f compose.test.yaml

.PHONY: help demo down reset dev dev-down migrate migrate-status test test-api test-web logs

help: ## List the available targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sed -E 's/:.*## /\t/'

demo: ## Build and start the local demo on http://localhost:8080
	docker compose up -d --build --wait

down: ## Stop the demo (data is kept)
	docker compose down

reset: ## Stop the demo and DELETE all demo data
	docker compose down -v

dev: ## Development with hot reload on http://localhost:3000
	$(DEV) up --build

dev-down: ## Stop development (data is kept)
	$(DEV) down

migrate: ## Run pending migrations in development (as app_owner)
	$(DEV) run --rm migrate

migrate-status: ## Show migration status in development (as app_owner)
	$(DEV) run --rm migrate php artisan migrate:status

test: test-api test-web ## Run all tests

test-api: ## Pest against a real PostgreSQL (isolated project)
	$(TEST) build api-test
	$(TEST) run --rm api-test; status=$$?; $(TEST) down -v; exit $$status

test-web: ## Type check and Vitest
	docker build --target test -t cortex-web:test ./web
	docker run --rm cortex-web:test sh -c "npm run typecheck && npm run test"

logs: ## Follow the demo logs
	docker compose logs -f
