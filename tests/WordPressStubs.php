<?php


namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(public string $message = 'error')
            {
            }
        }
    }

    if (!function_exists('get_posts')) {
        /**
         * @param array<string, mixed> $args
         * @return array<int, int>
         */
        function get_posts(array $args = []): array
        {
            $name = (string) ($args['name'] ?? '');
            $postType = (string) ($args['post_type'] ?? 'post');
            $key = $postType . '::' . $name;
            $row = $GLOBALS['__muster_wp_posts'][$key] ?? null;

            if ($row === null) {
                return [];
            }

            return [(int) $row['ID']];
        }
    }

    if (!function_exists('wp_insert_post')) {
        /**
         * @param array<string, mixed> $attrs
         * @param bool $wpError
         * @return int|WP_Error
         */
        function wp_insert_post(array $attrs, bool $wpError = false): int|WP_Error
        {
            unset($wpError);

            $id = (int) ($GLOBALS['__muster_wp_next_post_id'] ?? 1);
            $GLOBALS['__muster_wp_next_post_id'] = $id + 1;

            $row = [
                'ID' => $id,
                'post_type' => (string) ($attrs['post_type'] ?? 'post'),
                'post_name' => (string) ($attrs['post_name'] ?? ''),
                'post_title' => (string) ($attrs['post_title'] ?? ''),
                'post_content' => (string) ($attrs['post_content'] ?? ''),
                'post_status' => (string) ($attrs['post_status'] ?? 'draft'),
            ];

            $key = $row['post_type'] . '::' . $row['post_name'];
            $GLOBALS['__muster_wp_posts'][$key] = $row;

            return $id;
        }
    }

    if (!function_exists('wp_update_post')) {
        /**
         * @param array<string, mixed> $attrs
         * @param bool $wpError
         * @return int|WP_Error
         */
        function wp_update_post(array $attrs, bool $wpError = false): int|WP_Error
        {
            unset($wpError);

            $id = (int) ($attrs['ID'] ?? 0);
            if ($id < 1) {
                return new WP_Error('missing-id');
            }

            $postType = (string) ($attrs['post_type'] ?? 'post');
            $slug = (string) ($attrs['post_name'] ?? '');
            $key = $postType . '::' . $slug;

            $existing = $GLOBALS['__muster_wp_posts'][$key] ?? null;
            if ($existing === null) {
                return new WP_Error('missing-post');
            }

            $GLOBALS['__muster_wp_posts'][$key] = [
                'ID' => $id,
                'post_type' => $postType,
                'post_name' => $slug,
                'post_title' => (string) ($attrs['post_title'] ?? ''),
                'post_content' => (string) ($attrs['post_content'] ?? ''),
                'post_status' => (string) ($attrs['post_status'] ?? 'draft'),
            ];

            return $id;
        }
    }

    if (!function_exists('update_post_meta')) {
        /**
         * @param int $postId
         * @param string $key
         * @param mixed $value
         * @return bool
         */
        function update_post_meta(int $postId, string $key, mixed $value): bool
        {
            $GLOBALS['__muster_wp_meta'][$postId][$key] = $value;

            return true;
        }
    }

    if (!function_exists('is_wp_error')) {
        /**
         * @param mixed $thing
         * @return bool
         */
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof WP_Error;
        }
    }

    if (!function_exists('sanitize_title')) {
        /**
         * @param string $title
         * @return string
         */
        function sanitize_title(string $title): string
        {
            $slug = strtolower($title);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

            return trim($slug, '-');
        }
    }

    if (!function_exists('post_type_exists')) {
        /**
         * @param string $postType
         * @return bool
         */
        function post_type_exists(string $postType): bool
        {
            $allowed = $GLOBALS['__muster_registered_post_types'] ?? ['post', 'page'];

            return in_array($postType, $allowed, true);
        }
    }
}

namespace PressGang\Muster\Tests {
    /**
     * @return void
     */
    function reset_wordpress_stub_state(): void
    {
        $GLOBALS['__muster_wp_posts'] = [];
        $GLOBALS['__muster_wp_meta'] = [];
        $GLOBALS['__muster_wp_next_post_id'] = 1;
        $GLOBALS['__muster_registered_post_types'] = ['post', 'page', 'event'];
    }
}
