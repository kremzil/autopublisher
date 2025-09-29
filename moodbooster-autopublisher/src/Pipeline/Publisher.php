<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\Util\Html;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Publisher
{
    private Dedup $dedup;

    public function __construct(Dedup $dedup)
    {
        $this->dedup = $dedup;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $options
     * @param array<string, mixed> $editor
     * @return array<string, mixed>|WP_Error
     */
    public function publish(array $item, array $plan, array $draft, array $options, array $editor)
    {
        $title = $editor['fixes_suggested']['headline_to_use'] ?? ($draft['seo_title'] ?? ($draft['title_variants'][0] ?? $item['title'] ?? ''));
        $title = sanitize_text_field($title);

        $excerpt = sanitize_textarea_field($draft['excerpt'] ?? Html::plainText($draft['body_html'] ?? ''));
        $status = ($options['publish_mode'] ?? 'draft') === 'publish' && !empty($editor['approval']) ? 'publish' : 'draft';

        $content = (string) ($draft['body_html'] ?? '');
        if (strlen(strip_tags($content)) < 1200) {
            $status = 'draft';
        }

        if (!empty($options['attribution_footer'])) {
            $content .= sprintf('<p><em>%s</em> <a href="%s" rel="noopener" target="_blank">%s</a></p>',
                esc_html__('Zdroj:', 'moodbooster-autopub'),
                esc_url($item['url'] ?? ''),
                esc_html(parse_url($item['url'] ?? '', PHP_URL_HOST) ?: '')
            );
        }

        $postArgs = [
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status' => $status,
            'post_type' => 'post',
            'post_author' => get_current_user_id() ?: 1,
        ];

        if (!empty($item['dt'])) {
            $postArgs['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime((string) $item['dt']));
            $postArgs['post_date'] = get_date_from_gmt($postArgs['post_date_gmt']);
        }

        /** @var array<string, mixed> $postArgs */
        $postArgs = apply_filters('moodbooster_autopub_post_args', $postArgs, $item, $plan, $draft);

        $postId = wp_insert_post($postArgs, true);
        if (is_wp_error($postId)) {
            return $postId;
        }

        if (!empty($options['category'])) {
            wp_set_post_categories($postId, [(int) $options['category']]);
        }

        $tags = $draft['tags'] ?? [];
        if (is_array($tags) && !empty($tags)) {
            wp_set_post_tags($postId, $tags, false);
        }

        $meta = [
            '_mb_source' => sanitize_text_field((string) ($item['source'] ?? 'unknown')),
            '_mb_source_url' => esc_url_raw((string) ($item['url'] ?? '')),
            '_mb_source_fp' => $item['fingerprint'] ?? sha1(($item['source'] ?? '') . ($item['url'] ?? '')),
            '_mb_author_original' => sanitize_text_field((string) ($item['author'] ?? '')),
            '_mb_published_original' => sanitize_text_field((string) ($item['dt'] ?? '')),
            '_mb_image_source_url' => $item['image_url'] ?? '',
            '_mb_pipeline_version' => \Moodbooster\AutoPub\VERSION,
        ];

        if (!empty($editor['quality_scores'])) {
            $meta['_mb_quality'] = wp_json_encode($editor['quality_scores']);
        }

        $embedding = $this->dedup->vector($title);
        if ($embedding !== []) {
            $meta['_mb_title_emb'] = wp_json_encode($embedding);
        }

        if (!empty($draft['citations'])) {
            $meta['_mb_citations'] = wp_json_encode($draft['citations']);
        }

        if (!empty($plan['internal_links'])) {
            $meta['_mb_plan_internal_links'] = wp_json_encode($plan['internal_links']);
        }

        $meta = apply_filters('moodbooster_autopub_post_meta', $meta, $item, $plan, $draft, $editor);
        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }

        if (empty($editor['approval'])) {
            update_post_meta($postId, '_mb_needs_review', $editor['reasons'] ?? []);
        }

        $this->maybeInsertUpdateBlock($postId, $plan, $item);

        Log::info($item['source'] ?? 'source', 'publish', 'Post created', [
            'post_id' => $postId,
            'status' => $status,
        ]);

        return [
            'post_id' => $postId,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $item
     */
    private function maybeInsertUpdateBlock(int $postId, array $plan, array $item): void
    {
        if (empty($plan['update_target_url'])) {
            return;
        }

        $target = url_to_postid((string) $plan['update_target_url']);
        if ($target <= 0) {
            return;
        }

        $summary = sprintf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__('Aktualizácia:', 'moodbooster-autopub'),
            esc_html($item['summary'] ?? '')
        );

        $existing = get_post_field('post_content', $target);
        if ($existing) {
            wp_update_post([
                'ID' => $target,
                'post_content' => $summary . $existing,
            ]);
        }
    }
}