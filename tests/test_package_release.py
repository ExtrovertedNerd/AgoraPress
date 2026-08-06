"""
Smoke tests for release packaging (bin/package-release.php).

Runnable via:
  pytest tests/test_package_release.py -v
"""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import tempfile
import zipfile
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "bin" / "package-release.php"
VERSION_PHP = ROOT / "ap-includes" / "version.php"
CHANGELOG = ROOT / "CHANGELOG.md"
README = ROOT / "README.md"
GITIGNORE = ROOT / ".gitignore"


@pytest.fixture(scope="module")
def ap_version() -> str:
    text = VERSION_PHP.read_text(encoding="utf-8")
    match = re.search(
        r"define\s*\(\s*['\"]AP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        text,
    )
    assert match, "AP_VERSION must be defined in ap-includes/version.php"
    return match.group(1)


def test_package_script_exists() -> None:
    assert SCRIPT.is_file()


def test_help_exits_zero() -> None:
    proc = subprocess.run(
        ["php", str(SCRIPT), "--help"],
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stdout + proc.stderr
    combined = proc.stdout + proc.stderr
    assert "--output-dir" in combined
    assert "--dry-run" in combined


def test_dry_run_json(ap_version: str) -> None:
    proc = subprocess.run(
        ["php", str(SCRIPT), "--dry-run", "--json"],
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stdout + proc.stderr
    data = json.loads(proc.stdout)
    assert data["ok"] is True
    assert data["dry_run"] is True
    assert data["version"] == ap_version
    assert data["file_count"] > 50
    assert data["prefix"] == "AgoraPress"


def test_builds_zip_and_excludes_dev_paths() -> None:
    with tempfile.TemporaryDirectory(prefix="ap-pkg-") as tmp:
        out = Path(tmp) / "dist"
        proc = subprocess.run(
            [
                "php",
                str(SCRIPT),
                f"--output-dir={out}",
                "--version=0.0.0-pytest",
                "--json",
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc.returncode == 0, proc.stdout + proc.stderr
        data = json.loads(proc.stdout)
        zip_path = out / "AgoraPress-0.0.0-pytest.zip"
        sha_path = out / "AgoraPress-0.0.0-pytest.sha256"
        version_json = out / "version.json"
        assert zip_path.is_file()
        assert sha_path.is_file()
        assert version_json.is_file()

        raw = zip_path.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        assert data["sha256"] == digest
        assert digest in sha_path.read_text(encoding="utf-8")

        payload = json.loads(version_json.read_text(encoding="utf-8"))
        assert payload["version"] == "0.0.0-pytest"
        assert payload["sha256"] == digest
        assert "download_url" in payload
        assert "changelog_url" in payload
        assert "released" in payload
        assert "notes" not in payload

        with zipfile.ZipFile(zip_path) as zf:
            names = set(zf.namelist())
            assert "AgoraPress/index.php" in names
            assert "AgoraPress/ap-includes/version.php" in names
            assert "AgoraPress/ap-admin/index.php" in names
            assert "AgoraPress/CHANGELOG.md" in names
            assert "AgoraPress/ap-content/themes/agora/style.css" in names

            assert not any(n.startswith("AgoraPress/tests/") for n in names)
            assert not any(n.startswith("AgoraPress/vendor/") for n in names)
            assert not any(n.startswith("AgoraPress/bin/") for n in names)
            assert "AgoraPress/phpunit.xml.dist" not in names
            assert "AgoraPress/ap-config.php" not in names
            assert all(n.startswith("AgoraPress/") for n in names)
            assert all(".." not in n for n in names)


def test_changelog_and_readme_document_packaging() -> None:
    changelog = CHANGELOG.read_text(encoding="utf-8")
    readme = README.read_text(encoding="utf-8")
    assert "package-release.php" in changelog
    assert "Release packaging" in changelog
    assert re.search(r"(?im)^##\s+Release packaging\s*$", readme)
    assert "bin/package-release.php" in readme
    assert "composer package" in readme


def test_gitignore_covers_dist() -> None:
    gi = GITIGNORE.read_text(encoding="utf-8")
    assert "/dist/" in gi or "dist/" in gi
    assert "*.sqlite" in gi or "database.sqlite" in gi
