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
$canModerate = $q instanceof AP_Query && !empty($q->get('can_moderate', false));
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
$editPostId = isset($_GET['edit_post']) ? (int) $_GET['edit_post'] : 0;
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
        <?php if ($canModerate && $topicId > 0) : ?>
            <div class="ap-forum-toolbar ap-forum-toolbar--topic" role="toolbar" aria-label="Topic moderation">
                <form method="post" action="" class="ap-forum-action-form">
                    <input type="hidden" name="ap_forum_action" value="<?php echo $locked ? 'ap_forum_unlock_topic' : 'ap_forum_lock_topic'; ?>">
                    <input type="hidden" name="topic_id" value="<?php echo (int) $topicId; ?>">
                    <?php
                    $lockAction = ($locked ? 'ap_forum_unlock_topic' : 'ap_forum_lock_topic') . '_' . $topicId;
                    if (function_exists('ap_nonce_field')) {
                        echo ap_nonce_field($lockAction);
                    }
                    ?>
                    <button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm">
                        <?php echo $locked ? 'Unlock topic' : 'Lock topic'; ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
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
            $likeCount = (int) ($post['like_count'] ?? 0);
            $likedByMe = !empty($post['liked_by_me']);
            $canLike = !empty($post['can_like']);
            $canEdit = !empty($post['can_edit']);
            $canDelete = !empty($post['can_delete']);
            $authorStats = is_array($post['author_stats'] ?? null) ? $post['author_stats'] : [];
            $authorPosts = (int) ($authorStats['forum_posts'] ?? 0);
            $authorLikes = (int) ($authorStats['forum_likes_received'] ?? 0);
            $editCount = (int) ($post['edit_count'] ?? 0);
            $isEditing = $editPostId > 0 && $editPostId === $postId && $canEdit;
            $rawContent = (string) ($post['content'] ?? '');
            ?>
            <section class="ap-forum-post" id="post-<?php echo $postId; ?>" aria-label="<?php echo agora_esc_attr('Post #' . $postNum . ' by ' . $author); ?>">
                <aside class="ap-forum-post__author">
                    <div class="ap-forum-post__avatar" aria-hidden="true"><?php echo agora_esc($initial); ?></div>
                    <p class="ap-forum-post__author-name"><?php echo agora_esc($author); ?></p>
                    <?php if ($role !== '') : ?>
                        <p class="ap-forum-post__author-meta"><?php echo agora_esc($role); ?></p>
                    <?php endif; ?>
                    <?php if ($authorPosts > 0 || $authorLikes > 0) : ?>
                        <p class="ap-forum-post__author-meta ap-forum-post__stats">
                            <?php if ($authorPosts > 0) : ?>
                                <span><?php echo (int) $authorPosts; ?> post<?php echo $authorPosts === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                            <?php if ($authorLikes > 0) : ?>
                                <span><?php echo (int) $authorLikes; ?> like<?php echo $authorLikes === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        </p>
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
                    <?php if ($isEditing) : ?>
                        <form method="post" action="" class="ap-forum-form ap-forum-form--edit">
                            <input type="hidden" name="ap_forum_action" value="ap_forum_edit_post">
                            <input type="hidden" name="post_id" value="<?php echo (int) $postId; ?>">
                            <?php
                            $editNonce = 'ap_forum_edit_post_' . $postId;
                            if (function_exists('ap_nonce_field')) {
                                echo ap_nonce_field($editNonce);
                            }
                            ?>
                            <div class="ap-field">
                                <label for="edit-body-<?php echo (int) $postId; ?>">Edit message</label>
                                <?php
                                if (function_exists('ap_editor')) {
                                    echo ap_editor([
                                        'id' => 'edit-body-' . $postId,
                                        'name' => 'post_body',
                                        'value' => $rawContent,
                                        'mode' => class_exists('AP_Editor', false)
                                            ? AP_Editor::modeForContext('forum')
                                            : 'visual',
                                        'rows' => 6,
                                        'required' => true,
                                    ]);
                                } else {
                                    echo '<textarea id="edit-body-' . (int) $postId . '" name="post_body" required rows="6">'
                                        . agora_esc($rawContent) . '</textarea>';
                                }
                                ?>
                            </div>
                            <div class="ap-field">
                                <label for="edit-reason-<?php echo (int) $postId; ?>">Edit reason (optional)</label>
                                <input type="text" id="edit-reason-<?php echo (int) $postId; ?>" name="edit_reason" maxlength="255">
                            </div>
                            <div class="ap-forum-toolbar">
                                <button type="submit" class="ap-btn">Save changes</button>
                                <a class="ap-btn ap-btn--ghost" href="<?php
                                echo agora_esc_url(strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . '#post-' . $postId);
                                ?>">Cancel</a>
                            </div>
                        </form>
                    <?php else : ?>
                    <div class="ap-forum-post__body">
                        <?php echo $safeBody; ?>
                    </div>
                    <?php if ($editCount > 0) : ?>
                        <p class="ap-forum-post__edited">Last edited (<?php echo (int) $editCount; ?> time<?php echo $editCount === 1 ? '' : 's'; ?>)</p>
                    <?php endif; ?>
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
                    <div class="ap-forum-post__actions" role="group" aria-label="Post actions">
                        <?php if ($canLike || $likeCount > 0) : ?>
                            <?php if ($canLike) : ?>
                                <form method="post" action="" class="ap-forum-action-form ap-forum-action-form--inline">
                                    <input type="hidden" name="ap_forum_action" value="ap_forum_like_post">
                                    <input type="hidden" name="post_id" value="<?php echo (int) $postId; ?>">
                                    <?php
                                    if (function_exists('ap_nonce_field')) {
                                        echo ap_nonce_field('ap_forum_like_post_' . $postId);
                                    }
                                    ?>
                                    <button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm ap-forum-like<?php echo $likedByMe ? ' is-liked' : ''; ?>" aria-pressed="<?php echo $likedByMe ? 'true' : 'false'; ?>">
                                        <span aria-hidden="true">👍</span>
                                        <?php echo $likedByMe ? 'Liked' : 'Like'; ?>
                                        <?php if ($likeCount > 0) : ?>
                                            <span class="ap-forum-like__count">(<?php echo (int) $likeCount; ?>)</span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            <?php elseif ($likeCount > 0) : ?>
                                <span class="ap-forum-like ap-forum-like--static" title="Likes">
                                    <span aria-hidden="true">👍</span>
                                    <span class="ap-forum-like__count"><?php echo (int) $likeCount; ?></span>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($canEdit) : ?>
                            <a class="ap-btn ap-btn--ghost ap-btn--sm" href="?edit_post=<?php echo (int) $postId; ?>#post-<?php echo (int) $postId; ?>">Edit</a>
                        <?php endif; ?>
                        <?php if ($canDelete) : ?>
                            <form method="post" action="" class="ap-forum-action-form ap-forum-action-form--inline" onsubmit="return confirm('Delete this post?');">
                                <input type="hidden" name="ap_forum_action" value="ap_forum_delete_post">
                                <input type="hidden" name="post_id" value="<?php echo (int) $postId; ?>">
                                <?php
                                if (function_exists('ap_nonce_field')) {
                                    echo ap_nonce_field('ap_forum_delete_post_' . $postId);
                                }
                                ?>
                                <button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm ap-btn--danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
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
