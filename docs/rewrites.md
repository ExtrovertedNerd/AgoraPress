# Permalinks and rewrites

This is the **front-controller and permalink guide** for AgoraPress
**`0.3.6-beta`** (schema `AP_DB_VERSION` **12**). It describes URL rewriting
**as built**: the shipped Apache [`.htaccess`](../.htaccess), the shipped
Nginx example [`docker/nginx.conf.example`](../docker/nginx.conf.example),
pretty permalinks vs query-string `?p=` / `?page_id=`, day-and-name posts vs
`/slug/` pages, and `php ap-cli rewrite flush`.

**Source (as built):** `index.php`, `ap-includes/class-ap-rewrite.php`,
`ap-admin/options-permalink.php`, `.htaccess`, `ap-content/.htaccess`,
`docker/nginx.conf.example`, `docker/apache-vhost.conf`,
`ap-includes/class-ap-cli.php` (`rewrite flush`).

Pretty permalinks need a **front controller**: the web server must send
unknown paths to `index.php`. AgoraPress then parses the path (`AP_Rewrite`).
Missing that hop is the usual cause of **404s on `/slug/`** (and on
`/YYYY/MM/DD/slug/`). Plain `?p=` still works without rewriting.

There is **no** IIS `web.config`, Caddyfile, or PHP built-in-server router in
core. Adapt the Apache or Nginx examples.

---

## Two layers

| Layer | What it does | Where |
|-------|----------------|--------|
| **Web server** | Serve real files as files. Send every other path to `index.php`. | Apache: shipped [`.htaccess`](../.htaccess). Nginx: `try_files $uri $uri/ /index.php?$args` in [`docker/nginx.conf.example`](../docker/nginx.conf.example). |
| **PHP (`AP_Rewrite`)** | Turn the path (and `$_GET`) into query vars for `AP_Query`. Build public links. | `ap-includes/class-ap-rewrite.php`, called from `index.php` via `ap_parse_request()` |

The web server does **not** contain per-post rewrite rules. `.htaccess` and
the Nginx `location /` block are a single front-controller pattern. Individual
post, page, archive, forum, feed, sitemap, and REST paths are matched in PHP.

Request flow (public front end):

```
Browser
  → Apache / Nginx (file? serve it. else → index.php)
  → index.php → bootstrap
  → AP_Rewrite::parseFromGlobals()  (REQUEST_URI + $_GET)
  → REST / feed / sitemap short-circuit, or theme template
```

`/ap-admin/` and `/install/` are **real directories** with their own PHP
entry points. Existing files and directories are not rewritten, so the admin
and installer do not go through the front-controller path match.

Document root must be the AgoraPress root: the folder that contains
`index.php`, `ap-admin/`, `ap-includes/`, `ap-content/`, and `install/`.

---

## Pretty vs `?p=` / `?page_id=`

Option `permalink_structure` (Settings → Permalinks,
`options-permalink.php`, cap `manage_options`).

| Mode | Stored structure | Example post | Example page |
|------|------------------|--------------|--------------|
| **Plain** (fresh install default) | Empty string | `https://example.com/?p=123` | `https://example.com/?page_id=45` |
| **Pretty** | Any non-empty structure | Path from the chosen structure | `https://example.com/about/` |

A fresh install seeds **Plain**. That works on hosts without `mod_rewrite` or
without Nginx `try_files`, as long as `/` runs `index.php` (Apache
`DirectoryIndex` / Nginx `index index.php`).

**`?p=` and `?page_id=` still work** after you switch to a pretty structure.
`AP_Rewrite` always maps those public query args. On the site home (`/` with
no extra path) they are the whole request. On a pretty path they overlay only
when the path did not already set the same var. Bookmarks and plugins that
link `?p=123` keep working.

Plain-mode equivalents (when the structure is empty):

| Resource | Query |
|----------|--------|
| Post | `?p={id}` |
| Page | `?page_id={id}` |
| Category | `?cat={id}` or `?category_name={slug}` |
| Tag | `?tag={slug}` |
| Author | `?author_name={login}` |
| Search | `?s={query}` |
| Feed | `?feed=rss2` |
| REST | `?rest_route=/ap/v1/posts` |
| Forum index | `?ap_forum_view=index` |
| Forum | `?ap_forum_view=forum&forum_id={id}` |
| Topic | `?ap_forum_view=topic&topic_id={id}` |

---

## Day-and-name posts vs `/slug/` pages

**Posts** follow `permalink_structure`. **Pages** do not: when pretty
permalinks are on, a page is always `/{path}/` (hierarchical: parent slugs,
then the page slug). Changing the post structure does not change page URLs.

Common presets (`AP_Rewrite::commonStructures()`, same radios as
Settings → Permalinks):

| Label | Structure | Example post URL |
|-------|-----------|------------------|
| Plain | *(empty)* | `/?p=123` |
| **Day and name** | `/%year%/%monthnum%/%day%/%postname%/` | `/2026/08/03/hello-world/` |
| Month and name | `/%year%/%monthnum%/%postname%/` | `/2026/08/hello-world/` |
| Numeric | `/archives/%post_id%/` (normalized) | `/archives/123/` |
| Post name | `/%postname%/` | `/hello-world/` |
| Custom | Any combination of supported tags | Operator-defined |

Admin tag list (Settings → Permalinks help text): `%year%` `%monthnum%`
`%day%` `%post_id%` `%postname%` `%category%` `%author%`.

**Pages (pretty):**

| Page | URL |
|------|-----|
| Top-level `about` | `/about/` |
| Child `child` under `parent` | `/parent/child/` |

If pretty permalinks are **off**, those same pages are `?page_id=`.

Date archives (`/2026/`, `/2026/08/`, `/2026/08/03/`) are separate from a
day-and-name **post** (`/2026/08/03/hello-world/`). The extra slug is the
post.

With **Post name** (`/%postname%/`), a post at `/hello/` is matched as a
post before the page catch-all. Do not give a post and a page the same slug
if you use that structure — the post rule wins.

Optional bases (empty → defaults):

| Option | Default | Pretty URL |
|--------|---------|------------|
| `category_base` | `category` | `/category/{slug}/` |
| `tag_base` | `tag` | `/tag/{slug}/` |

Other pretty paths `AP_Rewrite` generates when the structure is non-empty:

| Path | Meaning |
|------|---------|
| `/author/{login}/` | Author archive |
| `/search/{query}/` | Search |
| `/feed/` · `/feed/atom/` | Site feeds |
| `/page/2/` | Blog index pagination |
| `/forums/` · `/forums/{slug}/` · `/topic/{slug}/` | Forum front (see [forums.md](forums.md)) |
| `/ap-json/` · `/ap-json/ap/v1/…` | REST (see [rest.md](rest.md)) |

`/sitemap.xml` and `/robots.txt` are recognized **even when pretty
permalinks are off** — crawlers expect those paths — but the web server still
has to hand them to `index.php`. Without a front controller they 404 like any
other pretty URL. Query-string forms `?sitemap=index` and `?robots=1` also
work.

---

## Apache (shipped `.htaccess`)

Keep the root [`.htaccess`](../.htaccess). The vhost must allow it:

```apache
AllowOverride All
```

Shipped Docker Apache (`docker/apache-vhost.conf`) sets that on
`/var/www/html`. Shared-hosting Apache usually allows `.htaccess` already.
If `AllowOverride None`, pretty URLs never reach `index.php`.

What the shipped file does:

1. **Deny** `ap-config.php`, `composer.json` / `composer.lock`, `.env`.
2. **Deny** `.sqlite` / `.sqlite3` / `.db` (demo default
   `ap-content/database.sqlite`).
3. **Allow** public CSS/JS under `ap-includes/css/` and `ap-includes/js/`.
4. **Forbid** other `ap-includes/` (PHP and schema are include-only).
5. **Front controller:** if the request is not an existing file or
   directory, rewrite to `/index.php`.

Relevant block (comments in the file match this behaviour):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    RewriteRule ^ap-includes/(css|js)/ - [L]
    RewriteRule ^ap-includes/ - [F,L]

    RewriteRule ^index\.php$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.php [L]
</IfModule>
```

`!-f` / `!-d` is why a manual root **`favicon.ico`** is served as a static
file and never hits PHP. Same for uploads, theme CSS, and anything else that
exists on disk.

`ap-content/.htaccess` turns off directory indexes and also denies
`.sqlite` / `.db`. It is **not** the front controller.

`AP_Rewrite::apacheRewriteBlock()` returns a shorter snippet (engine on,
skip files, rewrite to `index.php`). The **repo file** is what production
should keep: it adds the deny rules. Saving Permalinks does **not** rewrite
`.htaccess` (unlike some CMS hosts). Copy or keep the shipped file.

**`mod_rewrite` required** for pretty permalinks. Without it, stay on Plain
(`?p=`).

Subdirectory install: shipped `RewriteBase /` assumes the site is at the
domain root. If the public URL is `https://example.com/blog/`, set
`RewriteBase /blog/` to match. PHP still strips the home-path prefix when
parsing (`AP_Rewrite` uses the stored `home` option).

---

## Nginx (shipped example)

Docker Compose in this repo is **Apache**, not Nginx. For a live Nginx host,
start from [`docker/nginx.conf.example`](../docker/nginx.conf.example).

The required front-controller line is:

```nginx
try_files $uri $uri/ /index.php?$args;
```

Full `location /` as shipped:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
```

`$uri` is the request path as a file. `$uri/` is that path as a directory
(so `/` can use `index index.php`). If neither exists, Nginx internally
rewrites to `/index.php` and keeps the query string (`$args`). PHP then
reads `REQUEST_URI` (for example `/2026/08/03/hello-world/`) and
`AP_Rewrite` maps it.

`AP_Rewrite::nginxTryFilesSnippet()` returns that same `location /` block.

### Why missing `try_files` 404s pretty URLs

Nginx does not read `.htaccess`. If the server block only has
`index index.php` and no `try_files` (or equivalent) fall-through:

| Request | Result without `try_files` | Result with shipped `try_files` |
|---------|----------------------------|----------------------------------|
| `/` | `index.php` (directory index) | Same |
| `/?p=123` | `index.php` with query string | Same — **plain still works** |
| `/about/` | **404** (no such file) | `index.php` → page `about` |
| `/2026/08/03/hello-world/` | **404** | `index.php` → day-and-name post |
| `/favicon.ico` (file exists) | Static 200 | Static 200 (`$uri` first) |
| `/ap-admin/index.php` | PHP | PHP (`location ~ \.php$`) |

That 404 is the web server, not AgoraPress. Flushing rewrite rules will not
fix it. Add `try_files $uri $uri/ /index.php?$args;` (and a working
`fastcgi_pass` for `.php`).

The example also:

- Sets `root /var/www/agorapress;` and `server_name example.com;` (adjust).
- Denies `ap-config.php`, composer files, `.env`, SQLite/DB files, and
  raw `ap-includes/` PHP (CSS/JS locations stay public).
- Leaves existing files, including a manual root `favicon.ico`, to `$uri`.

PHP-FPM socket in the example is `unix:/run/php/php8.3-fpm.sock` — change it
to the socket your host actually uses. That path is an example, not a
product requirement.

---

## Flush rewrite rules

PHP stores generated rules in option `rewrite_rules` (JSON). Flush
**regenerates that option**. It does **not** write `.htaccess`, Nginx
config, or `web.config`.

Do this after you change the permalink structure or category/tag bases:

| How | What happens |
|-----|----------------|
| **Settings → Permalinks → Save Changes** | `AP_Options::updatePermalinkSettings()` → `AP_Rewrite::setStructure()` / `setCategoryBase()` / `setTagBase()` → each calls `flushRules()` |
| **CLI** | `php ap-cli rewrite flush` |

```bash
php ap-cli rewrite flush
```

Built-in command group `rewrite`, usage `rewrite flush` (empty subcommand
also flushes). Prints `Rewrite rules flushed (N rule(s)).` Exit `0` on
success. Unknown subcommand: usage text, exit `1`. Needs an installed site
(`ap-config.php`); otherwise `ap-cli` exits `3`.

`php ap-cli rewrite flush` does **not** reload Apache or Nginx. If pretty
URLs 404, fix the vhost / `.htaccess` / `try_files` first.

Integrators: `AP_Rewrite::flushRules()` / `ap_flush_rewrite_rules()`.

---

## Static root `favicon.ico`

Shipped Apache skips existing files (`RewriteCond %{REQUEST_FILENAME} !-f`).
Shipped Nginx tries `$uri` first. A file named `favicon.ico` in the document
root is left alone.

When option `site_icon` is unset (`0`), core emits **no** `<link rel="icon">`
tags, so browsers may still request `/favicon.ico` as the usual passive
default. Details: [site-icon.md](site-icon.md).

---

## What is not in core

- Writing `.htaccess` (or Nginx config) from **Settings → Permalinks**
- An IIS / Caddy / Traefik snippet
- Multisite path or domain mapping
- Trailing-slash canonical redirects as a separate feature (pretty
  structures are stored and generated **with** a trailing slash)
- A Site Health check that probes `mod_rewrite` or `try_files`

If those surfaces are not in this guide and not in the shipped files, they
are **not in core**.

---

## Checklist

1. Document root = AgoraPress root (`index.php` lives there).
2. Fresh site: Plain (`?p=` / `?page_id=`) until the front controller is
   confirmed.
3. Apache: shipped `.htaccess` present, `mod_rewrite` on, `AllowOverride All`.
4. Nginx: `try_files $uri $uri/ /index.php?$args;` plus PHP-FPM for
   `index.php`.
5. Settings → Permalinks: pick **Day and name** (or another pretty
   structure). Pages become `/slug/`. Save (flush) or run
   `php ap-cli rewrite flush`.
6. Confirm `?p=` still opens the same post.
7. Confirm a real file (`/favicon.ico` if you placed one, or a theme CSS
   URL) is **not** rewritten to PHP.

Post-install context: [install.md](install.md#post-install-checklist).
Symptom list: [troubleshooting.md](troubleshooting.md).

---

## Related documentation

| Doc | Why |
|-----|-----|
| [install.md](install.md) | Permalinks on the post-install checklist |
| [../README.md](../README.md) | Landing page; production rewrite bullet |
| [README.md](README.md) | Docs index |
| [cli.md](cli.md) | `ap-cli` flags and exit codes |
| [site-icon.md](site-icon.md) | Passive root `favicon.ico` |
| [rest.md](rest.md) | `/ap-json/` vs `?rest_route=` |
| [forums.md](forums.md) | `/forums/` · `/topic/` |
| [security.md](security.md) | Deny rules on the same `.htaccess` / Nginx example |
| [troubleshooting.md](troubleshooting.md) | Pretty URL 404 → check `try_files` / `.htaccess` |
| [admin.md](admin.md) | Settings → Permalinks (save flushes rules) |
| [updates.md](updates.md) | One-click apply **does** copy root `.htaccess` from the package |

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
