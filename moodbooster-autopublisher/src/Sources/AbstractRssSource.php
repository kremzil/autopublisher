<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

use Moodbooster\AutoPub\Http\Client;
use Moodbooster\AutoPub\Util\Html;
use Moodbooster\AutoPub\Util\Log;
use SimplePie_Item;
use WP_Error;

abstract class AbstractRssSource implements SourceInterface
{
    protected Client $http;

    public function __construct(Client $http)
    {
        $this->http = $http;
    }

    abstract protected function feedUrl(): string;

    abstract protected function sourceKey(): string;

    public function fetch(int $max = 20): array
    {
        require_once ABSPATH . WPINC . '/feed.php';

        $feed = fetch_feed($this->feedUrl());
        if (is_wp_error($feed)) {
            Log::error($this->sourceKey(), 'fetch', 'RSS fetch failed', [
                'error' => $feed->get_error_message(),
            ]);

            return [];
        }

        $items = [];
        $quantity = min($feed->get_item_quantity($max), $max);
        for ($i = 0; $i < $quantity; $i++) {
            $item = $feed->get_item($i);
            if (!$item instanceof SimplePie_Item) {
                continue;
            }

            $url = $item->get_permalink();
            if (!$url) {
                continue;
            }

            $title = trim((string) $item->get_title());
            if ($title === '') {
                continue;
            }

            $data = [
                'source' => $this->sourceKey(),
                'url' => esc_url_raw($url),
                'title' => $title,
                'dt' => $item->get_date('c') ?: null,
                'author' => $this->authorName($item),
                'summary' => Html::plainText($item->get_description() ?: ''),
                'image_url' => $this->extractImage($item),
            ];
            $data['fingerprint'] = sha1($this->sourceKey() . $data['url']);

            $items[] = $data;
        }

        if ($items === []) {
            $fallback = $this->fallbackFetch($max);
            if ($fallback !== []) {
                return $fallback;
            }
        }

        return $items;
    }

    protected function authorName(SimplePie_Item $item): ?string
    {
        $author = $item->get_author();
        if ($author && $author->get_name()) {
            return $author->get_name();
        }

        return null;
    }

    protected function extractImage(SimplePie_Item $item): ?string
    {
        $enclosure = $item->get_enclosure();
        if ($enclosure && $enclosure->get_link()) {
            return esc_url_raw($enclosure->get_link());
        }

        $media = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
        if (is_array($media)) {
            foreach ($media as $tag) {
                if (!empty($tag['attribs']['']['url'])) {
                    return esc_url_raw((string) $tag['attribs']['']['url']);
                }
            }
        }

        $thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (is_array($thumbnail)) {
            foreach ($thumbnail as $tag) {
                if (!empty($tag['attribs']['']['url'])) {
                    return esc_url_raw((string) $tag['attribs']['']['url']);
                }
            }
        }

        return null;
    }

    /**
     * Override to provide HTML fallback scraping.
     *
     * @return array<int, array{source:string,url:string,title:string,dt?:string,author?:string,summary?:string,image_url?:string,fingerprint?:string}>
     */
    protected function fallbackFetch(int $max): array
    {
        return [];
    }
}