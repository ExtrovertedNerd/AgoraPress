<?php

/**
 * Handles post visibility logic for scheduled publishing, private posts, and previews.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

final class AP_Post_Visibility
{
    /**
     * Publishes scheduled posts that are due.
     *
     * @param AP_DB $db
     * @param int   $now
     * @return int
     */
    public static function publishDueScheduled(AP_DB $db, int $now): int
    {
        $table = $db->quoteIdentifier($db->table('posts'));
        $idCol = $db->quoteIdentifier('ID');
        $statusCol = $db->quoteIdentifier('post_status');
        $dateCol = $db->quoteIdentifier('post_date');

        $sql = "SELECT {$idCol}, {$dateCol} FROM {$table} WHERE {$statusCol} = :status";
        $results = $db->getResults($sql, ['status' => 'future']);

        $publishedCount = 0;

        foreach ($results as $row) {
            $postDate = strtotime((string) $row->post_date);
            if ($postDate === false || $postDate > $now) {
                continue;
            }

            $id = (int) $row->ID;
            $existing = AP_Post::get($id, $db);
            $affected = $db->update('posts', ['post_status' => 'publish'], ['ID' => $id, 'post_status' => 'future']);

            if ($affected === 1) {
                $publishedCount++;
                if (function_exists('ap_do_action')) {
                    ap_do_action('ap_publish_future_post', $id);
                    ap_do_action('ap_post_updated', $id, AP_Post::get($id, $db), $existing);
                }
            }
        }

        return $publishedCount;
    }

    /**
     * Determines if a user can view a singular post.
     *
     * @param AP_Post $post
     * @param int     $userId
     * @param bool    $preview
     * @param AP_DB   $db
     * @return bool
     */
    public static function userCanViewSingular(AP_Post $post, int $userId, bool $preview, AP_DB $db): bool
    {
        $status = $post->post_status;

        if ($status === 'publish') {
            return true;
        }

        if (in_array($status, ['trash', 'auto-draft', 'inherit'], true)) {
            return false;
        }

        if ($userId < 1) {
            return false;
        }

        if (!class_exists('AP_Roles', false)) {
            return false;
        }

        if ($status === 'private') {
            return AP_Roles::userCan($userId, 'read_post', (int) $post->ID, $db);
        }

        if (in_array($status, ['draft', 'pending', 'future'], true)) {
            if (!$preview) {
                return false;
            }

            return AP_Roles::userCan($userId, 'edit_post', (int) $post->ID, $db);
        }

        return false;
    }

    /**
     * Resolves singular queries for restricted visibility posts.
     *
     * @param AP_Query               $query
     * @param array<string, mixed>   $vars
     * @param array<string, mixed>   $args
     * @param AP_DB|null             $db
     * @param int|null               $userId
     * @return AP_Query
     */
    public static function resolveSingular(
        AP_Query $query,
        array $vars,
        array $args,
        ?AP_DB $db,
        ?int $userId
    ): AP_Query {
        if (!$query->is_404) {
            return $query;
        }

        $isSingular = (
            (!empty($args['p']) || !empty($args['name']) || !empty($args['page_id']) || !empty($args['pagename']))
            && empty($args['ap_forum_view'])
        );

        if (!$isSingular) {
            return $query;
        }

        $preview = in_array(strtolower((string) ($vars['preview'] ?? '')), ['1', 'true', 'yes'], true);

        if ($userId === null) {
            $userId = function_exists('ap_get_current_user_id') ? ap_get_current_user_id($db) : 0;
        }

        if ($userId < 1) {
            return $query;
        }

        $probe = new AP_Query(
            array_merge($args, ['post_status' => ['private', 'draft', 'pending', 'future'], 'posts_per_page' => 1]),
            $db
        );

        $candidate = $probe->post;
        if (!$candidate instanceof AP_Post) {
            return $query;
        }

        // Front-end callers pass a null DB; fall back to the site connection.
        $checkDb = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if (!$checkDb instanceof AP_DB || !self::userCanViewSingular($candidate, $userId, $preview, $checkDb)) {
            return $query;
        }

        $found = new AP_Query(
            array_merge($args, ['post_status' => $candidate->post_status]),
            $db
        );

        $found->is_preview = $preview && $candidate->post_status !== 'private';

        if ($found->post_count === 0) {
            return $query;
        }

        return $found;
    }
}
