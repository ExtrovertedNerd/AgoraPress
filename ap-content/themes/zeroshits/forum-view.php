<?php

/**
 * Single forum template — topic list within a forum.
 *
 * Selected when ap_forum_view=forum or forum_id is set.
 * Data via {@see zeroshits_get_forum_topics_data()} / filter `zeroshits_forum_topics_data`.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

AP_Theme::getHeader();

$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$forumId = $q instanceof AP_Query ? (int) $q->get('forum_id', 0) : 0;
$forumName = $q instanceof AP_Query ? (string) $q->get('forum_name', '') : '';
$forumDesc = $q instanceof AP_Query ? (string) $q->get('forum_desc', '') : '';
$forumClosed = $q instanceof AP_Query && !empty($q->get('forum_closed', false));
$canPost = $q instanceof AP_Query && !empty($q->get('can_post_topic', false));
$notFound = $q instanceof AP_Query && !empty($q->get('ap_forum_not_found', false));
$disabled = $q instanceof AP_Query && !empty($q->get('ap_forum_disabled', false));
$allowedTopicTypes = $q instanceof AP_Query && is_array($q->get('allowed_topic_types', null))
    ? $q->get('allowed_topic_types', [])
    : [];
if ($forumName === '') {
    $forumName = 'Forum';
}
$home = function_exists('zeroshits_home_url') ? zeroshits_home_url('/') : '/';
$forumsUrl = function_exists('ap_forums_url')
    ? ap_forums_url()
    : (rtrim($home, '/') . '/forums/');
$topics = function_exists('zeroshits_get_forum_topics_data')
    ? zeroshits_get_forum_topics_data($forumId)
    : [];
$notice = function_exists('ap_get_forum_notice') ? ap_get_forum_notice() : null;
$loggedIn = false;
if (function_exists('ap_is_user_logged_in') && class_exists('AP_Session', false)) {
    try {
        $loggedIn = ap_is_user_logged_in();
    } catch (Throwable) {
        $loggedIn = false;
    }
}
$nonceAction = 'ap_forum_new_topic_' . $forumId;
if ($allowedTopicTypes === [] && $canPost && $forumId > 0 && function_exists('ap_forum_allowed_topic_types_for_create')) {
    $allowedTopicTypes = ap_forum_allowed_topic_types_for_create($forumId);
}
?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo zeroshits_esc_url($home); ?>">Home</a></li>
        <li><a href="<?php echo zeroshits_esc_url($forumsUrl); ?>">Forums</a></li>
        <li><span aria-current="page"><?php echo zeroshits_esc($forumName); ?></span></li>
    </ol>
</nav>

<div class="ap-forum ap-forum--view">
    <header class="ap-forum__header<?php echo $forumClosed ? ' ap-forum__header--locked' : ''; ?>">
        <div>
            <h1 class="ap-archive-title">
                <?php if ($forumClosed) : ?>
                    <span class="ap-badge ap-badge--locked">Locked</span>
                <?php endif; ?>
                <?php echo zeroshits_esc($forumName); ?>
            </h1>
            <?php if ($forumDesc !== '') : ?>
                <p class="ap-forum__lead"><?php echo zeroshits_esc($forumDesc); ?></p>
            <?php else : ?>
                <p class="ap-forum__lead">Topics in this forum, newest activity first.</p>
            <?php endif; ?>
        </div>
        <?php if ($canPost && !$forumClosed) : ?>
            <div class="ap-forum-toolbar">
                <a class="ap-btn" href="#new-topic">New topic</a>
            </div>
        <?php endif; ?>
    </header>

<?php if (is_array($notice) && ($notice['message'] ?? '') !== '') : ?>
    <div class="ap-forum-notice ap-forum-notice--<?php echo zeroshits_esc_attr((string) ($notice['type'] ?? 'info')); ?>" role="status">
        <p><?php echo zeroshits_esc((string) $notice['message']); ?></p>
    </div>
<?php endif; ?>

<?php if ($disabled) : ?>
    <?php
    echo function_exists('ap_forum_empty_state_html')
        ? ap_forum_empty_state_html('forum_disabled')
        : '<div class="ap-empty ap-forum-empty ap-forum-empty--forum_disabled" role="status"><p>The forum module is currently disabled.</p></div>';
    ?>
<?php elseif ($notFound) : ?>
    <?php
    echo function_exists('ap_forum_empty_state_html')
        ? ap_forum_empty_state_html('forum_not_found', ['back_url' => $forumsUrl])
        : '<div class="ap-empty ap-forum-empty ap-forum-empty--forum_not_found" role="status"><p>Forum not found.</p><p><a href="'
            . zeroshits_esc_url($forumsUrl) . '">Back to forums</a></p></div>';
    ?>
<?php elseif ($topics === []) : ?>
    <?php
    // Empty forum: distinct copy when closed vs open (and CTA when user can post).
    if (function_exists('ap_forum_empty_state_html')) {
        if ($forumClosed) {
            echo ap_forum_empty_state_html('forum_empty_closed');
        } else {
            echo ap_forum_empty_state_html('forum_empty', [
                'can_post' => $canPost,
                'cta_url' => $canPost ? '#new-topic' : '',
            ]);
        }
    } elseif ($forumClosed) {
        echo '<div class="ap-empty ap-forum-empty ap-forum-empty--forum_empty_closed" role="status">'
            . '<p>This forum is closed and has no topics yet.</p></div>';
    } else {
        echo '<div class="ap-empty ap-forum-empty ap-forum-empty--forum_empty" role="status">'
            . '<p>No topics yet in this forum.</p>';
        if ($canPost) {
            echo '<p class="ap-forum-empty__cta"><a class="ap-btn" href="#new-topic">Start the first topic</a></p>';
        } else {
            echo '<p>Be the first to start a conversation.</p>';
        }
        echo '</div>';
    }
    ?>
<?php else : ?>
    <section class="ap-forum-panel" aria-label="Topics">
        <?php // Column labels align with SPEC A4 topic rows (Topics N/A → em-dash in rows). ?>
        <div class="ap-forum-cat-header" role="row" aria-hidden="true">
            <span class="ap-forum-cat-header__title">Title</span>
            <span class="ap-forum-cat-header__topics">Topics</span>
            <span class="ap-forum-cat-header__posts">Posts</span>
            <span class="ap-forum-cat-header__last">Last Post</span>
        </div>
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
                // Posts = OP + replies (not replies-only). Prefer payload from
                // topicToDisplayRow; fall back for older themes/helpers.
                $posts = isset($topic['posts'])
                    ? (int) $topic['posts']
                    : (isset($topic['post_count']) ? (int) $topic['post_count'] : $replies + 1);
                $sticky = !empty($topic['sticky']);
                $locked = !empty($topic['locked']);
                $announce = !empty($topic['announcement']);
                $rules = !empty($topic['rules']);
                $unread = !empty($topic['is_unread']);
                $iconType = (string) ($topic['icon_type'] ?? 'standard');
                $last = is_array($topic['last_post'] ?? null) ? $topic['last_post'] : null;
                $lastTitle = $last !== null
                    ? (string) ($last['title'] ?? $title)
                    : $title;
                $lastAuthor = $last !== null
                    ? (string) ($last['author'] ?? $topic['last_author'] ?? '')
                    : (string) ($topic['last_author'] ?? '');
                $lastTime = $last !== null
                    ? (string) ($last['time'] ?? $last['date'] ?? $topic['last_date'] ?? '')
                    : (string) ($topic['last_date'] ?? '');
                $lastUrl = $last !== null
                    ? (string) ($last['url'] ?? $url)
                    : $url;
                // SPEC A1: guests → neutral; logged-in → read/unread.
                $tracking = function_exists('ap_is_user_logged_in') && ap_is_user_logged_in();
                $rowClass = function_exists('ap_forum_row_classes')
                    ? ap_forum_row_classes([
                        'is_unread' => $unread,
                        'tracking' => $tracking,
                        'topic' => true,
                        'icon_type' => $iconType,
                        'locked' => $locked,
                    ])
                    : ('ap-forum-list__item ap-forum-list__item--topic ap-forum-row ap-forum-row--topic'
                        . ($unread ? ' ap-forum-list__item--unread ap-forum-row--unread' : ' ap-forum-row--read')
                        . ($locked ? ' ap-forum-row--locked' : ''));
                ?>
                <li class="<?php echo zeroshits_esc_attr($rowClass); ?>">
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
                    <?php // SPEC A4 col 2: Title ?>
                    <div class="ap-forum-list__main ap-forum-row__title">
                        <h2 class="ap-forum-list__name">
                            <?php if ($sticky) : ?>
                                <span class="ap-badge ap-badge--sticky">Sticky</span>
                            <?php endif; ?>
                            <?php if ($announce) : ?>
                                <span class="ap-badge ap-badge--announce">Announcement</span>
                            <?php endif; ?>
                            <?php if ($rules) : ?>
                                <span class="ap-badge ap-badge--rules">Rules</span>
                            <?php endif; ?>
                            <?php if ($locked) : ?>
                                <span class="ap-badge ap-badge--locked">Locked</span>
                            <?php endif; ?>
                            <?php if ($unread) : ?>
                                <span class="ap-badge ap-badge--unread">Unread</span>
                            <?php endif; ?>
                            <a href="<?php echo zeroshits_esc_url($url); ?>"><?php echo zeroshits_esc($title); ?></a>
                        </h2>
                        <?php if ($author !== '') : ?>
                            <p class="ap-forum-list__desc">Started by <?php echo zeroshits_esc($author); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php // SPEC A4 col 3: Topics N/A on pure topic lists ?>
                    <div class="ap-forum-stat ap-forum-list__topics ap-forum-row__topics" aria-label="Topics not applicable">
                        <span class="ap-forum-stat__value ap-forum-list__empty">—</span>
                        <span class="ap-forum-stat__label">Topics</span>
                    </div>
                    <?php // SPEC A4 col 4: Posts (OP + replies) ?>
                    <div class="ap-forum-stat ap-forum-list__posts ap-forum-row__posts" aria-label="<?php echo zeroshits_esc_attr($posts . ' posts'); ?>">
                        <span class="ap-forum-stat__value"><?php echo $posts; ?></span>
                        <span class="ap-forum-stat__label">Posts</span>
                    </div>
                    <?php // SPEC A4 col 5: Last Post — 3 lines ?>
                    <div class="ap-forum-list__last ap-forum-row__last ap-forum-last-post">
                        <span class="ap-forum-last-post__title">
                            <?php if ($lastTitle !== '' && $lastUrl !== '') : ?>
                                <a href="<?php echo zeroshits_esc_url($lastUrl); ?>"><?php echo zeroshits_esc($lastTitle); ?></a>
                            <?php elseif ($lastTitle !== '') : ?>
                                <?php echo zeroshits_esc($lastTitle); ?>
                            <?php else : ?>
                                <span class="ap-forum-list__empty">—</span>
                            <?php endif; ?>
                        </span>
                        <span class="ap-forum-last-post__author">
                            <?php if ($lastAuthor !== '') : ?>
                                by <?php echo zeroshits_esc($lastAuthor); ?>
                            <?php else : ?>
                                <span class="ap-forum-list__empty">—</span>
                            <?php endif; ?>
                        </span>
                        <span class="ap-forum-last-post__time">
                            <?php if ($lastTime !== '') : ?>
                                <time datetime="<?php echo zeroshits_esc_attr($lastTime); ?>"><?php echo zeroshits_esc($lastTime); ?></time>
                            <?php else : ?>
                                <span class="ap-forum-list__empty">—</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (!$disabled && !$notFound && $forumId > 0) : ?>
    <?php if ($forumClosed && $topics !== []) : ?>
        <?php
        // Closed notice only when topics exist (empty+closed already used forum_empty_closed).
        echo function_exists('ap_forum_empty_state_html')
            ? ap_forum_empty_state_html('forum_closed')
            : '<div class="ap-empty ap-forum-empty ap-forum-empty--forum_closed" role="status"><p>This forum is closed. New topics are not accepted.</p></div>';
        ?>
    <?php elseif ($canPost && !$forumClosed) : ?>
        <section class="ap-forum-form" id="new-topic" aria-labelledby="new-topic-heading">
            <h2 id="new-topic-heading" class="ap-comments__title">Start a new topic</h2>
            <form method="post" action="">
                <input type="hidden" name="ap_forum_action" value="ap_forum_new_topic">
                <input type="hidden" name="forum_id" value="<?php echo (int) $forumId; ?>">
                <?php
                if (function_exists('ap_nonce_field')) {
                    echo ap_nonce_field($nonceAction);
                } elseif (class_exists('AP_Nonce', false)) {
                    echo AP_Nonce::field($nonceAction);
                }
                ?>
                <div class="ap-field">
                    <label for="agora-topic-title">Subject</label>
                    <input type="text" id="agora-topic-title" name="topic_title" required maxlength="255" placeholder="Topic subject" autocomplete="off">
                </div>
                <?php
                // SPEC A2: type control within sticky/announce permissions.
                if (function_exists('ap_forum_topic_type_select_html')) {
                    echo ap_forum_topic_type_select_html($allowedTopicTypes, [
                        'id' => 'agora-topic-type',
                        'name' => 'topic_type',
                        'selected' => 'standard',
                        'label' => 'Topic type',
                        'hide_single' => true,
                    ]);
                }
                ?>
                <div class="ap-field">
                    <?php
                    if (function_exists('ap_editor')) {
                        echo ap_editor([
                            'id' => 'agora-topic-body',
                            'name' => 'topic_body',
                            'mode' => class_exists('AP_Editor', false)
                                ? AP_Editor::modeForContext('forum')
                                : 'visual',
                            'rows' => 8,
                            'required' => true,
                            'label' => 'Message',
                            'placeholder' => 'Write your message…',
                            'class' => '',
                        ]);
                    } else {
                        ?>
                    <label for="agora-topic-body">Message</label>
                    <textarea id="agora-topic-body" name="topic_body" required rows="8" placeholder="Write your message…"></textarea>
                        <?php
                    }
                    ?>
                </div>
                <button type="submit" class="ap-btn">Post topic</button>
            </form>
        </section>
    <?php elseif (!$loggedIn) : ?>
        <section class="ap-forum-form" id="new-topic" aria-labelledby="new-topic-heading">
            <h2 id="new-topic-heading" class="ap-comments__title">Start a new topic</h2>
            <p class="ap-forum__lead">
                <a href="<?php echo zeroshits_esc_url(function_exists('ap_site_url') ? ap_site_url('ap-admin/login.php') : '/ap-admin/login.php'); ?>">Log in</a>
                to start a new topic.
            </p>
        </section>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
AP_Theme::getFooter();
