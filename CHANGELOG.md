# Changelog

All notable changes to AgoraPress will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Core currently reports `AP_VERSION` `0.1.0-dev` (see `ap-includes/version.php`).
No tagged public release yet — ship notes accumulate under **[Unreleased]** until the first version is cut.

## [Unreleased]

### Added

- Initial project structure and guiding documents
- Repository layout matching SPEC.md §2: `index.php`, `ap-config-sample.php`, `.htaccess`, nginx example, `composer.json`, `docker-compose.yml`, `LICENSE` (GPLv2-or-later), `ap-admin/`, `ap-includes/` (core class stubs + `compatibility/`), `ap-content/` (themes, plugins, mu-plugins, languages, uploads), `tests/`
- Structure verification: `php tests/Structure/assert-structure.php`, `tests/test_repository_structure.py`, `tests/Structure/RepositoryStructureTest.php`
- Coding standards: PSR-12 adapted via `phpcs.xml.dist`, `CODING_STANDARDS.md`, PHPCS dev dependency, and Composer scripts `cs` / `cs:check` / `cs:fix`
- Docker Compose local stack: `docker-compose.yml` + `docker/Dockerfile` (PHP 8.3 Apache, SPEC extensions, mod_rewrite), MySQL 8 utf8mb4 with healthchecks; contract tests under `tests/Docker/` and `tests/test_docker_compose.py`
- Front controller + bootstrap: `index.php` loads `ap-includes/bootstrap.php` / `version.php`; missing `ap-config.php` (or PHP below 8.2) returns a friendly HTTP 503 HTML page without fatals; installed path loads config + core includes. Tests: `tests/Bootstrap/`, `tests/test_bootstrap.py`
- Sample site config `ap-config-sample.php`: DB driver (mysql default; sqlite/pgsql documented), utf8mb4 + `utf8mb4_unicode_ci`, `$table_prefix = 'ap_'`, auth keys/salts, debug flags off, `AP_TELEMETRY` false, guarded `AP_ABSPATH`, Docker Compose credential notes. Tests: `tests/Config/`, `tests/test_config_sample.py`
- README.md: vision summary (core principles, three module toggles, non-goals), requirements, Docker Compose + manual quick-start, layout, development commands, license/links. Tests: `tests/Readme/`, `tests/test_readme.py`
- Basic PHPUnit / static analysis skeleton: `phpunit.xml.dist` (bootstrap `tests/bootstrap.php`, suite under `tests/`, source `ap-includes/`), Composer scripts `test` / `cs` / `cs:check` / `cs:fix`, PHPCS via `phpcs.xml.dist`, GitHub Actions CI (PHP 8.2–8.4 hard-fail). Skeleton contract tests: `tests/Phpunit/`, `tests/test_phpunit_skeleton.py`
- This changelog (`CHANGELOG.md`) following Keep a Changelog + SemVer. Contract tests: `tests/Changelog/`, `tests/test_changelog.py`
