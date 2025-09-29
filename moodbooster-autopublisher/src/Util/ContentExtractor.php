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
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (!$loaded) {
            return ['content' => Html::normalizeBody($html)];
        }

        self::removeNodes($doc, ['script', 'style', 'noscript', 'iframe', 'form', 'nav', 'header', 'footer']);

        $xpath = new DOMXPath($doc);
        $article = $xpath->query('//article');
        $contentNode = null;
        if ($article instanceof \DOMNodeList && $article->length > 0) {
            $contentNode = $article->item(0);
        }

        if (!$contentNode instanceof DOMElement) {
            $candidates = $xpath->query('//div[contains(@class,"content") or contains(@class,"article") or contains(@class,"post")]');
            if ($candidates instanceof \DOMNodeList && $candidates->length > 0) {
                $contentNode = $candidates->item(0);
            }
        }

        if (!$contentNode instanceof DOMElement) {
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
                $src = $first->getAttribute('src');
                if ($src !== '') {
                    return self::absUrl($src, $baseUrl);
                }
            }
        }

        $meta = $xpath->query('//meta[@property="og:image" or @name="og:image"]');
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