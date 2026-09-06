"""
Smoke tests for comprehensive developer documentation (Phase 7).

Runnable via:
  pytest tests/test_developer_docs.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
README = ROOT / "README.md"

REQUIRED_DOCS = (
    "README.md",
    "hooks.md",
    "themes.md",
    "plugins.md",
    "editor.md",
    "site-icon.md",
    "compatibility.md",
    "schema.md",
    "vision-compliance.md",
    "install.md",
    "rewrites.md",
    "updates.md",
    "cli.md",
    "admin.md",
    "forums.md",
    "roles.md",
    "rest.md",
    "security.md",
    "troubleshooting.md",
)


@pytest.fixture(scope="module")
def docs_root() -> Path:
    assert DOCS.is_dir(), "Missing docs/ directory"
    return DOCS


@pytest.mark.parametrize("name", REQUIRED_DOCS)
def test_doc_file_exists_and_is_substantial(docs_root: Path, name: str) -> None:
    path = docs_root / name
    assert path.is_file(), f"Missing docs/{name}"
    text = path.read_text(encoding="utf-8")
    assert len(text) >= 800, f"docs/{name} too short ({len(text)} chars)"


# Existing integrator guides plus operator/agent guides the index must point at.
INDEX_LINKED_GUIDES = (
    "hooks.md",
    "themes.md",
    "plugins.md",
    "editor.md",
    "site-icon.md",
    "compatibility.md",
    "schema.md",
    "vision-compliance.md",
    "install.md",
    "updates.md",
    "rewrites.md",
    "cli.md",
    "admin.md",
    "forums.md",
    "roles.md",
    "rest.md",
    "security.md",
    "troubleshooting.md",
    "bot_handbook.md",
    "features_and_functions.md",
)

AUDIENCE_HEADINGS = (
    r"(?im)^###\s+New operators\s*$",
    r"(?im)^###\s+Day-to-day operators\s*$",
    r"(?im)^###\s+Theme & plugin authors\s*$",
    r"(?im)^###\s+Trusted agent",
    r"(?im)^###\s+Developers\s*$",
)


def test_docs_index_links_guides(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    for name in INDEX_LINKED_GUIDES:
        assert name in index, f"docs/README.md should link to {name}"


def test_docs_index_is_audience_index(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    assert re.search(r"(?im)^#\s+AgoraPress documentation index\s*$", index)
    assert re.search(r"(?im)^##\s+By audience\s*$", index)
    assert re.search(r"(?im)^##\s+Quick mental model\s*$", index)
    assert re.search(r"(?im)^##\s+Feature map", index)
    assert re.search(r"(?im)^##\s+Source map\s*$", index)
    for pattern in AUDIENCE_HEADINGS:
        assert re.search(pattern, index), f"Expected audience heading matching: {pattern}"


def test_docs_index_states_public_safe_rule(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8").lower()
    assert "public-safe" in index or "this repository is **public**" in index or "this repository is public" in index
    assert "never write" in index
    assert "do not invent" in index
    assert "not in core" in index
    assert "bot_handbook.md" in index
    # Public docs must not name private hosts / mailboxes / process internals.
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in index, f"docs/README.md must not contain private marker: {banned}"


def test_bot_handbook_states_public_safe_rule(docs_root: Path) -> None:
    path = docs_root / "bot_handbook.md"
    assert path.is_file(), "Missing docs/bot_handbook.md"
    text = path.read_text(encoding="utf-8")
    assert len(text) >= 800, f"docs/bot_handbook.md too short ({len(text)} chars)"
    lower = text.lower()
    assert "public-safe" in lower or "this repository is **public**" in lower or "this repository is public" in lower
    assert "never write" in lower
    assert "do not invent" in lower
    assert "not in core" in lower
    assert "do not invent surfaces" in lower or "do **not invent**" in lower
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/bot_handbook.md must not contain private marker: {banned}"


def test_no_parallel_docs_index(docs_root: Path) -> None:
    assert not (docs_root / "index.md").exists(), (
        "One public index only: docs/README.md (do not also create docs/index.md)"
    )


def test_docs_index_reflects_031_beta(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    assert "0.3.6-beta" in index
    assert "AP_Analytics" in index or "analytics" in index.lower()
    assert "class-ap-analytics.php" in index


def test_vision_compliance_doc_content(docs_root: Path) -> None:
    text = (docs_root / "vision-compliance.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "free forever",
        "no telemetry",
        "classic wordpress theme compatibility",
        "intentional deviations",
        "ap_telemetry",
        "three independent modules",
        "0.2.0-beta",
        "local analytics",
        "analytics_enabled",
    ):
        assert phrase in text, f"vision-compliance.md missing: {phrase}"


def test_editor_doc_content(docs_root: Path) -> None:
    text = (docs_root / "editor.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "ap_editor",
        "classic",
        "visual",
        "textarea",
        "contenteditable",
        "non-goal",
        "block",
        "lightweight",
        "no jquery",
        "ap_content_format",
    ):
        assert phrase in text, f"editor.md missing: {phrase}"


def test_site_icon_doc_content(docs_root: Path) -> None:
    text = (docs_root / "site-icon.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "site_icon",
        "settings → general",
        "ap_media",
        "generatesiteiconsizes",
        "32",
        "180",
        "192",
        "512",
        "ico",
        "ap_head",
        "apple-touch-icon",
        "ap_site_icon_meta_tags",
        "favicon.ico",
        "manage_options",
        "gd",
        "imagick",
    ):
        assert phrase in text, f"site-icon.md missing: {phrase}"


def test_hooks_doc_content(docs_root: Path) -> None:
    text = (docs_root / "hooks.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "ap_add_action",
        "ap_do_action",
        "ap_add_filter",
        "ap_apply_filters",
        "ap_plugins_loaded",
        "ap_loaded",
        "ap_after_setup_theme",
        "ap_enqueue_scripts",
        "priority",
        "ap_analytics_should_record",
        "ap_analytics_prune",
    ):
        assert phrase in text, f"hooks.md missing: {phrase}"


def test_themes_doc_content(docs_root: Path) -> None:
    text = (docs_root / "themes.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "style.css",
        "index.php",
        "template",
        "child",
        "ap_template_hierarchy",
        "front-page.php",
        "single.php",
        "ap_enqueue_scripts",
        "agora",
    ):
        assert phrase in text, f"themes.md missing: {phrase}"


def test_plugins_doc_content(docs_root: Path) -> None:
    text = (docs_root / "plugins.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "plugin name",
        "active_plugins",
        "ap_activate_plugin",
        "ap_register_activation_hook",
        "mu-plugins",
        "ap_add_shortcode",
        "ap_register_setting",
        "ap_rest_api_init",
        # ACP admin page registration (settings screens in the Control Panel)
        "ap_register_admin_page",
        "admin.php?page=",
        "ap_admin_menu",
        "add_options_page",
        "manage_options",
        "ap_admin::pageurl",
    ):
        assert phrase in text, f"plugins.md missing: {phrase}"


def test_compatibility_doc_content(docs_root: Path) -> None:
    text = (docs_root / "compatibility.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "classic wordpress",
        "functions-shim",
        "wp_enqueue_scripts",
        "ap_enqueue_scripts",
        "theme.json",
        "cli-convert",
        "block",
    ):
        assert phrase in text, f"compatibility.md missing: {phrase}"


def test_install_doc_content(docs_root: Path) -> None:
    path = docs_root / "install.md"
    assert path.is_file(), "Missing docs/install.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "/install/",
        "php install/cli.php",
        "--db-driver",
        "--site-title",
        "--site-url",
        "--admin-user",
        "--admin-email",
        "--admin-password",
        "--table-prefix",
        "--config-path",
        "--skip-requirements",
        "--sample-content",
        "--no-sample-content",
        "ap_admin_password",
        "ap_db_password",
        "docker compose",
        "ap-config-sample.php",
        "ap-config.php",
        "ap-content/",
        "uploads",
        "settings → modules",
        "permalinks",
        "site health",
        "analytics_enabled",
        "ap_db_version",
        "session.save_path",
        "/ap-admin/",
        "0.3.6-beta",
        "exit codes",
    ):
        assert phrase in lower, f"install.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/install.md must not contain private marker: {banned}"


def test_rewrites_doc_content(docs_root: Path) -> None:
    path = docs_root / "rewrites.md"
    assert path.is_file(), "Missing docs/rewrites.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    assert "try_files $uri $uri/ /index.php" in text
    for phrase in (
        "try_files $uri $uri/ /index.php?$args",
        ".htaccess",
        "docker/nginx.conf.example",
        "index.php",
        "front controller",
        "?p=",
        "?page_id=",
        "day and name",
        "/yyyy/mm/dd/",
        "/slug/",
        "php ap-cli rewrite flush",
        "permalink_structure",
        "settings → permalinks",
        "favicon.ico",
        "allowoverride all",
        "mod_rewrite",
        "ap_rewrite",
        "rewrite_rules",
        "0.3.6-beta",
        "ap_db_version",
    ):
        assert phrase in lower, f"rewrites.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/rewrites.md must not contain private marker: {banned}"


def test_updates_doc_content(docs_root: Path) -> None:
    path = docs_root / "updates.md"
    assert path.is_file(), "Missing docs/updates.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "version.json",
        "https://agorapress.extrovertednerd.com/version.json",
        "tools → update core",
        "php bin/package-release.php",
        "php ap-cli core check-update",
        "php ap-cli db migrate",
        "ap-config.php",
        "ap-config-sample.php",
        "install/",
        "ap-content/uploads/",
        "ap-content/plugins/",
        "custom themes",
        "no site identity",
        "sha256",
        "agoraPress-{version}.zip".lower(),
        "version_check_enabled",
        "update_core",
        "ap_core_updater",
        "ap_version_check",
        "--force",
        "not in core",
        "0.3.6-beta",
        "ap_db_version",
        "ziparchive",
        ".maintenance",
    ):
        assert phrase in lower, f"updates.md missing: {phrase}"
    assert "php ap-cli core update" in lower or "core update" in lower
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/updates.md must not contain private marker: {banned}"


def test_cli_doc_content(docs_root: Path) -> None:
    path = docs_root / "cli.md"
    assert path.is_file(), "Missing docs/cli.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "php ap-cli --help",
        "--path",
        "--url",
        "--skip-plugins",
        "--skip-themes",
        "exit_ok",
        "exit_usage",
        "exit_error",
        "exit_not_installed",
        "php install/cli.php",
        "cli info",
        "core check-update",
        "core version",
        "db check",
        "db migrate",
        "option get",
        "option set",
        "option delete",
        "option list",
        "plugin list",
        "plugin activate",
        "plugin deactivate",
        "theme list",
        "theme activate",
        "user list",
        "user get",
        "user create",
        "post list",
        "post get",
        "post create",
        "post update",
        "cache flush",
        "cron event list",
        "cron event run",
        "rewrite flush",
        "site health",
        "--file",
        "remote urls",
        "stream wrappers",
        "draft",
        "publish",
        "ap_user_password",
        "ap_cli_init",
        "not in core",
        "0.3.6-beta",
        "ap_db_version",
        "php ap-cli core update",
    ):
        assert phrase in lower, f"cli.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/cli.md must not contain private marker: {banned}"


def test_admin_doc_content(docs_root: Path) -> None:
    path = docs_root / "admin.md"
    assert path.is_file(), "Missing docs/admin.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "/ap-admin/",
        "login.php",
        "index.php",
        "edit.php",
        "post.php",
        "post-new.php",
        "revision.php",
        "edit-comments.php",
        "comment.php",
        "edit-tags.php",
        "media.php",
        "media-new.php",
        "upload.php",
        "nav-menus.php",
        "widgets.php",
        "themes.php",
        "theme-options.php",
        "plugins.php",
        "users.php",
        "user-new.php",
        "user-edit.php",
        "profile.php",
        "forums.php",
        "forum-edit.php",
        "forum-groups.php",
        "forum-moderation.php",
        "forum-topics.php",
        "options-general.php",
        "options-writing.php",
        "options-reading.php",
        "options-discussion.php",
        "options-media.php",
        "options-permalink.php",
        "options-privacy.php",
        "options-modules.php",
        "options-forums.php",
        "options-hall-of-fame.php",
        "analytics.php",
        "site-health.php",
        "update-core.php",
        "import.php",
        "export-personal-data.php",
        "erase-personal-data.php",
        "admin.php?page=",
        "ap_register_admin_page",
        "ap_admin_menu",
        "ap_plugin_installer",
        "plugin name",
        "ziparchive",
        "install_plugins",
        "activate_plugins",
        "hall of fame",
        "handshake",
        "agorapress-hof-",
        "hall_of_fame_status",
        "challenge",
        "proof",
        "donate",
        "paywall",
        "https://agorapress.extrovertednerd.com/donate",
        "manage_options",
        "manage_forums",
        "moderate_forums",
        "update_core",
        "view_site_health",
        "analytics_enabled",
        "rest_api_enabled",
        "site_icon",
        "settings → modules",
        "settings → general",
        "tools → update core",
        "wxr",
        "phpbb",
        "session.save_path",
        "not in core",
        "gutenberg",
        "marketplace",
        "0.3.6-beta",
        "ap_db_version",
    ):
        assert phrase in lower, f"admin.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/admin.md must not contain private marker: {banned}"


def test_forums_doc_content(docs_root: Path) -> None:
    path = docs_root / "forums.md"
    assert path.is_file(), "Missing docs/forums.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
        "ap_module_forum",
        "settings → modules",
        "ap_forum",
        "ap_forum_front",
        "ap_forum_permissions",
        "ap_forum_moderation",
        "ap_forum_like",
        "ap_forum_stats",
        "ap_forum_guard",
        "ap_forum_read",
        "ap_forum_attachment",
        "ap_group",
        "ap_private_message",
        "ap_online",
        "/forums/",
        "/topic/",
        "ap_forum_view",
        "forum.php",
        "forum-view.php",
        "topic.php",
        "forum-search.php",
        "category",
        "standard",
        "sticky",
        "announcement",
        "rules",
        "two-pane",
        "ap-forum-post--two-pane",
        "forum_post_likes",
        "like_count",
        "manage_forums",
        "moderate_forums",
        "view_forum",
        "public",
        "members only",
        "forum_flood_interval",
        "forum_search_enabled",
        "forum_online_enabled",
        "forum_unread_tracking_enabled",
        "forum_private_messaging_enabled",
        "forum_attachments_enabled",
        "topic_track",
        "forum_track",
        "total topics",
        "rest_module_disabled",
        "/ap-json/ap/v1/forums",
        "php ap-cli forum",
        "not in core",
        "options-forums.php",
        "forum-moderation.php",
        "forum-groups.php",
    ):
        assert phrase in lower, f"forums.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/forums.md must not contain private marker: {banned}"


def test_roles_doc_content(docs_root: Path) -> None:
    path = docs_root / "roles.md"
    assert path.is_file(), "Missing docs/roles.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
        "ap_roles",
        "ap_user_roles",
        "ap_capabilities",
        "ap_user_level",
        "default_role",
        "administrator",
        "editor",
        "author",
        "contributor",
        "subscriber",
        "read",
        "manage_options",
        "list_users",
        "promote_users",
        "edit_posts",
        "upload_files",
        "moderate_comments",
        "edit_own_comments",
        "delete_own_comments",
        "manage_categories",
        "moderate_forums",
        "manage_forums",
        "view_site_health",
        "export_others_personal_data",
        "edit_comment",
        "delete_comment",
        "mapmetacap",
        "ap_user_can",
        "ap_current_user_can",
        "ap_add_role",
        "ap_add_cap",
        "ap_forum_permissions",
        "ap_group",
        "view_forum",
        "edit_own",
        "delete_own",
        "guests",
        "registered",
        "global_moderators",
        "user_has_cap",
        "php ap-cli role",
        "not in core",
        "users.php",
        "user-edit.php",
        "php ap-cli user create",
        "example.com",
    ):
        assert phrase in lower, f"roles.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/roles.md must not contain private marker: {banned}"


def test_rest_doc_content(docs_root: Path) -> None:
    path = docs_root / "rest.md"
    assert path.is_file(), "Missing docs/rest.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
        "/ap-json/",
        "?rest_route=",
        "ap/v1",
        "rest_api_enabled",
        "x-ap-nonce",
        "_ap_nonce",
        "ap_rest",
        "http basic",
        "cookie",
        "ap_rest",
        "rest_disabled",
        "rest_no_route",
        "rest_module_disabled",
        "rest_cookie_invalid_nonce",
        "rest_not_logged_in",
        "php ap-cli option set rest_api_enabled",
        "/ap/v1/posts",
        "/ap/v1/pages",
        "/ap/v1/comments",
        "/ap/v1/users",
        "/ap/v1/categories",
        "/ap/v1/tags",
        "/ap/v1/forums",
        "/ap/v1/topics",
        "post",
        "put",
        "patch",
        "delete",
        "ap_rest_api_init",
        "ap_register_rest_route",
        "ap_create_rest_nonce",
        "ap_module_blog",
        "edit_posts",
        "list_users",
        "try_files $uri $uri/ /index.php?$args",
        "application/json",
        "not in core",
        "application passwords",
        "oauth",
        "jwt",
        "example.com",
        "admin@example.com",
    ):
        assert phrase in lower, f"rest.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/rest.md must not contain private marker: {banned}"


def test_security_doc_content(docs_root: Path) -> None:
    path = docs_root / "security.md"
    assert path.is_file(), "Missing docs/security.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "prepared statements",
        "ap_db",
        "pdo",
        "ap_nonce",
        "_ap_nonce",
        "x-ap-nonce",
        "argon2id",
        "password_argon2id",
        "rate_limit",
        "session.save_path",
        "php-fpm",
        "770",
        "ap_telemetry",
        "no-site-id",
        "version.json",
        "hall of fame",
        "export-personal-data",
        "erase-personal-data",
        "wp_page_for_privacy_policy",
        "analytics_enabled",
        "rest_api_enabled",
        "ap-config.php",
        ".env",
        "sqlite",
        "ap-includes",
        ".htaccess",
        "docker/nginx.conf.example",
        "try_files $uri $uri/ /index.php?$args",
        "ap_logged_in_key",
        "ap_nonce_salt",
        "not in core",
        "2fa",
        "0.3.6-beta",
        "ap_db_version",
    ):
        assert phrase in lower, f"security.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/security.md must not contain private marker: {banned}"


def test_troubleshooting_doc_content(docs_root: Path) -> None:
    path = docs_root / "troubleshooting.md"
    assert path.is_file(), "Missing docs/troubleshooting.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    for phrase in (
        "try_files $uri $uri/ /index.php",
        "try_files $uri $uri/ /index.php?$args",
        "?p=",
        "mod_rewrite",
        "php ap-cli rewrite flush",
        "session.save_path",
        "php-fpm",
        "security token",
        "ap-content/uploads/",
        "site icon",
        "settings → modules",
        "ap_module_static_pages",
        "ap_module_blog",
        "ap_module_forum",
        "compatibility.md",
        "block",
        "fse",
        "rest_api_enabled",
        "/ap-json/",
        "?rest_route=",
        "rest_disabled",
        "0.3.2",
        "0.3.6",
        "edit user",
        "getbyid",
        "comment_ok",
        "site health",
        "not in core",
        "0.3.6-beta",
        "ap_db_version",
    ):
        assert phrase in lower, f"troubleshooting.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, f"docs/troubleshooting.md must not contain private marker: {banned}"
    # Public-safe: do not name private accounts even without '@'.
    assert "stallboy" not in lower, "docs/troubleshooting.md must not name private accounts"


def test_schema_doc_content(docs_root: Path) -> None:
    text = (docs_root / "schema.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "ap_db_version",
        "schema_migrations",
        "options",
        "users",
        "posts",
        "postmeta",
        "terms",
        "comments",
        "forums",
        "topics",
        "forum_posts",
        "forum_permissions",
        "topic_track",
        "analytics_hits",
        "analytics_daily",
        "utf8mb4",
        "ap_",
    ):
        assert phrase in text, f"schema.md missing: {phrase}"

# Root README Documentation table: existing integrator guides plus the
# operator/agent guides (rows may land before the topic files exist).
README_DOCUMENTATION_TABLE_GUIDES = (
    "docs/README.md",
    "docs/install.md",
    "docs/rewrites.md",
    "docs/updates.md",
    "docs/cli.md",
    "docs/admin.md",
    "docs/forums.md",
    "docs/roles.md",
    "docs/rest.md",
    "docs/security.md",
    "docs/troubleshooting.md",
    "docs/bot_handbook.md",
    "docs/features_and_functions.md",
    "docs/hooks.md",
    "docs/themes.md",
    "docs/plugins.md",
    "docs/editor.md",
    "docs/site-icon.md",
    "docs/compatibility.md",
    "docs/schema.md",
    "docs/vision-compliance.md",
)


def test_readme_links_developer_docs() -> None:
    assert README.is_file()
    text = README.read_text(encoding="utf-8")
    assert re.search(r"(?im)^##\s+Documentation\s*$", text), (
        "README should have a Documentation section"
    )
    assert "human landing page" in text.lower()
    assert "second handbook" in text.lower()
    assert "docs/index.md" in text.lower()
    for name in README_DOCUMENTATION_TABLE_GUIDES:
        assert f"]({name})" in text, (
            f"README Documentation table should link to {name}"
        )


def test_readme_is_not_a_second_handbook() -> None:
    """Audience index lives in docs/README.md, not the root landing page."""
    text = README.read_text(encoding="utf-8")
    assert not re.search(r"(?im)^##\s+By audience\s*$", text)
    assert not re.search(r"(?im)^###\s+New operators\s*$", text)
    assert not re.search(r"(?im)^###\s+Trusted agent", text)


def test_project_layout_mentions_docs() -> None:
    text = README.read_text(encoding="utf-8")
    assert "docs/" in text or "docs/" in text.lower()
