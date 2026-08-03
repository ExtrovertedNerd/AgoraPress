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
$forumDesc = $q instanceof AP_Query ? (string) $q->get('forum_desc', '') : '';
$forumClosed = $q instanceof AP_Query && !empty($q->get('forum_closed', false));
$canPost = $q instanceof AP_Query && !empty($q->get('can_post_topic', false));
$notFound = $q instanceof AP_Query && !empty($q->get('ap_forum_not_found', false));
$disabled = $q instanceof AP_Query && !empty($q->get('ap_forum_disabled', false));
if ($forumName === '') {
    $forumName = 'Forum';
}
$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$forumsUrl = function_exists('ap_forums_url')
    ? ap_forums_url()
    : (rtrim($home, '/') . '/forums/');
$topics = function_exists('agora_get_forum_topics_data')
    ? agora_get_forum_topics_data($forumId)
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
            <?php if ($forumDesc !== '') : ?>
                <p class="ap-forum__lead"><?php echo agora_esc($forumDesc); ?></p>
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
        <p>Forum not found.</p>
        <p><a href="<?php echo agora_esc_url($forumsUrl); ?>">Back to forums</a></p>
    </div>
<?php elseif ($topics === []) : ?>
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
                $unread = !empty($topic['is_unread']);
                $lastDate = (string) ($topic['last_date'] ?? '');
                $lastAuthor = (string) ($topic['last_author'] ?? '');
                ?>
                <li class="ap-forum-list__item ap-forum-list__item--topic<?php echo $unread ? ' ap-forum-list__item--unread' : ''; ?>">
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
                            <?php if ($unread) : ?>
                                <span class="ap-badge ap-badge--unread">Unread</span>
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

<?php if (!$disabled && !$notFound && $forumId > 0) : ?>
    <?php if ($forumClosed) : ?>
        <div class="ap-empty" role="status">
            <p>This forum is closed. New topics are not accepted.</p>
        </div>
    <?php elseif ($canPost) : ?>
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
                <div class="ap-field">
                    <?php
                    if (function_exists('ap_editor')) {
                        echo ap_editor([
                            'id' => 'agora-topic-body',
                            'name' => 'topic_body',
                            'mode' => class_exists('AP_Editor', false)
                                ? AP_Editor::modeForContext('forum')
                                : 'bbcode',
                            'rows' => 8,
                            'required' => true,
                            'label' => 'Message',
                            'placeholder' => 'Write your message… (BBCode and Markdown supported)',
                            'class' => '',
                        ]);
                    } else {
                        ?>
                    <label for="agora-topic-body">Message</label>
                    <textarea id="agora-topic-body" name="topic_body" required rows="8" placeholder="Write your message… (BBCode and Markdown supported)"></textarea>
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
                <a href="<?php echo agora_esc_url(function_exists('ap_site_url') ? ap_site_url('ap-admin/login.php') : '/ap-admin/login.php'); ?>">Log in</a>
                to start a new topic.
            </p>
        </section>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
AP_Theme::getFooter();
