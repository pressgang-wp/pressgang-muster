<?php

namespace {
    if (!class_exists('WP_CLI_ExitException')) {
        class WP_CLI_ExitException extends \RuntimeException
        {
        }
    }

    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            /**
             * @param string $command
             * @param mixed $handler
             * @return void
             */
            public static function add_command(string $command, mixed $handler): void
            {
                $GLOBALS['__muster_wp_cli_commands'][$command] = $handler;
            }

            /**
             * @param string $message
             * @return void
             */
            public static function line(string $message): void
            {
                $GLOBALS['__muster_wp_cli_lines'][] = $message;
            }

            /**
             * @param string $message
             * @return never
             * @throws WP_CLI_ExitException
             */
            public static function error(string $message): never
            {
                $GLOBALS['__muster_wp_cli_lines'][] = $message;
                throw new WP_CLI_ExitException($message);
            }

            public static function halt(int $exitCode): never
            {
                throw new WP_CLI_ExitException('halt:' . $exitCode);
            }
        }
    }
}

namespace PressGang\Muster\Tests {
    use WP_CLI_ExitException;
    use PHPUnit\Framework\TestCase;
    use PressGang\Muster\Builders\PostBuilder;
    use PressGang\Muster\Cli\MusterCommand;
    use PressGang\Muster\Muster;
    use PressGang\Muster\MusterContext;
    use PressGang\Muster\Victuals\VictualsFactory;

    final class MusterCommandTest extends TestCase
    {
        protected function setUp(): void
        {
            reset_wordpress_stub_state();
        }

        public function testHandleRunsMusterClass(): void
        {
            $GLOBALS['__muster_cli_test_run_count'] = 0;

            MusterCommand::handle([TestMusterForCli::class], ['seed' => '1978']);

            self::assertSame(2, $GLOBALS['__muster_cli_test_run_count']);
            self::assertContains('Muster applied.', $GLOBALS['__muster_wp_cli_lines']);
        }

    public function testHandleReportsMissingClass(): void
    {
        $this->expectException(WP_CLI_ExitException::class);

        try {
            MusterCommand::handle(['Missing\\ClassName'], []);
        } finally {
            self::assertStringContainsString('Muster failed:', (string) end($GLOBALS['__muster_wp_cli_lines']));
        }
    }

    public function testHandleFailsWhenNoClassArgumentProvided(): void
    {
        $this->expectException(WP_CLI_ExitException::class);

        try {
            MusterCommand::handle([], []);
        } finally {
            self::assertSame('Muster class argument is required.', (string) ($GLOBALS['__muster_wp_cli_lines'][0] ?? ''));
        }
    }

        public function testOnlyFilterSkipsDeclarationGroupsNotInList(): void
        {
            $GLOBALS['__muster_cli_test_run_count'] = 0;
            $GLOBALS['__muster_cli_test_pattern_counter'] = 0;

            MusterCommand::handle([TestMusterForCli::class], ['only' => 'allowed']);

            self::assertSame(2, $GLOBALS['__muster_cli_test_run_count']);
            self::assertSame(2, $GLOBALS['__muster_cli_test_pattern_counter']);
        }

        public function testDryRunEmitsVisibleIntent(): void
        {
            $GLOBALS['__muster_cli_test_run_count'] = 0;
            MusterCommand::handle([TestMusterForCli::class], ['dry-run' => true]);

            self::assertContains('Planning pattern [allowed] for 1 iterations.', $GLOBALS['__muster_wp_cli_lines']);
            self::assertContains('Muster plan complete.', $GLOBALS['__muster_wp_cli_lines']);
            self::assertSame(1, $GLOBALS['__muster_cli_test_run_count']);
            self::assertCount(0, $GLOBALS['__muster_wp_posts']);
        }

        public function testQuietSuppressesSuccessfulHumanOutput(): void
        {
            MusterCommand::handle([TestMusterForCli::class], ['only' => 'allowed', 'quiet' => true]);

            self::assertSame([], $GLOBALS['__muster_wp_cli_lines']);
        }

        public function testVerboseEmitsBuilderAndOperationDetails(): void
        {
            MusterCommand::handle([TestMusterForCli::class], ['only' => 'allowed', 'verbose' => true]);

            self::assertNotEmpty(array_filter(
                $GLOBALS['__muster_wp_cli_lines'],
                static fn (string $line): bool => str_contains($line, 'Post create [event:allowed-1]')
            ));
            self::assertNotEmpty(array_filter(
                $GLOBALS['__muster_wp_cli_lines'],
                static fn (string $line): bool => str_contains($line, 'scope=') && str_contains($line, 'key=allowed-1')
            ));
        }

        public function testLongPatternsEmitBoundedProgress(): void
        {
            MusterCommand::handle([ProgressMusterForCli::class], ['dry-run' => true]);

            self::assertContains('Pattern [progress] progress: 12/12.', $GLOBALS['__muster_wp_cli_lines']);
            $progress = array_filter(
                $GLOBALS['__muster_wp_cli_lines'],
                static fn (string $line): bool => str_contains($line, 'Pattern [progress] progress:')
            );
            self::assertLessThanOrEqual(10, count($progress));
        }

        public function testJsonFormatEmitsOneStructuredPayload(): void
        {
            MusterCommand::handle([TestMusterForCli::class], ['only' => 'allowed', 'format' => 'json']);

            self::assertCount(1, $GLOBALS['__muster_wp_cli_lines']);
            $payload = json_decode($GLOBALS['__muster_wp_cli_lines'][0], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('applied', $payload['status']);
            self::assertSame(1, $payload['plan']['summary']['create']);
            self::assertSame(1, $payload['apply']['summary']['create']);
            self::assertSame('allowed', $payload['plan']['operations'][0]['group']);
        }

        public function testDryRunJsonContainsPlanOnly(): void
        {
            MusterCommand::handle([TestMusterForCli::class], [
                'only' => 'allowed',
                'dry-run' => true,
                'format' => 'json',
            ]);

            self::assertCount(1, $GLOBALS['__muster_wp_cli_lines']);
            $payload = json_decode($GLOBALS['__muster_wp_cli_lines'][0], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('planned', $payload['status']);
            self::assertNull($payload['apply']);
            self::assertCount(0, $GLOBALS['__muster_wp_posts']);
        }

        public function testEpochPinsRelativeDatesAcrossPlanAndApply(): void
        {
            MusterCommand::handle([TestMusterForCli::class], [
                'only' => 'allowed',
                'epoch' => '2026-01-01 09:00:00+00:00',
                'format' => 'json',
            ]);

            self::assertSame(
                '2026-01-08 09:00:00',
                reset($GLOBALS['__muster_wp_posts'])['post_date']
            );
        }

        public function testScenarioDefaultEpochIsUsedWhenFlagIsAbsent(): void
        {
            MusterCommand::handle([TestMusterForCli::class], [
                'only' => 'allowed',
                'format' => 'json',
            ]);

            self::assertSame(
                '2025-01-08 09:00:00',
                reset($GLOBALS['__muster_wp_posts'])['post_date']
            );
        }

        public function testInvalidEpochIsAStructuredConflict(): void
        {
            try {
                MusterCommand::handle([TestMusterForCli::class], [
                    'epoch' => '+1 week',
                    'format' => 'json',
                ]);
                self::fail('Expected conflict exit.');
            } catch (WP_CLI_ExitException $error) {
                self::assertSame('halt:1', $error->getMessage());
            }

            self::assertCount(1, $GLOBALS['__muster_wp_cli_lines']);
            $payload = json_decode($GLOBALS['__muster_wp_cli_lines'][0], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('conflict', $payload['status']);
            self::assertStringContainsString(
                'Fixture epoch must be an absolute date',
                $payload['plan']['operations'][0]['message']
            );
        }

        public function testUnknownOnlyGroupIsAStructuredConflict(): void
        {
            try {
                MusterCommand::handle([TestMusterForCli::class], [
                    'only' => 'missing',
                    'format' => 'json',
                ]);
                self::fail('Expected conflict exit.');
            } catch (WP_CLI_ExitException $error) {
                self::assertSame('halt:1', $error->getMessage());
            }

            self::assertCount(1, $GLOBALS['__muster_wp_cli_lines']);
            $payload = json_decode($GLOBALS['__muster_wp_cli_lines'][0], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('conflict', $payload['status']);
            self::assertSame(1, $payload['plan']['summary']['conflict']);
            self::assertStringContainsString(
                'Unknown Muster group requested by --only: missing.',
                $payload['plan']['operations'][0]['message']
            );
        }

        public function testJsonConflictStillEmitsOnlyStructuredPayload(): void
        {
            $context = new MusterContext(new VictualsFactory());
            (new PostBuilder($context, 'event'))->title('Editorial')->slug('allowed-1')->save();

            try {
                MusterCommand::handle([TestMusterForCli::class], ['only' => 'allowed', 'format' => 'json']);
                self::fail('Expected conflict exit.');
            } catch (WP_CLI_ExitException $error) {
                self::assertSame('halt:1', $error->getMessage());
            }

            self::assertCount(1, $GLOBALS['__muster_wp_cli_lines']);
            $payload = json_decode($GLOBALS['__muster_wp_cli_lines'][0], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('conflict', $payload['status']);
            self::assertSame(1, $payload['plan']['summary']['conflict']);
        }
    }

    final class TestMusterForCli extends Muster
    {
        public static function defaultEpoch(): string
        {
            return '2025-01-01 09:00:00+00:00';
        }

        public function run(): void
        {
            $GLOBALS['__muster_cli_test_run_count'] = (int) ($GLOBALS['__muster_cli_test_run_count'] ?? 0) + 1;

            $this->group('allowed', function (): void {
                $this->pattern('allowed')->count(1)->build(function (int $i) {
                    $GLOBALS['__muster_cli_test_pattern_counter'] = (int) ($GLOBALS['__muster_cli_test_pattern_counter'] ?? 0) + 1;

                    return $this->post('event')
                        ->key('allowed-' . $i)
                        ->title('A')
                        ->slug('allowed-' . $i)
                        ->date($this->at('+1 week')->format('Y-m-d H:i:s'));
                });
            });

            $this->group('blocked', function (): void {
                $this->pattern('blocked')->count(1)->build(function (int $i) {
                    $GLOBALS['__muster_cli_test_pattern_counter'] = (int) ($GLOBALS['__muster_cli_test_pattern_counter'] ?? 0) + 100;

                    return $this->post('event')->key('blocked-' . $i)->title('B')->slug('blocked-' . $i);
                });
            });
        }
    }

    final class ProgressMusterForCli extends Muster
    {
        public function run(): void
        {
            $this->pattern('progress')->count(12)->build(
                fn (int $i) => $this->post()->key('progress:' . $i)->slug('progress-' . $i)
            );
        }
    }
}
