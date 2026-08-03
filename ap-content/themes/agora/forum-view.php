<?php

/**
 * Single forum template — topic list within a forum.
 *
 * Selected when ap_forum_view=forum or forum_id is set.
 * Data via {@see agora_get_forum_topics_data()} / filter `agora_forum_topics_data`.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$forumId = $q instanceof AP_Query ? (int) $q->get('forum_id', 0) : 0;
$forumName = $q instanceof AP_Query ? (string) $q->get('forum_name', '') : '';
if ($forumName === '') {
    $forumName = 'Forum';
}
$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$forumsUrl = rtrim($home, '/') . '/forums/';
$topics = function_exists('agora_get_forum_topics_data')
    ? agora_get_forum_topics_data($forumId)
    : [];
?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo agora_esc_url($home); ?>">Home</a></li>
        <li><a href="<?php echo agora_esc_url($forumsUrl); ?>">Forums</a></li>
        <li><span aria-current="page"><?php echo agora_esc($forumName); ?></span></li>
    </ol>
</nav>

<div class="ap-forum ap-forum--view">
    <header class="ap-forum__header">
        <div>
            <h1 class="ap-archive-title"><?php echo agora_esc($forumName); ?></h1>
            <p class="ap-forum__lead">Topics in this forum, newest activity first.</p>
        </div>
        <div class="ap-forum-toolbar">
            <a class="ap-btn" href="#new-topic">New topic</a>
        </div>
    </header>

<?php if ($topics === []) : ?>
    <div class="ap-empty" role="status">
        <p>No topics yet in this forum.</p>
        <p>Be the first to start a conversation.</p>
    </div>
<?php else : ?>
    <section class="ap-forum-panel" aria-label="Topics">
        <ul class="ap-forum-list">
            <?php foreach ($topics as $topic) : ?>
                <?php
                if (!is_array($topic)) {
                    continue;
                }
                $title = (string) ($topic['title'] ?? 'Topic');
                $url = (string) ($topic['url'] ?? '#');
                $author = (string) ($topic['author'] ?? '');
                $replies = (int) ($topic['replies'] ?? 0);
                $views = (int) ($topic['views'] ?? 0);
                $sticky = !empty($topic['sticky']);
                $locked = !empty($topic['locked']);
                $announce = !empty($topic['announcement']);
                $lastDate = (string) ($topic['last_date'] ?? '');
                $lastAuthor = (string) ($topic['last_author'] ?? '');
                ?>
                <li class="ap-forum-list__item ap-forum-list__item--topic">
                    <div>
                        <h2 class="ap-forum-list__name">
                            <?php if ($sticky) : ?>
                                <span class="ap-badge ap-badge--sticky">Sticky</span>
                            <?php endif; ?>
                            <?php if ($announce) : ?>
                                <span class="ap-badge ap-badge--announce">Announcement</span>
                            <?php endif; ?>
                            <?php if ($locked) : ?>
                                <span class="ap-badge ap-badge--locked">Locked</span>
                            <?php endif; ?>
                            <a href="<?php echo agora_esc_url($url); ?>"><?php echo agora_esc($title); ?></a>
                        </h2>
                        <?php if ($author !== '') : ?>
                            <p class="ap-forum-list__desc">Started by <?php echo agora_esc($author); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="ap-forum-stat">
                        <span class="ap-forum-stat__value"><?php echo $replies; ?></span>
                        <span class="ap-forum-stat__label">Replies</span>
                    </div>
                    <div class="ap-forum-stat">
                        <span class="ap-forum-stat__value"><?php echo $views; ?></span>
                        <span class="ap-forum-stat__label">Views</span>
                    </div>
                    <div class="ap-forum-list__last">
                        <?php if ($lastAuthor !== '') : ?>
                            <strong><?php echo agora_esc($lastAuthor); ?></strong>
                        <?php endif; ?>
                        <?php if ($lastDate !== '') : ?>
                            <?php echo agora_esc($lastDate); ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

    <section class="ap-forum-form" id="new-topic" aria-labelledby="new-topic-heading">
        <h2 id="new-topic-heading" class="ap-comments__title">Start a new topic</h2>
        <p class="ap-forum__lead">Posting will be available when the forum module is enabled.</p>
        <div class="ap-field">
            <label for="agora-topic-title">Subject</label>
            <input type="text" id="agora-topic-title" name="topic_title" disabled placeholder="Topic subject" autocomplete="off">
        </div>
        <div class="ap-field">
            <label for="agora-topic-body">Message</label>
            <textarea id="agora-topic-body" name="topic_body" disabled placeholder="Write your message…"></textarea>
        </div>
        <button type="button" class="ap-btn" disabled>Post topic</button>
    </section>
</div>
<?php
AP_Theme::getFooter();
