"""
Smoke tests for AP_Mail unit coverage.

Runnable via:
  pytest tests/test_mail.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAIL_CLASS = ROOT / "ap-includes" / "class-ap-mail.php"
SMTP_CLASS = ROOT / "ap-includes" / "class-ap-smtp.php"
MAIL_TEST = ROOT / "tests" / "Security" / "MailTest.php"
SMTP_TEST = ROOT / "tests" / "Security" / "SmtpTest.php"
REG_TEST = ROOT / "tests" / "User" / "RegistrationTest.php"
COMPOSER = ROOT / "composer.json"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_mail_files_exist() -> None:
    assert MAIL_CLASS.is_file(), "Missing class-ap-mail.php"
    assert SMTP_CLASS.is_file(), "Missing class-ap-smtp.php"
    assert MAIL_TEST.is_file(), "Missing MailTest.php"
    assert SMTP_TEST.is_file(), "Missing SmtpTest.php"
    assert REG_TEST.is_file(), "Missing RegistrationTest.php"


def test_mail_api_surface() -> None:
    src = MAIL_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Mail",
        "enableTestMode",
        "disableTestMode",
        "getTestOutbox",
        "clearTestOutbox",
        "failNextForTests",
        "function send",
        "sanitizeHeaderValue",
        "X-Mailer",
        "TRANSPORT_PHP",
        "TRANSPORT_SMTP",
        "@mail(",
        "sendViaSmtp",
        "sendTestToAdmin",
        "storedLastError",
        "healthSnapshot",
        "smtp_user_set",
        "smtp_pass_set",
        "mail_from_email",
        "mail_from_name",
        "mail_reply_to",
        "mail_transport",
        "AP_MAIL_FROM_NAME",
        "AP_MAIL_FROM_EMAIL",
        "AP_MAIL_TRANSPORT",
        "AP_SMTP_HOST",
        "AP_SMTP_PORT",
        "AP_SMTP_ENCRYPTION",
        "AP_SMTP_USER",
        "AP_SMTP_PASS",
        "configConstantName",
        "definedConfigConstants",
        "ap_mail_send",
        "applySendFilter",
        "consumeOutboundQuota",
        "ACTION_MAIL",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-mail.php"
    assert "PHPMailer" not in src or "No PHPMailer" in src
    assert "AP_Mail::send()" in src


def test_smtp_native_client_surface() -> None:
    src = SMTP_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_SMTP",
        "stream_socket_client",
        "AUTH PLAIN",
        "AUTH LOGIN",
        "STARTTLS",
        "STREAM_CLIENT_CONNECT",
        "ENCRYPTION_SSL",
        "ENCRYPTION_TLS",
        "setIoForTests",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-smtp.php"
    assert "new PHPMailer" not in src
    assert "use PHPMailer" not in src


def test_no_phpmailer_composer_dependency() -> None:
    import json

    data = json.loads(COMPOSER.read_text(encoding="utf-8"))
    names = list((data.get("require") or {})) + list((data.get("require-dev") or {}))
    joined = " ".join(names).lower()
    assert "phpmailer" not in joined, f"PHPMailer must not be a Composer dependency: {names}"


def test_phpunit_covers_outbox_smtp_constants_and_failed_send() -> None:
    mail = MAIL_TEST.read_text(encoding="utf-8")
    smtp = SMTP_TEST.read_text(encoding="utf-8")
    reg = REG_TEST.read_text(encoding="utf-8")
    for needle in (
        "testTestModeCapturesOutboundMail",
        "getTestOutbox",
        "testConfigConstantsOverrideOptions",
        "AP_MAIL_TRANSPORT",
        "AP_SMTP_HOST",
        "AP_SMTP_PASS",
        "testEmptyHostConstantOverridesOption",
    ):
        assert needle in mail, f"Expected {needle!r} in MailTest.php"
    for needle in (
        "setIoForTests",
        "testAuthPlainTranscript",
        "testApMailSendUsesSmtpTransportWithTranscript",
        "recorded transcript",
        "no live server",
    ):
        assert needle in smtp, f"Expected {needle!r} in SmtpTest.php"
    for needle in (
        "testFailedSendDoesNotPrintCheckYourEmailAsSuccess",
        "check your email",
        "failNextForTests",
        "mail_sent",
        "ap-notice--success",
    ):
        assert needle in reg, f"Expected {needle!r} in RegistrationTest.php"


def test_mail_phpunit_suite() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    result = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            str(MAIL_TEST),
            str(SMTP_TEST),
            str(REG_TEST),
            "--colors=never",
        ],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, (
        f"PHPUnit failed (exit {result.returncode}):\n"
        f"{result.stdout}\n{result.stderr}"
    )
