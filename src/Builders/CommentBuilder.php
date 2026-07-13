<?php

namespace PressGang\Muster\Builders;

use LogicException;
use PressGang\Muster\Contracts\PersistableDeclaration;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Refs\CommentRef;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Refs\UserRef;
use PressGang\Muster\Refs\LazyRef;
use PressGang\Muster\Results\OperationAction;
use RuntimeException;

/**
 * Fluent idempotent builder for WordPress comments and replies.
 *
 * Managed identity is the Muster scope and logical key. Collision detection
 * uses a WordPress-native locator composed from post, parent, comment type,
 * author identity, and deterministic GMT date; content remains mutable.
 */
final class CommentBuilder implements PersistableDeclaration
{
    use HasOwnership;

    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @param MusterContext $context
     * @param int|PostRef|LazyRef|null $post
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        int|PostRef|LazyRef|null $post = null,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);

        if ($post !== null) {
            $this->payload['comment_post_ID'] = $post;
        }
    }

    public function post(int|PostRef|LazyRef $post): self
    {
        $this->payload['comment_post_ID'] = $post;

        return $this;
    }

    public function author(string $name): self
    {
        $this->payload['comment_author'] = $name;

        return $this;
    }

    public function email(string $email): self
    {
        $this->payload['comment_author_email'] = $email;

        return $this;
    }

    public function url(string $url): self
    {
        $this->payload['comment_author_url'] = $url;

        return $this;
    }

    public function user(int|UserRef|LazyRef $user): self
    {
        $this->payload['user_id'] = $user;

        return $this;
    }

    public function content(string $content): self
    {
        $this->payload['comment_content'] = $content;

        return $this;
    }

    /**
     * Set approval state (`approve`, `hold`, `spam`, or `trash`).
     *
     * @param string $status
     * @return self
     */
    public function status(string $status): self
    {
        $this->payload['comment_approved'] = match (strtolower(trim($status))) {
            'approve', 'approved', '1' => '1',
            'hold', 'pending', '0' => '0',
            'spam' => 'spam',
            'trash' => 'trash',
            default => throw new LogicException(sprintf('Unsupported comment status [%s].', $status)),
        };

        return $this;
    }

    public function type(string $type): self
    {
        $type = trim($type);
        if ($type === '') {
            throw new LogicException('Comment type must not be empty.');
        }

        $this->payload['comment_type'] = $type;

        return $this;
    }

    public function date(string $date): self
    {
        $this->payload['comment_date'] = $date;

        return $this;
    }

    public function parent(int|CommentRef|LazyRef $parent): self
    {
        $this->payload['comment_parent'] = $parent;

        return $this;
    }

    /**
     * Set comment meta written after the core comment succeeds.
     *
     * @param array<string, mixed> $meta
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->payload['meta'] = $meta;

        return $this;
    }

    /**
     * Persist an idempotent WordPress comment upsert.
     *
     * The logical key owns the comment. A matching unowned native locator is a
     * conflict unless `adopt()` was declared. Omitted mutable fields are
     * preserved on updates; comment meta is applied after core persistence.
     *
     * See: https://developer.wordpress.org/reference/functions/get_comments/
     * See: https://developer.wordpress.org/reference/functions/wp_insert_comment/
     * See: https://developer.wordpress.org/reference/functions/wp_update_comment/
     *
     * @return CommentRef
     */
    public function save(): CommentRef
    {
        $intent = $this->ownershipIntent();
        $attributes = $this->attributes();
        $postId = (int) $attributes['comment_post_ID'];
        $type = (string) $attributes['comment_type'];
        $locator = $this->locator($attributes);

        if (!function_exists('get_comment') || !function_exists('get_comments')) {
            throw new RuntimeException('get_comment() and get_comments() are required to plan or save comments.');
        }

        $natural = $this->findNatural($attributes);
        if ($natural !== null && $this->context->isPlannedDeleted(
            'comment',
            (int) $natural->comment_ID,
            $type,
            $locator
        )) {
            $natural = null;
        }

        $owned = null;
        $existing = $natural;

        if ($intent !== null) {
            $owned = $this->currentOwnership($this->context, $intent, 'comment', $type);
            $ownedComment = $owned === null ? null : get_comment($owned->id());

            if ($ownedComment !== null && $ownedComment !== false
                && $this->context->isPlannedDeleted('comment', (int) $ownedComment->comment_ID, $type, $owned->locator())) {
                $ownedComment = null;
            }

            if ($ownedComment !== null && $ownedComment !== false) {
                $existing = $ownedComment;
            }

            if ($existing !== null && $natural !== null
                && (int) $existing->comment_ID !== (int) $natural->comment_ID) {
                $this->throwOwnershipConflict(
                    $this->context,
                    $intent,
                    'comment',
                    (int) $natural->comment_ID,
                    $locator,
                    sprintf('Comment locator [%s] belongs to a different comment.', $locator)
                );
            }

            if ($existing !== null) {
                $this->claimExistingOwnership(
                    $this->context,
                    $intent,
                    'comment',
                    (int) $existing->comment_ID,
                    $type,
                    $locator
                );
            }
        }

        $existingId = $existing === null ? null : (int) $existing->comment_ID;
        $writeAttributes = $this->persistenceAttributes($attributes, $existingId !== null);
        $this->context->debugDeclaration('Comment', [
            ...array_keys($writeAttributes),
            ...array_map(static fn (string $key): string => 'meta.' . $key, array_keys((array) ($this->payload['meta'] ?? []))),
        ]);
        $coreChanged = $existing === null || $this->attributesDiffer($existing, $writeAttributes);
        $operation = $this->operation($existing, $writeAttributes, $owned);
        $plannedId = $existingId ?? 0;

        if ($this->context->dryRun()) {
            if ($intent !== null) {
                $this->reportOwnership($this->context, $intent, $operation, 'comment', $plannedId, $locator);
                $this->recordOwnership($this->context, $intent, 'comment', $plannedId, $type, $locator);
            }

            return new CommentRef($plannedId, $postId);
        }

        if ($operation === OperationAction::Keep && $existingId !== null) {
            if ($intent !== null) {
                $this->recordOwnership($this->context, $intent, 'comment', $existingId, $type, $locator);
                $this->reportOwnership($this->context, $intent, $operation, 'comment', $existingId, $locator);
            }

            return new CommentRef($existingId, $postId);
        }

        if (!function_exists('wp_insert_comment') || !function_exists('wp_update_comment')) {
            throw new RuntimeException('WordPress write functions are required to save comments.');
        }

        if ($existingId === null) {
            $result = wp_insert_comment($writeAttributes);
            if ((function_exists('is_wp_error') && is_wp_error($result)) || !is_int($result) || $result <= 0) {
                throw new RuntimeException('Failed to insert comment.');
            }

            $commentId = $result;
        } elseif ($coreChanged) {
            $writeAttributes['comment_ID'] = $existingId;
            $result = wp_update_comment($writeAttributes, true);
            if ((function_exists('is_wp_error') && is_wp_error($result)) || $result === false || $result === 0) {
                throw new RuntimeException('Failed to update comment.');
            }

            $commentId = $existingId;
        } else {
            $commentId = $existingId;
        }
        $meta = $this->payload['meta'] ?? [];
        if (is_array($meta) && function_exists('update_comment_meta')) {
            foreach ($meta as $key => $value) {
                update_comment_meta($commentId, (string) $key, $value);
            }
        }

        if ($intent !== null) {
            $this->recordOwnership($this->context, $intent, 'comment', $commentId, $type, $locator);
            $this->reportOwnership($this->context, $intent, $operation, 'comment', $commentId, $locator);
        }

        return new CommentRef($commentId, $postId);
    }

    /**
     * @return array<string, int|string>
     */
    private function attributes(): array
    {
        $post = $this->payload['comment_post_ID'] ?? null;
        $postId = $this->resolvePostId($post);
        if ($postId < 1 && !($this->context->dryRun() && ($post instanceof PostRef || $post instanceof LazyRef))) {
            throw new LogicException('Comment builder requires a saved post or positive post ID.');
        }

        $userId = $this->resolveUserId($this->payload['user_id'] ?? 0);
        $author = (string) ($this->payload['comment_author'] ?? '');
        $email = (string) ($this->payload['comment_author_email'] ?? '');

        if ($userId > 0 && function_exists('get_user_by')) {
            $user = get_user_by('id', $userId);
            if ($user !== false) {
                $author = $author !== '' ? $author : (string) ($user->display_name ?? $user->user_login ?? '');
                $email = $email !== '' ? $email : (string) ($user->user_email ?? '');
            }
        }

        if ($email === '' && $author === '') {
            throw new LogicException('Comment builder requires an author email, author name, or resolvable user.');
        }

        if (!array_key_exists('comment_content', $this->payload)) {
            throw new LogicException('Comment builder requires explicit content().');
        }

        $date = (string) ($this->payload['comment_date'] ?? $this->context->clock()->epoch()->format('Y-m-d H:i:s'));
        $dateGmt = function_exists('get_gmt_from_date') ? get_gmt_from_date($date) : $date;

        return [
            'comment_post_ID' => $postId,
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_author_url' => (string) ($this->payload['comment_author_url'] ?? ''),
            'comment_content' => (string) $this->payload['comment_content'],
            'comment_type' => (string) ($this->payload['comment_type'] ?? 'comment'),
            'comment_parent' => $this->resolveParentId($this->payload['comment_parent'] ?? 0),
            'user_id' => $userId,
            'comment_approved' => (string) ($this->payload['comment_approved'] ?? '1'),
            'comment_date' => $date,
            'comment_date_gmt' => $dateGmt,
        ];
    }

    /**
     * @param array<string, int|string> $attributes
     */
    private function locator(array $attributes): string
    {
        $identity = (string) $attributes['comment_author_email'];
        if ($identity === '') {
            $identity = (string) $attributes['comment_author'];
        }

        return sprintf(
            'post:%d|parent:%d|type:%s|author:%s|date:%s',
            (int) $attributes['comment_post_ID'],
            (int) $attributes['comment_parent'],
            (string) $attributes['comment_type'],
            strtolower($identity),
            (string) $attributes['comment_date_gmt']
        );
    }

    /**
     * @param array<string, int|string> $attributes
     */
    private function findNatural(array $attributes): ?object
    {
        $comments = get_comments([
            'post_id' => (int) $attributes['comment_post_ID'],
            'parent' => (int) $attributes['comment_parent'],
            'status' => 'all',
            'number' => 0,
        ]);

        foreach ($comments as $comment) {
            if ((string) ($comment->comment_type ?? 'comment') === (string) $attributes['comment_type']
                && (string) ($comment->comment_author_email ?? '') === (string) $attributes['comment_author_email']
                && (string) ($comment->comment_author ?? '') === (string) $attributes['comment_author']
                && (string) ($comment->comment_date_gmt ?? '') === (string) $attributes['comment_date_gmt']) {
                return $comment;
            }
        }

        return null;
    }

    /**
     * @param object|null $existing
     * @param array<string, int|string> $attributes
     */
    private function operation(?object $existing, array $attributes, ?OwnedResource $owned): OperationAction
    {
        if ($existing === null) {
            if ($owned !== null && $this->context->ownership()->isPlannedClaim($owned->scope(), $owned->key())) {
                return OperationAction::Keep;
            }

            return OperationAction::Create;
        }

        if ($this->attributesDiffer($existing, $attributes)) {
            return OperationAction::Update;
        }

        return ($this->payload['meta'] ?? []) === [] ? OperationAction::Keep : OperationAction::Update;
    }

    /**
     * Preserve optional mutable fields that were not declared on an update.
     *
     * @param array<string, int|string> $attributes
     * @return array<string, int|string>
     */
    private function persistenceAttributes(array $attributes, bool $updating): array
    {
        if (!$updating) {
            return $attributes;
        }

        foreach (['comment_author_url', 'comment_approved', 'user_id'] as $field) {
            if (!array_key_exists($field, $this->payload)) {
                unset($attributes[$field]);
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, int|string> $attributes
     */
    private function attributesDiffer(object $existing, array $attributes): bool
    {
        foreach ($attributes as $field => $value) {
            if ((string) ($existing->{$field} ?? '') !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    private function resolvePostId(mixed $post): int
    {
        if ($post instanceof LazyRef) {
            return $post->resolve(['post', 'attachment'])->id();
        }

        return $post instanceof PostRef ? $post->id() : (int) $post;
    }

    private function resolveParentId(mixed $parent): int
    {
        if ($parent instanceof LazyRef) {
            return $parent->resolve('comment')->id();
        }

        return $parent instanceof CommentRef ? $parent->id() : (int) $parent;
    }

    private function resolveUserId(mixed $user): int
    {
        if ($user instanceof LazyRef) {
            return $user->resolve('user')->id();
        }

        return $user instanceof UserRef ? $user->userId() : (int) $user;
    }
}
