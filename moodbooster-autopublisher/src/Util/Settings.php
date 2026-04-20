<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Util;

final class Settings
{
    public const OPTION_KEY = 'mb_autopub_opts';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'api_key' => '',
            'sources' => [
                'europawire' => true,
                'ellepolska' => true,
                'fashionpost' => true,
                'miastokobiet' => false,
                'marieclairehu' => false,
                'fashionstreethu' => false,
                'lofficielbe' => false,
                'togethermag' => false,
            ],
            'fetch_mode' => 'rss_html',
            'publish_mode' => 'draft',
            'cadence' => 'daily',
            'max_per_run' => 3,
            'category' => 0,
            'image_min_width' => 1200,
            'image_min_height' => 675,
            'image_force_ratio' => true,
            'image_skip_under_min' => true,
            'dedupe_url' => true,
            'dedupe_title' => true,
            'dedupe_embeddings' => true,
            'title_blocklist' => [],
            'attribution_footer' => false,
            'log_retention_days' => 30,
            'model_brief' => 'gpt-4o-mini',
            'model_plan' => 'gpt-4o-mini',
            'model_write' => 'gpt-4o-mini',
            'model_check' => 'gpt-4o-mini',
            'model_headline' => 'gpt-4o-mini',
            'repair_enabled' => true,
            'dashboard_per_page' => 50,
            'quality_threshold' => 0.7,
            'editorial_style' => 'Magazine news: clear Slovak lead, useful context, no tabloid claims, no unsupported inferences.',
            'enable_gated_publish' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION_KEY, []);
        if (!\is_array($saved)) {
            $saved = [];
        }

        return array_merge(self::defaults(), $saved);
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $options = self::all();

        return $options[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public static function modelMap(): array
    {
        $options = self::all();
        $defaults = self::defaults();

        return [
            'brief' => (string) ($options['model_brief'] ?: $defaults['model_brief']),
            'plan' => (string) ($options['model_plan'] ?: $defaults['model_plan']),
            'write' => (string) ($options['model_write'] ?: $defaults['model_write']),
            'check' => (string) ($options['model_check'] ?: $defaults['model_check']),
            'headline' => (string) ($options['model_headline'] ?: $defaults['model_headline']),
        ];
    }
}
