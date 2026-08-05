"""
Smoke tests for the lightweight visual WYSIWYG editor.

Runnable via:
  pytest tests/test_editor.py -v
"""

from __future__ import annotations

import shutil
import subprocess
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
