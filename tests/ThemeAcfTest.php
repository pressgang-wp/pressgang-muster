<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Acf\AcfValueGenerator;
use PressGang\Muster\Acf\ThemeAcf;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class ThemeAcfTest extends TestCase
{
    private string $acfJsonDir;

    protected function setUp(): void
    {
        reset_wordpress_stub_state();

        // A fake theme with two location-targeted groups and one options group.
        $theme = $GLOBALS['__muster_stylesheet_dir'];
        $this->acfJsonDir = $theme . '/acf-json';
        @mkdir($this->acfJsonDir, 0755, true);

        file_put_contents($this->acfJsonDir . '/group_event.json', json_encode([
            'key' => 'group_event',
            'title' => 'Event',
            'fields' => [
                ['key' => 'field_venue', 'name' => 'venue', 'type' => 'text'],
                ['key' => 'field_hero', 'name' => 'hero', 'type' => 'image'],
            ],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]],
        ]));

        file_put_contents($this->acfJsonDir . '/group_contact.json', json_encode([
            'key' => 'group_contact',
            'title' => 'Contact',
            'fields' => [['key' => 'field_map', 'name' => 'map', 'type' => 'text']],
            'location' => [[['param' => 'page_template', 'operator' => '==', 'value' => 'page-templates/contact.php']]],
        ]));

        file_put_contents($this->acfJsonDir . '/group_options.json', json_encode([
            'key' => 'group_options',
            'title' => 'Site Options',
            'fields' => [['key' => 'field_footer', 'name' => 'footer', 'type' => 'text']],
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'site-options']]],
        ]));

        // Groups on the three location params that were previously unseedable:
        // the front page, a post template, and a nav-menu-item location.
        file_put_contents($this->acfJsonDir . '/group_hero_home.json', json_encode([
            'key' => 'group_hero_home',
            'title' => 'Hero Home',
            'fields' => [['key' => 'field_hero_title', 'name' => 'hero_title', 'type' => 'text']],
            'location' => [[['param' => 'page_type', 'operator' => '==', 'value' => 'front_page']]],
        ]));

        file_put_contents($this->acfJsonDir . '/group_cta.json', json_encode([
            'key' => 'group_cta',
            'title' => 'CTA',
            'fields' => [['key' => 'field_cta_text', 'name' => 'cta_text', 'type' => 'text']],
            'location' => [[['param' => 'post_template', 'operator' => '==', 'value' => 'page-templates/contact.php']]],
        ]));

        file_put_contents($this->acfJsonDir . '/group_menu.json', json_encode([
            'key' => 'group_menu',
            'title' => 'Menu Item Columns',
            'fields' => [['key' => 'field_columns', 'name' => 'columns', 'type' => 'text']],
            'location' => [[['param' => 'nav_menu_item', 'operator' => '==', 'value' => 'location/primary']]],
        ]));
    }

    private function generator(): AcfValueGenerator
    {
        return new AcfValueGenerator((new VictualsFactory())->make(42));
    }

    public function testValuesForMatchesPostTypeTarget(): void
    {
        $values = ThemeAcf::valuesFor('event', $this->generator(), 'populated', $this->acfJsonDir);

        self::assertArrayHasKey('field_venue', $values);
        self::assertArrayNotHasKey('field_map', $values);
        self::assertArrayNotHasKey('field_footer', $values);
    }

    public function testValuesForMatchesPageTemplateTarget(): void
    {
        $values = ThemeAcf::valuesFor('page-templates/contact.php', $this->generator(), 'populated', $this->acfJsonDir);

        self::assertArrayHasKey('field_map', $values);
        self::assertArrayNotHasKey('field_venue', $values);
    }

    public function testValuesForMatchesOptionsPageTarget(): void
    {
        $values = ThemeAcf::valuesFor('site-options', $this->generator(), 'populated', $this->acfJsonDir);

        self::assertArrayHasKey('field_footer', $values);
        self::assertArrayNotHasKey('field_venue', $values);
    }

    public function testValuesForMatchesFrontPageTarget(): void
    {
        $values = ThemeAcf::valuesFor('front_page', $this->generator(), 'populated', $this->acfJsonDir);

        self::assertArrayHasKey('field_hero_title', $values);
    }

    public function testValuesForMatchesPostTemplateTarget(): void
    {
        $values = ThemeAcf::valuesFor('page-templates/contact.php', $this->generator(), 'populated', $this->acfJsonDir);

        // Both the page_template group and the post_template group live at this
        // path, so both sets of fields resolve for the one target string.
        self::assertArrayHasKey('field_cta_text', $values);
        self::assertArrayHasKey('field_map', $values);
    }

    public function testValuesForMatchesNavMenuItemTarget(): void
    {
        $values = ThemeAcf::valuesFor('location/primary', $this->generator(), 'populated', $this->acfJsonDir);

        self::assertArrayHasKey('field_columns', $values);
    }

    public function testValuesForUnknownTargetIsEmpty(): void
    {
        self::assertSame([], ThemeAcf::valuesFor('recipe', $this->generator(), 'populated', $this->acfJsonDir));
    }

    public function testFieldNamesForReturnsTopLevelNamesOfTargetedGroups(): void
    {
        $names = ThemeAcf::fieldNamesFor('event', $this->acfJsonDir);

        self::assertContains('venue', $names);
        self::assertContains('hero', $names);
        self::assertNotContains('map', $names);
        self::assertNotContains('footer', $names);
    }

    public function testFieldNamesForUnknownTargetIsEmpty(): void
    {
        self::assertSame([], ThemeAcf::fieldNamesFor('recipe', $this->acfJsonDir));
    }

    public function testMetaWriteToAnAcfFieldNameIsRejected(): void
    {
        $muster = $this->contentMuster();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('venue');

        // `venue` is an ACF field on the event group — a raw meta() write to it
        // would land where ACF can't read it, so save() must refuse.
        $muster->post('event')->key('event:one')->slug('an-event')->meta(['venue' => 'Town Hall'])->save();
    }

    public function testMetaWriteToANonAcfKeyIsAllowed(): void
    {
        $muster = $this->contentMuster();

        // `capacity` is not an ACF field for the event group, so it is genuine
        // raw meta and passes through untouched.
        $ref = $muster->post('event')->key('event:one')->slug('an-event')->meta(['capacity' => 250])->save();

        self::assertSame(250, $GLOBALS['__muster_wp_meta'][$ref->id()]['capacity']);
    }

    public function testAcfWriteToAnAcfFieldIsUnaffectedByTheGuard(): void
    {
        $muster = $this->contentMuster();

        // The guard only inspects meta(); the correct acf() path is never blocked.
        $ref = $muster->post('event')->key('event:one')->slug('an-event')->acf(['field_venue' => 'Town Hall'])->save();

        self::assertSame('an-event', $ref->slug());
    }

    public function testMusterAcfForResolvesThemeDirAndProviders(): void
    {
        // acfFor() reads the acf-json dir from the (stubbed) active theme and
        // wires providers, so relational/media generation works end to end.
        $muster = new class(new MusterContext(new VictualsFactory(), seed: 42)) extends Muster {
            public function run(): void
            {
            }
        };

        $values = $muster->acfFor('event');

        self::assertArrayHasKey('field_venue', $values);
        self::assertIsString($values['field_venue']);
        self::assertArrayHasKey('field_hero', $values);
        self::assertSame(1, $values['field_hero']);
        self::assertSame(1, $muster->resetOwned());
        self::assertSame([], get_posts(['name' => 'seed-hero', 'post_type' => 'attachment']));
    }

    public function testContentPreFillsAGeneratedTitleAndBody(): void
    {
        $muster = $this->contentMuster();

        $muster->content('event')->key('event:one')->slug('an-event')->save();

        $stored = $GLOBALS['__muster_wp_posts']['event::an-event'];
        self::assertNotSame('', (string) ($stored['post_title'] ?? ''));
        self::assertNotSame('', (string) ($stored['post_content'] ?? ''));
    }

    public function testContentAppliesAcfDefaultsForTheType(): void
    {
        $muster = $this->contentMuster();

        $muster->content('event')->key('event:one')->slug('an-event')->save();

        // The event group's image field means acfFor() created a placeholder
        // attachment — evidence the ACF defaults were generated and applied.
        self::assertNotSame([], get_posts(['name' => 'seed-hero', 'post_type' => 'attachment']));
    }

    public function testContentDefaultsAreOverridable(): void
    {
        $muster = $this->contentMuster();

        $muster->content('event')->key('event:one')->slug('an-event')->title('A specific title')->save();

        self::assertSame('A specific title', $GLOBALS['__muster_wp_posts']['event::an-event']['post_title']);
    }

    public function testAcfSupportIsReusedAcrossSeparateMusterScopes(): void
    {
        // Two separate musters (a site seed, then a test setup against the same
        // database) both generate the shared placeholder for the same field. The
        // second must reuse it, not conflict on ownership.
        $context = new MusterContext(new VictualsFactory(), seed: 42);

        $seed = new class($context) extends Muster {
            public function run(): void
            {
            }
        };
        $setup = new class($context) extends Muster {
            public function run(): void
            {
            }
        };

        $seed->acfFor('event');
        $setup->acfFor('event');

        self::assertCount(
            1,
            get_posts(['name' => 'seed-hero', 'post_type' => 'attachment', 'post_status' => 'any'])
        );
    }

    private function contentMuster(): Muster
    {
        return new class(new MusterContext(new VictualsFactory(), seed: 42)) extends Muster {
            public function run(): void
            {
            }
        };
    }
}
