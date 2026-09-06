"""
Charter presence tests for public docs (humans + Grok Bot).

Small pytest smoke so a required guide cannot disappear silently.
Runnable via:
  pytest tests/test_docs_presence.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
CLI_SRC = ROOT / "ap-includes" / "class-ap-cli.php"

# New operator/agent guides this charter added (SPEC “required new guides”).
REQUIRED_NEW_PATHS = (
    "install.md",
    "updates.md",
    "rewrites.md",
    "cli.md",
    "admin.md",
    "forums.md",
    "rest.md",
    "roles.md",
    "security.md",
    "troubleshooting.md",
    "bot_handbook.md",
    "features_and_functions.md",
)

# Phase 0 inventory: built-in ap-cli --help command groups.
PHASE0_CLI_GROUPS = (
    "cache",
    "cli",
    "core",
    "cron",
    "db",
    "help",
    "option",
    "plugin",
    "post",
    "rewrite",
    "site",
    "theme",
    "user",
    "version",
)

PRIVATE_MARKERS = (
    "roland",
    "stallboy@",
    "mail.0shits.com",
    "keepass",
    "stalwart",
)

CATALOG_TOKENS = (
    "AP_DB_VERSION",
    "/ap-admin/",
    "/ap-json/",
    "rest_api_enabled",
    "analytics_enabled",
    "ap_register_admin_page",
)

ADD_COMMAND = re.compile(
    r"self::addCommand\(\s*'([a-z0-9-]+)'",
    re.MULTILINE,
)


@pytest.fixture(scope="module")
def docs_root() -> Path:
    assert DOCS.is_dir(), "Missing docs/ directory"
    return DOCS


def _builtin_cli_groups() -> list[str]:
    text = CLI_SRC.read_text(encoding="utf-8")
    start = text.find("public static function ensureBuiltins()")
    assert start != -1, "AP_Cli::ensureBuiltins() not found"
    chunk = text[start:]
    names = ADD_COMMAND.findall(chunk)
    assert names, "No addCommand() names found in ensureBuiltins()"
    return sorted(set(names))


@pytest.mark.parametrize("name", REQUIRED_NEW_PATHS)
def test_required_new_path_exists_and_is_non_empty(docs_root: Path, name: str) -> None:
    path = docs_root / name
    assert path.is_file(), f"Missing required guide: docs/{name}"
    text = path.read_text(encoding="utf-8")
    assert text.strip(), f"docs/{name} must be non-empty"


def test_docs_index_links_every_new_guide(docs_root: Path) -> None:
    index = (docs_root / "README.md").read_text(encoding="utf-8")
    for name in REQUIRED_NEW_PATHS:
        pattern = rf"\]\({re.escape(name)}(?:#[^)]*)?\)"
        assert re.search(pattern, index), (
            f"docs/README.md should markdown-link {name}"
        )


def test_bot_handbook_states_public_safe_rule_and_do_not_invent_surfaces(
    docs_root: Path,
) -> None:
    path = docs_root / "bot_handbook.md"
    assert path.is_file(), "Missing docs/bot_handbook.md"
    text = path.read_text(encoding="utf-8")
    lower = text.lower()
    assert (
        "public-safe" in lower
        or "this repository is **public**" in lower
        or "this repository is public" in lower
    ), "docs/bot_handbook.md should state the public-safe / public-repository rule"
    assert (
        "do not invent surfaces" in lower or "do **not invent** surfaces" in lower
    ), "docs/bot_handbook.md should say do not invent surfaces"
    assert "never write" in lower
    assert "not in core" in lower


def test_rewrites_doc_contains_shipped_try_files_pattern(docs_root: Path) -> None:
    text = (docs_root / "rewrites.md").read_text(encoding="utf-8")
    assert "try_files $uri $uri/ /index.php" in text
    assert "try_files $uri $uri/ /index.php?$args" in text


def test_cli_doc_names_every_builtin_command_group(docs_root: Path) -> None:
    groups = _builtin_cli_groups()
    for expected in PHASE0_CLI_GROUPS:
        assert expected in groups, (
            f"Phase 0 group {expected!r} missing from AP_Cli::ensureBuiltins()"
        )
    extra = sorted(set(groups) - set(PHASE0_CLI_GROUPS))
    assert not extra, (
        "AP_Cli built-ins drifted from Phase 0 inventory; update docs/cli.md "
        f"and PHASE0_CLI_GROUPS. Extra: {extra}"
    )

    cli = (docs_root / "cli.md").read_text(encoding="utf-8")
    for group in groups:
        pattern = rf"^\|\s+`{re.escape(group)}`\s+\|"
        assert re.search(pattern, cli, re.MULTILINE), (
            f"docs/cli.md must name ap-cli group `{group}` in the built-in table"
        )


def test_catalog_or_linked_guides_mention_required_tokens(docs_root: Path) -> None:
    catalog = (docs_root / "features_and_functions.md").read_text(encoding="utf-8")
    for token in CATALOG_TOKENS:
        assert token in catalog, (
            f"docs/features_and_functions.md should mention: {token}"
        )


def _docs_markdown_names(docs_root: Path) -> list[str]:
    return sorted(path.name for path in docs_root.glob("*.md"))


@pytest.mark.parametrize("name", _docs_markdown_names(DOCS) if DOCS.is_dir() else [])
def test_docs_file_contains_no_private_markers(docs_root: Path, name: str) -> None:
    text = (docs_root / name).read_text(encoding="utf-8").lower()
    for banned in PRIVATE_MARKERS:
        assert banned not in text, (
            f"docs/{name} must not contain private marker: {banned}"
        )


def test_no_parallel_docs_index(docs_root: Path) -> None:
    assert not (docs_root / "index.md").exists(), (
        "One public index only: docs/README.md (do not also create docs/index.md)"
    )
