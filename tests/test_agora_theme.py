"""
Smoke tests for the default Agora theme: polish, 6 schemes, blog + forum templates.

Runnable via:
  pytest tests/test_agora_theme.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AGORA = ROOT / "ap-content" / "themes" / "agora"
STYLE = AGORA / "style.css"
FUNCTIONS = AGORA / "functions.php"
HEADER = AGORA / "header.php"
THEME_OPTIONS = ROOT / "ap-admin" / "theme-options.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
ADMIN = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"

SCHEMES = ("marble", "parchment", "cloud", "obsidian", "midnight", "charcoal")
LIGHT = ("marble", "parchment", "cloud")
DARK = ("obsidian", "midnight", "charcoal")

FORUM_TEMPLATES = ("forum.php", "forum-view.php", "topic.php")
BLOG_TEMPLATES = (
    "index.php",
    "single.php",
    "page.php",
    "archive.php",
    "search.php",
    "404.php",
    "header.php",
    "footer.php",
    "home.php",
    "front-page.php",
)


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_agora_theme_files_exist() -> None:
    assert STYLE.is_file()
    assert FUNCTIONS.is_file()
    assert HEADER.is_file()
    assert THEME_OPTIONS.is_file()
    for name in BLOG_TEMPLATES:
        assert (AGORA / name).is_file(), f"missing blog template {name}"
    for name in FORUM_TEMPLATES:
        assert (AGORA / name).is_file(), f"missing forum template {name}"


def test_style_css_has_six_scheme_selectors_and_no_images() -> None:
    css = STYLE.read_text(encoding="utf-8")
    assert "Theme Name: Agora" in css
    for slug in SCHEMES:
        assert f"agora-scheme-{slug}" in css, f"missing CSS for {slug}"

    # Lightweight / image-free: no url(...) image references.
    assert not re.search(
        r"url\s*\(\s*['\"]?(?:https?:|data:image|[^)]+\.(?:png|jpe?g|gif|webp|svg))",
        css,
        flags=re.I,
    )


def test_agora_theme_version_lockstep() -> None:
    """style.css header Version and AGORA_THEME_VERSION stay in lockstep (board CSS bump)."""
    css = STYLE.read_text(encoding="utf-8")
    src = FUNCTIONS.read_text(encoding="utf-8")
    m = re.search(r"^Version:\s*(\S+)", css, flags=re.M)
    assert m, "style.css missing Version: header"
    version = m.group(1)
    assert f"AGORA_THEME_VERSION = '{version}'" in src, (
        f"AGORA_THEME_VERSION must match style.css Version {version!r}"
    )
    # Board CSS is a theme-local bump (0.3.4+); keep progressive.
    parts = version.split(".")
    assert len(parts) >= 2
    major, minor = int(parts[0]), int(parts[1])
    assert (major, minor) >= (0, 3)


def test_style_css_polish_responsive_accessible_forum() -> None:
    css = STYLE.read_text(encoding="utf-8")
    # Responsive breakpoints
    assert "@media (max-width: 640px)" in css or "@media (max-width:" in css
    assert "@media (min-width: 900px)" in css or "@media (min-width:" in css
    # Accessibility
    assert "skip-link" in css
    assert "focus-visible" in css
    assert "prefers-reduced-motion" in css
    assert "screen-reader-text" in css
    assert "prefers-contrast" in css or "forced-colors" in css
    # Phase 5 — button focus + unread contrast (no opacity dimming of read rows).
    assert ".ap-btn:focus-visible" in css
    assert ".ap-btn--ghost:focus-visible" in css
    assert ".ap-forum-like:focus-visible" in css
    assert "--ap-forum-unread-bar-width" in css
    assert "Do not dim whole rows with opacity" in css
    assert not re.search(r"\.ap-forum-row--read\s*\{[^}]*opacity\s*:", css)
    # Typography tokens
    assert "--ap-font-display" in css
    assert "--ap-text-base" in css or "font-size" in css
    # Forum components
    assert ".ap-forum" in css
    assert ".ap-forum-list" in css
    assert ".ap-forum-post" in css
    # SPEC B2 — two-pane topic post layout
    assert ".ap-forum-post--two-pane" in css
    assert ".ap-forum-post__main" in css
    assert ".ap-forum-post__author" in css
    assert "author main" in css
    # SPEC B2 — Top of page control
    assert ".ap-forum-post__foot" in css
    assert ".ap-forum-post__top" in css
    # SPEC B1 — First unread post jump
    assert ".ap-forum-first-unread" in css
    assert ".ap-forum-first-unread-wrap" in css
    assert ".ap-breadcrumbs" in css
    assert ".ap-pagination" in css
    # On-accent for dark-scheme button contrast
    assert "--ap-on-accent" in css
    # Account indicator in header (logged-in + guest auth links)
    assert ".site-account" in css
    assert ".site-account__welcome" in css
    assert ".site-account__logout" in css
    assert ".site-account__login" in css
    assert ".site-account__register" in css
    assert ".site-account--guest" in css
    for slug in SCHEMES:
        assert f"agora-scheme-{slug}" in css


def test_functions_define_scheme_and_forum_api() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function agora_get_color_schemes",
        "function agora_get_color_scheme",
        "function agora_set_color_scheme",
        "function agora_body_class",
        "function agora_sanitize_color_scheme",
        "function agora_get_forum_view",
        "function agora_forum_template_hierarchy",
        "function agora_get_forum_index_data",
        "function agora_get_forum_topics_data",
        "function agora_get_topic_posts_data",
        "function agora_the_posts_pagination",
        "function agora_the_entry_meta",
        "function agora_get_account_indicator",
        "function agora_the_account_indicator",
        "function agora_get_guest_auth_links",
        "function agora_users_can_register",
        "site-account--guest",
        "site-account__login",
        "site-account__register",
        "AGORA_COLOR_SCHEME_OPTION",
        "AGORA_DEFAULT_COLOR_SCHEME",
        "marble",
        "parchment",
        "cloud",
        "obsidian",
        "midnight",
        "charcoal",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_header_applies_body_class_and_a11y() -> None:
    src = HEADER.read_text(encoding="utf-8")
    assert "agora_body_class" in src
    assert "color-scheme" in src
    assert "skip-link" in src
    assert 'id="main"' in src
    assert "viewport" in src
    assert "lang=" in src
    assert "agora_the_account_indicator" in src


def test_footer_powered_by_agorapress_is_linked() -> None:
    """“Powered by AgoraPress” credits the project site with a link on AgoraPress.

    TODO 8.3 / Final checks: the word AgoraPress must link to the project root
    https://agorapress.extrovertednerd.com (not donate or hall-of-fame subpaths).
    """
    footer = (AGORA / "footer.php").read_text(encoding="utf-8")
    project_url = "https://agorapress.extrovertednerd.com"
    assert "Powered by" in footer
    assert project_url in footer
    # Exact project-root URL in the Powered-by anchor (literal or via ap_esc_url).
    assert (
        f"ap_esc_url('{project_url}')" in footer
        or f'ap_esc_url("{project_url}")' in footer
        or f'href="{project_url}"' in footer
        or f"href='{project_url}'" in footer
    )
    assert re.search(
        r'Powered by\s*<a\s+href="[^"]*agorapress\.extrovertednerd\.com[^"]*"\s*>\s*AgoraPress\s*</a>',
        footer,
        flags=re.I | re.S,
    )
    powered_chunk = footer.split("Powered by", 1)[1].split("</p>", 1)[0]
    assert "/donate" not in powered_chunk
    assert "hall-of-fame" not in powered_chunk
    # Footer menu location + useful-link fallback (Privacy / Login).
    assert "theme_location" in footer
    assert "'footer'" in footer or '"footer"' in footer
    assert "fallbackFooter" in footer


def test_forum_templates_markup() -> None:
    forum = (AGORA / "forum.php").read_text(encoding="utf-8")
    assert "ap-forum" in forum
    assert "ap-breadcrumbs" in forum
    assert "agora_get_forum_index_data" in forum
    # SPEC A3 — category header label row (Title spans icon+title).
    assert "ap-forum-cat-header" in forum
    assert "ap-forum-cat-header__title" in forum
    assert "ap-forum-cat-header__topics" in forum
    assert "ap-forum-cat-header__posts" in forum
    assert "ap-forum-cat-header__last" in forum
    assert ">Title<" in forum
    assert ">Topics<" in forum
    assert ">Posts<" in forum
    assert ">Last Post<" in forum
    # SPEC A4 — five-column forum rows + 3-line last post.
    assert "ap-forum-row" in forum
    assert "ap-forum-last-post__title" in forum
    assert "ap-forum-last-post__author" in forum
    assert "ap-forum-last-post__time" in forum
    assert "ap_forum_row_icon_html" in forum
    # SPEC A1 — read/unread/neutral row classes + icon variants.
    assert "ap_forum_row_classes" in forum
    assert "tracking" in forum

    view = (AGORA / "forum-view.php").read_text(encoding="utf-8")
    assert "ap-forum" in view
    assert "agora_get_forum_topics_data" in view
    assert "ap-forum-row" in view
    assert "ap-forum-last-post" in view
    assert "ap_forum_row_icon_html" in view
    assert "ap_forum_row_classes" in view

    topic = (AGORA / "topic.php").read_text(encoding="utf-8")
    assert "ap-forum-post" in topic
    assert "agora_get_topic_posts_data" in topic
    # SPEC B2 — two-pane post (author left, body/actions right).
    assert "ap-forum-post--two-pane" in topic
    assert "ap-forum-post__author" in topic
    assert "ap-forum-post__main" in topic
    assert "ap-forum-post__body" in topic
    assert "ap-forum-post__actions" in topic
    assert "ap-forum-post__head-start" in topic
    # SPEC B2 author pane: joined + location (omit location when empty).
    assert "ap-forum-post__joined" in topic
    assert "ap-forum-post__location" in topic
    assert "ap-forum-post__stat--joined" in topic
    assert "ap-forum-post__stat--location" in topic
    # SPEC B2 — Top of page control (in-page jump to topic top).
    assert 'id="ap-topic-top"' in topic
    assert "ap-forum-post__foot" in topic
    assert "ap-forum-post__top" in topic
    assert 'href="#ap-topic-top"' in topic
    assert 'aria-label="Back to top of topic"' in topic
    # Post action button labels (Phase 5 a11y).
    assert "aria-label=" in topic
    assert "Quote post #" in topic
    assert "Edit post #" in topic
    assert "Delete post #" in topic
    assert "Like post #" in topic
    # SPEC B1 — First unread post link above OP when applicable.
    assert "first_unread_post_id" in topic
    assert "ap-forum-first-unread" in topic
    assert "ap_forum_first_unread_link_html" in topic
    assert "First unread post" in topic


def test_forum_category_header_css_grid() -> None:
    """Category header shares board grid; Title spans icon+title columns."""
    css = STYLE.read_text(encoding="utf-8")
    assert ".ap-forum-cat-header" in css
    assert "ap-forum-cat-header__title" in css
    # Title spans icon + title columns (grid-column 1 / span 2).
    assert re.search(
        r"\.ap-forum-cat-header__title\s*\{[^}]*grid-column:\s*1\s*/\s*span\s*2",
        css,
        flags=re.S,
    )
    # Five-column board grid via tokens (icon | title | topics | posts | last).
    assert "--ap-forum-icon-col" in css
    assert "--ap-forum-stat-col" in css
    assert "--ap-forum-last-col" in css
    assert "minmax(0, 1fr)" in css
    # SPEC A4 row/icon/last-post hooks.
    assert ".ap-forum-icon" in css
    assert ".ap-forum-last-post__title" in css
    assert ".ap-forum-last-post__author" in css
    assert ".ap-forum-last-post__time" in css
    # SPEC A1/A2 — read/unread row + icon state variants.
    assert ".ap-forum-row--unread" in css
    assert ".ap-forum-row--read" in css
    assert ".ap-forum-icon--unread" in css
    assert ".ap-forum-icon--read" in css
    assert ".ap-forum-icon--sticky.ap-forum-icon--unread" in css
    assert ".ap-forum-icon--announcement.ap-forum-icon--unread" in css
    assert ".ap-forum-icon--rules.ap-forum-icon--unread" in css
    assert ".ap-forum-icon--locked.ap-forum-icon--unread" in css
    # Unread wins over zebra stripe; locked+unread dual bar.
    assert ":not(.ap-forum-row--unread)" in css
    assert ".ap-forum-row--locked.ap-forum-row--unread" in css
    # Responsive: hide category labels on narrow boards.
    assert re.search(
        r"@media\s*\(max-width:\s*699px\)\s*\{[^}]*\.ap-forum-cat-header",
        css,
        flags=re.S,
    )


def test_agora_board_css_stays_theme_local() -> None:
    """Board layout CSS lives in Agora theme only — not core — so custom themes stay free."""
    includes = ROOT / "ap-includes"
    # Core must not ship board-row layout rules that override third-party themes.
    for path in includes.rglob("*.css"):
        text = path.read_text(encoding="utf-8")
        assert ".ap-forum-row--unread" not in text, f"core CSS must not style board rows: {path}"
        assert ".ap-forum-cat-header" not in text, f"core CSS must not style cat header: {path}"
    # Markup helpers stay in PHP (stable class names), styling in themes.
    functions = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    assert "ap-forum-row--unread" in functions
    assert "ap-forum-list__item" in functions
    assert "ap-forum-row__icon" in functions


def test_known_custom_theme_styles_stable_board_hooks() -> None:
    """zeroshits (known custom theme) styles the same stable board hooks — no hard break."""
    zs = ROOT / "ap-content" / "themes" / "zeroshits"
    if not zs.is_dir():
        return
    style = (zs / "style.css").read_text(encoding="utf-8")
    for needle in (
        ".ap-forum-cat-header",
        ".ap-forum-row",
        ".ap-forum-row--unread",
        ".ap-forum-row--read",
        ".ap-forum-row--locked",
        ".ap-forum-icon--unread",
        ".ap-forum-icon--read",
        ".ap-forum-last-post__title",
        ".ap-forum-last-post__author",
        ".ap-forum-last-post__time",
        "ap-forum-list__item",
    ):
        assert needle in style, f"zeroshits missing stable hook {needle!r}"
    # Dual legacy + new class surface so either selector path works.
    assert ".ap-forum-list__item" in style or "ap-forum-list__item" in style
    forum = (zs / "forum.php").read_text(encoding="utf-8")
    assert "ap-forum-row" in forum
    assert "ap-forum-cat-header" in forum
    assert "ap_forum_row_classes" in forum or "ap-forum-row--" in forum


def test_theme_options_admin_and_menu() -> None:
    opts = THEME_OPTIONS.read_text(encoding="utf-8")
    assert "agora_color_scheme" in opts
    assert "agora_set_color_scheme" in opts
    assert "Theme Options" in opts

    admin = ADMIN.read_text(encoding="utf-8")
    assert "theme-options" in admin
    assert "theme-options.php" in admin
    assert "theme_options_saved" in admin


def test_installer_seeds_marble_default() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "agora_color_scheme" in src
    assert "'marble'" in src


def test_color_scheme_and_forum_runtime_via_php() -> None:
    """Runtime: six schemes, body class, blog render, forum hierarchy + templates."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "require $root . '/ap-includes/class-ap-query.php';\n"
        "require $root . '/ap-includes/hooks.php';\n"
        "require $root . '/ap-includes/class-ap-theme.php';\n"
        "require $root . '/ap-includes/class-ap-assets.php';\n"
        "require $root . '/ap-includes/functions.php';\n"
        "require $root . '/ap-includes/template-tags.php';\n"
        "AP_Post::resetRegistry();\n"
        "AP_Theme::reset();\n"
        "if (function_exists('ap_reset_hooks')) { ap_reset_hooks(); }\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "AP_Post::ensureBuiltins();\n"
        "foreach (['home'=>'https://example.test','siteurl'=>'https://example.test',\n"
        "  'stylesheet'=>'agora','template'=>'agora','blogname'=>'Scheme Test'] as $n=>$v) {\n"
        "  $db->insert('options', ['option_name'=>$n,'option_value'=>$v,'autoload'=>'yes']);\n"
        "}\n"
        "$GLOBALS['apdb'] = $db;\n"
        "AP_Theme::setThemesRootOverride($root . '/ap-content/themes');\n"
        "AP_Theme::setActiveOverride('agora', 'agora');\n"
        "AP_Theme::setup($db);\n"
        "if (!function_exists('agora_get_color_schemes')) { fwrite(STDERR,\"no api\\n\"); exit(1); }\n"
        "$schemes = agora_get_color_schemes();\n"
        "if (count($schemes) !== 6) { fwrite(STDERR,\"count\\n\"); exit(1); }\n"
        "$expected = ['marble','parchment','cloud','obsidian','midnight','charcoal'];\n"
        "if (array_keys($schemes) !== $expected) { fwrite(STDERR,\"keys\\n\"); exit(1); }\n"
        "$light = $dark = 0;\n"
        "foreach ($schemes as $m) { if ($m['mode']==='light') $light++; elseif ($m['mode']==='dark') $dark++; }\n"
        "if ($light !== 3 || $dark !== 3) { fwrite(STDERR,\"modes\\n\"); exit(1); }\n"
        "if (agora_get_color_scheme($db) !== 'marble') { fwrite(STDERR,\"default\\n\"); exit(1); }\n"
        "if (!agora_set_color_scheme('midnight', $db)) { fwrite(STDERR,\"set\\n\"); exit(1); }\n"
        "if (agora_get_color_scheme($db) !== 'midnight') { fwrite(STDERR,\"get\\n\"); exit(1); }\n"
        "if (agora_set_color_scheme('nope', $db)) { fwrite(STDERR,\"invalid ok\\n\"); exit(1); }\n"
        "$cls = agora_body_class($db);\n"
        "if (!str_contains($cls, 'agora-scheme-midnight') || !str_contains($cls, 'agora-mode-dark')) {\n"
        "  fwrite(STDERR,\"body $cls\\n\"); exit(1);\n"
        "}\n"
        "AP_Post::insert(['post_title'=>'S','post_type'=>'post','post_status'=>'publish',\n"
        "  'post_content'=>'c'], $db);\n"
        "$q = new AP_Query(['post_type'=>'post','posts_per_page'=>5], $db);\n"
        "ob_start(); AP_Theme::render($q, $db); $html = ob_get_clean();\n"
        "if (!str_contains($html, 'agora-scheme-midnight') || !str_contains($html, 'S')) {\n"
        "  fwrite(STDERR,\"render\\n\"); exit(1);\n"
        "}\n"
        "if (!str_contains($html, 'skip-link') || !str_contains($html, 'id=\"main\"')) {\n"
        "  fwrite(STDERR,\"a11y\\n\"); exit(1);\n"
        "}\n"
        "// Forum hierarchy + empty index render\n"
        "$fq = new AP_Query(['ap_forum_view'=>'index','post_type'=>'post','posts_per_page'=>1], $db);\n"
        "$GLOBALS['ap_query'] = $fq;\n"
        "if (agora_get_forum_view($fq) !== 'index') { fwrite(STDERR,\"forum view\\n\"); exit(1); }\n"
        "$hier = AP_Theme::getHierarchy($fq, $db);\n"
        "if (($hier[0] ?? '') !== 'forum.php') { fwrite(STDERR,\"hier \".implode(',', $hier).\"\\n\"); exit(1); }\n"
        "$bcls = agora_body_class($db);\n"
        "if (!str_contains($bcls, 'agora-forum') || !str_contains($bcls, 'agora-forum--index')) {\n"
        "  fwrite(STDERR,\"forum body $bcls\\n\"); exit(1);\n"
        "}\n"
        "ob_start(); AP_Theme::render($fq, $db); $fhtml = ob_get_clean();\n"
        "if (!str_contains($fhtml, 'ap-forum') || !str_contains($fhtml, 'Forums')) {\n"
        "  fwrite(STDERR,\"forum render\\n\"); exit(1);\n"
        "}\n"
        "$tq = new AP_Query(['ap_forum_view'=>'topic','topic_id'=>1,'topic_title'=>'Hello'], $db);\n"
        "$th = AP_Theme::getHierarchy($tq, $db);\n"
        "if (($th[0] ?? '') !== 'topic.php') { fwrite(STDERR,\"topic hier\\n\"); exit(1); }\n"
        "echo \"OK\\n\";\n"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write(code)
        path = fh.name
    try:
        result = subprocess.run(
            [_php_bin(), "-d", "display_errors=1", path],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "OK" in (result.stdout or "")


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
