<?php


use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

if (!function_exists('get_posts')) {
    echo "WordPress runtime is required. Run with: wp eval-file bin/demo-muster.php\n";

    return;
}

$seed = 1978;
$postType = 'event';
$total = 3;

$slugs = [];
for ($i = 1; $i <= $total; $i++) {
    $slugs[] = "event-{$i}";
}

$beforeBySlug = [];
foreach ($slugs as $slug) {
    $existing = get_posts([
        'name' => $slug,
        'post_type' => $postType,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'suppress_filters' => true,
        'no_found_rows' => true,
    ]);

    $beforeBySlug[$slug] = !empty($existing) ? (int) $existing[0] : 0;
}

$context = new MusterContext(new VictualsFactory(), seed: $seed);

$muster = new class($context) extends Muster {
    public function run(): void
    {
        $this->pattern('event')
            ->count(3)
            ->seed(1978)
            ->build(fn (int $i) =>
                $this->post('event')
                    ->title($this->victuals()->headline())
                    ->slug("event-{$i}")
                    ->status('publish')
                    ->content($this->victuals()->paragraphs(2))
                    ->meta(['muster_seed' => 1978, 'muster_index' => $i])
            );
    }
};

$muster->run();

$created = 0;
$updated = 0;

foreach ($slugs as $slug) {
    if (($beforeBySlug[$slug] ?? 0) > 0) {
        $updated++;
    } else {
        $created++;
    }
}

echo sprintf(
    "Muster demo complete. created=%d updated=%d seed=%d\n",
    $created,
    $updated,
    $seed
);
