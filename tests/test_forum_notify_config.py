"""
Smoke tests for topic-notify site option and user-meta defaults (AP_Forum_Notify).

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
USER = ROOT / "ap-includes" / "class-ap-user.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
MIGRATION = ROOT / "ap-includes" / "schema" / "migrations" / "0013_topic_subscriptions.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumNotifyConfigTest.php"
PHPUNIT_SUBS = ROOT / "tests" / "Forum" / "ForumNotifySubscriptionsTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_notify_config_files_exist() -> None:
    for path in (NOTIFY, INSTALLER, USER, BOOTSTRAP, FUNCTIONS, MIGRATION, PHPUNIT, PHPUNIT_SUBS):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_class_api_and_defaults() -> None:
    src = NOTIFY.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Notify",
        "OPTION_ENABLED",
        "OPTION_MAX_PER_MINUTE",
        "forum_topic_notify_enabled",
        "forum_notify_max_per_minute",
        "forum_notify_email",
        "DEFAULT_ENABLED",
        "DEFAULT_MAX_PER_MINUTE",
        "DEFAULT_USER_ENABLED",
        "META_NOTIFY_EMAIL",
        "function isEnabled",
        "function shouldShowChrome",
        "function viewerMaySubscribe",
        "function enqueueReply",
        "function send",
        "CRON_HOOK",
        "function getMaxPerMinute",
        "function isUserNotifyEnabled",
        "function setUserNotifyEnabled",
        "function enableUserNotifyOnSubscribe",
        "flip the master on",
        "function userNotifyStoredValue",
        "function seedUserDefault",
        "function sanitizeEnabled",
        "function sanitizeMaxPerMinute",
        "function updateSettings",
        "function defaultOptionMap",
        "function seedDefaults",
        "function isSubscribed",
        "function subscribe",
        "function unsubscribe",
        "function listForUser",
        "function listForUserWithTitles",
        "function deleteForUser",
        "function deleteForTopic",
        "function wantsNotifyOnCompose",
        "function maybeSubscribeFromCompose",
        "POST_NOTIFY_REPLIES",
        "topic_subscriptions",
        "rate_limit_mail",
    ):
        assert needle in src, f"AP_Forum_Notify missing {needle!r}"

    assert "DEFAULT_ENABLED = false" in src
    assert "DEFAULT_USER_ENABLED = false" in src
    assert "DEFAULT_MAX_PER_MINUTE = 4" in src
    assert "META_NOTIFY_EMAIL = 'forum_notify_email'" in src


def test_bootstrap_and_functions_wiring() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-notify.php" in bootstrap

    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_forum_topic_notify_enabled" in functions
    assert "function ap_forum_notify_should_show_chrome" in functions
    assert "function ap_forum_viewer_may_subscribe" in functions
    assert "function ap_forum_topic_subscribe_form_html" in functions
    assert "function ap_forum_notify_enqueue_reply" in functions
    assert "function ap_forum_notify_send" in functions
    assert "function ap_forum_notify_max_per_minute" in functions
    assert "function ap_forum_user_notify_enabled" in functions
    assert "function ap_forum_set_user_notify_enabled" in functions
    assert "function ap_forum_enable_user_notify_on_subscribe" in functions
    assert "function ap_forum_list_topic_subscriptions" in functions
    assert "function ap_forum_user_subscribed_to_topic" in functions
    assert "function ap_forum_subscribe_topic" in functions
    assert "function ap_forum_unsubscribe_topic" in functions
    assert "function ap_forum_notify_wants_on_compose" in functions
    assert "function ap_forum_notify_maybe_subscribe_from_compose" in functions
    assert "function ap_forum_notify_compose_checkbox_html" in functions


def test_installer_seeds_options_default_off() -> None:
    installer = INSTALLER.read_text(encoding="utf-8")
    assert "forum_topic_notify_enabled" in installer
    assert "forum_notify_max_per_minute" in installer
    assert "'forum_topic_notify_enabled' => '0'" in installer
    assert "'forum_notify_max_per_minute' => '4'" in installer
    assert "rate_limit_mail" in installer
    assert "forum_notify_email" in installer
    assert "seedUserDefault" in installer


def test_user_create_seeds_notify_meta_off() -> None:
    src = USER.read_text(encoding="utf-8")
    assert "AP_Forum_Notify::seedUserDefault" in src
    assert "forum_notify_email" in NOTIFY.read_text(encoding="utf-8")


def test_user_delete_and_topic_hard_delete_drop_subscription_rows() -> None:
    user = USER.read_text(encoding="utf-8")
    assert "AP_Forum_Notify::deleteForUser" in user
    assert "topic_subscriptions" in user

    forum = (ROOT / "ap-includes" / "class-ap-forum.php").read_text(encoding="utf-8")
    assert "AP_Forum_Notify::deleteForTopic" in forum
    assert "topic_subscriptions" in forum
    # Soft-delete must not be the only path that mentions subscriptions.
    assert "Soft-delete leaves watches" in forum or "soft-delete keeps" in forum.lower()


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
        str(PHPUNIT_SUBS),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit failed:\n{combined}"


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
