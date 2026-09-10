# AgoraPress CLI (`ap-cli`)

This is the **installed-site CLI cookbook** for AgoraPress **`0.3.8-beta`**
(schema `AP_DB_VERSION` **12**). It describes every built-in command group
that `php ap-cli --help` lists, plus global flags and exit codes, **as
built**.

The compact landing-page examples are in
[`../README.md`](../README.md#admin--site-cli-ap-cli). Fresh install is a
**different** tool: [`php install/cli.php`](install.md) (different flags,
different exit codes). Do not run `ap-cli` until `ap-config.php` exists.

**Source (as built):** `ap-cli`, `ap-includes/class-ap-cli.php` (`AP_Cli`),
`ap-includes/bootstrap.php` (`AP_CLI`, `AP_CLI_SKIP_PLUGINS`;
`AP_CLI_SKIP_THEMES` is defined by the CLI and unused by bootstrap).

`ap-cli` is a **local shell tool**. It is not a remote API and not REST
([rest.md](rest.md)). There is **no** `php ap-cli core update` apply verb
([updates.md](updates.md)). Plugin-registered verbs on `ap_cli_init` are
not core — this guide documents built-ins only.

---

## Invocation

Must run as PHP CLI (`php` or `phpdbg`). Invoking the script over HTTP
prints `ap-cli must be run from the command line.` and exits `1`.

```bash
php ap-cli --help
php ap-cli help
php ap-cli help post
php ap-cli post --help
php ap-cli --version
php ap-cli version
./ap-cli option get blogname
```

Default site root is the directory that contains the `ap-cli` script.
Override with `--path` when the current working directory is elsewhere.

Bare `php ap-cli` (no command) prints top-level help and exits `0`.
`php ap-cli help <unknown>` and an unknown group name both exit `1`.

Installed-site commands boot `ap_bootstrap()`. That path requires
**PHP 8.2+**; an older SAPI returns `2`. `--path` pointing at a tree
without `ap-includes/bootstrap.php` also returns `2` (not `3`).

---

## Global flags

These flags are parsed before the command. They apply to every built-in
that boots core. `--help` / `--version` are also accepted as `-h` / `-V`.

| Flag | As built |
|------|----------|
| `--path=<path>` | AgoraPress root (directory containing `ap-includes/` and, for installed-site commands, `ap-config.php`). Relative paths resolve from the current working directory. Default: the directory that contains `ap-cli`. |
| `--url=<url>` | Sets `AP_HOME` for this process (home URL hint for link builders). Does not rewrite the stored `home` / `siteurl` options. |
| `--skip-plugins` | Defines `AP_CLI_SKIP_PLUGINS`. Bootstrap still loads **must-use** plugins under `ap-content/mu-plugins/`; it does **not** load active plugins. Use this when a broken plugin blocks CLI. |
| `--skip-themes` | Defines `AP_CLI_SKIP_THEMES`. Help text: “Skip theme setup side effects where possible.” As of 0.3.8-beta, core bootstrap does **not** read this constant (only `AP_CLI_SKIP_PLUGINS` is branched on); the flag is accepted and reserved. |
| `-h`, `--help` | Top-level help, or command help when placed after a command name (`php ap-cli plugin --help`). Help does **not** boot core, so it never lists plugin-registered verbs. |
| `-V`, `--version` | Print `AgoraPress {AP_VERSION} (PHP {PHP_VERSION})` when used alone (or with help). Does not boot the database. |

Valued flags accept `--name=value` or `--name value`. A bare `--name` is
treated as a boolean `true` for command-level flags. A lone `--` ends
flag parsing; everything after it is a positional argument.

`--skip-plugins` skips **active** plugins. MU-plugins still load. See
[Plugin-registered commands](#plugin-registered-commands) for what
`ap_cli_init` can and cannot do.

---

## Exit codes

Constants on `AP_Cli`:

| Code | Constant | Meaning |
|------|----------|---------|
| `0` | `EXIT_OK` | Success, help, or version |
| `1` | `EXIT_USAGE` | Unknown command, missing required args, invalid flags, or not CLI SAPI |
| `2` | `EXIT_ERROR` | Runtime failure (DB, migrate, plugin activate, missing option, PHP older than 8.2, missing `bootstrap.php`, uncaught exception, **text** Site Health with a critical check) |
| `3` | `EXIT_NOT_INSTALLED` | Missing or unreadable `ap-config.php` on a command that needs an installed site |

These codes are **not** the installer codes (`php install/cli.php` uses
`0` / `1` / `2` requirements / `3` install failure — see
[install.md](install.md)).

Commands that **do not** require an install: `help`, `version`, `cli`.
Everything else returns `3` if `ap-config.php` is missing (and the tree
still has `ap-includes/bootstrap.php`). A `--path` with no bootstrap file
returns `2`.

`php ap-cli site health` (default **text** format) returns `2` when any
check is **critical**. Recommended-only findings still exit `0`.
`--format=json` always exits `0` even when the payload contains critical
checks — inspect the JSON; do not rely on the process status.

---

## Built-in command groups

`php ap-cli --help` lists these groups **alphabetically**. The table below
is the same set, grouped by theme. Subcommands in parentheses are the
verbs each group actually implements.

| Group | Needs install | Subcommands as built |
|-------|---------------|----------------------|
| `help` | no | `help` · `help <command>` |
| `version` | no | (none) |
| `cli` | no | `info` |
| `core` | yes | `version` · `check-update` |
| `db` | yes | `check` · `migrate` |
| `option` | yes | `get` · `set` · `delete` · `list` |
| `plugin` | yes | `list` · `activate` · `deactivate` |
| `theme` | yes | `list` · `activate` |
| `user` | yes | `list` · `get` · `create` |
| `post` | yes | `list` · `get` · `create` · `update` |
| `cache` | yes | `flush` |
| `cron` | yes | `event list` · `event run` |
| `rewrite` | yes | `flush` |
| `site` | yes | `health` |

There is **no** built-in `plugin install`, `theme install`, `user delete`,
`post delete`, `db rollback`, `core update`, or `rewrite list`. Those are
**not in core**.

Default subcommand when you omit it:

| Invocation | Runs |
|------------|------|
| `php ap-cli cli` | `cli info` |
| `php ap-cli core` | `core version` |
| `php ap-cli db` | `db check` |
| `php ap-cli plugin` | `plugin list` |
| `php ap-cli theme` | `theme list` |
| `php ap-cli user` | `user list` |
| `php ap-cli post` | `post list` |
| `php ap-cli cache` | `cache flush` |
| `php ap-cli rewrite` | `rewrite flush` |
| `php ap-cli site` | `site health` |
| `php ap-cli cron event` | `cron event list` |

Omitting a subcommand is **usage** (`1`) for `option` and for bare
`php ap-cli cron` (you need `cron event`, `cron list`, or `cron run`).
`help` with no topic is top-level help, not usage.

Bootstrap for installed-site commands calls `ap_bootstrap()`. Pseudo-cron
does **not** fire during `ap-cli` (`AP_CLI` is defined; bootstrap skips
`AP_Cron::spawn()`). To run due events, use `php ap-cli cron event run`.

---

## `help`

```bash
php ap-cli help
php ap-cli help option
php ap-cli --help
php ap-cli plugin --help
```

Prints the top-level usage (command list, global flags, exit-code line) or
the usage string registered for one command. Unknown topic → exit `1`.

---

## `version`

```bash
php ap-cli version
php ap-cli --version
php ap-cli -V
```

Stdout: `AgoraPress 0.3.8-beta (PHP 8.x.x)`. Loads `ap-includes/version.php`
only — no `ap-config.php`, no database. For schema versions as well, use
`core version` on an installed site.

---

## `cli info`

```bash
php ap-cli cli info
php ap-cli cli
```

Does not need an install. Prints:

```text
os: …
php: …
sapi: …
agorapress: 0.3.8-beta
root: /path/to/site
installed: yes|no
core_loaded: yes|no
```

`installed` is whether `ap-config.php` is readable under `--path`. Unknown
subcommand → exit `1`.

---

## `core`

Needs an installed site.

### `core version`

```bash
php ap-cli core version
php ap-cli core
```

Prints the same version line as `version`, then:

```text
db_version_target: 12
db_version_applied: 12
```

`db_version_target` is `AP_DB_VERSION`. `db_version_applied` is
`AP_Migrator::getCurrentVersion()` (or `unavailable` if the migrator
cannot read the database).

### `core check-update`

```bash
php ap-cli core check-update
php ap-cli core check-update --force
```

Reports current vs remote `version.json`. Does **not** download or apply
files. There is **no** `php ap-cli core update`. Apply a zip from
**Tools → Update Core** or a manual deploy, then `db migrate` —
[updates.md](updates.md).

`--force` bypasses the `ap_version_check` cache (`AP_Version_Check::forceCheck()`).
`check_update` (underscore) is an alias of `check-update`.

Typical stdout when the endpoint answers:

```text
current: 0.3.8-beta
remote: 0.3.8-beta
update: none
download: https://agorapress.extrovertednerd.com/download/AgoraPress-0.3.8-beta.zip
changelog: https://agorapress.extrovertednerd.com/changelog
```

When the check is offline, disabled (`version_check_enabled=0`), or the
cache is empty: `remote: unavailable …` and `update: unknown` (still exit
`0`). Network / parser failures → exit `2`.

The GET never sends site identity ([updates.md](updates.md#privacy-no-site-identity)).

---

## `db`

Needs an installed site. Schema details: [schema.md](schema.md).

### `db check`

```bash
php ap-cli db check
php ap-cli db
```

Connectivity probe (`SELECT 1`) plus migrator status:

```text
status: ok
driver: mysql|sqlite|pgsql
prefix: ap_
schema_current: 12
schema_target: 12
pending_migrations: 0
needs_migration: no
```

DB errors → exit `2`.

### `db migrate`

```bash
php ap-cli db migrate
```

Applies pending files under `ap-includes/schema/migrations/` up to
`AP_DB_VERSION` **12**. Already current:

```text
No pending migrations (schema at 12).
```

Otherwise prints how many migrations ran and the new `schema_version`.
Failure → exit `2`. There is **no** rollback verb in core.

After a git pull or zip extract, run this before assuming the admin UI is
current ([updates.md](updates.md)).

---

## `option`

Needs an installed site. Options API: `AP_Options`.

### `option get`

```bash
php ap-cli option get blogname
php ap-cli option get --option=blogname
```

Prints the stored value. Strings print as-is. Arrays / objects print
pretty JSON. Booleans print `1` or an empty line. `--key=` is an alias
of `--option=`.

| Situation | Exit | Stderr |
|-----------|------|--------|
| No name (no positional and no `--option`/`--key`) | `1` | `Usage: option get <name>` |
| Named option does not exist | `2` | `Option not found: {name}` |
| Empty stored string | `0` | (empty stdout line — not “not found”) |

### `option set`

```bash
php ap-cli option set blogname "Example"
php ap-cli option set rest_api_enabled 0
php ap-cli option set --option=blogdescription --value="A forum-first CMS"
```

If `<value>` decodes as a JSON **object or array**, that structure is
stored; JSON scalars (`true`, `10`, `"x"`) stay strings. Booleans passed
as flags become `1` / `0`. `--key=` aliases `--option=` for the name.
Success stdout: `Updated option '{name}'.`

```bash
php ap-cli option set my_plugin_settings '{"enabled":true,"limit":10}'
```

### `option delete`

```bash
php ap-cli option delete my_plugin_settings
php ap-cli option delete --option=my_plugin_settings
```

No name → exit `1`. Missing or undeletable → exit `2`. Success stdout:
`Deleted option '{name}'.`

### `option list`

```bash
php ap-cli option list
php ap-cli option list --search=analytics
php ap-cli option list analytics
```

Tab-separated `name` + truncated value (80 characters, newlines flattened).
`--search` (or the extra positional) is a SQL `LIKE %term%` on
`option_name`. Empty table prints `(no options)`.

---

## `plugin`

Needs an installed site. Headers and activation: [plugins.md](plugins.md).
There is **no** zip-install verb on the CLI; upload from
**Plugins** in `/ap-admin/` ([admin.md](admin.md)).

### `plugin list`

```bash
php ap-cli plugin list
php ap-cli plugin
php ap-cli plugin list --format=json
```

Default table: `* basename  Plugin Name  vX.Y` (`*` = active), then
`Legend: * = active`. `--format=json` prints a **compact** (not pretty)
array of `file`, `name`, `version`, `status` (`active`|`inactive`).

Empty list — including `--format=json` — always prints the text line
`(no plugins installed)` (not `[]`).

The basename is the path under `ap-content/plugins/` used by `activate` /
`deactivate` (for example `sample-plugin/sample-plugin.php`).

### `plugin activate` / `plugin deactivate`

```bash
php ap-cli plugin activate sample-plugin/sample-plugin.php
php ap-cli plugin deactivate sample-plugin/sample-plugin.php
php ap-cli plugin activate --plugin=sample-plugin/sample-plugin.php
```

Uses `AP_Plugin::activate` / `deactivate`. Failure messages on stderr,
exit `2`. Missing basename → exit `1`. Success stdout:
`Plugin activated: {basename}` / `Plugin deactivated: {basename}`.

---

## `theme`

Needs an installed site. Hierarchy and zip installer:
[themes.md](themes.md). There is **no** CLI zip-install or
`theme deactivate` — activating another stylesheet is how you switch.

### `theme list`

```bash
php ap-cli theme list
php ap-cli theme
```

`* stylesheet  Theme Name` (`*` = active stylesheet), then
`Legend: * = active stylesheet`. Empty: `(no themes installed)`. There is
**no** `--format=json` for themes.

### `theme activate`

```bash
php ap-cli theme activate agora
php ap-cli theme activate --stylesheet=agora
php ap-cli theme activate --theme=agora
```

`--theme=` is an alias of `--stylesheet=`. Missing stylesheet → exit `1`.
Invalid / not a theme → exit `2`. Success stdout: `Theme activated: {slug}`.
Default shipped theme slug: `agora`.

---

## `user`

Needs an installed site. Roles: [roles.md](roles.md). There is **no**
`user update`, `user delete`, or password-reset verb on the CLI.

### `user list`

```bash
php ap-cli user list
php ap-cli user
php ap-cli user list --number=20 --role=administrator
php ap-cli user list --search=admin
```

Tab-separated `ID`, login, email, roles. Defaults: `--number=50`, order by
`ID` ascending. `--number` less than 1 falls back to 50. Empty:
`(no users)`.

### `user get`

```bash
php ap-cli user get 1
php ap-cli user get admin
php ap-cli user get admin@example.com
php ap-cli user get --id=1
php ap-cli user get --user=admin
```

Looks up by numeric ID, then login, then email (`--user=` / `--id=` are
aliases for the selector). Prints `AP_User::toPublicArray()` fields
(`ID`, `user_login`, `user_nicename`, `user_email`, `user_url`,
`user_registered`, `user_status`, `display_name`) plus `roles: …`.
No selector → exit `1`. Not found → exit `2`. The password hash is **not**
printed.

### `user create`

```bash
php ap-cli user create \
  --user_login=jane \
  --user_email=jane@example.com \
  --user_pass='choose-a-strong-password' \
  --role=author \
  --display_name="Jane Example"
```

Aliases: `--login`, `--email`, `--password`. Positionals: first extra arg
= login, second = email. Password is **not** taken from a positional.

Password may come from the environment instead of the flag:

```bash
AP_USER_PASSWORD='choose-a-strong-password' php ap-cli user create \
  --user_login=jane --user_email=jane@example.com --role=subscriber
```

Default `--role` is `subscriber`. Missing login, email, or password →
exit `1`. `AP_User::create` validation errors → exit `2`. Success stdout:
`User created: ID {id} ({login})`. Public-register reserved logins (locked
staff/system list, Settings → General `reserved_usernames` extras, and
filter `ap_reserved_usernames`) are **allowed** here — the reserved-name
gate is public self-register only, and that form reports “That username is not available.”
without saying a name is reserved.

Do not commit passwords. Prefer `AP_USER_PASSWORD` in a local shell so the
secret does not land in process lists or shell history as a `--user_pass=`
argument.

---

## `post`

Needs an installed site. Manages **posts and pages only** (`post` / `page`).
Other types are **not in core** for this command. Body files are
**local-filesystem only**. `--file` rejects any path that matches
`scheme://` (examples: `http://`, `https://`, `ftp://`, `php://`,
`file://`) with
`Invalid --file: remote URLs and stream wrappers are not allowed.`
A bare `data:` string (no `://`) is **not** that check; it fails later as
“not a readable regular file” unless such a local path exists.

**Create defaults:** posts → **`draft`**, pages → **`publish`**. Pass
`--status=` to override.

There is **no** `post delete`.

### `post list`

```bash
php ap-cli post list
php ap-cli post
php ap-cli post list --type=page
php ap-cli post list --type=post,page --status=publish --number=20
```

Columns: `ID`, type, slug, title (one line), status. Defaults:
`--type` omitted = both `post` and `page`; `--status=any`; `--number=100`
(`--limit` is an alias). `--type=any` is the same as omitting `--type`.
`--number` less than `0` falls back to 100 (`0` is allowed and lists
nothing).

Invalid `--type` (anything other than `post`, `page`, `any`, or a
comma-separated pair of those) → exit `1`. Empty: `(no posts)`.

### `post get`

```bash
php ap-cli post get --id=12
php ap-cli post get --slug=about --type=page
```

Requires `--id` (positive integer) **or** `--slug`. `--type` is optional
with `--id` (mismatch → exit `2`) and recommended with `--slug` when the
same slug could exist as both a post and a page. No selector → exit `1`.
Prints `ID`, `post_type`, `post_name`, `post_title`, `post_status`,
`post_date`, `post_modified`, `post_author`, `post_parent`, a `---`
separator, then `post_content`.

### `post create`

```bash
php ap-cli post create --title="Hello" --file=./hello.html --status=publish
php ap-cli post create --type=page --title="About" --slug=about --file=./about.html
php ap-cli post create --title="Draft only"
```

`--title` is required. `--type` defaults to `post`. `--file` is optional
(empty body if omitted). `--slug` sets `post_name` when provided.

Stdout: `Created post ID 15 (hello)` / `Created page ID 4 (about)`.

`--file` must be a readable regular file on disk. All of these exit `2`,
with **different** stderr lines:

| `--file` value | Stderr (prefix) |
|----------------|-----------------|
| `scheme://…` (http/https/ftp/php/file/…) | `Invalid --file: remote URLs and stream wrappers are not allowed.` |
| empty / bare `--file` | `Invalid --file: provide a local filesystem path to a regular file.` |
| directory | `Invalid --file: path is a directory, not a file` |
| missing / not a regular file | `Invalid --file: not a readable regular file` |

### `post update`

```bash
php ap-cli post update --id=15 --title="New title"
php ap-cli post update --slug=about --type=page --file=./about.html
php ap-cli post update --id=15 --status=publish
php ap-cli post update --id=15 --name=hello-world
```

Select with `--id` or `--slug` (same rules as `get`). Change at least one
of: `--title`, `--file`, `--status`, `--name` / `--post_name` (rename).
`--slug` is **only** a locator here; it does not rename.

No fields to change, empty `--title`/`--status`/`--name` when passed, or
no selector → exit `1`. Not found → exit `2`. Success stdout:
`Updated {post|page} ID {id} ({slug})`.

---

## `cache flush`

```bash
php ap-cli cache flush
php ap-cli cache
```

Calls `ap_cache_flush()` when defined, `AP_Options::flushCache()`, and
`ap_clean_page_cache()`. Success line is either `Object cache flushed.` or
`Cache flush requested (object cache may be unavailable).` — both exit
`0`. Unknown subcommand → exit `1`.

This is not a CDN purge and not a plugin object-cache drop-in manager.
Those are **not in core**.

---

## `cron`

Needs an installed site. Built-in events use `AP_Cron`. `ap-cli` does
**not** auto-run due events (bootstrap skips `AP_Cron::spawn()` when
`AP_CLI` is set).

### `cron event list`

```bash
php ap-cli cron event list
php ap-cli cron event
php ap-cli cron list
```

Tab-separated: ISO-8601 time, hook, schedule (`once` if unset), event key.
Empty: `(no scheduled events)`.

### `cron event run`

```bash
php ap-cli cron event run
php ap-cli cron run
```

Runs due callbacks (`AP_Cron::runDue()`). Stdout: `Ran N due cron callback(s).`

There is **no** `cron schedule` / `cron unschedule` verb in core. The
`cron list` / `cron run` forms are aliases of `cron event list|run`.

---

## `rewrite flush`

```bash
php ap-cli rewrite flush
php ap-cli rewrite
```

Regenerates stored rewrite rules (`AP_Rewrite::flushRules()`). Stdout:
`Rewrite rules flushed (N rule(s)).`

This does **not** write Apache `.htaccess` or Nginx config. Pretty
permalinks still need a front controller (`try_files` / `mod_rewrite`) —
[rewrites.md](rewrites.md). Saving **Settings → Permalinks** in
`/ap-admin/` also flushes.

---

## `site health`

```bash
php ap-cli site health
php ap-cli site
php ap-cli site health --format=json
```

Runs `AP_Site_Health::getChecks()` — the same checks as
**Tools → Site Health** (`/ap-admin/site-health.php`), including outbound
mail (transport + last error; the check does **not** send a message).
Default text:

```text
[GOOD] … — …
[RECO] … — …
[CRIT] … — …

Summary: N good, N recommended, N critical
```

`--format=json` prints the checks array (**pretty** JSON) and **always
exits `0`**, even when a check is critical. Inspect `status` in the
payload. Default **text** format: any **critical** check → exit `2`.
Recommended-only findings still exit `0`.

Unknown site subcommand → exit `1`. There is **no** `site option` or
`site switch` — AgoraPress is not multisite (multisite is **not in core**).

---

## Plugin-registered commands

After a successful core boot, `ap-cli` fires the `ap_cli_init` action.
Plugins (and MU-plugins) may call `AP_Cli::addCommand()` there. Those
names are **not** listed in this cookbook and are **not** core. See
[plugins.md](plugins.md) and [hooks.md](hooks.md).

Dispatch order as built (`AP_Cli::runFromArgv`):

1. Parse argv and register **built-ins**.
2. Unknown group name → exit `1` **before** bootstrap.
3. Only then load core (for `needs_install` commands) and fire
   `ap_cli_init`.

A plugin can **replace** a built-in callback that way. A **new**
top-level group registered only on `ap_cli_init` is **not reachable**:
`php ap-cli newname` is already `Unknown command`. `php ap-cli --help`
does not boot, so it never lists plugin verbs.

Command names are a single token after normalize (`[a-z0-9_-]` only;
spaces are stripped). `php ap-cli foo bar` is group `foo` with
subcommand `bar`.

`--skip-plugins` skips active plugins (MU-plugins still load). Built-ins
remain.

---

## Not in this tool / not in core

| Surface | Where it actually lives |
|---------|-------------------------|
| Fresh install | `php install/cli.php` / `/install/` — [install.md](install.md) |
| Apply a core zip | **Tools → Update Core**, or extract files then `db migrate` — **no** `ap-cli core update` |
| Plugin / theme zip upload | ACP **Plugins** / **Themes** — [plugins.md](plugins.md), [themes.md](themes.md) |
| Classic theme conversion helper | `ap-includes/compatibility/cli-convert.php` — [compatibility.md](compatibility.md) (not an `ap-cli` group) |
| User delete / password reset / role change after create | ACP **Users** — [admin.md](admin.md), [roles.md](roles.md) |
| Post/page delete, media, comments, forums | ACP / front-end — **not** `ap-cli post` |
| `--file=https://…` | Rejected (`scheme://`). Local path only. |
| New `ap-cli` group from `ap_cli_init` only | Not dispatched (unknown before boot). Replace a built-in, or it is **not in core** as a reachable verb. |
| Multisite, Gutenberg/FSE, SaaS, telemetry | **Not in core** |

If `php ap-cli --help` does not list a group, do not invent it.

---

## Operator recipes

```bash
# Another install root + public URL hint
php ap-cli --path=/var/www/agorapress --url=https://your-domain.example option get home

# Broken plugin blocking bootstrap
php ap-cli --skip-plugins plugin list
php ap-cli --skip-plugins plugin deactivate broken-plugin/broken-plugin.php

# After deploying files
php ap-cli core check-update
php ap-cli db migrate
php ap-cli cache flush
php ap-cli rewrite flush
php ap-cli site health

# Turn REST off without the admin UI
php ap-cli option set rest_api_enabled 0

# Create a published page from a local HTML file
php ap-cli post create --type=page --title="About" --slug=about --file=./about.html

# Site Health as JSON (exit 0 even if a check is critical)
php ap-cli site health --format=json
```

Generic paths only: `example.com`, `https://your-domain.example`,
`admin@example.com`, `noreply@example.com`, `smtp.example.com`,
`/var/www/agorapress` (the last already appears in shipped
`docker/nginx.conf.example`).

---

## Related documentation

| Doc | Why |
|-----|-----|
| [../README.md](../README.md) | Landing-page CLI examples |
| [README.md](README.md) | Docs index (humans + trusted agent) |
| [install.md](install.md) | `php install/cli.php` — not this tool |
| [updates.md](updates.md) | `core check-update`, `db migrate`, what an update does not overwrite |
| [rewrites.md](rewrites.md) | Front controller; why `rewrite flush` is not enough alone |
| [admin.md](admin.md) | `/ap-admin/` screens (zip installers, Site Health, Update Core) |
| [plugins.md](plugins.md) | Headers, MU-plugins, `ap_cli_init` |
| [themes.md](themes.md) | Stylesheets, Agora, Theme Options |
| [roles.md](roles.md) | Roles passed to `user create --role=` |
| [schema.md](schema.md) | `AP_DB_VERSION`, migrations |
| [rest.md](rest.md) | HTTP JSON API — not `ap-cli` |
| [troubleshooting.md](troubleshooting.md) | Symptom → check |
| [hooks.md](hooks.md) | `ap_cli_init` |
| [security.md](security.md) | Nonces, no telemetry, deny rules |
| [forums.md](forums.md) | There is **no** `php ap-cli forum` verb |

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
