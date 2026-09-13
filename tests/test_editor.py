"""
Smoke tests for the lightweight visual WYSIWYG editor.

Runnable via:
  pytest tests/test_editor.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EDITOR = ROOT / "ap-includes" / "class-ap-editor.php"
CSS = ROOT / "ap-includes" / "css" / "ap-editor.css"
JS = ROOT / "ap-includes" / "js" / "ap-editor.js"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
POST_EDIT = ROOT / "ap-admin" / "includes" / "class-ap-admin-post-edit.php"
TOPIC = ROOT / "ap-content" / "themes" / "agora" / "topic.php"
FORUM_VIEW = ROOT / "ap-content" / "themes" / "agora" / "forum-view.php"
SINGLE = ROOT / "ap-content" / "themes" / "agora" / "single.php"
PHPUNIT = ROOT / "tests" / "Editor" / "EditorTest.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_editor_files_exist() -> None:
    assert EDITOR.is_file()
    assert CSS.is_file()
    assert JS.is_file()
    assert PHPUNIT.is_file()


def test_editor_class_api() -> None:
    src = EDITOR.read_text(encoding="utf-8")
    for needle in (
        "class AP_Editor",
        "MODE_VISUAL",
        "MODE_MARKDOWN",
        "MODE_BBCODE",
        "MODE_HTML",
        "ARCHITECTURE_CLASSIC",
        "function architecture",
        "function isBlockEditor",
        "function isLightweight",
        "function isVisual",
        "MAX_JS_BYTES",
        "MAX_CSS_BYTES",
        "function buttons",
        "function emojis",
        "function valueToHtml",
        "function render",
        "function renderToolbar",
        "function renderEmojiPicker",
        "function enqueue",
        "function printAssets",
        "function modeForContext",
        "emoji-picker",
        "ap_editor_emojis",
        "data-ap-editor-architecture",
        "data-ap-editor-surface",
        "data-ap-editor-mode-switch",
        "data-ap-editor-set-mode",
        "Not a block",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-editor.php"


def test_editor_stays_lightweight_visual_no_block_surface() -> None:
    """Guard: classic visual WYSIWYG only — no Gutenberg / block packages."""
    # Soft asset budgets match AP_Editor::MAX_*_BYTES.
    assert JS.stat().st_size <= 49152, f"ap-editor.js too large: {JS.stat().st_size}"
    assert CSS.stat().st_size <= 24576, f"ap-editor.css too large: {CSS.stat().st_size}"

    for rel in (
        "ap-includes/blocks",
        "ap-includes/gutenberg",
        "ap-includes/block-editor",
        "ap-includes/class-ap-block-editor.php",
        "ap-includes/js/block-editor.js",
        "ap-includes/js/blocks.js",
        "ap-admin/js/gutenberg.js",
    ):
        path = ROOT / rel
        assert not path.exists(), f"Block-editor surface must not exist: {rel}"

    js = JS.read_text(encoding="utf-8").lower()
    for forbidden in (
        "prosemirror",
        "tinymce",
        "quill",
        "gutenberg",
        "wp.blocks",
        "lexical",
    ):
        assert forbidden not in js, f"JS must not include {forbidden!r}"

    # Visual surface is expected.
    assert "contenteditable" in js
    assert "execcommand" in js
    assert "data-ap-editor-surface" in js

    php = _php_bin()
    script = r"""
require_once __DIR__ . '/ap-includes/class-ap-content-format.php';
require_once __DIR__ . '/ap-includes/class-ap-editor.php';
if (AP_Editor::isBlockEditor()) { fwrite(STDERR, "isBlockEditor true\n"); exit(1); }
if (!AP_Editor::isLightweight()) { fwrite(STDERR, "not lightweight\n"); exit(1); }
if (!AP_Editor::isVisual()) { fwrite(STDERR, "not visual\n"); exit(1); }
if (AP_Editor::architecture() !== 'classic') { fwrite(STDERR, "bad architecture\n"); exit(1); }
$html = AP_Editor::render(['id' => 't', 'name' => 't', 'mode' => 'visual', 'value' => 'Hello **world**']);
if (strpos($html, 'data-ap-editor-architecture="classic"') === false) {
    fwrite(STDERR, "missing architecture attr\n"); exit(1);
}
if (strpos($html, 'data-ap-editor-surface') === false) {
    fwrite(STDERR, "missing surface\n"); exit(1);
}
if (!preg_match('/<textarea\b/i', $html)) { fwrite(STDERR, "no textarea\n"); exit(1); }
if (strpos($html, '<strong>world</strong>') === false) {
    fwrite(STDERR, "markdown not converted for surface\n"); exit(1);
}
if (strpos($html, '**world**') !== false) {
    fwrite(STDERR, "raw markdown leaked\n"); exit(1);
}
if (in_array('blocks', AP_Editor::modes(), true)) { fwrite(STDERR, "blocks mode\n"); exit(1); }
if (AP_Editor::modeForContext('forum') !== 'visual') { fwrite(STDERR, "forum not visual\n"); exit(1); }
echo "ok\n";
"""
    result = subprocess.run(
        [php, "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=60,
    )
    assert result.returncode == 0, result.stdout + "\n" + result.stderr
    assert "ok" in result.stdout


def test_helpers_and_bootstrap() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_editor" in functions
    assert "function ap_enqueue_editor" in functions
    assert "function ap_print_editor_assets" in functions
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-editor.php" in boot
    # Display pipeline formats content (not plain escape only).
    assert "AP_Content_Format" in boot
    assert "ap_the_content" in boot


def test_wired_into_post_page_comment_forum_editors() -> None:
    post_edit = POST_EDIT.read_text(encoding="utf-8")
    assert "AP_Editor" in post_edit
    assert "post_content" in post_edit
    assert "Visual editor" in post_edit or "visual" in post_edit.lower()

    topic = TOPIC.read_text(encoding="utf-8")
    assert "ap_editor" in topic or "AP_Editor" in topic
    assert "reply_body" in topic

    forum = FORUM_VIEW.read_text(encoding="utf-8")
    assert "ap_editor" in forum or "AP_Editor" in forum
    assert "topic_body" in forum

    single = SINGLE.read_text(encoding="utf-8")
    assert "ap_editor" in single or "AP_Editor" in single
    assert "ap-comment-form" in single
    assert 'name="comment"' in single or "name=\"comment\"" in single


def test_css_has_surface_rules() -> None:
    css = CSS.read_text(encoding="utf-8")
    assert ".ap-editor__surface" in css
    assert ".ap-editor__toolbar" in css
    assert "ap-editor--visual-active" in css


def _first_css_block(css: str, selector: str) -> str:
    pattern = r"(?m)^" + re.escape(selector) + r"\s*\{([^{}]+)\}"
    match = re.search(pattern, css)
    assert match, f"Missing CSS block for {selector!r}"
    return match.group(1)


def _rule_containing(css: str, selector: str) -> str:
    pattern = (
        r"(?<![a-zA-Z0-9_-])"
        + re.escape(selector)
        + r"(?![a-zA-Z0-9_-])[^{]*\{([^{}]+)\}"
    )
    match = re.search(pattern, css)
    assert match, f"Missing CSS rule containing {selector!r}"
    return match.group(1)


def _css_custom_properties(block: str) -> dict[str, str]:
    return dict(re.findall(r"(--[A-Za-z0-9-]+)\s*:\s*([^;]+);", block))


def _css_declaration(block: str, prop: str) -> str:
    match = re.search(
        r"(?:^|;)\s*" + re.escape(prop) + r"\s*:\s*([^;]+);",
        ";" + block,
    )
    assert match, f"Missing CSS declaration {prop!r}"
    return match.group(1).strip()


def _resolve_css_var(
    value: str,
    defined: dict[str, str],
    visiting: set[str] | None = None,
) -> str:
    """Walk var(--name, fallback) until a concrete value."""
    value = value.strip().rstrip(";").strip()
    match = re.match(
        r"^var\(\s*(--[A-Za-z0-9-]+)\s*(?:,\s*(.*))?\)$",
        value,
        re.S,
    )
    if not match:
        return value
    name = match.group(1)
    fallback = (match.group(2) or "").strip()
    seen = set() if visiting is None else visiting
    if name in seen:
        return _resolve_css_var(fallback, defined, seen) if fallback else name
    next_seen = seen | {name}
    if name in defined:
        return _resolve_css_var(defined[name], defined, next_seen)
    if fallback:
        return _resolve_css_var(fallback, defined, next_seen)
    return name


def _hex_luminance(hex_color: str) -> float:
    raw = hex_color.lstrip("#")
    if len(raw) == 3:
        raw = "".join(ch * 2 for ch in raw)
    channels = [int(raw[i : i + 2], 16) / 255.0 for i in (0, 2, 4)]
    out = []
    for channel in channels:
        if channel <= 0.04045:
            out.append(channel / 12.92)
        else:
            out.append(((channel + 0.055) / 1.055) ** 2.4)
    return 0.2126 * out[0] + 0.7152 * out[1] + 0.0722 * out[2]


def _contrast(a: str, b: str) -> float:
    hi, lo = sorted((_hex_luminance(a), _hex_luminance(b)), reverse=True)
    return (hi + 0.05) / (lo + 0.05)


def test_css_inherits_color_scheme_and_pairs_chrome() -> None:
    """Dark chrome follows color-scheme inherit + system colors, not only Agora tokens."""
    css = CSS.read_text(encoding="utf-8")
    wrap = _first_css_block(css, ".ap-editor")
    toolbar = _first_css_block(css, ".ap-editor__toolbar")
    surface = _first_css_block(css, ".ap-editor__surface")
    btn = _first_css_block(css, ".ap-editor__btn")

    assert "color-scheme: inherit" in wrap
    for token in (
        "--ap-editor-bg",
        "--ap-editor-fg",
        "--ap-editor-border",
        "--ap-editor-surface",
        "Canvas",
        "CanvasText",
        "Field",
        "FieldText",
    ):
        assert token in wrap, f"Expected {token!r} in .ap-editor"

    assert "background: var(--ap-editor-chrome-bg, Canvas)" in toolbar
    assert "color: var(--ap-editor-chrome-fg, CanvasText)" in toolbar
    assert "color-scheme: inherit" in toolbar
    assert "#eef0f3" not in toolbar
    assert "background: var(--ap-editor-field-bg, Field)" in surface
    assert "color: var(--ap-editor-field-fg, FieldText)" in surface
    assert "color: currentColor" in btn
    assert ".ap-editor button.ap-editor__btn" in css
    assert ".ap-editor .ap-editor__toolbar" in css
    assert re.search(
        r"\.ap-editor button\.ap-editor__btn\s*\{[^}]*color:\s*var\(--ap-editor-chrome-fg",
        css,
    )
    assert re.search(
        r"\.ap-editor button\.ap-editor__mode-btn\s*,[\s\S]*?\{\s*color:\s*var\(--ap-editor-field-fg",
        css,
    )
    isolate = _rule_containing(css, ".ap-editor button.ap-editor__btn")
    assert "--ap-editor-chrome-fg" in isolate
    assert "CanvasText" in isolate
    assert "currentColor" not in isolate
    assert "inherit" not in isolate
    field_isolate = _rule_containing(css, ".ap-editor button.ap-editor__mode-btn")
    assert "--ap-editor-field-fg" in field_isolate
    assert "FieldText" in field_isolate
    assert "currentColor" not in field_isolate
    assert "inherit" not in field_isolate
    assert re.search(
        r"\.ap-editor button\.ap-editor__mode-btn\.is-active\s*\{[^}]*"
        r"color:\s*var\(--ap-on-accent,\s*#fff\)",
        css,
    )

    assert re.search(r"--ap-editor-chrome-bg:\s*var\([^;]*Canvas\)", wrap)
    assert re.search(r"--ap-editor-chrome-fg:\s*var\([^;]*CanvasText\)", wrap)
    assert re.search(r"--ap-editor-field-bg:\s*var\([^;]*Field\)", wrap)
    assert re.search(r"--ap-editor-field-fg:\s*var\([^;]*FieldText\)", wrap)
    assert not re.search(r"#[0-9a-fA-F]{3,8}", wrap)

    # Dark chrome still resolves if Agora-only host rules are removed.
    stripped = re.sub(r"body\.agora-mode-dark[\s\S]*?\{[\s\S]*?\}", "", css)
    stripped_wrap = _first_css_block(stripped, ".ap-editor")
    assert "color-scheme: inherit" in stripped_wrap
    assert "Canvas" in stripped_wrap
    assert "FieldText" in stripped_wrap
    assert not re.search(
        r"body\.agora-mode-dark[^{]*\.ap-editor__(?:toolbar|btn|surface)[^{]*\{",
        css,
    )

    assert "html .ap-editor" in css
    assert "body .ap-editor" in css
    assert re.search(
        r"html\s+\.ap-editor\s*,[\s\S]*?body\s+\.ap-editor\s*\{[^}]*color-scheme:\s*inherit",
        css,
    )
    assert not re.search(
        r"html\s+\.ap-editor\s*,[\s\S]*?body\s+\.ap-editor\s*\{[^}]*color-scheme:\s*dark",
        css,
    )
    assert "html.agora-mode-dark .ap-editor" in css
    assert "body.agora-mode-dark .ap-editor" in css
    assert 'html[data-ap-color-mode="dark"] .ap-editor' in css
    assert 'body[data-ap-color-mode="dark"] .ap-editor' in css
    assert '[data-ap-color-mode="dark"] .ap-editor' in css
    assert re.search(
        r"html\.agora-mode-dark\s+\.ap-editor\s*,[\s\S]*?"
        r"body\.agora-mode-dark\s+\.ap-editor\s*,[\s\S]*?"
        r'html\[data-ap-color-mode="dark"\]\s+\.ap-editor\s*,[\s\S]*?'
        r"\{[^}]*color-scheme:\s*dark",
        css,
    )


def _max_css_bytes() -> int:
    src = EDITOR.read_text(encoding="utf-8")
    match = re.search(r"const MAX_CSS_BYTES\s*=\s*(\d+)", src)
    assert match, "AP_Editor::MAX_CSS_BYTES missing"
    return int(match.group(1))


def test_css_pairs_toolbar_surface_buttons() -> None:
    """Toolbar/surface/buttons pair bg with matching text; buttons use currentColor."""
    css = CSS.read_text(encoding="utf-8")
    assert CSS.stat().st_size <= _max_css_bytes(), (
        f"ap-editor.css {CSS.stat().st_size} exceeds MAX_CSS_BYTES {_max_css_bytes()}"
    )

    wrap = _first_css_block(css, ".ap-editor")
    toolbar = _first_css_block(css, ".ap-editor__toolbar")
    surface = _first_css_block(css, ".ap-editor__surface")
    textarea = _first_css_block(css, ".ap-editor__textarea")
    mode_switch = _first_css_block(css, ".ap-editor__mode-switch")
    picker = _first_css_block(css, ".ap-editor__emoji-picker")
    btn = _first_css_block(css, ".ap-editor__btn")
    mode_btn = _first_css_block(css, ".ap-editor__mode-btn")
    emoji_btn = _first_css_block(css, ".ap-editor__emoji-btn")
    emoji_close = _first_css_block(css, ".ap-editor__emoji-close")

    assert re.search(r"--ap-editor-chrome-bg:\s*var\(--ap-editor-bg,.*Canvas\)", wrap)
    assert re.search(r"--ap-editor-chrome-fg:\s*var\(--ap-editor-fg,.*CanvasText\)", wrap)
    assert re.search(r"--ap-editor-field-bg:\s*var\(--ap-editor-surface,.*Field\)", wrap)
    assert re.search(r"--ap-editor-field-fg:\s*var\(--ap-editor-fg,.*FieldText\)", wrap)
    assert re.search(r"--ap-editor-line:\s*var\(--ap-editor-border,.*currentColor", wrap)

    assert "background: var(--ap-editor-chrome-bg, Canvas)" in toolbar
    assert "color: var(--ap-editor-chrome-fg, CanvasText)" in toolbar
    assert "background: var(--ap-editor-field-bg, Field)" in surface
    assert "color: var(--ap-editor-field-fg, FieldText)" in surface
    assert "background: var(--ap-editor-field-bg, Field)" in textarea
    assert "color: var(--ap-editor-field-fg, FieldText)" in textarea
    assert "background: var(--ap-editor-field-bg, Field)" in mode_switch
    assert "color: var(--ap-editor-field-fg, FieldText)" in mode_switch
    assert "background: var(--ap-editor-field-bg, Field)" in picker
    assert "color: var(--ap-editor-field-fg, FieldText)" in picker

    for control in (btn, mode_btn, emoji_btn, emoji_close):
        assert "background: transparent" in control
        assert "color: currentColor" in control

    active = _first_css_block(css, ".ap-editor__mode-btn.is-active")
    assert "background: var(--ap-primary, #1a5fb4)" in active
    assert "color: var(--ap-on-accent, #fff)" in active


def test_contrast_fixture_without_agora_stylesheet() -> None:
    """Page with color-scheme: dark and light text, no Agora CSS, still has toolbar contrast."""
    fixture = ROOT / "tests" / "Editor" / "fixtures" / "editor-contrast-dark.html"
    html = fixture.read_text(encoding="utf-8")
    assert re.search(r"html,\s*body\s*\{[^}]*color-scheme:\s*dark", html)
    assert re.search(r"html,\s*body\s*\{[^}]*background:\s*#12141a", html)
    assert "color-scheme: dark" in html
    assert "color: #e8eaed" in html
    assert "button { color: inherit; }" in html
    assert "ap-editor.css" in html
    assert html.count('rel="stylesheet"') == 1
    assert "ap-editor__toolbar" in html
    assert "ap-editor__btn" in html
    assert "ap-editor__surface" in html
    assert 'data-ap-editor-contrast-fixture="dark-no-agora"' in html
    assert "agora/style.css" not in html
    assert "themes/agora" not in html
    assert "agora-mode-dark" not in html
    assert not re.search(r"--ap-(?:surface|text|fg|bg)\s*:", html)
    assert not re.search(r"--ap-editor-(?:bg|fg|surface|border)\s*:", html)

    assert _hex_luminance("#e8eaed") > 0.6
    assert _hex_luminance("#12141a") < 0.25
    assert _contrast("#e8eaed", "#12141a") >= 4.5
    assert _contrast("#e8eaed", "#eef0f3") < 4.5

    css = CSS.read_text(encoding="utf-8")
    for skin in ("Jarvis", "BlindVault", "MensBS", "AgoraPress_Addons"):
        assert skin not in css
        assert skin not in html

    wrap = _first_css_block(css, ".ap-editor")
    toolbar = _first_css_block(css, ".ap-editor__toolbar")
    btn = _first_css_block(css, ".ap-editor__btn")
    isolate = _rule_containing(css, ".ap-editor button.ap-editor__btn")
    tokens = _css_custom_properties(wrap)

    assert "color-scheme: inherit" in wrap
    assert "--ap-editor-chrome-bg" in tokens
    assert "--ap-editor-chrome-fg" in tokens
    assert _resolve_css_var(tokens["--ap-editor-chrome-bg"], tokens) == "Canvas"
    assert _resolve_css_var(tokens["--ap-editor-chrome-fg"], tokens) == "CanvasText"

    assert "background: var(--ap-editor-chrome-bg, Canvas)" in toolbar
    assert "color: var(--ap-editor-chrome-fg, CanvasText)" in toolbar
    assert "color-scheme: inherit" in toolbar
    assert "#eef0f3" not in toolbar
    assert "color: currentColor" in btn

    toolbar_bg = _resolve_css_var(_css_declaration(toolbar, "background"), tokens)
    toolbar_fg = _resolve_css_var(_css_declaration(toolbar, "color"), tokens)
    button_fg = _resolve_css_var(_css_declaration(isolate, "color"), tokens)
    assert toolbar_bg == "Canvas"
    assert toolbar_fg == "CanvasText"
    assert button_fg == "CanvasText"
    assert button_fg == toolbar_fg
    assert toolbar_bg != button_fg


def test_inherit_bleach_fixture_locks_chrome_foreground() -> None:
    """Light Canvas toolbar + theme button inherit must not use page color on labels."""
    fixture = (
        ROOT / "tests" / "Editor" / "fixtures" / "editor-button-inherit-light-toolbar.html"
    )
    html = fixture.read_text(encoding="utf-8")
    assert "button { color: inherit; }" in html
    assert ".ap-editor__toolbar { color: inherit; }" in html
    assert "color: #e8eaed" in html
    assert "ap-editor.css" in html
    assert "ap-editor__toolbar" in html
    assert "ap-editor__btn" in html
    assert "color-scheme: dark" not in html
    assert "agora/style.css" not in html
    assert not re.search(r"--ap-(?:surface|text|fg|bg)\s*:", html)

    css = CSS.read_text(encoding="utf-8")
    isolate = _rule_containing(css, ".ap-editor button.ap-editor__btn")
    toolbar_lock = _first_css_block(css, ".ap-editor .ap-editor__toolbar")
    toolbar = _first_css_block(css, ".ap-editor__toolbar")
    assert "--ap-editor-chrome-fg" in isolate
    assert "inherit" not in isolate
    assert "--ap-editor-chrome-fg" in toolbar_lock
    assert "inherit" not in toolbar_lock
    assert "background: var(--ap-editor-chrome-bg, Canvas)" in toolbar
    assert "color: var(--ap-editor-chrome-fg, CanvasText)" in toolbar


def test_contrast_phpunit_cases() -> None:
    """Fixture + Agora scheme contrast stay locked in EditorTest / AgoraThemeTest."""
    editor = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testContrastFixtureWithoutAgoraStylesheet",
        "function testCssInheritsColorSchemeAndPublishesEditorTokens",
        "function testCssDarkChromeDoesNotDependOnlyOnAgoraOrApTokens",
        "function testCssHonorsHtmlBodyColorSchemeAndKnownDarkHosts",
        "editor-contrast-dark.html",
        "Canvas",
        "CanvasText",
    ):
        assert needle in editor, f"Expected {needle!r} in EditorTest.php"

    agora = (ROOT / "tests" / "Theme" / "AgoraThemeTest.php").read_text(
        encoding="utf-8"
    )
    assert "function testSixSchemesKeepEditorContrastWithoutAddons" in agora


def test_phpunit_editor_suite_runs() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            "--colors=never",
            str(PHPUNIT),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "EditorTest PHPUnit suite failed"

