"""
Smoke tests for VISION / FEATURES reevaluation invariants.

Runnable via:
  pytest tests/test_vision_compliance.py -v
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPAT = ROOT / "ap-includes" / "compatibility"
AGORA = ROOT / "ap-content" / "themes" / "agora"
DOCS = ROOT / "docs" / "vision-compliance.md"
SAMPLE = ROOT / "ap-config-sample.php"
COMPOSER = ROOT / "composer.json"
README = ROOT / "README.md"
LICENSE = ROOT / "LICENSE"

SCHEMES = ("marble", "parchment", "cloud", "obsidian", "midnight", "charcoal")
COMPAT_FILES = (
    "load.php",
    "class-ap-theme-compat.php",
    "class-ap-theme-converter.php",
    "functions-shim.php",
    "template-tags.php",
    "cli-convert.php",
)


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_license_gplv2() -> None:
    text = LICENSE.read_text(encoding="utf-8")
    assert "GNU GENERAL PUBLIC LICENSE" in text
    assert "Version 2" in text
    data = json.loads(COMPOSER.read_text(encoding="utf-8"))
    assert data.get("license") == "GPL-2.0-or-later"


def test_composer_runtime_deps_are_extensions_only() -> None:
    data = json.loads(COMPOSER.read_text(encoding="utf-8"))
    for name in data.get("require", {}):
        assert name == "php" or str(name).startswith("ext-"), (
            f"runtime require must be php/ext only, found {name}"
        )


def test_telemetry_off_in_sample_config() -> None:
    sample = SAMPLE.read_text(encoding="utf-8")
    assert re.search(
        r"define\s*\(\s*['\"]AP_TELEMETRY['\"]\s*,\s*false\s*\)",
        sample,
    )
    loader = (ROOT / "ap-includes" / "load-config.php").read_text(encoding="utf-8")
    assert "'AP_TELEMETRY' => false" in loader


def test_version_check_no_site_identity() -> None:
    src = (ROOT / "ap-includes" / "class-ap-version-check.php").read_text(encoding="utf-8")
    assert "function sendsSiteIdentity" in src
    assert "return false;" in src
    assert "no-site-id" in src
    assert "http_build_query" not in src


def test_hall_of_fame_not_telemetry() -> None:
    src = (ROOT / "ap-includes" / "class-ap-hall-of-fame.php").read_text(encoding="utf-8")
    assert "no telemetry" in src.lower() or "not telemetry" in src.lower()
    assert "function isTelemetry" in src
    assert "function buildPayload" in src


def test_compatibility_layer_present() -> None:
    assert COMPAT.is_dir()
    for name in COMPAT_FILES:
        assert (COMPAT / name).is_file(), f"missing compat file {name}"
    compat_cls = (COMPAT / "class-ap-theme-compat.php").read_text(encoding="utf-8")
    for needle in (
        "function shouldEnableForTheme",
        "function isBlockTheme",
        "function mapHook",
        "function safeLoadFunctionsPhp",
        "MODE_AUTO",
        "wp_enqueue_scripts",
        "ap_enqueue_scripts",
    ):
        assert needle in compat_cls, f"compat missing {needle}"


def test_agora_six_schemes_image_free() -> None:
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    functions = (AGORA / "functions.php").read_text(encoding="utf-8")
    for slug in SCHEMES:
        assert f"agora-scheme-{slug}" in style
        assert f"'{slug}'" in functions or f'"{slug}"' in functions
    assert not re.search(
        r"url\s*\(\s*['\"]?(?:https?:|data:image|[^)]+\.(?:png|jpe?g|gif|webp|svg))",
        style,
        flags=re.I,
    )
    image_ext = {".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico"}
    found = [p for p in AGORA.rglob("*") if p.is_file() and p.suffix.lower() in image_ext]
    assert found == [], f"Agora must be image-free: {found}"


def test_module_option_constants_in_options_class() -> None:
    src = (ROOT / "ap-includes" / "class-ap-options.php").read_text(encoding="utf-8")
    for needle in (
        "MODULE_STATIC_PAGES",
        "MODULE_BLOG",
        "MODULE_FORUM",
        "ap_module_static_pages",
        "ap_module_blog",
        "ap_module_forum",
        "function isModuleEnabled",
        "function updateModules",
    ):
        assert needle in src, f"module API missing {needle}"


def test_no_jquery_library_in_core_paths() -> None:
    roots = [
        ROOT / "ap-includes",
        ROOT / "ap-admin",
        ROOT / "ap-content" / "themes" / "agora",
        ROOT / "install",
    ]
    pattern = re.compile(
        r"jquery[\.-]?[0-9]|/jquery|jquery\.min|jquery\.js|cdn\.jquery|code\.jquery",
        re.I,
    )
    hits: list[str] = []
    for root in roots:
        if not root.is_dir():
            continue
        for path in root.rglob("*"):
            if path.suffix.lower() not in {".php", ".js", ".css", ".html"}:
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            if pattern.search(text):
                hits.append(str(path.relative_to(ROOT)))
    assert hits == [], f"jQuery library refs: {hits}"


def test_vision_compliance_doc() -> None:
    assert DOCS.is_file()
    text = DOCS.read_text(encoding="utf-8")
    assert len(text) >= 2000
    for phrase in (
        "Intentional deviations",
        "No telemetry",
        "Classic WordPress Theme Compatibility",
        "Free forever",
        "D1",
        "D3",
    ):
        assert phrase in text, f"vision-compliance.md missing {phrase}"


def test_readme_not_phase_one_stub() -> None:
    text = README.read_text(encoding="utf-8")
    assert not re.search(r"Early development \(Phase 1\)", text, flags=re.I)
    assert "Classic WordPress Theme Compatibility" in text
    assert "free forever" in text.lower()
    assert "no telemetry" in text.lower()


def test_phpunit_vision_compliance() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    result = subprocess.run(
        [_php_bin(), str(phpunit), "--filter", "VisionCompliance", str(ROOT / "tests" / "Vision")],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    assert result.returncode == 0, result.stdout + "\n" + result.stderr
