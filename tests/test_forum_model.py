"""
Smoke tests for forum hierarchy, topics, and posts/replies.

Runnable via:
  pytest tests/test_forum_model.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
VERSION = ROOT / "ap-includes" / "version.php"
DB_CLASS = ROOT / "ap-includes" / "class-ap-db.php"
MIGRATOR = ROOT / "ap-includes" / "class-ap-migrator.php"
AGORA_FUNCTIONS = ROOT / "ap-content" / "themes" / "agora" / "functions.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_forum_class_defines_domain_api() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum",
        "function insertForum",
        "function updateForum",
        "function deleteForum",
        "function getHierarchy",
        "function getChildForums",
        "function getIndexData",
        "function createTopic",
        "function updateTopic",
        "function deleteTopic",
        "function getTopics",
        "function createReply",
        "function updatePost",
        "function deletePost",
        "function getPosts",
        "function incrementTopicViews",
        "FORUM_TYPE_CATEGORY",
        "TOPIC_STATUS_LOCKED",
        "TOPIC_TYPE_STICKY",
        "function uniqueForumSlug",
        "function uniqueTopicSlug",
        "function wouldCreateForumCycle",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum.php"


def test_functions_expose_forum_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_insert_forum",
        "function ap_get_forum",
        "function ap_create_topic",
        "function ap_get_topics",
        "function ap_create_forum_reply",
        "function ap_get_forum_posts",
        "function ap_get_forum_index_data",
        "function ap_get_forum_topics_data",
        "function ap_get_topic_posts_data",
        "function ap_delete_topic",
        "function ap_update_forum",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_forum_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum.php" in src


def test_agora_theme_uses_forum_api() -> None:
    src = AGORA_FUNCTIONS.read_text(encoding="utf-8")
    assert "ap_get_forum_index_data" in src or "AP_Forum::getIndexData" in src
    assert "ap_get_forum_topics_data" in src or "getTopicsDisplayData" in src
    assert "ap_get_topic_posts_data" in src or "getPostsDisplayData" in src


def test_phpunit_forum_model_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumModelTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum model failed:\n{combined}"


def test_forum_hierarchy_topics_replies_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        require {repr(str(FORUM_CLASS))};
        require {repr(str(FUNCTIONS))};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();

        $cat = ap_insert_forum([
            'forum_name' => 'Community',
            'forum_type' => 'category',
        ], $db);
        if ($cat < 1) {{ fwrite(STDERR, "cat\\n"); exit(2); }}

        $forum = ap_insert_forum([
            'forum_name' => 'Lounge',
            'parent_id' => $cat,
            'forum_type' => 'forum',
        ], $db);
        if ($forum < 1) {{ fwrite(STDERR, "forum\\n"); exit(3); }}

        $topic = ap_create_topic([
            'forum_id' => $forum,
            'topic_title' => 'First thread',
            'content' => 'Opening post',
            'poster_id' => 1,
        ], $db);
        if ($topic < 1) {{ fwrite(STDERR, "topic\\n"); exit(4); }}

        $reply = ap_create_forum_reply([
            'topic_id' => $topic,
            'content' => 'A reply',
            'poster_id' => 2,
        ], $db);
        if ($reply < 1) {{ fwrite(STDERR, "reply\\n"); exit(5); }}

        $f = ap_get_forum($forum, $db);
        if ((int) $f->topic_count !== 1 || (int) $f->post_count !== 2) {{
            fwrite(STDERR, "counts topic={{$f->topic_count}} post={{$f->post_count}}\\n");
            exit(6);
        }}

        $topics = ap_get_topics($forum, [], $db);
        $posts = ap_get_forum_posts($topic, [], $db);
        if (count($topics) !== 1 || count($posts) !== 2) {{
            fwrite(STDERR, "list sizes\\n");
            exit(7);
        }}

        $tree = ap_get_forum_hierarchy(0, [], $db);
        if (count($tree) !== 1 || count($tree[0]['children']) !== 1) {{
            fwrite(STDERR, "hierarchy\\n");
            exit(8);
        }}

        $index = ap_get_forum_index_data($db);
        if ($index === [] || ($index[0]['name'] ?? '') !== 'Community') {{
            fwrite(STDERR, "index\\n");
            exit(9);
        }}

        // Locked topic blocks reply.
        ap_update_topic($topic, ['status' => 'locked'], $db);
        $blocked = ap_create_forum_reply([
            'topic_id' => $topic,
            'content' => 'Should fail',
        ], $db);
        if ($blocked !== 0) {{
            fwrite(STDERR, "locked not enforced\\n");
            exit(10);
        }}

        echo "forum_domain_ok\\n";
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
    assert result.returncode == 0, f"forum domain runtime failed:\n{combined}"
    assert "forum_domain_ok" in (result.stdout or "")


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
