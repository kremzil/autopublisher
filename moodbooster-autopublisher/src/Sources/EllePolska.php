<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class EllePolska extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://www.elle.pl/rss';
    }

    protected function sourceKey(): string
    {
        return 'ellepolska';
    }
}
