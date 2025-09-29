<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class MiastoKobiet extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://www.miastokobiet.pl/feed/';
    }

    protected function sourceKey(): string
    {
        return 'miastokobiet';
    }
}
