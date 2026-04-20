<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Util;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class ContentExtractor
{
    /**
     * @return array{content:string,image?:string}
     */
    public static function extract(string $html, ?string $baseUrl = null): array
    {
        $doc = self::parseHtml($html);
        if (!$doc) {
            return ['content' => Html::normalizeBody($html)];
        }

        self::removeNodes($doc, ['script', 'style', 'noscript', 'iframe', 'form', 'nav', 'header', 'footer']);

        $contentNode = self::findContentNode($doc);

        if (!$contentNode instanceof DOMElement) {
            $xpath = new DOMXPath($doc);
            $paragraphs = $xpath->query('//p');
            $buffer = '';
            if ($paragraphs instanceof \DOMNodeList) {
                foreach ($paragraphs as $p) {
                    $buffer .= $doc->saveHTML($p);
                }
            }

            return [
                'content' => Html::normalizeBody($buffer),
                'image' => self::findFirstImage($doc, $baseUrl),
            ];
        }

        $htmlContent = $doc->saveHTML($contentNode) ?: '';

        return [
            'content' => Html::normalizeBody($htmlContent),
            'image' => self::findFirstImage($contentNode, $baseUrl),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function imageCandidates(string $html, ?string $baseUrl = null): array
    {
        $doc = self::parseHtml($html);
        if (!$doc) {
            return [];
        }

        self::removeNodes($doc, ['script', 'style', 'noscript', 'iframe', 'form']);

        $xpath = new DOMXPath($doc);
        $candidates = [];

        self::collectMetaImageCandidates($candidates, $xpath, $baseUrl);

        $contentNode = self::findContentNode($doc);
        if ($contentNode instanceof DOMElement) {
            self::collectImgCandidates($candidates, $xpath, $contentNode, $baseUrl, 'content_img');
        }

        self::collectImgCandidates($candidates, $xpath, $doc, $baseUrl, 'html_img');

        return self::dedupeCandidates($candidates);
    }

    private static function parseHtml(string $html): ?DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $loaded ? $doc : null;
    }

    private static function findContentNode(DOMDocument $doc): ?DOMElement
    {
        $xpath = new DOMXPath($doc);
        $article = $xpath->query('//article');
        if ($article instanceof \DOMNodeList && $article->length > 0) {
            $node = $article->item(0);
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        $candidates = $xpath->query('//div[contains(@class,"content") or contains(@class,"article") or contains(@class,"post")]');
        if ($candidates instanceof \DOMNodeList && $candidates->length > 0) {
            $node = $candidates->item(0);
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    private static function removeNodes(DOMDocument $doc, array $tags): void
    {
        foreach ($tags as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    private static function findFirstImage(DOMNode $context, ?string $baseUrl = null): ?string
    {
        $document = $context instanceof DOMDocument ? $context : $context->ownerDocument;
        if (!$document instanceof DOMDocument) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $nodes = $context instanceof DOMDocument ? $xpath->query('.//img') : $xpath->query('.//img', $context);
        if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
            $first = $nodes->item(0);
            if ($first instanceof DOMElement) {
                $src = self::imageUrlFromElement($first);
                if ($src !== null) {
                    return self::absUrl($src, $baseUrl);
                }
            }
        }

        $meta = $xpath->query('//meta[@property="og:image" or @property="og:image:secure_url" or @name="og:image" or @name="twitter:image" or @property="twitter:image" or @name="twitter:image:src"]');
        if ($meta instanceof \DOMNodeList && $meta->length > 0) {
            $firstMeta = $meta->item(0);
            if ($firstMeta instanceof DOMElement) {
                $content = $firstMeta->getAttribute('content');
                if ($content !== '') {
                    return self::absUrl($content, $baseUrl);
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function collectMetaImageCandidates(array &$candidates, DOMXPath $xpath, ?string $baseUrl): void
    {
        $meta = $xpath->query('//meta[@property="og:image" or @property="og:image:secure_url" or @name="og:image" or @name="twitter:image" or @property="twitter:image" or @name="twitter:image:src"]');
        if (!$meta instanceof \DOMNodeList) {
            return;
        }

        foreach ($meta as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $url = trim($node->getAttribute('content'));
            if ($url === '') {
                continue;
            }

            $source = 'meta_image';
            $name = strtolower($node->getAttribute('property') ?: $node->getAttribute('name'));
            if (strpos($name, 'twitter') !== false) {
                $source = 'twitter_image';
            } elseif (strpos($name, 'og:image') !== false) {
                $source = 'og_image';
            }

            $candidates[] = [
                'url' => self::absUrl($url, $baseUrl),
                'source' => $source,
                'alt' => '',
                'title' => '',
                'class' => '',
                'width' => 0,
                'height' => 0,
                'position' => count($candidates),
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function collectImgCandidates(array &$candidates, DOMXPath $xpath, DOMNode $context, ?string $baseUrl, string $source): void
    {
        $nodes = $context instanceof DOMDocument ? $xpath->query('.//img') : $xpath->query('.//img', $context);
        if (!$nodes instanceof \DOMNodeList) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $url = self::imageUrlFromElement($node);
            if ($url === null) {
                continue;
            }

            $candidates[] = [
                'url' => self::absUrl($url, $baseUrl),
                'source' => $source,
                'alt' => trim($node->getAttribute('alt')),
                'title' => trim($node->getAttribute('title')),
                'class' => trim($node->getAttribute('class')),
                'width' => self::dimensionFromAttribute($node->getAttribute('width')),
                'height' => self::dimensionFromAttribute($node->getAttribute('height')),
                'position' => count($candidates),
            ];
        }
    }

    private static function imageUrlFromElement(DOMElement $node): ?string
    {
        foreach (['data-src', 'data-lazy-src', 'data-original', 'data-image'] as $attribute) {
            $value = trim($node->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['srcset', 'data-srcset'] as $attribute) {
            $value = trim($node->getAttribute($attribute));
            if ($value !== '') {
                return self::largestSrcsetUrl($value);
            }
        }

        $src = trim($node->getAttribute('src'));
        if ($src !== '') {
            return $src;
        }

        return null;
    }

    private static function largestSrcsetUrl(string $srcset): ?string
    {
        $bestUrl = null;
        $bestSize = -1.0;
        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if (!$parts || empty($parts[0])) {
                continue;
            }

            $url = $parts[0];
            $descriptor = $parts[1] ?? '1x';
            $size = (float) preg_replace('/[^0-9.]/', '', $descriptor);
            if ($size <= 0) {
                $size = 1.0;
            }

            if ($size > $bestSize) {
                $bestUrl = $url;
                $bestSize = $size;
            }
        }

        return $bestUrl;
    }

    private static function dimensionFromAttribute(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) preg_replace('/[^0-9]/', '', $value);
        return max(0, $number);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private static function dedupeCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $key = strtolower(strtok($url, '#') ?: $url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    private static function absUrl(string $url, ?string $base): string
    {
        if (self::startsWith($url, 'http') || $base === null || $base === '') {
            return esc_url_raw($url);
        }

        if (self::startsWith($url, '//')) {
            $scheme = wp_parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return esc_url_raw($scheme . ':' . $url);
        }

        if (self::startsWith($url, '/')) {
            $parts = wp_parse_url($base);
            if (!$parts) {
                return esc_url_raw($url);
            }

            $host = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $host .= ':' . $parts['port'];
            }

            return esc_url_raw($host . $url);
        }

        return esc_url_raw(trailingslashit(dirname($base)) . ltrim($url, './'));
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        if (strlen($needle) > strlen($haystack)) {
            return false;
        }

        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
