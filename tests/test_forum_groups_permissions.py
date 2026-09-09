"""
Smoke tests for user groups + granular per-forum permissions (schema v7).

Runnable via:
  pytest tests/test_forum_groups_permissions.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
VERSION = ROOT / "ap-includes" / "version.php"
DB_CLASS = ROOT / "ap-includes" / "class-ap-db.php"
MIGRATOR = ROOT / "ap-includes" / "class-ap-migrator.php"
GROUP_CLASS = ROOT / "ap-includes" / "class-ap-group.php"
PERM_CLASS = ROOT / "ap-includes" / "class-ap-forum-permissions.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
FRONT_CLASS = ROOT / "ap-includes" / "class-ap-forum-front.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_and_classes_exist() -> None:
    assert (MIGRATIONS / "0007_forum_permissions.php").is_file()
    assert GROUP_CLASS.is_file()
    assert PERM_CLASS.is_file()


def test_db_version_at_least_seven() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 7


def test_migration_0007_surface() -> None:
    mig = MIGRATIONS / "0007_forum_permissions.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0007_Forum_Permissions",
        "forum_permissions",
        "permission_id",
        "perm_name",
        "perm_setting",
        "forum_group_perm",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0007 migration"


def test_group_class_api() -> None:
    src = GROUP_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Group",
        "function ensureSystemGroups",
        "function create",
        "function addMember",
        "function removeMember",
        "function getUserGroups",
        "function getEffectiveGroupIds",
        "SLUG_GUESTS",
        "SLUG_REGISTERED",
        "SLUG_ADMINISTRATORS",
        "SLUG_GLOBAL_MODERATORS",
        "TYPE_OPEN",
        "TYPE_CLOSED",
        "TYPE_HIDDEN",
        "TYPE_SYSTEM",
        "exclude_system",
        "exclude_hidden",
        "function query",
        "function queryPublic",
        "function count",
        "function allowsPublicJoin",
        "function joinPublic",
        "function groupTypeAllowsPublicJoin",
        "no public Join control",
    ):
        assert needle in src, f"Expected {needle} in AP_Group"


def test_named_groups_already_exist_groups_acp_is_forum_groups() -> None:
    """Named groups + Groups ACP already ship. Do not invent a second Groups ACP."""
    admin = ROOT / "ap-admin"
    assert (admin / "forum-groups.php").is_file()
    assert (admin / "includes" / "class-ap-admin-forum-groups.php").is_file()
    group_screens = sorted(p.name for p in admin.glob("*group*"))
    assert group_screens == ["forum-groups.php"], group_screens
    assert not (admin / "groups.php").exists()
    assert not (admin / "options-groups.php").exists()
    assert not (admin / "user-groups.php").exists()

    groups_src = (admin / "includes" / "class-ap-admin-forum-groups.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "TYPE_OPEN",
        "TYPE_CLOSED",
        "TYPE_HIDDEN",
        "addMember",
        "no Join control",
        "Forums → Groups is the roster",
        "Add Member",
    ):
        assert needle in groups_src, f"Expected {needle} in Groups ACP"
    assert "joinPublic" not in groups_src
    assert "Join this group" not in groups_src

    edit_src = (admin / "includes" / "class-ap-admin-forum-edit.php").read_text(
        encoding="utf-8"
    )
    assert "This group only" in edit_src
    assert "forum_access_groups[]" in edit_src

    perm_src = PERM_CLASS.read_text(encoding="utf-8")
    assert "ACCESS_PUBLIC" in perm_src
    assert "ACCESS_MEMBERS" in perm_src
    assert "ACCESS_GROUP_ONLY" in perm_src
    assert "function namedGroupsForPicker" in perm_src
    assert "function getAccessGroupIds" in perm_src
    assert "function applyGroupOnlyAccess" in perm_src

    phpunit = ROOT / "tests" / "Forum" / "ForumGroupsPermissionsTest.php"
    phpunit_src = phpunit.read_text(encoding="utf-8")
    assert "testNamedGroupTypesAndExcludeSystemQuery" in phpunit_src
    assert "testGroupsAcpAlreadyExistsAndForumEditDoesNotInventOne" in phpunit_src
    assert "testThisGroupOnlyPresetStoresNamedGroupsAndRejectsSystem" in phpunit_src
    assert "testThisGroupOnlyApplyDeniesGuestsNotRegisteredAndAllowsNamedGroup" in phpunit_src
    assert "testThisGroupOnlySiteWideModeratorCannotEnterUnlessMember" in phpunit_src
    assert "testThisGroupOnlyBoardIndexHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlySearchHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyFeedsHideForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlySitemapHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyRestHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyPrettyUrlsHideForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyListingHygieneHidesBoardFromOutsiders" in phpunit_src
    assert "testThisGroupOnlyGuestAndNonMemberCannotListOrOpenBoard" in phpunit_src
    assert "testHiddenGroupsHaveNoPublicJoinControl" in phpunit_src
    assert "AP_Forums_List_Table" in phpunit_src


def test_hidden_groups_stay_without_public_join_control() -> None:
    """Hidden groups have no public Join; Forums → Groups remains the roster."""
    group = GROUP_CLASS.read_text(encoding="utf-8")
    assert "function allowsPublicJoin" in group
    assert "function joinPublic" in group
    assert "function queryPublic" in group
    assert "Hidden groups have no public Join control" in group

    front = FRONT_CLASS.read_text(encoding="utf-8")
    assert "ACTION_JOIN_GROUP" not in front
    assert "ap_group_join" not in front
    assert "joinPublic" not in front

    rest = (ROOT / "ap-includes" / "class-ap-rest.php").read_text(encoding="utf-8")
    assert "/groups" not in rest
    assert "joinPublic" not in rest

    fn = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_join_group_public" in fn
    assert "function ap_group_allows_public_join" in fn
    assert "function ap_get_public_groups" in fn

    theme = ROOT / "ap-content" / "themes" / "agora"
    join_re = re.compile(
        r"join[-_ ]?(this[-_ ])?group|ap_group_join|ap_join_group|ap_forum_join_group",
        re.IGNORECASE,
    )
    for name in (
        "forum.php",
        "forum-view.php",
        "topic.php",
        "forum-search.php",
        "functions.php",
    ):
        src = (theme / name).read_text(encoding="utf-8")
        assert join_re.search(src) is None, f"{name} must not render a public group Join"

    acp = (ROOT / "ap-admin" / "includes" / "class-ap-admin-forum-groups.php").read_text(
        encoding="utf-8"
    )
    assert "Add Member" in acp
    assert "no Join control" in acp
    assert "joinPublic" not in acp


def test_listing_hygiene_surfaces_exist() -> None:
    """Anyone without view_forum must not see the board on public surfaces."""
    forum = FORUM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "function getListableForums",
        "function hasListableDescendant",
        "function isListableToUser",
        "function filterForumsVisibleToUser",
        "function getFeedTopics",
        "function feedForumIdsForScope",
        "Anyone without view_forum must not see the forum or an empty parent",
        "empty parent categories are dropped",
    ):
        assert needle in forum, f"Expected {needle} in AP_Forum"

    front = FRONT_CLASS.read_text(encoding="utf-8")
    for needle in (
        "CANNOT_VIEW_MESSAGE",
        "You cannot view this.",
        "function denyUnviewableForum",
        "ap_forum_cannot_view",
        "Do not leave a forum view slug that themes could use as a teaser",
        "Direct URL may stuff forum_s",
        "function userCanListForum",
        "forum_search_ready",
        "do not broaden to the public index",
        "do not broaden to all boards",
        "Stuffed cannot_view + leftover name/slug/search term",
    ):
        assert needle in front, f"Expected {needle} in AP_Forum_Front"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_get_listable_forums" in fn
    assert "function ap_forum_is_listable_to_user" in fn

    nav = (ROOT / "ap-includes" / "class-ap-nav-menu.php").read_text(encoding="utf-8")
    assert "function forumItemIsListable" in nav
    assert "view_forum" in nav

    rest = (ROOT / "ap-includes" / "class-ap-rest.php").read_text(encoding="utf-8")
    assert "function canViewForumResource" in rest
    assert "getListableForums" in rest
    assert "function isForumRestRequest" in rest
    assert "function shouldDenyForumRest" in rest
    assert "function cannotViewResponse" in rest
    assert "function forumRestScopeIsListable" in rest
    assert "You cannot view this." in rest
    assert "Do not interpolate board name or slug" in rest

    sitemap = (ROOT / "ap-includes" / "class-ap-sitemap.php").read_text(encoding="utf-8")
    for needle in (
        "getListableForums",
        "function isForumSitemapRequest",
        "You cannot view this.",
        "empty parent",
        "Do not interpolate board name or slug",
        "isDeniedForumSitemap",
        "forumSitemapScopeIsListable",
        "listableForumsForSitemap",
    ):
        assert needle in sitemap, f"Expected {needle} in AP_Sitemap"

    feed = (ROOT / "ap-includes" / "class-ap-feed.php").read_text(encoding="utf-8")
    for needle in (
        "function isForumFeedRequest",
        "serveForumFeed",
        "You cannot view this.",
        "empty parent",
        "Do not interpolate board name or slug",
        "isDeniedForumFeed",
        "forumFeedScopeIsListable",
    ):
        assert needle in feed, f"Expected {needle} in AP_Feed"

    theme = (ROOT / "ap-includes" / "class-ap-theme.php").read_text(encoding="utf-8")
    assert "ap_forum_cannot_view" in theme

    seo = (ROOT / "ap-includes" / "class-ap-seo.php").read_text(encoding="utf-8")
    assert "leftover forum_id / slug as canonical" in seo

    query_src = (ROOT / "ap-includes" / "class-ap-query.php").read_text(encoding="utf-8")
    assert "win over home/front/search" in query_src

    four_oh_four = (ROOT / "ap-content" / "themes" / "agora" / "404.php").read_text(
        encoding="utf-8"
    )
    assert "ap_forum_cannot_view" in four_oh_four
    assert "You cannot view this." in four_oh_four
    assert "forum_name" not in four_oh_four
    assert "forum_slug" not in four_oh_four

    forum_index = (ROOT / "ap-content" / "themes" / "agora" / "forum.php").read_text(
        encoding="utf-8"
    )
    assert "agora_get_forum_index_data" in forum_index

    phpunit = ROOT / "tests" / "Forum" / "ForumGroupsPermissionsTest.php"
    phpunit_src = phpunit.read_text(encoding="utf-8")
    assert "testThisGroupOnlyBoardIndexHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlySearchHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyFeedsHideForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlySitemapHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyRestHidesForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyPrettyUrlsHideForumAndEmptyParent" in phpunit_src
    assert "testThisGroupOnlyGuestAndNonMemberCannotListOrOpenBoard" in phpunit_src
    assert "ZX9CapstoneCellar" in phpunit_src
    assert "ZX9CapstoneVault" in phpunit_src
    assert "ap-forum--index" in phpunit_src
    assert "ap-forum--search" in phpunit_src
    assert "applyToQuery" in phpunit_src
    assert "ZX9IndexCellar" in phpunit_src
    assert "ZX9StaffCellar" in phpunit_src
    assert "ZX9SearchCellar" in phpunit_src
    assert "ZX9FeedCellar" in phpunit_src
    assert "ZX9FeedVault" in phpunit_src
    assert "ZX9SitemapCellar" in phpunit_src
    assert "ZX9SitemapVault" in phpunit_src
    assert "ZX9RestCellar" in phpunit_src
    assert "ZX9RestVault" in phpunit_src
    assert "ZX9PrettyCellar" in phpunit_src
    assert "ZX9PrettyVault" in phpunit_src
    assert "isForumFeedRequest" in phpunit_src
    assert "isForumSitemapRequest" in phpunit_src
    assert "isForumRestRequest" in phpunit_src
    assert "captureRestServe" in phpunit_src
    assert "capturePrettyUrlPage" in phpunit_src
    assert "assertForumPrettyDenied" in phpunit_src
    assert "searchForQuery" in phpunit_src


def test_guest_and_non_member_cannot_list_or_open_group_only_board() -> None:
    """Charter: guest/non-member cannot list or open; member can; feeds omit; ACP lists."""
    phpunit = ROOT / "tests" / "Forum" / "ForumGroupsPermissionsTest.php"
    src = phpunit.read_text(encoding="utf-8")
    assert "function testThisGroupOnlyGuestAndNonMemberCannotListOrOpenBoard" in src
    for needle in (
        "ZX9CapstoneCellar",
        "ZX9CapstoneVault",
        "ZX9CapstoneSquare",
        "ZX9CapstoneSecretToken",
        "isListableToUser(0, $secretId",
        "isListableToUser($outsider, $secretId",
        "isListableToUser($member, $secretId",
        "getIndexData($this->db, ['user_id' => 0])",
        "getIndexData($this->db, ['user_id' => $outsider])",
        "forumViewArgsForUser(0, $secretSlug)",
        "forumViewArgsForUser($outsider, $secretSlug)",
        "forumViewArgsForUser($member, $secretSlug)",
        "getFeedTopics(['user_id' => 0]",
        "forums/feed",
        "buildProvider('forums'",
        "buildProvider('topics'",
        "route' => '/ap/v1/forums'",
        "AP_Forums_List_Table",
        "AP_Admin_Forum_Edit::renderForm",
        "value=\"group_only\" selected",
    ):
        assert needle in src, f"Expected {needle} in capstone group-only test"

    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        require {repr(str(ROOT / "ap-includes/class-ap-options.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-user.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-roles.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-forum.php"))};
        require {repr(str(GROUP_CLASS))};
        require {repr(str(PERM_CLASS))};
        require {repr(str(ROOT / "ap-includes/functions.php"))};
        require {repr(str(ROOT / "ap-includes/hooks.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-query.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-rewrite.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-session.php"))};
        require {repr(str(FRONT_CLASS))};
        require {repr(str(ROOT / "ap-includes/class-ap-rest.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-sitemap.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-feed.php"))};
        require {repr(str(ROOT / "ap-admin/includes/class-ap-forums-list-table.php"))};

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();
        AP_Roles::ensureDefaults($db);
        AP_Forum_Permissions::ensureDefaults($db);
        AP_Group::ensureSystemGroups($db);
        AP_Options::update('home', 'https://example.test', $db);
        AP_Options::update('siteurl', 'https://example.test', $db);
        AP_Options::update('permalink_structure', '/%postname%/', $db);
        AP_Options::update('ap_module_forum', '1', $db);
        AP_Options::update('rest_api_enabled', '1', $db);
        AP_Options::update('sitemap_enabled', '1', $db);
        $GLOBALS['apdb'] = $db;

        $vip = AP_Group::create(['group_name' => 'Capstone VIP', 'group_type' => 'closed'], $db);
        $cat = AP_Forum::insertForum(['forum_name' => 'CapstoneCellar', 'forum_type' => 'category'], $db);
        $secret = AP_Forum::insertForum(['forum_name' => 'CapstoneVault', 'forum_type' => 'forum', 'parent_id' => $cat], $db);
        $public = AP_Forum::insertForum(['forum_name' => 'CapstoneSquare', 'forum_type' => 'forum'], $db);
        if ($vip < 1 || $cat < 1 || $secret < 1 || $public < 1) {{
            fwrite(STDERR, "fixture insert failed\\n");
            exit(2);
        }}
        if (!AP_Forum_Permissions::saveAccessFromForm($secret, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $db)) {{
            fwrite(STDERR, "group_only apply failed\\n");
            exit(3);
        }}

        $mk = static function (string $login, string $email, string $role) use ($db): int {{
            $r = AP_User::create([
                'user_login' => $login,
                'user_email' => $email,
                'user_pass' => 'password-ok-123',
                'display_name' => $login,
            ], $db);
            if (empty($r['ok'])) {{
                fwrite(STDERR, "user create failed: $login\\n");
                exit(4);
            }}
            $id = (int) $r['id'];
            AP_Roles::setUserRole($id, $role, $db);
            return $id;
        }};
        $outsider = $mk('cap_out', 'cap_out@example.test', 'subscriber');
        $member = $mk('cap_vip', 'cap_vip@example.test', 'subscriber');
        $admin = $mk('cap_admin', 'cap_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $db);
        $topic = AP_Forum::createTopic([
            'forum_id' => $secret,
            'topic_title' => 'CapstoneSecretToken thread',
            'content' => 'hidden body',
            'poster_id' => $member,
        ], $db);
        AP_Forum::createTopic([
            'forum_id' => $public,
            'topic_title' => 'CapstoneTownHello thread',
            'content' => 'public body',
            'poster_id' => $outsider,
        ], $db);
        if ($topic < 1) {{
            fwrite(STDERR, "topic create failed\\n");
            exit(5);
        }}

        if (AP_Forum::isListableToUser(0, $secret, $db) || AP_Forum::isListableToUser($outsider, $secret, $db)) {{
            fwrite(STDERR, "outsider listed secret board\\n");
            exit(6);
        }}
        if (!AP_Forum::isListableToUser($member, $secret, $db) || !AP_Forum::isListableToUser($admin, $secret, $db)) {{
            fwrite(STDERR, "member/admin cannot list secret board\\n");
            exit(7);
        }}

        $indexBlob = json_encode(AP_Forum::getIndexData($db, ['user_id' => 0])) ?: '';
        if (str_contains($indexBlob, 'CapstoneCellar') || str_contains($indexBlob, 'CapstoneVault')) {{
            fwrite(STDERR, "guest index leaked category or board\\n");
            exit(8);
        }}
        if (!str_contains($indexBlob, 'CapstoneSquare')) {{
            fwrite(STDERR, "guest index missing public board\\n");
            exit(9);
        }}

        $guestOpen = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_id' => $secret,
        ], $db);
        if (empty($guestOpen['ap_forum_cannot_view']) || ($guestOpen['forum_name'] ?? 'x') !== '') {{
            fwrite(STDERR, "guest opened secret board\\n");
            exit(10);
        }}

        AP_Rewrite::resetCache();
        $feedTopics = json_encode(AP_Forum::getFeedTopics(['user_id' => 0], $db)) ?: '';
        $rss = AP_Feed::serve(AP_Rewrite::parseRequest('forums/feed', [], $db), $db, false);
        $forumSitemap = AP_Sitemap::buildProvider('forums', 1, $db);
        $topicSitemap = AP_Sitemap::buildProvider('topics', 1, $db);
        foreach ([$feedTopics, $rss, $forumSitemap, $topicSitemap] as $blob) {{
            if (str_contains($blob, 'CapstoneVault') || str_contains($blob, 'CapstoneSecretToken') || str_contains($blob, 'CapstoneCellar')) {{
                fwrite(STDERR, "rss/sitemap leaked group-only board\\n");
                exit(11);
            }}
        }}
        if (!str_contains($rss, 'CapstoneTownHello')) {{
            fwrite(STDERR, "forum RSS missing public topic\\n");
            exit(15);
        }}

        $rest = AP_Rest::dispatch(['method' => 'GET', 'route' => '/ap/v1/forums', 'user_id' => 0], $db);
        $restJson = json_encode($rest['data'] ?? null) ?: '';
        if (str_contains($restJson, 'CapstoneVault') || str_contains($restJson, 'CapstoneCellar')) {{
            fwrite(STDERR, "REST list leaked group-only board\\n");
            exit(12);
        }}
        $restGet = AP_Rest::dispatch(['method' => 'GET', 'route' => '/ap/v1/forums/' . $secret, 'user_id' => 0], $db);
        if ((int) ($restGet['status'] ?? 0) !== 404) {{
            fwrite(STDERR, "REST GET secret did not 404\\n");
            exit(13);
        }}

        $acp = new AP_Forums_List_Table($db);
        $acp->prepareItems([]);
        $names = [];
        foreach ($acp->items as $item) {{
            $names[] = (string) ($item->forum_name ?? '');
        }}
        if (!in_array('CapstoneVault', $names, true) || !in_array('CapstoneCellar', $names, true)) {{
            fwrite(STDERR, "ACP list missing group-only board\\n");
            exit(14);
        }}

        echo "ok\\n";
        """
    )
    proc = subprocess.run(
        [_php_bin(), "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stderr + proc.stdout
    assert "ok" in proc.stdout


def test_permissions_class_api() -> None:
    src = PERM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Permissions",
        "function ensureDefaults",
        "function setPermission",
        "function userCan",
        "function getUserPermissions",
        "function applyGroupOnlyAccess",
        "PERM_VIEW",
        "PERM_POST_TOPICS",
        "PERM_MODERATE",
        "FORUM_GLOBAL",
    ):
        assert needle in src, f"Expected {needle} in AP_Forum_Permissions"


def test_wired_into_bootstrap_functions_tables() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-group.php" in boot
    assert "class-ap-forum-permissions.php" in boot

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_create_group",
        "function ap_add_group_member",
        "function ap_user_can_forum",
        "function ap_ensure_forum_permission_defaults",
        "function ap_set_forum_permission",
    ):
        assert needle in fn, f"Expected {needle} in functions.php"

    lc = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "forum_permissions" in lc

    forum = FORUM_CLASS.read_text(encoding="utf-8")
    assert "forum_permissions" in forum

    installer = INSTALLER.read_text(encoding="utf-8")
    assert "ensureSystemGroups" in installer
    assert "ensureDefaults" in installer


def test_migration_applies_and_acl_works_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        require {repr(str(ROOT / "ap-includes/class-ap-options.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-roles.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-forum.php"))};
        require {repr(str(GROUP_CLASS))};
        require {repr(str(PERM_CLASS))};
        require {repr(str(ROOT / "ap-includes/functions.php"))};

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION < 7) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 7\\n");
            exit(2);
        }}
        $applied = $m->migrate();
        if (count($applied) < 7 || (int) $applied[6]['version'] !== 7) {{
            fwrite(STDERR, "version 7 not applied\\n");
            exit(3);
        }}
        $name = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_forum_permissions']
        );
        if ($name !== 'ap_forum_permissions') {{
            fwrite(STDERR, "forum_permissions table missing\\n");
            exit(4);
        }}
        AP_Roles::ensureDefaults($db);
        AP_Forum_Permissions::ensureDefaults($db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Smoke'], $db);
        if ($forumId < 1) {{
            fwrite(STDERR, "forum insert failed\\n");
            exit(5);
        }}
        if (!AP_Forum_Permissions::userCan(0, $forumId, 'view_forum', $db)) {{
            fwrite(STDERR, "guest cannot view\\n");
            exit(6);
        }}
        if (AP_Forum_Permissions::userCan(0, $forumId, 'post_topics', $db)) {{
            fwrite(STDERR, "guest should not post\\n");
            exit(7);
        }}
        $g = AP_Group::create(['group_name' => 'Smoke VIP'], $db);
        if ($g < 1) {{
            fwrite(STDERR, "group create failed\\n");
            exit(8);
        }}
        echo "ok\\n";
        """
    )
    proc = subprocess.run(
        [_php_bin(), "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stderr + proc.stdout
    assert "ok" in proc.stdout
