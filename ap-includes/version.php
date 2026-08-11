<?php

/**
 * AgoraPress version constants.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Current core version (SemVer). Pre-release beta for public packaging.
 */
define('AP_VERSION', '0.3.4-beta');

/**
 * Database schema version expected by this codebase.
 *
 * Integer (stored as string for historical/define symmetry). Bumped when a new
 * migration ships under ap-includes/schema/migrations/. Version 1: options /
 * users / usermeta. Version 2: posts / postmeta. Version 3: terms /
 * term_taxonomy / term_relationships. Version 4: comments / commentmeta.
 * Version 5: dedicated forum tables (forums, topics, forum_posts, groups,
 * group_members, messages, ranks, reports, online).
 * Version 6: forum_attachments (post/topic media links + quotas).
 * Version 7: forum_permissions (granular group × forum ACL).
 * Version 8: forum moderation (warnings + bans tables; reports in v5).
 * Version 9: forum unread tracking (topic_track + forum_track; online in v5).
 * Version 10: local analytics (analytics_hits + analytics_daily).
 * Version 11: forum post likes (forum_post_likes + forum_posts.like_count).
 * Version 12: topic type enum (standard | sticky | announcement | rules) + backfill.
 * {@see AP_Migrator} tracks applied versions in schema_migrations. Installer /
 * update path apply pending migrations until the database matches this target.
 */
define('AP_DB_VERSION', '12');
