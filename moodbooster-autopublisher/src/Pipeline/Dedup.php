<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\Util\Log;
use Moodbooster\AutoPub\Util\Settings;
use WP_Query;

final class Dedup
{
    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $options
     * @return array{action:string,post_id?:int,reason?:string}
     */
    public function evaluate(array $item, array $options): array
    {
        $fingerprint = $item['fingerprint'] ?? sha1(($item['source'] ?? '') . ($item['url'] ?? ''));
        if (!empty($options['dedupe_url'])) {
            $existing = $this->findByFingerprint($fingerprint);
            if ($existing) {
                Log::info($item['source'] ?? 'source', 'dedupe', 'Skipping due to fingerprint', [
                    'post_id' => $existing,
                    'fingerprint' => $fingerprint,
                ]);

                return ['action' => 'skip', 'post_id' => $existing, 'reason' => 'fingerprint'];
            }
        }

        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            return ['action' => 'skip', 'reason' => 'no_title'];
        }

        if ($this->isBlocklisted($title, $options['title_blocklist'] ?? [])) {
            return ['action' => 'skip', 'reason' => 'blocklist'];
        }

        $recentSimilar = null;
        if (!empty($options['dedupe_title'])) {
            $recentSimilar = $this->findSimilarTitle($title);
            if ($recentSimilar && $recentSimilar['score'] >= 0.9) {
                if ($recentSimilar['age_days'] !== null && $recentSimilar['age_days'] <= 30) {
                    return ['action' => 'update', 'post_id' => $recentSimilar['post_id'], 'reason' => 'update_recent'];
                }

                return ['action' => 'skip', 'post_id' => $recentSimilar['post_id'], 'reason' => 'title_similarity'];
            }
        }

        if (!empty($options['dedupe_embeddings'])) {
            $embedding = $this->vector($title);
            $similar = $this->findEmbeddingMatch($embedding);
            if ($similar && $similar['score'] >= 0.9) {
                return ['action' => 'skip', 'post_id' => $similar['post_id'], 'reason' => 'embedding'];
            }
        }

        return ['action' => 'create', 'reason' => 'fresh'];
    }

    public function findByFingerprint(string $fp): ?int
    {
        $query = new WP_Query([
            'post_type' => 'post',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_mb_source_fp',
                    'value' => $fp,
                ],
            ],
            'fields' => 'ids',
            'post_status' => ['publish', 'draft', 'pending', 'future'],
        ]);

        if (!empty($query->posts[0])) {
            return (int) $query->posts[0];
        }

        return null;
    }

    /**
     * @return array{post_id:int,score:float,age_days:?int}|null
     */
    private function findSimilarTitle(string $title): ?array
    {
        $recent = new WP_Query([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        $best = null;
        foreach ($recent->posts as $postId) {
            $postId = (int) $postId;
            $postTitle = get_the_title($postId);
            if (!$postTitle) {
                continue;
            }

            similar_text(mb_strtolower($title), mb_strtolower($postTitle), $percent);
            $score = $percent / 100;
            if ($score > 0.9) {
                $post = get_post($postId);
                $ageDays = null;
                if ($post && $post->post_date_gmt) {
                    $ageDays = (int) floor((time() - strtotime($post->post_date_gmt)) / DAY_IN_SECONDS);
                }
                if (!$best || $score > $best['score']) {
                    $best = [
                        'post_id' => $postId,
                        'score' => $score,
                        'age_days' => $ageDays,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * @param array<string, float> $vector
     * @return array{post_id:int,score:float}|null
     */
    private function findEmbeddingMatch(array $vector): ?array
    {
        if ($vector === []) {
            return null;
        }

        global $wpdb;
        $metaKey = '_mb_title_emb';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT 200",
                $metaKey
            )
        );

        $best = null;
        if ($results) {
            foreach ($results as $row) {
                $stored = json_decode((string) $row->meta_value, true);
                if (!is_array($stored)) {
                    continue;
                }
                $score = $this->cosineSimilarity($vector, $stored);
                if ($score >= 0.9) {
                    $best = ['post_id' => (int) $row->post_id, 'score' => $score];
                    break;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<string> $blocklist
     */
    private function isBlocklisted(string $title, array $blocklist): bool
    {
        foreach ($blocklist as $term) {
            if ($term !== '' && stripos($title, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, float>
     */
    public function vector(string $title): array
    {
        $title = mb_strtolower($title);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY);
        $vector = [];
        if (!$words) {
            return $vector;
        }

        foreach ($words as $word) {
            $vector[$word] = ($vector[$word] ?? 0) + 1;
        }

        $norm = sqrt(array_sum(array_map(static fn($v) => $v * $v, $vector)));
        if ($norm > 0) {
            foreach ($vector as $word => $value) {
                $vector[$word] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $word => $value) {
            if (isset($b[$word])) {
                $sum += $value * $b[$word];
            }
        }

        return min(1.0, max(0.0, $sum));
    }
}
