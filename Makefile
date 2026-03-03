.PHONY: check test demo demo-seed demo-clean demo-verify demo-all clean-install-validate benchmark leaderboard migration-matrix-sqlite migration-matrix-mysql migration-matrix-pgsql

check:
	composer run check

test:
	composer run test

DEMO_DIR ?= demo
DEMO_CORE_DIR := $(DEMO_DIR)/core
DEMO_HOST ?= 127.0.0.1
DEMO_PORT ?= 8787
DEMO_BASE_URL ?= http://$(DEMO_HOST):$(DEMO_PORT)
DEMO_SERVER_HANDLE ?= content
DEMO_DISPATCH_CHECK ?= 1
DEMO_JWT_SECRET ?= emcp-demo-secret-0123456789abcdef0123456789abcdef
SAPI_BASE_PATH ?= api
SAPI_VERSION ?= v1

EVO_BIN ?= evo
EVO_BRANCH ?= 3.5.x
EVO_DB_TYPE ?= sqlite
EVO_DB_NAME ?= database.sqlite
EVO_DB_HOST ?=
EVO_DB_PORT ?=
EVO_DB_USER ?=
EVO_DB_PASSWORD ?=
EVO_ADMIN_USERNAME ?= admin
EVO_ADMIN_EMAIL ?= dmi3yy@evo.im
EVO_ADMIN_PASSWORD ?= 123456
EVO_ADMIN_DIRECTORY ?= manager
EVO_LANGUAGE ?= uk
EMCP_CONSTRAINT ?= *@dev
EMCP_PATH_REPO ?= $(CURDIR)
CLEAN_INSTALL_LOG_PATH ?= $(DEMO_DIR)/clean-install.log

demo:
	@set -eu; \
		GH_TOKEN_VALUE="$${GITHUB_PAT:-$${GITHUB_TOKEN:-$${GH_TOKEN:-}}}"; \
		COMPOSER_CACHE_DIR_VALUE="$${COMPOSER_CACHE_DIR:-/tmp/emcp-composer-cache}"; \
		mkdir -p "$${COMPOSER_CACHE_DIR_VALUE}"; \
		export COMPOSER_CACHE_DIR="$${COMPOSER_CACHE_DIR_VALUE}"; \
		if [ -n "$${GH_TOKEN_VALUE}" ]; then \
			export COMPOSER_AUTH="$$(printf '{"github-oauth":{"github.com":"%s"}}' "$${GH_TOKEN_VALUE}")"; \
			echo "Using GitHub token from ENV (GITHUB_PAT/GITHUB_TOKEN/GH_TOKEN)."; \
		fi; \
		DB_HOST_FLAG=""; \
		DB_PORT_FLAG=""; \
		DB_USER_FLAG=""; \
		DB_PASSWORD_FLAG=""; \
		if [ -n "$(EVO_DB_HOST)" ]; then DB_HOST_FLAG="--db-host=$(EVO_DB_HOST)"; fi; \
		if [ -n "$(EVO_DB_PORT)" ]; then DB_PORT_FLAG="--db-port=$(EVO_DB_PORT)"; fi; \
		if [ -n "$(EVO_DB_USER)" ]; then DB_USER_FLAG="--db-user=$(EVO_DB_USER)"; fi; \
		if [ -n "$(EVO_DB_PASSWORD)" ]; then DB_PASSWORD_FLAG="--db-password=$(EVO_DB_PASSWORD)"; fi; \
		command -v "$(EVO_BIN)" >/dev/null 2>&1 || { \
			echo "Error: command '$(EVO_BIN)' not found. Install installer first: composer global require evolution-cms/installer"; \
			exit 1; \
		}; \
		if [ -d "$(DEMO_DIR)" ]; then \
		echo "Error: '$(DEMO_DIR)' already exists. Run 'make demo-clean' first."; \
		exit 1; \
	fi; \
		"$(EVO_BIN)" install "$(DEMO_DIR)" \
			--cli \
			--branch="$(EVO_BRANCH)" \
			--db-type="$(EVO_DB_TYPE)" \
			--db-name="$(EVO_DB_NAME)" \
			$${DB_HOST_FLAG} \
			$${DB_PORT_FLAG} \
			$${DB_USER_FLAG} \
			$${DB_PASSWORD_FLAG} \
			--admin-username="$(EVO_ADMIN_USERNAME)" \
			--admin-email="$(EVO_ADMIN_EMAIL)" \
			--admin-password="$(EVO_ADMIN_PASSWORD)" \
			--admin-directory="$(EVO_ADMIN_DIRECTORY)" \
			--language="$(EVO_LANGUAGE)" \
		--composer-clear-cache; \
	cd "$(DEMO_CORE_DIR)"; \
	composer config repositories.stask '{"type":"vcs","url":"https://github.com/Seiger/sTask"}'; \
	composer config repositories.sapi '{"type":"vcs","url":"https://github.com/Seiger/sApi"}'; \
	composer config repositories.emcp '{"type":"path","url":"$(EMCP_PATH_REPO)","options":{"symlink":true}}'; \
	php artisan package:installrequire evolution-cms/emcp "$(EMCP_CONSTRAINT)"; \
	php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-config --force; \
	php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-mcp-config --force; \
	php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-stubs --force; \
	php artisan migrate --force; \
	if php -r "require 'vendor/autoload.php'; exit(class_exists('DatabaseSeeder') ? 0 : 1);" >/dev/null 2>&1; then \
		php artisan db:seed --force; \
	else \
		echo "Skipping db:seed: DatabaseSeeder is not defined in this EVO install."; \
	fi; \
	php artisan cache:clear-full; \
	echo "Demo environment is ready in '$(DEMO_DIR)'."; \
	echo "Run 'make demo-verify' to start php -S and run all tests automatically."

demo-seed:
	@set -eu; \
	test -f "$(DEMO_CORE_DIR)/artisan" || { \
		echo "Error: demo is not installed. Run 'make demo' first."; \
		exit 1; \
	}; \
	cd "$(DEMO_CORE_DIR)"; \
	if php -r "require 'vendor/autoload.php'; exit(class_exists('DatabaseSeeder') ? 0 : 1);" >/dev/null 2>&1; then \
		php artisan db:seed --force; \
	else \
		echo "Skipping db:seed: DatabaseSeeder is not defined in this EVO install."; \
	fi; \
	php artisan cache:clear-full

demo-clean:
	@set -eu; \
	rm -rf "$(DEMO_DIR)"

demo-verify:
	@set -eu; \
	test -f "$(DEMO_CORE_DIR)/artisan" || { \
		echo "Error: demo is not installed. Run 'make demo' first."; \
		exit 1; \
	}; \
	DEMO_DIR="$(DEMO_DIR)" \
	DEMO_CORE_DIR="$(DEMO_CORE_DIR)" \
	DEMO_HOST="$(DEMO_HOST)" \
	DEMO_PORT="$(DEMO_PORT)" \
	DEMO_BASE_URL="$(DEMO_BASE_URL)" \
	DEMO_ADMIN_USERNAME="$(EVO_ADMIN_USERNAME)" \
	DEMO_ADMIN_PASSWORD="$(EVO_ADMIN_PASSWORD)" \
	EMCP_SERVER_HANDLE="$(DEMO_SERVER_HANDLE)" \
	EMCP_DISPATCH_CHECK="$(DEMO_DISPATCH_CHECK)" \
	SAPI_JWT_SECRET="$(DEMO_JWT_SECRET)" \
	SAPI_BASE_PATH="$(SAPI_BASE_PATH)" \
	SAPI_VERSION="$(SAPI_VERSION)" \
	sh scripts/demo_verify.sh

demo-all: demo demo-verify

clean-install-validate:
	@set -eu; \
		CLEAN_INSTALL_LOG_PATH="$(CLEAN_INSTALL_LOG_PATH)" sh scripts/clean_install_validation.sh

benchmark:
	@set -eu; \
		php scripts/benchmark/run.php --output=build/benchmarks/simulation-report.json

leaderboard: benchmark
	@set -eu; \
		php scripts/benchmark/leaderboard.php --input=build/benchmarks/simulation-report.json --output=build/benchmarks/leaderboard.md

migration-matrix-sqlite:
	@set -eu; \
		sh scripts/migration_matrix_check.sh sqlite

migration-matrix-mysql:
	@set -eu; \
		sh scripts/migration_matrix_check.sh mysql

migration-matrix-pgsql:
	@set -eu; \
		sh scripts/migration_matrix_check.sh pgsql
