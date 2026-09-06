# Roles and capabilities

This is the **operator and integrator guide** for AgoraPress roles,
capabilities, blog-comment ownership, and the relationship to forum ACL at
**`0.3.6-beta`** (schema `AP_DB_VERSION` **12**). It describes the system
**as built**. Do not invent extra core roles, caps, filters, CLI verbs, or
admin screens.

The compact landing-page bullets are in
[`../README.md`](../README.md). ACP screen map: [admin.md](admin.md). Forum
groups and per-forum ACL: [forums.md](forums.md). REST write caps:
[rest.md](rest.md). Hardening: [security.md](security.md). CLI
`user create --role=`: [cli.md](cli.md).

**Source (as built):** `ap-includes/class-ap-roles.php` (`AP_Roles`),
helpers in `ap-includes/functions.php` (`ap_user_can`, `ap_current_user_can`,
`ap_map_meta_cap`, `ap_add_role`, `ap_add_cap`, …),
`ap-includes/class-ap-forum-permissions.php` (`AP_Forum_Permissions`),
`ap-includes/class-ap-group.php` (`AP_Group`), comment helpers
`ap_user_can_edit_comment` / `ap_user_can_delete_comment`, ACP Users
(`users.php`, `user-new.php`, `user-edit.php`, `profile.php`).

There is **no** dedicated Roles admin screen, **no** `php ap-cli role`
verb, **no** `user_has_cap` filter, and **no** built-in CMS role named
Moderator or Super Admin. Missing from this guide and from core means
**not in core**.

---

## Two permission systems

AgoraPress has **one account table** (`users` + `usermeta`) and **two**
permission layers. They share a few CMS capabilities. They are not the
same map.

| Layer | What it gates | Stored in |
|-------|----------------|-----------|
| CMS roles (`AP_Roles`) | ACP, posts, pages, media, comments, plugins, themes, settings, REST writes | Option `ap_user_roles`; usermeta `ap_capabilities` |
| Forum ACL (`AP_Forum_Permissions`) | View / read / post / attach / moderate **a forum** | `{prefix}forum_permissions` (group × forum × permission) |

Forum ACL is **never** applied to blog posts or static pages. Blog comment
ownership caps are **never** applied to forum posts. Forum post
edit/delete uses the ACL keys `edit_own` / `delete_own` (and
`moderate_forum`) — see [forums.md](forums.md#moderation).

---

## How CMS roles work

`AP_Roles::ensureDefaults()` runs from the installer and from bootstrap.
It is **idempotent**: a non-empty `ap_user_roles` option is kept. On
upgrade it **merges** newly introduced default caps into the five built-in
roles (comment ownership, privacy tools, Site Health, forum caps, …) so
those roles do not go stale. Custom caps already granted on a built-in
role are left alone. The **administrator** role is always given every
primitive in `allPrimitiveCapabilities()`.

| Storage | As built |
|---------|----------|
| Role registry | Option `ap_user_roles` — slug → `{ name, capabilities }` |
| Per-user assignment | Usermeta `ap_capabilities` — role slugs set to `true`, plus optional **direct** cap grants/denials |
| Numeric level | Usermeta `ap_user_level` (0–10, WP-style convenience). **Not** the forum access ladder |
| Default new-user role | Option `default_role` (fresh install: `subscriber`) |

`setUserRole()` replaces the user’s roles with **one** slug (ACP Users →
Add / Edit and `php ap-cli user create` do this). `addUserRole()` can
attach extra registered roles without removing existing ones. Direct caps
(`addUserCap`) **override** role-derived grants. Capability checks ignore
orphaned role keys after `removeRole()`.

`removeRole('administrator')` always returns **false**. The last
administrator cannot be demoted from Users → Edit
(`Cannot demote the last administrator.`).

Checks: `AP_Roles::userCan($userId, $cap, $objectId)` /
`ap_user_can()` / `ap_current_user_can()`. Empty user id, empty cap, and
the sentinel `do_not_allow` all fail. There is **no** `user_has_cap`
filter in core.

Users with **no** role (empty `ap_capabilities`) cannot enter `/ap-admin/`
— login requires the primitive `read`. Banned / pending accounts
(`users.user_status !== 0`) cannot obtain a session; that is account
status, not a capability.

---

## Built-in roles

Five core slugs. Do not invent others.

| Slug | Display name | Level | Who it is for |
|------|--------------|-------|----------------|
| `administrator` | Administrator | 10 | Site owner. Every primitive cap. First installer account. |
| `editor` | Editor | 7 | Site-wide content + comments + **forum moderation** (`moderate_forums`). Not Settings. |
| `author` | Author | 2 | Own posts, media, **edit own blog comments**. |
| `contributor` | Contributor | 1 | Draft/delete own unpublished posts. Cannot publish or upload. |
| `subscriber` | Subscriber | 0 | Read + **delete own blog comments**. Default registration role. |

Installer (`php install/cli.php` / web installer) assigns **administrator**
to the first account. Self-registration and `AP_User::create()` with no
`role` use `default_role` (Settings → General). Unknown slugs fall back to
`subscriber`. CLI default for `php ap-cli user create` is also
`subscriber`.

There is **no** built-in `moderator`, `super_admin`, `forum_user`, or
`author_premium` role. Forum “Moderator” on Forums → Edit is an **ACL
ladder level**, not a CMS role — see [Forum ACL relationship](#forum-acl-relationship).

---

## Primitive capabilities

Non-meta caps known to core (`AP_Roles::allPrimitiveCapabilities()`).
Administrator has **all** of them. Other roles get the curated subsets in
the next section.

| Area | Caps |
|------|------|
| Dashboard | `read`, `manage_options` |
| Users | `list_users`, `create_users`, `edit_users`, `delete_users`, `promote_users` |
| Posts | `edit_posts`, `edit_others_posts`, `edit_published_posts`, `publish_posts`, `delete_posts`, `delete_others_posts`, `delete_published_posts`, `delete_private_posts`, `edit_private_posts`, `read_private_posts` |
| Pages | `edit_pages`, `edit_others_pages`, `edit_published_pages`, `publish_pages`, `delete_pages`, `delete_others_pages`, `delete_published_pages`, `delete_private_pages`, `edit_private_pages`, `read_private_pages` |
| Media | `upload_files` |
| Comments | `moderate_comments`, `edit_own_comments`, `delete_own_comments` |
| Taxonomies | `manage_categories` |
| Appearance | `switch_themes`, `edit_themes`, `edit_theme_options`, `install_themes`, `update_themes`, `delete_themes` |
| Plugins | `activate_plugins`, `edit_plugins`, `install_plugins`, `update_plugins`, `delete_plugins` |
| Updates | `update_core` |
| Tools | `import`, `export` |
| Privacy | `manage_privacy_options`, `export_others_personal_data`, `erase_others_personal_data` |
| Site Health | `view_site_health` |
| Forums (CMS stubs) | `moderate_forums`, `manage_forums` |

ACP screen gates (which cap each `/ap-admin/` file checks):
[admin.md](admin.md#capability-cheat-sheet).

---

## Who gets which caps

Each row **adds** to the row above. Administrator is not “editor plus a
few” — it is the full primitive list (Settings, users, themes, plugins,
updates, privacy, Site Health, `manage_forums`, …).

| Role | Capabilities |
|------|----------------|
| subscriber | `read`, `delete_own_comments` |
| contributor | subscriber + `edit_posts`, `delete_posts` |
| author | contributor + `publish_posts`, `edit_published_posts`, `delete_published_posts`, `upload_files`, `edit_own_comments` |
| editor | author + others/private **posts**, full **pages**, `manage_categories`, `moderate_comments`, `moderate_forums` |
| administrator | every primitive |

Consequences operators actually hit:

- **Subscriber** can log in, leave blog comments, and trash **their own**
  comments. They cannot open Posts, Media, or Comments in the ACP (no
  `edit_posts` / `upload_files` / `moderate_comments`).
- **Contributor** can draft posts but cannot publish or upload.
- **Author** can publish their own posts, upload media, and **edit** their
  own blog comments. They cannot moderate other people’s comments.
- **Editor** can edit others’ posts/pages, moderate all comments, and
  moderate **forums** (`moderate_forums`). They cannot reach Settings
  (`manage_options`) or the forum **tree** (`manage_forums`).
- **Administrator** can do both forum tree and Settings.

---

## Meta capabilities

`edit_post`, `delete_post`, `read_post`, `edit_page`, `delete_page`,
`read_page`, `edit_comment`, and `delete_comment` are **not** stored on
roles. `AP_Roles::mapMetaCap()` / `ap_map_meta_cap()` turn them into
primitives using the row (author, status) and the acting user.

Any other string is treated as already primitive (pass-through).

### Posts and pages

Own vs others, and `publish` / `private` / `trash` / draft, map to the
matching `edit_*` / `delete_*` / `read_private_*` primitives. Missing
object id falls back to the plural (`edit_posts`, `delete_pages`, …).
Missing row → `do_not_allow`. Custom post types currently fall back to the
**posts** primitives.

REST post writes use the same map (`edit_posts` to create; `edit_post` /
`delete_post` on a row) — [rest.md](rest.md). Pages are **GET only** in
core REST.

### Comments (blog)

See the next section. `edit_comment` / `delete_comment` without a comment
id map to `moderate_comments`. Unknown comment id → `do_not_allow`.

---

## Comment ownership

These caps apply to **blog / page comments** (`AP_Comment`, table
`comments`). They do **not** apply to forum posts.

| Primitive | Default grant | What it allows |
|-----------|---------------|----------------|
| `delete_own_comments` | Subscriber+ | Owner may trash/delete **their** comment |
| `edit_own_comments` | Author+ | Owner may edit **their** comment content |
| `moderate_comments` | Editor+ | Any comment: list, approve, spam, trash, edit, delete |

Mapping (`mapEditComment` / `mapDeleteComment`):

1. The comment’s `user_id` must be **> 0** and equal the acting user.
   Guest comments (`user_id` 0) have no owner path.
2. If the owner also has `moderate_comments`, that primitive is used
   (moderators keep full access to their own rows).
3. Otherwise the owner path requires `edit_own_comments` or
   `delete_own_comments`.
4. Non-owners always require `moderate_comments`.

Helpers: `ap_user_can_edit_comment($commentId, $userId)` /
`ap_user_can_delete_comment($commentId, $userId)`. Front POST actions
`ap_comment_edit` and `ap_comment_delete` (nonce-protected). Default Agora
`single.php` shows Edit / Delete on own comments when those helpers
return true (`?comment_edit=`). ACP **Comments** list is
`moderate_comments`; **Edit comment** (`comment.php?c=`) uses meta
`edit_comment` so an Author can open their own row.

REST comments are **GET only**. There is no comment create/edit/delete
route in core.

Discussion policy (moderation queue, registration-required comments,
avatars) is Settings → Discussion — [admin.md](admin.md). Logged-in users
**can** post blog comments; that is not a capability surprise —
[troubleshooting.md](troubleshooting.md#logged-in-comments-and-edit-user).

---

## Forum ACL relationship

Forum permission is **group × forum × permission**, not a sixth CMS role.

### CMS caps that forum code reads

| Cap | Default grant | Effect inside forums |
|-----|---------------|----------------------|
| `manage_forums` | Administrator only | **Always allows** every forum permission. ACP Forums tree, Edit, Groups. Virtual membership in system group `administrators`. |
| `moderate_forums` | Editor and Administrator | Grants the **moderation family** on every forum: `moderate_forum`, `sticky_topics`, `announce_topics`, `lock_topics`, `move_topics`, `edit_own`, `delete_own`. ACP Topics and Moderation. Virtual membership in `global_moderators`. Also skips flood / approval queues. |

Subscriber, Contributor, and Author have **no** extra forum primitives.
On a **Public** forum they view/post because they are in the virtual
**Registered Users** group (`registered`), not because of a CMS cap.

### System groups (`AP_Group`)

Seeded on install. Virtual membership is computed; you do not have to add
users to these groups by hand.

| Slug | Who |
|------|-----|
| `guests` | Not logged in (`user_id` 0) |
| `registered` | Any logged-in user (virtual) |
| `global_moderators` | `moderate_forums` or `manage_forums` |
| `administrators` | `manage_forums` **or** CMS role `administrator` |

Named groups (ACP **Groups**, cap `manage_forums`) are extra rows with
types `open` / `closed` / `hidden` / `system` and member roles
`member` / `moderator` / `leader`. System groups cannot be deleted like
ordinary groups.

Do not confuse:

| Name | What it is |
|------|------------|
| CMS role `editor` (level **7**) | `AP_Roles` slug. Gets `moderate_forums`. |
| Forum ladder `moderator` | Access-level column on Forums → Edit. Maps to group `global_moderators`. |
| `ap_user_level` 0–10 | Usermeta convenience. **Not** consulted by `AP_Forum_Permissions`. |

### Permission keys and presets

Keys: `view_forum`, `read_forum`, `post_topics`, `post_replies`,
`edit_own`, `delete_own`, `attach_files`, `moderate_forum`,
`sticky_topics`, `announce_topics`, `lock_topics`, `move_topics`.

ACP Forums → Edit stores a preset (Public, Members only, Read only,
Moderators only, Administrators only, or Custom). Labels and effects:
[forums.md](forums.md#groups-and-per-forum-acl).

### Resolution

`AP_Forum_Permissions::userCan($userId, $forumId, $perm)`:

1. `manage_forums` → allow.
2. `moderate_forums` **and** a moderation-family permission → allow.
3. Collect effective groups (explicit membership + virtual system groups).
4. Forum-specific `{prefix}forum_permissions` row overrides global
   (`forum_id = 0`).
5. Explicit group **deny** wins; else explicit **allow** (so a VIP group
   can open a forum that `registered` cannot see). Deny on the soft
   virtual groups `guests` / `registered` can be overridden by an allow
   on an explicit (custom or elevated) group.

Helpers: `ap_user_can_forum()`, `ap_current_user_can_forum()`,
`ap_user_can_view_forum()`, `ap_user_can_post_topic()`,
`ap_user_can_moderate_forum()`.

Private messages use CMS `read` (or `manage_forums`), not forum ACL —
[forums.md](forums.md#private-messages). Bans (`user_status` = 1) are
forum moderation records, not a role.

---

## How plugins add caps

There is **no** Roles screen and **no** `user_has_cap` filter. Plugins
register and grant through `AP_Roles` (typically on activation):

```php
ap_add_role('reviewer', 'Reviewer', [
    'read' => true,
    'edit_posts' => true,
    'moderate_comments' => true,
]);

ap_add_cap('author', 'myplugin_manage', true);
ap_remove_cap('author', 'myplugin_manage');

ap_add_user_role($userId, 'reviewer');
ap_set_user_role($userId, 'editor');
```

| Helper | As built |
|--------|----------|
| `ap_add_role($slug, $name, $caps)` | False if the slug is empty or already exists |
| `ap_remove_role($slug)` | False for `administrator` |
| `ap_add_cap($role, $cap, $grant = true)` | Grant or deny on a registered role |
| `ap_remove_cap($role, $cap)` | Drops the key from the role |
| `ap_set_user_role` / `ap_add_user_role` / `ap_remove_user_role` | Assignment |
| `ap_user_can` / `ap_current_user_can` / `ap_map_meta_cap` | Checks |

Plugin ACP pages declare their own gate on
`ap_register_admin_page(… 'capability' => 'manage_options')` — default
`manage_options`. That is a string stored on the menu row, not a new
primitive unless you also `ap_add_cap` it onto roles.
[plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp).

A custom role is **that plugin**, not core. Do not document it as a
built-in AgoraPress role.

---

## Operator surfaces

| Surface | As built |
|---------|----------|
| Users list (`users.php`) | Cap `list_users`. Filter by role; bulk role change needs `promote_users`. Cannot delete the sole administrator. |
| Add user (`user-new.php`) | Cap `create_users`. Role dropdown is the registered slug list. |
| Edit user (`user-edit.php`) | Cap `edit_users`. Changing role needs `promote_users`. Cannot demote the last administrator. |
| Profile (`profile.php`) | Cap `read`. **Never** changes the signed-in user’s role. |
| Settings → General | `default_role` for self-registration. |
| `php ap-cli user list --role=` | Filter. |
| `php ap-cli user create --role=` | Default `subscriber`. **No** `user update` / `user delete`. |
| REST `GET /ap/v1/users` | Public profile fields (no email). Email on `GET /users/{id}` only for self or `list_users`. **No** user writes. |

Registration (`users_can_register`, email verification, math CAPTCHA) is
Settings → General, not a membership plugin. There is **no** TOTP / 2FA
in core.

CLI create (password from the environment so it does not land in history):

```bash
AP_USER_PASSWORD='choose-a-strong-password' php ap-cli user create \
  --user_login=jane --user_email=jane@example.com --role=author
```

---

## Not in core

Do not tell operators or agents these exist:

- Extra built-in CMS roles (`moderator`, `super_admin`, `forum_moderator`, …)
- A Roles / Capabilities admin screen, or editing the `ap_user_roles`
  option from Settings
- `php ap-cli role` / `user update` / `user delete`
- A `user_has_cap` / `map_meta_cap` filter
- Application Passwords, OAuth, JWT, or TOTP
- REST writes for users, comments, or forum replies
- Applying forum ACL to posts/pages, or `edit_own_comments` to forum posts
- Multisite / network roles
- Gutenberg / FSE capability types

If a plugin added a role or cap, that surface is **the plugin**, not core.

---

## Related docs

| Need | Doc |
|------|-----|
| ACP screens and cheat sheet | [admin.md](admin.md) |
| Forum groups, presets, moderation | [forums.md](forums.md) |
| `php ap-cli user` | [cli.md](cli.md) |
| REST `list_users` / `edit_posts` | [rest.md](rest.md) |
| Nonces and capability checks | [security.md](security.md) |
| Logged-in comments / Edit User | [troubleshooting.md](troubleshooting.md) |
| `ap_register_admin_page` capability | [plugins.md](plugins.md) |
| `users` / `usermeta` / `forum_permissions` | [schema.md](schema.md) |
| There is **no** `user_has_cap` filter | [hooks.md](hooks.md) |
| Docs index | [README.md](README.md) |

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
