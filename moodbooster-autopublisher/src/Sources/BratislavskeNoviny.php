<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class BratislavskeNoviny extends AbstractHtmlSource
{
    protected function listingUrl(): string
    {
        return 'https://www.bratislavskenoviny.sk/';
    }

    protected function sourceKey(): string
    {
        return 'bratislavskenoviny';
    }

    protected function isArticleUrl(string $url): bool
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return $host === 'www.bratislavskenoviny.sk'
            && preg_match('#^/[a-z0-9-]+/[0-9]+-[a-z0-9-]+#', $path) === 1;
    }
}
