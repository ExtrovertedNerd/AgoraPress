"""
Smoke tests for BBCode + Markdown + limited safe HTML formatting.

Runnable via:
  pytest tests/test_content_format.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORMAT_CLASS = ROOT / "ap-includes" / "class-ap-content-format.php"
SPOILER_CSS = ROOT / "ap-includes" / "css" / "ap-spoiler.css"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
PHPUNIT = ROOT / "tests" / "Content" / "ContentFormatTest.php"
SPOILER_STYLE_PHPUNIT = ROOT / "tests" / "Content" / "SpoilerStyleTest.php"
SPOILER_DARK_FIXTURE = ROOT / "tests" / "Content" / "fixtures" / "spoiler-dark-no-agora.html"
SPOILER_LIGHT_FIXTURE = ROOT / "tests" / "Content" / "fixtures" / "spoiler-light-no-agora.html"
TOPIC_TPL = ROOT / "ap-content" / "themes" / "agora" / "topic.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_content_format_files_exist() -> None:
    assert FORMAT_CLASS.is_file(), "Missing class-ap-content-format.php"
    assert PHPUNIT.is_file(), "Missing ContentFormatTest.php"
    assert SPOILER_CSS.is_file(), "Missing ap-spoiler.css"
    assert SPOILER_STYLE_PHPUNIT.is_file(), "Missing SpoilerStyleTest.php"
    assert SPOILER_DARK_FIXTURE.is_file(), "Missing spoiler-dark-no-agora.html"
    assert SPOILER_LIGHT_FIXTURE.is_file(), "Missing spoiler-light-no-agora.html"


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
        "'details'",
        "'summary'",
        "MODE_AUTO",
        "MODE_BBCODE",
        "MODE_MARKDOWN",
        "MODE_HTML",
        "MODE_PLAIN",
        "STYLE_HANDLE",
        "function registerAssets",
        "function enqueueAssets",
        "function printStyle",
        "function stripSpoilers",
        "SPOILER_PLACEHOLDER",
        "ap-spoiler.css",
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
        "function ap_strip_spoilers",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_content_format() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-content-format.php" in src
    assert "registerAssets" in src


def test_forum_wires_content_html() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    assert "content_html" in src
    assert "filteredContent" in src or "function filteredContent" in src
    assert "displayHtml" in src or "function displayHtml" in src


def test_agora_topic_uses_content_html() -> None:
    src = TOPIC_TPL.read_text(encoding="utf-8")
    assert "content_html" in src


def test_spoiler_css_contract() -> None:
    """Closed body unreadable; open follows color-scheme; native keyboard; no images."""
    css = SPOILER_CSS.read_text(encoding="utf-8")
    assert ".ap-spoiler" in css
    assert ".ap-spoiler:not([open]) > .ap-spoiler__body" in css
    assert "display: none !important" in css
    assert ".ap-spoiler:not([open])::details-content" in css
    assert ".ap-spoiler[open] > .ap-spoiler__body" in css
    assert ".ap-spoiler[open]::details-content" in css
    assert "color-scheme: inherit" in css
    assert "color-scheme: dark" in css
    assert "display: list-item" in css
    assert "list-style-type: disclosure-closed" in css
    assert ":focus-visible" in css
    assert "cursor: pointer" in css
    stripped = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    assert "url(" not in stripped
    assert "background-image" not in stripped
    assert ":hover" not in stripped
    assert "prefers-color-scheme" not in stripped
    assert "list-style: none" not in stripped
    assert "list-style-type: none" not in stripped
    assert "ap-spoiler.js" not in stripped


def test_spoiler_spec_minimum_phpunit_cases_exist() -> None:
    """SPEC: format; empty body; default label; excerpt/feed do not leak; toolbar."""
    cases = (
        ("tests/Content/ContentFormatTest.php", "testSpoilerFormatMarkupToDetails"),
        ("tests/Content/ContentFormatTest.php", "testSpoilerDefaultLabelAndTitleAttribute"),
        ("tests/Content/ContentFormatTest.php", "testSpoilerEmptyBodyRendersNothing"),
        ("tests/Content/ContentFormatTest.php", "testStripSpoilersBbcodeAndHtmlDoNotLeakInnerText"),
        ("tests/Template/TemplateTagsTest.php", "testExcerptStripsSpoilerInnerText"),
        ("tests/Feed/FeedTest.php", "testRssAndAtomStripSpoilerInnerText"),
        ("tests/Editor/EditorTest.php", "testSpoilerButtonWrapsVisualAndInsertsShortcodeInText"),
        ("tests/Editor/EditorTest.php", "testSpoilerToolbarIsSharedOnPostPageCommentForum"),
    )
    for relative, method in cases:
        path = ROOT / relative
        assert path.is_file(), f"Missing {relative}"
        src = path.read_text(encoding="utf-8")
        needle = f"function {method}"
        assert needle in src, f"Expected {needle!r} in {relative}"


def test_spoiler_format_empty_body_and_default_label() -> None:
    """[spoiler] → details; empty body → nothing; default label Spoiler; strip does not leak."""
    script = r"""
declare(strict_types=1);
require_once __DIR__ . '/ap-includes/class-ap-content-format.php';
require_once __DIR__ . '/ap-includes/functions.php';

$block = static function (string $label, string $body): string {
    return '<details class="ap-spoiler">'
        . '<summary class="ap-spoiler__summary">' . $label . '</summary>'
        . '<div class="ap-spoiler__body">' . $body . '</div>'
        . '</details>';
};

$plain = AP_Content_Format::format('[spoiler]hidden plot[/spoiler]');
if (!str_contains($plain, $block('Spoiler', 'hidden plot'))) {
    fwrite(STDERR, "default format\n"); exit(1);
}
if (str_contains($plain, '[spoiler]')) { fwrite(STDERR, "raw leftover\n"); exit(2); }

$eq = AP_Content_Format::format('[spoiler=Ending]they lived[/spoiler]');
if (!str_contains($eq, $block('Ending', 'they lived'))) {
    fwrite(STDERR, "eq format\n"); exit(3);
}

$attr = AP_Content_Format::format('[spoiler title="Finale"]they lived[/spoiler]');
if (!str_contains($attr, $block('Finale', 'they lived'))) {
    fwrite(STDERR, "title format\n"); exit(4);
}

$empty = AP_Content_Format::format('[spoiler][/spoiler]');
if (str_contains($empty, '<details') || str_contains($empty, 'ap-spoiler')) {
    fwrite(STDERR, "empty body\n"); exit(5);
}

$blank = AP_Content_Format::format("[spoiler=Label]\n  \n[/spoiler]");
if (str_contains($blank, '<details') || str_contains($blank, 'Label')) {
    fwrite(STDERR, "blank body\n"); exit(6);
}

$titledEmpty = AP_Content_Format::format('[spoiler title="Nope"]   [/spoiler]');
if (str_contains($titledEmpty, '<details') || str_contains($titledEmpty, 'Nope')) {
    fwrite(STDERR, "titled empty\n"); exit(7);
}

$stripped = ap_strip_spoilers('Safe [spoiler]leaked secret[/spoiler] text');
if ($stripped !== 'Safe [Spoiler] text') { fwrite(STDERR, "strip\n"); exit(8); }
if (str_contains($stripped, 'leaked secret')) { fwrite(STDERR, "leak\n"); exit(9); }

$html = AP_Content_Format::format('[spoiler=Ending]they lived[/spoiler] after');
$fromHtml = AP_Content_Format::stripSpoilers($html);
if (str_contains($fromHtml, 'they lived')) { fwrite(STDERR, "html leak\n"); exit(10); }
if (!str_contains($fromHtml, '[Spoiler]')) { fwrite(STDERR, "html placeholder\n"); exit(11); }

echo "ok\n";
"""
    result = subprocess.run(
        [_php_bin(), "-d", "display_errors=1", "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"Spoiler format pipeline failed:\n{combined}"
    assert "ok" in (result.stdout or "")


def test_phpunit_content_format_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Content/ContentFormatTest.php",
            "tests/Content/SpoilerStyleTest.php",
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

        $stripped = ap_strip_spoilers('Safe [spoiler]leaked[/spoiler] text');
        if ($stripped !== 'Safe [Spoiler] text') {{ fwrite(STDERR, "strip\\n"); exit(10); }}

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
