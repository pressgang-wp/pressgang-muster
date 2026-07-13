<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\Contracts\PersistableDeclaration;
use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Ownership\ResolvesIdentity;
use PressGang\Muster\Refs\PostRef;
use PressGang\Muster\Refs\LazyRef;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Support\WpResult;

/**
 * Fluent media builder with idempotent-upsert intent.
 *
 * Sources: an existing file copied into the uploads directory, or a generated
 * placeholder image. Placeholders are deterministic — the fill colour derives
 * from the slug — so the same slug always yields the same image and visual
 * snapshots stay stable between runs.
 *
 * Muster-scoped builders use an explicit logical key. The WordPress locator is
 * `attachment + post_name` (slug), which may change without replacing the file.
 */
final class AttachmentBuilder implements PersistableDeclaration
{
    use HasOwnership;
    use ResolvesIdentity;

    private ?string $sourcePath = null;

    private int $width = 1200;

    private int $height = 800;

    private ?string $label = null;

    private bool $placeholder = false;

    private ?string $title = null;

    private ?string $alt = null;

    private PostRef|LazyRef|int|null $parent = null;

    private PostRef|LazyRef|int|null $featuredOn = null;

    /**
     * @param MusterContext $context
     * @param string $slug
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        private string $slug,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);
    }

    /**
     * Use an existing file as the attachment source.
     *
     * @param string $path
     * @return self
     */
    public function fromFile(string $path): self
    {
        $this->sourcePath = $path;
        $this->placeholder = false;

        return $this;
    }

    /**
     * Generate a deterministic placeholder image as the attachment source.
     *
     * @param int $width
     * @param int $height
     * @param string|null $label Text rendered onto the image; defaults to the slug.
     * @return self
     */
    public function placeholder(int $width = 1200, int $height = 800, ?string $label = null): self
    {
        $this->placeholder = true;
        $this->width = $width;
        $this->height = $height;
        $this->label = $label;

        return $this;
    }

    /**
     * @param string $title
     * @return self
     */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the image's alt text (`_wp_attachment_image_alt`).
     *
     * @param string $alt
     * @return self
     */
    public function alt(string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    /**
     * Attach the media item to a post (sets the attachment's parent).
     *
     * @param PostRef|LazyRef|int $post
     * @return self
     */
    public function attachTo(PostRef|LazyRef|int $post): self
    {
        $this->parent = $post;

        return $this;
    }

    /**
     * Set the media item as a post's featured image after save.
     *
     * @param PostRef|LazyRef|int $post
     * @return self
     */
    public function featuredOn(PostRef|LazyRef|int $post): self
    {
        $this->featuredOn = $post;

        return $this;
    }

    /**
     * Upsert the attachment and apply alt text and featured-image assignments.
     * Unowned slug matches require explicit `adopt()`.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_insert_attachment/
     *
     * @return PostRef Always with post type `attachment`.
     *
     * @throws LogicException If no source is configured.
     * @throws RuntimeException If WordPress runtime functions are unavailable or the save fails.
     */
    public function save(): PostRef
    {
        if (!$this->placeholder && $this->sourcePath === null) {
            throw new LogicException('Attachment source is required: call placeholder() or fromFile().');
        }

        $intent = $this->ownershipIntent();
        $parentId = $this->parent === null ? null : $this->resolvePostId($this->parent);
        $featuredOnId = $this->featuredOn === null ? null : $this->resolvePostId($this->featuredOn);
        $this->context->debugDeclaration('Attachment', array_keys(array_filter([
            'slug' => true,
            'source' => $this->sourcePath !== null || $this->placeholder,
            'title' => $this->title !== null,
            'alt' => $this->alt !== null,
            'parent' => $this->parent !== null,
            'featured_on' => $this->featuredOn !== null,
        ])));

        if (!function_exists('get_posts')) {
            throw new RuntimeException('get_posts() is required to plan or save attachments.');
        }

        ['existing' => $attachmentId, 'owned' => $owned] = $this->resolveIdentity(
            $this->context,
            $intent,
            'attachment',
            'attachment',
            $this->slug,
            findNatural: fn (): ?int => $this->findExistingId(),
            resolveOwned: fn (OwnedResource $owned): ?int => $this->resolveOwnedAttachmentId($owned),
            idOf: static fn (int $id): int => $id,
            conflictMessage: fn (int $naturalId): string => sprintf(
                'Cannot move owned attachment [%s:%s] to slug [%s]; that slug belongs to attachment ID %d.',
                $intent['scope'],
                $intent['key'],
                $this->slug,
                $naturalId
            ),
        );

        $operation = $this->attachmentOperation($attachmentId, $owned, $intent);
        $plannedId = $attachmentId ?? 0;

        if ($this->context->dryRun()) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'attachment', $plannedId, 'attachment', $this->slug);

            return new PostRef($plannedId, 'attachment', $this->slug);
        }

        if ($operation === OperationAction::Keep && $attachmentId !== null) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'attachment', $attachmentId, 'attachment', $this->slug);

            return new PostRef($attachmentId, 'attachment', $this->slug);
        }

        if (!function_exists('wp_insert_attachment') || !function_exists('wp_upload_dir')) {
            throw new RuntimeException('WordPress write functions are required to save attachments.');
        }

        if ($attachmentId === null) {
            $attachmentId = $this->insertAttachment();
        } elseif ($intent !== null) {
            $this->updateOwnedAttachment($attachmentId, $parentId);
        }

        $this->applySideEffects($attachmentId, $featuredOnId);

        $this->finalizeUpsert($this->context, $intent, $operation, 'attachment', $attachmentId, 'attachment', $this->slug);

        $this->context->logger()->debug(
            sprintf('Attachment %s [attachment:%s] as ID %d.', $operation->value, $this->slug, $attachmentId)
        );

        return new PostRef($attachmentId, 'attachment', $this->slug);
    }

    /**
     * Determine whether the declaration creates, updates, or keeps the attachment.
     *
     * Title, alt text, parent, and featured-image payloads are conservatively
     * reported as updates until those writes expose comparable read contracts.
     *
     * @param int|null $attachmentId
     * @param OwnedResource|null $owned
     * @param array{scope: string, key: string, adopt: bool}|null $intent
     * @return OperationAction
     */
    private function attachmentOperation(?int $attachmentId, ?OwnedResource $owned, ?array $intent): OperationAction
    {
        if ($attachmentId === null) {
            $plannedClaim = $intent !== null
                && $this->context->ownership()->isPlannedClaim($intent['scope'], $intent['key']);

            return $plannedClaim ? OperationAction::Keep : OperationAction::Create;
        }

        if ($intent !== null && ($owned === null
            || $owned->locator() !== $this->slug
            || $this->title !== null
            || $this->parent !== null
            || $this->alt !== null
            || $this->featuredOn !== null)) {
            return OperationAction::Update;
        }

        return OperationAction::Keep;
    }

    /**
     * Re-point an owned attachment's slug, title, and parent without
     * replacing its file.
     *
     * @param int $attachmentId
     * @param int|null $parentId Resolved parent post ID, when declared.
     * @return void
     * @throws RuntimeException If the update fails or `wp_update_post()` is missing.
     */
    private function updateOwnedAttachment(int $attachmentId, ?int $parentId): void
    {
        if (!function_exists('wp_update_post')) {
            throw new RuntimeException('wp_update_post() is required to update owned attachments.');
        }

        $attributes = [
            'ID' => $attachmentId,
            'post_name' => $this->slug,
        ];

        if ($this->title !== null) {
            $attributes['post_title'] = $this->title;
        }

        if ($this->parent !== null) {
            $attributes['post_parent'] = $parentId;
        }

        $result = wp_update_post($attributes, true);

        if (!WpResult::isId($result)) {
            throw new RuntimeException(sprintf('Failed to update owned attachment [%s].', $this->slug));
        }
    }

    /**
     * Apply alt text and featured-image assignment after the core save.
     *
     * @param int $attachmentId
     * @param int|null $featuredOnId Resolved post ID to feature the image on.
     * @return void
     */
    private function applySideEffects(int $attachmentId, ?int $featuredOnId): void
    {
        if ($this->alt !== null && function_exists('update_post_meta')) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $this->alt);
        }

        if ($this->featuredOn !== null && function_exists('set_post_thumbnail')) {
            set_post_thumbnail((int) $featuredOnId, $attachmentId);
        }
    }

    /**
     * Look up an existing attachment by slug.
     *
     * @return int|null
     */
    private function findExistingId(): ?int
    {
        $existing = get_posts([
            'name' => $this->slug,
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        return empty($existing) ? null : (int) $existing[0];
    }

    private function resolveOwnedAttachmentId(OwnedResource $owned): ?int
    {
        if (!function_exists('get_post')) {
            throw new RuntimeException('get_post() is required to resolve owned attachments.');
        }

        $post = get_post($owned->id());

        return is_object($post)
            && isset($post->ID, $post->post_type)
            && (string) $post->post_type === 'attachment'
                ? (int) $post->ID
                : null;
    }

    /**
     * Write the source file into uploads and register the attachment post.
     *
     * @return int
     *
     * @throws RuntimeException If the file cannot be written or the insert fails.
     */
    private function insertAttachment(): int
    {
        $file = $this->resolveTargetPath();
        $this->writeSourceFile($file);

        /** @var int|\WP_Error $attachmentId */
        $attachmentId = wp_insert_attachment([
            'post_title' => $this->title ?? $this->slug,
            'post_name' => $this->slug,
            'post_mime_type' => $this->detectMimeType($file),
            'post_status' => 'inherit',
        ], $file, $this->parent === null ? 0 : $this->resolvePostId($this->parent), true);

        if (!WpResult::isId($attachmentId)) {
            throw new RuntimeException(sprintf('Failed to save attachment [%s].', $this->slug));
        }

        $this->generateMetadata((int) $attachmentId, $file);

        return (int) $attachmentId;
    }

    /**
     * Build the destination path inside the uploads directory.
     *
     * @return string
     *
     * @throws RuntimeException If the uploads directory is not writable.
     */
    private function resolveTargetPath(): string
    {
        $uploads = wp_upload_dir();
        $dir = (string) ($uploads['path'] ?? '');

        if ($dir === '' || (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))) {
            throw new RuntimeException('Uploads directory is not writable.');
        }

        if ($this->placeholder) {
            $extension = 'png';
        } else {
            $extension = strtolower(pathinfo((string) $this->sourcePath, PATHINFO_EXTENSION));
            $extension = $extension === '' ? 'bin' : $extension;
        }

        return rtrim($dir, '/') . '/' . $this->slug . '.' . $extension;
    }

    /**
     * Produce the file contents: copy the configured source, or generate a placeholder.
     *
     * @param string $file
     * @return void
     *
     * @throws RuntimeException If the source is unreadable or the write fails.
     */
    private function writeSourceFile(string $file): void
    {
        if ($this->placeholder) {
            $this->writePlaceholder($file);

            return;
        }

        if (!is_readable((string) $this->sourcePath) || !copy((string) $this->sourcePath, $file)) {
            throw new RuntimeException(sprintf('Attachment source [%s] is not readable.', (string) $this->sourcePath));
        }
    }

    /**
     * @param string $file
     * @return string
     */
    private function detectMimeType(string $file): string
    {
        if ($this->placeholder) {
            return 'image/png';
        }

        if (function_exists('wp_check_filetype')) {
            return (string) (wp_check_filetype($file)['type'] ?? 'application/octet-stream');
        }

        return 'application/octet-stream';
    }

    /**
     * Write a deterministic PNG: a solid colour derived from the slug hash,
     * labelled when GD is available.
     *
     * Falls back to a minimal valid 1×1 PNG without GD so runs stay deterministic
     * in stripped-down environments.
     *
     * @param string $file
     * @return void
     *
     * @throws RuntimeException If no image can be written.
     */
    private function writePlaceholder(string $file): void
    {
        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(max(1, $this->width), max(1, $this->height));
            [$r, $g, $b] = $this->colourFromSlug();
            imagefilledrectangle($image, 0, 0, $this->width, $this->height, imagecolorallocate($image, $r, $g, $b));
            imagestring($image, 5, 12, 12, $this->label ?? $this->slug, imagecolorallocate($image, 255, 255, 255));

            $written = imagepng($image, $file);
            imagedestroy($image);

            if ($written) {
                return;
            }
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        if (file_put_contents($file, (string) $png) === false) {
            throw new RuntimeException('Failed to write placeholder image.');
        }
    }

    /**
     * Derive stable mid-range RGB channels (50–177) from the slug, keeping the
     * white label legible on every generated colour.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function colourFromSlug(): array
    {
        $hash = crc32($this->slug);

        return [
            50 + ($hash & 0x7F),
            50 + (($hash >> 8) & 0x7F),
            50 + (($hash >> 16) & 0x7F),
        ];
    }

    /**
     * @param PostRef|LazyRef|int $post
     * @return int
     */
    private function resolvePostId(PostRef|LazyRef|int $post): int
    {
        if ($post instanceof LazyRef) {
            return $post->resolve(['post', 'attachment'])->id();
        }

        return $post instanceof PostRef ? $post->id() : $post;
    }

    /**
     * Generate and store attachment metadata where the image API is available,
     * loading `wp-admin/includes/image.php` on demand outside admin requests.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_generate_attachment_metadata/
     *
     * @param int $attachmentId
     * @param string $file
     * @return void
     */
    private function generateMetadata(int $attachmentId, string $file): void
    {
        if (!function_exists('wp_generate_attachment_metadata') && defined('ABSPATH')) {
            $include = ABSPATH . 'wp-admin/includes/image.php';
            if (is_readable($include)) {
                require_once $include;
            }
        }

        if (function_exists('wp_generate_attachment_metadata') && function_exists('wp_update_attachment_metadata')) {
            $metadata = wp_generate_attachment_metadata($attachmentId, $file);
            if (is_array($metadata)) {
                wp_update_attachment_metadata($attachmentId, $metadata);
            }
        }
    }
}
