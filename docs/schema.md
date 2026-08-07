# Database schema

AgoraPress uses a **versioned migration system** and a configurable table prefix (default **`ap_`**). Schema supports **MySQL 8+ / MariaDB 10.6+**, **SQLite 3.35+**, and **PostgreSQL**.

**Source:** `ap-includes/schema/migrations/`, `class-ap-migrator.php`, `class-ap-migration.php`, `class-ap-db.php`  
**Target schema version:** `AP_DB_VERSION` in `ap-includes/version.php` (currently **12**, AgoraPress `0.3.0-beta`)

## Conventions

| Topic | Detail |
|-------|--------|
| Prefix | `$table_prefix` in `ap-config.php` (default `ap_`). Applied via `AP_DB::table('posts')` → `{prefix}posts` |
| Charset | MySQL: `utf8mb4` / `utf8mb4_unicode_ci` |
| Access | PDO **prepared statements only** — never concatenate untrusted input into SQL |
| Registry | Applied versions stored in `{prefix}schema_migrations` |
| Migrations | Numbered files `NNNN_slug.php` implementing `AP_Migration` |

### Helpers

```php
$apdb->table('users');           // prefixed name
$apdb->quoteIdentifier(...);
ap_get_table_prefix();
ap_prefixed_table('posts');
ap_core_base_tables();           // list of core base names
ap_forum_base_tables();
ap_all_base_tables();
```

Install / upgrade:

```bash
php install/cli.php …            # fresh install runs migrations
php ap-cli db migrate            # apply pending on an installed site
php ap-cli db check
```

## Migration map

| Ver | File | Creates |
|-----|------|---------|
| 1 | `0001_core_options_users.php` | `options`, `users`, `usermeta` |
| 2 | `0002_core_posts_postmeta.php` | `posts`, `postmeta` |
| 3 | `0003_core_terms_taxonomies.php` | `terms`, `term_taxonomy`, `term_relationships` |
| 4 | `0004_core_comments_commentmeta.php` | `comments`, `commentmeta` |
| 5 | `0005_forum_tables.php` | `forums`, `topics`, `forum_posts`, `groups`, `group_members`, `messages`, `ranks`, `reports`, `online` |
| 6 | `0006_forum_attachments.php` | `forum_attachments` |
| 7 | `0007_forum_permissions.php` | `forum_permissions` |
| 8 | `0008_forum_moderation.php` | `warnings`, `bans` |
| 9 | `0009_forum_online_unread.php` | `topic_track`, `forum_track` |
| 10 | `0010_analytics_tables.php` | `analytics_hits`, `analytics_daily` |
| 11 | `0011_forum_likes_stats.php` | `forum_post_likes`; `forum_posts.like_count` |

Also created by the migrator infrastructure: **`{prefix}schema_migrations`**.

All physical names are `{prefix}{base}` (e.g. `ap_posts`). Below, tables are named by **base** only.

---

## Core tables

### options

Site options (Options API).

| Column | Notes |
|--------|-------|
| `option_id` | PK |
| `option_name` | Unique, max 191 |
| `option_value` | Long text / JSON-encoded arrays |
| `autoload` | `yes` / `no` — autoloaded options primed in one query on bootstrap |

### users

Shared accounts for CMS, forums, and admin.

| Column | Notes |
|--------|-------|
| `ID` | PK |
| `user_login` | Login name |
| `user_pass` | Argon2id (or fallback) hash |
| `user_nicename` | URL slug |
| `user_email` | |
| `user_url` | |
| `user_registered` | |
| `user_activation_key` | Verify / reset keys |
| `user_status` | `0` active; non-zero pending/disabled |
| `display_name` | |

### usermeta

| Column | Notes |
|--------|-------|
| `umeta_id` | PK |
| `user_id` | |
| `meta_key` / `meta_value` | Capabilities, sessions, avatar, profile fields, … |

### posts

Posts, pages, revisions, attachments, CPTs.

| Column | Notes |
|--------|-------|
| `ID` | PK |
| `post_author` | → users.ID |
| `post_date` / `post_date_gmt` | |
| `post_content` / `post_title` / `post_excerpt` | |
| `post_status` | publish, draft, pending, private, future, trash, … |
| `comment_status` / `ping_status` | |
| `post_password` | |
| `post_name` | Slug |
| `post_modified` / `post_modified_gmt` | |
| `post_content_filtered` | |
| `post_parent` | Hierarchy / revision parent |
| `guid` | |
| `menu_order` | |
| `post_type` | post, page, revision, attachment, … |
| `post_mime_type` | Attachments |
| `comment_count` | Denormalized |

### postmeta

| Column | Notes |
|--------|-------|
| `meta_id` | PK |
| `post_id` | |
| `meta_key` / `meta_value` | Sticky, page template, attachment path, … |

### terms / term_taxonomy / term_relationships

Classic taxonomy model:

- **terms** — `term_id`, `name`, `slug`, `term_group`  
- **term_taxonomy** — `term_taxonomy_id`, `term_id`, `taxonomy`, `description`, `parent`, `count`  
- **term_relationships** — `object_id`, `term_taxonomy_id`, `term_order`  

Built-ins: `category` (hierarchical), `post_tag` (flat).

### comments / commentmeta

| comments (selected) | Notes |
|---------------------|-------|
| `comment_ID` | PK |
| `comment_post_ID` | |
| `comment_author` / email / url / IP | |
| `comment_date` / `comment_date_gmt` | |
| `comment_content` | |
| `comment_approved` | `1`, `0`, `spam`, `trash` |
| `comment_parent` | Nested threads |
| `user_id` | Logged-in author |

`commentmeta`: `meta_id`, `comment_id`, `meta_key`, `meta_value`.

---

## Forum tables (dedicated)

Forums use **dedicated tables** for performance while **sharing** users, capabilities, options, and media with the CMS.

### forums

Hierarchical categories & forums.

| Column (selected) | Notes |
|-------------------|-------|
| `forum_id` | PK |
| `parent_id` | Tree |
| `forum_type` | `category` \| `forum` \| `link` |
| `forum_status` | `open` \| `closed` \| `hidden` |
| `forum_name` / `forum_slug` / `forum_desc` | |
| `forum_order` | Sort |
| `topic_count` / `post_count` | Denormalized |
| `last_post_id` / `last_poster_id` / `last_post_time` / `last_topic_id` | |

### topics

| Column (selected) | Notes |
|-------------------|-------|
| `topic_id` | PK |
| `forum_id` | |
| `topic_title` / `topic_slug` | |
| `topic_poster` | User ID |
| `topic_type` | standard / sticky / announcement / rules (v12; legacy normal/announce/global backfilled) |
| `topic_status` | open / locked / moved / deleted, … |
| `topic_approved` | Approval queue |
| `topic_views` / reply counts | |
| timestamps / last post pointers | |

### forum_posts

Replies (separate from blog `comments`).

| Column (selected) | Notes |
|-------------------|-------|
| `post_id` | PK |
| `topic_id` / `forum_id` | |
| `poster_id` | |
| `post_content` / `post_content_filtered` | Raw + rendered HTML |
| `poster_ip` | |
| `like_count` | Denormalized thumbs-up total (v11) |
| approval / edit trail / report flags | |

### forum_post_likes

One like per user per forum post (v11). Unique on `(post_id, user_id)`.

| Column | Notes |
|--------|-------|
| `like_id` | PK |
| `post_id` | → forum_posts |
| `user_id` | → users |
| `created_at` | When the like was cast |

User-level aggregates live in **usermeta** (maintained by `AP_Forum_Stats`):

| Meta key | Meaning |
|----------|---------|
| `forum_posts` | Approved forum posts authored |
| `forum_likes_given` | Likes this user has cast |
| `forum_likes_received` | Likes on this user's posts |

### groups / group_members

Forum user groups (permission foundation). System groups include guests, registered, administrators, global_moderators (seeded by installer).

### forum_permissions

ACL: **group × forum × capability**. `forum_id = 0` means global defaults. Capabilities include view/read/post/edit/attach/moderate/sticky/announce/lock/move (resolved by `AP_Forum_Permissions`).

ACP manages this via **user levels** (Guest → Registered → Moderator → Administrator) with increasing ability, plus presets (Public, Members only, Read only, Moderators only, Administrators only, Custom). Forum ACL never applies to blog posts or pages (those use publish status only).

### forum_attachments

Links media library files to forum posts/topics (quotas, download counts).

### messages

Private messages (threads via `parent_id`, soft-delete per user).

### ranks

Post-count / special ranks.

### reports

Moderation reports on topics/posts.

### warnings / bans

Staff warnings; user / IP / email bans and suspensions (`AP_Forum_Moderation`).

### online

Who’s online / session presence (`AP_Online`).

### topic_track / forum_track

Per-user last-read markers for unread tracking (`AP_Forum_Read`). Usermeta `forum_last_mark` may complement “mark all read”.

### analytics_hits

Raw page-view hits for local admin analytics (no third-party phone-home).

**Options** (seeded on install; see `AP_Analytics`):

| Option | Default | Notes |
|--------|---------|--------|
| `analytics_enabled` | **off** (`0`) | Opt-in collection. Fresh installs never record hits until an admin enables this. Missing option is treated as off. |
| `analytics_retention_days` | `90` | Whole days of history kept before prune/cron. Clamped to 1–3650. |

Collection is optional (`analytics_enabled`; default **off**). When enabled, a server-side shutdown recorder writes public GET/HEAD page views (skips ap-admin, feeds, REST, sitemaps, obvious bots, and logged-in `manage_options` users by default). 404s are recorded with `status_code=404` unless filtered off. No front-end JS.

**Retention prune:** daily pseudo-cron hook `ap_analytics_prune` (`AP_Analytics::registerCron()` / `AP_Analytics::prune()`) deletes rows older than `analytics_retention_days` from `analytics_hits` (`hit_time`) and `analytics_daily` (`day`). Runs even when collection is disabled. First schedule is ensured on bootstrap before `AP_Cron::spawn()`.

**Aggregation helpers** (`AP_Analytics`, read-only unless noted; ACP reports use these):

| Method | Purpose |
|--------|---------|
| `countHits()` | Filtered hit count (range, path, status, exclude admin) |
| `getSummary()` | Pageviews today / last 7 days / last 30 days |
| `getTopPaths()` | Top paths by hit count |
| `getTopReferrers()` | Top non-empty referrers |
| `getDailyTotals()` | Per-day totals (gap-filled chart series) |
| `rollupDaily()` | Rebuild `analytics_daily` rows from raw hits for a range |

Reports still work when collection is disabled (historical data). Defaults exclude `is_admin=1` hits for public pageview numbers.

| Column | Notes |
|--------|-------|
| `hit_id` | PK |
| `hit_time` | Request timestamp (prune by retention) |
| `path` | Request path (prefer without query noise); max 512 |
| `object_id` | Post/page ID when known; `0` otherwise |
| `status_code` | HTTP status (e.g. 200, 404) |
| `referrer` | Optional truncated referrer |
| `ua_class` | Optional coarse class: `browser` / `bot` / `other` (not full UA) |
| `is_admin` | `1` when hit is flagged as logged-in admin traffic |

Indexes on `hit_time`, `path`, `object_id`, `status_code`, `ua_class`.

### analytics_daily

Daily rollups for ACP summaries (pageviews by day/path/object). Populated by `AP_Analytics::rollupDaily()` (or future cron); ACP may also query `analytics_hits` directly via aggregation helpers.

| Column | Notes |
|--------|-------|
| `day` | Calendar day (`YYYY-MM-DD`) |
| `path` | Same path key as hits; empty path + `object_id=0` may hold site totals |
| `object_id` | Content ID when rolled up by object; `0` for path-only |
| `hits` | Aggregated view count |

Primary key: `(day, path, object_id)`.

---

## Entity relationships (simplified)

```
users ──┬── posts / postmeta
        ├── comments
        ├── usermeta (caps, sessions, avatar)
        ├── group_members → groups → forum_permissions → forums
        ├── topics / forum_posts / messages
        ├── topic_track / forum_track / online
        └── warnings / bans

analytics_hits / analytics_daily  (optional local analytics; path or object_id → posts)

forums → topics → forum_posts → forum_attachments → media (posts attachment type)
terms ← term_taxonomy ← term_relationships → posts
```

Media files live on disk under `ap-content/uploads/`; DB rows are `posts` with `post_type = attachment`.

---

## Multi-driver notes

- Migrations emit **driver-specific DDL** (`mysql` / `pgsql` / sqlite default).  
- PostgreSQL uses quoted identifiers where case matters (e.g. `"ID"`).  
- SQLite uses simplified types; indexes are created as separate statements with prefix-safe names.  
- Application code should use `AP_DB` helpers, not raw MySQL-only SQL.

## Extending the schema

Core owns versioned migrations under `ap-includes/schema/migrations/`. Third-party plugins should prefer:

1. **Options** and **postmeta** / **usermeta**  
2. Custom tables created on activation via plugin-owned DDL (document carefully; use the site prefix)  
3. Contributing a core migration only when the feature is productized in AgoraPress itself  

Always:

- Respect `$table_prefix`  
- Use prepared statements  
- Provide upgrade/cleanup on deactivation if you create tables  

## Related domain APIs

| Tables | Primary classes |
|--------|-----------------|
| options | `AP_Options` |
| users / usermeta | `AP_User`, `AP_Session`, `AP_Roles` |
| posts / postmeta | `AP_Post`, `AP_Media`, `AP_Query` |
| terms* | `AP_Taxonomy` |
| comments* | `AP_Comment` |
| forums* | `AP_Forum`, `AP_Forum_Front`, `AP_Forum_Moderation`, `AP_Forum_Permissions`, `AP_Group`, `AP_Private_Message`, `AP_Online`, `AP_Forum_Read`, `AP_Forum_Attachment` |
| analytics_hits / analytics_daily | `AP_Analytics` (config, recorder, prune, aggregation); ACP `AP_Admin_Analytics` |

See also [hooks.md](hooks.md) for lifecycle actions fired around inserts/updates that plugins can listen to instead of writing SQL.
