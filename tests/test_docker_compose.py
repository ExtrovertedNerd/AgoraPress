"""
Confirm docker-compose stack matches SPEC (PHP 8.2+, MySQL 8, required extensions).

Runnable via:
  pytest tests/test_docker_compose.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
COMPOSE_PATH = ROOT / "docker-compose.yml"
DOCKERFILE_PATH = ROOT / "docker" / "Dockerfile"
APACHE_VHOST = ROOT / "docker" / "apache-vhost.conf"
PHP_INI = ROOT / "docker" / "php-agorapress.ini"

# SPEC §1 extensions that must be available in the web image (via install or built-in).
REQUIRED_EXT_MARKERS = (
    "pdo_mysql",
    "mbstring",
    "zip",
    "gd",
    "intl",
)


@pytest.fixture(scope="module")
def compose_text() -> str:
    assert COMPOSE_PATH.is_file(), "Missing docker-compose.yml"
    return COMPOSE_PATH.read_text(encoding="utf-8")


@pytest.fixture(scope="module")
def dockerfile_text() -> str:
    assert DOCKERFILE_PATH.is_file(), "Missing docker/Dockerfile"
    return DOCKERFILE_PATH.read_text(encoding="utf-8")


def test_compose_and_dockerfile_exist() -> None:
    assert COMPOSE_PATH.is_file()
    assert DOCKERFILE_PATH.is_file()
    assert APACHE_VHOST.is_file()
    assert PHP_INI.is_file()


def test_compose_defines_web_and_db_services(compose_text: str) -> None:
    assert re.search(r"(?m)^services:\s*$", compose_text)
    assert re.search(r"(?m)^\s{2}web:\s*$", compose_text)
    assert re.search(r"(?m)^\s{2}db:\s*$", compose_text)


def test_web_builds_from_dockerfile(compose_text: str) -> None:
    assert "dockerfile: docker/Dockerfile" in compose_text
    assert "context: ." in compose_text


def test_db_uses_mysql_8(compose_text: str) -> None:
    assert re.search(r"image:\s*mysql:8(\.0)?\b", compose_text), (
        "db service must use MySQL 8.x (SPEC primary DB)"
    )


def test_db_charset_utf8mb4(compose_text: str) -> None:
    assert "utf8mb4" in compose_text
    assert "utf8mb4_unicode_ci" in compose_text


def test_web_depends_on_healthy_db(compose_text: str) -> None:
    assert "depends_on" in compose_text
    assert "service_healthy" in compose_text


def test_compose_exposes_http_port(compose_text: str) -> None:
    # Default host port 8080 → container 80 (optional AP_HTTP_PORT override).
    assert re.search(r"8080\}?:80|8080:80", compose_text)


def test_compose_db_credentials_align_with_sample_env(compose_text: str) -> None:
    for key in (
        "AP_DB_HOST",
        "AP_DB_NAME",
        "AP_DB_USER",
        "AP_DB_PASSWORD",
        "MYSQL_DATABASE",
        "MYSQL_USER",
        "MYSQL_PASSWORD",
        "MYSQL_ROOT_PASSWORD",
    ):
        assert key in compose_text, f"Expected {key} in docker-compose.yml"


def test_compose_default_table_prefix_ap_(compose_text: str) -> None:
    assert re.search(r"AP_TABLE_PREFIX:.*ap_", compose_text), (
        "Docker stack should default table prefix to ap_"
    )


def test_compose_persists_db_volume(compose_text: str) -> None:
    assert "ap_db_data" in compose_text
    assert "/var/lib/mysql" in compose_text


def test_dockerfile_base_is_php_8_3_or_higher(dockerfile_text: str) -> None:
    # SPEC: PHP 8.2+; image uses 8.3 (recommended).
    assert re.search(r"FROM\s+php:8\.(3|4)", dockerfile_text), (
        "Dockerfile should use php:8.3+ Apache base image"
    )
    assert "apache" in dockerfile_text.lower()


def test_dockerfile_enables_mod_rewrite(dockerfile_text: str) -> None:
    assert "a2enmod rewrite" in dockerfile_text


@pytest.mark.parametrize("ext", REQUIRED_EXT_MARKERS)
def test_dockerfile_installs_required_extension(dockerfile_text: str, ext: str) -> None:
    assert ext in dockerfile_text, (
        f"Dockerfile should install/configure PHP extension marker: {ext}"
    )


def test_apache_vhost_allows_htaccess() -> None:
    text = APACHE_VHOST.read_text(encoding="utf-8")
    assert "AllowOverride All" in text
    assert "DocumentRoot /var/www/html" in text


def test_compose_config_validates() -> None:
    """docker-compose config must accept the file when the CLI is available."""
    binary = shutil.which("docker-compose") or shutil.which("docker")
    if binary is None:
        pytest.skip("docker-compose / docker not available")

    if binary.endswith("docker-compose") or binary.endswith("docker-compose.exe"):
        cmd = [binary, "config"]
    else:
        # Prefer classic plugin if present; skip if neither works.
        plugin = subprocess.run(
            [binary, "compose", "version"],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        if plugin.returncode != 0:
            # Fall back: try docker-compose name already handled.
            alt = shutil.which("docker-compose")
            if alt is None:
                pytest.skip("docker compose plugin not available")
            cmd = [alt, "config"]
        else:
            cmd = [binary, "compose", "config"]

    result = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
    )
    assert result.returncode == 0, (
        "docker-compose config failed:\n"
        f"stdout:\n{result.stdout}\n"
        f"stderr:\n{result.stderr}"
    )
    assert "web" in result.stdout
    assert "db" in result.stdout
