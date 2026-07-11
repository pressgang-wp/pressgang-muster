<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Acf\AcfJson;
use PressGang\Muster\Acf\AcfValueGenerator;
use PressGang\Muster\Victuals\VictualsFactory;

final class AcfValueGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['key' => 'field_title', 'name' => 'title', 'type' => 'text', 'required' => 1],
            ['key' => 'field_intro', 'name' => 'intro', 'type' => 'textarea'],
            ['key' => 'field_count', 'name' => 'count', 'type' => 'number', 'min' => 2, 'max' => 8],
            ['key' => 'field_flag', 'name' => 'flag', 'type' => 'true_false'],
            ['key' => 'field_style', 'name' => 'style', 'type' => 'select', 'choices' => ['light' => 'Light', 'dark' => 'Dark']],
            ['key' => 'field_cta', 'name' => 'cta', 'type' => 'link'],
            ['key' => 'field_hero', 'name' => 'hero', 'type' => 'image'],
            [
                'key' => 'field_slides',
                'name' => 'slides',
                'type' => 'repeater',
                'sub_fields' => [
                    ['key' => 'field_slide_heading', 'name' => 'heading', 'type' => 'text'],
                    ['key' => 'field_slide_image', 'name' => 'image', 'type' => 'image'],
                ],
            ],
        ];
    }

    public function testPopulatedGeneratesPerTypeAndSkipsProviderlessMedia(): void
    {
        $generator = new AcfValueGenerator((new VictualsFactory())->make(42));
        $values = $generator->populated($this->fields());

        self::assertIsString($values['field_title']);
        self::assertNotEmpty($values['field_title']);
        self::assertSame(5, $values['field_count']);
        self::assertSame(1, $values['field_flag']);
        self::assertSame('light', $values['field_style']);
        self::assertSame('/', $values['field_cta']['url']);
        self::assertSame(false, array_key_exists('field_hero', $values));

        self::assertCount(2, $values['field_slides']);
        self::assertIsString($values['field_slides'][0]['heading']);
        self::assertSame(false, array_key_exists('image', $values['field_slides'][0]));
    }

    public function testProvidersSupplyMediaAndRelationalIds(): void
    {
        $generator = new AcfValueGenerator((new VictualsFactory())->make(42), [
            'attachment' => fn (string $name): int => 77,
            'post' => fn (array $types): int => 88,
        ]);

        $values = $generator->populated([
            ['key' => 'field_hero', 'name' => 'hero', 'type' => 'image'],
            ['key' => 'field_related', 'name' => 'related', 'type' => 'relationship', 'post_type' => ['event']],
        ]);

        self::assertSame(77, $values['field_hero']);
        self::assertSame([88], $values['field_related']);
    }

    public function testMinimalIncludesOnlyRequiredFields(): void
    {
        $generator = new AcfValueGenerator((new VictualsFactory())->make(42));
        $values = $generator->minimal($this->fields());

        self::assertCount(1, $values);
        self::assertIsString($values['field_title']);
    }

    public function testSameSeedProducesIdenticalValues(): void
    {
        $factory = new VictualsFactory();
        $first = (new AcfValueGenerator($factory->make(1234)))->populated($this->fields());
        $second = (new AcfValueGenerator($factory->make(1234)))->populated($this->fields());

        self::assertSame($first, $second);
    }

    public function testFlexibleContentGeneratesOneRowPerLayout(): void
    {
        $generator = new AcfValueGenerator((new VictualsFactory())->make(42));
        $values = $generator->populated([
            [
                'key' => 'field_blocks',
                'name' => 'blocks',
                'type' => 'flexible_content',
                'layouts' => [
                    ['name' => 'quote', 'sub_fields' => [['name' => 'text', 'type' => 'textarea']]],
                    ['name' => 'stats', 'sub_fields' => [['name' => 'value', 'type' => 'number']]],
                ],
            ],
        ]);

        self::assertCount(2, $values['field_blocks']);
        self::assertSame('quote', $values['field_blocks'][0]['acf_fc_layout']);
        self::assertSame('stats', $values['field_blocks'][1]['acf_fc_layout']);
    }

    public function testAcfJsonGroupsAndTargets(): void
    {
        $dir = sys_get_temp_dir() . '/muster-acf-json-' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/group_a.json', json_encode([
            'key' => 'group_a',
            'title' => 'Hero',
            'fields' => [['key' => 'field_x', 'name' => 'x', 'type' => 'text']],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'event']],
                [['param' => 'page_template', 'operator' => '==', 'value' => 'page-templates/contact-page.php']],
                [['param' => 'post_status', 'operator' => '==', 'value' => 'publish']],
            ],
        ]));
        file_put_contents($dir . '/not-a-group.json', json_encode(['foo' => 'bar']));

        $groups = AcfJson::groups($dir);

        self::assertCount(1, $groups);
        self::assertSame('group_a', $groups[0]['key']);

        self::assertSame([
            ['param' => 'post_type', 'value' => 'event'],
            ['param' => 'page_template', 'value' => 'page-templates/contact-page.php'],
        ], AcfJson::targets($groups[0]));
    }
}
