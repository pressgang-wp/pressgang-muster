<?php

namespace PressGang\Muster\Tests;

use PHPUnit\Framework\TestCase;
use PressGang\Muster\Muster;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Victuals\VictualsFactory;

final class ManifestTest extends TestCase
{
    protected function setUp(): void
    {
        reset_wordpress_stub_state();
    }

    public function testManifestSeedsDeclaredTermsAndPosts(): void
    {
        $muster = $this->manifestMuster();

        $muster->assemble([
            'terms' => ['hit_group' => 3],
            'posts' => [
                'hit' => ['count' => 5, 'terms' => ['hit_group' => 'rotate']],
            ],
        ]);

        self::assertCount(3, $GLOBALS['__muster_wp_terms']);

        $hits = array_filter(
            $GLOBALS['__muster_wp_posts'],
            static fn (array $p): bool => ($p['post_type'] ?? '') === 'hit'
        );
        self::assertCount(5, $hits);
    }

    public function testManifestThumbnailFlagFeaturesEveryPost(): void
    {
        $muster = $this->manifestMuster();

        $muster->assemble([
            'posts' => [
                'hit' => ['count' => 2, 'thumbnail' => true],
            ],
        ]);

        // Each of the two rows received a placeholder featured image.
        self::assertCount(2, $GLOBALS['__muster_wp_thumbnails']);
    }

    public function testManifestRunsAreDeterministic(): void
    {
        $first = $this->manifestMuster();
        $first->assemble(['posts' => ['hit' => ['count' => 2]]]);
        $titlesA = $this->postTitles();

        reset_wordpress_stub_state();

        $second = $this->manifestMuster();
        $second->assemble(['posts' => ['hit' => ['count' => 2]]]);

        self::assertSame($titlesA, $this->postTitles());
    }

    /**
     * @return array<int, string>
     */
    private function postTitles(): array
    {
        return array_values(array_map(
            static fn (array $p): string => (string) ($p['post_title'] ?? ''),
            $GLOBALS['__muster_wp_posts']
        ));
    }

    public function testManifestFillAppliesExplicitValuesToEveryRow(): void
    {
        $muster = $this->manifestMuster();

        $muster->assemble([
            'posts' => [
                'hit' => ['count' => 2, 'fill' => [
                    'post_status' => 'draft',
                    'meta_input'  => ['campaign' => 'launch'],
                ]],
            ],
        ]);

        $hits = array_filter(
            $GLOBALS['__muster_wp_posts'],
            static fn (array $p): bool => ($p['post_type'] ?? '') === 'hit'
        );
        self::assertCount(2, $hits);

        // Explicit values reach every generated row; slugs stay self-keyed.
        foreach ($hits as $hit) {
            self::assertSame('draft', $hit['post_status']);
        }
        self::assertSame('launch', $GLOBALS['__muster_wp_meta'][1]['campaign']);
        self::assertSame('launch', $GLOBALS['__muster_wp_meta'][2]['campaign']);
    }

    private function manifestMuster(): Muster
    {
        return new class(new MusterContext(new VictualsFactory(), seed: 42)) extends Muster {
            public function run(): void
            {
            }
        };
    }
}
