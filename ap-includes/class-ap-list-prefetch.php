<?php

/**
 * Batched list lookups.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Class for batched list lookups.
 */
final class AP_List_Prefetch
{
    /**
     * Get post counts for a list of user IDs.
     *
     * @param array<int|string> $userIds
     * @return array<int, int>
     */
    public static function postCounts(array $userIds, AP_DB $db): array
    {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $userIds),
                    static fn (int $i): bool => $i > 0
                )
            )
        );
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $postsTable = $db->table('posts');
        $pa = $db->quoteIdentifier('post_author');
        $pt = $db->quoteIdentifier('post_type');
        $ps = $db->quoteIdentifier('post_status');

        $sql = sprintf(
            'SELECT %s, COUNT(*) AS %s FROM %s ' .
            'WHERE %s IN (%s) ' .
            'AND %s IN (?, ?) ' .
            'AND %s NOT IN (?, ?, ?) ' .
            'GROUP BY %s',
            $pa,
            $db->quoteIdentifier('n'),
            $postsTable,
            $pa,
            $placeholders,
            $pt,
            $ps,
            $pa
        );

        $params = array_merge($ids, ['post', 'page', 'trash', 'auto-draft', 'inherit']);
        $results = $db->getResults($sql, $params);

        $counts = [];
        foreach ($ids as $uid) {
            $counts[$uid] = 0;
        }
        foreach ($results as $row) {
            $counts[(int) $row->post_author] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * Get term names for a list of post IDs.
     *
     * @param array<int|string> $postIds
     * @return array<int, list<string>>
     */
    public static function termNames(array $postIds, string $taxonomy, AP_DB $db): array
    {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $postIds),
                    static fn (int $i): bool => $i > 0
                )
            )
        );
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $trTable = $db->table('term_relationships');
        $ttTable = $db->table('term_taxonomy');
        $tTable = $db->table('terms');
        $objId = $db->quoteIdentifier('object_id');
        $name = $db->quoteIdentifier('name');
        $ttId = $db->quoteIdentifier('term_taxonomy_id');
        $termId = $db->quoteIdentifier('term_id');
        $tax = $db->quoteIdentifier('taxonomy');
        $termOrder = $db->quoteIdentifier('term_order');

        $sql = sprintf(
            'SELECT tr.%s, t.%s FROM %s tr ' .
            'INNER JOIN %s tt ON tr.%s = tt.%s ' .
            'INNER JOIN %s t ON tt.%s = t.%s ' .
            'WHERE tr.%s IN (%s) AND tt.%s = ? ' .
            'ORDER BY tr.%s ASC, tr.%s ASC, t.%s ASC',
            $objId,
            $name,
            $trTable,
            $ttTable,
            $ttId,
            $ttId,
            $tTable,
            $termId,
            $termId,
            $objId,
            $placeholders,
            $tax,
            $objId,
            $termOrder,
            $name
        );

        $results = $db->getResults($sql, array_merge($ids, [$taxonomy]));

        $names = [];
        foreach ($ids as $pid) {
            $names[$pid] = [];
        }
        foreach ($results as $row) {
            $pid = (int) $row->object_id;
            $names[$pid][] = (string) $row->name;
        }

        return $names;
    }

    /**
     * Get user objects for a list of user IDs.
     *
     * @param array<int|string> $userIds
     * @return array<int, AP_User>
     */
    public static function users(array $userIds, AP_DB $db): array
    {
        $originalIds = array_values(
            array_unique(
                array_map('intval', $userIds)
            )
        );
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $userIds),
                    static fn (int $i): bool => $i > 0
                )
            )
        );
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $usersTable = $db->table('users');
        $idCol = $db->quoteIdentifier('ID');

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s IN (%s)',
            $usersTable,
            $idCol,
            $placeholders
        );
        $results = $db->getResults($sql, $ids);

        $users = [];
        foreach ($originalIds as $uid) {
            if ($uid <= 0) {
                continue;
            }
            foreach ($results as $row) {
                if ((int) $row->ID === $uid) {
                    $users[$uid] = AP_User::fromRow($row);
                    break;
                }
            }
        }

        return $users;
    }
}
