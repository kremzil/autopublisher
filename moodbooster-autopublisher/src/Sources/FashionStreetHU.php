<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class FashionStreetHU extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://fashionstreetonline.hu/feed/';
    }

    protected function sourceKey(): string
    {
        return 'fashionstreethu';
    }
}
