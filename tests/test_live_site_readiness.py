"""
Smoke tests: AgoraPress is ready for a live-site / production install.

Runnable via:
  pytest tests/test_live_site_readiness.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HTACCESS = ROOT / ".htaccess"
CONTENT_HTACCESS = ROOT / "ap-content" / ".htaccess"
NGINX = ROOT / "docker" / "nginx.conf.example"
GITIGNORE = ROOT / ".gitignore"
PACKAGE = ROOT / "bin" / "package-release.php"
README = ROOT / "README.md"
CHANGELOG = ROOT / "CHANGELOG.md"
INSTALL_UI = ROOT / "install" / "index.php"


def test_root_htaccess_production_hardening() -> None:
    ht = HTACCESS.read_text(encoding="utf-8")
    assert "RewriteEngine On" in ht
    assert "index.php" in ht
    assert "ap-config" in ht
    assert "sqlite" in ht.lower()
    assert "ap-includes" in ht
    assert re.search(r"ap-includes/\(css\|js\)", ht)
    assert "[F,L]" in ht


def test_content_htaccess_blocks_sqlite() -> None:
    assert CONTENT_HTACCESS.is_file()
    ht = CONTENT_HTACCESS.read_text(encoding="utf-8")
    assert "sqlite" in ht.lower()
    assert "Options -Indexes" in ht


def test_nginx_example_hardens_production() -> None:
    nginx = NGINX.read_text(encoding="utf-8")
    assert "try_files" in nginx
    assert "sqlite" in nginx.lower()
    assert "ap-includes" in nginx
    assert "deny all" in nginx
    assert "ap-config" in nginx


def test_gitignore_covers_sqlite() -> None:
    gi = GITIGNORE.read_text(encoding="utf-8")
    assert "*.sqlite" in gi or "database.sqlite" in gi
    assert "ap-config.php" in gi


def test_package_release_excludes_sqlite() -> None:
    src = PACKAGE.read_text(encoding="utf-8")
    assert "sqlite" in src.lower()
    assert "ap-config.php" in src


def test_readme_production_install_section() -> None:
    readme = README.read_text(encoding="utf-8")
    assert re.search(r"(?im)^###\s+Production install \(live site\)\s*$", readme)
    assert "ready for live-site install" in readme
    assert "Site Health" in readme
    assert "AP_DEBUG" in readme


def test_changelog_mentions_live_site_readiness() -> None:
    changelog = CHANGELOG.read_text(encoding="utf-8")
    assert "Live-site install readiness" in changelog


def test_installer_mentions_sqlite_download_protection() -> None:
    ui = INSTALL_UI.read_text(encoding="utf-8")
    assert "production" in ui.lower()
    assert "sqlite" in ui.lower()
