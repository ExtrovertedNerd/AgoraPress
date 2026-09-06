# REST API (`/ap-json/`)

This is the **operator and integrator guide** for AgoraPress’s lightweight
JSON REST API at **`0.3.6-beta`** (schema `AP_DB_VERSION` **12**). It
describes the public prefix, the `ap/v1` resources **as built**, cookie +
`X-AP-Nonce` or HTTP Basic authentication, and the master switch
`rest_api_enabled`.

The compact landing-page bullets are in
[`../README.md`](../README.md#rest-api). Pretty vs plain URLs:
[rewrites.md](rewrites.md). Nonces and hardening: [security.md](security.md).
404 kinds: [troubleshooting.md](troubleshooting.md). Plugin registration
sketch: [plugins.md](plugins.md).

**Source (as built):** `ap-includes/class-ap-rest.php` (`AP_Rest`), helpers in
`ap-includes/functions.php` (`ap_rest_enabled`, `ap_rest_url`,
`ap_register_rest_route`, `ap_rest_dispatch`, `ap_create_rest_nonce`),
front-controller short-circuit in `index.php`, rewrite match in
`AP_Rewrite`. Inspired by the WordPress REST API **shape** — not a fork and
not a WP-REST compatibility layer.

There is **no** ACP Settings screen for REST, **no** Application Passwords /
OAuth / JWT, and **no** write routes for pages, comments, users, taxonomies,
or forums. Missing from this guide and from core means **not in core**.

---

## How it works

`AP_Rest` registers built-in routes once per process, then fires
`ap_rest_api_init` so plugins can add more. `index.php` short-circuits
**before** feeds and the theme when rewrite vars include `rest_route`. The
response is JSON; page cache is skipped (`AP_Page_Cache::skipRequest()`);
local analytics does not count `/ap-json/` hits.

| Constant / option | Value |
|-------------------|--------|
| Pretty prefix | `ap-json` (`AP_Rest::URL_PREFIX`) |
| Primary namespace | `ap/v1` (`AP_Rest::NAMESPACE`) |
| Master switch | option `rest_api_enabled` (`AP_Rest::OPTION_ENABLED`) |
| Cookie-write nonce action | `ap_rest` (`AP_Rest::NONCE_ACTION`) |
| Fresh-install default | `rest_api_enabled` = `1` (on) |

Disable with `0`. The filter `ap_rest_enabled` can also force the API off.
There is **no** Settings → REST screen; toggle from CLI
([cli.md](cli.md)):

```bash
php ap-cli option get rest_api_enabled
php ap-cli option set rest_api_enabled 0
php ap-cli option set rest_api_enabled 1
```

When off, every dispatch returns HTTP **404** JSON `rest_disabled`
(`REST API is disabled.`). That is not a web-server 404 — see
[troubleshooting.md](troubleshooting.md#rest-404).

---

## URLs

Pretty permalinks (`permalink_structure` non-empty) produce `/ap-json/…`
with a trailing slash. Plain permalinks (fresh-install default) produce
`?rest_route=`:

| Kind | Example |
|------|---------|
| Pretty index | `https://example.com/ap-json/` |
| Pretty resource | `https://example.com/ap-json/ap/v1/posts/` |
| Plain index | `https://example.com/?rest_route=/` |
| Plain resource | `https://example.com/?rest_route=/ap/v1/posts` |

PHP recognizes `/ap-json/…` **even when pretty permalinks are off**, but the
web server still has to hand that path to `index.php`. Missing nginx
`try_files $uri $uri/ /index.php?$args` or Apache `mod_rewrite` 404s
`/ap-json/` the same way it 404s `/slug/` ([rewrites.md](rewrites.md)).
`?rest_route=/ap/v1/posts` still works when `/` runs the front controller.

Helpers:

```php
ap_rest_url('/ap/v1/posts');          // pretty or plain, from site `home`
AP_Rest::getUrl('/ap/v1/settings');   // same
```

`HEAD` is treated as `GET`. Unmatched `OPTIONS` still returns `Allow` /
`Access-Control-Allow-Methods` / `Access-Control-Allow-Headers`
(`Authorization, Content-Type, X-AP-Nonce`). Core does **not** set
`Access-Control-Allow-Origin` on ordinary GET/POST responses.

Emitted headers on a served request:

| Header | Value |
|--------|--------|
| `Content-Type` | `application/json; charset=UTF-8` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Robots-Tag` | `noindex` |

The posts **list** also sets `X-AP-Total`, `X-AP-Page`, and `X-AP-Per-Page`.
`X-AP-Total` is the count of items in **that response** (after an in-memory
search filter), not a site-wide match total.

---

## Authentication

Two methods, in this order:

1. **HTTP Basic** — `Authorization: Basic …` (username **or** email +
   password). PHP CGI fallbacks: `HTTP_AUTHORIZATION`,
   `REDIRECT_HTTP_AUTHORIZATION`, `PHP_AUTH_USER` / `PHP_AUTH_PW`.
2. **Session cookie** — the same logged-in browser cookie as `/ap-admin/`
   (`AP_Session::getCurrentUser()` / `ap_get_current_user_id()`).

The index payload advertises this as
`"authentication": { "cookie": true, "basic": true }`. There is no third
built-in scheme.

### Cookie writes need a nonce

Cookie-authenticated **mutations** (`POST` / `PUT` / `PATCH` / `DELETE`)
require a valid nonce for action `ap_rest`. Send it as:

- Header `X-AP-Nonce` (preferred), or
- Body / params `_ap_nonce`

Compatibility aliases that core also accepts: header `X-WP-Nonce`, body
`_wpnonce`. Create a token with `ap_create_rest_nonce( $user_id )` (same
HMAC path as other [security.md](security.md) nonces).

HTTP Basic **skips** the nonce — the credentials are the proof. Missing or
bad cookie nonce → **403** `rest_cookie_invalid_nonce`.

Anonymous writes → **401** `rest_not_logged_in`. Logged-in without the
capability → **403** (`rest_cannot_create` / `rest_cannot_edit` /
`rest_cannot_delete` / `rest_forbidden`).

### Examples (generic host)

Read (no auth):

```bash
curl https://example.com/?rest_route=/ap/v1/settings
curl https://example.com/ap-json/ap/v1/posts/
```

Create a post with Basic (nonce not required):

```bash
curl -u admin@example.com:'your-password' \
  -H 'Content-Type: application/json' \
  -X POST \
  https://example.com/?rest_route=/ap/v1/posts \
  -d '{"title":"From REST","content":"Hello","status":"draft"}'
```

Create a post from a logged-in browser session (nonce required):

```bash
curl -X POST https://example.com/ap-json/ap/v1/posts/ \
  -H 'Cookie: …session cookie…' \
  -H 'X-AP-Nonce: …ap_create_rest_nonce token…' \
  -H 'Content-Type: application/json' \
  -d '{"title":"From REST","content":"Hello","status":"draft"}'
```

Do not put live passwords, salts, or private hosts in tickets or docs.
`admin@example.com` and `example.com` are examples only.

---

## `ap/v1` resources as built

Built-ins are registered in `AP_Rest::registerBuiltins()`. Forum and topic
routes are **always registered**; their handlers 404 when the Forum module
is off. Blog and Static Pages handlers 404 the same way when those modules
are off.

| Method | Route | Auth | Notes |
|--------|-------|------|--------|
| `GET` | `/` | public | Site index: name, namespaces, route map, `agorapress_version` |
| `GET` | `/ap/v1` | public | Namespace index |
| `GET` | `/ap/v1/settings` | public | Public site settings. `email` is always `""` |
| `GET` | `/ap/v1/posts` | public | Published posts. Blog module |
| `POST` | `/ap/v1/posts` | auth + `edit_posts` | Create. **201** |
| `GET` | `/ap/v1/posts/{id}` | public for published | Single post |
| `PUT`, `PATCH` | `/ap/v1/posts/{id}` | auth + edit cap | Update |
| `DELETE` | `/ap/v1/posts/{id}` | auth + delete cap | Trash, or `force` permanent delete |
| `GET` | `/ap/v1/pages` | public | Published pages. Static Pages module |
| `GET` | `/ap/v1/pages/{id}` | public for published | Single page. **GET only** |
| `GET` | `/ap/v1/comments` | public | Approved comments |
| `GET` | `/ap/v1/comments/{id}` | public if approved | Unapproved: `moderate_comments` |
| `GET` | `/ap/v1/users` | public | Public profile fields (no email) |
| `GET` | `/ap/v1/users/{id}` | public | Email only for self or `list_users` |
| `GET` | `/ap/v1/categories` `[/{id}]` | public | Taxonomy `category` |
| `GET` | `/ap/v1/tags` `[/{id}]` | public | Taxonomy `post_tag` |
| `GET` | `/ap/v1/forums` `[/{id}]` | public | Forum module; else `rest_module_disabled` |
| `GET` | `/ap/v1/topics` `[/{id}]` | public | Same |

Unknown path/method → **404** `rest_no_route`. Do not invent extra core
routes (media, menus, plugins, settings write, comment create, forum
reply, …).

---

## Index and settings

`GET /` (pretty: `/ap-json/`) returns site `name` / `description` / `url` /
`home`, `namespaces` (default `ap/v1`; filter `ap_rest_namespaces`),
`authentication`, a `routes` map of method lists, `timezone_string`,
`agorapress_version` (`AP_VERSION`), `site_logo` (always `null` in core),
and `_links`.

`GET /ap/v1/settings` returns `title`, `description`, `url`, empty
`email`, `timezone`, `date_format`, `time_format`, `language` (`WPLANG`),
`users_can_register`, and `modules` (`static_pages`, `blog`, `forum`
booleans). Admin email is **never** exposed on this public endpoint.

---

## Posts (the only built-in writes)

Requires option `ap_module_blog` on. Otherwise **404**
`rest_module_disabled` (`Blog module is disabled.`).

### List / get

Anonymous list is **published** posts. A logged-in user with `edit_posts`
also sees `draft`, `pending`, `private`, and `future` in the list.

Query params (list):

| Param | As built |
|-------|----------|
| `page` | ≥ 1, default `1` |
| `per_page` | 1–100, default `10` |
| `search` | Case-insensitive substring on title, content, excerpt (in-memory after the query) |
| `author` | Author user id |
| `order` | `ASC` or `DESC` (default `DESC`) |
| `orderby` | `date` (default), `title`, `id`, `modified`, `slug` |

Single get: published + no password is public. Password-protected or
non-public posts need to be the author or have the matching edit/read-private
caps; otherwise **403** `rest_forbidden`. Invalid id → **404**
`rest_post_invalid_id`.

Password-protected content is stripped in the payload
(`password_protected: true`, empty `content` / `excerpt`). The password
value is never serialized.

Post object (selected fields): `id`, `date` / `date_gmt`, `modified` /
`modified_gmt`, `slug`, `status`, `type`, `link`, `title` / `content` /
`excerpt` (`raw` + `rendered`), `author`, `parent`, `menu_order`,
`comment_status`, `comment_count`, `password_protected`. Filter
`ap_rest_prepare_post` can reshape this. Rendered content runs
`ap_the_content`.

### Create / update / delete

Body is JSON (`Content-Type: application/json`) or
`application/x-www-form-urlencoded`. Field aliases: `title` /
`post_title`, `content` / `post_content`, `excerpt` / `post_excerpt`,
`status` / `post_status`, `slug` / `post_name`.

| Write | As built |
|-------|----------|
| Create | Title **or** content required (else **400** `rest_missing_callback_param`). Default `status` is `draft`. Status `publish` / `future` / `private` without `publish_posts` is stored as `pending`. Author is the authenticated user. **201**. |
| Update | `PUT` or `PATCH`. Same field aliases. Empty body is a no-op **200**. |
| Delete | Default **trash**. `force` (query, body, or params) permanently deletes. Response `{ "deleted": true, "previous": {…} }`. |

Caps: create needs `edit_posts`; update uses `edit_post` / `edit_posts` /
`edit_others_posts`; delete uses `delete_posts` / `delete_others_posts`.
See [roles.md](roles.md).

---

## Pages

Requires `ap_module_static_pages`. **GET only** — there is no REST create /
update / delete for pages (use [cli.md](cli.md) `php ap-cli post` with
`--type=page`, or the ACP).

List: `page` / `per_page` (defaults 1 / 10, max 100), ordered by
`menu_order` `ASC`. Visibility matches posts (publish for anonymous;
`edit_pages` unlocks extra statuses). Invalid id → `rest_post_invalid_id`.
Payload shape is the same `preparePost` object with `"type": "page"`.

---

## Comments

**GET only.** List is `status=approve` only, newest first
(`comment_date_gmt` `DESC`). Params: `page`, `per_page` (default 10, max
100), `post` or `post_id`.

Single get of a non-approved comment requires `moderate_comments`; others
get **403**. Invalid id → `rest_comment_invalid_id`.

Payload: `id`, `post`, `parent`, `author` (user id), `author_name`,
`date` / `date_gmt`, `content` (`raw` + `rendered`), `status`
(`approved` / `hold` / `spam` / `trash`), `type`. No commenter email in
the public object.

There is **no** REST comment create, edit, spam, or delete.

---

## Users

**GET only.** List: `page` / `per_page` (default 10, max 100), `orderby=ID`
`ASC`. Invalid id → `rest_user_invalid_id`.

Public fields: `id`, `name` (display name, else login), `url`,
`description` (profile meta), `slug` (`user_nicename`), `avatar_urls`
(sizes 24 / 48 / 96 when the avatar helper is loaded).

`email` is added **only** when the viewer is that user or has
`list_users`. Anonymous lists never include email. Filter
`ap_rest_prepare_user`. There is **no** REST user create / role change /
password reset.

---

## Categories and tags

**GET only.** `/categories` uses taxonomy `category`; `/tags` uses
`post_tag`. Params: `page` (default 1), `per_page` (default **100**, max
100), `hide_empty` (truthy → hide unused terms). Ordered by `name` `ASC`.
Invalid id → `rest_term_invalid`.

Payload: `id`, `count`, `description`, `link`, `name`, `slug`,
`taxonomy`, `parent`. No REST term create / rename / delete.

---

## Forums and topics

Requires `ap_module_forum` ([forums.md](forums.md)). When the module is
off, these handlers return **404** `rest_module_disabled`
(`Forum module is disabled.`).

| Route | As built |
|-------|----------|
| `GET /ap/v1/forums` | Open forums (`status=open`) |
| `GET /ap/v1/forums/{id}` | One forum; missing → `rest_forum_invalid_id` |
| `GET /ap/v1/topics` | `forum` / `forum_id` lists that forum’s open topics; without it, approved topics if `AP_Forum::queryTopics` exists |
| `GET /ap/v1/topics/{id}` | One topic; missing → `rest_topic_invalid_id` |

Topic list params: `page` (default 1), `per_page` (default **20**, max
100).

Forum payload: `id`, `name`, `slug`, `description`, `parent`, `type`,
`status`, `topic_count`, `post_count`, `link`. Topic payload: `id`,
`forum`, `title`, `slug`, `status`, `type`, `author`, `post_count`,
`views`, `link`, `date`.

**Read-only.** No REST create topic, reply, like, lock, attach, or PM.

---

## Errors

JSON body (HTTP status matches `data.status`):

```json
{
  "code": "rest_disabled",
  "message": "REST API is disabled.",
  "data": { "status": 404 }
}
```

Codes you will actually see from core:

| Code | HTTP | Meaning |
|------|------|---------|
| `rest_disabled` | 404 | `rest_api_enabled` is `0` (or `ap_rest_enabled` forced off) |
| `rest_no_route` | 404 | Path + method not registered |
| `rest_module_disabled` | 404 | Blog / Static Pages / Forum module off for that resource |
| `rest_post_invalid_id` | 404 | Missing post or page, or wrong type |
| `rest_comment_invalid_id` | 404 | Missing comment |
| `rest_user_invalid_id` | 404 | Missing user |
| `rest_term_invalid` | 404 | Missing category or tag |
| `rest_forum_invalid_id` | 404 | Missing forum |
| `rest_topic_invalid_id` | 404 | Missing topic |
| `rest_not_logged_in` | 401 | Write without credentials |
| `rest_forbidden` | 403 | View/action not allowed |
| `rest_cannot_create` / `rest_cannot_edit` / `rest_cannot_delete` | 403 or 500 | Cap failure or persistence failure |
| `rest_missing_callback_param` | 400 | Create post with empty title and content |
| `rest_cookie_invalid_nonce` | 403 | Cookie write missing/bad `X-AP-Nonce` |
| `rest_internal_error` | 500 | Uncaught throwable in a callback |
| `rest_unavailable` | 503 | Front controller catch, or `ap_rest_dispatch` when the class is not loaded |

Split a **404** into web-server HTML vs these JSON codes before changing
permalinks ([troubleshooting.md](troubleshooting.md#rest-404)).

---

## Plugins

Register on `ap_rest_api_init` (after built-ins):

```php
ap_add_action('ap_rest_api_init', static function (): void {
    ap_register_rest_route('myplugin/v1', '/ping', [
        'methods' => 'GET',
        'callback' => static function (): array {
            return ['status' => 200, 'data' => ['pong' => true]];
        },
        'permission_callback' => static fn (): true => true,
    ]);
});
```

`AP_Rest::registerRoute( $namespace, $route, $args )` is the same call.
`$route` may include named captures (`/items/(?P<id>\d+)`). `$args`:

| Key | As built |
|-----|----------|
| `methods` | String (`GET,POST`) or array. Default `GET` |
| `callback` | Required. Request keys: `method`, `route`, `params`, `query`, `body`, `headers`, `user_id`, `db`, `server` |
| `permission_callback` | Return `true` or `{code, message, status}` |
| `args` / `schema` | Stored on the route; core does not auto-validate them |

Return `{status, data}` (optional `headers`), a WP-style
`{code, message, data: {status}}` error, or a raw JSON-able value
(treated as **200**). Helpers: `ap_rest_enabled()`, `ap_rest_dispatch()`
(no HTTP emit — tests / internal). Action `ap_rest_served` runs after
dispatch, before emit.

Capability-check every privileged callback. Fail closed if a module you
depend on is off. Details: [plugins.md](plugins.md), [hooks.md](hooks.md).

---

## Not in core

Do not tell operators or agents these exist in AgoraPress core:

- An ACP Settings screen for `rest_api_enabled` (use
  `php ap-cli option set rest_api_enabled 0`)
- Application Passwords, OAuth, JWT, API keys, or a remote token exchange
- REST writes for pages, comments, users, categories, tags, forums, topics,
  replies, likes, or PMs
- `/ap-json/wp/v2/…` or a WordPress REST compatibility namespace
- Media / attachments, menus, widgets, plugins, themes, or settings **write**
  endpoints
- Batch requests, embeds, revisions-as-REST, or search-as-REST
- A CORS `Access-Control-Allow-Origin` policy on GET/POST
- REST-specific rate limiting (login still uses `AP_Rate_Limit`)
- Gutenberg / block REST types

If a plugin registered extra routes on `ap_rest_api_init`, those routes are
**that plugin**, not core. Discover them from `GET /ap-json/` `routes`.

---

## Related docs

| Need | Doc |
|------|-----|
| Pretty `/ap-json/` vs `?rest_route=` | [rewrites.md](rewrites.md) |
| HTML 404 vs JSON `rest_disabled` | [troubleshooting.md](troubleshooting.md#rest-404) |
| Nonces, Basic vs cookie | [security.md](security.md) |
| `php ap-cli option set rest_api_enabled` | [cli.md](cli.md) |
| Caps for post writes / `list_users` | [roles.md](roles.md) |
| Forum module-off | [forums.md](forums.md) |
| Plugin `ap_register_rest_route` | [plugins.md](plugins.md) |
| Selected hooks (`ap_rest_api_init`) | [hooks.md](hooks.md) |
| ACP has no REST screen | [admin.md](admin.md) |
| Landing-page REST blurb | [../README.md](../README.md#rest-api) |
| Tables behind `ap/v1` resources | [schema.md](schema.md) |
| Fresh install leaves `rest_api_enabled` on | [install.md](install.md) |
