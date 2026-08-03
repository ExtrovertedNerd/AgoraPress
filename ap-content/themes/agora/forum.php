<?php

/**
 * Forum index template — categories and forums list.
 *
 * Selected when query var ap_forum_view=index (or ap_forum is set).
 * Data via {@see agora_get_forum_index_data()} / filter `agora_forum_index_data`.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$home = function_exists('agora_home_url') ? agora_home_url('/') : '/';
$categories = function_exists('agora_get_forum_index_data') ? agora_get_forum_index_data() : [];
?>
<nav class="ap-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="<?php echo agora_esc_url($home); ?>">Home</a></li>
        <li><span aria-current="page">Forums</span></li>
    </ol>
</nav>

<div class="ap-forum ap-forum--index">
    <header class="ap-forum__header">
        <div>
            <h1 class="ap-archive-title">Forums</h1>
            <p class="ap-forum__lead">Discussions across the community. Browse categories below.</p>
        </div>
    </header>

<?php if ($categories === []) : ?>
    <div class="ap-empty" role="status">
        <p>No forums have been created yet.</p>
        <p>When the forum module is active, categories and forums will appear here.</p>
    </div>
<?php else : ?>
    <?php foreach ($categories as $category) : ?>
        <?php
        $catName = (string) ($category['name'] ?? 'Category');
        $forums = is_array($category['forums'] ?? null) ? $category['forums'] : [];
        ?>
        <section class="ap-forum-panel">
            <h2 class="ap-forum-panel__title"><?php echo agora_esc($catName); ?></h2>
            <?php if ($forums === []) : ?>
                <div class="ap-empty" style="border:0;box-shadow:none;border-radius:0;">
                    <p>No forums in this category.</p>
                </div>
            <?php else : ?>
                <ul class="ap-forum-list">
                    <?php foreach ($forums as $forum) : ?>
                        <?php
                        if (!is_array($forum)) {
                            continue;
                        }
                        $name = (string) ($forum['name'] ?? 'Forum');
                        $desc = (string) ($forum['description'] ?? '');
                        $url = (string) ($forum['url'] ?? '#');
                        $topics = (int) ($forum['topics'] ?? 0);
                        $posts = (int) ($forum['posts'] ?? 0);
                        $last = is_array($forum['last_post'] ?? null) ? $forum['last_post'] : null;
                        ?>
                        <li class="ap-forum-list__item">
                            <div>
                                <h3 class="ap-forum-list__name">
                                    <a href="<?php echo agora_esc_url($url); ?>"><?php echo agora_esc($name); ?></a>
                                </h3>
                                <?php if ($desc !== '') : ?>
                                    <p class="ap-forum-list__desc"><?php echo agora_esc($desc); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="ap-forum-stat" aria-label="<?php echo agora_esc_attr($topics . ' topics'); ?>">
                                <span class="ap-forum-stat__value"><?php echo $topics; ?></span>
                                <span class="ap-forum-stat__label">Topics</span>
                            </div>
                            <div class="ap-forum-stat" aria-label="<?php echo agora_esc_attr($posts . ' posts'); ?>">
                                <span class="ap-forum-stat__value"><?php echo $posts; ?></span>
                                <span class="ap-forum-stat__label">Posts</span>
                            </div>
                            <?php if ($last !== null) : ?>
                                <div class="ap-forum-list__last">
                                    <?php if (!empty($last['title'])) : ?>
                                        <strong><?php echo agora_esc((string) $last['title']); ?></strong>
                                    <?php endif; ?>
                                    <?php
                                    $bits = [];
                                    if (!empty($last['author'])) {
                                        $bits[] = (string) $last['author'];
                                    }
                                    if (!empty($last['date'])) {
                                        $bits[] = (string) $last['date'];
                                    }
                                    if ($bits !== []) {
                                        echo agora_esc(implode(' · ', $bits));
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
</div>
<?php
AP_Theme::getFooter();
