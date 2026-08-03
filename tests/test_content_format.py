"""
Smoke tests for BBCode + Markdown + limited safe HTML formatting.

Runnable via:
  pytest tests/test_content_format.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORMAT_CLASS = ROOT / "ap-includes" / "class-ap-content-format.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
PHPUNIT = ROOT / "tests" / "Content" / "ContentFormatTest.php"
TOPIC_TPL = ROOT / "ap-content" / "themes" / "agora" / "topic.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_content_format_files_exist() -> None:
    assert FORMAT_CLASS.is_file(), "Missing class-ap-content-format.php"
    assert PHPUNIT.is_file(), "Missing ContentFormatTest.php"


def test_content_format_class_api() -> None:
    src = FORMAT_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Content_Format",
        "function format",
        "function bbcodeToHtml",
        "function markdownToHtml",
        "function kses",
        "function allowedTags",
        "function isSafeUrl",
        "MODE_AUTO",
        "MODE_BBCODE",
        "MODE_MARKDOWN",
        "MODE_HTML",
        "MODE_PLAIN",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-content-format.php"


def test_functions_expose_format_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_format_content",
        "function ap_bbcode_to_html",
        "function ap_markdown_to_html",
        "function ap_kses",
        "function ap_allowed_html",
        "function ap_is_safe_url",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_content_format() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-content-format.php" in src


def test_forum_wires_content_html() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    assert "content_html" in src
    assert "filteredContent" in src or "function filteredContent" in src
    assert "displayHtml" in src or "function displayHtml" in src


def test_agora_topic_uses_content_html() -> None:
    src = TOPIC_TPL.read_text(encoding="utf-8")
    assert "content_html" in src


def test_phpunit_content_format_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Content/ContentFormatTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit content format failed:\n{combined}"


def test_format_pipeline_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(FORMAT_CLASS))};
        require {repr(str(FUNCTIONS))};

        $bb = ap_format_content('[b]Hi[/b] [url=https://ex.test]go[/url]');
        if (!str_contains($bb, '<strong>Hi</strong>')) {{ fwrite(STDERR, "bb\\n"); exit(2); }}
        if (!str_contains($bb, 'href="https://ex.test"')) {{ fwrite(STDERR, "url\\n"); exit(3); }}

        $md = ap_format_content('**bold** and `code`', ['mode' => 'markdown']);
        if (!str_contains($md, '<strong>bold</strong>')) {{ fwrite(STDERR, "md\\n"); exit(4); }}
        if (!str_contains($md, '<code>code</code>')) {{ fwrite(STDERR, "code\\n"); exit(5); }}

        $bad = ap_format_content('<script>alert(1)</script><em>ok</em>', ['mode' => 'html']);
        if (str_contains($bad, '<script')) {{ fwrite(STDERR, "script\\n"); exit(6); }}
        if (!str_contains($bad, '<em>ok</em>')) {{ fwrite(STDERR, "em\\n"); exit(7); }}

        if (ap_is_safe_url('javascript:x')) {{ fwrite(STDERR, "js\\n"); exit(8); }}
        if (!ap_is_safe_url('https://ok.test')) {{ fwrite(STDERR, "https\\n"); exit(9); }}

        echo "ok\\n";
        """
    )
    result = subprocess.run(
        [_php_bin(), "-d", "display_errors=1", "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHP format pipeline failed:\n{combined}"
    assert "ok" in (result.stdout or "")
