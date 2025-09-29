<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class TogetherMagazineBE extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://togethermag.eu/feed/';
    }

    protected function sourceKey(): string
    {
        return 'togethermag';
    }
}
