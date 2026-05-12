<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Sources;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Moodbooster\AutoPub\Http\Client;
use Moodbooster\AutoPub\Util\ContentExtractor;
use Moodbooster\AutoPub\Util\Html;
use Moodbooster\AutoPub\Util\Log;

abstract class AbstractHtmlSource implements SourceInterface
{
    protected Client $http;

    public function __construct(Client $http)
    {
        $this->http = $http;
    }

    abstract protected function listingUrl(): string;

    abstract protected function sourceKey(): string;

    abstract protected function isArticleUrl(string $url): bool;

    public function fetch(int $max = 20): array
    {
        $response = $this->http->get($this->listingUrl());
        if (is_wp_error($response)) {
            Log::error($this->sourceKey(), 'fetch', 'HTML listing fetch failed', [
                'error' => $response->get_error_message(),
            ]);

            return [];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            Log::error($this->sourceKey(), 'fetch', 'HTML listing returned HTTP error', [
                'status' => $code,
            ]);

            return [];
        }

        $urls = $this->articleUrls(wp_remote_retrieve_body($response), $this->listingUrl(), $max);
        $items = [];
        foreach ($urls as $url) {
            $item = $this->articleItem($url);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
            if (count($items) >= $max) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    protected function articleUrls(string $html, string $baseUrl, int $max): array
    {
        $doc = $this->parseHtml($html);
        if (!$doc) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $links = $xpath->query('//a[@href]');
        if (!$links instanceof \DOMNodeList) {
            return [];
        }

        $seen = [];
        $urls = [];
        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $url = $this->absUrl($link->getAttribute('href'), $baseUrl);
            if (!$this->isArticleUrl($url)) {
                continue;
            }

            $key = strtolower(strtok($url, '#') ?: $url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $urls[] = $url;
            if (count($urls) >= max(1, $max * 2)) {
                break;
            }
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function articleItem(string $url): ?array
    {
        $response = $this->http->get($url);
        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        $extracted = ContentExtractor::extract($body, $url);
        $title = trim((string) ($extracted['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $content = (string) ($extracted['content'] ?? '');
        if ($this->isBlockedArticle($title, $content)) {
            Log::info($this->sourceKey(), 'fetch', 'Skipping blocked partner article', [
                'url' => $url,
                'reason' => 'tasr',
            ]);

            return null;
        }

        $source = $this->sourceKey();

        return [
            'source' => $source,
            'url' => esc_url_raw($url),
            'title' => $title,
            'dt' => $this->publishedDate($body),
            'summary' => mb_substr(Html::plainText($content), 0, 500),
            'image_url' => $extracted['image'] ?? null,
            'fingerprint' => sha1($source . $url),
            'processing_mode' => 'import_only',
        ];
    }

    protected function isBlockedArticle(string $title, string $content): bool
    {
        $text = Html::plainText($title . ' ' . $content);
        $text = preg_replace('/Všetky práva vyhradené\\..*?TASR.*?autorského zákona\\./isu', ' ', $text) ?? $text;

        return preg_match('/(?:^|[\\s(])TASR(?:[\\s).,:;]|$)/u', $text) === 1
            || stripos($text, 'Tlačová agentúra Slovenskej republiky') !== false;
    }

    protected function publishedDate(string $html): ?string
    {
        $doc = $this->parseHtml($html);
        if (!$doc) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//meta[@property="article:published_time" or @name="date" or @name="pubdate"] | //time[@datetime]');
        if (!$nodes instanceof \DOMNodeList || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (!$node instanceof DOMElement) {
            return null;
        }

        $value = $node->getAttribute('datetime') ?: $node->getAttribute('content');
        $timestamp = strtotime($value);

        return $timestamp ? gmdate('c', $timestamp) : null;
    }

    protected function parseHtml(string $html): ?DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $loaded ? $doc : null;
    }

    protected function absUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return esc_url_raw($url);
        }

        if (strpos($url, '//') === 0) {
            $scheme = wp_parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return esc_url_raw($scheme . ':' . $url);
        }

        $parts = wp_parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return esc_url_raw($url);
        }

        $host = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        if (strpos($url, '/') === 0) {
            return esc_url_raw($host . $url);
        }

        $path = $parts['path'] ?? '/';
        return esc_url_raw($host . trailingslashit(dirname($path)) . ltrim($url, './'));
    }
}
