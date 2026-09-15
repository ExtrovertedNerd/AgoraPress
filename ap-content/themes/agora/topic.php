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
$cannotView = $q instanceof AP_Query && !empty($q->get('ap_forum_cannot_view', false));
$cannotViewMessage = $q instanceof AP_Query
    ? (string) $q->get('ap_forum_cannot_view_message', '')
    : '';
if ($cannotViewMessage === '') {
    $cannotViewMessage = class_exists('AP_Forum_Front', false)
        ? AP_Forum_Front::CANNOT_VIEW_MESSAGE
        : 'You cannot view this.';
}
$topicId = $q instanceof AP_Query ? (int) $q->get('topic_id', 0) : 0;
$topicTitle = $q instanceof AP_Query ? (string) $q->get('topic_title', '') : '';
$forumName = $q instanceof AP_Query ? (string) $q->get('forum_name', '') : '';
$forumUrl = $q instanceof AP_Query ? (string) $q->get('forum_url', '') : '';
$canReply = $q instanceof AP_Query && !empty($q->get('can_reply', false));
$canModerate = $q instanceof AP_Query && !empty($q->get('can_moderate', false));
$canSetTopicType = $q instanceof AP_Query && !empty($q->get('can_set_topic_type', false));
$canMoveTopic = $q instanceof AP_Query && !empty($q->get('can_move_topic', false));
$moveDestinations = $q instanceof AP_Query && is_array($q->get('move_destinations', null))
    ? $q->get('move_destinations', [])
    : [];
$canMergeTopic = $q instanceof AP_Query && !empty($q->get('can_merge_topic', false));
$mergeTargets = $q instanceof AP_Query && is_array($q->get('merge_targets', null))
    ? $q->get('merge_targets', [])
    : [];
$canSplitTopic = $q instanceof AP_Query && !empty($q->get('can_split_topic', false));
$splitDestinations = $q instanceof AP_Query && is_array($q->get('split_destinations', null))
    ? $q->get('split_destinations', [])
    : [];
$canSubscribe = $q instanceof AP_Query && !empty($q->get('can_subscribe', false));
$topicSubscribed = $q instanceof AP_Query && !empty($q->get('topic_subscribed', false));
$topicType = $q instanceof AP_Query
    ? (string) $q->get('topic_type', 'standard')
    : 'standard';
$allowedTopicTypes = $q instanceof AP_Query && is_array($q->get('allowed_topic_types', null))
    ? $q->get('allowed_topic_types', [])
    : [];
$notFound = $q instanceof AP_Query && !empty($q->get('ap_forum_not_found', false));
$disabled = $q instanceof AP_Query && !empty($q->get('ap_forum_disabled', false));
if (!$cannotView && $topicTitle === '') {
    $topicTitle = 'Topic';
}
if (!$cannotView && $forumName === '') {
    $forumName = 'Forum';
}
$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$forumsUrl = function_exists('ap_forums_url')
    ? ap_forums_url()
    : (rtrim($home, '/') . '/forums/');
if ($forumUrl === '') {
    $forumUrl = $forumsUrl;
}
$posts = (!$cannotView && function_exists('agora_get_topic_posts_data'))
    ? agora_get_topic_posts_data($topicId)
    : [];
$locked = $q instanceof AP_Query && !empty($q->get('topic_locked', false));
// SPEC B1 — resolved before mark-as-read in AP_Forum_Front (0 = hide).
$firstUnreadPostId = $q instanceof AP_Query
    ? (int) $q->get('first_unread_post_id', 0)
    : 0;
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
// Quote starts a reply with BBCode citation (SPEC B2).
$quotePostId = isset($_GET['quote']) ? (int) $_GET['quote'] : 0;
// Prefill reply body when ?quote={postId} (server-rendered citation).
$quoteMarkup = '';
if ($quotePostId > 0 && $canReply && !$locked) {
    if (function_exists('ap_forum_quote_for_post')) {
        $quoteMarkup = ap_forum_quote_for_post($quotePostId);
    } elseif (class_exists('AP_Forum', false)) {
        $quoteMarkup = AP_Forum::getQuoteMarkupForPost($quotePostId);
    }
    // Fallback: build from the already-loaded post list when core helper is unavailable.
    if ($quoteMarkup === '' && $posts !== []) {
        foreach ($posts as $qp) {
            if (!is_array($qp) || (int) ($qp['id'] ?? 0) !== $quotePostId) {
                continue;
            }
            $qAuthor = (string) ($qp['author'] ?? 'Guest');
            $qBody = (string) ($qp['content'] ?? '');
            if (function_exists('ap_forum_build_quote_markup')) {
                $quoteMarkup = ap_forum_build_quote_markup($qAuthor, $qBody);
            } elseif (class_exists('AP_Forum', false)) {
                $quoteMarkup = AP_Forum::buildQuoteMarkup($qAuthor, $qBody);
            }
            break;
        }
    }
}
$forumIdForType = $q instanceof AP_Query ? (int) $q->get('forum_id', 0) : 0;
if (
    $allowedTopicTypes === []
    && $forumIdForType > 0
    && function_exists('ap_forum_allowed_topic_types_for_edit')
) {
    $allowedTopicTypes = ap_forum_allowed_topic_types_for_edit($forumIdForType, $topicType);
    $canSetTopicType = $allowedTopicTypes !== [];
}
$splitPostCount = 0;
if (is_array($posts)) {
    foreach ($posts as $splitCandidate) {
        if (is_array($splitCandidate) && (int) ($splitCandidate['id'] ?? 0) > 0) {
            $splitPostCount++;
        }
    }
}
$topicPostCount = $q instanceof AP_Query ? (int) $q->get('topic_post_count', 0) : 0;
if ($topicPostCount < 1) {
    $topicPostCount = $splitPostCount;
}
// Topic must have ≥2 posts so one can remain; current page still needs a checkbox.
$showSplit = $canSplitTopic && $topicId > 0 && $topicPostCount >= 2 && $splitPostCount >= 1;
$splitFormId = 'agora-split-topic-form';
?>
<?php if ($cannotView) : ?>
<div class="ap-forum ap-forum--cannot-view">
    <div class="ap-empty ap-forum-empty ap-forum-empty--cannot_view" role="status">
        <p><?php echo agora_esc($cannotViewMessage); ?></p>
        <p class="ap-forum-empty__back"><a href="<?php echo agora_esc_url($forumsUrl); ?>">Back to forums</a></p>
    </div>
</div>
<?php
AP_Theme::getFooter();
return;
endif; ?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo agora_esc_url($home); ?>">Home</a></li>
        <li><a href="<?php echo agora_esc_url($forumsUrl); ?>">Forums</a></li>
        <li><a href="<?php echo agora_esc_url($forumUrl); ?>"><?php echo agora_esc($forumName); ?></a></li>
        <li><span aria-current="page"><?php echo agora_esc($topicTitle); ?></span></li>
    </ol>
</nav>

<article class="ap-forum ap-forum--topic" id="ap-topic-top">
    <header class="ap-forum__header">
        <div>
            <h1 class="ap-forum-topic-title"><?php echo agora_esc($topicTitle); ?></h1>
            <p class="ap-forum-topic-meta">
                <?php if ($locked) : ?>
                    <span class="ap-badge ap-badge--locked">Locked</span>
                <?php endif; ?>
                <?php if ($topicType === 'sticky') : ?>
                    <span class="ap-badge ap-badge--sticky">Sticky</span>
                <?php elseif ($topicType === 'announcement') : ?>
                    <span class="ap-badge ap-badge--announce">Announcement</span>
                <?php elseif ($topicType === 'rules') : ?>
                    <span class="ap-badge ap-badge--rules">Rules</span>
                <?php endif; ?>
                <span><?php echo agora_esc($forumName); ?></span>
                <?php if ($posts !== []) : ?>
                    <span><?php echo count($posts); ?> post<?php echo count($posts) === 1 ? '' : 's'; ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php
        $showMove = $canMoveTopic && $moveDestinations !== [];
        $showMerge = $canMergeTopic && $mergeTargets !== [];
        if (($canModerate || $canSetTopicType || $canSubscribe || $showMove || $showMerge || $showSplit) && $topicId > 0) :
            ?>
            <div class="ap-forum-toolbar ap-forum-toolbar--topic" role="toolbar" aria-label="Topic actions">
                <?php
                if ($canSubscribe) {
                    if (function_exists('ap_forum_topic_subscribe_form_html')) {
                        echo ap_forum_topic_subscribe_form_html($topicId, $topicSubscribed, [
                            'forum_id' => $forumIdForType,
                        ]);
                    } else {
                        $subAction = $topicSubscribed
                            ? 'ap_forum_unsubscribe_topic'
                            : 'ap_forum_subscribe_topic';
                        $subLabel = $topicSubscribed ? 'Unsubscribe' : 'Subscribe';
                        $subAria = $topicSubscribed
                            ? 'Unsubscribe from email notifications for this topic'
                            : 'Subscribe to email notifications for this topic';
                        echo '<form method="post" action="" class="ap-forum-action-form ap-forum-subscribe">';
                        echo '<input type="hidden" name="ap_forum_action" value="' . agora_esc_attr($subAction) . '">';
                        echo '<input type="hidden" name="topic_id" value="' . (int) $topicId . '">';
                        if (function_exists('ap_nonce_field')) {
                            echo ap_nonce_field($subAction . '_' . $topicId);
                        }
                        echo '<button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm ap-forum-subscribe__button"'
                            . ' aria-pressed="' . ($topicSubscribed ? 'true' : 'false') . '"'
                            . ' aria-label="' . agora_esc_attr($subAria) . '">';
                        echo agora_esc($subLabel);
                        echo '</button></form>';
                    }
                }
                ?>
                <?php if ($canModerate) : ?>
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
                <?php endif; ?>
                <?php
                if ($showMove) {
                    if (function_exists('ap_forum_move_topic_form_html')) {
                        echo ap_forum_move_topic_form_html($topicId, $moveDestinations, [
                            'id' => 'agora-move-dest-forum',
                        ]);
                    } else {
                        echo '<form method="post" action="" class="ap-forum-action-form ap-forum-action-form--move-topic">';
                        echo '<input type="hidden" name="ap_forum_action" value="ap_forum_move_topic">';
                        echo '<input type="hidden" name="topic_id" value="' . (int) $topicId . '">';
                        if (function_exists('ap_nonce_field')) {
                            echo ap_nonce_field('ap_forum_move_topic_' . $topicId);
                        }
                        echo '<div class="ap-field ap-field--move-dest ap-field--inline">';
                        echo '<label for="agora-move-dest-forum">Move to</label>';
                        echo '<select id="agora-move-dest-forum" name="dest_forum_id" required>';
                        echo '<option value="">Select forum</option>';
                        foreach ($moveDestinations as $dest) {
                            if (!is_array($dest)) {
                                continue;
                            }
                            $destId = (int) ($dest['forum_id'] ?? 0);
                            $destName = (string) ($dest['forum_name'] ?? '');
                            if ($destId < 1 || $destName === '') {
                                continue;
                            }
                            echo '<option value="' . $destId . '">' . agora_esc($destName) . '</option>';
                        }
                        echo '</select></div>';
                        echo '<button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm"'
                            . ' aria-label="Move this topic to another forum">Move</button>';
                        echo '</form>';
                    }
                }
                if ($showMerge) {
                    if (function_exists('ap_forum_merge_topic_form_html')) {
                        echo ap_forum_merge_topic_form_html($topicId, $mergeTargets, [
                            'id' => 'agora-merge-target-topic',
                        ]);
                    } else {
                        echo '<form method="post" action="" class="ap-forum-action-form ap-forum-action-form--merge-topic">';
                        echo '<input type="hidden" name="ap_forum_action" value="ap_forum_merge_topic">';
                        echo '<input type="hidden" name="topic_id" value="' . (int) $topicId . '">';
                        if (function_exists('ap_nonce_field')) {
                            echo ap_nonce_field('ap_forum_merge_topic_' . $topicId);
                        }
                        echo '<div class="ap-field ap-field--merge-target ap-field--inline">';
                        echo '<label for="agora-merge-target-topic">Merge into</label>';
                        echo '<select id="agora-merge-target-topic" name="target_topic_id" required>';
                        echo '<option value="">Select topic</option>';
                        foreach ($mergeTargets as $target) {
                            if (!is_array($target)) {
                                continue;
                            }
                            $targetId = (int) ($target['topic_id'] ?? 0);
                            $targetTitle = trim((string) ($target['topic_title'] ?? ''));
                            $targetForum = trim((string) ($target['forum_name'] ?? ''));
                            if ($targetId < 1) {
                                continue;
                            }
                            if ($targetTitle === '') {
                                $targetTitle = '(no title)';
                            }
                            $optionLabel = $targetForum !== ''
                                ? $targetTitle . ' — ' . $targetForum
                                : $targetTitle;
                            echo '<option value="' . $targetId . '">' . agora_esc($optionLabel) . '</option>';
                        }
                        echo '</select></div>';
                        echo '<button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm"'
                            . ' aria-label="Merge this topic into another topic">Merge</button>';
                        echo '</form>';
                    }
                }
                if ($showSplit) {
                    echo '<a class="ap-btn ap-btn--ghost ap-btn--sm" href="#split"'
                        . ' aria-label="Split selected posts into a new topic">Split</a>';
                }
                ?>
                <?php if ($canSetTopicType && $allowedTopicTypes !== []) : ?>
                <form method="post" action="" class="ap-forum-action-form ap-forum-action-form--topic-type">
                    <input type="hidden" name="ap_forum_action" value="ap_forum_set_topic_type">
                    <input type="hidden" name="topic_id" value="<?php echo (int) $topicId; ?>">
                    <?php
                    if (function_exists('ap_nonce_field')) {
                        echo ap_nonce_field('ap_forum_set_topic_type_' . $topicId);
                    }
                    if (function_exists('ap_forum_topic_type_select_html')) {
                        echo ap_forum_topic_type_select_html($allowedTopicTypes, [
                            'id' => 'agora-edit-topic-type',
                            'name' => 'topic_type',
                            'selected' => $topicType,
                            'label' => 'Type',
                            'hide_single' => false,
                            'class' => 'ap-field ap-field--topic-type ap-field--inline',
                        ]);
                    }
                    ?>
                    <button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm">Update type</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

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
<?php elseif ($notFound) : ?>
    <div class="ap-empty ap-forum-empty ap-forum-empty--topic_not_found" role="status">
        <p>Topic not found.</p>
        <p class="ap-forum-empty__back"><a href="<?php echo agora_esc_url($forumsUrl); ?>">Back to forums</a></p>
    </div>
<?php elseif ($posts === []) : ?>
    <?php
    echo function_exists('ap_forum_empty_state_html')
        ? ap_forum_empty_state_html('topic_empty')
        : '<div class="ap-empty ap-forum-empty ap-forum-empty--topic_empty" role="status"><p>No posts in this topic yet.</p></div>';
    ?>
<?php else : ?>
    <?php
    // SPEC B1 — “First unread post” above the OP when the viewer has unread posts.
    // Hidden for guests / fully-read topics (first_unread_post_id is 0).
    if ($firstUnreadPostId > 0) {
        if (function_exists('ap_forum_first_unread_link_html')) {
            echo ap_forum_first_unread_link_html($firstUnreadPostId);
        } else {
            echo '<p class="ap-forum-first-unread-wrap">'
                . '<a class="ap-forum-first-unread" href="#post-' . (int) $firstUnreadPostId . '">'
                . 'First unread post'
                . '</a></p>';
        }
    }
    ?>
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
            $canReport = !empty($post['can_report']);
            $canEdit = !empty($post['can_edit']);
            $canDelete = !empty($post['can_delete']);
            // Quote when the viewer can reply (display flag or topic-level can_reply).
            $canQuote = array_key_exists('can_quote', $post)
                ? !empty($post['can_quote'])
                : ($canReply && !$locked);
            $authorStats = is_array($post['author_stats'] ?? null) ? $post['author_stats'] : [];
            $authorPosts = (int) ($authorStats['forum_posts'] ?? 0);
            $authorLikesGiven = (int) ($authorStats['forum_likes_given'] ?? 0);
            $authorLikesReceived = (int) ($authorStats['forum_likes_received'] ?? 0);
            // Back-compat if a filter only provided a single likes total.
            if ($authorLikesReceived === 0 && isset($authorStats['forum_likes'])) {
                $authorLikesReceived = (int) $authorStats['forum_likes'];
            }
            $authorId = (int) ($post['author_id'] ?? 0);
            $authorUrl = (string) ($post['author_url'] ?? '');
            $avatarHtml = is_string($post['avatar_html'] ?? null) ? (string) $post['avatar_html'] : '';
            $joinedRaw = trim((string) ($post['joined'] ?? ''));
            $joinedDisplay = '';
            if ($joinedRaw !== '') {
                $joinedTs = strtotime($joinedRaw);
                $joinedDisplay = $joinedTs !== false
                    ? gmdate('M j, Y', $joinedTs)
                    : $joinedRaw;
            }
            $location = trim((string) ($post['location'] ?? ''));
            // SPEC B2: signature at bottom of post when enabled + present.
            $signatureHtml = '';
            if (!empty($post['signature_html']) && is_string($post['signature_html'])) {
                $signatureHtml = (string) $post['signature_html'];
            } elseif (trim((string) ($post['signature'] ?? '')) !== '') {
                $sigRaw = trim((string) $post['signature']);
                if (function_exists('agora_esc')) {
                    $signatureHtml = nl2br(agora_esc($sigRaw), false);
                } else {
                    $signatureHtml = nl2br(htmlspecialchars($sigRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                }
            }
            $subject = trim((string) ($post['subject'] ?? ''));
            $editCount = (int) ($post['edit_count'] ?? 0);
            $isEditing = $editPostId > 0 && $editPostId === $postId && $canEdit;
            $rawContent = (string) ($post['content'] ?? '');
            // Registered authors always get the stats block (posts / likes / joined);
            // location row is added only when non-empty (SPEC B2).
            $hasAuthorStats = $authorId > 0
                || $authorPosts > 0
                || $authorLikesGiven > 0
                || $authorLikesReceived > 0
                || $joinedDisplay !== '';
            // SPEC B2 actions L→R: Quote → Edit/mod → Like/Unlike → Report.
            $hasActions = $canQuote || $canLike || $likeCount > 0 || $canEdit || $canDelete || $canReport;
            ?>
            <section class="ap-forum-post ap-forum-post--two-pane" id="post-<?php echo $postId; ?>" aria-label="<?php echo agora_esc_attr('Post #' . $postNum . ' by ' . $author); ?>">
                <?php // Left pane — author (SPEC B2): avatar, name, role, posts, likes, joined, location. ?>
                <aside class="ap-forum-post__author" aria-label="<?php echo agora_esc_attr('Author: ' . $author); ?>">
                    <div class="ap-forum-post__avatar" aria-hidden="true">
                        <?php if ($avatarHtml !== '') : ?>
                            <?php echo $avatarHtml; ?>
                        <?php else : ?>
                            <span class="ap-forum-post__avatar-fallback"><?php echo agora_esc($initial); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="ap-forum-post__author-name">
                        <?php if ($authorUrl !== '') : ?>
                            <a href="<?php echo agora_esc_url($authorUrl); ?>" class="ap-forum-post__author-link"><?php echo agora_esc($author); ?></a>
                        <?php else : ?>
                            <?php echo agora_esc($author); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($role !== '') : ?>
                        <p class="ap-forum-post__author-role ap-forum-post__author-meta"><?php echo agora_esc($role); ?></p>
                    <?php endif; ?>
                    <div class="ap-forum-post__author-spacer" aria-hidden="true"></div>
                    <?php if ($hasAuthorStats) : ?>
                        <dl class="ap-forum-post__author-stats ap-forum-post__stats">
                            <?php if ($authorId > 0 || $authorPosts > 0) : ?>
                                <div class="ap-forum-post__stat ap-forum-post__stat--posts">
                                    <dt>Posts</dt>
                                    <dd><?php echo (int) $authorPosts; ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if ($authorId > 0 || $authorLikesGiven > 0 || $authorLikesReceived > 0) : ?>
                                <div class="ap-forum-post__stat ap-forum-post__stat--likes-given">
                                    <dt>Likes given</dt>
                                    <dd><?php echo (int) $authorLikesGiven; ?></dd>
                                </div>
                                <div class="ap-forum-post__stat ap-forum-post__stat--likes-received">
                                    <dt>Likes received</dt>
                                    <dd><?php echo (int) $authorLikesReceived; ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if ($joinedDisplay !== '') : ?>
                                <div class="ap-forum-post__stat ap-forum-post__stat--joined">
                                    <dt>Joined</dt>
                                    <dd>
                                        <time class="ap-forum-post__joined" datetime="<?php echo agora_esc_attr($joinedRaw); ?>"><?php echo agora_esc($joinedDisplay); ?></time>
                                    </dd>
                                </div>
                            <?php endif; ?>
                            <?php if ($location !== '') : ?>
                                <div class="ap-forum-post__stat ap-forum-post__stat--location">
                                    <dt>Location</dt>
                                    <dd class="ap-forum-post__location"><?php echo agora_esc($location); ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>
                </aside>
                <?php // Right pane — body + actions (SPEC B2). ?>
                <div class="ap-forum-post__main">
                    <div class="ap-forum-post__head">
                        <div class="ap-forum-post__head-start">
                            <?php if ($showSplit && (int) ($post['id'] ?? 0) > 0) : ?>
                                <label class="ap-forum-post__split" for="agora-split-post-<?php echo (int) $postId; ?>">
                                    <input type="checkbox" name="post_ids[]" value="<?php echo (int) $postId; ?>"
                                        id="agora-split-post-<?php echo (int) $postId; ?>"
                                        form="<?php echo agora_esc_attr($splitFormId); ?>"
                                        aria-label="<?php echo agora_esc_attr('Include post #' . $postNum . ' in the new topic'); ?>">
                                    <span>Split</span>
                                </label>
                            <?php endif; ?>
                            <?php if ($subject !== '') : ?>
                                <span class="ap-forum-post__subject"><?php echo agora_esc($subject); ?></span>
                            <?php elseif ($date !== '') : ?>
                                <time class="ap-forum-post__date" datetime="<?php echo agora_esc_attr($date); ?>"><?php echo agora_esc($date); ?></time>
                            <?php else : ?>
                                <span class="ap-forum-post__subject ap-forum-post__subject--fallback">#<?php echo $postNum; ?></span>
                            <?php endif; ?>
                            <?php if ($subject !== '' && $date !== '') : ?>
                                <time class="ap-forum-post__date" datetime="<?php echo agora_esc_attr($date); ?>"><?php echo agora_esc($date); ?></time>
                            <?php endif; ?>
                            <a class="ap-forum-post__permalink" href="#post-<?php echo $postId; ?>">#<?php echo $postNum; ?></a>
                        </div>
                        <?php if ($hasActions && !$isEditing) : ?>
                            <div class="ap-forum-post__actions" role="group" aria-label="Post actions">
                                <?php if ($canQuote) : ?>
                                    <a class="ap-btn ap-btn--ghost ap-btn--sm ap-forum-quote" href="?quote=<?php echo (int) $postId; ?>#reply" aria-label="<?php echo agora_esc_attr('Quote post #' . $postNum); ?>">Quote</a>
                                <?php endif; ?>
                                <?php if ($canEdit) : ?>
                                    <a class="ap-btn ap-btn--ghost ap-btn--sm ap-forum-edit" href="?edit_post=<?php echo (int) $postId; ?>#post-<?php echo (int) $postId; ?>" aria-label="<?php echo agora_esc_attr('Edit post #' . $postNum); ?>">Edit</a>
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
                                        <button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm ap-btn--danger ap-forum-delete" aria-label="<?php echo agora_esc_attr('Delete post #' . $postNum); ?>">Delete</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canLike || $likeCount > 0) : ?>
                                    <?php if ($canLike) : ?>
                                        <form method="post" action="" class="ap-forum-action-form ap-forum-action-form--inline">
                                            <input type="hidden" name="ap_forum_action" value="ap_forum_like_post">
                                            <input type="hidden" name="post_id" value="<?php echo (int) $postId; ?>">
                                            <?php
                                            if (function_exists('ap_nonce_field')) {
                                                echo ap_nonce_field('ap_forum_like_post_' . $postId);
                                            }
                                            $likeLabel = $likedByMe
                                                ? 'Unlike post #' . $postNum
                                                : 'Like post #' . $postNum;
                                            if ($likeCount > 0) {
                                                $likeLabel .= ' (' . $likeCount . ' like' . ($likeCount === 1 ? '' : 's') . ')';
                                            }
                                            ?>
                                            <button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm ap-forum-like<?php echo $likedByMe ? ' is-liked' : ''; ?>" aria-pressed="<?php echo $likedByMe ? 'true' : 'false'; ?>" aria-label="<?php echo agora_esc_attr($likeLabel); ?>">
                                                <span aria-hidden="true"><?php echo $likedByMe ? '👎' : '👍'; ?></span>
                                                <?php echo $likedByMe ? 'Unlike' : 'Like'; ?>
                                                <?php if ($likeCount > 0) : ?>
                                                    <span class="ap-forum-like__count" aria-hidden="true">(<?php echo (int) $likeCount; ?>)</span>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    <?php elseif ($likeCount > 0) : ?>
                                        <span class="ap-forum-like ap-forum-like--static" aria-label="<?php echo agora_esc_attr($likeCount . ' like' . ($likeCount === 1 ? '' : 's')); ?>">
                                            <span aria-hidden="true">👍</span>
                                            <span class="ap-forum-like__count" aria-hidden="true"><?php echo (int) $likeCount; ?></span>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($canReport) : ?>
                                    <?php
                                    if (function_exists('ap_forum_report_post_form_html')) {
                                        echo ap_forum_report_post_form_html($postId, [
                                            'id' => 'agora-report-reason-' . $postId,
                                            'post_number' => $postNum,
                                        ]);
                                    } else {
                                        echo '<form method="post" action="" class="ap-forum-action-form ap-forum-action-form--report-post">';
                                        echo '<input type="hidden" name="ap_forum_action" value="ap_forum_report_post">';
                                        echo '<input type="hidden" name="post_id" value="' . (int) $postId . '">';
                                        if (function_exists('ap_nonce_field')) {
                                            echo ap_nonce_field('ap_forum_report_post_' . $postId);
                                        }
                                        echo '<div class="ap-field ap-field--report-reason ap-field--inline">';
                                        echo '<label class="screen-reader-text" for="agora-report-reason-'
                                            . (int) $postId . '">Reason</label>';
                                        echo '<input type="text" id="agora-report-reason-' . (int) $postId
                                            . '" name="report_reason" required maxlength="255" placeholder="Reason"'
                                            . ' autocomplete="off">';
                                        echo '</div>';
                                        echo '<button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm ap-forum-report"'
                                            . ' aria-label="' . agora_esc_attr('Report post #' . $postNum) . '">Report</button>';
                                        echo '</form>';
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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
                    <?php if ($signatureHtml !== '') : ?>
                        <footer class="ap-forum-post__signature" aria-label="Signature">
                            <div class="ap-forum-post__signature-body">
                                <?php echo $signatureHtml; ?>
                            </div>
                        </footer>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php // SPEC B2 — bottom-right “Top” control (in-page jump to topic top). ?>
                    <div class="ap-forum-post__foot">
                        <a class="ap-forum-post__top" href="#ap-topic-top" aria-label="Back to top of topic">Top</a>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$disabled && !$notFound && $showSplit) : ?>
    <section class="ap-forum-form ap-forum-form--split" id="split" aria-labelledby="split-heading">
        <h2 id="split-heading" class="ap-comments__title">Split topic</h2>
        <p class="ap-forum__lead" id="split-hint">Select posts above to move into a new topic. At least one post must remain here.</p>
        <?php
        if (function_exists('ap_forum_split_topic_form_html')) {
            echo ap_forum_split_topic_form_html($topicId, $splitDestinations, [
                'form_id' => $splitFormId,
                'id' => 'agora-split-dest-forum',
                'title_id' => 'agora-split-topic-title',
                'current_forum_id' => $forumIdForType,
                'describedby' => 'split-hint',
            ]);
        } else {
            echo '<form method="post" action="" class="ap-forum-action-form--split-topic" id="'
                . agora_esc_attr($splitFormId) . '" aria-describedby="split-hint">';
            echo '<input type="hidden" name="ap_forum_action" value="ap_forum_split_topic">';
            echo '<input type="hidden" name="topic_id" value="' . (int) $topicId . '">';
            if (function_exists('ap_nonce_field')) {
                echo ap_nonce_field('ap_forum_split_topic_' . $topicId);
            }
            echo '<div class="ap-field ap-field--split-title">';
            echo '<label for="agora-split-topic-title">New title</label>';
            echo '<input type="text" id="agora-split-topic-title" name="topic_title"'
                . ' required maxlength="255" placeholder="New topic title">';
            echo '</div>';
            $splitDestOptions = [];
            foreach ($splitDestinations as $dest) {
                $destId = 0;
                $destName = '';
                if (is_object($dest)) {
                    $destId = (int) ($dest->forum_id ?? 0);
                    $destName = (string) ($dest->forum_name ?? '');
                } elseif (is_array($dest)) {
                    $destId = (int) ($dest['forum_id'] ?? 0);
                    $destName = (string) ($dest['forum_name'] ?? '');
                }
                if ($destId < 1 || $destName === '') {
                    continue;
                }
                $splitDestOptions[$destId] = $destName;
            }
            $splitOnlyCurrent = $forumIdForType > 0
                && count($splitDestOptions) === 1
                && isset($splitDestOptions[$forumIdForType]);
            if ($splitDestOptions !== [] && !$splitOnlyCurrent) {
                echo '<div class="ap-field ap-field--split-dest">';
                echo '<label for="agora-split-dest-forum">Forum</label>';
                echo '<select id="agora-split-dest-forum" name="dest_forum_id">';
                foreach ($splitDestOptions as $destId => $destName) {
                    $selected = $forumIdForType > 0 && $destId === $forumIdForType ? ' selected' : '';
                    echo '<option value="' . $destId . '"' . $selected . '>'
                        . agora_esc($destName) . '</option>';
                }
                echo '</select></div>';
            }
            echo '<button type="submit" class="ap-btn ap-btn--ghost ap-btn--sm"'
                . ' aria-label="Split selected posts into a new topic">Split</button>';
            echo '</form>';
        }
        ?>
    </section>
<?php endif; ?>

<?php if (!$disabled && !$notFound) : ?>
    <?php if ($locked) : ?>
        <?php
        echo function_exists('ap_forum_empty_state_html')
            ? ap_forum_empty_state_html('topic_locked')
            : '<div class="ap-empty ap-forum-empty ap-forum-empty--topic_locked" role="status"><p>This topic is locked. New replies are not accepted.</p></div>';
        ?>
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
                            'value' => $quoteMarkup,
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
                    <textarea id="agora-reply-body" name="reply_body" required rows="6" placeholder="Write your reply…"><?php echo agora_esc($quoteMarkup); ?></textarea>
                        <?php
                    }
                    ?>
                </div>
                <?php
                if (function_exists('ap_forum_notify_compose_checkbox_html')) {
                    echo ap_forum_notify_compose_checkbox_html([
                        'forum_id' => $forumIdForType,
                        'topic_id' => $topicId,
                        'id' => 'agora-notify-replies-reply',
                    ]);
                }
                ?>
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
