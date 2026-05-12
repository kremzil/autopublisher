<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class NaseKosice extends AbstractHtmlSource
{
    protected function listingUrl(): string
    {
        return 'https://nasekosice.sk/';
    }

    protected function sourceKey(): string
    {
        return 'nasekosice';
    }

    protected function isArticleUrl(string $url): bool
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return in_array($host, ['nasekosice.sk', 'www.nasekosice.sk'], true)
            && preg_match('#^/clanky/[0-9]+-[a-z0-9-]+#', $path) === 1;
    }
}
