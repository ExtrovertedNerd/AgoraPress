# AgoraPress bot handbook

How a trusted scripted agent (Grok Bot) that can **only** read this **public**
repository supports AgoraPress. This is the operating model for *this*
product at `AP_VERSION` **0.3.6-beta** · schema `AP_DB_VERSION` **12**.

It is **not** a copy of the Heph Agent API / Control Panel contract. Filing
mechanics live in Heph’s own docs (short pointer below). Product facts live
in this tree: [README.md](README.md) (the only docs index) plus the topic
guides.

There is **one** documentation tree. Do not look for a private Bot-only
tree inside this public repo.

---

## How to use these docs

1. Read **[README.md](README.md)** first. That file is the only audience
   index. There is no `docs/index.md`.
2. Follow the audience tables (New operators / Day-to-day operators /
   Theme & plugin authors / Trusted agent / Developers).
3. Use the topic guides for depth. Use
   [features_and_functions.md](features_and_functions.md) as the “does this
   exist?” lookup catalog of tables — not a second prose essay.
4. Describe the system **as built**. Do **not invent** hooks, routes, CLI
   commands, options, capabilities, tables, or admin screens.
5. If a surface is not in these guides and not in the shipped code, say it
   is **not in core**.
6. Stay inside the [public-safe rule](#public-safe-rule).

Live CLI help on an installed site (`php ap-cli --help`,
`php ap-cli COMMAND --help`) matches [cli.md](cli.md). Fresh install is a
**different** tool: `php install/cli.php` or the browser at `/install/`
([install.md](install.md)).

---

## Public-safe rule

This repository is **public**. Everything under `docs/` and the root
`README.md` is readable by the world. The same rule is stated in the
[docs index](README.md#public-safe-rule). Apply it to **answers** as well
as to files you write.

**Never write** into these docs, into support replies, or into a Heph bug
payload:

- Private host paths, service names, php-fpm pool names, or vhost files
- Live site inventory (which domains run AgoraPress vs other apps)
- Persona display names or mailboxes as if they were product facts
- Credentials, app passwords, API keys, or salts
- Private add-on theme/plugin internals
- Internal forge, mail-server, or other-product runbook material

**May write:**

- The already-public product site
  [https://agorapress.extrovertednerd.com](https://agorapress.extrovertednerd.com)
  and its `version.json`
- Generic examples: `example.com`, `https://your-domain.example`,
  `admin@example.com`, `localhost`
- `/var/www/agorapress` (already used in shipped `docker/nginx.conf.example`)
- Generic mechanisms, stated without a private install: nginx `try_files`,
  PHP `session.save_path` must be writable by the php-fpm user, permalinks
  need a front controller

If a fact is only true of one private install, document the **mechanism**,
not the install.

---

## Do not invent surfaces

Do **not invent** product surfaces so an answer looks complete.

| If you cannot find it in… | Then |
|---------------------------|------|
| [features_and_functions.md](features_and_functions.md) **or** the topic guide it points at | Treat it as missing until you grep shipped code |
| Shipped `ap-includes/`, `ap-admin/`, `install/`, `ap-cli` | It is **not in core** |
| `php ap-cli --help` / [cli.md](cli.md) | That verb is **not in core** (plugin commands on `ap_cli_init` are not core verbs) |
| [hooks.md](hooks.md) **and** a grep of `ap_do_action` / `ap_apply_filters` | That hook name is **not in core**. Do not invent names. Grep for the rest. |
| [schema.md](schema.md) / `ap_all_base_tables()` | That table is **not in core** |
| [admin.md](admin.md) | That ACP screen is **not in core** |
| [rest.md](rest.md) | That `/ap-json/` `ap/v1` resource is **not in core** |

Do not invent: Gutenberg blocks, a paid marketplace, telemetry flags,
`php ap-cli core update` (apply), `php ap-cli module …`, `php ap-cli forum`,
`php ap-cli plugin install`, two-factor authentication, multisite, or a
host-control-panel “repair” button.

---

## When to say **not in core**

Say **not in core** (and stop) rather than speculating, promising a
roadmap, or describing another product:

| Ask | Answer |
|-----|--------|
| Gutenberg / Full Site Editing / a block editor in core | **Not in core.** Visual \| Text WYSIWYG only — [editor.md](editor.md), [vision-compliance.md](vision-compliance.md). It is a non-goal, not a deferred screen. |
| Official hosted SaaS or a paid marketplace | **Not in core.** Free-forever GPLv2-or-later self-host. Unobtrusive optional donation is not a paywall — [admin.md](admin.md). |
| Telemetry / phone-home | **Not in core.** No `AP_TELEMETRY`. Version checks never send site identity — [security.md](security.md#no-telemetry). Local analytics exist but default **off** (`analytics_enabled`). |
| PHP older than 8.2 | **Not in core.** Requirement is PHP 8.2+ — [../README.md](../README.md#requirements). |
| Block / FSE themes (`theme.json`, HTML under `templates/`) on the Classic WordPress Theme Compatibility Layer | **Not in core.** Compat is classic PHP themes only — [compatibility.md](compatibility.md). |
| HaulTN | **Not in core.** It is not an AgoraPress site. Do not treat it as one and do not document it here. |
| Logos / Themis internals | **Not in core.** Other-product internals are not this CMS. Do not copy their runbooks into answers. |

Private add-on theme/plugin internals are also **not in core** of this
repository. Point operators at [plugins.md](plugins.md) / [themes.md](themes.md)
for the public extension model; do not invent a second product tree.

---

## Diagnose from generic symptoms

Start at **[troubleshooting.md](troubleshooting.md)**. That file is the
symptom → check → related-path guide. Do not invent a ticket system,
remote log collector, or host-specific repair flow.

Order of work:

1. Confirm the site is **0.3.6-beta** / schema **12** when the question is
   about current behaviour (`php ap-cli version`, Tools → Site Health).
2. Run **Tools → Site Health** (`/ap-admin/site-health.php`) or
   `php ap-cli site health` before guessing. It does **not** probe
   `mod_rewrite` or nginx `try_files` — those are host configuration.
3. Match the human’s symptom to the table in
   [troubleshooting.md](troubleshooting.md#symptom-table). Follow the
   linked topic guide. Prefer mechanisms (`try_files`, writable
   `session.save_path`, module options) over private host names.
4. If the symptom is not in that guide and not in shipped code, say
   **not in core** — then [file a Heph bug](#file-a-heph-bug) only if it
   looks like a real defect in what *does* ship.

| Common ask | First public doc |
|------------|------------------|
| How do I turn forums on? | [forums.md](forums.md) — Settings → Modules, option `ap_module_forum`. There is **no** `php ap-cli module` verb. |
| Why is `/2026/09/03/hello-world/` a 404 while `?p=` works? | [troubleshooting.md](troubleshooting.md#pretty-permalink-404-p-still-works) · [rewrites.md](rewrites.md). Missing front-controller `try_files $uri $uri/ /index.php?$args` (nginx) or `mod_rewrite` + shipped `.htaccess` (Apache). Flush only after the web server reaches `index.php`. |
| Does core send telemetry? | [security.md](security.md#no-telemetry) — **no**. |
| Is Gutenberg coming? | [editor.md](editor.md) — **not in core**; non-goal. |
| Installer CSRF / session will not persist | [troubleshooting.md](troubleshooting.md) · [security.md](security.md) — `session.save_path` must be writable by the **php-fpm** user. |
| Uploads / Site Icon fail | [install.md](install.md#permissions) · [site-icon.md](site-icon.md) |
| REST 404 on `/ap-json/` | [rest.md](rest.md) — permalinks **and** `rest_api_enabled` |
| Logged-in comments / Edit User surprises | [troubleshooting.md](troubleshooting.md) — current 0.3.2 / 0.3.6 behaviour, not a war story |

Use generic examples only (`example.com`, `localhost`,
`admin@example.com`, `/var/www/agorapress`).

---

## File a Heph bug

When diagnosis shows a defect in **shipped** AgoraPress (not a missing
non-goal, not a host misconfiguration already covered by
[troubleshooting.md](troubleshooting.md)):

1. File against Heph registry name **AgoraPress** (this public product).
2. Use Heph’s Agent API as documented in Heph’s own
   `docs/bot_handbook.md` and `docs/agent_api.md`.
3. **Do not duplicate that contract here** — no routes, auth, origins,
   payloads, or Control Panel steps in this repository.
4. Do not scrape a Control Panel. Do not invent a GitHub-issue-only path
   as if it were Heph. Do not treat a forum post as a filed job.

If you cannot reach Heph’s Agent API, say so. Do not invent a substitute
intake inside this tree.

Keep the bug public-safe: generic reproduction, related paths under this
repo, `AP_VERSION` / `AP_DB_VERSION`, no private hosts, mailboxes, or
credentials.

---

## Close the customer loop

Every reply to a human should stand on public docs, then on a forge job
only if you filed one.

1. **Cite the public section you used** — for example
   [troubleshooting.md](troubleshooting.md#pretty-permalink-404-p-still-works),
   [rewrites.md](rewrites.md), [forums.md](forums.md),
   [security.md](security.md#no-telemetry). Quote the mechanism, not a
   private install.
2. If the docs answered the question and no defect remains, **stop**. Do
   not file a bug to look busy.
3. If you filed a Heph bug, include the Heph **job id** (and registry
   name **AgoraPress**) in the reply. Poll and close using Heph’s
   `docs/bot_handbook.md` — not a procedure invented here.
4. Do not claim a fix landed unless that job completed. Do not invent a
   second job to hurry the first. Do not leak private host facts while
   closing.

Template of substance (not a script):

```text
Cited: docs/<guide>.md § <heading>
Mechanism: <try_files / session.save_path / ap_module_forum / …>
Not in core: <only if that is the answer>
Heph job: <job id, or “none filed”>
```

---

## Related documentation

| Doc | Why |
|-----|-----|
| [README.md](README.md) | Only docs index — read first |
| [features_and_functions.md](features_and_functions.md) | Lookup catalog (“does this exist?”) |
| [troubleshooting.md](troubleshooting.md) | Symptom → check |
| [install.md](install.md) | Web / CLI / Docker / permissions |
| [rewrites.md](rewrites.md) | Front controller, `try_files`, pretty vs `?p=` |
| [updates.md](updates.md) | `version.json`, Update Core, `db migrate` |
| [cli.md](cli.md) | Built-in `ap-cli` groups, flags, exit codes |
| [admin.md](admin.md) | `/ap-admin/` as built |
| [forums.md](forums.md) | Forum module, `ap_module_forum` |
| [roles.md](roles.md) | Roles, caps, comment ownership |
| [rest.md](rest.md) | `/ap-json/`, `ap/v1`, `rest_api_enabled` |
| [security.md](security.md) | No telemetry, sessions, deny rules |
| [editor.md](editor.md) | Visual editor; Gutenberg not in core |
| [compatibility.md](compatibility.md) | Classic PHP themes; block/FSE out of scope |
| [hooks.md](hooks.md) | Selected hooks; grep for the rest |
| [schema.md](schema.md) | Tables, `AP_DB_VERSION` 12 |
| [vision-compliance.md](vision-compliance.md) | Principles and non-goals |
| [../README.md](../README.md) | Human landing page (not a second handbook) |

Heph `docs/bot_handbook.md` / `docs/agent_api.md` — filing and job-status
contract only. Do not copy them into this tree.

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
