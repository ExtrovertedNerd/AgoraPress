<?php

/**
 * Migration 0005 — dedicated forum tables.
 *
 * Creates (SPEC §4 Forums):
 * - {prefix}forums         — hierarchical categories & forums
 * - {prefix}topics         — topics (sticky, locked, announcements)
 * - {prefix}forum_posts    — posts/replies (separate from blog comments)
 * - {prefix}groups         — user groups (forum permissions foundation)
 * - {prefix}group_members  — group membership + roles
 * - {prefix}messages       — private messages
 * - {prefix}ranks          — post-count / special ranks
 * - {prefix}reports        — moderation reports
 * - {prefix}online         — who’s online / session presence
 *
 * Dedicated tables for performance while sharing users, capabilities, options,
 * and media with the CMS core. Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 * Table prefix is honored via AP_DB.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0005_Forum_Tables', false)) {
    /**
     * Create dedicated forum subsystem tables.
     */
    final class AP_Migration_0005_Forum_Tables implements AP_Migration
    {
        public function version(): int
        {
            return 5;
        }

        public function description(): string
        {
            return 'Forum tables: forums, topics, forum_posts, groups, group_members, messages, ranks, reports, online';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply forum tables schema: '
                        . ($db->lastError() ?? 'unknown error')
                    );
                }
            }
        }

        /**
         * Ordered CREATE TABLE / INDEX statements for the active driver.
         *
         * @return list<string>
         */
        private function createStatements(AP_DB $db, string $driver): array
        {
            $t = static function (string $base) use ($db): string {
                return $db->quoteIdentifier($db->table($base));
            };

            $forums = $t('forums');
            $topics = $t('topics');
            $forumPosts = $t('forum_posts');
            $groups = $t('groups');
            $groupMembers = $t('group_members');
            $messages = $t('messages');
            $ranks = $t('ranks');
            $reports = $t('reports');
            $online = $t('online');
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements(
                    $forums,
                    $topics,
                    $forumPosts,
                    $groups,
                    $groupMembers,
                    $messages,
                    $ranks,
                    $reports,
                    $online
                ),
                'pgsql' => $this->pgsqlStatements(
                    $forums,
                    $topics,
                    $forumPosts,
                    $groups,
                    $groupMembers,
                    $messages,
                    $ranks,
                    $reports,
                    $online,
                    $idx
                ),
                default => $this->sqliteStatements(
                    $forums,
                    $topics,
                    $forumPosts,
                    $groups,
                    $groupMembers,
                    $messages,
                    $ranks,
                    $reports,
                    $online,
                    $idx
                ),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(
            string $forums,
            string $topics,
            string $forumPosts,
            string $groups,
            string $groupMembers,
            string $messages,
            string $ranks,
            string $reports,
            string $online
        ): array {
            return [
                // forum_type: category | forum | link
                // forum_status: open | closed | hidden
                "CREATE TABLE {$forums} ("
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `forum_type` VARCHAR(20) NOT NULL DEFAULT 'forum',"
                    . " `forum_status` VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " `forum_name` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `forum_slug` VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' `forum_desc` TEXT NOT NULL,'
                    . ' `forum_order` INT NOT NULL DEFAULT 0,'
                    . ' `topic_count` BIGINT NOT NULL DEFAULT 0,'
                    // post_count = approved opening posts + replies (not replies-only).
                    . ' `post_count` BIGINT NOT NULL DEFAULT 0,'
                    . ' `last_post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `last_poster_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `last_post_time` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' `last_topic_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`forum_id`),'
                    . ' KEY `parent_id` (`parent_id`),'
                    . ' KEY `forum_slug` (`forum_slug`(191)),'
                    . ' KEY `forum_order` (`parent_id`, `forum_order`, `forum_id`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                // topic_status: open | locked | moved | deleted
                // topic_type: normal | sticky | announce | global
                "CREATE TABLE {$topics} ("
                    . ' `topic_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `topic_title` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `topic_slug` VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' `topic_poster` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `topic_status` VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " `topic_type` VARCHAR(20) NOT NULL DEFAULT 'normal',"
                    . ' `topic_approved` TINYINT(1) NOT NULL DEFAULT 1,'
                    . ' `topic_views` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `reply_count` BIGINT NOT NULL DEFAULT 0,'
                    . ' `first_post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `last_post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `last_poster_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `topic_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `topic_modified` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . " `topic_last_post_time` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' PRIMARY KEY (`topic_id`),'
                    . ' KEY `forum_id` (`forum_id`),'
                    . ' KEY `topic_poster` (`topic_poster`),'
                    . ' KEY `forum_type_time` (`forum_id`, `topic_type`, `topic_last_post_time`),'
                    . ' KEY `topic_slug` (`topic_slug`(191)),'
                    . ' KEY `topic_approved` (`topic_approved`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$forumPosts} ("
                    . ' `post_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `topic_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `poster_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `post_subject` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `post_content` LONGTEXT NOT NULL,'
                    . ' `post_content_filtered` LONGTEXT NOT NULL,'
                    . " `poster_ip` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' `post_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `post_modified` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' `post_approved` TINYINT(1) NOT NULL DEFAULT 1,'
                    . ' `post_reported` TINYINT(1) NOT NULL DEFAULT 0,'
                    . " `post_edit_reason` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `post_edit_user` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `post_edit_time` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' `post_edit_count` INT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `post_position` INT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`post_id`),'
                    . ' KEY `topic_id` (`topic_id`),'
                    . ' KEY `forum_id` (`forum_id`),'
                    . ' KEY `poster_id` (`poster_id`),'
                    . ' KEY `topic_time` (`topic_id`, `post_time`, `post_id`),'
                    . ' KEY `post_approved` (`post_approved`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                // group_type: open | closed | hidden | system
                "CREATE TABLE {$groups} ("
                    . ' `group_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `group_name` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `group_slug` VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' `group_desc` TEXT NOT NULL,'
                    . " `group_type` VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . ' `member_count` BIGINT NOT NULL DEFAULT 0,'
                    . ' `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (`group_id`),'
                    . ' UNIQUE KEY `group_slug` (`group_slug`),'
                    . ' KEY `group_name` (`group_name`(191))'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                // member_role: member | moderator | leader
                "CREATE TABLE {$groupMembers} ("
                    . ' `membership_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `group_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `member_role` VARCHAR(20) NOT NULL DEFAULT 'member',"
                    . ' `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (`membership_id`),'
                    . ' UNIQUE KEY `group_user` (`group_id`, `user_id`),'
                    . ' KEY `user_id` (`user_id`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$messages} ("
                    . ' `message_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `sender_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `recipient_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `subject` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `message_content` LONGTEXT NOT NULL,'
                    . ' `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' `read_at` DATETIME DEFAULT NULL,'
                    . ' `sender_deleted` TINYINT(1) NOT NULL DEFAULT 0,'
                    . ' `recipient_deleted` TINYINT(1) NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`message_id`),'
                    . ' KEY `sender_id` (`sender_id`),'
                    . ' KEY `recipient_id` (`recipient_id`),'
                    . ' KEY `parent_id` (`parent_id`),'
                    . ' KEY `recipient_read` (`recipient_id`, `recipient_deleted`, `read_at`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$ranks} ("
                    . ' `rank_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `rank_title` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `rank_min_posts` INT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `rank_special` TINYINT(1) NOT NULL DEFAULT 0,'
                    . " `rank_image` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `rank_order` INT NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`rank_id`),'
                    . ' KEY `rank_min_posts` (`rank_min_posts`),'
                    . ' KEY `rank_order` (`rank_order`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                // report_type: post | topic | user | message
                // report_status: open | closed | dismissed
                "CREATE TABLE {$reports} ("
                    . ' `report_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `reporter_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `report_type` VARCHAR(20) NOT NULL DEFAULT 'post',"
                    . ' `report_object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `report_reason` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `report_details` TEXT NOT NULL,'
                    . " `report_status` VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . ' `reported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' `resolved_at` DATETIME DEFAULT NULL,'
                    . ' `resolved_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`report_id`),'
                    . ' KEY `report_status` (`report_status`),'
                    . ' KEY `report_object` (`report_type`, `report_object_id`),'
                    . ' KEY `reporter_id` (`reporter_id`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$online} ("
                    . ' `online_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `session_key` VARCHAR(64) NOT NULL DEFAULT '',"
                    . " `session_ip` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' `session_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `session_page` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `session_forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `session_topic_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `guest_name` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' PRIMARY KEY (`online_id`),'
                    . ' UNIQUE KEY `session_key` (`session_key`),'
                    . ' KEY `user_id` (`user_id`),'
                    . ' KEY `session_time` (`session_time`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(
            string $forums,
            string $topics,
            string $forumPosts,
            string $groups,
            string $groupMembers,
            string $messages,
            string $ranks,
            string $reports,
            string $online,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$forums} ("
                    . ' forum_id BIGSERIAL PRIMARY KEY,'
                    . ' parent_id BIGINT NOT NULL DEFAULT 0,'
                    . " forum_type VARCHAR(20) NOT NULL DEFAULT 'forum',"
                    . " forum_status VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " forum_name VARCHAR(255) NOT NULL DEFAULT '',"
                    . " forum_slug VARCHAR(200) NOT NULL DEFAULT '',"
                    . " forum_desc TEXT NOT NULL DEFAULT '',"
                    . ' forum_order INTEGER NOT NULL DEFAULT 0,'
                    . ' topic_count BIGINT NOT NULL DEFAULT 0,'
                    // post_count = approved opening posts + replies (not replies-only).
                    . ' post_count BIGINT NOT NULL DEFAULT 0,'
                    . ' last_post_id BIGINT NOT NULL DEFAULT 0,'
                    . ' last_poster_id BIGINT NOT NULL DEFAULT 0,'
                    . " last_post_time TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' last_topic_id BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}forums_parent_id ON {$forums} (parent_id)",
                "CREATE INDEX {$idx}forums_slug ON {$forums} (forum_slug)",
                "CREATE INDEX {$idx}forums_order ON {$forums} (parent_id, forum_order, forum_id)",

                "CREATE TABLE {$topics} ("
                    . ' topic_id BIGSERIAL PRIMARY KEY,'
                    . ' forum_id BIGINT NOT NULL DEFAULT 0,'
                    . " topic_title VARCHAR(255) NOT NULL DEFAULT '',"
                    . " topic_slug VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' topic_poster BIGINT NOT NULL DEFAULT 0,'
                    . " topic_status VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " topic_type VARCHAR(20) NOT NULL DEFAULT 'normal',"
                    . ' topic_approved SMALLINT NOT NULL DEFAULT 1,'
                    . ' topic_views BIGINT NOT NULL DEFAULT 0,'
                    . ' reply_count BIGINT NOT NULL DEFAULT 0,'
                    . ' first_post_id BIGINT NOT NULL DEFAULT 0,'
                    . ' last_post_id BIGINT NOT NULL DEFAULT 0,'
                    . ' last_poster_id BIGINT NOT NULL DEFAULT 0,'
                    . ' topic_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " topic_modified TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . " topic_last_post_time TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00'"
                    . ')',
                "CREATE INDEX {$idx}topics_forum_id ON {$topics} (forum_id)",
                "CREATE INDEX {$idx}topics_poster ON {$topics} (topic_poster)",
                "CREATE INDEX {$idx}topics_forum_type_time ON {$topics} (forum_id, topic_type, topic_last_post_time)",
                "CREATE INDEX {$idx}topics_slug ON {$topics} (topic_slug)",
                "CREATE INDEX {$idx}topics_approved ON {$topics} (topic_approved)",

                "CREATE TABLE {$forumPosts} ("
                    . ' post_id BIGSERIAL PRIMARY KEY,'
                    . ' topic_id BIGINT NOT NULL DEFAULT 0,'
                    . ' forum_id BIGINT NOT NULL DEFAULT 0,'
                    . ' poster_id BIGINT NOT NULL DEFAULT 0,'
                    . " post_subject VARCHAR(255) NOT NULL DEFAULT '',"
                    . " post_content TEXT NOT NULL DEFAULT '',"
                    . " post_content_filtered TEXT NOT NULL DEFAULT '',"
                    . " poster_ip VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' post_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " post_modified TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' post_approved SMALLINT NOT NULL DEFAULT 1,'
                    . ' post_reported SMALLINT NOT NULL DEFAULT 0,'
                    . " post_edit_reason VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' post_edit_user BIGINT NOT NULL DEFAULT 0,'
                    . " post_edit_time TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' post_edit_count INTEGER NOT NULL DEFAULT 0,'
                    . ' post_position INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}forum_posts_topic_id ON {$forumPosts} (topic_id)",
                "CREATE INDEX {$idx}forum_posts_forum_id ON {$forumPosts} (forum_id)",
                "CREATE INDEX {$idx}forum_posts_poster_id ON {$forumPosts} (poster_id)",
                "CREATE INDEX {$idx}forum_posts_topic_time ON {$forumPosts} (topic_id, post_time, post_id)",
                "CREATE INDEX {$idx}forum_posts_approved ON {$forumPosts} (post_approved)",

                "CREATE TABLE {$groups} ("
                    . ' group_id BIGSERIAL PRIMARY KEY,'
                    . " group_name VARCHAR(255) NOT NULL DEFAULT '',"
                    . " group_slug VARCHAR(200) NOT NULL DEFAULT '',"
                    . " group_desc TEXT NOT NULL DEFAULT '',"
                    . " group_type VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . ' member_count BIGINT NOT NULL DEFAULT 0,'
                    . ' created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                    . ')',
                "CREATE UNIQUE INDEX {$idx}groups_slug ON {$groups} (group_slug)",
                "CREATE INDEX {$idx}groups_name ON {$groups} (group_name)",

                "CREATE TABLE {$groupMembers} ("
                    . ' membership_id BIGSERIAL PRIMARY KEY,'
                    . ' group_id BIGINT NOT NULL DEFAULT 0,'
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . " member_role VARCHAR(20) NOT NULL DEFAULT 'member',"
                    . ' joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                    . ')',
                "CREATE UNIQUE INDEX {$idx}group_members_group_user ON {$groupMembers} (group_id, user_id)",
                "CREATE INDEX {$idx}group_members_user_id ON {$groupMembers} (user_id)",

                "CREATE TABLE {$messages} ("
                    . ' message_id BIGSERIAL PRIMARY KEY,'
                    . ' sender_id BIGINT NOT NULL DEFAULT 0,'
                    . ' recipient_id BIGINT NOT NULL DEFAULT 0,'
                    . ' parent_id BIGINT NOT NULL DEFAULT 0,'
                    . " subject VARCHAR(255) NOT NULL DEFAULT '',"
                    . " message_content TEXT NOT NULL DEFAULT '',"
                    . ' sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' read_at TIMESTAMP DEFAULT NULL,'
                    . ' sender_deleted SMALLINT NOT NULL DEFAULT 0,'
                    . ' recipient_deleted SMALLINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}messages_sender ON {$messages} (sender_id)",
                "CREATE INDEX {$idx}messages_recipient ON {$messages} (recipient_id)",
                "CREATE INDEX {$idx}messages_parent ON {$messages} (parent_id)",
                "CREATE INDEX {$idx}messages_recipient_read ON {$messages} (recipient_id, recipient_deleted, read_at)",

                "CREATE TABLE {$ranks} ("
                    . ' rank_id BIGSERIAL PRIMARY KEY,'
                    . " rank_title VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' rank_min_posts INTEGER NOT NULL DEFAULT 0,'
                    . ' rank_special SMALLINT NOT NULL DEFAULT 0,'
                    . " rank_image VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' rank_order INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}ranks_min_posts ON {$ranks} (rank_min_posts)",
                "CREATE INDEX {$idx}ranks_order ON {$ranks} (rank_order)",

                "CREATE TABLE {$reports} ("
                    . ' report_id BIGSERIAL PRIMARY KEY,'
                    . ' reporter_id BIGINT NOT NULL DEFAULT 0,'
                    . " report_type VARCHAR(20) NOT NULL DEFAULT 'post',"
                    . ' report_object_id BIGINT NOT NULL DEFAULT 0,'
                    . " report_reason VARCHAR(255) NOT NULL DEFAULT '',"
                    . " report_details TEXT NOT NULL DEFAULT '',"
                    . " report_status VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . ' reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' resolved_at TIMESTAMP DEFAULT NULL,'
                    . ' resolved_by BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}reports_status ON {$reports} (report_status)",
                "CREATE INDEX {$idx}reports_object ON {$reports} (report_type, report_object_id)",
                "CREATE INDEX {$idx}reports_reporter ON {$reports} (reporter_id)",

                "CREATE TABLE {$online} ("
                    . ' online_id BIGSERIAL PRIMARY KEY,'
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . " session_key VARCHAR(64) NOT NULL DEFAULT '',"
                    . " session_ip VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' session_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " session_page VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' session_forum_id BIGINT NOT NULL DEFAULT 0,'
                    . ' session_topic_id BIGINT NOT NULL DEFAULT 0,'
                    . " guest_name VARCHAR(100) NOT NULL DEFAULT ''"
                    . ')',
                "CREATE UNIQUE INDEX {$idx}online_session_key ON {$online} (session_key)",
                "CREATE INDEX {$idx}online_user_id ON {$online} (user_id)",
                "CREATE INDEX {$idx}online_session_time ON {$online} (session_time)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(
            string $forums,
            string $topics,
            string $forumPosts,
            string $groups,
            string $groupMembers,
            string $messages,
            string $ranks,
            string $reports,
            string $online,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$forums} ("
                    . ' forum_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' parent_id INTEGER NOT NULL DEFAULT 0,'
                    . " forum_type TEXT NOT NULL DEFAULT 'forum',"
                    . " forum_status TEXT NOT NULL DEFAULT 'open',"
                    . " forum_name TEXT NOT NULL DEFAULT '',"
                    . " forum_slug TEXT NOT NULL DEFAULT '',"
                    . " forum_desc TEXT NOT NULL DEFAULT '',"
                    . ' forum_order INTEGER NOT NULL DEFAULT 0,'
                    . ' topic_count INTEGER NOT NULL DEFAULT 0,'
                    // post_count = approved opening posts + replies (not replies-only).
                    . ' post_count INTEGER NOT NULL DEFAULT 0,'
                    . ' last_post_id INTEGER NOT NULL DEFAULT 0,'
                    . ' last_poster_id INTEGER NOT NULL DEFAULT 0,'
                    . " last_post_time TEXT NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' last_topic_id INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}forums_parent_id ON {$forums} (parent_id)",
                "CREATE INDEX {$idx}forums_slug ON {$forums} (forum_slug)",
                "CREATE INDEX {$idx}forums_order ON {$forums} (parent_id, forum_order, forum_id)",

                "CREATE TABLE {$topics} ("
                    . ' topic_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' forum_id INTEGER NOT NULL DEFAULT 0,'
                    . " topic_title TEXT NOT NULL DEFAULT '',"
                    . " topic_slug TEXT NOT NULL DEFAULT '',"
                    . ' topic_poster INTEGER NOT NULL DEFAULT 0,'
                    . " topic_status TEXT NOT NULL DEFAULT 'open',"
                    . " topic_type TEXT NOT NULL DEFAULT 'normal',"
                    . ' topic_approved INTEGER NOT NULL DEFAULT 1,'
                    . ' topic_views INTEGER NOT NULL DEFAULT 0,'
                    . ' reply_count INTEGER NOT NULL DEFAULT 0,'
                    . ' first_post_id INTEGER NOT NULL DEFAULT 0,'
                    . ' last_post_id INTEGER NOT NULL DEFAULT 0,'
                    . ' last_poster_id INTEGER NOT NULL DEFAULT 0,'
                    . " topic_time TEXT NOT NULL DEFAULT '',"
                    . " topic_modified TEXT NOT NULL DEFAULT '',"
                    . " topic_last_post_time TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}topics_forum_id ON {$topics} (forum_id)",
                "CREATE INDEX {$idx}topics_poster ON {$topics} (topic_poster)",
                "CREATE INDEX {$idx}topics_forum_type_time ON {$topics} (forum_id, topic_type, topic_last_post_time)",
                "CREATE INDEX {$idx}topics_slug ON {$topics} (topic_slug)",
                "CREATE INDEX {$idx}topics_approved ON {$topics} (topic_approved)",

                "CREATE TABLE {$forumPosts} ("
                    . ' post_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' topic_id INTEGER NOT NULL DEFAULT 0,'
                    . ' forum_id INTEGER NOT NULL DEFAULT 0,'
                    . ' poster_id INTEGER NOT NULL DEFAULT 0,'
                    . " post_subject TEXT NOT NULL DEFAULT '',"
                    . " post_content TEXT NOT NULL DEFAULT '',"
                    . " post_content_filtered TEXT NOT NULL DEFAULT '',"
                    . " poster_ip TEXT NOT NULL DEFAULT '',"
                    . " post_time TEXT NOT NULL DEFAULT '',"
                    . " post_modified TEXT NOT NULL DEFAULT '',"
                    . ' post_approved INTEGER NOT NULL DEFAULT 1,'
                    . ' post_reported INTEGER NOT NULL DEFAULT 0,'
                    . " post_edit_reason TEXT NOT NULL DEFAULT '',"
                    . ' post_edit_user INTEGER NOT NULL DEFAULT 0,'
                    . " post_edit_time TEXT NOT NULL DEFAULT '',"
                    . ' post_edit_count INTEGER NOT NULL DEFAULT 0,'
                    . ' post_position INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}forum_posts_topic_id ON {$forumPosts} (topic_id)",
                "CREATE INDEX {$idx}forum_posts_forum_id ON {$forumPosts} (forum_id)",
                "CREATE INDEX {$idx}forum_posts_poster_id ON {$forumPosts} (poster_id)",
                "CREATE INDEX {$idx}forum_posts_topic_time ON {$forumPosts} (topic_id, post_time, post_id)",
                "CREATE INDEX {$idx}forum_posts_approved ON {$forumPosts} (post_approved)",

                "CREATE TABLE {$groups} ("
                    . ' group_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " group_name TEXT NOT NULL DEFAULT '',"
                    . " group_slug TEXT NOT NULL DEFAULT '' UNIQUE,"
                    . " group_desc TEXT NOT NULL DEFAULT '',"
                    . " group_type TEXT NOT NULL DEFAULT 'open',"
                    . ' member_count INTEGER NOT NULL DEFAULT 0,'
                    . " created_at TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}groups_name ON {$groups} (group_name)",

                "CREATE TABLE {$groupMembers} ("
                    . ' membership_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' group_id INTEGER NOT NULL DEFAULT 0,'
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . " member_role TEXT NOT NULL DEFAULT 'member',"
                    . " joined_at TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE UNIQUE INDEX {$idx}group_members_group_user ON {$groupMembers} (group_id, user_id)",
                "CREATE INDEX {$idx}group_members_user_id ON {$groupMembers} (user_id)",

                "CREATE TABLE {$messages} ("
                    . ' message_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' sender_id INTEGER NOT NULL DEFAULT 0,'
                    . ' recipient_id INTEGER NOT NULL DEFAULT 0,'
                    . ' parent_id INTEGER NOT NULL DEFAULT 0,'
                    . " subject TEXT NOT NULL DEFAULT '',"
                    . " message_content TEXT NOT NULL DEFAULT '',"
                    . " sent_at TEXT NOT NULL DEFAULT '',"
                    . ' read_at TEXT DEFAULT NULL,'
                    . ' sender_deleted INTEGER NOT NULL DEFAULT 0,'
                    . ' recipient_deleted INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}messages_sender ON {$messages} (sender_id)",
                "CREATE INDEX {$idx}messages_recipient ON {$messages} (recipient_id)",
                "CREATE INDEX {$idx}messages_parent ON {$messages} (parent_id)",
                "CREATE INDEX {$idx}messages_recipient_read ON {$messages} (recipient_id, recipient_deleted, read_at)",

                "CREATE TABLE {$ranks} ("
                    . ' rank_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " rank_title TEXT NOT NULL DEFAULT '',"
                    . ' rank_min_posts INTEGER NOT NULL DEFAULT 0,'
                    . ' rank_special INTEGER NOT NULL DEFAULT 0,'
                    . " rank_image TEXT NOT NULL DEFAULT '',"
                    . ' rank_order INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}ranks_min_posts ON {$ranks} (rank_min_posts)",
                "CREATE INDEX {$idx}ranks_order ON {$ranks} (rank_order)",

                "CREATE TABLE {$reports} ("
                    . ' report_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' reporter_id INTEGER NOT NULL DEFAULT 0,'
                    . " report_type TEXT NOT NULL DEFAULT 'post',"
                    . ' report_object_id INTEGER NOT NULL DEFAULT 0,'
                    . " report_reason TEXT NOT NULL DEFAULT '',"
                    . " report_details TEXT NOT NULL DEFAULT '',"
                    . " report_status TEXT NOT NULL DEFAULT 'open',"
                    . " reported_at TEXT NOT NULL DEFAULT '',"
                    . ' resolved_at TEXT DEFAULT NULL,'
                    . ' resolved_by INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}reports_status ON {$reports} (report_status)",
                "CREATE INDEX {$idx}reports_object ON {$reports} (report_type, report_object_id)",
                "CREATE INDEX {$idx}reports_reporter ON {$reports} (reporter_id)",

                "CREATE TABLE {$online} ("
                    . ' online_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . " session_key TEXT NOT NULL DEFAULT '' UNIQUE,"
                    . " session_ip TEXT NOT NULL DEFAULT '',"
                    . " session_time TEXT NOT NULL DEFAULT '',"
                    . " session_page TEXT NOT NULL DEFAULT '',"
                    . ' session_forum_id INTEGER NOT NULL DEFAULT 0,'
                    . ' session_topic_id INTEGER NOT NULL DEFAULT 0,'
                    . " guest_name TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}online_user_id ON {$online} (user_id)",
                "CREATE INDEX {$idx}online_session_time ON {$online} (session_time)",
            ];
        }
    }
}

return new AP_Migration_0005_Forum_Tables();
