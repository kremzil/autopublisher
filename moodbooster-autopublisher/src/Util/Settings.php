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
}
