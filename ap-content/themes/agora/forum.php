<?php

/**
 * Forum index template — categories and forums list.
 *
 * Selected when query var ap_forum_view=index (or ap_forum is set).
 * Data via {@see agora_get_forum_index_data()} / filter `agora_forum_index_data`.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$categories = function_exists('agora_get_forum_index_data') ? agora_get_forum_index_data() : [];
$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$disabled = $q instanceof AP_Query && !empty($q->get('ap_forum_disabled', false));
$notice = function_exists('ap_get_forum_notice') ? ap_get_forum_notice() : null;
if ($notice === null && function_exists('agora_get_forum_notice')) {
    $notice = agora_get_forum_notice();
}
?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo agora_esc_url($home); ?>">Home</a></li>
        <li><span aria-current="page">Forums</span></li>
    </ol>
</nav>

<div class="ap-forum ap-forum--index">
    <header class="ap-forum__header">
        <div>
            <h1 class="ap-archive-title">Forums</h1>
            <p class="ap-forum__lead">Discussions across the community. Browse categories below.</p>
        </div>
    </header>

<?php
$searchAction = function_exists('agora_forum_search_url')
    ? agora_forum_search_url()
    : (function_exists('ap_forum_search_url') ? ap_forum_search_url() : $home . 'forums/search/');
$searchEnabled = !class_exists('AP_Forum_Guard', false) || AP_Forum_Guard::isSearchEnabled();
if ($searchEnabled) :
    $prettySearch = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks();
    ?>
    <form class="ap-search-form ap-forum-search-form" role="search" method="get" action="<?php echo agora_esc_url($searchAction); ?>">
        <label class="screen-reader-text" for="ap-forum-index-search">Search forums</label>
        <?php if (!$prettySearch) : ?>
            <input type="hidden" name="ap_forum_view" value="search">
        <?php endif; ?>
        <input type="search" id="ap-forum-index-search" name="forum_s" placeholder="Search topics and posts…" required>
        <button type="submit">Search</button>
    </form>
<?php endif; ?>

<?php if (is_array($notice) && ($notice['message'] ?? '') !== '') : ?>
    <div class="ap-forum-notice ap-forum-notice--<?php echo agora_esc_attr((string) ($notice['type'] ?? 'info')); ?>" role="status">
        <p><?php echo agora_esc((string) $notice['message']); ?></p>
    </div>
<?php endif; ?>

<?php if ($disabled) : ?>
    <?php
    echo function_exists('ap_forum_empty_state_html')
        ? ap_forum_empty_state_html('forum_disabled')
        : '<div class="ap-empty ap-forum-empty ap-forum-empty--forum_disabled" role="status"><p>The forum module is currently disabled.</p></div>';
    ?>
<?php else : ?>
    <?php if ($categories === []) : ?>
        <?php
        echo function_exists('ap_forum_empty_state_html')
            ? ap_forum_empty_state_html('board_empty')
            : '<div class="ap-empty ap-forum-empty ap-forum-empty--board_empty" role="status"><p>No forums have been created yet.</p></div>';
        ?>
    <?php else : ?>
        <?php foreach ($categories as $category) : ?>
            <?php
            $catName = (string) ($category['name'] ?? 'Category');
            $forums = is_array($category['forums'] ?? null) ? $category['forums'] : [];
            ?>
            <section class="ap-forum-panel">
                <h2 class="ap-forum-panel__title"><?php echo agora_esc($catName); ?></h2>
                <?php if ($forums === []) : ?>
                    <?php
                    echo function_exists('ap_forum_empty_state_html')
                        ? ap_forum_empty_state_html('category_empty', ['class' => 'ap-forum-empty--inset'])
                        : '<div class="ap-empty ap-forum-empty ap-forum-empty--category_empty ap-forum-empty--inset" role="status"><p>No forums in this category.</p></div>';
                    ?>
                <?php else : ?>
                    <?php // SPEC A3: category header label row (Title spans icon+title cols). ?>
                    <div class="ap-forum-cat-header" role="row" aria-hidden="true">
                        <span class="ap-forum-cat-header__title">Title</span>
                        <span class="ap-forum-cat-header__topics">Topics</span>
                        <span class="ap-forum-cat-header__posts">Posts</span>
                        <span class="ap-forum-cat-header__last">Last Post</span>
                    </div>
                    <ul class="ap-forum-list">
                        <?php foreach ($forums as $forum) : ?>
                            <?php
                            if (!is_array($forum)) {
                                continue;
                            }
                            $name = (string) ($forum['name'] ?? 'Forum');
                            $desc = (string) ($forum['description'] ?? '');
                            $url = (string) ($forum['url'] ?? '#');
                            $topics = (int) ($forum['topics'] ?? $forum['topic_count'] ?? 0);
                            $posts = (int) ($forum['posts'] ?? $forum['post_count'] ?? 0);
                            $last = is_array($forum['last_post'] ?? null) ? $forum['last_post'] : null;
                            $unread = !empty($forum['is_unread']);
                            $iconType = (string) ($forum['icon_type'] ?? 'standard');
                            $isClosed = !empty($forum['is_closed']) || !empty($forum['is_locked'])
                                || (string) ($forum['status'] ?? '') === 'closed'
                                || $iconType === 'locked';
                            $isEmpty = array_key_exists('is_empty', $forum)
                                ? !empty($forum['is_empty'])
                                : ($topics === 0 && $posts === 0 && $last === null);
                            // SPEC A1: guests → neutral; logged-in → read/unread (no lying about state).
                            $tracking = function_exists('ap_is_user_logged_in') && ap_is_user_logged_in();
                            $rowArgs = [
                                'is_unread' => $unread,
                                'tracking' => $tracking,
                                'icon_type' => $iconType,
                                'is_closed' => $isClosed,
                                'is_empty' => $isEmpty,
                            ];
                            $rowClass = function_exists('ap_forum_row_classes')
                                ? ap_forum_row_classes($rowArgs)
                                : ('ap-forum-list__item ap-forum-row'
                                    . ($unread ? ' ap-forum-list__item--unread ap-forum-row--unread' : ' ap-forum-row--read')
                                    . ($isClosed ? ' ap-forum-row--locked' : '')
                                    . ($isEmpty ? ' ap-forum-row--empty' : ''));
                            ?>
                            <li class="<?php echo agora_esc_attr($rowClass); ?>">
                                <?php // SPEC A4 col 1: Icon (type + read/unread/neutral variant) ?>
                                <?php
                                if (function_exists('ap_forum_row_icon_html')) {
                                    echo ap_forum_row_icon_html($iconType, [
                                        'is_unread' => $unread,
                                        'tracking' => $tracking,
                                    ]);
                                } else {
                                    echo '<span class="ap-forum-row__icon ap-forum-list__icon">'
                                        . '<span class="ap-forum-icon ap-forum-icon--standard" aria-hidden="true"></span>'
                                        . '</span>';
                                }
                                ?>
                                <?php // SPEC A4 col 2: Title (+ description) ?>
                                <div class="ap-forum-list__main ap-forum-row__title">
                                    <h3 class="ap-forum-list__name">
                                        <?php if ($isClosed) : ?>
                                            <span class="ap-badge ap-badge--locked">Locked</span>
                                        <?php endif; ?>
                                        <?php if ($unread) : ?>
                                            <span class="ap-badge ap-badge--unread">Unread</span>
                                        <?php endif; ?>
                                        <a href="<?php echo agora_esc_url($url); ?>"><?php echo agora_esc($name); ?></a>
                                    </h3>
                                    <?php if ($desc !== '') : ?>
                                        <p class="ap-forum-list__desc"><?php echo agora_esc($desc); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php // SPEC A4 col 3: Topics ?>
                                <div class="ap-forum-stat ap-forum-list__topics ap-forum-row__topics" aria-label="<?php echo agora_esc_attr($topics . ' topics'); ?>">
                                    <span class="ap-forum-stat__value"><?php echo $topics; ?></span>
                                    <span class="ap-forum-stat__label">Topics</span>
                                </div>
                                <?php // SPEC A4 col 4: Posts ?>
                                <div class="ap-forum-stat ap-forum-list__posts ap-forum-row__posts" aria-label="<?php echo agora_esc_attr($posts . ' posts'); ?>">
                                    <span class="ap-forum-stat__value"><?php echo $posts; ?></span>
                                    <span class="ap-forum-stat__label">Posts</span>
                                </div>
                                <?php // SPEC A4 col 5: Last Post — 3 lines (title, by author, timestamp) ?>
                                <div class="ap-forum-list__last ap-forum-row__last ap-forum-last-post<?php echo $last === null ? ' ap-forum-last-post--empty' : ''; ?>">
                                    <?php if ($last !== null) : ?>
                                        <?php
                                        $lastTitle = (string) ($last['title'] ?? '');
                                        $lastAuthor = (string) ($last['author'] ?? '');
                                        $lastTime = (string) ($last['time'] ?? $last['date'] ?? '');
                                        $lastUrl = (string) ($last['url'] ?? '');
                                        ?>
                                        <span class="ap-forum-last-post__title">
                                            <?php if ($lastTitle !== '' && $lastUrl !== '') : ?>
                                                <a href="<?php echo agora_esc_url($lastUrl); ?>"><?php echo agora_esc($lastTitle); ?></a>
                                            <?php elseif ($lastTitle !== '') : ?>
                                                <?php echo agora_esc($lastTitle); ?>
                                            <?php else : ?>
                                                <span class="ap-forum-list__empty">No posts</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="ap-forum-last-post__author">
                                            <?php if ($lastAuthor !== '') : ?>
                                                by <?php echo agora_esc($lastAuthor); ?>
                                            <?php else : ?>
                                                <span class="ap-forum-list__empty">—</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="ap-forum-last-post__time">
                                            <?php if ($lastTime !== '') : ?>
                                                <time datetime="<?php echo agora_esc_attr($lastTime); ?>"><?php echo agora_esc($lastTime); ?></time>
                                            <?php else : ?>
                                                <span class="ap-forum-list__empty">—</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php elseif (function_exists('ap_forum_empty_last_post_html')) : ?>
                                        <?php echo ap_forum_empty_last_post_html(); ?>
                                    <?php else : ?>
                                        <span class="ap-forum-last-post__title ap-forum-list__empty">No posts</span>
                                        <span class="ap-forum-last-post__author ap-forum-list__empty">—</span>
                                        <span class="ap-forum-last-post__time ap-forum-list__empty">—</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    // SPEC §C — Forum chrome footer (not site footer): Total Topics · Posts · Members.
    // Posts = approved opening posts + replies (not replies-only). Same definition
    // as forum-row / topic-row post counts — see AP_Forum “Post-count definition”.
    if (function_exists('ap_forum_board_stats_footer_html')) {
        echo ap_forum_board_stats_footer_html();
    } elseif (function_exists('ap_get_forum_board_stats')) {
        $boardStats = ap_get_forum_board_stats();
        $t = (int) ($boardStats['topics'] ?? 0);
        $p = (int) ($boardStats['posts'] ?? 0);
        $m = (int) ($boardStats['members'] ?? 0);
        echo '<footer class="ap-forum-footer ap-board-stats" role="contentinfo" aria-label="Board statistics">'
            . '<span class="ap-board-stats__item ap-board-stats__item--topics">'
            . '<span class="ap-board-stats__label">Total Topics:</span> '
            . '<span class="ap-board-stats__value" data-stat="topics">' . $t . '</span></span>'
            . '<span class="ap-board-stats__sep" aria-hidden="true"> · </span>'
            . '<span class="ap-board-stats__item ap-board-stats__item--posts">'
            . '<span class="ap-board-stats__label">Total Posts:</span> '
            . '<span class="ap-board-stats__value" data-stat="posts">' . $p . '</span></span>'
            . '<span class="ap-board-stats__sep" aria-hidden="true"> · </span>'
            . '<span class="ap-board-stats__item ap-board-stats__item--members">'
            . '<span class="ap-board-stats__label">Total Members:</span> '
            . '<span class="ap-board-stats__value" data-stat="members">' . $m . '</span></span>'
            . '</footer>';
    }
    ?>
<?php endif; ?>
</div>
<?php
AP_Theme::getFooter();
