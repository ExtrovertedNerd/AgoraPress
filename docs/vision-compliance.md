# Vision & Features Compliance

**Date:** 2026-08-07  
**Scope:** Full codebase reevaluation against the project constitution (`VISION.md`, `FEATURES.md`, `SPEC.md` under the operator’s private process tree).  
**Version under review:** `AP_VERSION` `0.3.0-beta`

This document is the **public product record** of that review. It locks the north-star principles into something contributors and automated tests can verify. It does not replace the constitution files themselves.

---

## Verdict

AgoraPress still matches the mission: a **free, lightweight, privacy-respecting** spiritual successor to classic WordPress **plus** first-class forums, with the **Classic WordPress Theme Compatibility Layer** as a high-priority differentiator.

| Area | Status |
|------|--------|
| Free forever / GPLv2-or-later | Pass |
| No telemetry by default | Pass |
| Lightweight core (no runtime PHP deps; image-free Agora theme) | Pass |
| Three independent modules (Static Pages / Blog / Forum) | Pass |
| Classic WP Theme Compatibility Layer | Pass (solid for classic PHP themes) |
| Secure & private defaults | Pass |
| Non-goals respected (Gutenberg, SaaS marketplace, heavy AI, PHP &lt; 8.2) | Pass |
| Live-site install readiness (hardening, installer, Site Health) | Pass |
| Local site analytics (opt-in, no third party) | Pass (shipped in 0.2.0-beta; default off) |
| Forum likes + edit/moderation UI | Pass (shipped in 0.2.1-beta; ACL-gated) |
| Theme Options / theme_mods API | Pass (shipped in 0.2.1-beta) |
| Forum UI phpBB-parity (board index + topic view) | Pass (shipped in 0.3.0-beta) |

No bloat or phone-home paths were found in core product code. Network egress from core is limited to:

1. **Version check** — admin-only, GET public `version.json`, no site identity  
2. **Core package download** — admin-initiated one-click update, GET only, no site identity  
3. **Hall of Fame join/leave** — explicit `manage_options` action only; domain + action (+ withdrawal token), never automatic  

---

## Core principles (checklist)

### 1. Free forever

- `LICENSE` is GPLv2 (or later as stated in product docs / `composer.json` `GPL-2.0-or-later`).
- No freemium gates, license keys, premium modules, or paid marketplace in core.
- Admin donation link is permanent and non-optional (subtle footer tip only), unobtrusive, and never blocks features.

### 2. Lightweight by design

- `composer.json` **require** is PHP extensions only (no runtime libraries).
- Dev tools (PHPUnit, PHPStan, PHPCS) are `require-dev` only.
- Default **Agora** theme: pure CSS, **no image assets**, exactly **six** color schemes (3 light + 3 dark): Marble, Parchment, Cloud, Obsidian, Midnight, Charcoal.
- Optional modules keep unused surfaces out of admin and front routing.
- Classic visual editor only (no Gutenberg runtime in core).

### 3. Easy self-host & maintain

- Web installer (`install/`), CLI install (`install/cli.php`), Docker Compose.
- Versioned migrations (`AP_DB_VERSION` = 12), Site Health, Update Core screen, **Tools → Analytics**, forum likes (v11), topic type enum (v12).
- Familiar `ap-admin` / `ap-includes` / `ap-content` layout for classic WP operators.
- Zero-dependency `ap-cli` for options, plugins, themes, users, **posts/pages**, DB, cache, cron, rewrites, health.
- Production hardening examples (Apache `.htaccess`, Nginx) deny secrets, SQLite downloads, and direct `ap-includes/` PHP.

### 4. Easy to theme + Classic WP Compatibility Layer

Location: `ap-includes/compatibility/`

| Piece | Role |
|-------|------|
| `load.php` | Public entry |
| `class-ap-theme-compat.php` | Modes, shim load, hook map, block detection, safe `functions.php` |
| `class-ap-theme-converter.php` | Analysis / dry-run conversion report |
| `functions-shim.php` | Bare WP function names → AgoraPress |
| `template-tags.php` | Classic loop / template tags |
| `cli-convert.php` | CLI report |

**Invariants verified:**

- Per-theme mode: `auto` | `on` | `off` (`ap_theme_compat_modes`)
- Default **Agora** is native — shims stay off under `auto`
- Block / FSE packages (`theme.json` / HTML under `templates/`) are not auto-enabled; theme uploader rejects them by default
- Hook map rewrites common WP hooks to `ap_*` equivalents
- Conversion helper is dry-run (does not rewrite theme files)

Documented limitations: [compatibility.md](compatibility.md).

### 5. Powerful & extensible

Hooks, plugins, Settings API, shortcodes, CPT/taxonomies, REST (`AP_Rest`), CLI (`ap-cli` including content management).

### 6. Integrated community

Dedicated forum tables; shared users, roles, capabilities, and media. Hierarchy, topics, replies, attachments, groups/ACL (per-forum presets and custom matrix), moderation, PMs, online/unread, search/flood/approval.

### 7. Secure & private by default

- PDO prepared statements only  
- Nonces / caps / escaping helpers  
- Argon2id passwords, rate limiting, hardened uploads  
- No `AP_TELEMETRY` constant, flag, or option — telemetry is never used (config sample, installer, Site Health)  
- Version check and updater User-Agents are generic (`no-site-id`); they never append domain, email, or site URL  
- **Local analytics** (`AP_Analytics`): opt-in (`analytics_enabled` default **off**), server-side hits only, data in site DB, no third-party scripts/endpoints; not Hall of Fame or version-check traffic  

### 8. Migration friendly

WordPress WXR importer and phpBB importer (JSON or live DB). phpBB attachments / PMs / ranks remain deferred (see deviations).

---

## FEATURES prioritization snapshot

Phases 0–7 of the build roadmap are complete for MVP/v1 surface area described in FEATURES. Remaining **Later / non-goal** items stay out of core:

| Item | Stance |
|------|--------|
| Full Gutenberg / FSE in core | Non-goal — respected (`AP_Editor` is classic visual WYSIWYG, not a block editor; see [editor.md](editor.md)) |
| Multisite | Later — not present |
| Official SaaS / paid marketplace | Non-goal — none |
| Heavy AI / telemetry | Non-goal — none |
| Optional 2FA | Still Later (not shipped) |
| Soft-delete polish, bookmarks, polls, ranks UI, custom BBCodes | Forum v1 polish — partial (ranks table exists; full UI later) |

---

## Intentional deviations

These are deliberate engineering choices that differ slightly from early planning language. They do **not** weaken free/privacy/lightweight principles.

### D1 — One-click core auto-update shipped early

**FEATURES** listed full one-click auto-update as **Later**.  
**Implementation:** `AP_Core_Updater` + admin Update Core, privacy-preserving GET download + optional SHA-256. Preserves `ap-config.php`, site content, and (from 0.1.4-dev) never rewrites `install/` or `ap-config-sample.php` so admins can remove those post-install.

**Why:** Maintainability is a core principle; a privacy-safe update path is more important than deferring it. Still admin-initiated only.

### D2 — Hall of Fame implemented early

**FEATURES** listed Hall of Fame as v1/Later.  
**Implementation:** Fully voluntary domain registration only; no installer pings; withdrawable. Admin-footer donation link is permanent/non-optional (no toggle).

**Why:** Matches the constitution’s preferred install-counting path over anonymous telemetry.

### D3 — PostgreSQL is first-class, not “planned later”

**VISION** high-level text once said PostgreSQL was planned.  
**SPEC + code:** `mysql` | `sqlite` | `pgsql` drivers in `AP_DB` and migrations.

**Why:** Multi-driver schema from day one avoids a painful retrofit; still primary-recommended is MySQL/MariaDB.

### D4 — Page cache is integration hooks, not a bundled disk HTML store

**FEATURES:** object & page caching support.  
**Implementation:** in-core object cache API + drop-in; `AP_Page_Cache` purge hooks and `advanced-cache.php` support when `AP_CACHE` is true — no heavy built-in full-page HTML store.

**Why:** Lightweight by design; operators plug in their preferred page cache.

### D5 — phpBB importer scope

Users, forums, topics, and posts import; attachments, private messages, and ranks are deferred.

**Why:** Ship a reliable core migration path first; extend importers without blocking MVP.

### D6 — REST API and operational CLI delivered with polish phase

**FEATURES** tagged REST + CLI as v1.  
**Implementation:** Lightweight `AP_Rest` and zero-dep `ap-cli` ship in the 0.1.x-dev tree, including shell content management (`post list|get|create|update` with local `--file` only).

**Why:** Extensibility and ops tools belong with a usable MVP, not a later bolt-on.

### D7 — README / status language

Product README status tracks the shipped release line (`0.3.0-beta`) and reflects that installer, CMS, forums (including phpBB-parity board UI and likes/moderation UI), admin, compatibility layer, packaging, live-site hardening, Theme Options API, and opt-in local analytics are **implemented**, not Phase-1 stubs.

### D8 — Local site analytics (SPEC 0.2.0-beta)

**SPEC** requires privacy-respecting admin analytics with data in the site DB only.  
**Implementation:** migration 10 (`analytics_hits` / `analytics_daily`), `AP_Analytics` server-side recorder (default off), retention prune cron, ACP **Tools → Analytics**. No front-end JS beacon; no third-party endpoints.

**Why:** Operators get usable pageview reports without weakening the no-telemetry posture. Collection remains opt-in and local-only.

---

## Explicit non-findings (no action needed)

- No jQuery in core product PHP/CSS/JS  
- No **third-party** analytics / beacon / usage-stat collectors (Google Analytics, Matomo cloud, etc.)  
- Local pageview analytics is **opt-in**, server-side, and **local DB only** (not telemetry; not Hall of Fame)  
- No image assets under the default Agora theme  
- No paywall or license-key enforcement paths  
- Compatibility layer does not claim block/FSE parity (documented out of scope)  
- `ap-cli post --file` never fetches remote URLs (local filesystem only)  

---

## How tests guard this

| Suite | What it locks |
|-------|----------------|
| `tests/Vision/VisionComplianceTest.php` | Principles, telemetry defaults, compat files, Agora schemes, modules, LICENSE, no jQuery |
| `tests/test_vision_compliance.py` | Same surface for pytest smoke |
| Existing Hall of Fame / Version check / Theme compat / Site Health / Readme / Live-site readiness tests | Privacy, install hardening, and differentiator detail |

Re-run after large features land:

```bash
./vendor/bin/phpunit --filter VisionCompliance
pytest tests/test_vision_compliance.py -v
```

---

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
