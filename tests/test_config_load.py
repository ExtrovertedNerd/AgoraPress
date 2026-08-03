"""
Smoke tests for ap-includes/load-config.php (Phase 1 config loading).

Runnable via:
  pytest tests/test_config_load.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
SAMPLE = ROOT / "ap-config-sample.php"
VERSION = ROOT / "ap-includes" / "version.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_load_config_file_exists() -> None:
    assert LOAD_CONFIG.is_file(), "Missing ap-includes/load-config.php"
    assert SAMPLE.is_file()


def test_load_config_defines_public_api() -> None:
    src = LOAD_CONFIG.read_text(encoding="utf-8")
    for needle in (
        "function ap_load_config",
        "function ap_config_is_loaded",
        "function ap_get_table_prefix",
        "function ap_normalize_table_prefix",
        "function ap_default_table_prefix",
        "function ap_core_base_tables",
        "function ap_forum_base_tables",
        "function ap_all_base_tables",
        "function ap_prefixed_table",
        "function ap_prefixed_tables",
        "function ap_required_config_constants",
        "function ap_apply_config_defaults",
        "function ap_define_path_constants",
        "function ap_apply_debug_settings",
        "function ap_get_invalid_config_html",
        "function ap_finalize_table_prefix",
    ):
        assert needle in src, f"Expected {needle} in load-config.php"


def test_bootstrap_wires_load_config() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "load-config.php" in src
    assert "ap_load_config" in src


def test_sample_config_loads_via_php() -> None:
    """Isolated process: sample config must load cleanly and set AP_TABLE_PREFIX."""
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        define('AP_ABSPATH', {repr(str(ROOT) + '/')});
        require {repr(str(VERSION))};
        require {repr(str(LOAD_CONFIG))};
        $ok = ap_load_config({repr(str(SAMPLE))}, false);
        if ($ok !== true) {{
            fwrite(STDERR, "load failed\\n");
            exit(2);
        }}
        if (!ap_config_is_loaded() || ap_get_table_prefix() !== 'ap_') {{
            fwrite(STDERR, "prefix/loaded check failed\\n");
            exit(3);
        }}
        if (!defined('AP_CONTENT_DIR') || !defined('AP_PLUGIN_DIR')) {{
            fwrite(STDERR, "paths missing\\n");
            exit(4);
        }}
        echo "ok\\n";
        exit(0);
        """
    )

    result = subprocess.run(
        [
            _php_bin(),
            "-d",
            "display_errors=1",
            "-d",
            "error_reporting=E_ALL",
            "-r",
            script,
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"exit {result.returncode}:\n{combined}"
    assert "ok" in combined


def test_normalize_table_prefix_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(LOAD_CONFIG))};
        $cases = [
            ['ap_', 'ap_'],
            ['bad-x!', 'badx'],
            ['', 'ap_'],
            ['9x', 'ap_9x'],
        ];
        foreach ($cases as [$in, $want]) {{
            $got = ap_normalize_table_prefix($in);
            if ($got !== $want) {{
                fwrite(STDERR, "normalize($in) => $got want $want\\n");
                exit(2);
            }}
        }}
        if (ap_default_table_prefix() !== 'ap_') {{
            fwrite(STDERR, "default prefix\\n");
            exit(3);
        }}
        $core = ap_core_base_tables();
        $forum = ap_forum_base_tables();
        if (!in_array('options', $core, true) || !in_array('forums', $forum, true)) {{
            fwrite(STDERR, "base tables missing\\n");
            exit(4);
        }}
        if (ap_prefixed_table('options') !== 'ap_options') {{
            fwrite(STDERR, "prefixed helper\\n");
            exit(5);
        }}
        echo "ok\\n";
        exit(0);
        """
    )

    result = subprocess.run(
        [_php_bin(), "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "ok" in combined


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
