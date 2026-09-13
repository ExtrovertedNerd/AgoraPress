"""
Charter presence tests for public docs (humans + Grok Bot).

Small pytest smoke so a required guide cannot disappear silently.
Runnable via:
  pytest tests/test_docs_presence.py -v
"""

from __future__ import annotations

import re
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
CLI_SRC = ROOT / "ap-includes" / "class-ap-cli.php"

# New operator/agent guides this charter added (SPEC “required new guides”).
REQUIRED_NEW_PATHS = (
    "install.md",
    "updates.md",
    "rewrites.md",
    "cli.md",
    "admin.md",
    "forums.md",
    "rest.md",
    "roles.md",
    "security.md",
    "troubleshooting.md",
    "bot_handbook.md",
    "features_and_functions.md",
)

# Phase 0 inventory: built-in ap-cli --help command groups.
PHASE0_CLI_GROUPS = (
    "cache",
    "cli",
    "core",
    "cron",
    "db",
    "help",
    "option",
    "plugin",
    "post",
    "rewrite",
    "site",
    "theme",
    "user",
    "version",
)

PRIVATE_MARKERS = (
    "roland",
    "stallboy",
    "mail.0shits.com",
    "0shits.com",
    "keepass",
    "stalwart",
    "jarvis",
    "blindvault",
    "mensbs",
    "agorapress_addons",
)

# RFC 2606 examples, loopback, already-public product site, public references.
PUBLIC_SAFE_HOSTS = {
    "localhost",
    "127.0.0.1",
    "example.com",
    "example.net",
    "example.org",
    "smtp.example.com",
    "your-domain.example",
    "agorapress.extrovertednerd.com",
    "github.com",
    "docs.docker.com",
    "www.gnu.org",
    "gnu.org",
    "keepachangelog.com",
    "semver.org",
}

PUBLIC_SAFE_VAR_WWW = {"agorapress", "html", "site"}
# Other-product names. Allowed only in docs/bot_handbook.md as "not in core".
OTHER_PRODUCT_MARKERS = ("haultn", "themis", "logos")
URL_HOST = re.compile(r"https?://([a-zA-Z0-9.-]+)", re.IGNORECASE)
MAILBOX = re.compile(r"[A-Z0-9._%+\-]+@([A-Z0-9.\-]+\.[A-Z]{2,})", re.IGNORECASE)
ORG_HOST = re.compile(r"[a-z0-9.-]*extrovertednerd\.com", re.IGNORECASE)
PRIVATE_FS_PATH = re.compile(r"(?<![A-Za-z0-9])/(?:home|root|srv|opt|etc)/[^\s`'\" )\]]+")
VAR_WWW = re.compile(r"/var/www/([A-Za-z0-9._-]*)")

CATALOG_TOKENS = (
    "AP_DB_VERSION",
    "/ap-admin/",
    "/ap-json/",
    "rest_api_enabled",
    "analytics_enabled",
    "ap_register_admin_page",
    "options-mail.php",
    "ap_mail_send",
    "ap_user_created",
    "ap_reserved_usernames",
    "forum_group_only",
    "group_only",
    "This group only",
    "AP_MAIL_TRANSPORT",
    "agora_visitor_color_preview",
    "--ap-editor-bg",
    "Set as default",
)

ADD_COMMAND = re.compile(
    r"self::addCommand\(\s*'([a-z0-9-]+)'",
    re.MULTILINE,
)


@pytest.fixture(scope="module")
def docs_root() -> Path:
    assert DOCS.is_dir(), "Missing docs/ directory"
    return DOCS


def _builtin_cli_groups() -> list[str]:
    text = CLI_SRC.read_text(encoding="utf-8")
    start = text.find("public static function ensureBuiltins()")
    assert start != -1, "AP_Cli::ensureBuiltins() not found"
    chunk = text[start:]
    names = ADD_COMMAND.findall(chunk)
    assert names, "No addCommand() names found in ensureBuiltins()"
    return sorted(set(names))


@pytest.mark.parametrize("name", REQUIRED_NEW_PATHS)
def test_required_new_path_exists_and_is_non_empty(docs_root: Path, name: str) -> None:
    path = docs_root / name
    assert path.is_file(), f"Missing required guide: docs/{name}"
    text = path.read_text(encoding="utf-8")
    assert text.strip(), f"docs/{name} must be non-empty"


def test_docs_index_links_every_new_guide(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    for name in REQUIRED_NEW_PATHS:
        pattern = rf"\]\({re.escape(name)}(?:#[^)]*)?\)"
        assert re.search(pattern, index), (
            f"docs/README.md should markdown-link {name}"
        )
    for path in sorted(docs_root.glob("*.md")):
        if path.name == "README.md":
            continue
        pattern = rf"\]\({re.escape(path.name)}(?:#[^)]*)?\)"
        assert re.search(pattern, index), (
            f"docs/README.md should markdown-link {path.name}"
        )
    assert re.search(r"(?im)^##\s+By audience\s*$", index)
    assert re.search(r"(?im)^##\s+Not in core\s*$", index)
    assert re.search(r"(?im)^##\s+Quick command map\s*$", index)
    groups = _builtin_cli_groups()
    for group in groups:
        assert f"php ap-cli {group}" in index, (
            f"docs/README.md should name builtin ap-cli group {group}"
        )


def test_bot_handbook_states_public_safe_rule_and_do_not_invent_surfaces(
    docs_root: Path,
) -> None:
    path = docs_root / "bot_handbook.md"
    assert path.is_file(), "Missing docs/bot_handbook.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    assert (
        "public-safe" in lower
        or "this repository is **public**" in lower
        or "this repository is public" in lower
    ), "docs/bot_handbook.md should state the public-safe / public-repository rule"
    assert (
        "do not invent surfaces" in lower or "do **not invent** surfaces" in lower
    ), "docs/bot_handbook.md should say do not invent surfaces"
    assert "never write" in lower
    assert "not in core" in lower
    for heading in (
        r"(?im)^##\s+How to use these docs\s*$",
        r"(?im)^##\s+Public-safe rule\s*$",
        r"(?im)^##\s+Do not invent surfaces\s*$",
        r"(?im)^##\s+When to say \*\*not in core\*\*\s*$",
        r"(?im)^##\s+Diagnose from generic symptoms\s*$",
        r"(?im)^##\s+File a Heph bug\s*$",
        r"(?im)^##\s+Close the customer loop\s*$",
    ):
        assert re.search(heading, text), f"bot_handbook.md missing heading: {heading}"
    assert "readme.md" in lower
    assert "troubleshooting.md" in lower
    assert "registry name **agorapress**" in lower
    assert "agent_api.md" in lower
    assert "do not duplicate" in lower
    assert "job id" in lower
    assert "haultn" in lower
    assert "gutenberg" in lower
    assert "ap_telemetry" in lower
    for banned in PRIVATE_MARKERS:
        assert banned not in lower, (
            f"docs/bot_handbook.md must not contain private marker: {banned}"
        )


def test_rewrites_doc_contains_shipped_try_files_pattern(docs_root: Path) -> None:
    text = (docs_root / "rewrites.md").read_text(encoding="utf-8")
    assert "try_files $uri $uri/ /index.php" in text
    assert "try_files $uri $uri/ /index.php?$args" in text

    nginx = (ROOT / "docker" / "nginx.conf.example").read_text(encoding="utf-8")
    htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
    assert "try_files $uri $uri/ /index.php?$args;" in nginx
    assert "try_files $uri $uri/ /index.php?$args;" in text
    assert "RewriteCond %{REQUEST_FILENAME} !-f" in htaccess
    assert "RewriteCond %{REQUEST_FILENAME} !-f" in text
    assert "RewriteRule . /index.php [L]" in htaccess
    assert "RewriteRule . /index.php [L]" in text
    assert "favicon.ico" in htaccess
    assert "favicon.ico" in text
    lower = text.lower()
    assert "php ap-cli rewrite flush" in lower
    assert "does **not** write" in lower
    assert "?p=" in text
    assert "?page_id=" in text
    assert "/slug/" in text
    assert "/yyyy/mm/dd/" in lower


def test_updates_doc_covers_preserve_list_and_public_endpoint(docs_root: Path) -> None:
    """SPEC: updates.md names version.json, Update Core, skip list, CLI verbs."""
    text = (docs_root / "updates.md").read_text(encoding="utf-8")
    lower = text.lower()

    src = (ROOT / "ap-includes" / "class-ap-version-check.php").read_text(
        encoding="utf-8"
    )
    match = re.search(r"public const DEFAULT_ENDPOINT = '([^']+)'", src)
    assert match, "AP_Version_Check::DEFAULT_ENDPOINT not found"
    assert match.group(1) in text

    updater = (ROOT / "ap-includes" / "class-ap-core-updater.php").read_text(
        encoding="utf-8"
    )
    preserve = re.search(
        r"public const PRESERVE_EXACT = \[([^\]]+)\]", updater, re.MULTILINE
    )
    assert preserve, "AP_Core_Updater::PRESERVE_EXACT not found"
    for path in re.findall(r"'([^']+)'", preserve.group(1)):
        assert path in text, f"docs/updates.md must name preserve path {path}"

    assert "tools → update core" in lower
    assert "no site identity" in lower
    assert "php bin/package-release.php" in text
    assert "php ap-cli core check-update" in text
    assert "php ap-cli db migrate" in text
    assert "php ap-cli core update" in lower
    assert "not in core" in lower
    for path in (
        "install/",
        "ap-content/uploads/",
        "ap-content/plugins/",
        "ap-content/mu-plugins/",
    ):
        assert path in text, f"docs/updates.md must name skip path {path}"
    assert "ap-content/themes/agora" in text
    assert "VersionCheck; no-site-id" in text
    assert "CoreUpdater; no-site-id" in text


def test_cli_doc_names_every_builtin_command_group(docs_root: Path) -> None:
    groups = _builtin_cli_groups()
    for expected in PHASE0_CLI_GROUPS:
        assert expected in groups, (
            f"Phase 0 group {expected!r} missing from AP_Cli::ensureBuiltins()"
        )
    extra = sorted(set(groups) - set(PHASE0_CLI_GROUPS))
    assert not extra, (
        "AP_Cli built-ins drifted from Phase 0 inventory; update docs/cli.md "
        f"and PHASE0_CLI_GROUPS. Extra: {extra}"
    )

    cli = (docs_root / "cli.md").read_text(encoding="utf-8")
    for group in groups:
        pattern = rf"^\|\s+`{re.escape(group)}`\s+\|"
        assert re.search(pattern, cli, re.MULTILINE), (
            f"docs/cli.md must name ap-cli group `{group}` in the built-in table"
        )


def test_cli_doc_cookbook_matches_ap_cli_as_built(docs_root: Path) -> None:
    """SPEC: cli.md cookbook matches AP_Cli flags, exits, --file, defaults."""
    cli = (docs_root / "cli.md").read_text(encoding="utf-8")
    src = CLI_SRC.read_text(encoding="utf-8")
    bootstrap = (ROOT / "ap-includes" / "bootstrap.php").read_text(encoding="utf-8")

    assert "AP_CLI_SKIP_PLUGINS" in bootstrap
    assert "AP_CLI_SKIP_THEMES" not in bootstrap
    assert "AP_CLI_SKIP_THEMES" in src
    assert "AP_CLI_SKIP_THEMES" in cli
    assert "reserved" in cli.lower()
    assert "AP_CLI_SKIP_PLUGINS" in cli

    lower = cli.lower()
    for needle in (
        "exit_ok",
        "exit_usage",
        "exit_error",
        "exit_not_installed",
        "--path",
        "--url",
        "--skip-plugins",
        "--skip-themes",
        "php ap-cli core update",
        "php install/cli.php",
        "ap_user_password",
        "ap_cli_init",
        "scheme://",
        "remote urls and stream wrappers are not allowed",
        "draft",
        "publish",
        "check-update",
        "--force",
        "cron event list",
        "cron event run",
        "rewrite flush",
        "site health",
        "not in core",
        "always exits `0`",
        "usage: option get <name>",
        "option not found",
        "not reachable",
        "check_update",
        "--theme=",
        "--key=",
        "compact",
    ):
        assert needle in lower, f"docs/cli.md should mention: {needle}"

    assert "`--format=json` always exits `0`" in cli
    assert "const EXIT_OK = 0" in src
    assert "const EXIT_USAGE = 1" in src
    assert "const EXIT_ERROR = 2" in src
    assert "const EXIT_NOT_INSTALLED = 3" in src
    assert "status = $type === 'page' ? 'publish' : 'draft'" in src
    assert "remote URLs and stream wrappers are not allowed" in src
    assert "getenv('AP_USER_PASSWORD')" in src


def test_admin_doc_matches_acp_as_built(docs_root: Path) -> None:
    """SPEC: admin.md maps /ap-admin/ as built (allowlist, zip, HoF, donate)."""
    text = (docs_root / "admin.md").read_text(encoding="utf-8")
    admin_dir = ROOT / "ap-admin"
    chrome = {"admin-bootstrap.php", "admin-header.php", "admin-footer.php"}
    entry = sorted(p.name for p in admin_dir.glob("*.php") if p.name not in chrome)
    assert entry, "ap-admin/ should contain entry scripts"
    for basename in entry:
        assert basename in text, f"docs/admin.md must name ACP entry script {basename}"

    admin_src = (admin_dir / "includes" / "class-ap-admin.php").read_text(
        encoding="utf-8"
    )
    cap_keys = re.findall(r"'([a-z0-9.-]+\.php)' => '", admin_src)
    assert cap_keys, "AP_Admin::screenCapabilities() keys should parse"
    for basename in cap_keys:
        assert basename in text, (
            f"docs/admin.md must name screenCapabilities key {basename}"
        )

    hof = (ROOT / "ap-includes" / "class-ap-hall-of-fame.php").read_text(
        encoding="utf-8"
    )
    endpoint = re.search(r"public const DEFAULT_ENDPOINT = '([^']+)'", hof)
    donate = re.search(r"public const DONATION_URL = '([^']+)'", hof)
    public_page = re.search(r"public const PUBLIC_PAGE_URL = '([^']+)'", hof)
    assert endpoint and donate and public_page
    assert endpoint.group(1) in text
    assert donate.group(1) in text
    assert public_page.group(1) in text
    assert "hall-of-fame-join" in text
    assert "hall-of-fame-leave" in text
    assert "hall-of-fame-dismiss" in text
    assert "usesInstallerPings" in text
    assert "return false" in hof

    installer = (ROOT / "ap-includes" / "class-ap-plugin-installer.php").read_text(
        encoding="utf-8"
    )
    assert "const DEFAULT_MAX_BYTES = 41943040" in installer
    assert "40 MiB" in text
    assert "plugin-upload" in text

    analytics = (
        admin_dir / "includes" / "class-ap-admin-analytics.php"
    ).read_text(encoding="utf-8")
    assert "const CAPABILITY = 'manage_options'" in analytics
    assert "const DEFAULT_DAYS = 30" in analytics
    assert "const ALLOWED_DAYS = [7, 14, 30, 90]" in analytics

    for needle in (
        "user-edit.php?user_id=",
        "The requested admin page was not found.",
        "Read only (members)",
        "AP_ADMIN",
        "ap_admin_menu",
        "admin_menu",
        "maybeQueueAdminNotice",
        "noindex, nofollow",
        "blog_public",
        "sitemap_enabled",
        "open_graph_enabled",
        "phpbb-json",
        "phpbb-db",
        "paywall",
        "not in core",
        "admin-resend",
        "smtp.example.com",
        "noreply@example.com",
        "AP_MAIL_FROM_EMAIL",
        "AP_SMTP_HOST",
        "This group only",
        "register-guard.js",
        "Set as default",
        "This is the default category. Set another category as default first.",
        "default_category",
        "ensureDefaultCategory",
        "set-default-tag-",
        "Default Post Category",
    ):
        assert needle in text, f"docs/admin.md should mention: {needle}"


PHP_STRING_CONST = re.compile(r"public const ([A-Z0-9_]+) = '([^']+)'")
PHP_INT_CONST = re.compile(r"public const ([A-Z0-9_]+) = (\d+)")


def _php_string_consts(path: Path) -> dict[str, str]:
    src = path.read_text(encoding="utf-8")
    names = dict(PHP_STRING_CONST.findall(src))
    assert names, f"No string constants in {path.name}"
    return names


def _php_int_consts(path: Path) -> dict[str, int]:
    src = path.read_text(encoding="utf-8")
    return {name: int(value) for name, value in PHP_INT_CONST.findall(src)}


def _php_action_allowlist(path: Path) -> list[str]:
    src = path.read_text(encoding="utf-8")
    match = re.search(
        r"in_array\(\$rowAction, \[(.*?)\], true\)",
        src,
        re.DOTALL,
    )
    assert match, f"row-action allowlist not found in {path.name}"
    names = re.findall(r"'([a-z_]+)'", match.group(1))
    assert names, f"No row actions in {path.name}"
    return names


def test_forums_doc_matches_module_as_built(docs_root: Path) -> None:
    """SPEC: forums.md describes the first-class forum module as built."""
    text = (docs_root / "forums.md").read_text(encoding="utf-8")
    includes = ROOT / "ap-includes"

    front = _php_string_consts(includes / "class-ap-forum-front.php")
    for name, value in front.items():
        if name.startswith("ACTION_"):
            assert value in text, (
                f"docs/forums.md must name AP_Forum_Front::{name} ({value})"
            )

    perms = _php_string_consts(includes / "class-ap-forum-permissions.php")
    for name, value in perms.items():
        if name.startswith("PERM_") or name.startswith("ACCESS_"):
            assert value in text, (
                f"docs/forums.md must name AP_Forum_Permissions::{name} ({value})"
            )

    forum = _php_string_consts(includes / "class-ap-forum.php")
    for name, value in forum.items():
        if name.startswith(("FORUM_TYPE_", "FORUM_STATUS_", "TOPIC_TYPE_")):
            if name in ("TOPIC_TYPE_NORMAL", "TOPIC_TYPE_ANNOUNCE", "TOPIC_TYPE_GLOBAL"):
                continue
            assert value in text, (
                f"docs/forums.md must name AP_Forum::{name} ({value})"
            )

    group = _php_string_consts(includes / "class-ap-group.php")
    for name, value in group.items():
        if name.startswith("SLUG_"):
            assert value in text, (
                f"docs/forums.md must name AP_Group::{name} ({value})"
            )

    online = _php_string_consts(includes / "class-ap-online.php")
    assert online["GUEST_COOKIE"] in text
    online_ints = _php_int_consts(includes / "class-ap-online.php")
    assert str(online_ints["DEFAULT_WINDOW"]) in text
    assert str(online_ints["MIN_WINDOW"]) in text
    assert str(online_ints["MAX_WINDOW"]) in text

    read_meta = _php_string_consts(includes / "class-ap-forum-read.php")
    assert read_meta["META_LAST_MARK"] in text
    assert read_meta["OPTION_ENABLED"] in text

    guard_ints = _php_int_consts(includes / "class-ap-forum-guard.php")
    assert str(guard_ints["DEFAULT_FLOOD_INTERVAL"]) in text
    assert str(guard_ints["DEFAULT_SPAM_MAX_LINKS"]) in text

    attach_ints = _php_int_consts(includes / "class-ap-forum-attachment.php")
    assert str(attach_ints["DEFAULT_MAX_SIZE"]) in text
    assert str(attach_ints["DEFAULT_MAX_PER_POST"]) in text
    assert str(attach_ints["DEFAULT_USER_QUOTA"]) in text

    rest_src = (includes / "class-ap-rest.php").read_text(encoding="utf-8")
    forum_payload = re.search(
        r"public static function prepareForum\(.*?\n        return \[(.*?)\n        \];",
        rest_src,
        re.DOTALL,
    )
    topic_payload = re.search(
        r"public static function prepareTopic\(.*?\n        return \[(.*?)\n        \];",
        rest_src,
        re.DOTALL,
    )
    assert forum_payload and topic_payload
    for key in re.findall(r"'([a-z_]+)'\s*=>", forum_payload.group(1)):
        assert f"`{key}`" in text, (
            f"docs/forums.md must name REST forum payload key {key}"
        )
    for key in re.findall(r"'([a-z_]+)'\s*=>", topic_payload.group(1)):
        assert f"`{key}`" in text, (
            f"docs/forums.md must name REST topic payload key {key}"
        )

    admin = ROOT / "ap-admin"
    for action in _php_action_allowlist(admin / "forum-topics.php"):
        assert f"`{action}`" in text, (
            f"docs/forums.md must name Topics row action {action}"
        )
    for action in _php_action_allowlist(admin / "forum-moderation.php"):
        assert f"`{action}`" in text, (
            f"docs/forums.md must name Moderation row action {action}"
        )

    deny = "The Forum module is disabled. Enable it under Settings → Modules."
    assert deny in text
    assert deny in (admin / "forums.php").read_text(encoding="utf-8")
    assert deny in (admin / "forum-topics.php").read_text(encoding="utf-8")
    assert deny in (admin / "forum-moderation.php").read_text(encoding="utf-8")
    assert deny in (admin / "forum-groups.php").read_text(encoding="utf-8")
    assert deny in (admin / "options-forums.php").read_text(encoding="utf-8")

    empty_src = (includes / "functions.php").read_text(encoding="utf-8")
    empty_match = re.search(
        r"function ap_forum_empty_state_html.*?\n    \$allowed = \[(.*?)\];",
        empty_src,
        re.DOTALL,
    )
    assert empty_match, "ap_forum_empty_state_html allowlist not found"
    for kind in re.findall(r"'([a-z_]+)'", empty_match.group(1)):
        assert f"`{kind}`" in text, (
            f"docs/forums.md must name empty-state kind {kind}"
        )

    for needle in (
        "forum_access_level",
        "rest_forum_invalid_id",
        "rest_topic_invalid_id",
        "Forum module is disabled.",
        "ap_forum_notice",
        "ap_mark_all_forums_read",
        "does **not** read",
        "Mark all as read",
        "status=open",
        "not in core",
        "This group only",
        "group_only",
        "forum_group_only",
        "forum_access_groups",
        "rest_cannot_view",
        "You cannot view this.",
        "ap_forum_cannot_view",
        "/forums/feed/",
        "getListableForums",
        "no public Join",
    ):
        assert needle in text, f"docs/forums.md should mention: {needle}"


def test_catalog_or_linked_guides_mention_required_tokens(docs_root: Path) -> None:
    catalog = (docs_root / "features_and_functions.md").read_text(encoding="utf-8")
    for token in CATALOG_TOKENS:
        assert token in catalog, (
            f"docs/features_and_functions.md should mention: {token}"
        )


def _docs_markdown_names(docs_root: Path) -> list[str]:
    return sorted(path.name for path in docs_root.glob("*.md"))


@pytest.mark.parametrize("name", _docs_markdown_names(DOCS) if DOCS.is_dir() else [])
def test_docs_file_contains_no_private_markers(docs_root: Path, name: str) -> None:
    text = (docs_root / name).read_text(encoding="utf-8").lower()
    for banned in PRIVATE_MARKERS:
        assert banned not in text, (
            f"docs/{name} must not contain private marker: {banned}"
        )


@pytest.mark.parametrize(
    "relative",
    ("README.md", "CHANGELOG.md", "ap-config-sample.php"),
)
def test_public_product_file_contains_no_private_markers(relative: str) -> None:
    path = ROOT / relative
    assert path.is_file(), f"Missing public file: {relative}"
    text = path.read_text(encoding="utf-8").lower()
    for banned in PRIVATE_MARKERS:
        assert banned not in text, (
            f"{relative} must not contain private marker: {banned}"
        )


def _public_safe_landing_files() -> list[str]:
    names = [f"docs/{path.name}" for path in sorted(DOCS.glob("*.md"))] if DOCS.is_dir() else []
    names.extend(["README.md", "CHANGELOG.md", "ap-config-sample.php"])
    return names


def _is_allowed_public_host(host: str) -> bool:
    if host in PUBLIC_SAFE_HOSTS:
        return True
    for suffix in (".example.com", ".example.net", ".example.org"):
        if host.endswith(suffix) and len(host) > len(suffix):
            return True
    return bool(re.fullmatch(r"(?:[a-z0-9-]+\.)+example", host))


def _is_allowed_example_mailbox_domain(domain: str) -> bool:
    domain = domain.lower()
    for root in ("example.com", "example.net", "example.org"):
        if domain == root or domain.endswith("." + root):
            return True
    return False


def _assert_document_is_public_safe(relative: str, text: str) -> None:
    lower = text.lower()
    for banned in PRIVATE_MARKERS:
        assert banned not in lower, f"{relative} must not contain private marker: {banned}"
    if relative != "docs/bot_handbook.md":
        for banned in OTHER_PRODUCT_MARKERS:
            assert banned not in lower, (
                f"{relative} must not name other-product {banned} (not in core)"
            )
    for hit in ORG_HOST.findall(text):
        assert hit.lower() == "agorapress.extrovertednerd.com", (
            f"{relative} may name only the public product host, not fleet inventory ({hit})"
        )
    for domain in MAILBOX.findall(text):
        assert _is_allowed_example_mailbox_domain(domain), (
            f"{relative} mailbox must be @example.com (or .net/.org), got @{domain}"
        )
    for host in URL_HOST.findall(text):
        host = host.lower()
        if not host or "…" in host or "..." in host:
            continue
        assert _is_allowed_public_host(host), f"{relative} must not name private host {host}"
    private_paths = PRIVATE_FS_PATH.findall(text)
    assert private_paths == [], f"{relative} must not document private host paths: {private_paths}"
    for leaf in VAR_WWW.findall(text):
        assert leaf in PUBLIC_SAFE_VAR_WWW, (
            f"{relative} /var/www/{leaf} is not a shipped generic example"
        )


@pytest.mark.parametrize("relative", _public_safe_landing_files())
def test_public_landing_file_stays_public_safe(relative: str) -> None:
    """SPEC: no private hosts, persona mailboxes, or live fleet inventory."""
    path = ROOT / relative
    assert path.is_file(), f"Missing public file: {relative}"
    _assert_document_is_public_safe(relative, path.read_text(encoding="utf-8"))


CHARTER_PUBLIC_SAFE_GUIDES = (
    "admin.md",
    "themes.md",
    "editor.md",
    "troubleshooting.md",
    "features_and_functions.md",
)


@pytest.mark.parametrize("name", CHARTER_PUBLIC_SAFE_GUIDES)
def test_charter_guide_states_public_safe_rule(docs_root: Path, name: str) -> None:
    """Phase 6 guides restate: no private hosts, persona mailboxes, or fleet inventory."""
    text = (docs_root / name).read_text(encoding="utf-8")
    _assert_document_is_public_safe(f"docs/{name}", text)
    lower = text.lower()
    assert "private host" in lower, f"docs/{name} should forbid private hosts"
    assert "persona mailbox" in lower, f"docs/{name} should forbid persona mailboxes"
    assert "fleet inventory" in lower, f"docs/{name} should forbid live fleet inventory"
    assert "example.com" in text, f"docs/{name} should use generic example.com"


def test_docs_index_and_handbook_state_fleet_and_persona_rule() -> None:
    for relative in ("docs/README.md", "docs/bot_handbook.md"):
        text = (ROOT / relative).read_text(encoding="utf-8")
        lower = text.lower()
        assert "persona" in lower, f"{relative} should mention persona mailboxes as forbidden"
        assert "inventory" in lower or "fleet" in lower, (
            f"{relative} should mention live-site / fleet inventory as forbidden"
        )
        assert "private host" in lower
        assert "example.com" in text
        assert "admin@example.com" in text


def test_public_safe_mail_examples_appear_in_operator_docs() -> None:
    for relative in (
        "docs/README.md",
        "docs/bot_handbook.md",
        "docs/admin.md",
        "docs/security.md",
        "docs/install.md",
        "docs/troubleshooting.md",
        "docs/cli.md",
        "ap-config-sample.php",
    ):
        path = ROOT / relative
        assert path.is_file(), f"Missing {relative}"
        text = path.read_text(encoding="utf-8")
        assert "smtp.example.com" in text, (
            f"{relative} should use generic SMTP host smtp.example.com"
        )
        assert "noreply@example.com" in text, (
            f"{relative} should use generic from-address noreply@example.com"
        )


def test_no_parallel_docs_index(docs_root: Path) -> None:
    assert not (docs_root / "index.md").exists(), (
        "One public index only: docs/README.md (do not also create docs/index.md)"
    )


def _root_readme_documentation_section() -> str:
    text = (ROOT / "README.md").read_text(encoding="utf-8")
    match = re.search(r"(?im)^##\s+Documentation\s*$", text)
    assert match, "Root README.md must have a ## Documentation heading"
    rest = text[match.end() :]
    nxt = re.search(r"(?im)^##\s+", rest)
    return rest[: nxt.start()] if nxt else rest


def _markdown_table_text(section: str) -> str:
    lines = [ln for ln in section.splitlines() if ln.startswith("|")]
    assert lines, "README Documentation section must contain a markdown table"
    return "\n".join(lines)


def test_root_readme_documentation_table_lists_every_guide(docs_root: Path) -> None:
    """Phase 6: landing-page table lists every docs/*.md guide (not placeholders)."""
    section = _root_readme_documentation_section()
    lower = section.lower()
    assert "human landing page" in lower
    assert "second handbook" in lower
    assert "docs/index.md" in lower
    assert "docs/bot_handbook.md" in lower

    table = _markdown_table_text(section)
    assert re.search(r"(?i)\|\s*guide\s*\|\s*topic\s*\|", table), (
        "README Documentation table should have Guide / Topic columns"
    )

    names = _docs_markdown_names(docs_root)
    assert names, "docs/ should contain markdown guides"
    for name in names:
        target = f"docs/{name}"
        assert f"]({target})" in table, (
            f"README Documentation table should link to {target}"
        )

    for name in REQUIRED_NEW_PATHS:
        target = f"docs/{name}"
        assert f"]({target})" in table, (
            f"README Documentation table should list required new guide {target}"
        )


def test_root_readme_is_not_a_second_handbook(docs_root: Path) -> None:
    """Root README is the landing page; one index and one bot handbook in docs/."""
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    assert not re.search(r"(?im)^##\s+By audience\s*$", readme)
    assert not re.search(r"(?im)^###\s+New operators\s*$", readme)
    assert not re.search(r"(?im)^###\s+Trusted agent", readme)

    assert not (docs_root / "index.md").exists()
    handbooks = sorted(path.name for path in docs_root.glob("*handbook*"))
    assert handbooks == ["bot_handbook.md"], (
        "One trusted-agent handbook only: docs/bot_handbook.md "
        f"(found {handbooks})"
    )


def test_spec_success_install_from_readme_and_install_guide(docs_root: Path) -> None:
    """SPEC: a stranger can install via Docker or /install/ from README + install.md."""
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    install = (docs_root / "install.md").read_text(encoding="utf-8")
    for text in (readme, install):
        assert "docker compose" in text
        assert "/install/" in text
        assert "docker-compose.yml" in text
    assert "php install/cli.php" in install
    assert "ap-config-sample.php" in install
    assert "http://localhost:8080/install/" in readme


def test_spec_success_pretty_permalink_404_diagnosable(docs_root: Path) -> None:
    """SPEC: nginx 404 on /slug/ is diagnosable from rewrites.md + troubleshooting.md."""
    rewrites = (docs_root / "rewrites.md").read_text(encoding="utf-8")
    trouble = (docs_root / "troubleshooting.md").read_text(encoding="utf-8")
    for text in (rewrites, trouble):
        assert "try_files $uri $uri/ /index.php?$args" in text
        assert "/slug/" in text
        assert "?p=" in text
    assert "404" in trouble.lower()
    assert "mod_rewrite" in trouble
    assert "nginx" in trouble.lower()


def test_spec_success_cli_help_agrees_with_cli_doc(docs_root: Path) -> None:
    """SPEC: php ap-cli --help and docs/cli.md agree on built-ins and flags."""
    result = subprocess.run(
        ["php", str(ROOT / "ap-cli"), "--help"],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stderr
    help_text = result.stdout
    cli = (docs_root / "cli.md").read_text(encoding="utf-8")
    for group in _builtin_cli_groups():
        assert re.search(rf"^\s+{re.escape(group)}\s+", help_text, re.MULTILINE), (
            f"php ap-cli --help should list {group}"
        )
        assert re.search(rf"^\|\s+`{re.escape(group)}`\s+\|", cli, re.MULTILINE), (
            f"docs/cli.md must name ap-cli group `{group}` in the built-in table"
        )
    for flag in ("--path", "--url", "--skip-plugins", "--skip-themes"):
        assert flag in help_text
        assert flag in cli
    assert "php install/cli.php --help" in help_text
    assert "Exit codes:" in help_text
    assert "Exit codes" in cli


def test_spec_success_bot_can_answer_charter_questions(docs_root: Path) -> None:
    """SPEC: a Bot can answer the four charter questions from docs/ alone."""
    handbook = (docs_root / "bot_handbook.md").read_text(encoding="utf-8")
    lower = handbook.lower()
    assert "how do i turn forums on" in lower
    assert "/2026/09/03/hello-world/" in handbook
    assert "does core send telemetry" in lower
    assert "is gutenberg coming" in lower

    forums = (docs_root / "forums.md").read_text(encoding="utf-8")
    assert "ap_module_forum" in forums
    assert "Settings → Modules" in forums
    assert "php ap-cli option" in forums

    trouble = (docs_root / "troubleshooting.md").read_text(encoding="utf-8")
    assert "try_files $uri $uri/ /index.php?$args" in trouble
    assert "?p=" in trouble

    security = (docs_root / "security.md").read_text(encoding="utf-8")
    assert "AP_TELEMETRY" in security
    assert "no telemetry" in security.lower()
    assert "no-site-id" in security
    assert "view_site_health" in security
    assert "session.save_path" in security
    assert "php-fpm" in security.lower()

    editor = (docs_root / "editor.md").read_text(encoding="utf-8")
    assert "gutenberg" in editor.lower()
    assert "not in core" in editor.lower()
    assert "non-goal" in editor.lower()


def test_spec_success_root_readme_has_no_private_markers() -> None:
    """SPEC: public landing doc names no private host, mailbox, or Addons skin."""
    readme = (ROOT / "README.md").read_text(encoding="utf-8").lower()
    for banned in PRIVATE_MARKERS:
        assert banned not in readme, f"README.md must not contain private marker: {banned}"
