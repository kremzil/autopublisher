<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class FashionPost extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://fashionpost.pl/feed/';
    }

    protected function sourceKey(): string
    {
        return 'fashionpost';
    }
}
