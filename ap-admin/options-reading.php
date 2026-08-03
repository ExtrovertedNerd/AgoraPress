<?php

/**
 * Settings — Reading (front page + feeds).
 *
 * Homepage displays latest posts or a static page; posts page; posts per page;
 * syndication feed count and full text vs summary.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

// Published pages for the homepage / posts page dropdowns.
$pages = AP_Post::query([
    'post_type' => 'page',
    'post_status' => 'publish',
    'orderby' => 'post_title',
    'order' => 'ASC',
    'limit' => 200,
], $db);

// --- Save (Settings API nonce group "reading" or legacy ap_options_reading) ---
$isReadingPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (isset($_POST['ap_save_reading']) || isset($_POST['ap_settings_submit']));
if ($isReadingPost) {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $nonceOk = ap_check_nonce($nonce, 'ap_settings_reading', $userId > 0 ? $userId : null)
        || ap_check_nonce($nonce, 'ap_options_reading', $userId > 0 ? $userId : null);
    if (!$nonceOk) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateReadingSettings([
            'show_on_front' => (string) ($_POST['show_on_front'] ?? 'posts'),
            'page_on_front' => (int) ($_POST['page_on_front'] ?? 0),
            'page_for_posts' => (int) ($_POST['page_for_posts'] ?? 0),
            'posts_per_page' => (int) ($_POST['posts_per_page'] ?? 10),
            'posts_per_rss' => (int) ($_POST['posts_per_rss'] ?? 10),
            'rss_use_excerpt' => (string) ($_POST['rss_use_excerpt'] ?? '0'),
        ], $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-reading.php', ['message' => 'reading_saved']));
        }
        AP_Admin::addNotice('Could not save reading settings.', 'error');
    }
}

$showOnFront = AP_Options::showOnFront($db);
$pageOnFront = AP_Options::pageOnFront($db);
$pageForPosts = AP_Options::pageForPosts($db);
$postsPerPage = AP_Options::postsPerPage($db);
$postsPerRss = AP_Options::postsPerRss($db);
$rssExcerpt = AP_Options::rssUseExcerpt($db);

$ap_admin_title = 'Reading Settings';
$ap_admin_screen = 'options-reading';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Reading Settings</h1>
</div>

<p>Control what visitors see on the front of your site and how many posts appear in lists and feeds.</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php
    if (class_exists('AP_Settings', false)) {
        AP_Settings::settingsFields('reading');
    } else {
        echo ap_nonce_field('ap_options_reading', '_ap_nonce', false);
    }
    ?>

    <fieldset class="ap-fieldset">
        <legend>Your homepage displays</legend>
        <p>
            <label>
                <input type="radio" name="show_on_front" value="posts"
                    <?php echo $showOnFront === 'posts' ? 'checked' : ''; ?>>
                Your latest posts
            </label>
        </p>
        <p>
            <label>
                <input type="radio" name="show_on_front" value="page"
                    <?php echo $showOnFront === 'page' ? 'checked' : ''; ?>>
                A static page
            </label>
        </p>
        <p class="ap-field">
            <label for="page_on_front">Homepage</label>
            <select name="page_on_front" id="page_on_front">
                <option value="0">— Select —</option>
                <?php foreach ($pages as $page) : ?>
                    <?php if (!$page instanceof AP_Post) {
                        continue;
                    } ?>
                    <option value="<?php echo (int) $page->ID; ?>"
                        <?php echo $pageOnFront === (int) $page->ID ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html((string) $page->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="ap-field">
            <label for="page_for_posts">Posts page</label>
            <select name="page_for_posts" id="page_for_posts">
                <option value="0">— Select —</option>
                <?php foreach ($pages as $page) : ?>
                    <?php if (!$page instanceof AP_Post) {
                        continue;
                    } ?>
                    <option value="<?php echo (int) $page->ID; ?>"
                        <?php echo $pageForPosts === (int) $page->ID ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html((string) $page->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ap-help">Optional. When set with a static homepage, this page shows the blog index.</span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Blog pages</legend>
        <p class="ap-field">
            <label for="posts_per_page">Blog pages show at most</label>
            <input type="number" name="posts_per_page" id="posts_per_page"
                min="1" max="100" value="<?php echo (int) $postsPerPage; ?>">
            posts
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Syndication feeds</legend>
        <p class="ap-field">
            <label for="posts_per_rss">Syndication feeds show the most recent</label>
            <input type="number" name="posts_per_rss" id="posts_per_rss"
                min="1" max="100" value="<?php echo (int) $postsPerRss; ?>">
            items
        </p>
        <p>
            <label>
                <input type="radio" name="rss_use_excerpt" value="0"
                    <?php echo !$rssExcerpt ? 'checked' : ''; ?>>
                Full text
            </label>
        </p>
        <p>
            <label>
                <input type="radio" name="rss_use_excerpt" value="1"
                    <?php echo $rssExcerpt ? 'checked' : ''; ?>>
                Summary
            </label>
        </p>
        <?php
        $rssUrl = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2', $db) : '';
        $atomUrl = function_exists('ap_get_feed_link') ? ap_get_feed_link('atom', $db) : '';
        ?>
        <?php if ($rssUrl !== '') : ?>
            <p class="ap-help">
                RSS:
                <a href="<?php echo ap_esc_url($rssUrl); ?>" target="_blank" rel="noopener">
                    <?php echo ap_esc_html($rssUrl); ?>
                </a>
                · Atom:
                <a href="<?php echo ap_esc_url($atomUrl); ?>" target="_blank" rel="noopener">
                    <?php echo ap_esc_html($atomUrl); ?>
                </a>
            </p>
        <?php endif; ?>
    </fieldset>

    <p class="ap-form-actions">
        <button type="submit" name="ap_save_reading" value="1" class="button button-primary">
            Save Changes
        </button>
    </p>
</form>

<?php
require __DIR__ . '/admin-footer.php';
