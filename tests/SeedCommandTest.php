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
    public function fresh(): void
    {
        $GLOBALS['__muster_seed_calls'][] = 'fresh';
    }

    public function run(): void
    {
        $GLOBALS['__muster_seed_calls'][] = 'run';
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

    public function testFreshRequiresAFreshMethod(): void
    {
        $bare = new class(\PressGang\Muster\Tests\bareContext()) extends Muster {
            public function run(): void
            {
            }
        };

        try {
            SeedCommand::handle([$bare::class], ['fresh' => true]);
            self::fail('Expected failure for missing fresh().');
        } catch (\WP_CLI_ExitException $e) {
            self::assertStringContainsString('fresh()', $e->getMessage());
        }
    }

    public function testFreshRunsBeforeRun(): void
    {
        SeedCommand::handle([RecordingSiteMuster::class], ['fresh' => true]);

        self::assertSame(['fresh', 'run'], $GLOBALS['__muster_seed_calls']);
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
