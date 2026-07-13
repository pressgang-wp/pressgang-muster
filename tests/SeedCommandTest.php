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

/**
 * Test double with independently selectable declaration groups.
 */
final class GroupedRecordingSiteMuster extends Muster
{
    public function run(): void
    {
        $this->group('selected', function (): void {
            $this->page()->key('page:selected')->title('Selected')->slug('selected')->save();
        });

        $this->group('other', function (): void {
            $this->page()->key('page:other')->title('Other')->slug('other')->save();
        });
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

        self::assertContains('  Summary: create=0 update=0 keep=0 prune=0 conflict=0', $GLOBALS['__muster_wp_cli_lines']);
    }

    public function testFreshDeletesOnlyPreviouslyOwnedResourcesBeforeRun(): void
    {
        SeedCommand::handle([RecordingSiteMuster::class], []);
        $GLOBALS['__muster_seed_calls'] = [];

        SeedCommand::handle([RecordingSiteMuster::class], ['fresh' => true]);

        self::assertSame(['run', 'run'], $GLOBALS['__muster_seed_calls']);
        self::assertContains('  Summary: create=1 update=0 keep=0 prune=1 conflict=0', $GLOBALS['__muster_wp_cli_lines']);
        self::assertCount(1, $GLOBALS['__muster_wp_posts']);
    }

    public function testFreshOnlyResetsFullScopeThenRebuildsSelectedGroups(): void
    {
        SeedCommand::handle([GroupedRecordingSiteMuster::class], []);

        SeedCommand::handle([GroupedRecordingSiteMuster::class], [
            'fresh' => true,
            'only' => 'selected',
        ]);

        self::assertCount(1, $GLOBALS['__muster_wp_posts']);
        self::assertSame('selected', reset($GLOBALS['__muster_wp_posts'])['post_name']);
    }

    public function testRunsWithoutFreshByDefault(): void
    {
        SeedCommand::handle([RecordingSiteMuster::class], []);

        self::assertSame(['run', 'run'], $GLOBALS['__muster_seed_calls']);
    }
}

/**
 * @return \PressGang\Muster\MusterContext
 */
function bareContext(): \PressGang\Muster\MusterContext
{
    return new \PressGang\Muster\MusterContext(new \PressGang\Muster\Victuals\VictualsFactory());
}
