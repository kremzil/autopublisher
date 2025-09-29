<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Util;

final class Html
{
    /**
     * @param string $html
     */
    public static function stripLinks(string $html): string
    {
        return preg_replace('/<\s*a[^>]*>(.*?)<\s*\/\s*a\s*>/is', '$1', $html) ?? $html;
    }

    public static function normalizeBody(string $html): string
    {
        $html = self::stripLinks($html);
        $allowed = [
            'p' => [],
            'h3' => [],
            'strong' => [],
            'em' => [],
            'blockquote' => [],
            'br' => [],
        ];

        return wp_kses($html, $allowed);
    }

    public static function plainText(string $html): string
    {
        $html = wp_strip_all_tags($html);

        return trim(preg_replace('/\s+/u', ' ', $html) ?? $html);
    }
}
