<?php

use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

$requiredFns = [
    'get_posts',
    'get_term_by',
    'get_user_by',
    'get_option',
];

foreach ($requiredFns as $fn) {
    if (!function_exists($fn)) {
        echo "WordPress runtime is required. Run with: wp eval-file bin/demo-muster-extended.php\n";

        return;
    }
}

$seed = 4242;
$postType = 'event';
$termTaxonomy = 'category';
$termSlug = 'featured-events';
$userLogin = 'demo-editor';
$optionKey = 'muster_demo_mode';
$optionMissing = '__muster_missing__';

$postSlugs = ['event-ext-1', 'event-ext-2'];

$before = [
    'posts' => [],
    'term_exists' => false,
    'user_exists' => false,
    'option_exists' => false,
];

foreach ($postSlugs as $slug) {
    $existing = get_posts([
        'name' => $slug,
        'post_type' => $postType,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'suppress_filters' => true,
        'no_found_rows' => true,
    ]);

    $before['posts'][$slug] = !empty($existing);
}

$before['term_exists'] = get_term_by('slug', $termSlug, $termTaxonomy) !== false;
$before['user_exists'] = get_user_by('login', $userLogin) !== false;
$before['option_exists'] = get_option($optionKey, $optionMissing) !== $optionMissing;

$context = new MusterContext(new VictualsFactory(), seed: $seed);

$muster = new class($context) extends Muster {
    public function run(): void
    {
        $this->user('demo-editor')
            ->email('demo-editor@example.test')
            ->displayName('Demo Editor')
            ->role('editor')
            ->meta(['department' => 'content'])
            ->save();

        $this->term('category', 'Featured Events')
            ->slug('featured-events')
            ->description('Events highlighted by the Muster demo script.')
            ->meta(['highlight' => 1])
            ->save();

        $this->option('muster_demo_mode')
            ->value('live')
            ->autoload(false)
            ->save();

        $this->pattern('event')
            ->count(2)
            ->seed(4242)
            ->build(function (int $i) {
                return $this->post('event')
                    ->title($this->victuals()->headline())
                    ->slug('event-ext-' . $i)
                    ->status('publish')
                    ->content($this->victuals()->paragraphs(2))
                    ->terms('category', ['featured-events'])
                    ->meta(['muster_seed' => 4242, 'muster_index' => $i]);
            });
    }
};

$muster->run();

$created = 0;
$updated = 0;

foreach ($before['posts'] as $slug => $didExist) {
    if ($didExist) {
        $updated++;
    } else {
        $created++;
    }
}

if ($before['term_exists']) {
    $updated++;
} else {
    $created++;
}

if ($before['user_exists']) {
    $updated++;
} else {
    $created++;
}

if ($before['option_exists']) {
    $updated++;
} else {
    $created++;
}

echo sprintf(
    "Extended Muster demo complete. created=%d updated=%d seed=%d\n",
    $created,
    $updated,
    $seed
);
