<?php

namespace PressGang\Muster\Builders;

use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Refs\PostRef;

/**
 * Fluent media builder with idempotent-upsert intent.
 *
 * Sources: an existing file copied into the uploads directory, or a
 * deterministic generated placeholder image (colour derived from the slug,
 * so the same slug always produces the same image).
 */
final class AttachmentBuilder
{
    private ?string $sourcePath = null;

    private int $width = 1200;

    private int $height = 800;

    private ?string $label = null;

    private bool $placeholder = false;

    private ?string $title = null;

    private ?string $alt = null;

    private PostRef|int|null $parent = null;

    private PostRef|int|null $featuredOn = null;

    /**
     * @param MusterContext $context
     * @param string $slug
     */
    public function __construct(
        private MusterContext $context,
        private string $slug,
    ) {
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
     * @param string|null $label Optional text rendered onto the image.
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
     * @param PostRef|int $post
     * @return self
     */
    public function attachTo(PostRef|int $post): self
    {
        $this->parent = $post;

        return $this;
    }

    /**
     * Set the media item as a post's featured image after save.
     *
     * @param PostRef|int $post
     * @return self
     */
    public function featuredOn(PostRef|int $post): self
    {
        $this->featuredOn = $post;

        return $this;
    }

    /**
     * @return PostRef
     *
     * Identity rule: `attachment + post_name` (slug). Existing attachments are
     * reused without regenerating the file; missing ones get their source file
     * written into the uploads dir and registered via `wp_insert_attachment()`
     * with generated metadata where the image API is available.
     *
     * See: https://developer.wordpress.org/reference/functions/wp_insert_attachment/
     * See: https://developer.wordpress.org/reference/functions/wp_generate_attachment_metadata/
     *
     * @throws LogicException If no source is configured.
     * @throws RuntimeException If WordPress runtime functions are unavailable or save fails.
     */
    public function save(): PostRef
    {
        if (!$this->placeholder && $this->sourcePath === null) {
            throw new LogicException('Attachment source is required: call placeholder() or fromFile().');
        }

        if ($this->context->dryRun()) {
            $this->context->logger()->info(
                sprintf('Dry run attachment upsert [attachment:%s].', $this->slug)
            );

            return new PostRef(0, 'attachment', $this->slug);
        }

        if (!function_exists('get_posts') || !function_exists('wp_insert_attachment') || !function_exists('wp_upload_dir')) {
            throw new RuntimeException('WordPress runtime functions are required to save attachments.');
        }

        $existing = get_posts([
            'name' => $this->slug,
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        if (!empty($existing)) {
            $attachmentId = (int) $existing[0];
            $action = 'reused';
        } else {
            $attachmentId = $this->insertAttachment();
            $action = 'created';
        }

        if ($this->alt !== null && function_exists('update_post_meta')) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $this->alt);
        }

        if ($this->featuredOn !== null && function_exists('set_post_thumbnail')) {
            $postId = $this->featuredOn instanceof PostRef ? $this->featuredOn->id() : $this->featuredOn;
            set_post_thumbnail($postId, $attachmentId);
        }

        $this->context->logger()->debug(
            sprintf('Attachment %s [attachment:%s] as ID %d.', $action, $this->slug, $attachmentId)
        );

        return new PostRef($attachmentId, 'attachment', $this->slug);
    }

    /**
     * @return int
     *
     * @throws RuntimeException If the file cannot be written or insert fails.
     */
    private function insertAttachment(): int
    {
        $uploads = wp_upload_dir();
        $dir = (string) ($uploads['path'] ?? '');

        if ($dir === '' || (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))) {
            throw new RuntimeException('Uploads directory is not writable.');
        }

        $file = rtrim($dir, '/') . '/' . $this->slug . ($this->placeholder ? '.png' : '.' . pathinfo((string) $this->sourcePath, PATHINFO_EXTENSION));

        if ($this->placeholder) {
            $this->writePlaceholder($file);
        } else {
            if (!is_readable((string) $this->sourcePath) || !copy((string) $this->sourcePath, $file)) {
                throw new RuntimeException(sprintf('Attachment source [%s] is not readable.', (string) $this->sourcePath));
            }
        }

        $parentId = 0;
        if ($this->parent !== null) {
            $parentId = $this->parent instanceof PostRef ? $this->parent->id() : $this->parent;
        }

        $mime = $this->placeholder ? 'image/png' : (function_exists('wp_check_filetype')
            ? (string) (wp_check_filetype($file)['type'] ?? 'application/octet-stream')
            : 'application/octet-stream');

        /** @var int|\WP_Error $attachmentId */
        $attachmentId = wp_insert_attachment([
            'post_title' => $this->title ?? $this->slug,
            'post_name' => $this->slug,
            'post_mime_type' => $mime,
            'post_status' => 'inherit',
        ], $file, $parentId, true);

        if ((function_exists('is_wp_error') && is_wp_error($attachmentId)) || !is_int($attachmentId) || $attachmentId <= 0) {
            throw new RuntimeException(sprintf('Failed to save attachment [%s].', $this->slug));
        }

        $this->generateMetadata($attachmentId, $file);

        return $attachmentId;
    }

    /**
     * Write a deterministic PNG: solid colour derived from the slug hash,
     * with an optional label when GD's font support is available.
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

            $label = $this->label ?? $this->slug;
            imagestring($image, 5, 12, 12, $label, imagecolorallocate($image, 255, 255, 255));

            $written = imagepng($image, $file);
            imagedestroy($image);

            if ($written) {
                return;
            }
        }

        // GD unavailable: fall back to a minimal valid 1x1 PNG so runs stay deterministic.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        if (file_put_contents($file, (string) $png) === false) {
            throw new RuntimeException('Failed to write placeholder image.');
        }
    }

    /**
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
