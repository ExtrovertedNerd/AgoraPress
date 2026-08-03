# AgoraPress Coding Standards

**Base:** [PSR-12](https://www.php-fig.org/psr/psr-12/) (adapted)  
**Enforcement:** PHP_CodeSniffer via `phpcs.xml.dist`  
**SPEC:** §6 Coding Standards

## Quick commands

```bash
composer cs          # report style issues (alias of cs:check)
composer cs:check    # same, non-zero exit on violations
composer cs:fix     # auto-fix what PHPCBF can safely correct
composer analyse     # PHPStan static analysis (level 3, ap-includes)
composer phpstan     # alias of analyse
composer test        # PHPUnit
```

Requires dev dependencies: `composer install`.

## What “PSR-12 adapted” means

We follow PSR-12 for **formatting and structure** (braces, indentation, visibility, type declarations, control structures, etc.) with deliberate exceptions so the core stays a **hybrid procedural + OOP** codebase familiar to classic WordPress developers.

| Area | Rule |
|------|------|
| Indentation | 4 spaces (no tabs) |
| Line endings | LF; files end with a single newline |
| Keywords / constants | `true` / `false` / `null` lowercase |
| Visibility | Required on all properties and methods |
| Type hints | Prefer parameter, return, and property types (PHP 8.2+) |
| Strict types | `declare(strict_types=1);` on every PHP file where practical |
| PHPDoc | Public APIs, non-obvious logic, and file-level `@package AgoraPress` |
| jQuery | **No** jQuery in new core code |

### Intentional adaptations (not pure PSR-1/12)

1. **Class names** — Core classes use the `AP_` prefix with underscores (`AP_DB`, `AP_Query`, `AP_User`), not namespaced `StudlyCaps` only. This matches the hybrid WP-inspired API.
2. **No required namespaces on core includes** — Global class names and `ap_*` functions are the public surface for themes/plugins (similar to classic WordPress).
3. **File naming** — `class-ap-*.php` under `ap-includes/` per SPEC layout, not PSR-4 path mapping for core.
4. **Procedural files** — `functions.php`, `hooks.php`, and bootstrap may declare symbols and later register hooks at load time.
5. **Functions** — Global helpers use `ap_` snake_case (`ap_add_action`, `ap_apply_filters`).
6. **Methods** — Prefer camelCase on class methods (PSR-12). Keep procedural APIs as `ap_*` functions.

### Out of scope for core sniffs

- `ap-content/` (themes, plugins, uploads) — third-party and site content.
- `vendor/` — Composer packages.
- `.hephaestus/` — forge process state (never committed).

## PHP version

- Target: **PHP 8.2+** (8.3 / 8.4 recommended).
- Use modern language features carefully; keep syntax and behavior compatible with 8.2.

## Static analysis (PHPStan)

**Enforcement:** PHPStan via `phpstan.neon.dist`  
**Level:** 3 (balanced for hybrid procedural + OOP core)

| Area | Policy |
|------|--------|
| Paths | `ap-includes/` (core library API) |
| Excluded | `ap-includes/compatibility/` (classic WP shims) |
| Bootstrap | `tests/phpstan-bootstrap.php` (constants + classmap, no DB) |
| Goal | Catch real type / return / PHPDoc mistakes without fighting intentional `class_exists` / `method_exists` guards used for partial bootstrap |

Raise the level gradually when the codebase is ready; do not lower it without a tracked reason. Prefer fixing code and PHPDoc over ignore comments.

## Tests

- PHPUnit for critical paths (`composer test`).
- Structure / config smoke tests live under `tests/`.
- Coding standards + PHPStan contract tests under `tests/CodingStandards/`.

## Changing the ruleset

Edit `phpcs.xml.dist` / `phpstan.neon.dist` and update this document when adaptations change. Prefer small, reviewable rule changes. If a rule is too noisy for scaffold stubs, fix the code when practical rather than permanently weakening the standard.
