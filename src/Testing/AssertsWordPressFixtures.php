<?php

namespace PressGang\Muster\Testing;

use PHPUnit\Framework\Assert;

/**
 * Focused PHPUnit assertions for WordPress resources created by Muster.
 *
 * The helpers query through public WordPress APIs and return the resolved core
 * object so integration tests can make additional domain-specific assertions.
 */
trait AssertsWordPressFixtures
{
    protected function assertPostExists(string $slug, string $postType = 'post'): object
    {
        $this->requireWpFunction('get_posts');
        $ids = get_posts([
            'name' => $slug,
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        Assert::assertNotEmpty($ids, sprintf('Failed asserting that %s [%s] exists.', $postType, $slug));
        $post = get_post((int) $ids[0]);
        Assert::assertIsObject($post);

        return $post;
    }

    protected function assertTermExists(string $taxonomy, string $slug): object
    {
        $this->requireWpFunction('get_term_by');
        $term = get_term_by('slug', $slug, $taxonomy);
        Assert::assertIsObject($term, sprintf('Failed asserting that term [%s:%s] exists.', $taxonomy, $slug));

        return $term;
    }

    protected function assertUserExists(string $login): object
    {
        $this->requireWpFunction('get_user_by');
        $user = get_user_by('login', $login);
        Assert::assertIsObject($user, sprintf('Failed asserting that user [%s] exists.', $login));

        return $user;
    }

    protected function assertOptionEquals(string $name, mixed $expected): void
    {
        $this->requireWpFunction('get_option');
        $missing = new \stdClass();
        $actual = get_option($name, $missing);
        Assert::assertNotSame($missing, $actual, sprintf('Failed asserting that option [%s] exists.', $name));
        Assert::assertSame($expected, $actual, sprintf('Option [%s] does not match the expected fixture value.', $name));
    }

    protected function assertCommentExists(int $postId, string $content): object
    {
        $this->requireWpFunction('get_comments');
        $comments = get_comments(['post_id' => $postId, 'status' => 'all', 'number' => 0]);

        foreach ($comments as $comment) {
            if ((string) ($comment->comment_content ?? '') === $content) {
                return $comment;
            }
        }

        Assert::fail(sprintf('Failed asserting that post ID %d has comment [%s].', $postId, $content));
    }

    /**
     * Fail fast with a uniform message when a WordPress lookup is unavailable.
     *
     * @param string $function WordPress function the assertion depends on.
     * @return void
     */
    private function requireWpFunction(string $function): void
    {
        Assert::assertTrue(
            function_exists($function),
            sprintf('%s() must be available for WordPress fixture assertions.', $function)
        );
    }
}
