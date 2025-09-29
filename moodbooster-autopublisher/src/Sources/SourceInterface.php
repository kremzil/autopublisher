<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

interface SourceInterface
{
    /**
     * @return array<int, array{source:string,url:string,title:string,dt?:string,author?:string,summary?:string,image_url?:string,fingerprint?:string}>
     */
    public function fetch(int $max = 20): array;
}
