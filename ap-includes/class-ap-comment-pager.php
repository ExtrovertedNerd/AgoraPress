<?php

/**
 * Comment pagination.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Class for comment pagination.
 */
final class AP_Comment_Pager
{
    public const DEFAULT_PER_PAGE = 50;

    /**
     * Get the number of comments per page.
     *
     * @return int
     */
    public static function perPage(?AP_DB $db = null): int
    {
        if (!class_exists('AP_Options', false)) {
            return self::DEFAULT_PER_PAGE;
        }

        $val = AP_Options::get('comments_per_page', self::DEFAULT_PER_PAGE, $db);

        if (!is_numeric($val)) {
            return self::DEFAULT_PER_PAGE;
        }

        $num = (int) $val;
        return $num < 0 ? 0 : $num;
    }

    /**
     * Get a page of comments for a post.
     *
     * @return array{comments: list<AP_Comment>, total: int, pages: int, page: int}
     */
    public static function page(int $postId, int $page, int $perPage, ?AP_DB $db = null): array
    {
        $total = AP_Comment::count(['post_id' => $postId, 'status' => 'approve'], $db);

        if ($total === 0) {
            return [
                'comments' => [],
                'total' => 0,
                'pages' => 1,
                'page' => 1,
            ];
        }

        if ($perPage < 1) {
            $comments = AP_Comment::getByPost($postId, [], $db);
            return [
                'comments' => $comments,
                'total' => $total,
                'pages' => 1,
                'page' => 1,
            ];
        }

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $comments = AP_Comment::getByPost(
            $postId,
            ['number' => $perPage, 'offset' => ($page - 1) * $perPage],
            $db
        );

        return [
            'comments' => $comments,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
        ];
    }
}
