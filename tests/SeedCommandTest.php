<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Cli\SeedCommand;
use PressGang\Muster\Muster;

/**
 * Test double: a seedable Muster recording lifecycle order.
 */
final class RecordingSiteMuster extends Muster
{
    public function run(): void
    {
        $GLOBALS['__muster_seed_calls'][] = 'run';
        $this->page()->key('seed-page')->title('Seed page')->slug('seed-page')->save();
    }
}

final class SeedCommandTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
        $GLOBALS['__muster_seed_calls'] = [];
    }

    public function testRefusesProductionUnconditionally(): void
    {
        $GLOBALS['__muster_wp_environment'] = 'production';

        $this->expectException(\WP_CLI_ExitException::class);
        SeedCommand::handle([RecordingSiteMuster::class], []);
    }

    public function testDefaultClassFollowsChildNamespaceConvention(): void
    {
        try {
            SeedCommand::handle([], []);
            self::fail('Expected failure for missing default class.');
        } catch (\WP_CLI_ExitException $e) {
            self::assertStringContainsString('TestTheme\\Muster\\SiteMuster', $e->getMessage());
        }
    }

    public function testFreshNeedsNoCustomLifecycleMethod(): void
    {
        $bare = new class(\PressGang\Muster\Tests\bareContext()) extends Muster {
            public function run(): void
            {
            }
        };

        SeedCommand::handle([$bare::class], ['fresh' => true]);

        self::assertContains('Fresh: 0 owned resources cleared.', $GLOBALS['__muster_wp_cli_lines']);
    }

    public function testFreshDeletesOnlyPreviouslyOwnedResourcesBeforeRun(): void
    {
        SeedCommand::handle([RecordingSiteMuster::class], []);
        $GLOBALS['__muster_seed_calls'] = [];

        SeedCommand::handle([RecordingSiteMuster::class], ['fresh' => true]);

        self::assertSame(['run'], $GLOBALS['__muster_seed_calls']);
        self::assertContains('Fresh: 1 owned resources cleared.', $GLOBALS['__muster_wp_cli_lines']);
        self::assertCount(1, $GLOBALS['__muster_wp_posts']);
    }

    public function testRunsWithoutFreshByDefault(): void
    {
        SeedCommand::handle([RecordingSiteMuster::class], []);

        self::assertSame(['run'], $GLOBALS['__muster_seed_calls']);
    }
}

/**
 * @return \PressGang\Muster\MusterContext
 */
function bareContext(): \PressGang\Muster\MusterContext
{
    return new \PressGang\Muster\MusterContext(new \PressGang\Muster\Victuals\VictualsFactory());
}
