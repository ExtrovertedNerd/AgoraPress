"""
Confirm repository structure matches SPEC.md §2 File & Directory Structure.

Runnable via the project test runner (pytest):
  pytest tests/test_repository_structure.py -v

Also covered by the PHP checker:
  php tests/Structure/assert-structure.php
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]

# Paths required by SPEC.md §2 (relative to repo root).
REQUIRED_PATHS: list[str] = [
    # Root entry / config / tooling
    "index.php",
    "ap-config-sample.php",
    ".htaccess",
    "composer.json",
    "docker-compose.yml",
    "README.md",
    "LICENSE",
    # Nginx example (SPEC: ".htaccess / nginx examples")
    "docker/nginx.conf.example",
    # Docker Compose stack (SPEC §2 / Phase 0)
    "docker/Dockerfile",
    "docker/apache-vhost.conf",
    "docker/php-agorapress.ini",

    # Admin
    "ap-admin",
    # Web + CLI installer
    "install/index.php",
    "install/cli.php",
    "ap-includes/class-ap-requirements.php",
    "ap-includes/class-ap-installer.php",
    "ap-includes/class-ap-cli-install.php",
    # Core includes
    "ap-includes/class-ap-db.php",
    "ap-includes/class-ap-migrator.php",
    "ap-includes/class-ap-migration.php",
    "ap-includes/schema/migrations",
    "ap-includes/class-ap-query.php",
    "ap-includes/class-ap-user.php",
    "ap-includes/class-ap-roles.php",
    "ap-includes/class-ap-theme.php",
    "ap-includes/class-ap-plugin.php",
    "ap-includes/class-ap-forum.php",
    "ap-includes/compatibility",
    "ap-includes/functions.php",
    "ap-includes/hooks.php",
    # Content
    "ap-content/themes",
    "ap-content/plugins",
    "ap-content/mu-plugins",
    "ap-content/languages",
    # Tests
    "tests",
]

# Patterns that must appear in .gitignore (runtime / secrets / process state).
MUST_BE_GITIGNORED: list[str] = [
    "ap-config.php",
    "ap-content/uploads",
    ".hephaestus",
]

# Explicit Hephaestus rules required by constitution (never track process state).
HEPHAESTUS_GITIGNORE_RULES: list[str] = [
    ".hephaestus/",
    "**/.hephaestus/",
]


def _gitignore_covers(gi: str, pattern: str) -> bool:
    """Return True if .gitignore content covers the given path pattern."""
    needle = pattern.rstrip("/")
    candidates = (
        pattern,
        needle,
        f"/{needle}",
        f"/{needle}/",
        f"{needle}/",
        f"**/{needle}/",
        f"**/{needle}",
    )
    return any(c in gi for c in candidates)


def _gitignore_lines(gi: str) -> set[str]:
    """Non-empty, non-comment .gitignore lines (stripped)."""
    lines: set[str] = set()
    for raw in gi.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        lines.add(line)
    return lines


@pytest.mark.parametrize("rel", REQUIRED_PATHS)
def test_required_path_exists(rel: str) -> None:
    """Every SPEC.md §2 required path must exist on disk."""
    path = ROOT / rel
    assert path.exists(), f"Missing required path: {rel}"


def test_gitignore_covers_runtime_and_secrets() -> None:
    """ap-config.php, uploads, and .hephaestus must be gitignored."""
    gi_path = ROOT / ".gitignore"
    assert gi_path.is_file(), "Missing .gitignore"
    gi = gi_path.read_text(encoding="utf-8")
    for pattern in MUST_BE_GITIGNORED:
        assert _gitignore_covers(gi, pattern), (
            f"Expected .gitignore to cover: {pattern}"
        )


def test_gitignore_never_tracks_hephaestus() -> None:
    """Constitution: .hephaestus/ process state must never be committed."""
    gi_path = ROOT / ".gitignore"
    assert gi_path.is_file(), "Missing .gitignore"
    gi = gi_path.read_text(encoding="utf-8")
    rules = _gitignore_lines(gi)

    for rule in HEPHAESTUS_GITIGNORE_RULES:
        assert rule in rules, (
            f".gitignore must contain exact rule {rule!r} "
            "(never track Hephaestus process state)"
        )

    # If git is available, prove ignore works and nothing is already tracked.
    try:
        tracked = subprocess.run(
            ["git", "ls-files", "--", ".hephaestus", "**/.hephaestus"],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return

    if tracked.returncode != 0:
        return  # Not a git work tree or git unavailable — rule text still asserted.

    assert tracked.stdout.strip() == "", (
        ".hephaestus/ paths must not be tracked by git; found:\n"
        f"{tracked.stdout}"
    )

    # -q accepts only a single path; check each process-state path individually.
    for path in (".hephaestus/", ".hephaestus/TODO.md", ".hephaestus/Workflow.md"):
        check = subprocess.run(
            ["git", "check-ignore", "-q", path],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        # Exit 0 = ignored; 1 = not ignored; 128 = git error (skip soft).
        if check.returncode == 128:
            return
        assert check.returncode == 0, (
            f"git check-ignore must match {path!r}; "
            f"exit={check.returncode} stderr={check.stderr!r}"
        )


def test_license_is_gplv2() -> None:
    """LICENSE must be GPLv2-or-later text per SPEC."""
    license_path = ROOT / "LICENSE"
    assert license_path.is_file()
    body = license_path.read_text(encoding="utf-8", errors="replace")
    assert re.search(r"GNU GENERAL PUBLIC LICENSE", body, re.I), (
        "LICENSE does not appear to be GPL text"
    )
    assert "Version 2" in body or re.search(r"GPL-2\.0", body, re.I), (
        "LICENSE should be GPLv2 (or later)"
    )
    # GPLv2-or-later: application notice must allow any later GPL version.
    assert re.search(r"any later version", body, re.I) or re.search(
        r"GPL-2\.0-or-later", body, re.I
    ), "LICENSE should allow GPLv2 or later (not GPLv2-only)"


def test_php_structure_assert_script_passes() -> None:
    """Run the canonical PHP structure checker when php is available."""
    script = ROOT / "tests" / "Structure" / "assert-structure.php"
    assert script.is_file(), "Missing tests/Structure/assert-structure.php"
    php = "php"
    try:
        result = subprocess.run(
            [php, str(script)],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
    except FileNotFoundError:
        pytest.skip("php binary not available")
    assert result.returncode == 0, (
        "Structure check failed:\n"
        f"stdout:\n{result.stdout}\n"
        f"stderr:\n{result.stderr}"
    )


def test_table_prefix_default_in_sample_config() -> None:
    """Sample config must default table prefix to ap_ (SPEC)."""
    sample = (ROOT / "ap-config-sample.php").read_text(encoding="utf-8")
    assert re.search(r"\$table_prefix\s*=\s*['\"]ap_['\"]", sample), (
        "ap-config-sample.php should set $table_prefix = 'ap_'"
    )
