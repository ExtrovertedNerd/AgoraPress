"""
Smoke tests for topic-notify site option defaults (AP_Forum_Notify).

Runnable via:
  pytest tests/test_forum_notify_config.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NOTIFY = ROOT / "ap-includes" / "class-ap-forum-notify.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
MIGRATION = ROOT / "ap-includes" / "schema" / "migrations" / "0013_topic_subscriptions.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumNotifyConfigTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_notify_config_files_exist() -> None:
    for path in (NOTIFY, INSTALLER, BOOTSTRAP, FUNCTIONS, MIGRATION, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_class_api_and_defaults() -> None:
    src = NOTIFY.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Notify",
        "OPTION_ENABLED",
        "OPTION_MAX_PER_MINUTE",
        "forum_topic_notify_enabled",
        "forum_notify_max_per_minute",
        "DEFAULT_ENABLED",
        "DEFAULT_MAX_PER_MINUTE",
        "function isEnabled",
        "function getMaxPerMinute",
        "function sanitizeEnabled",
        "function sanitizeMaxPerMinute",
        "function updateSettings",
        "function defaultOptionMap",
        "function seedDefaults",
        "rate_limit_mail",
    ):
        assert needle in src, f"AP_Forum_Notify missing {needle!r}"

    assert "DEFAULT_ENABLED = false" in src
    assert "DEFAULT_MAX_PER_MINUTE = 4" in src


def test_bootstrap_and_functions_wiring() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-notify.php" in bootstrap

    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_forum_topic_notify_enabled" in functions
    assert "function ap_forum_notify_max_per_minute" in functions


def test_installer_seeds_options_default_off() -> None:
    installer = INSTALLER.read_text(encoding="utf-8")
    assert "forum_topic_notify_enabled" in installer
    assert "forum_notify_max_per_minute" in installer
    assert "'forum_topic_notify_enabled' => '0'" in installer
    assert "'forum_notify_max_per_minute' => '4'" in installer
    assert "rate_limit_mail" in installer


def test_migration_0013_seeds_option_defaults() -> None:
    src = MIGRATION.read_text(encoding="utf-8")
    assert "forum_topic_notify_enabled" in src
    assert "forum_notify_max_per_minute" in src
    assert "seedNotifyOptions" in src or "seedDefaults" in src
    assert "topic_track" not in src
    assert "forum_track" not in src


def test_structure_assert_lists_notify_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-forum-notify.php" in src


def test_phpunit_forum_notify_config() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit failed:\n{combined}"


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
