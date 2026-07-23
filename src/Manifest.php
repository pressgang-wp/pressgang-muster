<?php

namespace PressGang\Muster;

/**
 * Interprets a declarative seeding manifest — a plain array describing the
 * common whole-surface case ("N terms per taxonomy, N of each post type, a page
 * per template, a menu per location") — into the fluent builders.
 *
 * The manifest is the terse default; a class-based Muster remains the escape
 * hatch for anything the array cannot express. Each section runs in its own
 * named declaration group, so `--only` selects it. Everything it produces goes
 * through the same primitives a hand-written seeder would use — patterns
 * (self-keyed rows), `withThumbnail()`, and `content()` — so determinism,
 * ownership, and plan/apply are identical. See ADR 0006.
 *
 * Shape:
 *
 *     [
 *         'terms' => [ 'hit_group' => 3 ],                    // taxonomy => count
 *         'posts' => [
 *             'hit'  => ['count' => 5, 'thumbnail' => true, 'terms' => ['hit_group' => 'rotate']],
 *             'post' => ['count' => 5, 'thumbnail' => true],
 *             'page' => ['count' => 1, 'fill' => ['post_name' => 'about', 'post_title' => 'About']],
 *         ],
 *         'pages' => 'templates',    // one page per registered page template
 *         'menus' => 'locations',    // a menu per registered nav location
 *     ]
 *
 * A post spec's optional `fill` block carries explicit WP-native values (see
 * {@see \PressGang\Muster\Builders\PostBuilder::fill()}) applied to every
 * generated row — the manifest's way to declare concrete field values, not just
 * generated ones (ADR 0010).
 */
final class Manifest
{
    /**
     * @param Muster $muster
     */
    public function __construct(private Muster $muster)
    {
    }

    /**
     * Seed everything the manifest declares, in dependency order (terms before
     * the posts that tag them).
     *
     * @param array<string, mixed> $manifest
     * @return void
     */
    public function assemble(array $manifest): void
    {
        $terms = (array) ($manifest['terms'] ?? []);

        $this->terms($terms);
        $this->posts((array) ($manifest['posts'] ?? []), $terms);
        $this->pages($manifest['pages'] ?? null);
        $this->menus($manifest['menus'] ?? null);
    }

    /**
     * `taxonomy => count` — that many terms per taxonomy.
     *
     * @param array<string, int> $terms
     * @return void
     */
    private function terms(array $terms): void
    {
        foreach ($terms as $taxonomy => $count) {
            $this->muster->group('terms:' . $taxonomy, function () use ($taxonomy, $count): void {
                for ($i = 1; $i <= (int) $count; $i++) {
                    $this->muster->term($taxonomy)
                        ->key('term:' . $taxonomy . ':' . $i)
                        ->name(ucwords(str_replace(['_', '-'], ' ', $taxonomy)) . ' ' . $i)
                        ->slug($taxonomy . '-' . $i)
                        ->save();
                }
            });
        }
    }

    /**
     * `postType => spec` — `count` populated posts of the type, optionally with
     * a thumbnail, rotating taxonomy terms, and a `fill` block of explicit
     * WP-native values applied to every row (last, so it wins over generated
     * content; row slugs stay self-keyed unless `fill` sets `post_name`).
     *
     * @param array<string, array<string, mixed>> $posts
     * @param array<string, int> $terms Term counts, for `rotate` resolution.
     * @return void
     */
    private function posts(array $posts, array $terms): void
    {
        foreach ($posts as $type => $spec) {
            $count = (int) ($spec['count'] ?? 1);
            $tags = (array) ($spec['terms'] ?? []);

            $this->muster->group('posts:' . $type, function () use ($type, $spec, $count, $tags, $terms): void {
                $pattern = $this->muster->pattern($type)->count($count);

                if (! empty($spec['thumbnail'])) {
                    $pattern->withThumbnail();
                }

                $fill = (array) ($spec['fill'] ?? []);

                $pattern->build(function (int $i) use ($type, $tags, $terms, $fill) {
                    $post = $this->muster->content($type)->slug($type . '-' . $i);

                    foreach ($tags as $taxonomy => $mode) {
                        $slugs = $this->resolveTerms((string) $taxonomy, $mode, $i, $terms);
                        if ($slugs !== []) {
                            $post->terms((string) $taxonomy, $slugs);
                        }
                    }

                    // Explicit values are applied last so they win over the
                    // generated content and the self-keyed slug (ADR 0010).
                    if ($fill !== []) {
                        $post->fill($fill);
                    }

                    return $post;
                });
            });
        }
    }

    /**
     * Resolve a post's taxonomy terms: `rotate` spreads rows across the seeded
     * terms of that taxonomy; an explicit array of slugs is used verbatim.
     *
     * @param string $taxonomy
     * @param mixed $mode `rotate` or an array of term slugs.
     * @param int $i One-based row index.
     * @param array<string, int> $terms Seeded term counts.
     * @return array<int, string>
     */
    private function resolveTerms(string $taxonomy, mixed $mode, int $i, array $terms): array
    {
        if (is_array($mode)) {
            return array_values(array_map('strval', $mode));
        }

        if ($mode === 'rotate') {
            $n = (int) ($terms[$taxonomy] ?? 0);
            if ($n < 1) {
                return [];
            }

            return [$taxonomy . '-' . ((($i - 1) % $n) + 1)];
        }

        return [];
    }

    /**
     * `'templates'` — one page per registered page template, each pre-filled and
     * carrying the template's own ACF values.
     *
     * @param mixed $mode
     * @return void
     */
    private function pages(mixed $mode): void
    {
        if ($mode !== 'templates' || ! function_exists('wp_get_theme')) {
            return;
        }

        $templates = array_keys(wp_get_theme()->get_page_templates());
        if ($templates === []) {
            return;
        }

        $this->muster->group('pages', function () use ($templates): void {
            foreach ($templates as $template) {
                $slug = strtolower((string) preg_replace('/\.php$/', '', basename((string) $template)));

                $page = $this->muster->page()
                    ->key('page:' . $slug)
                    ->title(ucwords(str_replace('-', ' ', $slug)))
                    ->slug($slug)
                    ->template((string) $template)
                    ->content($this->muster->victuals()->paragraphs(2));

                $acf = $this->muster->acfFor((string) $template);
                if ($acf !== []) {
                    $page->acf($acf);
                }

                $page->save();
            }
        });
    }

    /**
     * `'locations'` — a menu with a Home link per registered nav location,
     * assigned to that location.
     *
     * @param mixed $mode
     * @return void
     */
    private function menus(mixed $mode): void
    {
        if ($mode !== 'locations' || ! function_exists('get_registered_nav_menus')) {
            return;
        }

        foreach (array_keys(get_registered_nav_menus()) as $location) {
            $this->muster->group('menus:' . $location, function () use ($location): void {
                $this->muster->menu('Fixture ' . ucwords(str_replace(['_', '-'], ' ', (string) $location)))
                    ->key('menu:' . $location)
                    ->link('Home', '/')
                    ->location((string) $location)
                    ->save();
            });
        }
    }
}
