# Forums

This is the **operator and integrator guide** for AgoraPress’s first-class
forum module at **`0.3.8-beta`** (schema `AP_DB_VERSION` **12**). It describes
the hierarchy, topic types, two-pane topic view, likes, moderation, groups and
per-forum ACL (including the **This group only** preset), listing hygiene,
attachments, private messages, search, flood guards, online/unread tracking,
and board stats **as built**.

The compact landing-page bullets are in
[`../README.md`](../README.md). Admin screen map:
[admin.md](admin.md#forums). Tables: [schema.md](schema.md). Roles and the
forum ACL relationship: [roles.md](roles.md). Pretty URLs:
[rewrites.md](rewrites.md).

**Source (as built):** `ap-includes/class-ap-forum.php` (`AP_Forum`),
`class-ap-forum-front.php` (`AP_Forum_Front`),
`class-ap-forum-permissions.php` (`AP_Forum_Permissions`),
`class-ap-forum-moderation.php` (`AP_Forum_Moderation`),
`class-ap-forum-like.php` (`AP_Forum_Like`),
`class-ap-forum-stats.php` (`AP_Forum_Stats`),
`class-ap-forum-guard.php` (`AP_Forum_Guard`),
`class-ap-forum-read.php` (`AP_Forum_Read`),
`class-ap-forum-attachment.php` (`AP_Forum_Attachment`),
`class-ap-group.php` (`AP_Group`),
`class-ap-feed.php` (`AP_Feed`),
`class-ap-sitemap.php` (`AP_Sitemap`),
`class-ap-rest.php` (`AP_Rest`),
`class-ap-private-message.php` (`AP_Private_Message`),
`class-ap-online.php` (`AP_Online`),
`ap-admin/forums.php`, `forum-edit.php`, `forum-topics.php`,
`forum-moderation.php`, `forum-groups.php`, `options-forums.php`,
default Agora templates `forum.php` / `forum-view.php` / `topic.php` /
`forum-search.php`.

There is **no** `php ap-cli forum` verb, **no** Gutenberg blocks for topics,
and **no** official phpBB skin in core. Missing from this guide and from core
means **not in core**.

---

## How the module works

Forums are an **independent module**, not a post type. Option
`ap_module_forum` (Settings → Modules) turns the whole surface on or off.
Fresh install seeds it **on**. At least one of Static Pages, Blog, and Forum
must stay on.

Dedicated tables (migrations 5–9, 11–12) hold forums, topics, posts, groups,
ACL, messages, likes, unread marks, and presence. Accounts, capabilities,
options, and media stay in the CMS tables. Forum posts are **not** blog
`comments`.

| Layer | What it does |
|-------|----------------|
| Front | `AP_Forum_Front` maps rewrite query vars to theme templates and handles nonce-protected POST actions. |
| Model | `AP_Forum` is hierarchy / topics / posts / search / URLs. |
| ACL | `AP_Forum_Permissions` + `AP_Group` (per-forum, by user level). Never applied to blog posts or pages. |
| ACP | Forums / Topics / Moderation / Groups (`manage_forums` or `moderate_forums`) plus Settings → Forums (`manage_options`). |
| REST | Read-only `GET /ap-json/ap/v1/forums` and `/topics` — [rest.md](rest.md). |

Installer sample content (when sample content is enabled) seeds a **Community**
category, a **General Discussion** forum, and a **Welcome to the forums**
topic.

---

## Front URLs and templates

Pretty permalinks (Settings → Permalinks, then
`php ap-cli rewrite flush` — [rewrites.md](rewrites.md)):

| Pretty | Plain query | View |
|--------|-------------|------|
| `/forums/` | `?ap_forum_view=index` | Board index |
| `/forums/{slug}/` | `?ap_forum_view=forum&forum_slug=` (resolved to `forum_id`) | Single forum (topic list) |
| `/topic/{slug}/` | `?ap_forum_view=topic&topic_slug=` (resolved to `topic_id`) | Topic + replies |
| `/forums/search/` and `/forums/search/{term}/` | `?ap_forum_view=search&forum_s=` | Forum search |
| `/forums/feed/` · `/forums/feed/atom/` | `?ap_forum_view=index&feed=rss2` (or `atom`) | Board-index feed |
| `/forums/{slug}/feed/` · `/forums/{slug}/feed/atom/` | `?ap_forum_view=forum&forum_id=&feed=` | Single-forum feed |
| `/topic/{slug}/feed/` · `/topic/{slug}/feed/atom/` | `?ap_forum_view=topic&topic_id=&feed=` | Topic feed |

Paged variants exist (`/forums/page/2/`, `/forums/{slug}/page/2/`,
`/topic/{slug}/page/2/`, `/forums/search/{term}/page/2/`, …). Query vars
`forum_slug`, `topic_slug`, `forum_s`, `paged` are registered with the
rewrite map. Static root `favicon.ico` is left alone. After a POST, Agora
redirects with `?ap_forum_notice={code}` (see [Front notices](#front-notices)).

Default **Agora** prepends forum templates via
`agora_forum_template_hierarchy` on `ap_template_hierarchy`:

| View | Files tried first |
|------|-------------------|
| index | `forum.php`, `forums.php` |
| forum | `forum-view.php`, `single-forum.php` |
| topic | `topic.php`, `forum-topic.php`, `single-topic.php` |
| search | `forum-search.php`, `search-forum.php`, `forum.php` |

Custom themes override the same filenames in the child/parent stack
([themes.md](themes.md)). State-changing forms POST `ap_forum_action` to
`AP_Forum_Front::handlePost()`:

`ap_forum_new_topic`, `ap_forum_reply`, `ap_forum_edit_post`,
`ap_forum_delete_post`, `ap_forum_like_post`, `ap_forum_lock_topic`,
`ap_forum_unlock_topic`, `ap_forum_set_topic_type`.

Sitemaps include the board index, forums, and topics when the module is on
(`AP_Sitemap` providers `forums` and `topics`). `AP_Forum::getForums()`
default excludes `forum_status=hidden` (open + closed). Listing also
follows `view_forum`: a **This group only** room, and an empty parent
category that only held that room, stay off the public index, search,
feeds, sitemap, REST, pretty URLs, and nav menus for anyone who cannot
view it — [Listing hygiene](#listing-hygiene-view_forum).

### Front notices

Query var `ap_forum_notice` (and same-request flash via
`AP_Forum_Front::getNotice()`). Codes as built:

| Code | Message |
|------|---------|
| `topic_created` | Topic created. |
| `topic_pending` | Your topic was submitted and is awaiting moderation. |
| `reply_posted` | Reply posted. |
| `reply_pending` | Your reply was submitted and is awaiting moderation. |
| `flood` | You are posting too quickly. Please wait a moment and try again. |
| `spam` | Your post was rejected by the spam filter. |
| `login_required` | You must be logged in to post. |
| `permission` | You do not have permission to do that. |
| `locked` | This topic is locked. |
| `invalid` | Please check your input and try again. |
| `nonce` | Security check failed. Please try again. |
| `post_edited` | Post updated. |
| `post_deleted` / `topic_deleted` | Post deleted. / Topic deleted. |
| `post_liked` / `post_unliked` | Thanks for the like. / Like removed. |
| `topic_locked` / `topic_unlocked` | Topic locked. / Topic unlocked. |
| `topic_type_updated` | Topic type updated. |

Like failures that stay on the page: `login_required` → “Log in to like posts.”;
`forbidden` → “You do not have permission to like this post.”

Empty-state copy (Agora / `ap_forum_empty_state_html`): `board_empty`,
`category_empty`, `forum_empty`, `forum_empty_closed`, `forum_closed`,
`forum_disabled`, `forum_not_found`, `topic_empty`, `topic_locked`.

---

## Hierarchy

`AP_Forum` types and statuses:

| `forum_type` | Role |
|--------------|------|
| `category` | Container on the board index. Children are forums (or links). |
| `forum` | Holds topics. |
| `link` | Listed on the index like a forum (icon key `link`). There is **no** destination-URL column on `{prefix}forums` and **no** redirect field on Forums → Edit. |

| `forum_status` | Role |
|----------------|------|
| `open` | New topics allowed when ACL permits. |
| `closed` | No new topics. Themes show a Locked badge (`AP_Forum::isForumClosed()`). Existing topics remain readable. |
| `hidden` | Still ACL-gated (`view_forum`); not treated as a public listing. |

Tree fields: `parent_id`, `forum_order`, `forum_name` / `forum_slug` /
`forum_desc`. Denormalized `topic_count` / `post_count` and last-post
pointers. **Posts** on a forum row = approved opening posts **plus** replies
(not replies-only). Same definition as the board footer — see
[Board stats](#board-stats).

ACP: **Forums** (`forums.php`, cap `manage_forums`) is the tree + bulk
delete. **Create / Edit** (`forum-edit.php`) sets type, status, parent,
order, and `forum_access_level` (the [access-level](#groups-and-per-forum-acl)
preset), including **This group only** plus a named-group picker
(`forum_access_groups[]`). Deleting a forum that still has children or
topics fails unless `AP_Forum::deleteForum($id, true)` force-deletes
(recursive children + permanent topic/post removal). There is **no**
destination-URL field for `forum_type=link`. ACP still lists every board
(`include_hidden`), including group-only rooms guests cannot see.

Menus can link to forums (Appearance → Menus). That is a nav item, not a
second forum tree. Public render still requires `view_forum`; empty parent
categories are omitted.

---

## Topic types

Column `topics.topic_type` (migration **12**). Canonical values:

| Type | Meaning | List order |
|------|---------|------------|
| `standard` | Ordinary thread | After elevated types |
| `sticky` | Stays at the top of **this** forum | After announcement / rules |
| `announcement` | Elevated announce | First |
| `rules` | Sticky-style info / rules thread | After announcement |

`AP_Forum::getTopics()` orders `announcement`, then `rules`, then `sticky`,
then `standard`, then `topic_last_post_time` DESC. Locked status wins the
**icon** (`topicIconType()` returns `locked` even when the type is sticky).

Legacy aliases normalize on write/read: `normal` → `standard`,
`announce` / `global` → `announcement`, `info` → `rules`. PHP constants
`TOPIC_TYPE_NORMAL`, `TOPIC_TYPE_ANNOUNCE`, `TOPIC_TYPE_GLOBAL` are
deprecated aliases.

Creating or changing type is ACL-gated (`sticky_topics` for sticky,
`announce_topics` for announcement and rules). Agora’s new-topic form and
topic toolbar expose a type `<select>` only for types the current user may
set (`ap_forum_topic_type_select_html`).

Topic **status** is separate: `open` | `locked` | `moved` | `deleted`.
Soft-deleted topics are hidden from the public view.

---

## Two-pane topic view

Default Agora `topic.php` renders each post as
`.ap-forum-post--two-pane`:

| Pane | Contents |
|------|----------|
| Author (`aside.ap-forum-post__author`) | Avatar, display name, role label, posts / likes given / likes received, joined date, location when set |
| Main (`div.ap-forum-post__main`) | Subject, timestamp, permalink `#post-{id}`, Quote / Like / Edit / Delete when ACL allows, formatted body, optional signature |

Author counters come from `AP_Forum_Stats::getUsersStats()` (usermeta,
one query for all posters on the page). Signatures use usermeta
`signature` when option `forum_signatures_enabled` is on (default on);
users edit the signature on their profile (Users → Edit / Profile).

Logged-in readers get a **First unread post** jump when
`first_unread_post_id` is set (`AP_Forum_Read::markTopicReadOnView()` runs
before the watermark advances). `?quote={postId}` prefills the reply box
with BBCode citation (`AP_Forum::getQuoteMarkupForPost()`). Topic views
increment on a successful topic view.

The visual editor on new-topic / reply is the same classic WYSIWYG as the
rest of core (`AP_Editor::modeForContext('forum')`) — [editor.md](editor.md).
Content is stored raw and formatted with `AP_Content_Format` (`context` =
`forum`).

---

## Likes

Registered users may thumbs-up an **approved** forum post they can view.
One like per user per post (`{prefix}forum_post_likes`, unique
`(post_id, user_id)`, migration 11). Denormalized `forum_posts.like_count`
plus usermeta `forum_likes_given` / `forum_likes_received`.

| Rule | As built |
|------|----------|
| Guests | `login_required` — Agora shows a log-in prompt, not a like button |
| ACL | `view_forum` on the post’s forum; otherwise `forbidden` |
| Toggle | POST `ap_forum_like_post` (nonce). Same action unlike if already liked |
| API | `AP_Forum_Like::toggle()` / `like()` / `unlike()` |
| Hooks | `ap_forum_post_liked`, `ap_forum_post_unliked` |

There is **no** dislike, **no** like-on-topics (posts only), and **no** REST
like endpoint.

---

## Moderation

### Front (Agora)

When `moderate_forum` (or the matching own-post ACL) allows:

- Edit own post (`edit_own`) or any post when moderating
- Soft-delete own post (`delete_own`) or any post when moderating
- Lock / unlock topic (`lock_topics`)
- Change topic type (`sticky_topics` / `announce_topics`)

Nonces are per action (`ap_forum_lock_topic_{id}`, …). Locked topics reject
replies (`ap_forum_notice=locked`).

### ACP

| Screen | Cap | What it does |
|--------|-----|----------------|
| Topics (`forum-topics.php`) | `moderate_forums` | Row/bulk allowlist: `lock`, `unlock`, `sticky`, `unsticky`, `approve`, `unapprove`, `trash`, `soft_delete`, `restore`, `delete`. Filter by `topic_status` and `forum_id`. **No** move / merge / split controls on this screen. |
| Moderation (`forum-moderation.php`) | `moderate_forums` | Pending topics/posts and **reports**. Row actions: `approve_topic`, `trash_topic`, `reject_topic`, `approve_post`, `trash_post`, `reject_post`, `resolve_report`, `dismiss_report`, `reopen_report`. |

Module-off on these ACP screens is HTTP **403**:
“The Forum module is disabled. Enable it under Settings → Modules.”

### API (`AP_Forum_Moderation`)

Used by ACP and tests; UI layers must pass the acting user id (passing `0`
skips ACL — installers / CLI / tests only).

- Lock / unlock, set topic type
- Soft-delete / restore / force-delete topics and posts
- **Move, merge, split topics** — API only (`moveTopic` / `mergeTopics` /
  `splitTopic`). Default Agora and the Topics screen do **not** expose these.
- Reports (`reports` table): types `post` / `topic` / `user` / `message`;
  statuses `open` / `closed` / `dismissed`
- Warnings (`warnings`): `active` / `expired` / `revoked`
- Bans (`bans`): `user` / `ip` / `email`; statuses `active` / `expired` /
  `lifted`; banned accounts get `user_status` = 1

Default Agora **does not** ship a “Report this post” form. Reports are
handled in the ACP queue. There is **no** ranks admin UI (the `ranks` table
exists; phpBB ranks are **not** imported).

New posts may start **pending** when Settings → Forums has “New posts
require moderator approval” (`forum_posts_require_approval`). Users with
`manage_forums` or `moderate_forums` skip the queue. Filter
`ap_pre_forum_post_status` can override.

---

## Groups and per-forum ACL

Forum ACL is **group × forum × permission**, stored in
`{prefix}forum_permissions` (migration 7). `forum_id = 0` is global
defaults. `perm_setting` 1 = allow, 0 = deny.

This ladder applies **only to forums**. Blog posts and pages use publish
status.

### System groups (`AP_Group`)

Seeded on install (virtual membership where noted):

| Slug | Who |
|------|-----|
| `guests` | Not logged in (`user_id` 0) |
| `registered` | Any logged-in user (virtual) |
| `global_moderators` | Users with `moderate_forums` (Editor and Administrator by default) |
| `administrators` | Users with `manage_forums` / administrator role |

Named groups (ACP **Groups**, `forum-groups.php`, cap `manage_forums`) have
types `open` | `closed` | `hidden` | `system` and member roles
`member` | `moderator` | `leader`. System groups cannot be deleted like
ordinary groups. Hidden groups are not listed publicly and have
**no public Join control**. Closed and system groups also fail
`AP_Group::joinPublic()` / `ap_join_group_public()`; only `open` named
groups may self-join via that API. Default Agora does **not** ship a Join
button. Forums → Groups remains the roster (Add Member).

### Permission keys (`AP_Forum_Permissions`)

`view_forum`, `read_forum`, `post_topics`, `post_replies`, `edit_own`,
`delete_own`, `attach_files`, `moderate_forum`, `sticky_topics`,
`announce_topics`, `lock_topics`, `move_topics`.

### Access-level presets (Forums → Edit)

Field `forum_access_level` on `forum-edit.php` (posted; **not** a column on
`{prefix}forums`). ACP applies a preset to `{prefix}forum_permissions`, or
a custom Guest / Registered / Moderator / Administrator matrix. Labels as
built (`AP_Forum_Permissions`):

| Preset (posted `forum_access_level`) | Effect |
|--------|--------|
| Public (`public`) | Guests view/read; registered post/edit-own/attach; staff moderate |
| Members only (`members`) | Hidden from guests; registered post; staff moderate |
| Read only (members) (`members_readonly`) | Guests and members view/read only; only staff post |
| Moderators only (`moderators`) | Hidden from guests and ordinary members |
| Administrators only (`administrators`) | Administrators only |
| This group only (`group_only`) | Named (non-system) group(s) plus administrators. See below. |
| Custom (`custom`) | Per-level checkboxes for every permission |

### This group only (`group_only`)

Picker: one or more named groups (`forum_access_groups[]`). System groups
are not listed. Open, closed, and hidden named groups are. Create groups
under Forums → Groups, then return to Forums → Edit.

| Surface | As built |
|---------|----------|
| Option `forum_group_only` (`AP_Forum_Permissions::OPTION_GROUP_ONLY`) | Map of `forum_id` → named group ids. Schema stays **12**. |
| `{prefix}forum_permissions` | Guest **deny-all**. Chosen groups get registered-level **allow** (`view_forum`, `read_forum`, `post_topics`, `post_replies`, `edit_own`, `delete_own`, `attach_files`). Administrators **allow-all**. |
| `AP_Forum_Permissions::applyGroupOnlyAccess()` | Writes those rows. Switching away from the preset clears those named-group rows and the `forum_group_only` entry. |

Apply rules:

- Deny guests.
- Do **not** stamp an explicit deny on virtual `registered` or
  `global_moderators`. Every logged-in member is also registered; a
  member-moderator is also a global moderator. Explicit deny-wins would
  lock the chosen audience out.
- Allow the chosen named group(s) + administrators.
- An empty picker still marks the forum as group-only so Edit
  round-trips the preset. Then only `manage_forums` can enter.

**Staff caveat:** `manage_forums` (Administrator) **always** allows and
still sees the board in ACP. Site-wide `moderate_forums` (Editor+) does
**not** walk into a `group_only` room unless the user is also in a chosen
named group. A group member who also has `moderate_forums` gets the
moderation-family permissions once inside.

### Resolution

1. `manage_forums` (Administrator) **always** allows.
2. On a `group_only` forum, anyone not in a chosen named group (and not
   `manage_forums`) is denied **before** the `moderate_forums` shortcut.
3. `moderate_forums` (Editor+) grants the moderation-family permissions
   (`moderate_forum`, sticky/announce/lock/move, edit_own, delete_own) on
   forums the user can already `view_forum`.
4. Collect effective groups (explicit membership + virtual system groups).
5. Forum-specific rows override global (`forum_id = 0`).
6. Explicit group **deny** wins; else explicit **allow** (so a VIP group can
   open a forum that registered users cannot see); else any remaining allow.

Closed forums do not by themselves block view/read. Hidden forums still
need `view_forum`.

### Listing hygiene (`view_forum`)

Anyone without `view_forum` does not see that forum, or an empty parent
category that only contained unlistable children. Helpers:
`AP_Forum::getListableForums()`, `AP_Forum::isListableToUser()`,
`ap_get_listable_forums()`, `ap_forum_is_listable_to_user()`.

A direct URL the viewer cannot list is a generic 404: “You cannot view
this.” (`AP_Forum_Front::CANNOT_VIEW_MESSAGE`). The response does **not**
include the board name or slug. Query flags: `ap_forum_cannot_view`,
`ap_forum_cannot_view_message`, `is_404`. `forum_name`, `forum_slug`,
`forum_desc`, `forum_s`, and topic title/slug are cleared so themes and
SEO cannot teaser the room.

| Surface | As built |
|---------|----------|
| Board index | `AP_Forum::getIndexData()`, Agora `forum.php`. Unlistable children omitted; a parent category with no remaining visible forum/link is dropped. |
| Pretty URLs | `/forums/{slug}/`, `/topic/{slug}/` — generic 404 above. |
| Search | `AP_Forum::search()` with `check_permissions` (Agora `forum-search.php`). Hits only in forums the viewer may list. A search scoped to an unlistable board is the same generic 404, not an empty result that would confirm the slug. |
| Feeds | `/forums/feed/`, `/forums/{slug}/feed/`, `/topic/{slug}/feed/` (`AP_Feed`). Index items omit unlistable boards. A direct forum/topic feed the viewer cannot list is **HTML** 404 “You cannot view this.” — never RSS/Atom whose `<title>` or self URL would name the room. |
| Sitemap | `AP_Sitemap` providers `forums` and `topics` use listable rows for the current viewer (crawlers are guests). Direct unlistable forum/topic sitemap URLs are the same HTML 404, not XML. `forum_type=link` is skipped in the forums urlset. |
| REST | See [REST and CLI](#rest-and-cli). List uses `getListableForums`. Single get and a topics list scoped to a board: JSON 404 `rest_cannot_view` / “You cannot view this.” Missing and unlistable share that code so clients cannot probe ids or names. |
| Nav menus | Forum items require `view_forum`; empty parent categories are omitted (`AP_Nav_Menu`). |

ACP Forums / Edit still lists every board (`include_hidden`).
Authenticated `manage_forums` can open group-only rooms on the front and
via REST.

Site-wide checkboxes on Settings → Forums
(`forum_allow_guest_viewing` default **on**,
`forum_allow_guest_posting` default **off**) are **stored**
(`AP_Options::updateForumSettings()`). `AP_Forum_Permissions::userCan()`
does **not** read them. Front create/reply and “can this visitor see this
forum?” use the per-forum matrix above. Toggling those two checkboxes
alone does not hide the board from guests or allow guest posting.

Core roles that matter here: Administrator has `manage_forums` +
`moderate_forums`; Editor has `moderate_forums`. Subscriber / Author /
Contributor have `read` and post in **public** forums via the registered
group, not via extra forum caps. Full role table: [roles.md](roles.md).

---

## Attachments

`AP_Forum_Attachment` links media-library files (`AP_Media`, under
`ap-content/uploads/`) to forum posts/topics in `{prefix}forum_attachments`
(migration 6). Quotas and download counts live on those rows.

Settings → Forums (and class defaults):

| Option | Default |
|--------|---------|
| `forum_attachments_enabled` | on |
| `forum_attachment_max_size` | 2097152 (2 MiB) |
| `forum_attachment_allowed_types` | `jpg,jpeg,png,gif,webp,pdf,txt,zip` |

Class defaults **not** on that settings form: max **5** attachments per
post (`forum_attachment_max_per_post`,
`AP_Forum_Attachment::DEFAULT_MAX_PER_POST`), per-user quota **10 MiB**
(`forum_attachment_user_quota` = `10485760`, `0` = unlimited). Upload also
needs `attach_files` on the forum and the Media allow-list / PHP size
limits.

Default Agora **new-topic / reply forms do not include a file input**.
Attachments are an API (`ap_assign_forum_attachments`, …) plus the media
library. Themes or plugins can add an upload control; core does not ship
one on the composer.

---

## Private messages

Table `{prefix}messages` (migration 5). Option
`forum_private_messaging_enabled` (default **on**). Unavailable when the
Forum module is off (`AP_Private_Message::isAvailable()`).

| Capability | As built |
|------------|----------|
| Send | Logged in, not banned, PMs on, `read` (or `manage_forums`) |
| Receive | Logged-in user id; banned users can still **receive** staff mail but cannot send |
| View | Participant, or staff with `manage_forums` |
| Folders | `inbox` / `outbox` / `unread` |
| Delete | Per-side soft-delete (`sender_deleted` / `recipient_deleted`); hard purge when both sides have deleted |
| Threads | Replies hang under `parent_id` (root message) |

Procedural helpers: `ap_send_private_message()`, `ap_get_pm_inbox()`,
`ap_format_private_message()`, … Privacy export/erase includes PMs
([security.md](security.md)).

Default Agora **does not ship an inbox / compose template**. There is no
`/messages/` rewrite and no ACP “Private messages” screen. Plugins or a
custom theme must call the API. That is **not in core** as a public UI.

---

## Search

Option `forum_search_enabled` (default **on**). When off, `AP_Forum::search()`
returns empty unless `force` is set (not used by the public front).

| Item | As built |
|------|----------|
| URL | `/forums/search/` or `?ap_forum_view=search` |
| Query var | `forum_s` (pretty path segment is URL-encoded) |
| Scope | Topic titles and post bodies (`type` `topics` / `posts` / `all`) |
| ACL | Only forums the user may `view_forum`; empty allowed set → no hits. Scoped search of an unlistable board is the generic 404 in [Listing hygiene](#listing-hygiene-view_forum), not an empty hit list. |
| Page size | 20, filter `ap_forum_search_per_page` (clamped 1–100) |
| Term | Trimmed, max 200 characters |

Agora shows a search form on the board index when search is enabled.

---

## Flood and anti-spam (`AP_Forum_Guard`)

| Option | Default | Effect |
|--------|---------|--------|
| `forum_flood_interval` | **30** seconds (0 = off, cap 3600) | Minimum gap between posts for a user or IP |
| `forum_posts_require_approval` | off | New posts pending unless the user is exempt |
| `forum_spam_max_links` | **5** (0 = off) | Over this many `http(s)` links → pending |
| `forum_spam_blacklist` | empty | Comma/newline keywords → reject as spam |

Exempt from flood **and** the approval queue: `manage_forums` or
`moderate_forums`. Guests are not exempt.

Front notices: `flood` (“You are posting too quickly…”), `spam`
(“Your post was rejected by the spam filter.”). Plugins register extra
checkers with `AP_Forum_Guard::registerSpamChecker()` or filter
`ap_pre_forum_post_status`. There is **no** built-in CAPTCHA on forum
forms (registration CAPTCHA is a different setting — [admin.md](admin.md)).

---

## Who’s online and unread

### Presence (`AP_Online`)

Option `forum_online_enabled` (default **on**). Window
`forum_online_window` default **900** seconds (15 minutes; clamped 60–86400).
That window is a class/option default — it is **not** a field on
Settings → Forums.

Each visitor has one `{prefix}online` row keyed by `session_key`. Guests
use cookie `ap_forum_session`. `AP_Forum_Front::applyToQuery()` calls
`AP_Online::trackCurrent()` on forum views when the module is on. Stale
rows are pruned opportunistically.

Helpers: `ap_online_enabled()`, `ap_is_user_online()`,
`AP_Online::getOnlineUsers()`. Default Agora **does not render a “Who’s
online” list** on the board index (the API is there for themes/plugins).

### Unread (`AP_Forum_Read`)

Option `forum_unread_tracking_enabled` (default **on**). Guests never
persist marks (always “read” for the API; Agora paints guest rows
**neutral**, not fake unread).

Effective mark time for a topic:

`max(topic_track.mark_time, forum_track.mark_time, usermeta forum_last_mark, epoch)`

A topic is unread when `topic_last_post_time` is strictly after that.
Viewing a topic marks posts **on the current page** only (multi-page
topics do not claim later pages as read). Soft-deleted topics are not
marked. Marks only move forward.

Board-index rows expose `is_unread` for logged-in users (bulk
`AP_Forum_Read::annotateForums()`, not per-forum queries).

API also has `AP_Forum_Read::markForumRead()` /
`markAllRead()` (helpers `ap_mark_forum_read()`,
`ap_mark_all_forums_read()`). `markAllRead()` writes usermeta
`forum_last_mark`. Default Agora **does not** ship a “Mark all as read”
or “Mark forum read” control.

---

## Board stats

Agora’s forum chrome footer (not the site footer) prints
**Total Topics · Total Posts · Total Members** via
`ap_forum_board_stats_footer_html()` / `AP_Forum_Stats::getBoardStats()`.
Live SQL `COUNT`s — no object-cache lag, no telemetry.

| Stat | Definition |
|------|------------|
| Topics | Approved topics with `topic_status` ≠ `deleted` |
| Posts | Approved `forum_posts` rows (opening posts **+** replies) whose parent topic is approved and not soft-deleted |
| Members | Rows in `users` (guests are not counted) |

Topic-list “Posts” is `reply_count + 1`. `topics.reply_count` stays
replies-only. Per-user usermeta `forum_posts` is “approved posts this user
authored”, not the board total.

---

## Settings → Forums

**URL:** `/ap-admin/options-forums.php` · **Cap:** `manage_options` ·
**Module:** Forum must be on (otherwise HTTP 403:
“The Forum module is disabled. Enable it under Settings → Modules.”).

Fieldsets as built: Display (topics/posts per page options), Guests,
Features (PMs, search, who’s online, unread, signatures), Attachments,
Moderation & anti-spam. Per-forum visibility is **not** on this screen —
it is Forums → Edit (`forum_access_level`).

Agora’s front (`AP_Forum_Front::topicsForQuery()` /
`postsForQuery()`, unread mark-on-view) pages at **20** regardless of the
stored Display options. Changing **Topics per page** /
**Posts per page** on this screen does **not** change default Agora
listing. Plugins may hook `ap_forum_topics_per_page` and
`ap_forum_posts_per_page` (clamped 1–100). The options are still stored
(`forum_topics_per_page` default 20, `forum_posts_per_page` default 15).

---

## REST and CLI

When `rest_api_enabled` is on ([rest.md](rest.md)). Routes are always
registered; handlers 404 when the module is off.

| Method | Route | As built |
|--------|-------|----------|
| `GET` | `/ap-json/ap/v1/forums` | `AP_Forum::getListableForums()` for the REST user (guest when unauthenticated). Omits `forum_status=hidden` (same default as `getForums()`), forums the viewer cannot `view_forum`, and empty parent categories. Closed boards still appear when listable. Group-only rooms are omitted for guests and non-members. **No** pagination. |
| `GET` | `/ap-json/ap/v1/forums/{id}` | One forum by id when `isListableToUser`. Otherwise JSON **404** `rest_cannot_view` (“You cannot view this.”) — missing and unlistable share that code. |
| `GET` | `/ap-json/ap/v1/topics` | `forum` / `forum_id` lists that forum’s **open** topics (`status=open`) after the same listable check; unlistable scope → `rest_cannot_view`. Without a forum id, approved topics via `AP_Forum::queryTopics` with `check_permissions`. `page` default 1, `per_page` default **20** (max 100). |
| `GET` | `/ap-json/ap/v1/topics/{id}` | One topic by id when its forum is listable. Missing topic or unlistable forum → `rest_cannot_view`. |

When the Forum module is off, those handlers return JSON **404**
`rest_module_disabled` with message `Forum module is disabled.`

`permission_callback` is always true (the route is registered publicly).
Handlers apply listing hygiene themselves. Forum payload:
`id`, `name`, `slug`, `description`, `parent`, `type`, `status`,
`topic_count`, `post_count`, `link`. Topic payload: `id`, `forum`,
`title`, `slug`, `status`, `type`, `author`, `post_count`, `views`,
`link`, `date`. There is **no** REST create/reply/like/moderate/PM.
Core does **not** emit `rest_forum_invalid_id` or `rest_topic_invalid_id`.

There is **no** `php ap-cli forum` (or topic/post-in-forum) command group.
Toggle the module with `php ap-cli option get|set ap_module_forum`.

---

## Module off

Option `ap_module_forum` = `0`:

| Surface | Behavior |
|---------|----------|
| ACP sidebar | Forums, Topics, Moderation, Groups, Settings → Forums **hidden** |
| ACP entry scripts | HTTP **403** `AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.')` |
| Front `/forums/` (and other forum views) | Query flag `ap_forum_disabled`. Agora empty state “The forum module is currently disabled.” — **not** a hard 404 on the index |
| Unknown forum/topic slug | `ap_forum_not_found` + `is_404` (same as when the module is on) |
| POST create/reply/like/… | Notice “The forum module is disabled.” |
| REST forum/topic handlers | **404** `rest_module_disabled` (`Forum module is disabled.`; route still registered) |
| PMs, unread, online | `isAvailable()` is false |
| Sitemap `forums` provider | Omitted |

Turning the module off does **not** drop tables or delete topics. Turn it
back on under Settings → Modules. Symptom checklist:
[troubleshooting.md](troubleshooting.md#forum--blog--pages-missing).

---

## Schema (pointer)

Full column lists: [schema.md](schema.md#forum-tables-dedicated).

| Migration | Tables / change |
|-----------|-----------------|
| 5 | `forums`, `topics`, `forum_posts`, `groups`, `group_members`, `messages`, `ranks`, `reports`, `online` |
| 6 | `forum_attachments` |
| 7 | `forum_permissions` |
| 8 | `warnings`, `bans` |
| 9 | `topic_track`, `forum_track` |
| 11 | `forum_post_likes`; `forum_posts.like_count` |
| 12 | Topic type enum `standard` \| `sticky` \| `announcement` \| `rules` + backfill |

Prefix default `ap_`. Helpers: `ap_forum_base_tables()`.

---

## Import

Tools → Import (`import.php`, cap `import`) can load phpBB 3.x users, forum
hierarchy, topics, and posts (JSON export or live DB). BBCode UIDs are
stripped. **Attachments, private messages, and ranks are not imported.**
WXR import is for posts/pages/comments, not the forum tables.
See [admin.md](admin.md).

---

## Not in core

Do not tell operators these exist in AgoraPress core:

- A `php ap-cli forum` (or PM / like / moderate) verb
- REST write endpoints for topics, replies, likes, or PMs
- REST codes `rest_forum_invalid_id` / `rest_topic_invalid_id` (missing and
  unlistable both return `rest_cannot_view`)
- A REST, feed, or sitemap body that names a board the viewer cannot
  `view_forum`
- A public group Join button on default Agora (`AP_Group::joinPublic()`
  exists for `open` named groups only; hidden / closed / system fail;
  Forums → Groups is the roster)
- A shipped Agora inbox / compose page or `/messages/` rewrite
- A “Who’s online” block on the default board index (tracking API only)
- A “Mark all as read” / “Mark forum read” button on default Agora
- A file picker on the default new-topic / reply composer
- A front-end “Report post” button (ACP reports queue exists)
- ACP move / merge / split (those exist on `AP_Forum_Moderation` only)
- A Settings → Forums guest checkbox that bypasses per-forum ACL (the
  two guest options are stored; `userCan()` does not read them)
- Display options on Settings → Forums driving Agora’s 20-item paging
  (filters `ap_forum_topics_per_page` / `ap_forum_posts_per_page` do)
- Ranks UI, polls, bookmarks, custom BBCode packs, or phpBB style
  download
- Forum ACL applied to blog posts or static pages
- Gutenberg / blocks for topics
- A second permission system besides [roles.md](roles.md) + this ACL

---

## Related docs

| Need | Doc |
|------|-----|
| ACP screen map | [admin.md](admin.md#forums) |
| Roles, `manage_forums` / `moderate_forums` | [roles.md](roles.md) |
| `/forums/` rewrite rules | [rewrites.md](rewrites.md) |
| REST `ap/v1` forums/topics | [rest.md](rest.md) |
| Tables | [schema.md](schema.md) |
| Agora templates / two-pane CSS | [themes.md](themes.md) |
| Selected forum hooks | [hooks.md](hooks.md) |
| Flood vs login rate limits | [security.md](security.md) |
| Module-off 404s | [troubleshooting.md](troubleshooting.md) |
| Visual editor on reply | [editor.md](editor.md) |
| phpBB / WXR import | [admin.md](admin.md) |
| Enable the Forum module after install | [install.md](install.md) |
| There is **no** `php ap-cli forum` verb | [cli.md](cli.md) |
