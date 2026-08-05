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
$canReply = $q instanceof AP_Query && !empty($q->get('can_reply', false));
$notFound = $q instanceof AP_Query && !empty($q->get('ap_forum_not_found', false));
$disabled = $q instanceof AP_Query && !empty($q->get('ap_forum_disabled', false));
if ($topicTitle === '') {
    $topicTitle = 'Topic';
}
if ($forumName === '') {
    $forumName = 'Forum';
}
$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$forumsUrl = function_exists('ap_forums_url')
    ? ap_forums_url()
    : (rtrim($home, '/') . '/forums/');
if ($forumUrl === '') {
    $forumUrl = $forumsUrl;
}
$posts = function_exists('agora_get_topic_posts_data')
    ? agora_get_topic_posts_data($topicId)
    : [];
$locked = $q instanceof AP_Query && !empty($q->get('topic_locked', false));
$notice = function_exists('ap_get_forum_notice') ? ap_get_forum_notice() : null;
$loggedIn = false;
if (function_exists('ap_is_user_logged_in') && class_exists('AP_Session', false)) {
    try {
        $loggedIn = ap_is_user_logged_in();
    } catch (Throwable) {
        $loggedIn = false;
    }
} elseif (function_exists('ap_get_current_user_id') && class_exists('AP_Session', false)) {
    try {
        $loggedIn = ap_get_current_user_id() > 0;
    } catch (Throwable) {
        $loggedIn = false;
    }
}
$nonceAction = 'ap_forum_reply_' . $topicId;
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

<?php if (is_array($notice) && ($notice['message'] ?? '') !== '') : ?>
    <div class="ap-forum-notice ap-forum-notice--<?php echo agora_esc_attr((string) ($notice['type'] ?? 'info')); ?>" role="status">
        <p><?php echo agora_esc((string) $notice['message']); ?></p>
    </div>
<?php endif; ?>

<?php if ($disabled) : ?>
    <div class="ap-empty" role="status">
        <p>The forum module is currently disabled.</p>
    </div>
<?php elseif ($notFound) : ?>
    <div class="ap-empty" role="status">
        <p>Topic not found.</p>
        <p><a href="<?php echo agora_esc_url($forumsUrl); ?>">Back to forums</a></p>
    </div>
<?php elseif ($posts === []) : ?>
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
            $postId = (int) ($post['id'] ?? $postNum);
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
            $attachments = is_array($post['attachments'] ?? null) ? $post['attachments'] : [];
            ?>
            <section class="ap-forum-post" id="post-<?php echo $postId; ?>" aria-label="<?php echo agora_esc_attr('Post #' . $postNum . ' by ' . $author); ?>">
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
                        <a href="#post-<?php echo $postId; ?>">#<?php echo $postNum; ?></a>
                    </div>
                    <div class="ap-forum-post__body">
                        <?php echo $safeBody; ?>
                    </div>
                    <?php if ($attachments !== []) : ?>
                        <ul class="ap-forum-attachments" aria-label="Attachments">
                            <?php foreach ($attachments as $att) : ?>
                                <?php
                                if (!is_array($att)) {
                                    continue;
                                }
                                $attName = (string) ($att['filename'] ?? $att['name'] ?? 'file');
                                $attUrl = (string) ($att['url'] ?? '#');
                                $attSize = (string) ($att['size_label'] ?? $att['filesize'] ?? '');
                                ?>
                                <li class="ap-forum-attachments__item">
                                    <a href="<?php echo agora_esc_url($attUrl); ?>"><?php echo agora_esc($attName); ?></a>
                                    <?php if ($attSize !== '') : ?>
                                        <span class="ap-forum-attachments__size"><?php echo agora_esc((string) $attSize); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$disabled && !$notFound) : ?>
    <?php if ($locked) : ?>
        <div class="ap-empty" role="status">
            <p>This topic is locked. New replies are not accepted.</p>
        </div>
    <?php elseif ($canReply) : ?>
        <section class="ap-forum-form" id="reply" aria-labelledby="reply-heading">
            <h2 id="reply-heading" class="ap-comments__title">Reply</h2>
            <form method="post" action="">
                <input type="hidden" name="ap_forum_action" value="ap_forum_reply">
                <input type="hidden" name="topic_id" value="<?php echo (int) $topicId; ?>">
                <?php
                if (function_exists('ap_nonce_field')) {
                    echo ap_nonce_field($nonceAction);
                } elseif (class_exists('AP_Nonce', false)) {
                    echo AP_Nonce::field($nonceAction);
                }
                ?>
                <div class="ap-field">
                    <?php
                    if (function_exists('ap_editor')) {
                        echo ap_editor([
                            'id' => 'agora-reply-body',
                            'name' => 'reply_body',
                            'mode' => class_exists('AP_Editor', false)
                                ? AP_Editor::modeForContext('forum')
                                : 'visual',
                            'rows' => 6,
                            'required' => true,
                            'label' => 'Message',
                            'placeholder' => 'Write your reply…',
                            'class' => '',
                        ]);
                    } else {
                        ?>
                    <label for="agora-reply-body">Message</label>
                    <textarea id="agora-reply-body" name="reply_body" required rows="6" placeholder="Write your reply…"></textarea>
                        <?php
                    }
                    ?>
                </div>
                <button type="submit" class="ap-btn">Post reply</button>
            </form>
        </section>
    <?php elseif (!$loggedIn) : ?>
        <section class="ap-forum-form" id="reply" aria-labelledby="reply-heading">
            <h2 id="reply-heading" class="ap-comments__title">Reply</h2>
            <p class="ap-forum__lead">
                <a href="<?php echo agora_esc_url(function_exists('ap_site_url') ? ap_site_url('ap-admin/login.php') : '/ap-admin/login.php'); ?>">Log in</a>
                to post a reply.
            </p>
        </section>
    <?php endif; ?>
<?php endif; ?>
</article>
<?php
AP_Theme::getFooter();
