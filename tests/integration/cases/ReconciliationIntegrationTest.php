<?php

namespace PressGang\Muster\IntegrationTests;

use PressGang\Muster\Clock\FixtureClock;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

/**
 * Real WordPress scenario covering each core persistence family.
 */
final class CoreResourceScenario extends Muster
{
    public static function defaultEpoch(): string
    {
        return '2026-01-01 09:00:00+00:00';
    }

    public function run(): void
    {
        $this->group('core', function (): void {
            $this->term('muster_type')
                ->key('term:type:talk')
                ->name('Talk')
                ->slug('talk')
                ->description('A seeded event type.')
                ->save();

            $this->user('muster_editor')
                ->key('user:editor')
                ->password('integration-password')
                ->email('editor@muster.test')
                ->displayName('Muster Editor')
                ->role('editor')
                ->save();

            $this->post('muster_event')
                ->key('event:launch')
                ->title('Launch event')
                ->slug('launch-event')
                ->status('publish')
                ->date($this->at('+1 week')->format('Y-m-d H:i:s'))
                ->meta(['muster_code' => 'launch'])
                ->save();

            $this->option('muster_fixture')
                ->key('option:fixture')
                ->value(['ready' => true])
                ->autoload(false)
                ->save();
        });
    }
}

/**
 * Scenario whose second declaration can become stale between runs.
 */
final class PrunableScenario extends Muster
{
    public function __construct(MusterContext $context, private bool $includeExtra)
    {
        parent::__construct($context);
    }

    public function run(): void
    {
        $this->group('pages', function (): void {
            $this->page()->key('page:kept')->title('Kept')->slug('kept')->save();

            if ($this->includeExtra) {
                $this->page()->key('page:stale')->title('Stale')->slug('stale')->save();
            }
        });

        $this->pruneOwned();
    }
}

/**
 * Verifies Muster against real WordPress core APIs and a real test database.
 */
final class ReconciliationIntegrationTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option('pressgang_muster_registry');
        register_post_type('muster_event', ['public' => false]);
        register_taxonomy('muster_type', ['muster_event'], ['public' => false]);
    }

    public function tear_down(): void
    {
        unregister_taxonomy('muster_type');
        unregister_post_type('muster_event');

        parent::tear_down();
    }

    public function test_core_resources_are_idempotent_and_merge_safe(): void
    {
        $this->runCoreScenario();

        $post = get_page_by_path('launch-event', OBJECT, 'muster_event');
        $term = get_term_by('slug', 'talk', 'muster_type');
        $user = get_user_by('login', 'muster_editor');

        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertInstanceOf(\WP_Term::class, $term);
        self::assertInstanceOf(\WP_User::class, $user);

        $postId = $post->ID;
        $termId = $term->term_id;
        $userId = $user->ID;

        wp_update_post(['ID' => $postId, 'post_content' => 'Editorial content']);
        $this->runCoreScenario();

        $post = get_page_by_path('launch-event', OBJECT, 'muster_event');
        $term = get_term_by('slug', 'talk', 'muster_type');
        $user = get_user_by('login', 'muster_editor');

        self::assertSame($postId, $post->ID);
        self::assertSame($termId, $term->term_id);
        self::assertSame($userId, $user->ID);
        self::assertSame('Editorial content', $post->post_content);
        self::assertSame('2026-01-08 09:00:00', $post->post_date);
        self::assertSame('launch', get_post_meta($postId, 'muster_code', true));
        self::assertSame(['ready' => true], get_option('muster_fixture'));
        self::assertNotFalse(get_option('pressgang_muster_registry', false));
    }

    public function test_planning_reads_wordpress_without_persisting(): void
    {
        $context = $this->context(true);
        (new CoreResourceScenario($context))->run();

        self::assertNull(get_page_by_path('launch-event', OBJECT, 'muster_event'));
        self::assertFalse(get_term_by('slug', 'talk', 'muster_type'));
        self::assertFalse(get_user_by('login', 'muster_editor'));
        self::assertFalse(get_option('muster_fixture', false));
        self::assertFalse(get_option('pressgang_muster_registry', false));
        self::assertSame(4, $context->report()->summary()['create']);
    }

    public function test_prune_removes_only_stale_owned_resources(): void
    {
        (new PrunableScenario($this->context(false), true))->run();
        $editorPage = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'editor-page',
            'post_title' => 'Editor page',
        ]);

        (new PrunableScenario($this->context(false), false))->run();

        self::assertNotNull(get_page_by_path('kept', OBJECT, 'page'));
        self::assertNull(get_page_by_path('stale', OBJECT, 'page'));
        self::assertInstanceOf(\WP_Post::class, get_post($editorPage));
    }

    private function runCoreScenario(): void
    {
        (new CoreResourceScenario($this->context(false)))->run();
    }

    private function context(bool $dryRun): MusterContext
    {
        return new MusterContext(
            new VictualsFactory(),
            seed: 1978,
            dryRun: $dryRun,
            clock: new FixtureClock('2026-01-01 09:00:00+00:00')
        );
    }
}
