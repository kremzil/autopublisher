<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\Http\Client;
use Moodbooster\AutoPub\Util\ContentExtractor;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Images
{
    private Client $http;

    public function __construct(Client $http)
    {
        $this->http = $http;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function pick(array $item, array $options, string $contentHtml)
    {
        $candidates = [];
        if (!empty($item['image_url'])) {
            $candidates[] = $item['image_url'];
        }

        $extracted = ContentExtractor::extract($contentHtml, $item['url'] ?? null);
        if (!empty($extracted['image'])) {
            $candidates[] = $extracted['image'];
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        if ($candidates === []) {
            return new WP_Error('mb_no_image', __('No image candidates found', 'moodbooster-autopub'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($candidates as $url) {
            $existing = $this->findExistingAttachment($url);
            if ($existing) {
                $meta = wp_get_attachment_metadata($existing);
                if (is_array($meta)) {
                    return [
                        'attachment_id' => $existing,
                        'source_url' => $url,
                        'width' => $meta['width'] ?? 0,
                        'height' => $meta['height'] ?? 0,
                        'reused' => true,
                        'force_ratio' => !empty($options['image_force_ratio']),
                    ];
                }
            }

            $prepared = $this->downloadCandidate($url, $options);
            if (!is_wp_error($prepared)) {
                return $prepared;
            }

            Log::warn('images', 'candidate', 'Skipping image candidate', [
                'url' => $url,
                'reason' => $prepared instanceof WP_Error ? $prepared->get_error_message() : 'unknown',
            ]);
        }

        return new WP_Error('mb_image_failed', __('Unable to download a valid image', 'moodbooster-autopub'));
    }

    /**
     * @param array<string, mixed> $prepared
     * @return int|WP_Error
     */
    public function attach(array $prepared, int $postId)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if (!empty($prepared['attachment_id'])) {
            $attachmentId = (int) $prepared['attachment_id'];
            if ($attachmentId > 0 && $postId > 0) {
                wp_update_post([
                    'ID' => $attachmentId,
                    'post_parent' => $postId,
                ]);
                set_post_thumbnail($postId, $attachmentId);
            }

            return $attachmentId;
        }

        $fileArray = [
            'name' => $prepared['file_name'],
            'type' => $prepared['mime'],
            'tmp_name' => $prepared['tmp_path'],
            'error' => 0,
            'size' => $prepared['size'],
        ];

        $attachmentId = media_handle_sideload($fileArray, $postId, null, ['test_form' => false]);
        if (is_wp_error($attachmentId)) {
            Log::error('images', 'attach', 'Failed to sideload image', [
                'error' => $attachmentId->get_error_message(),
                'source_url' => $prepared['source_url'],
            ]);

            if (is_string($prepared['tmp_path']) && file_exists($prepared['tmp_path'])) {
                @unlink($prepared['tmp_path']);
            }

            return $attachmentId;
        }

        update_post_meta($attachmentId, '_mb_image_source_url', esc_url_raw($prepared['source_url']));
        $this->ensureRatio($attachmentId, !empty($prepared['force_ratio']));
        set_post_thumbnail($postId, $attachmentId);

        if (is_string($prepared['tmp_path']) && file_exists($prepared['tmp_path'])) {
            @unlink($prepared['tmp_path']);
        }

        return $attachmentId;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function downloadCandidate(string $url, array $options)
    {
        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $size = filesize($tmp) ?: 0;
        $info = getimagesize($tmp);
        if (!$info) {
            @unlink($tmp);

            return new WP_Error('mb_image_meta', __('Unable to read image metadata', 'moodbooster-autopub'));
        }

        $width = $info[0] ?? 0;
        $height = $info[1] ?? 0;
        $mime = $info['mime'] ?? 'image/jpeg';

        $minWidth = (int) ($options['image_min_width'] ?? 1200);
        $minHeight = (int) ($options['image_min_height'] ?? 675);

        if (($width < $minWidth || $height < $minHeight) && !empty($options['image_skip_under_min'])) {
            @unlink($tmp);

            return new WP_Error('mb_image_small', __('Candidate image below minimum dimensions', 'moodbooster-autopub'));
        }

        $fileName = basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg');

        return [
            'tmp_path' => $tmp,
            'file_name' => $fileName,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'source_url' => $url,
            'force_ratio' => !empty($options['image_force_ratio']),
        ];
    }

    private function ensureRatio(int $attachmentId, bool $force): void
    {
        if (!$force) {
            return;
        }

        $file = get_attached_file($attachmentId);
        if (!$file || !file_exists($file)) {
            return;
        }

        $meta = wp_get_attachment_metadata($attachmentId);
        if (!is_array($meta)) {
            return;
        }

        $width = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);
        if ($width === 0 || $height === 0) {
            return;
        }

        $targetRatio = 16 / 9;
        $currentRatio = $width / max(1, $height);
        if (abs($currentRatio - $targetRatio) < 0.05) {
            return;
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return;
        }

        $newHeight = (int) round($width / $targetRatio);
        if ($newHeight > $height) {
            $newWidth = (int) round($height * $targetRatio);
            $x = (int) max(0, ($width - $newWidth) / 2);
            $editor->crop($x, 0, $newWidth, $height);
        } else {
            $y = (int) max(0, ($height - $newHeight) / 2);
            $editor->crop(0, $y, $width, $newHeight);
        }

        $editor->save($file);
        wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $file));
    }

    private function findExistingAttachment(string $url): ?int
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_mb_image_source_url',
                    'value' => esc_url_raw($url),
                ],
            ],
            'fields' => 'ids',
        ]);

        if (!empty($query->posts[0])) {
            return (int) $query->posts[0];
        }

        return null;
    }
}