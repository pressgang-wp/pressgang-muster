<?php

namespace {
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
        }
    }
}

namespace PressGang\Muster\Tests {
    use PHPUnit\Framework\TestCase;
    use PressGang\Muster\Cli\MusterCommand;
    use PressGang\Muster\Muster;

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

            self::assertSame(1, $GLOBALS['__muster_cli_test_run_count']);
            self::assertSame('Muster completed.', $GLOBALS['__muster_wp_cli_lines'][0] ?? '');
        }

        public function testHandleReportsMissingClass(): void
        {
            MusterCommand::handle(['Missing\\ClassName'], []);

            self::assertStringContainsString('Muster failed:', (string) ($GLOBALS['__muster_wp_cli_lines'][0] ?? ''));
        }

        public function testOnlyFilterSkipsPatternsNotInList(): void
        {
            $GLOBALS['__muster_cli_test_run_count'] = 0;
            $GLOBALS['__muster_cli_test_pattern_counter'] = 0;

            MusterCommand::handle([TestMusterForCli::class], ['only' => 'allowed']);

            self::assertSame(1, $GLOBALS['__muster_cli_test_run_count']);
            self::assertSame(1, $GLOBALS['__muster_cli_test_pattern_counter']);
        }
    }

    final class TestMusterForCli extends Muster
    {
        public function run(): void
        {
            $GLOBALS['__muster_cli_test_run_count'] = (int) ($GLOBALS['__muster_cli_test_run_count'] ?? 0) + 1;

            $this->pattern('allowed')->count(1)->build(function (int $i) {
                $GLOBALS['__muster_cli_test_pattern_counter'] = (int) ($GLOBALS['__muster_cli_test_pattern_counter'] ?? 0) + 1;

                return $this->post('event')->title('A')->slug('allowed-' . $i);
            });

            $this->pattern('blocked')->count(1)->build(function (int $i) {
                $GLOBALS['__muster_cli_test_pattern_counter'] = (int) ($GLOBALS['__muster_cli_test_pattern_counter'] ?? 0) + 100;

                return $this->post('event')->title('B')->slug('blocked-' . $i);
            });
        }
    }
}
