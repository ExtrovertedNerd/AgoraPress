<?php

/**
 * Single topic template — posts/replies in a thread.
 *
 * Selected when ap_forum_view=topic or topic_id is set.
 * Data via {@see agora_get_topic_posts_data()} / filter `agora_topic_posts_data`.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$topicId = $q instanceof AP_Query ? (int) $q->get('topic_id', 0) : 0;
$topicTitle = $q instanceof AP_Query ? (string) $q->get('topic_title', '') : '';
$forumName = $q instanceof AP_Query ? (string) $q->get('forum_name', '') : '';
$forumUrl = $q instanceof AP_Query ? (string) $q->get('forum_url', '') : '';
if ($topicTitle === '') {
    $topicTitle = 'Topic';
}
if ($forumName === '') {
    $forumName = 'Forum';
}
$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$forumsUrl = rtrim($home, '/') . '/forums/';
if ($forumUrl === '') {
    $forumUrl = $forumsUrl;
}
$posts = function_exists('agora_get_topic_posts_data')
    ? agora_get_topic_posts_data($topicId)
    : [];
$locked = $q instanceof AP_Query && !empty($q->get('topic_locked', false));
?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo agora_esc_url($home); ?>">Home</a></li>
        <li><a href="<?php echo agora_esc_url($forumsUrl); ?>">Forums</a></li>
        <li><a href="<?php echo agora_esc_url($forumUrl); ?>"><?php echo agora_esc($forumName); ?></a></li>
        <li><span aria-current="page"><?php echo agora_esc($topicTitle); ?></span></li>
    </ol>
</nav>

<article class="ap-forum ap-forum--topic">
    <header class="ap-forum__header">
        <div>
            <h1 class="ap-forum-topic-title"><?php echo agora_esc($topicTitle); ?></h1>
            <p class="ap-forum-topic-meta">
                <?php if ($locked) : ?>
                    <span class="ap-badge ap-badge--locked">Locked</span>
                <?php endif; ?>
                <span><?php echo agora_esc($forumName); ?></span>
                <?php if ($posts !== []) : ?>
                    <span><?php echo count($posts); ?> post<?php echo count($posts) === 1 ? '' : 's'; ?></span>
                <?php endif; ?>
            </p>
        </div>
    </header>

<?php if ($posts === []) : ?>
    <div class="ap-empty" role="status">
        <p>No posts in this topic yet.</p>
    </div>
<?php else : ?>
    <div class="ap-forum-posts">
        <?php foreach ($posts as $index => $post) : ?>
            <?php
            if (!is_array($post)) {
                continue;
            }
            $author = (string) ($post['author'] ?? 'Guest');
            $date = (string) ($post['date'] ?? '');
            $body = (string) ($post['content'] ?? '');
            $role = (string) ($post['role'] ?? '');
            $postNum = (int) ($post['number'] ?? ($index + 1));
            $initial = function_exists('mb_substr')
                ? mb_strtoupper(mb_substr($author, 0, 1))
                : strtoupper(substr($author, 0, 1));
            // Content is expected to be pre-sanitized by the forum layer; escape by default.
            $safeBody = agora_esc($body);
            $safeBody = nl2br($safeBody, false);
            if (!empty($post['content_html']) && is_string($post['content_html'])) {
                // Trusted HTML from the forum formatter (Phase 5).
                $safeBody = (string) $post['content_html'];
            }
            ?>
            <section class="ap-forum-post" id="post-<?php echo (int) ($post['id'] ?? $postNum); ?>" aria-label="<?php echo agora_esc_attr('Post #' . $postNum . ' by ' . $author); ?>">
                <aside class="ap-forum-post__author">
                    <div class="ap-forum-post__avatar" aria-hidden="true"><?php echo agora_esc($initial); ?></div>
                    <p class="ap-forum-post__author-name"><?php echo agora_esc($author); ?></p>
                    <?php if ($role !== '') : ?>
                        <p class="ap-forum-post__author-meta"><?php echo agora_esc($role); ?></p>
                    <?php endif; ?>
                </aside>
                <div>
                    <div class="ap-forum-post__head">
                        <?php if ($date !== '') : ?>
                            <time datetime="<?php echo agora_esc_attr($date); ?>"><?php echo agora_esc($date); ?></time>
                        <?php else : ?>
                            <span></span>
                        <?php endif; ?>
                        <a href="#post-<?php echo (int) ($post['id'] ?? $postNum); ?>">#<?php echo $postNum; ?></a>
                    </div>
                    <div class="ap-forum-post__body">
                        <?php echo $safeBody; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$locked) : ?>
    <section class="ap-forum-form" id="reply" aria-labelledby="reply-heading">
        <h2 id="reply-heading" class="ap-comments__title">Reply</h2>
        <p class="ap-forum__lead">Replies will be available when the forum module is enabled.</p>
        <div class="ap-field">
            <label for="agora-reply-body">Message</label>
            <textarea id="agora-reply-body" name="reply_body" disabled placeholder="Write your reply…"></textarea>
        </div>
        <button type="button" class="ap-btn" disabled>Post reply</button>
    </section>
<?php else : ?>
    <div class="ap-empty" role="status">
        <p>This topic is locked. New replies are not accepted.</p>
    </div>
<?php endif; ?>
</article>
<?php
AP_Theme::getFooter();
