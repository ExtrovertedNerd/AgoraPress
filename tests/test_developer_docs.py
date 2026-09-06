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
    "bot_handbook.md",
    "features_and_functions.md",
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


def test_features_and_functions_catalog_is_tables_lookup(docs_root: Path) -> None:
    """Lookup catalog: Phase 0 inventory tables pointing at topic guides."""
    path = docs_root / "features_and_functions.md"
    assert path.is_file(), "Missing docs/features_and_functions.md"
    text = path.read_text(encoding="utf-8")
    assert len(text) >= 800, f"docs/features_and_functions.md too short ({len(text)} chars)"

    for heading in (
        r"(?im)^##\s+Modules\s*$",
        r"(?im)^##\s+Operator-facing options\s*$",
        r"(?im)^##\s+Roles and capabilities\s*$",
        r"(?im)^##\s+`ap-cli` verbs\s*$",
        r"(?im)^##\s+REST resources",
        r"(?im)^##\s+Admin screens",
        r"(?im)^##\s+Default Agora schemes\s*$",
        r"(?im)^##\s+Hooks\s*$",
    ):
        assert re.search(heading, text), f"features_and_functions.md missing heading: {heading}"

    for phrase in (
        "AP_DB_VERSION",
        "/ap-admin/",
        "/ap-json/",
        "rest_api_enabled",
        "analytics_enabled",
        "ap_register_admin_page",
        "ap_module_static_pages",
        "ap_module_blog",
        "ap_module_forum",
        "administrator",
        "editor",
        "author",
        "contributor",
        "subscriber",
        "edit_own_comments",
        "delete_own_comments",
        "cli info",
        "core check-update",
        "db migrate",
        "/ap/v1/posts",
        "marble",
        "parchment",
        "cloud",
        "obsidian",
        "midnight",
        "charcoal",
        "agora_color_scheme",
        "not in core",
        "hooks.md",
        "roles.md",
        "cli.md",
        "rest.md",
        "admin.md",
        "themes.md",
        "plugins.md",
        "forums.md",
        "GET only",
        "No install/zip",
        "encyclopedia",
    ):
        assert phrase in text, f"features_and_functions.md missing: {phrase}"

    for group in (
        "help",
        "version",
        "cli",
        "core",
        "db",
        "option",
        "plugin",
        "theme",
        "user",
        "post",
        "cache",
        "cron",
        "rewrite",
        "site",
    ):
        assert group in text, f"features_and_functions.md should name ap-cli group: {group}"

    for screen in (
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
        "admin.php",
    ):
        assert screen in text, f"features_and_functions.md should name ACP screen: {screen}"

    for line in text.splitlines():
        stripped = line.lstrip()
        if stripped == "" or stripped.startswith("#") or stripped.startswith("|"):
            continue
        raise AssertionError(
            f"docs/features_and_functions.md must be tables only; leftover prose: {line}"
        )

    lower = text.lower()
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, (
            f"docs/features_and_functions.md must not contain private marker: {banned}"
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
        "0.3.6-beta",
        "ap_db_version",
        "local analytics",
        "analytics_enabled",
        "standard",
        "announcement",
        "rules",
        "ap_register_admin_page",
        "plugin install",
        "schema.md",
        "cli.md",
        "editor.md",
    ):
        assert phrase in text, f"vision-compliance.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/vision-compliance.md must not contain private marker: {banned}"


def test_editor_doc_content(docs_root: Path) -> None:
    text = (docs_root / "editor.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
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
        "not in core",
        "admin.md",
        "forums.md",
        "class-ap-admin-post-edit.php",
    ):
        assert phrase in text, f"editor.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/editor.md must not contain private marker: {banned}"


def test_site_icon_doc_content(docs_root: Path) -> None:
    text = (docs_root / "site-icon.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
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
        "admin.md",
        "rewrites.md",
        "install.md",
    ):
        assert phrase in text, f"site-icon.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/site-icon.md must not contain private marker: {banned}"


HOOKS_DOC_API_FUNCTIONS = frozenset(
    {
        "ap_add_action",
        "ap_do_action",
        "ap_do_action_ref_array",
        "ap_remove_action",
        "ap_remove_all_actions",
        "ap_has_action",
        "ap_did_action",
        "ap_current_action",
        "ap_doing_action",
        "ap_add_filter",
        "ap_apply_filters",
        "ap_apply_filters_ref_array",
        "ap_remove_filter",
        "ap_remove_all_filters",
        "ap_has_filter",
        "ap_current_filter",
        "ap_doing_filter",
        "ap_reset_hooks",
        "ap_register_admin_page",
        "ap_add_cap",
        "ap_get_the_excerpt",
        "ap_nav_menu",
        "ap_print_styles",
        "ap_print_scripts",
    }
)

# Compat $hookMap values that native core does not ap_do_action / ap_apply_filters.
HOOKS_DOC_COMPAT_MAP_ONLY = frozenset(
    {
        "ap_init",
        "ap_template_redirect",
        "ap_widgets_init",
        "ap_wp",
        "ap_print_styles",
        "ap_print_scripts",
        "ap_excerpt_length",
        "ap_excerpt_more",
        "ap_nav_menu_css_class",
        "ap_nav_menu_args",
    }
)


def _product_php_files() -> list[Path]:
    files: list[Path] = []
    for folder in (ROOT / "ap-includes", ROOT / "ap-admin"):
        files.extend(folder.rglob("*.php"))
    return files


def _literal_hook_names_from_product() -> set[str]:
    """Hook names passed as string literals to ap_do_action / ap_apply_filters."""
    call = re.compile(
        r"ap_(?:do_action(?:_ref_array)?|apply_filters(?:_ref_array)?)\s*\(\s*"
        r"(?:self::[A-Z0-9_]+,\s*)?['\"]([a-zA-Z0-9_]+)['\"]"
    )
    const = re.compile(
        r"(?:ADMIN_MENU_HOOK|CRON_HOOK)\s*=\s*['\"]([a-zA-Z0-9_]+)['\"]"
    )
    names: set[str] = set()
    for path in _product_php_files():
        text = path.read_text(encoding="utf-8", errors="replace")
        names.update(call.findall(text))
        names.update(const.findall(text))
    return names


def _compat_hook_map_values() -> set[str]:
    src = ROOT / "ap-includes" / "compatibility" / "class-ap-theme-compat.php"
    text = src.read_text(encoding="utf-8")
    return set(re.findall(r"'[a-z0-9_]+'\s*=>\s*'(ap_[a-z0-9_]+)'", text))


def test_hooks_doc_content(docs_root: Path) -> None:
    text = (docs_root / "hooks.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
        "ap_add_action",
        "ap_do_action",
        "ap_add_filter",
        "ap_apply_filters",
        "ap_plugins_loaded",
        "ap_mu_plugins_loaded",
        "ap_loaded",
        "ap_after_setup_theme",
        "ap_enqueue_scripts",
        "priority",
        "ap_analytics_should_record",
        "ap_analytics_prune",
        "grep",
        "encyclopedia",
        "grep for the rest",
        "not in core",
        "ap_admin_menu",
        "ap_theme_options_register",
        "ap_site_icon_meta_tags",
        "ap_cli_init",
        "ap_rest_api_init",
        "ap_rest_enabled",
        "ap_plugin_installed",
        "ap_moderation_topic_soft_deleted",
        "map target",
        "user_has_cap",
        "plugins.md",
        "cli.md",
        "rest.md",
        "roles.md",
        "admin.md",
        "compatibility.md",
    ):
        assert phrase in text, f"hooks.md missing: {phrase}"
    assert "native core does **not** fire" in text or "native core does not fire" in text
    assert "**no** `user_has_cap`" in text or "no `user_has_cap`" in text
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/hooks.md must not contain private marker: {banned}"


def test_hooks_doc_selected_names_exist_in_code(docs_root: Path) -> None:
    """hooks.md stays selected: every named ap_* token must exist in core or be labeled."""
    doc = (docs_root / "hooks.md").read_text(encoding="utf-8")
    mentioned = set(re.findall(r"`(ap_[a-z0-9_]+)`", doc))
    literals = _literal_hook_names_from_product()
    compat = _compat_hook_map_values()
    allowed = literals | compat | HOOKS_DOC_API_FUNCTIONS

    invented: list[str] = []
    for name in sorted(mentioned):
        if name in allowed:
            continue
        if name.endswith("_") and any(
            existing.startswith(name) for existing in literals
        ):
            continue
        invented.append(name)
    assert not invented, (
        "docs/hooks.md must not invent hook names; grep ap_do_action / "
        f"ap_apply_filters for the rest. Unknown: {invented}"
    )

    selected, _, _ = doc.partition("## Grep for the rest")
    for bogus in ("ap_excerpt_length", "ap_excerpt_more"):
        for line in selected.splitlines():
            if bogus not in line or not line.lstrip().startswith("|"):
                continue
            lower = line.lower()
            assert "map" in lower or "compat" in lower or "not" in lower, (
                f"hooks.md must not list {bogus} as a native selected hook: {line}"
            )
    for bogus in ("ap_print_styles", "ap_print_scripts"):
        for line in selected.splitlines():
            if bogus not in line or not line.lstrip().startswith("|"):
                continue
            lower = line.lower()
            assert (
                "function" in lower or "map" in lower or "compat" in lower
            ), f"hooks.md must not list {bogus} as a native action: {line}"

    compat_section = doc[doc.find("## Compat map targets") :] if "## Compat map targets" in doc else ""
    assert compat_section, "hooks.md must list compat map targets native core does not fire"
    for name in HOOKS_DOC_COMPAT_MAP_ONLY:
        assert f"`{name}`" in doc, f"hooks.md should name compat-only {name}"
        assert name in compat_section or name in (
            "ap_init",
            "ap_template_redirect",
        ), f"compat-only {name} should appear in the compat-map section"

    # Selected, not an encyclopedia: grepped core has many more names than this guide.
    selected_fires = (mentioned & literals) - HOOKS_DOC_API_FUNCTIONS
    assert len(literals) > len(selected_fires) + 20, (
        "hooks.md should stay selected + grep for the rest, not dump every core hook"
    )


def test_themes_doc_content(docs_root: Path) -> None:
    text = (docs_root / "themes.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
        "style.css",
        "index.php",
        "template",
        "child",
        "ap_template_hierarchy",
        "front-page.php",
        "single.php",
        "ap_enqueue_scripts",
        "agora",
        "edit_theme_options",
        "install_themes",
        "php ap-cli theme install",
        "not in core",
        "admin.md",
        "cli.md",
        "forums.md",
        "compatibility.md",
    ):
        assert phrase in text, f"themes.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/themes.md must not contain private marker: {banned}"


def test_plugins_doc_content(docs_root: Path) -> None:
    text = (docs_root / "plugins.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
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
        "ap_plugin_installer",
        "php ap-cli plugin install",
        "ziparchive",
        "ap_plugin_installed",
        "ap_plugin_deleted",
        "rest.md",
        "admin.md",
        "cli.md",
        "roles.md",
        "security.md",
        "theme_options",
        "forums",
        "rest_api_enabled",
        "writes exist",
        "get-only",
    ):
        assert phrase in text, f"plugins.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/plugins.md must not contain private marker: {banned}"


def test_plugin_zip_installer_and_admin_page_stay_in_plugins_doc(docs_root: Path) -> None:
    """Zip installer and ap_register_admin_page stay in plugins.md; admin.md points there."""
    plugins = (docs_root / "plugins.md").read_text(encoding="utf-8")
    admin = (docs_root / "admin.md").read_text(encoding="utf-8")

    assert re.search(r"(?im)^##\s+Plugin installer\s*$", plugins)
    assert re.search(r"(?im)^##\s+Admin pages", plugins)
    for phrase in (
        "ap_register_admin_page",
        "AP_Plugin_Installer",
        "ap_install_plugin_from_zip",
        "DEFAULT_MAX_BYTES",
        "plugin-upload",
        "install_plugins",
        "ZipArchive",
        "add_options_page",
        "ap_admin_menu",
        "40 MiB",
    ):
        assert phrase in plugins, f"plugins.md is the canonical home, missing: {phrase}"

    assert re.search(r"(?im)^##\s+Plugin zip installer\s*$", admin)
    assert re.search(r"(?im)^##\s+Plugin-registered ACP pages\s*$", admin)
    assert "plugins.md#plugin-installer" in admin
    assert "plugins.md#admin-pages-settings-screens-in-the-acp" in admin
    assert "ap_register_admin_page" in admin
    assert "AP_Plugin_Installer" in admin


def test_compatibility_doc_content(docs_root: Path) -> None:
    text = (docs_root / "compatibility.md").read_text(encoding="utf-8").lower()
    for phrase in (
        "0.3.6-beta",
        "ap_db_version",
        "classic wordpress",
        "functions-shim",
        "wp_enqueue_scripts",
        "ap_enqueue_scripts",
        "theme.json",
        "cli-convert",
        "block",
        "not in core",
        "troubleshooting.md",
        "admin.md",
        "themes.md",
        "try_files $uri $uri/ /index.php?$args",
        "rewrites.md",
        "ap_init",
        "hooks.md",
    ):
        assert phrase in text, f"compatibility.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/compatibility.md must not contain private marker: {banned}"


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
        "0.3.6-beta",
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
        "0012_topic_type_enum.php",
        "no new table",
        "enum backfill",
        "standard",
        "sticky",
        "announcement",
        "rules",
        "backfill",
        "normal",
        "announce",
        "global",
        "php ap-cli db migrate",
        "forums.md",
        "cli.md",
        "not in core",
    ):
        assert phrase in text, f"schema.md missing: {phrase}"
    for banned in ("roland", "stallboy@", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in text, f"docs/schema.md must not contain private marker: {banned}"

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


def test_integrator_docs_fill_listed_gaps(docs_root: Path) -> None:
    """Phase 4 listed gaps from the docs-map inventory, kept in integrator guides."""
    schema = (docs_root / "schema.md").read_text(encoding="utf-8")
    assert "0012_topic_type_enum.php" in schema
    assert "No new table" in schema
    assert "enum backfill" in schema.lower()

    plugins = (docs_root / "plugins.md").read_text(encoding="utf-8")
    assert "rest_api_enabled" in plugins
    assert "GET-only" in plugins
    assert "ap_register_admin_page" in plugins
    assert "admin.md" in plugins
    assert "php ap-cli plugin install" in plugins.lower()

    hooks = (docs_root / "hooks.md").read_text(encoding="utf-8")
    assert "grep" in hooks.lower()
    assert "encyclopedia" in hooks.lower()
    assert "grep for the rest" in hooks.lower()
    assert "**no** `user_has_cap`" in hooks or "no `user_has_cap`" in hooks
    assert "ap_moderation_topic_soft_deleted" in hooks
    assert "ap_mu_plugins_loaded" in hooks
    assert "Native core does **not** fire" in hooks or "native core does not fire" in hooks.lower()

    rest = (docs_root / "rest.md").read_text(encoding="utf-8")
    assert "ACP Settings screen" in rest
    assert "rest_api_enabled" in rest

    compatibility = (docs_root / "compatibility.md").read_text(encoding="utf-8")
    assert "try_files $uri $uri/ /index.php?$args" in compatibility


RELATED_HEADING = re.compile(
    r"(?im)^##\s+Related(?:\s+docs|\s+documentation|\s+APIs)?\s*$"
)
RELATED_MD_LINK = re.compile(r"\]\(([^)#]+)(?:#[^)]+)?\)")

# Phase 4: integrator guides point at the operator guides; operator guides
# point back. Values are markdown link targets that must appear in the
# file's Related section (not merely as body mentions).
INTEGRATOR_OPERATOR_CROSS_LINKS = {
    "hooks.md": (
        "admin.md",
        "cli.md",
        "rest.md",
        "forums.md",
        "roles.md",
        "security.md",
        "updates.md",
        "rewrites.md",
        "install.md",
        "troubleshooting.md",
        "plugins.md",
        "themes.md",
    ),
    "themes.md": (
        "admin.md",
        "cli.md",
        "forums.md",
        "rewrites.md",
        "install.md",
        "updates.md",
        "security.md",
        "troubleshooting.md",
        "plugins.md",
        "hooks.md",
    ),
    "plugins.md": (
        "admin.md",
        "cli.md",
        "rest.md",
        "roles.md",
        "security.md",
        "install.md",
        "updates.md",
        "rewrites.md",
        "forums.md",
        "troubleshooting.md",
        "hooks.md",
        "themes.md",
        "schema.md",
    ),
    "editor.md": (
        "admin.md",
        "forums.md",
        "roles.md",
        "security.md",
        "cli.md",
        "plugins.md",
        "themes.md",
        "hooks.md",
    ),
    "site-icon.md": (
        "admin.md",
        "install.md",
        "troubleshooting.md",
        "rewrites.md",
        "security.md",
        "updates.md",
        "plugins.md",
        "themes.md",
        "hooks.md",
    ),
    "compatibility.md": (
        "admin.md",
        "troubleshooting.md",
        "rewrites.md",
        "cli.md",
        "updates.md",
        "install.md",
        "themes.md",
        "hooks.md",
        "plugins.md",
    ),
    "schema.md": (
        "install.md",
        "cli.md",
        "updates.md",
        "forums.md",
        "admin.md",
        "roles.md",
        "security.md",
        "rest.md",
        "troubleshooting.md",
        "plugins.md",
    ),
    "vision-compliance.md": (
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
        "plugins.md",
        "themes.md",
        "hooks.md",
        "site-icon.md",
        "editor.md",
        "compatibility.md",
        "schema.md",
    ),
}

OPERATOR_GUIDE_CROSS_LINKS = {
    "install.md": (
        "rewrites.md",
        "cli.md",
        "updates.md",
        "admin.md",
        "security.md",
        "troubleshooting.md",
        "schema.md",
        "forums.md",
        "roles.md",
        "rest.md",
        "site-icon.md",
        "plugins.md",
        "themes.md",
    ),
    "updates.md": (
        "install.md",
        "cli.md",
        "schema.md",
        "admin.md",
        "security.md",
        "troubleshooting.md",
        "hooks.md",
        "rewrites.md",
        "plugins.md",
        "themes.md",
    ),
    "rewrites.md": (
        "install.md",
        "cli.md",
        "rest.md",
        "forums.md",
        "security.md",
        "troubleshooting.md",
        "admin.md",
        "updates.md",
        "site-icon.md",
    ),
    "cli.md": (
        "install.md",
        "updates.md",
        "rewrites.md",
        "admin.md",
        "plugins.md",
        "themes.md",
        "roles.md",
        "schema.md",
        "rest.md",
        "troubleshooting.md",
        "hooks.md",
        "security.md",
        "forums.md",
    ),
    "admin.md": (
        "install.md",
        "rewrites.md",
        "troubleshooting.md",
        "updates.md",
        "cli.md",
        "forums.md",
        "roles.md",
        "rest.md",
        "security.md",
        "plugins.md",
        "site-icon.md",
        "editor.md",
        "themes.md",
        "schema.md",
        "hooks.md",
    ),
    "forums.md": (
        "admin.md",
        "roles.md",
        "rewrites.md",
        "rest.md",
        "schema.md",
        "themes.md",
        "hooks.md",
        "security.md",
        "troubleshooting.md",
        "editor.md",
        "install.md",
        "cli.md",
    ),
    "roles.md": (
        "admin.md",
        "forums.md",
        "cli.md",
        "rest.md",
        "security.md",
        "troubleshooting.md",
        "plugins.md",
        "schema.md",
        "hooks.md",
    ),
    "rest.md": (
        "rewrites.md",
        "troubleshooting.md",
        "security.md",
        "cli.md",
        "roles.md",
        "forums.md",
        "plugins.md",
        "hooks.md",
        "admin.md",
        "schema.md",
        "install.md",
    ),
    "security.md": (
        "install.md",
        "rewrites.md",
        "updates.md",
        "roles.md",
        "rest.md",
        "admin.md",
        "troubleshooting.md",
        "cli.md",
        "plugins.md",
        "schema.md",
        "forums.md",
    ),
    "troubleshooting.md": (
        "install.md",
        "rewrites.md",
        "updates.md",
        "cli.md",
        "admin.md",
        "forums.md",
        "roles.md",
        "rest.md",
        "security.md",
        "site-icon.md",
        "compatibility.md",
        "schema.md",
        "plugins.md",
        "themes.md",
        "hooks.md",
    ),
}

CROSS_LINK_CASES = tuple(
    (name, guides)
    for mapping in (INTEGRATOR_OPERATOR_CROSS_LINKS, OPERATOR_GUIDE_CROSS_LINKS)
    for name, guides in mapping.items()
)


def _related_section(text: str, name: str) -> str:
    match = RELATED_HEADING.search(text)
    assert match, f"docs/{name} must have a Related / Related docs section"
    return text[match.start() :]


def _related_link_targets(related: str) -> set[str]:
    return {target.split("#", 1)[0] for target in RELATED_MD_LINK.findall(related)}


@pytest.mark.parametrize("name,guides", CROSS_LINK_CASES)
def test_topic_guides_cross_link_operator_guides(
    docs_root: Path, name: str, guides: tuple[str, ...]
) -> None:
    """Integrator and operator guides cross-link in their Related sections."""
    path = docs_root / name
    text = path.read_text(encoding="utf-8")
    related = _related_section(text, name)
    targets = _related_link_targets(related)
    missing = [guide for guide in guides if guide not in targets]
    assert not missing, (
        f"docs/{name} Related section should markdown-link {missing}"
    )
    for target in targets:
        if not target.endswith(".md"):
            continue
        if target.startswith("../"):
            dest = ROOT / target[3:]
        else:
            dest = docs_root / target
        assert dest.is_file(), (
            f"docs/{name} Related section links to missing {target}"
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
