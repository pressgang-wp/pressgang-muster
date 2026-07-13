<?php

namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(public string $message = 'error')
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
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

            if ($name === '') {
                $ids = [];
                foreach ($GLOBALS['__muster_wp_posts'] ?? [] as $row) {
                    if (($row['post_type'] ?? 'post') === $postType) {
                        $ids[] = (int) $row['ID'];
                    }
                }

                return $ids;
            }

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
                'post_excerpt' => (string) ($attrs['post_excerpt'] ?? ''),
                'post_status' => (string) ($attrs['post_status'] ?? 'draft'),
                'post_date' => (string) ($attrs['post_date'] ?? ''),
                'post_parent' => (int) ($attrs['post_parent'] ?? 0),
                'post_author' => (int) ($attrs['post_author'] ?? 0),
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

            $existing = null;
            $oldKey = null;
            foreach ($GLOBALS['__muster_wp_posts'] ?? [] as $key => $row) {
                if ((int) ($row['ID'] ?? 0) === $id) {
                    $existing = $row;
                    $oldKey = $key;
                    break;
                }
            }

            if ($existing === null) {
                return new WP_Error('missing-post');
            }

            $postType = (string) ($attrs['post_type'] ?? $existing['post_type'] ?? 'post');
            $slug = (string) ($attrs['post_name'] ?? $existing['post_name'] ?? '');
            $newKey = $postType . '::' . $slug;

            if ($oldKey !== null) {
                unset($GLOBALS['__muster_wp_posts'][$oldKey]);
            }
            unset($attrs['edit_date']);
            $GLOBALS['__muster_wp_posts'][$newKey] = array_merge($existing, $attrs, [
                'ID' => $id,
                'post_type' => $postType,
                'post_name' => $slug,
            ]);

            return $id;
        }
    }

    if (!function_exists('get_post')) {
        function get_post(int $postId): object|null
        {
            foreach ($GLOBALS['__muster_wp_posts'] ?? [] as $row) {
                if ((int) ($row['ID'] ?? 0) === $postId) {
                    return (object) $row;
                }
            }

            return null;
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

    if (!function_exists('wp_set_object_terms')) {
        /**
         * @param int $postId
         * @param array<int, string|int> $terms
         * @param string $taxonomy
         * @param bool $append
         * @return array<int, int>
         */
        function wp_set_object_terms(int $postId, array $terms, string $taxonomy, bool $append = false): array
        {
            unset($append);

            $GLOBALS['__muster_wp_post_terms'][$postId][$taxonomy] = $terms;

            return [1];
        }
    }

    if (!function_exists('get_term_by')) {
        /**
         * @param string $field
         * @param string $value
         * @param string $taxonomy
         * @return object|false
         */
        function get_term_by(string $field, string $value, string $taxonomy): object|false
        {
            if ($field !== 'slug') {
                return false;
            }

            $key = $taxonomy . '::' . $value;
            $term = $GLOBALS['__muster_wp_terms'][$key] ?? null;

            if ($term === null) {
                return false;
            }

            return (object) $term;
        }
    }

    if (!function_exists('wp_insert_term')) {
        /**
         * @param string $name
         * @param string $taxonomy
         * @param array<string, mixed> $args
         * @return array<string, int>|WP_Error
         */
        function wp_insert_term(string $name, string $taxonomy, array $args = []): array|WP_Error
        {
            $id = (int) ($GLOBALS['__muster_wp_next_term_id'] ?? 1);
            $GLOBALS['__muster_wp_next_term_id'] = $id + 1;

            $slug = (string) ($args['slug'] ?? sanitize_title($name));
            $key = $taxonomy . '::' . $slug;

            $GLOBALS['__muster_wp_terms'][$key] = [
                'term_id' => $id,
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'name' => $name,
                'description' => (string) ($args['description'] ?? ''),
                'parent' => (int) ($args['parent'] ?? 0),
            ];

            return ['term_id' => $id, 'term_taxonomy_id' => $id];
        }
    }

    if (!function_exists('wp_update_term')) {
        /**
         * @param int $termId
         * @param string $taxonomy
         * @param array<string, mixed> $args
         * @return array<string, int>|WP_Error
         */
        function wp_update_term(int $termId, string $taxonomy, array $args = []): array|WP_Error
        {
            foreach (($GLOBALS['__muster_wp_terms'] ?? []) as $key => $term) {
                if ((int) ($term['term_id'] ?? 0) !== $termId || (string) ($term['taxonomy'] ?? '') !== $taxonomy) {
                    continue;
                }

                $slug = (string) ($args['slug'] ?? $term['slug']);
                $newKey = $taxonomy . '::' . $slug;

                unset($GLOBALS['__muster_wp_terms'][$key]);
                $GLOBALS['__muster_wp_terms'][$newKey] = array_merge($term, $args, [
                    'term_id' => $termId,
                    'taxonomy' => $taxonomy,
                    'slug' => $slug,
                ]);

                return ['term_id' => $termId, 'term_taxonomy_id' => $termId];
            }

            return new WP_Error('missing-term');
        }
    }

    if (!function_exists('get_term')) {
        function get_term(int $termId, string $taxonomy = ''): object|null
        {
            foreach ($GLOBALS['__muster_wp_terms'] ?? [] as $row) {
                if ((int) ($row['term_id'] ?? 0) === $termId
                    && ($taxonomy === '' || (string) ($row['taxonomy'] ?? '') === $taxonomy)) {
                    return (object) $row;
                }
            }

            return null;
        }
    }

    if (!function_exists('update_term_meta')) {
        /**
         * @param int $termId
         * @param string $key
         * @param mixed $value
         * @return bool
         */
        function update_term_meta(int $termId, string $key, mixed $value): bool
        {
            $GLOBALS['__muster_wp_term_meta'][$termId][$key] = $value;

            return true;
        }
    }

    if (!function_exists('get_user_by')) {
        /**
         * @param string $field
         * @param string|int $value
         * @return object|false
         */
        function get_user_by(string $field, string|int $value): object|false
        {
            if ($field === 'id') {
                foreach ($GLOBALS['__muster_wp_users'] ?? [] as $user) {
                    if ((int) ($user['ID'] ?? 0) === (int) $value) {
                        return (object) $user;
                    }
                }

                return false;
            }

            if ($field !== 'login') {
                return false;
            }

            $value = (string) $value;

            $user = $GLOBALS['__muster_wp_users'][$value] ?? null;
            if ($user === null) {
                return false;
            }

            return (object) $user;
        }
    }

    if (!function_exists('wp_delete_user')) {
        function wp_delete_user(int $userId): bool
        {
            foreach ($GLOBALS['__muster_wp_users'] ?? [] as $login => $user) {
                if ((int) ($user['ID'] ?? 0) === $userId) {
                    unset($GLOBALS['__muster_wp_users'][$login]);
                    return true;
                }
            }

            return false;
        }
    }

    if (!function_exists('wp_insert_user')) {
        /**
         * @param array<string, mixed> $attrs
         * @return int|WP_Error
         */
        function wp_insert_user(array $attrs): int|WP_Error
        {
            $id = (int) ($GLOBALS['__muster_wp_next_user_id'] ?? 1);
            $GLOBALS['__muster_wp_next_user_id'] = $id + 1;

            $login = (string) ($attrs['user_login'] ?? '');
            $GLOBALS['__muster_wp_users'][$login] = [
                'ID' => $id,
                'user_login' => $login,
                'user_pass' => (string) ($attrs['user_pass'] ?? ''),
                'user_email' => (string) ($attrs['user_email'] ?? ''),
                'display_name' => (string) ($attrs['display_name'] ?? ''),
                'role' => (string) ($attrs['role'] ?? ''),
            ];

            return $id;
        }
    }

    if (!function_exists('wp_update_user')) {
        /**
         * @param array<string, mixed> $attrs
         * @return int|WP_Error
         */
        function wp_update_user(array $attrs): int|WP_Error
        {
            $id = (int) ($attrs['ID'] ?? 0);
            if ($id <= 0) {
                return new WP_Error('missing-user-id');
            }

            $login = (string) ($attrs['user_login'] ?? '');
            if ($login === '' || !isset($GLOBALS['__muster_wp_users'][$login])) {
                return new WP_Error('missing-user');
            }

            $GLOBALS['__muster_wp_users'][$login] = array_merge(
                $GLOBALS['__muster_wp_users'][$login],
                $attrs,
                ['ID' => $id, 'user_login' => $login]
            );

            return $id;
        }
    }

    if (!function_exists('update_user_meta')) {
        /**
         * @param int $userId
         * @param string $key
         * @param mixed $value
         * @return bool
         */
        function update_user_meta(int $userId, string $key, mixed $value): bool
        {
            $GLOBALS['__muster_wp_user_meta'][$userId][$key] = $value;

            return true;
        }
    }

    if (!function_exists('update_option')) {
        /**
         * @param string $key
         * @param mixed $value
         * @param bool|null $autoload
         * @return bool
         */
        function update_option(string $key, mixed $value, ?bool $autoload = null): bool
        {
            $GLOBALS['__muster_wp_options'][$key] = [
                'value' => $value,
                'autoload' => $autoload,
            ];

            return true;
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $key, mixed $default = false): mixed
        {
            return array_key_exists($key, $GLOBALS['__muster_wp_options'] ?? [])
                ? $GLOBALS['__muster_wp_options'][$key]['value']
                : $default;
        }
    }

    if (!function_exists('delete_option')) {
        function delete_option(string $key): bool
        {
            if (!array_key_exists($key, $GLOBALS['__muster_wp_options'] ?? [])) {
                return false;
            }

            unset($GLOBALS['__muster_wp_options'][$key]);

            return true;
        }
    }

    if (!function_exists('add_option')) {
        /**
         * @param string $key
         * @param mixed $value
         * @param string $deprecated
         * @param string $autoload
         * @return bool
         */
        function add_option(string $key, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
        {
            unset($deprecated);

            $GLOBALS['__muster_wp_options'][$key] = [
                'value' => $value,
                'autoload' => $autoload === 'yes',
            ];

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

    if (!function_exists('update_field')) {
        function update_field(string $selector, mixed $value, int|string $postId = 0): bool
        {
            $GLOBALS['__muster_acf_updates'][] = ['selector' => $selector, 'value' => $value, 'target' => $postId];

            return true;
        }
    }

    if (!function_exists('wp_get_nav_menu_object')) {
        function wp_get_nav_menu_object(string|int $menu): object|false
        {
            if (is_int($menu) || ctype_digit($menu)) {
                foreach ($GLOBALS['__muster_wp_menus'] ?? [] as $name => $id) {
                    if ((int) $id === (int) $menu) {
                        return (object) ['term_id' => (int) $id, 'name' => $name];
                    }
                }

                return false;
            }

            $id = $GLOBALS['__muster_wp_menus'][$menu] ?? null;

            return $id === null ? false : (object) ['term_id' => $id, 'name' => $menu];
        }
    }

    if (!function_exists('wp_update_nav_menu_object')) {
        /** @param array<string, mixed> $args */
        function wp_update_nav_menu_object(int $menuId, array $args = []): int|WP_Error
        {
            foreach ($GLOBALS['__muster_wp_menus'] ?? [] as $name => $id) {
                if ((int) $id !== $menuId) {
                    continue;
                }

                $newName = (string) ($args['menu-name'] ?? $name);
                unset($GLOBALS['__muster_wp_menus'][$name]);
                $GLOBALS['__muster_wp_menus'][$newName] = $menuId;

                return $menuId;
            }

            return new WP_Error('missing-menu');
        }
    }

    if (!function_exists('wp_delete_nav_menu')) {
        function wp_delete_nav_menu(int $menuId): bool
        {
            foreach ($GLOBALS['__muster_wp_menus'] ?? [] as $name => $id) {
                if ((int) $id === $menuId) {
                    unset($GLOBALS['__muster_wp_menus'][$name], $GLOBALS['__muster_wp_menu_items'][$menuId]);
                    return true;
                }
            }

            return false;
        }
    }

    if (!function_exists('wp_create_nav_menu')) {
        function wp_create_nav_menu(string $name): int|WP_Error
        {
            $id = (int) ($GLOBALS['__muster_wp_next_menu_id'] ?? 100);
            $GLOBALS['__muster_wp_next_menu_id'] = $id + 1;
            $GLOBALS['__muster_wp_menus'][$name] = $id;
            $GLOBALS['__muster_wp_menu_items'][$id] = [];

            return $id;
        }
    }

    if (!function_exists('wp_update_nav_menu_item')) {
        /**
         * @param array<string, mixed> $args
         */
        function wp_update_nav_menu_item(int $menuId, int $itemId = 0, array $args = []): int|WP_Error
        {
            $id = (int) ($GLOBALS['__muster_wp_next_menu_item_id'] ?? 500);
            $GLOBALS['__muster_wp_next_menu_item_id'] = $id + 1;
            $GLOBALS['__muster_wp_menu_items'][$menuId][$id] = $args;

            return $id;
        }
    }

    if (!function_exists('wp_get_nav_menu_items')) {
        /**
         * @return array<int, object>|false
         */
        function wp_get_nav_menu_items(int|string|object $menu): array|false
        {
            $menuId = is_object($menu) ? (int) $menu->term_id : (int) $menu;
            $items = $GLOBALS['__muster_wp_menu_items'][$menuId] ?? [];

            return array_map(
                static fn (int $id): object => (object) ['ID' => $id],
                array_keys($items)
            );
        }
    }

    if (!function_exists('wp_delete_post')) {
        function wp_delete_post(int $postId, bool $force = false): mixed
        {
            $GLOBALS['__muster_wp_deleted_posts'][] = $postId;

            foreach ($GLOBALS['__muster_wp_menu_items'] ?? [] as $menuId => $items) {
                unset($GLOBALS['__muster_wp_menu_items'][$menuId][$postId]);
            }

            foreach ($GLOBALS['__muster_wp_posts'] ?? [] as $key => $row) {
                if ((int) $row['ID'] === $postId) {
                    unset($GLOBALS['__muster_wp_posts'][$key]);
                }
            }

            return true;
        }
    }

    if (!function_exists('get_theme_mod')) {
        function get_theme_mod(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['__muster_wp_theme_mods'][$name] ?? $default;
        }
    }

    if (!function_exists('set_theme_mod')) {
        function set_theme_mod(string $name, mixed $value): bool
        {
            $GLOBALS['__muster_wp_theme_mods'][$name] = $value;

            return true;
        }
    }

    if (!function_exists('wp_upload_dir')) {
        /**
         * @return array<string, mixed>
         */
        function wp_upload_dir(): array
        {
            $dir = $GLOBALS['__muster_wp_upload_dir'] ?? sys_get_temp_dir() . '/muster-uploads';

            return ['path' => $dir, 'url' => 'https://example.test/uploads', 'error' => false];
        }
    }

    if (!function_exists('wp_insert_attachment')) {
        /**
         * @param array<string, mixed> $attrs
         */
        function wp_insert_attachment(array $attrs, string $file = '', int $parent = 0, bool $wpError = false): int|WP_Error
        {
            $attrs['post_type'] = 'attachment';
            $attrs['post_parent'] = $parent;

            /** @var int|WP_Error $id */
            $id = wp_insert_post($attrs, $wpError);

            if (is_int($id)) {
                $GLOBALS['__muster_wp_attachment_files'][$id] = $file;
            }

            return $id;
        }
    }

    if (!function_exists('set_post_thumbnail')) {
        function set_post_thumbnail(int $postId, int $attachmentId): bool
        {
            $GLOBALS['__muster_wp_thumbnails'][$postId] = $attachmentId;

            return true;
        }
    }

    if (!function_exists('get_terms')) {
        /**
         * @param array<string, mixed> $args
         * @return array<int, int>|WP_Error
         */
        function get_terms(array $args = []): array|WP_Error
        {
            $taxonomy = (string) ($args['taxonomy'] ?? '');
            $ids = [];

            foreach ($GLOBALS['__muster_wp_terms'] ?? [] as $row) {
                if (($row['taxonomy'] ?? '') === $taxonomy) {
                    $ids[] = (int) $row['term_id'];
                }
            }

            return $ids;
        }
    }

    if (!function_exists('wp_get_environment_type')) {
        function wp_get_environment_type(): string
        {
            return $GLOBALS['__muster_wp_environment'] ?? 'local';
        }
    }

    if (!function_exists('get_child_theme_namespace')) {
        function get_child_theme_namespace(): ?string
        {
            return $GLOBALS['__muster_child_namespace'] ?? 'TestTheme';
        }
    }

    if (!function_exists('get_stylesheet_directory')) {
        function get_stylesheet_directory(): string
        {
            return $GLOBALS['__muster_stylesheet_dir'] ?? sys_get_temp_dir() . '/muster-theme';
        }
    }

    if (!function_exists('wp_delete_term')) {
        function wp_delete_term(int $termId, string $taxonomy): mixed
        {
            $GLOBALS['__muster_wp_deleted_terms'][] = [$termId, $taxonomy];

            foreach ($GLOBALS['__muster_wp_terms'] ?? [] as $key => $row) {
                if ((int) $row['term_id'] === $termId && ($row['taxonomy'] ?? '') === $taxonomy) {
                    unset($GLOBALS['__muster_wp_terms'][$key]);
                }
            }

            return true;
        }
    }

    if (!function_exists('get_gmt_from_date')) {
        function get_gmt_from_date(string $date): string
        {
            return $date;
        }
    }

    if (!function_exists('get_comment')) {
        function get_comment(int $commentId): object|null
        {
            $row = $GLOBALS['__muster_wp_comments'][$commentId] ?? null;

            return is_array($row) ? (object) $row : null;
        }
    }

    if (!function_exists('get_comments')) {
        /**
         * @param array<string, mixed> $args
         * @return array<int, object>
         */
        function get_comments(array $args = []): array
        {
            $comments = [];

            foreach ($GLOBALS['__muster_wp_comments'] ?? [] as $row) {
                if (isset($args['post_id']) && (int) $row['comment_post_ID'] !== (int) $args['post_id']) {
                    continue;
                }
                if (isset($args['parent']) && (int) $row['comment_parent'] !== (int) $args['parent']) {
                    continue;
                }

                $comments[] = (object) $row;
            }

            return $comments;
        }
    }

    if (!function_exists('wp_insert_comment')) {
        /**
         * @param array<string, mixed> $attrs
         */
        function wp_insert_comment(array $attrs): int
        {
            $id = (int) ($GLOBALS['__muster_wp_next_comment_id'] ?? 1);
            $GLOBALS['__muster_wp_next_comment_id'] = $id + 1;
            $GLOBALS['__muster_wp_comments'][$id] = array_merge([
                'comment_ID' => $id,
                'comment_post_ID' => 0,
                'comment_author' => '',
                'comment_author_email' => '',
                'comment_author_url' => '',
                'comment_content' => '',
                'comment_type' => 'comment',
                'comment_parent' => 0,
                'user_id' => 0,
                'comment_approved' => '1',
                'comment_date' => '',
                'comment_date_gmt' => '',
            ], $attrs, ['comment_ID' => $id]);

            return $id;
        }
    }

    if (!function_exists('wp_update_comment')) {
        /**
         * @param array<string, mixed> $attrs
         */
        function wp_update_comment(array $attrs, bool $wpError = false): int|WP_Error
        {
            unset($wpError);
            $GLOBALS['__muster_wp_update_comment_calls']++;
            $id = (int) ($attrs['comment_ID'] ?? 0);
            if (!isset($GLOBALS['__muster_wp_comments'][$id])) {
                return new WP_Error('missing-comment');
            }

            $GLOBALS['__muster_wp_comments'][$id] = array_merge(
                $GLOBALS['__muster_wp_comments'][$id],
                $attrs
            );

            return 1;
        }
    }

    if (!function_exists('update_comment_meta')) {
        function update_comment_meta(int $commentId, string $key, mixed $value): bool
        {
            $GLOBALS['__muster_wp_comment_meta'][$commentId][$key] = $value;

            return true;
        }
    }

    if (!function_exists('wp_delete_comment')) {
        function wp_delete_comment(int $commentId, bool $forceDelete = false): bool
        {
            unset($forceDelete);
            unset($GLOBALS['__muster_wp_comments'][$commentId]);

            return true;
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
        $GLOBALS['__muster_wp_post_terms'] = [];
        $GLOBALS['__muster_wp_next_post_id'] = 1;

        $GLOBALS['__muster_wp_terms'] = [];
        $GLOBALS['__muster_wp_term_meta'] = [];
        $GLOBALS['__muster_wp_next_term_id'] = 1;

        $GLOBALS['__muster_wp_users'] = [];
        $GLOBALS['__muster_wp_user_meta'] = [];
        $GLOBALS['__muster_wp_next_user_id'] = 1;

        $GLOBALS['__muster_wp_comments'] = [];
        $GLOBALS['__muster_wp_comment_meta'] = [];
        $GLOBALS['__muster_wp_next_comment_id'] = 1;
        $GLOBALS['__muster_wp_update_comment_calls'] = 0;

        $GLOBALS['__muster_wp_options'] = [];
        $GLOBALS['__muster_registered_post_types'] = ['post', 'page', 'event'];

        $GLOBALS['__muster_wp_cli_lines'] = [];
        $GLOBALS['__muster_wp_cli_commands'] = [];

        $GLOBALS['__muster_acf_updates'] = [];

        $GLOBALS['__muster_wp_menus'] = [];
        $GLOBALS['__muster_wp_menu_items'] = [];
        $GLOBALS['__muster_wp_next_menu_id'] = 100;
        $GLOBALS['__muster_wp_next_menu_item_id'] = 500;
        $GLOBALS['__muster_wp_theme_mods'] = [];
        $GLOBALS['__muster_wp_deleted_posts'] = [];
        $GLOBALS['__muster_wp_deleted_terms'] = [];
        $GLOBALS['__muster_wp_thumbnails'] = [];
        $GLOBALS['__muster_wp_attachment_files'] = [];
        $GLOBALS['__muster_wp_upload_dir'] = sys_get_temp_dir() . '/muster-uploads-' . getmypid();

        $GLOBALS['__muster_wp_environment'] = 'local';
        $GLOBALS['__muster_child_namespace'] = 'TestTheme';
        $GLOBALS['__muster_stylesheet_dir'] = sys_get_temp_dir() . '/muster-theme-' . getmypid();
    }
}
