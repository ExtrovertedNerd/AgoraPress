# Updating AgoraPress

This is the **core update guide** for AgoraPress **`0.3.7-beta`** (schema
`AP_DB_VERSION` **12**). It describes the public `version.json` endpoint,
one-click **Tools → Update Core**, `php bin/package-release.php` artifacts,
what an update **does not** overwrite, and the installed-site CLI verbs
`php ap-cli core check-update` and `php ap-cli db migrate`.

The compact landing-page bullets are in
[`../README.md`](../README.md#updates). This file is the operator depth
guide. It does **not** invent a CLI apply command or an unattended cron
auto-update — those are **not in core**.

**Source (as built):** `ap-includes/class-ap-version-check.php`,
`ap-includes/class-ap-core-updater.php`, `ap-admin/update-core.php`,
`bin/package-release.php`, `ap-includes/class-ap-cli.php`
(`core check-update`, `db migrate` / `db check`),
`ap-includes/class-ap-migrator.php`, `ap-includes/version.php`.

Admin screen: **`/ap-admin/update-core.php`** (Tools → Update Core). Cap:
`update_core` (administrators; `manage_options` is accepted as a fallback on
the version-check notice path).

---

## Choose a path

| Path | When to use | What it does |
|------|-------------|--------------|
| [One-click Update Core](#one-click-tools--update-core) | Writable site root, PHP `ZipArchive`, outbound HTTP | Downloads the published zip, verifies optional SHA-256, replaces core files, runs pending migrations |
| [CLI check + migrate](#cli-check-update-and-db-migrate) | You already deployed files (zip extract, git pull, rsync) | `core check-update` reports; `db migrate` applies schema |
| [Manual package](#manual-deploy) | Hosting without a writable root, or you prefer SSH | Extract the release zip (or pull git), then `php ap-cli db migrate` |
| [Build a release](#package-releasephp) | You publish `version.json` / download zips | `php bin/package-release.php` → zip + sha256 + `version.json` |

There is **no** `php ap-cli core update` (apply) verb. Checking is CLI;
applying the zip is the admin one-click path or a manual file deploy.

---

## Privacy (no site identity)

Version checks and package downloads are **plain HTTP GET**. Core never
appends the site URL, domain, admin email, or other identifying query or
header data.

| Request | User-Agent (as built) | Body / query |
|---------|------------------------|--------------|
| `version.json` | `AgoraPress/{AP_VERSION} (VersionCheck; no-site-id)` | GET, `Accept: application/json` |
| Package zip | `AgoraPress/{AP_VERSION} (CoreUpdater; no-site-id)` | GET, `Accept: application/zip, …` |

Default endpoint:

`https://agorapress.extrovertednerd.com/version.json`

That URL is the already-public product endpoint. Plugins may change it with
the `ap_version_check_url` filter; do not invent a second official URL.

Option **`version_check_enabled`** (installer default **`1`**) turns the
check off when set to `0` / `false`. There is **no** dedicated Settings
screen for that option in core — use CLI:

```bash
php ap-cli option get version_check_enabled
php ap-cli option set version_check_enabled 0
php ap-cli option set version_check_enabled 1
```

The `ap_version_check_enabled` filter can also force the check off. With
checks disabled, one-click update pre-flight fails until you turn them back
on. This path is **not** Hall of Fame and **not** local analytics.

The checker is **not** a cron event. `ap-admin/admin-bootstrap.php` calls
`AP_Version_Check::maybeQueueAdminNotice()` on ACP requests so
administrators see an **Update available** banner. Front-end visitors never
see it. Both `AP_Version_Check::sendsSiteIdentity()` and
`AP_Core_Updater::sendsSiteIdentity()` return **false**.

---

## Public `version.json`

`AP_Version_Check` GETs the endpoint, parses JSON, and caches the result in
the options-backed transient `ap_version_check`:

| Outcome | Cache TTL |
|---------|-----------|
| Successful parse | 12 hours (`CACHE_TTL_SUCCESS`) |
| Soft failure (network / empty / bad JSON) | 1 hour (`CACHE_TTL_FAILURE`) |

Fetch mechanics (as built):

| Rule | As built |
|------|----------|
| Transport | cURL when `curl_init` exists, otherwise `allow_url_fopen` |
| Timeouts | **8 seconds** total · **5 seconds** connect |
| Redirects | **Not** followed (`CURLOPT_FOLLOWLOCATION` false) |
| Success | HTTP **2xx** and a non-empty body that parses |

A `version.json` URL that **301/302s** therefore fails the check (soft-fail
cache). The **zip** download is different: it **does** follow up to **3**
HTTP redirects.

Network and parse failures **fail silently** in admin (no error banner from
the checker itself). Tools → Update Core **Check again** and
`php ap-cli core check-update --force` delete the transient and fetch fresh.

### Payload shape (as published)

`php bin/package-release.php` writes this object (no operator `notes`
field — ready to serve as-is):

```json
{
  "version": "0.3.7-beta",
  "download_url": "https://agorapress.extrovertednerd.com/download/AgoraPress-0.3.7-beta.zip",
  "changelog_url": "https://agorapress.extrovertednerd.com/changelog",
  "sha256": "64-character lowercase hex",
  "released": "YYYY-MM-DD"
}
```

The parser (`AP_Version_Check::parseResponseBody`) requires a usable
**`version`** (or alias `latest`). Optional keys it also accepts:

| Canonical | Also accepted |
|-----------|----------------|
| `download_url` | `download`, `package`, `url` |
| `changelog_url` | `changelog` |
| `sha256` | `package_sha256`, `hash` (optional `sha256:` prefix) |

URLs must be `http://` or `https://`. A leading `v` on the version is
stripped. Comparison is PHP `version_compare` after that normalize.

When an update exists, administrators see an admin notice with **Update
now** (links to Tools → Update Core), plus **Download** / **Changelog**
when those URLs were published. Front-end visitors never see that notice.

---

## One-click: Tools → Update Core

Screen: `ap-admin/update-core.php`. Menu parent: **Tools**.

What the screen shows:

- Installed `AP_VERSION`
- Latest reported remote version (or “unknown” if disabled / offline)
- Optional package SHA-256 from `version.json`
- **Check again** (POST, nonce `update-core-check`)
- **Update to {remote}** when pre-flight says a newer zip is ready (POST,
  nonce `update-core-run`). Confirm dialog as built:
  `Update AgoraPress core to {remote}? Visitors will see a short
  maintenance page.`
- Manual **download** / **Changelog** links when published
- On-screen “What is preserved” is a **subset** (`ap-config.php`,
  uploads, plugins, mu-plugins, custom themes). The full skip list is
  [below](#what-an-update-does-not-overwrite) (`install/` and
  `ap-config-sample.php` are also skipped).

### Pre-flight (`AP_Core_Updater::canUpdate`)

All of these must hold before the one-click button is armed:

| Check | Requirement |
|-------|-------------|
| `ziparchive` | PHP `ZipArchive` (ext-zip) |
| `curl_or_stream` | `curl_init` **or** `allow_url_fopen` |
| `version_check_enabled` | Option not disabled |
| `abspath_writable` | Site root writable by PHP |
| `tmpdir_writable` | `sys_get_temp_dir()` writable |
| Remote version | `version.json` parsed and strictly **newer** than installed |
| `download_url` | Non-empty `http(s)` package URL |

If you are already on the latest version, the screen says so; that is a
warning, not a hard error.

### What the updater does

1. Read cached (or forced) remote info.
2. GET `download_url` into a temp file (default max **100 MiB**;
   **120 seconds** total / **15 seconds** connect; up to **3** redirects).
3. If `version.json` provided a SHA-256, `hash_file('sha256')` must match
   (`hash_equals`). Empty checksum → skip verify.
4. Extract with zip-bomb soft limits: **50 000** files, **250 MiB** total
   uncompressed, **80 MiB** per entry. Path traversal is rejected.
5. Detect a single package root (must contain `ap-includes/version.php`,
   `index.php`, and `ap-admin/`). Multiple candidate roots → refuse.
6. Refuse a **downgrade** (package `AP_VERSION` older than installed). A
   package version that **differs** from the announced `version.json`
   version is a **warning**, not a refuse.
7. Write `.maintenance` (front-end 503 HTML
   “Site briefly unavailable” / “AgoraPress is installing an update.”).
   If the process crashes, a `.maintenance` file older than **30 minutes**
   is ignored so the site is not stuck.
8. Copy allowed relative paths onto the site ([what is not overwritten](#what-an-update-does-not-overwrite)).
9. Run `AP_Migrator::migrate()` for pending schema files (unless the
   internal `skip_migrate` test flag is set).
10. **On success only:** store `ap_version` and `ap_last_core_update`,
    delete the `ap_version_check` transient, fire action `ap_core_updated`
    (`$from`, `$to`, full run-result array).
11. **Always** after maintenance started (success or fail): remove
    `.maintenance` and the temp directory.

If migrations fail **after** files were applied, the error is
`Files were updated but database migration failed: …`. The tree is already
on the new code. Options / `ap_core_updated` / transient delete do **not**
run. Finish with `php ap-cli db migrate` (or restore from backup). There
is **no** automatic file rollback in core.

PHP time/memory on the admin POST: `set_time_limit(300)` and
`ini_set('memory_limit', '256M')` when those calls are allowed.

---

## What an update does **not** overwrite

This list is the contract. One-click apply (`shouldApplyRelative`) **skips**
these paths even if they exist inside the zip:

| Path | Why it is left alone |
|------|----------------------|
| `ap-config.php` | Credentials and salts; never shipped, never replaced |
| `ap-config-sample.php` | Fresh-install template; safe to delete after production install |
| `install/` (the whole tree) | Fresh-install only; safe to delete after production install |
| `ap-content/uploads/` | Media library and other operator uploads |
| `ap-content/plugins/` | Installed plugins (including zip-installed) |
| `ap-content/mu-plugins/` | Must-use plugins |
| Custom themes under `ap-content/themes/` | Anything **other than** the default `agora` theme |
| `.maintenance` | Live maintenance lock written by the updater itself |
| `.git/` · `.hephaestus/` | VCS / process trees, if present |

**Default theme `agora` is updated** from the package
(`ap-content/themes/agora/…`). If you edited Agora in place, those edits
are overwritten. Put customizations in a [child theme](themes.md).

**Root `.htaccess` is overwritten** when the package contains one. Custom
Apache rules in that file are lost on one-click apply — keep a copy, or
put extra rules in the vhost. Nginx `try_files` lives in the server block,
not in `.htaccess` ([rewrites.md](rewrites.md)).

The ACP screen’s “What is preserved” blurb omits `install/` and
`ap-config-sample.php`; the updater still skips them. Trust this table,
not only the on-screen subset.

The release zip **does** contain `install/` and `ap-config-sample.php` so a
**fresh** extract can run the installer. The updater simply does not copy
those onto a live site. `bin/` (the packager itself) is **not** in the
release zip.

Also applied when present in the package: `index.php`, `ap-cli`,
`ap-admin/`, `ap-includes/`, root `.htaccess`, `LICENSE`, `CHANGELOG.md`,
`composer.json` (lockfile is **not** shipped), docs, Docker examples,
`ap-content/themes/index.php`, and language placeholders. User SQLite
files and `.env` are not in the zip.

---

## CLI: `check-update` and `db migrate`

`php ap-cli` manages an **installed** site (`ap-config.php` present). Exit
codes: `0` ok · `1` usage · `2` error · `3` not installed. Global flags:
`--path`, `--url`, `--skip-plugins`, `--skip-themes`. Cookbook: [cli.md](cli.md).

### `php ap-cli core check-update`

Reports current vs remote. Does **not** download or apply files.

```bash
php ap-cli core check-update
php ap-cli core check-update --force
php ap-cli core version
```

`--force` deletes the `ap_version_check` transient first (same idea as
**Check again**). Typical stdout:

```text
current: 0.3.7-beta
remote: 0.3.7-beta
update: none
download: https://agorapress.extrovertednerd.com/download/AgoraPress-0.3.7-beta.zip
changelog: https://agorapress.extrovertednerd.com/changelog
```

When the endpoint is offline, disabled, or the cache is empty:

```text
current: 0.3.7-beta
remote: unavailable (offline, disabled, or cache empty)
update: unknown
```

That still exits `0`. Two different version verbs:

| Command | Prints |
|---------|--------|
| `php ap-cli version` | `AgoraPress {AP_VERSION} (PHP {php})` only |
| `php ap-cli core version` | That line, plus `db_version_target` (`AP_DB_VERSION`) and `db_version_applied` from `schema_migrations` when the migrator can read it |

### `php ap-cli db migrate`

Applies pending numbered migrations under
`ap-includes/schema/migrations/` until the database matches
`AP_DB_VERSION` (**12** for this tree). Used after a **manual** file
deploy, and as a recovery step if one-click updated files but migration
failed.

```bash
php ap-cli db check
php ap-cli db migrate
```

`db check` prints driver, table prefix, `schema_current`, `schema_target`,
`pending_migrations`, and `needs_migration`. `db migrate` with nothing
pending prints `No pending migrations (schema at N).` and exits `0`.
Target version and table map: [schema.md](schema.md).

### Site Health (cache only)

**Tools → Site Health** / `php ap-cli site health` never GETs
`version.json`. It reads the `ap_version_check` transient if one already
exists.

| Check | Status | When |
|-------|--------|------|
| Database schema | **critical** | Pending files under `ap-includes/schema/migrations/` |
| Core updates | **recommended** | Cached remote version is strictly newer than `AP_VERSION` |
| Core updates | good | Cache empty, already current, checker not loaded, or `version_check_enabled` is off |

Turning checks off does **not** fail Site Health (it reports that
automatic version checks are disabled). Use **Check again** or
`php ap-cli core check-update --force` when you want a fresh fetch.

---

## Manual deploy

When PHP cannot write the site root (or you prefer SSH):

1. Back up `ap-config.php`, the database, and `ap-content/` (especially
   `uploads/`, `plugins/`, custom themes).
2. Obtain `AgoraPress-{version}.zip` from the published `download_url` (or
   build it — [below](#package-releasephp)).
3. Extract so the **contents** of the zip’s top-level `AgoraPress/` folder
   land on the existing site root (the folder that already has
   `ap-config.php`). Do not nest a second `AgoraPress/` directory inside
   the live root.
4. Do **not** replace `ap-config.php`. Leave `uploads/`, custom plugins,
   custom themes, and (if you removed them) `install/` /
   `ap-config-sample.php` alone.
5. Run:

   ```bash
   php ap-cli db migrate
   php ap-cli cache flush
   php ap-cli rewrite flush
   php ap-cli site health
   ```

`git pull` on a clone is a developer path: it brings tests and tooling the
release zip omits. After a pull you still need `php ap-cli db migrate` if
`AP_DB_VERSION` moved. Production hosts should prefer the release zip.

---

## `package-release.php`

Build the zip that one-click update and fresh installs consume:

```bash
php bin/package-release.php
php bin/package-release.php --output-dir=/tmp/dist
php bin/package-release.php --version=0.3.7-beta
php bin/package-release.php --prefix=AgoraPress
php bin/package-release.php --dry-run
php bin/package-release.php --json
php bin/package-release.php --help
# or: composer package
```

| Flag | As built |
|------|----------|
| `--output-dir=DIR` | Destination (default: `<repo>/dist`) |
| `--version=VER` | Override `AP_VERSION` in filenames / `version.json` |
| `--prefix=NAME` | Top-level folder inside the zip (default `AgoraPress`) |
| `--dry-run` | Report file count / paths; write nothing |
| `--json` | Machine-readable summary on stdout |
| `--help` / `-h` | Usage |

Unknown arguments print help and exit `1`. The packager itself is CLI-only
and requires `ZipArchive` (ext-zip). `dist/` is gitignored. Version
label defaults to `AP_VERSION` in `ap-includes/version.php`.

| Artifact (default under `dist/`) | Purpose |
|----------------------------------|---------|
| `AgoraPress-{version}.zip` | Production tree under a top-level `AgoraPress/` folder |
| `AgoraPress-{version}.sha256` | Checksum line consumed by `version.json` |
| `version.json` | Public endpoint payload (`version`, URLs, `sha256`, `released`) |

**Not shipped:** `tests/`, `vendor/`, `bin/`, `.git/`, `.github/`,
`.hephaestus/`, Composer lock / PHPCS / PHPStan / PHPUnit configs,
`ap-config.php`, `.env`, runtime SQLite/DB files, real files under
`ap-content/uploads/` (only `index.php` is kept). Zero production Composer
packages — `vendor/` is never in the zip.

Required paths inside the zip include `index.php`,
`ap-includes/version.php`, `ap-admin/index.php`, `ap-config-sample.php`,
`LICENSE`, `CHANGELOG.md`, `install/index.php`, `ap-cli`, and
`ap-content/themes/agora/style.css`.

To publish: serve `version.json` at the endpoint sites check, and serve
the zip at `download_url`. The generated `download_url` /
`changelog_url` point at `https://agorapress.extrovertednerd.com/…`. If
you host a private mirror, change those fields before serving the JSON
(or filter `ap_version_check_url` on the site).

---

## After an update

1. Confirm `php ap-cli version` prints `AgoraPress {new} (PHP …)` and
   Tools → Update Core shows the new `AP_VERSION`.
2. Confirm `php ap-cli core version` has `db_version_applied` equal to
   `db_version_target`, and `php ap-cli db check` has `needs_migration: no`
   with `schema_current` equal to `AP_DB_VERSION` (**12** on this tree).
3. **Tools → Site Health** (or `php ap-cli site health`).
4. If the admin shell changed, refresh or log in again (the success notice
   says so).
5. If pretty permalinks 404, the front controller is a server issue, not
   the updater — see [rewrites.md](rewrites.md). If you had customized
   root `.htaccess`, restore those extra rules (the package copy just
   replaced it).

---

## Not in core

Say **not in core** rather than inventing these:

- `php ap-cli core update` / unattended apply of the zip
- Cron that downloads and installs core by itself (the checker only
  queues an admin notice from `admin-bootstrap.php`; it is **not** a
  cron event)
- Following HTTP redirects on the `version.json` GET (the zip download
  may follow up to 3; the checker does not)
- One-click **plugin** or **theme** updates from this `version.json`
  (plugin/theme zip installers are a different admin surface —
  [plugins.md](plugins.md), [themes.md](themes.md))
- Rollback UI / automatic restore of overwritten files
- A Settings screen dedicated to `version_check_enabled`
- Telemetry, license pings, or site-identity query args on the version
  check

---

## Related documentation

| Doc | Why |
|-----|-----|
| [../README.md](../README.md) | Landing-page update + packaging bullets |
| [README.md](README.md) | Docs index (humans + trusted agent) |
| [install.md](install.md) | Fresh install; `install/` is not re-applied on update |
| [cli.md](cli.md) | Every built-in `ap-cli` group, flags, exit codes |
| [schema.md](schema.md) | Migration map, `AP_DB_VERSION`, prefix |
| [admin.md](admin.md) | `/ap-admin/` screens by task |
| [security.md](security.md) | No telemetry, deny rules |
| [troubleshooting.md](troubleshooting.md) | Symptom → check |
| [hooks.md](hooks.md) | `ap_core_updated`, `ap_version_check_url`, `ap_version_check_enabled` |
| [vision-compliance.md](vision-compliance.md) | Privacy / no-telemetry checklist |
| [rewrites.md](rewrites.md) | Front controller still required after a zip apply |
| [plugins.md](plugins.md) · [themes.md](themes.md) | Custom plugins/themes are not overwritten |

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
