<?php

/**
 * AgoraPress admin dashboard home with stats.
 *
 * Widgets: At a Glance (module-aware counts), Activity (recent content +
 * comments), Quick Draft (when Blog is enabled and user can edit_posts).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

// Dashboard is available to any role with `read` (shell gate + screen map).
AP_Admin::requireCapability('read');

$db = ap_db();
$userId = (int) ap_get_current_user_id();

// Quick Draft POST.
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['ap_dashboard_action'] ?? '') === 'quick-draft'
) {
    $result = AP_Admin_Dashboard::saveQuickDraft($_POST, $userId, $db);
    if ($result['ok'] && $result['id'] > 0) {
        AP_Admin::redirect(AP_Admin::url('index.php', [
            'message' => 'draft_saved',
            'post' => $result['id'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    if (($result['message_key'] ?? '') === 'nonce') {
        AP_Admin::addNotice('Security check failed. Please reload and try again.', 'error');
    }
}

AP_Admin::consumeQueryNotice();

$glance = AP_Admin_Dashboard::getAtAGlance($db);
$recentContent = AP_Admin_Dashboard::getRecentContent(AP_Admin_Dashboard::ACTIVITY_LIMIT, $db);
$recentComments = AP_Admin_Dashboard::getRecentComments(AP_Admin_Dashboard::ACTIVITY_LIMIT, $db);
$showQuickDraft = AP_Admin_Dashboard::canQuickDraft($userId, $db);

// Hall of Fame: voluntary domain registration prompt (never automatic).
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['ap_dashboard_action'] ?? '') === 'hof-dismiss'
    && class_exists('AP_Hall_Of_Fame', false)
) {
    $hofDismiss = AP_Hall_Of_Fame::dismissPrompt($userId, $_POST, $db);
    if ($hofDismiss['ok']) {
        AP_Admin::redirect(AP_Admin::url('index.php', [
            'message' => 'hall_of_fame_dismissed',
        ]));
    }
    foreach ($hofDismiss['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
}

$showHallOfFamePrompt = class_exists('AP_Hall_Of_Fame', false)
    && AP_Hall_Of_Fame::shouldShowPrompt($userId, $db);
$hofDomain = $showHallOfFamePrompt ? AP_Hall_Of_Fame::resolveDomain($db) : '';
$hofSettingsUrl = AP_Admin::url('options-hall-of-fame.php');

$blogOn = !empty($glance['modules']['blog']);
$pagesOn = !empty($glance['modules']['static_pages']);
// Forum stats reserved for Phase 5 (dedicated tables).
$forumOn = !empty($glance['modules']['forum']);

$postsListUrl = AP_Admin::url('edit.php', ['post_type' => 'post']);
$postsNewUrl = AP_Admin::url('post-new.php', ['post_type' => 'post']);
$pagesListUrl = AP_Admin::url('edit.php', ['post_type' => 'page']);
$pagesNewUrl = AP_Admin::url('post-new.php', ['post_type' => 'page']);
$commentsUrl = AP_Admin::url('edit-comments.php');
$commentsPendingUrl = AP_Admin::url('edit-comments.php', ['comment_status' => 'hold']);
$usersUrl = AP_Admin::url('users.php');

$siteName = AP_Admin::siteName($db);
$user = function_exists('ap_get_current_user') ? ap_get_current_user($db) : null;
$displayName = '';
if ($user !== null) {
    $displayName = $user->display_name !== '' ? $user->display_name : $user->user_login;
}

$ap_admin_title = 'Dashboard';
$ap_admin_screen = 'dashboard';
$ap_admin_body_class = 'ap-dashboard';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Dashboard</h1>
</div>

<p class="ap-dashboard-welcome">
    <?php if ($displayName !== '') : ?>
        Welcome, <strong><?php echo ap_esc_html($displayName); ?></strong>.
    <?php else : ?>
        Welcome to the AgoraPress admin.
    <?php endif; ?>
    Managing <strong><?php echo ap_esc_html($siteName); ?></strong>.
</p>

<?php if ($showHallOfFamePrompt) : ?>
    <section class="ap-notice ap-notice--info ap-hof-prompt" aria-labelledby="ap-hof-prompt-title">
        <h2 id="ap-hof-prompt-title" class="ap-hof-prompt-title">Join the Hall of Fame?</h2>
        <p>
            Optionally register your domain
            <?php if ($hofDomain !== '') : ?>
                (<code><?php echo ap_esc_html($hofDomain); ?></code>)
            <?php endif; ?>
            so AgoraPress can show a public install counter — fully voluntary,
            domain only, withdrawable anytime. No telemetry and no installer pings.
        </p>
        <div class="ap-card-actions">
            <a class="button button-primary" href="<?php echo ap_esc_url($hofSettingsUrl); ?>">
                Join the Hall of Fame
            </a>
            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('index.php')); ?>" class="ap-hof-dismiss-form">
                <input type="hidden" name="ap_dashboard_action" value="hof-dismiss">
                <?php echo ap_nonce_field(AP_Hall_Of_Fame::NONCE_DISMISS, '_ap_nonce', false, $userId); ?>
                <button type="submit" class="button">Not now</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<div class="ap-dashboard-grid">
    <!-- At a Glance -->
    <section class="ap-metabox ap-dashboard-widget" aria-labelledby="ap-glance-title">
        <h2 id="ap-glance-title" class="ap-metabox-title">At a Glance</h2>
        <div class="ap-metabox-body">
            <ul class="ap-glance-list">
                <?php if ($blogOn) : ?>
                    <li class="ap-glance-item">
                        <a href="<?php echo ap_esc_url($postsListUrl); ?>">
                            <span class="ap-glance-count"><?php echo (int) $glance['posts']['publish']; ?></span>
                            <span class="ap-glance-label"><?php echo (int) $glance['posts']['publish'] === 1 ? 'Post' : 'Posts'; ?></span>
                        </a>
                        <span class="ap-glance-meta">
                            <?php echo (int) $glance['posts']['draft']; ?> draft<?php echo (int) $glance['posts']['draft'] === 1 ? '' : 's'; ?>
                            <?php if ((int) $glance['posts']['pending'] > 0) : ?>
                                · <?php echo (int) $glance['posts']['pending']; ?> pending
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endif; ?>

                <?php if ($pagesOn) : ?>
                    <li class="ap-glance-item">
                        <a href="<?php echo ap_esc_url($pagesListUrl); ?>">
                            <span class="ap-glance-count"><?php echo (int) $glance['pages']['publish']; ?></span>
                            <span class="ap-glance-label"><?php echo (int) $glance['pages']['publish'] === 1 ? 'Page' : 'Pages'; ?></span>
                        </a>
                        <span class="ap-glance-meta">
                            <?php echo (int) $glance['pages']['draft']; ?> draft<?php echo (int) $glance['pages']['draft'] === 1 ? '' : 's'; ?>
                        </span>
                    </li>
                <?php endif; ?>

                <?php if ($blogOn) : ?>
                    <li class="ap-glance-item">
                        <a href="<?php echo ap_esc_url($commentsUrl); ?>">
                            <span class="ap-glance-count"><?php echo (int) $glance['comments']['total']; ?></span>
                            <span class="ap-glance-label"><?php echo (int) $glance['comments']['total'] === 1 ? 'Comment' : 'Comments'; ?></span>
                        </a>
                        <span class="ap-glance-meta">
                            <?php echo (int) $glance['comments']['approved']; ?> approved
                            <?php if ((int) $glance['comments']['pending'] > 0) : ?>
                                · <a href="<?php echo ap_esc_url($commentsPendingUrl); ?>"><?php echo (int) $glance['comments']['pending']; ?> pending</a>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endif; ?>

                <li class="ap-glance-item">
                    <?php if (AP_Admin::currentUserCan('list_users', $db)) : ?>
                        <a href="<?php echo ap_esc_url($usersUrl); ?>">
                            <span class="ap-glance-count"><?php echo (int) $glance['users']; ?></span>
                            <span class="ap-glance-label"><?php echo (int) $glance['users'] === 1 ? 'User' : 'Users'; ?></span>
                        </a>
                    <?php else : ?>
                        <span class="ap-glance-static">
                            <span class="ap-glance-count"><?php echo (int) $glance['users']; ?></span>
                            <span class="ap-glance-label"><?php echo (int) $glance['users'] === 1 ? 'User' : 'Users'; ?></span>
                        </span>
                    <?php endif; ?>
                </li>

                <?php if ($forumOn && is_array($glance['forum'] ?? null)) : ?>
                    <?php
                    $fStats = $glance['forum'];
                    $forumsListUrl = AP_Admin::url('forums.php');
                    $topicsListUrl = AP_Admin::url('forum-topics.php');
                    $modUrl = AP_Admin::url('forum-moderation.php');
                    ?>
                    <li class="ap-glance-item">
                        <?php if (AP_Admin::currentUserCan('manage_forums', $db)) : ?>
                            <a href="<?php echo ap_esc_url($forumsListUrl); ?>">
                                <span class="ap-glance-count"><?php echo (int) $fStats['forums']; ?></span>
                                <span class="ap-glance-label">
                                    <?php echo (int) $fStats['forums'] === 1 ? 'Forum' : 'Forums'; ?>
                                </span>
                            </a>
                        <?php else : ?>
                            <span class="ap-glance-static">
                                <span class="ap-glance-count"><?php echo (int) $fStats['forums']; ?></span>
                                <span class="ap-glance-label">
                                    <?php echo (int) $fStats['forums'] === 1 ? 'Forum' : 'Forums'; ?>
                                </span>
                            </span>
                        <?php endif; ?>
                    </li>
                    <li class="ap-glance-item">
                        <?php if (AP_Admin::currentUserCan('moderate_forums', $db)) : ?>
                            <a href="<?php echo ap_esc_url($topicsListUrl); ?>">
                                <span class="ap-glance-count"><?php echo (int) $fStats['topics']; ?></span>
                                <span class="ap-glance-label">
                                    <?php echo (int) $fStats['topics'] === 1 ? 'Topic' : 'Topics'; ?>
                                </span>
                            </a>
                        <?php else : ?>
                            <span class="ap-glance-static">
                                <span class="ap-glance-count"><?php echo (int) $fStats['topics']; ?></span>
                                <span class="ap-glance-label">
                                    <?php echo (int) $fStats['topics'] === 1 ? 'Topic' : 'Topics'; ?>
                                </span>
                            </span>
                        <?php endif; ?>
                    </li>
                    <?php if ((int) $fStats['pending'] > 0 && AP_Admin::currentUserCan('moderate_forums', $db)) : ?>
                        <li class="ap-glance-item">
                            <a href="<?php echo ap_esc_url($modUrl); ?>">
                                <span class="ap-glance-count"><?php echo (int) $fStats['pending']; ?></span>
                                <span class="ap-glance-label">Pending</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>

            <p class="ap-card-actions">
                <?php if ($blogOn && AP_Admin::currentUserCan('edit_posts', $db)) : ?>
                    <a class="button button-primary" href="<?php echo ap_esc_url($postsNewUrl); ?>">Add Post</a>
                    <a class="button" href="<?php echo ap_esc_url($postsListUrl); ?>">All Posts</a>
                <?php endif; ?>
                <?php if ($pagesOn && AP_Admin::currentUserCan('edit_pages', $db)) : ?>
                    <a class="button button-primary" href="<?php echo ap_esc_url($pagesNewUrl); ?>">Add Page</a>
                    <a class="button" href="<?php echo ap_esc_url($pagesListUrl); ?>">All Pages</a>
                <?php endif; ?>
                <?php if ($forumOn && AP_Admin::currentUserCan('manage_forums', $db)) : ?>
                    <a class="button button-primary" href="<?php echo ap_esc_url(AP_Admin::url('forum-edit.php')); ?>">Add Forum</a>
                    <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('forums.php')); ?>">All Forums</a>
                <?php endif; ?>
            </p>
        </div>
    </section>

    <!-- Activity -->
    <section class="ap-metabox ap-dashboard-widget" aria-labelledby="ap-activity-title">
        <h2 id="ap-activity-title" class="ap-metabox-title">Activity</h2>
        <div class="ap-metabox-body">
            <h3 class="ap-widget-subtitle">Recently published</h3>
            <?php if ($recentContent === []) : ?>
                <p class="ap-muted">No published content yet.</p>
            <?php else : ?>
                <ul class="ap-activity-list">
                    <?php foreach ($recentContent as $item) : ?>
                        <?php
                        $itemId = (int) $item->ID;
                        $itemTitle = $item->post_title !== '' ? $item->post_title : '(no title)';
                        $itemType = $item->post_type === 'page' ? 'Page' : 'Post';
                        $canEdit = AP_Admin::currentUserCan(
                            AP_Admin::editMetaCapForPostType($item->post_type),
                            $db,
                            $itemId
                        );
                        $editUrl = AP_Admin::url('post.php', ['post' => $itemId, 'action' => 'edit']);
                        $dateLabel = $item->post_date !== ''
                            ? substr($item->post_date, 0, 16)
                            : '';
                        ?>
                        <li>
                            <span class="ap-activity-type"><?php echo ap_esc_html($itemType); ?></span>
                            <?php if ($canEdit) : ?>
                                <a href="<?php echo ap_esc_url($editUrl); ?>"><?php echo ap_esc_html($itemTitle); ?></a>
                            <?php else : ?>
                                <?php echo ap_esc_html($itemTitle); ?>
                            <?php endif; ?>
                            <?php if ($dateLabel !== '') : ?>
                                <span class="ap-activity-date"><?php echo ap_esc_html($dateLabel); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($blogOn) : ?>
                <h3 class="ap-widget-subtitle">Recent comments</h3>
                <?php if ($recentComments === []) : ?>
                    <p class="ap-muted">No comments yet.</p>
                <?php else : ?>
                    <ul class="ap-activity-list">
                        <?php foreach ($recentComments as $comment) :
                            $author = $comment->comment_author !== ''
                                ? $comment->comment_author
                                : 'Anonymous';
                            $excerpt = function_exists('ap_strip_all_tags')
                                ? ap_strip_all_tags($comment->comment_content)
                                : strip_tags($comment->comment_content);
                            $excerpt = function_exists('mb_substr')
                                ? mb_substr($excerpt, 0, 80)
                                : substr($excerpt, 0, 80);
                            $status = $comment->comment_approved;
                            if ($status === AP_Comment::STATUS_APPROVED) {
                                $statusLabel = 'Approved';
                            } elseif ($status === AP_Comment::STATUS_HOLD) {
                                $statusLabel = 'Pending';
                            } else {
                                $statusLabel = (string) $status;
                            }
                            $onPost = AP_Post::get((int) $comment->comment_post_ID, $db);
                            $postTitle = ($onPost !== null && $onPost->post_title !== '')
                                ? $onPost->post_title
                                : ('Post #' . (int) $comment->comment_post_ID);
                            $statusClass = (string) $status === '1' ? 'approved' : 'pending';
                            ?>
                            <li>
                                <strong><?php echo ap_esc_html($author); ?></strong>
                                on
                                <?php if (AP_Admin::currentUserCan('moderate_comments', $db)) : ?>
                                    <a href="<?php echo ap_esc_url($commentsUrl); ?>">
                                        <?php echo ap_esc_html($postTitle); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo ap_esc_html($postTitle); ?>
                                <?php endif; ?>
                                <span class="ap-activity-status ap-status-<?php echo ap_esc_attr($statusClass); ?>">
                                    <?php echo ap_esc_html($statusLabel); ?>
                                </span>
                                <?php if ($excerpt !== '') : ?>
                                    <span class="ap-activity-excerpt"><?php echo ap_esc_html($excerpt); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (AP_Admin::currentUserCan('moderate_comments', $db)) : ?>
                        <p class="ap-card-actions">
                            <a class="button" href="<?php echo ap_esc_url($commentsUrl); ?>">View all comments</a>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($showQuickDraft) : ?>
        <!-- Quick Draft -->
        <section class="ap-metabox ap-dashboard-widget ap-dashboard-quick-draft" aria-labelledby="ap-quick-draft-title">
            <h2 id="ap-quick-draft-title" class="ap-metabox-title">Quick Draft</h2>
            <div class="ap-metabox-body">
                <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('index.php')); ?>" class="ap-quick-draft-form">
                    <input type="hidden" name="ap_dashboard_action" value="quick-draft">
                    <?php echo ap_nonce_field('quick-draft', '_ap_nonce', false, $userId); ?>
                    <p>
                        <label for="ap-quick-draft-title">Title</label>
                        <input type="text" name="post_title" id="ap-quick-draft-title" class="regular-text" value="" autocomplete="off">
                    </p>
                    <p>
                        <label for="ap-quick-draft-content">Content</label>
                        <textarea name="post_content" id="ap-quick-draft-content" rows="5" class="large-text"></textarea>
                    </p>
                    <p class="ap-card-actions">
                        <button type="submit" class="button button-primary">Save Draft</button>
                    </p>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php
require __DIR__ . '/admin-footer.php';
