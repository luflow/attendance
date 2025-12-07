
app_name=$(notdir $(CURDIR))
build_tools_directory=$(CURDIR)/build/tools
source_build_directory=$(CURDIR)/build/artifacts/source
source_package_name=$(source_build_directory)/$(app_name)
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
npm=$(shell which npm 2> /dev/null)
composer=$(shell which composer 2> /dev/null)

all: build

# Fetches the PHP and JS dependencies and compiles the JS. If no composer.json
# is present, the composer step is skipped, if no package.json or js/package.json
# is present, the npm step is skipped
.PHONY: build
build:
ifneq (,$(wildcard $(CURDIR)/composer.json))
	make composer
endif
ifneq (,$(wildcard $(CURDIR)/package.json))
	make npm
endif
ifneq (,$(wildcard $(CURDIR)/js/package.json))
	make npm
endif

# Installs composer dependencies for building the app and
# wipes out unused composer files.
.PHONY: composer
composer:
	composer install --no-dev --prefer-dist

# Installs npm dependencies
.PHONY: npm
npm:
ifeq (,$(wildcard $(CURDIR)/package.json))
	cd js && $(npm) ci && $(npm) run build
else
	npm ci && npm run build
endif

# Removes the appstore build
.PHONY: clean
clean:
	rm -rf ./build

# Same as clean but also removes dependencies installed by composer, bower and
# npm
.PHONY: distclean
distclean: clean
	rm -rf vendor
	rm -rf node_modules
	rm -rf js/vendor
	rm -rf js/node_modules

# Builds the source and appstore package
.PHONY: dist
dist:
	make source
	make appstore

# Builds the source package
.PHONY: source
source:
	rm -rf $(source_build_directory)
	mkdir -p $(source_build_directory)
	tar cvzf $(source_package_name).tar.gz ../$(app_name) \
	--exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/js/node_modules" \
	--exclude="../$(app_name)/node_modules" \
	--exclude="../$(app_name)/*.log" \
	--exclude="../$(app_name)/js/*.log" \

# Builds the source package for the app store, ignores php and js tests
.PHONY: appstore
appstore:
	make distclean
	make build
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)
	tar cvzf $(appstore_package_name).tar.gz \
	--exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/tests" \
	--exclude="../$(app_name)/Makefile" \
	--exclude="../$(app_name)/*.log" \
	--exclude="../$(app_name)/phpunit*xml" \
	--exclude="../$(app_name)/composer.*" \
	--exclude="../$(app_name)/js/node_modules" \
	--exclude="../$(app_name)/js/tests" \
	--exclude="../$(app_name)/js/test" \
	--exclude="../$(app_name)/js/*.log" \
	--exclude="../$(app_name)/js/package.json" \
	--exclude="../$(app_name)/js/bower.json" \
	--exclude="../$(app_name)/js/karma.*" \
	--exclude="../$(app_name)/js/protractor.*" \
	--exclude="../$(app_name)/package.json" \
	--exclude="../$(app_name)/bower.json" \
	--exclude="../$(app_name)/karma.*" \
	--exclude="../$(app_name)/protractor\.*" \
	--exclude="../$(app_name)/.*" \
	--exclude="../$(app_name)/js/.*" \
	--exclude="../$(app_name)/src" \
	--exclude="../$(app_name)/node_modules" \
	../$(app_name) \

.PHONY: test
test:
	$(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c tests/phpunit.xml
	$(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml

.PHONY: unittest
unittest:
	$(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c tests/phpunit.xml

.PHONY: integrationtest
integrationtest:
	$(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml

.PHONY: coverage
coverage:
	XDEBUG_MODE=coverage $(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c tests/phpunit.xml --coverage-php coverage_unittests.cov
	XDEBUG_MODE=coverage $(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml --coverage-php coverage_integrationtests.cov
	XDEBUG_MODE=coverage $(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpcov/phpcov merge --clover coverage.xml .

.PHONY: html-coverage
html-coverage:
	XDEBUG_MODE=coverage $(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c tests/phpunit.xml --coverage-php coverage_unittests.cov
	XDEBUG_MODE=coverage $(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml --coverage-php coverage_integrationtests.cov
	XDEBUG_MODE=coverage $(CURDIR)/vendor-bin/phpunit/vendor/phpunit/phpcov/phpcov merge --html coverage_html .

.PHONY: lint
lint: composer
	composer run lint
	composer run cs:check

.PHONY: lint-fix
lint-fix: composer
	composer run cs:fix