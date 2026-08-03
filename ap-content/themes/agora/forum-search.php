<?php

/**
 * Forum search results template.
 *
 * Selected when query var ap_forum_view=search.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$forumsUrl = function_exists('ap_forums_url') ? ap_forums_url() : (function_exists('agora_home_url') ? agora_home_url('/forums/') : '/forums/');
$searchAction = function_exists('agora_forum_search_url') ? agora_forum_search_url() : $forumsUrl;
$data = function_exists('agora_get_forum_search_data') ? agora_get_forum_search_data() : ['query' => '', 'total' => 0, 'results' => []];
$term = (string) ($data['query'] ?? '');
$total = (int) ($data['total'] ?? 0);
$results = is_array($data['results'] ?? null) ? $data['results'] : [];
$notice = function_exists('ap_get_forum_notice') ? ap_get_forum_notice() : null;
if ($notice === null && function_exists('agora_get_forum_notice')) {
    $notice = agora_get_forum_notice();
}
$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$disabled = $q instanceof AP_Query && !empty($q->get('ap_forum_disabled', false));
$searchEnabled = !$q instanceof AP_Query || $q->get('forum_search_enabled', true);
?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo agora_esc_url($home); ?>">Home</a></li>
        <li><a href="<?php echo agora_esc_url($forumsUrl); ?>">Forums</a></li>
        <li><span aria-current="page">Search</span></li>
    </ol>
</nav>

<div class="ap-forum ap-forum--search">
    <header class="ap-forum__header">
        <div>
            <h1 class="ap-archive-title">Forum search<?php
            if ($term !== '') {
                echo ': “' . agora_esc($term) . '”';
            }
            ?></h1>
            <p class="ap-forum__lead">
                <?php if ($term !== '') : ?>
                    <?php echo (int) $total; ?> result<?php echo $total === 1 ? '' : 's'; ?> found.
                <?php else : ?>
                    Search topics and posts across the community.
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
<?php elseif (!$searchEnabled) : ?>
    <div class="ap-empty" role="status">
        <p>Forum search is currently disabled.</p>
    </div>
<?php else : ?>
    <form class="ap-search-form ap-forum-search-form" role="search" method="get" action="<?php echo agora_esc_url($searchAction); ?>">
        <label class="screen-reader-text" for="ap-forum-search-field">Search forums</label>
        <?php
        // Pretty URLs: form posts to /forums/search/ with forum_s; plain uses query vars.
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks();
        if (!$pretty) :
            ?>
            <input type="hidden" name="ap_forum_view" value="search">
        <?php endif; ?>
        <input type="search" id="ap-forum-search-field" name="forum_s" value="<?php echo agora_esc_attr($term); ?>" placeholder="Search topics and posts…" required>
        <button type="submit">Search</button>
    </form>

    <?php if ($term === '') : ?>
        <div class="ap-empty" role="status">
            <p>Enter a search term to find topics and posts.</p>
        </div>
    <?php elseif ($results === []) : ?>
        <div class="ap-empty" role="status">
            <p>No results matched “<?php echo agora_esc($term); ?>”.</p>
        </div>
    <?php else : ?>
        <ul class="ap-forum-search-results">
            <?php foreach ($results as $row) : ?>
                <?php
                if (!is_array($row)) {
                    continue;
                }
                $rtype = (string) ($row['result_type'] ?? 'topic');
                $title = $rtype === 'post'
                    ? (string) ($row['topic_title'] ?? $row['subject'] ?? 'Post')
                    : (string) ($row['title'] ?? 'Topic');
                $url = (string) ($row['url'] ?? '#');
                $snippet = (string) ($row['snippet'] ?? '');
                $label = $rtype === 'post' ? 'Post' : 'Topic';
                ?>
                <li class="ap-forum-search-result ap-forum-search-result--<?php echo agora_esc_attr($rtype); ?>">
                    <span class="ap-forum-search-result__type"><?php echo agora_esc($label); ?></span>
                    <a class="ap-forum-search-result__title" href="<?php echo agora_esc_url($url); ?>"><?php echo agora_esc($title); ?></a>
                    <?php if ($snippet !== '') : ?>
                        <p class="ap-forum-search-result__snippet"><?php echo agora_esc($snippet); ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
AP_Theme::getFooter();
