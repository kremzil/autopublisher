<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class EuropaWire extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://news.europawire.eu/feed/';
    }

    protected function sourceKey(): string
    {
        return 'europawire';
    }
}
