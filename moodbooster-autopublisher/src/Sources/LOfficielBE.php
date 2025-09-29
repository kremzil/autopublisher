<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class LOfficielBE extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://www.lofficiel.be/feed/';
    }

    protected function sourceKey(): string
    {
        return 'lofficielbe';
    }
}
