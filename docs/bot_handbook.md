# AgoraPress bot handbook

How a trusted scripted agent (Grok Bot) that can only read this **public**
repository supports AgoraPress. This is the operating model for *this*
product. It is not a copy of the Heph Agent API / Control Panel contract.

## How to use these docs

1. Read [README.md](README.md) (the only docs index) first.
2. Follow the audience tables. Use the topic guides and the lookup catalog
   [features_and_functions.md](features_and_functions.md).
3. Do **not invent** hooks, routes, CLI commands, options, capabilities,
   tables, or admin screens.
4. If a surface is not in these guides and not in the shipped code, say it
   is **not in core**.
5. Stay inside the public-safe rule below.

Describe the system **as built** at `AP_VERSION` **0.3.6-beta** · schema
`AP_DB_VERSION` **12**.

---

## Public-safe rule

This repository is **public**. Everything under `docs/` and the root
`README.md` is readable by the world. The same rule is stated in the
[docs index](README.md#public-safe-rule).

**Never write** into these docs (or into answers that quote them):

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

If a fact is only true of one private install, document the **mechanism**, not
the install.

---

## Do not invent surfaces

Do not invent product surfaces so the docs look complete. If a hook, route,
CLI command, option, capability, table, or admin screen is not in these
guides and not in the shipped code, it is **not in core**.

Examples that are **not in core**:

- Gutenberg / Full Site Editing / a block editor
- Official hosted SaaS or a paid marketplace
- Telemetry or phone-home (version checks never send site identity)
- PHP older than 8.2
- Block / FSE themes on the Classic WordPress Theme Compatibility Layer

Generic examples only (`example.com`, `localhost`, `admin@example.com`). Do
not treat another product’s private runbook as AgoraPress documentation.

Generic diagnosis lives in [troubleshooting.md](troubleshooting.md). How to
file a Heph bug against registry name **AgoraPress** (short pointer to Heph’s
Agent API — do not duplicate that contract here) and how to close a customer
loop (cite the public doc section, then the Heph job id if a bug was filed)
are part of this handbook’s operating model. Stay inside the public-safe
rule. Do not invent surfaces.
