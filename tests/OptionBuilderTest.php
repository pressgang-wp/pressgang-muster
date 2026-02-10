<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Builders\OptionBuilder;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class OptionBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testSaveUpsertsOption(): void
    {
        $context = new MusterContext(new VictualsFactory());

        (new OptionBuilder($context, 'site_mode'))
            ->value('live')
            ->autoload(false)
            ->save();

        self::assertSame('live', $GLOBALS['__muster_wp_options']['site_mode']['value']);
        self::assertSame(false, $GLOBALS['__muster_wp_options']['site_mode']['autoload']);
    }

    public function testDryRunSkipsOptionWrites(): void
    {
        $context = new MusterContext(new VictualsFactory(), dryRun: true);

        (new OptionBuilder($context, 'site_mode'))
            ->value('staging')
            ->save();

        self::assertCount(0, $GLOBALS['__muster_wp_options']);
    }
}
