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
            'fields' => [['key' => 'field_venue', 'name' => 'venue', 'type' => 'text']],
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

    public function testValuesForUnknownTargetIsEmpty(): void
    {
        self::assertSame([], ThemeAcf::valuesFor('recipe', $this->generator(), 'populated', $this->acfJsonDir));
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
    }
}
