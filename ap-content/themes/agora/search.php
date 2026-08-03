<?php

/**
 * Search results template.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$term = $q instanceof AP_Query ? (string) $q->get('s', '') : '';
$home = function_exists('agora_home_url') ? agora_home_url('/') : (function_exists('ap_home_url') && class_exists('AP_Rewrite', false) ? ap_home_url('/') : '/');

echo '<h1 class="ap-archive-title">Search'
    . ($term !== ''
        ? ': ' . (function_exists('ap_esc_html') ? ap_esc_html($term) : htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        : '')
    . '</h1>';
?>
<form class="ap-search-form" role="search" method="get" action="<?php echo function_exists('ap_esc_url') ? ap_esc_url($home) : htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <label class="screen-reader-text" for="ap-search-field">Search for:</label>
    <input type="search" id="ap-search-field" name="s" value="<?php echo function_exists('ap_esc_attr') ? ap_esc_attr($term) : htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Search…" required>
    <button type="submit">Search</button>
</form>
<?php

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        $permalink = function_exists('agora_the_permalink') ? agora_the_permalink() : '';
        $excerpt = function_exists('ap_get_the_excerpt') ? ap_get_the_excerpt() : '';
        ?>
        <article class="ap-entry">
            <h2 class="ap-entry__title">
                <?php if ($permalink !== '') : ?>
                    <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php agora_the_title(); ?>
                    </a>
                <?php else : ?>
                    <?php agora_the_title(); ?>
                <?php endif; ?>
            </h2>
            <?php
            if (function_exists('agora_the_entry_meta')) {
                agora_the_entry_meta();
            }
            ?>
            <?php if ($excerpt !== '') : ?>
                <div class="ap-entry__excerpt"><?php echo function_exists('ap_esc_html') ? ap_esc_html($excerpt) : htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
        </article>
        <?php
    }
    if (function_exists('agora_the_posts_pagination')) {
        agora_the_posts_pagination();
    }
} else {
    echo '<div class="ap-empty" role="status"><p>No results found.</p></div>';
}

AP_Theme::getFooter();
