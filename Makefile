.PHONY: check test demo demo-seed demo-clean

check:
	composer run check

test:
	composer run test

DEMO_DIR ?= demo
DEMO_CORE_DIR := $(DEMO_DIR)/core
DEMO_CUSTOM_DIR := $(DEMO_CORE_DIR)/custom

EVO_BIN ?= evo
EVO_BRANCH ?= 3.5.x
EVO_DB_TYPE ?= sqlite
EVO_DB_NAME ?= database.sqlite
EVO_ADMIN_USERNAME ?= admin
EVO_ADMIN_EMAIL ?= dmi3yy@gmail.com
EVO_ADMIN_PASSWORD ?= 123456
EVO_ADMIN_DIRECTORY ?= manager
EVO_LANGUAGE ?= uk
EMCP_CONSTRAINT ?= *@dev
EMCP_PATH_REPO ?= ../../../

demo:
	@set -eu; \
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
		--admin-username="$(EVO_ADMIN_USERNAME)" \
		--admin-email="$(EVO_ADMIN_EMAIL)" \
		--admin-password="$(EVO_ADMIN_PASSWORD)" \
		--admin-directory="$(EVO_ADMIN_DIRECTORY)" \
		--language="$(EVO_LANGUAGE)" \
		--composer-clear-cache; \
	cd "$(DEMO_CUSTOM_DIR)"; \
	composer config repositories.emcp '{"type":"path","url":"$(EMCP_PATH_REPO)","options":{"symlink":true}}'; \
	cd ..; \
	php artisan package:installrequire evolution-cms/emcp "$(EMCP_CONSTRAINT)"; \
	php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-config --force; \
	php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-mcp-config --force; \
	php artisan vendor:publish --provider="EvolutionCMS\\eMCP\\eMCPServiceProvider" --tag=emcp-stubs --force; \
	php artisan migrate --force; \
	php artisan db:seed --force; \
	php artisan cache:clear-full; \
	echo "Demo environment is ready in '$(DEMO_DIR)'."

demo-seed:
	@set -eu; \
	test -f "$(DEMO_CORE_DIR)/artisan" || { \
		echo "Error: demo is not installed. Run 'make demo' first."; \
		exit 1; \
	}; \
	cd "$(DEMO_CORE_DIR)"; \
	php artisan db:seed --force; \
	php artisan cache:clear-full

demo-clean:
	@set -eu; \
	rm -rf "$(DEMO_DIR)"
