<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

final class MarieClaireHU extends AbstractRssSource
{
    protected function feedUrl(): string
    {
        return 'https://marieclaire.hu/feed/';
    }

    protected function sourceKey(): string
    {
        return 'marieclairehu';
    }
}
