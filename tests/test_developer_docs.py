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


def test_docs_index_links_guides(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    for name in (
        "hooks.md",
        "themes.md",
        "plugins.md",
        "editor.md",
        "site-icon.md",
        "compatibility.md",
        "schema.md",
        "vision-compliance.md",
    ):
        assert name in index, f"docs/README.md should link to {name}"


def test_docs_index_reflects_031_beta(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    assert "0.3.5-beta" in index
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

def test_readme_links_developer_docs() -> None:
    assert README.is_file()
    text = README.read_text(encoding="utf-8")
    assert "docs/README.md" in text
    assert "docs/hooks.md" in text
    assert re.search(r"(?im)^##\s+Developer documentation\s*$", text), (
        "README should have a Developer documentation section"
    )


def test_project_layout_mentions_docs() -> None:
    text = README.read_text(encoding="utf-8")
    assert "docs/" in text or "docs/" in text.lower()
