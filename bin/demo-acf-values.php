<?php
/**
 * Demo: derive seed values from a theme's acf-json directory.
 *
 * Usage: php bin/demo-acf-values.php /path/to/theme/acf-json [seed]
 *
 * Pure PHP — no WordPress required. Media/relational fields print as
 * "(needs provider)" placeholders; in a live Muster run those come from
 * AttachmentBuilder and post/term lookups.
 */

require_once __DIR__ . '/../tests/bootstrap.php';

use PressGang\Muster\Acf\AcfJson;
use PressGang\Muster\Acf\AcfValueGenerator;
use PressGang\Muster\Victuals\VictualsFactory;

$dir = $argv[1] ?? null;
$seed = (int) ($argv[2] ?? 42);

if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php bin/demo-acf-values.php /path/to/acf-json [seed]\n");
    exit(1);
}

$generator = new AcfValueGenerator((new VictualsFactory())->make($seed), [
    'attachment' => fn (string $name): int => 0, // placeholder: AttachmentBuilder in a live run
    'post' => fn (array $types): int => 0,
    'term' => fn (string $taxonomy): int => 0,
    'user' => fn (): int => 0,
]);

$groups = AcfJson::groups($dir);
echo count($groups) . " field groups in {$dir}\n\n";

foreach ($groups as $group) {
    $fields = (array) $group['fields'];
    $populated = $generator->populated($fields);
    $minimal = $generator->minimal($fields);
    $targets = array_map(fn (array $t): string => "{$t['param']}={$t['value']}", AcfJson::targets($group));

    printf(
        "%-42s %2d fields → %2d populated / %d required  [%s]\n",
        $group['title'] ?? $group['key'],
        count($fields),
        count($populated),
        count($minimal),
        implode(', ', $targets) ?: 'no seedable target'
    );
}
