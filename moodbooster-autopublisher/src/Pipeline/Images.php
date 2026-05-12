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
     * @param array<string, mixed> $context
     * @return array<string, mixed>|WP_Error
     */
    public function pick(array $item, array $options, string $contentHtml, array $context = [])
    {
        $candidates = $this->buildCandidates($item, $contentHtml, $context);
        if ($candidates === []) {
            $error = new WP_Error('mb_no_image', __('No image candidates found', 'moodbooster-autopub'));
            $error->add_data(['candidates' => []], 'mb_no_image');

            return $error;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attempted = [];
        foreach ($candidates as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $existing = $this->findExistingAttachment($url);
            if ($existing) {
                $meta = wp_get_attachment_metadata($existing);
                if (is_array($meta)) {
                    $candidate['result'] = 'reused';
                    $attempted[] = $candidate;

                    return [
                        'attachment_id' => $existing,
                        'source_url' => $url,
                        'width' => $meta['width'] ?? 0,
                        'height' => $meta['height'] ?? 0,
                        'reused' => true,
                        'force_ratio' => !empty($options['image_force_ratio']),
                        'selection' => $candidate,
                        'candidates' => $this->candidateReport($attempted, $candidates),
                    ];
                }
            }

            $prepared = $this->downloadCandidate($url, $options);
            if (!is_wp_error($prepared)) {
                $candidate['result'] = 'downloaded';
                $attempted[] = $candidate;

                $prepared['selection'] = $candidate;
                $prepared['candidates'] = $this->candidateReport($attempted, $candidates);

                return $prepared;
            }

            $candidate['result'] = 'skipped';
            $candidate['download_error'] = $prepared instanceof WP_Error ? $prepared->get_error_message() : 'unknown';
            $attempted[] = $candidate;

            Log::warn('images', 'candidate', 'Skipping image candidate', [
                'url' => $url,
                'score' => $candidate['score'] ?? 0,
                'reasons' => $candidate['reasons'] ?? [],
                'reason' => $prepared instanceof WP_Error ? $prepared->get_error_message() : 'unknown',
            ]);
        }

        $error = new WP_Error('mb_image_failed', __('Unable to download a valid image', 'moodbooster-autopub'));
        $error->add_data(['candidates' => $this->candidateReport($attempted, $candidates)], 'mb_image_failed');

        return $error;
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
            if (!empty($prepared['source_url'])) {
                update_post_meta($attachmentId, '_mb_image_source_url', esc_url_raw($prepared['source_url']));
            }
            if (!empty($prepared['selection']) && is_array($prepared['selection'])) {
                update_post_meta($attachmentId, '_mb_image_selection', wp_json_encode($prepared['selection'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
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
        if (!empty($prepared['selection']) && is_array($prepared['selection'])) {
            update_post_meta($attachmentId, '_mb_image_selection', wp_json_encode($prepared['selection'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->ensureRatio($attachmentId, !empty($prepared['force_ratio']));
        set_post_thumbnail($postId, $attachmentId);

        if (is_string($prepared['tmp_path']) && file_exists($prepared['tmp_path'])) {
            @unlink($prepared['tmp_path']);
        }

        return $attachmentId;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidates(array $item, string $contentHtml, array $context): array
    {
        $raw = [];
        if (!empty($item['image_url'])) {
            $raw[] = [
                'url' => esc_url_raw((string) $item['image_url']),
                'source' => 'rss_image',
                'alt' => '',
                'title' => '',
                'class' => '',
                'width' => 0,
                'height' => 0,
                'position' => 0,
            ];
        }

        foreach (ContentExtractor::imageCandidates($contentHtml, $item['url'] ?? null) as $candidate) {
            $raw[] = $candidate;
        }

        $terms = $this->contextTerms($item, $context);
        $seen = [];
        $scored = [];
        foreach ($raw as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $key = $this->candidateKey($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $scoredCandidate = $this->scoreCandidate($candidate, $terms);
            if (!empty($scoredCandidate['skip'])) {
                Log::info('images', 'candidate', 'Dropping weak image candidate', [
                    'url' => $url,
                    'reasons' => $scoredCandidate['reasons'] ?? [],
                ]);
                continue;
            }

            $scored[] = $scoredCandidate;
        }

        usort($scored, static function (array $a, array $b): int {
            $scoreA = (int) ($a['score'] ?? 0);
            $scoreB = (int) ($b['score'] ?? 0);
            if ($scoreA === $scoreB) {
                return (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0);
            }

            return $scoreB <=> $scoreA;
        });

        return $scored;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, string> $terms
     * @return array<string, mixed>
     */
    private function scoreCandidate(array $candidate, array $terms): array
    {
        $score = 0;
        $reasons = [];
        $url = (string) ($candidate['url'] ?? '');
        $source = (string) ($candidate['source'] ?? 'unknown');
        $searchText = $this->normalizeSearchText(implode(' ', [
            $url,
            (string) ($candidate['alt'] ?? ''),
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['class'] ?? ''),
        ]));

        if ($this->unsupportedImageUrl($url)) {
            $candidate['skip'] = true;
            $candidate['score'] = -1000;
            $candidate['reasons'] = ['unsupported_url'];

            return $candidate;
        }

        $sourceWeights = [
            'rss_image' => 36,
            'og_image' => 32,
            'twitter_image' => 28,
            'content_img' => 24,
            'html_img' => 8,
            'meta_image' => 20,
        ];
        $sourceScore = $sourceWeights[$source] ?? 8;
        $score += $sourceScore;
        $reasons[] = $source;

        $position = max(0, (int) ($candidate['position'] ?? 0));
        $positionScore = max(0, 18 - min(18, $position));
        if ($positionScore > 0) {
            $score += $positionScore;
            $reasons[] = 'early_position';
        }

        $width = (int) ($candidate['width'] ?? 0);
        $height = (int) ($candidate['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            if ($width <= 4 || $height <= 4) {
                $candidate['skip'] = true;
                $candidate['score'] = -1000;
                $candidate['reasons'] = ['tracking_pixel'];

                return $candidate;
            }

            if ($width >= 1200 && $height >= 675) {
                $score += 18;
                $reasons[] = 'declared_large';
            } elseif ($width >= 600 && $height >= 300) {
                $score += 10;
                $reasons[] = 'declared_medium';
            } elseif ($width < 240 || $height < 160) {
                $score -= 32;
                $reasons[] = 'penalty_small_declared';
            }

            $ratio = $width / max(1, $height);
            if ($ratio >= 1.2 && $ratio <= 2.2) {
                $score += 6;
                $reasons[] = 'news_ratio';
            }
            if (abs($ratio - (16 / 9)) < 0.2) {
                $score += 5;
                $reasons[] = 'near_16_9';
            }
        }

        $hardDropTerms = ['favicon', 'sprite', 'spacer', 'tracking', 'pixel', 'placeholder', 'avatar'];
        foreach ($hardDropTerms as $term) {
            if (strpos($searchText, $term) !== false) {
                $candidate['skip'] = true;
                $candidate['score'] = -1000;
                $candidate['reasons'] = ['drop_' . $term];

                return $candidate;
            }
        }

        $penaltyTerms = ['logo', 'icon', 'author', 'profile', 'thumbnail', 'thumb', 'badge', 'share', 'social', 'advert', 'banner', 'promo'];
        foreach ($penaltyTerms as $term) {
            if (strpos($searchText, $term) !== false) {
                $score -= ($term === 'thumbnail' || $term === 'thumb') ? 24 : 16;
                $reasons[] = 'penalty_' . $term;
            }
        }

        $matches = 0;
        foreach ($terms as $term) {
            if ($term !== '' && strpos($searchText, $term) !== false) {
                $matches++;
            }
        }
        if ($matches > 0) {
            $score += min(24, $matches * 4);
            $reasons[] = 'matched_terms_' . $matches;
        }

        $candidate['score'] = $score;
        $candidate['reasons'] = array_values(array_unique($reasons));

        return $candidate;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function contextTerms(array $item, array $context): array
    {
        $texts = [
            (string) ($item['title'] ?? ''),
            (string) ($item['source_title'] ?? ''),
        ];

        foreach (['plan', 'draft', 'headline', 'brief'] as $key) {
            if (empty($context[$key]) || !is_array($context[$key])) {
                continue;
            }

            foreach (['image_subject', 'headline', 'title', 'seo_title', 'image_caption', 'main_event', 'where', 'who'] as $field) {
                if (!empty($context[$key][$field]) && is_scalar($context[$key][$field])) {
                    $texts[] = (string) $context[$key][$field];
                }
            }
        }

        $normalized = $this->normalizeSearchText(implode(' ', $texts));
        $parts = preg_split('/[^a-z0-9]+/i', $normalized) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (strlen($part) < 4 || is_numeric($part)) {
                continue;
            }
            $terms[$part] = true;
            if (count($terms) >= 16) {
                break;
            }
        }

        return array_keys($terms);
    }

    private function normalizeSearchText(string $text): string
    {
        $text = wp_strip_all_tags($text);
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }

        return strtolower($text);
    }

    private function unsupportedImageUrl(string $url): bool
    {
        $normalized = strtolower(trim($url));
        if ($normalized === '' || strpos($normalized, 'data:') === 0 || strpos($normalized, 'blob:') === 0) {
            return true;
        }

        $path = strtolower((string) parse_url($normalized, PHP_URL_PATH));
        foreach (['.svg', '.gif', '.ico'] as $extension) {
            if (substr($path, -strlen($extension)) === $extension) {
                return true;
            }
        }

        return false;
    }

    private function candidateKey(string $url): string
    {
        return strtolower(strtok($url, '#') ?: $url);
    }

    /**
     * @param array<int, array<string, mixed>> $attempted
     * @param array<int, array<string, mixed>> $all
     * @return array<int, array<string, mixed>>
     */
    private function candidateReport(array $attempted, array $all): array
    {
        $byKey = [];
        foreach ($attempted as $candidate) {
            $byKey[$this->candidateKey((string) ($candidate['url'] ?? ''))] = $candidate;
        }

        foreach ($all as $candidate) {
            $key = $this->candidateKey((string) ($candidate['url'] ?? ''));
            if (!isset($byKey[$key])) {
                $byKey[$key] = $candidate;
            }
        }

        return array_slice(array_values($byKey), 0, 20);
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
        if (pathinfo($fileName, PATHINFO_EXTENSION) === '') {
            $fileName .= $this->extensionForMime((string) $mime);
        }

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

    private function extensionForMime(string $mime): string
    {
        $mime = strtolower($mime);
        $map = [
            'image/jpeg' => '.jpg',
            'image/jpg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
        ];

        return $map[$mime] ?? '.jpg';
    }
}
