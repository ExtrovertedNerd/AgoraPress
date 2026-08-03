"""
Smoke tests for escaping / sanitization helpers and static audit.

Runnable via:
  pytest tests/test_escaping.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORMATTING = ROOT / "ap-includes" / "class-ap-formatting.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
COMPAT = ROOT / "ap-includes" / "compatibility" / "functions-shim.php"
PHPUNIT = ROOT / "tests" / "Security" / "FormattingTest.php"

SCAN_DIRS = ("ap-admin", "ap-includes", "ap-content/themes", "install")
SUPERGLOBAL_ECHO_RE = re.compile(
    r"\b(?:echo|print)\s*\(?\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\b"
)


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_formatting_core_files_exist() -> None:
    for path in (FORMATTING, FUNCTIONS, BOOTSTRAP, COMPAT, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_formatting_api_surface() -> None:
    src = FORMATTING.read_text(encoding="utf-8")
    for needle in (
        "class AP_Formatting",
        "function escHtml",
        "function escAttr",
        "function escUrl",
        "function escUrlRaw",
        "function escJs",
        "function escXml",
        "function sanitizeTextField",
        "function sanitizeEmail",
        "function sanitizeKey",
        "function sanitizeFileName",
        "function sanitizeHexColor",
        "function sanitizeUser",
        "function absint",
        "function stripAllTags",
        "function allowedProtocols",
        "function cleanUrl",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-formatting.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_esc_html",
        "function ap_esc_attr",
        "function ap_esc_url",
        "function ap_esc_url_raw",
        "function ap_esc_js",
        "function ap_esc_xml",
        "function ap_sanitize_text_field",
        "function ap_sanitize_email",
        "function ap_sanitize_key",
        "function ap_sanitize_file_name",
        "function ap_sanitize_hex_color",
        "function ap_sanitize_user",
        "function ap_absint",
        "function ap_strip_all_tags",
        "function ap_allowed_protocols",
        "class-ap-formatting.php",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-formatting.php" in boot


def test_compat_shims_cover_new_helpers() -> None:
    src = COMPAT.read_text(encoding="utf-8")
    for needle in (
        "function esc_js",
        "function esc_xml",
        "function esc_url_raw",
        "function sanitize_email",
        "function sanitize_key",
        "function sanitize_file_name",
        "function sanitize_hex_color",
        "function sanitize_user",
        "function absint",
        "function wp_strip_all_tags",
    ):
        assert needle in src, f"Expected {needle!r} in functions-shim.php"


def test_no_direct_superglobal_echo() -> None:
    hits: list[str] = []
    for rel in SCAN_DIRS:
        base = ROOT / rel
        if not base.is_dir():
            continue
        for path in base.rglob("*.php"):
            if "vendor" in path.parts:
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            for m in SUPERGLOBAL_ECHO_RE.finditer(text):
                line = text.count("\n", 0, m.start()) + 1
                hits.append(f"{path.relative_to(ROOT)}:{line}")
    assert hits == [], "Direct echo of superglobals:\n" + "\n".join(hits)


def test_php_esc_url_rejects_javascript() -> None:
    script = r"""
    require 'ap-includes/class-ap-formatting.php';
    require 'ap-includes/functions.php';
    $bad = ['javascript:alert(1)', 'data:text/html,x', 'vbscript:x'];
    foreach ($bad as $u) {
        if (ap_esc_url($u) !== '') {
            fwrite(STDERR, "FAIL: " . $u . " => " . ap_esc_url($u) . PHP_EOL);
            exit(1);
        }
    }
    $good = ap_esc_url('https://example.com/?a=1&b=2');
    if ($good !== 'https://example.com/?a=1&amp;b=2') {
        fwrite(STDERR, "FAIL good: " . $good . PHP_EOL);
        exit(1);
    }
    if (ap_sanitize_email('User@Example.COM') !== 'user@example.com') {
        fwrite(STDERR, "FAIL email\n");
        exit(1);
    }
    if (ap_absint(-3) !== 0 || ap_absint('9') !== 9) {
        fwrite(STDERR, "FAIL absint\n");
        exit(1);
    }
    echo "ok\n";
    """
    result = subprocess.run(
        [_php_bin(), "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, (result.stdout or "") + (result.stderr or "")
    assert "ok" in (result.stdout or "")


def test_phpunit_formatting() -> None:
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
    result = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
