<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Html;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Writer
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'WriterOutput',
        'type' => 'object',
        'properties' => [
            'body_html' => ['type' => 'string', 'minLength' => 1200, 'description' => 'Only allow <p>, <h3>, <strong>, <em>, <blockquote>, <br>.'],
            'excerpt' => ['type' => 'string', 'minLength' => 80, 'maxLength' => 160],
            'tags' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 8,
                'items' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
            ],
            'internal_links' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['url', 'anchor'],
                    'properties' => [
                        'url' => ['type' => 'string', 'format' => 'uri'],
                        'anchor' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                    ],
                ],
            ],
            'citations' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'format' => 'uri'],
            ],
            'image_caption' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 140],
        ],
    ];

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $brief
     * @return array<string, mixed>|WP_Error
     */
    public function write(array $item, array $brief, array $plan, string $excerpt, string $model = 'gpt-4o-mini', string $editorialStyle = '')
    {
        $style = $editorialStyle !== '' ? "\n\nEditorial style guide: " . $editorialStyle : '';
        $input = [
            [
                'role' => 'system',
                'content' => __('You are a senior lifestyle news editor writing in Slovak (sk_SK). Write a clear magazine-news article from the fact brief and editorial plan. Start with a news lead, preserve named entities, avoid tabloid tone, and do not add facts outside the brief. Use only <p>, <h3>, <strong>, <em>, <blockquote>, <br> tags. Insert an <h3> heading every 3-5 paragraphs. Remove hyperlinks from body.', 'moodbooster-autopub') . $style,
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode([
                    'source' => $item,
                    'fact_brief' => $brief,
                    'plan' => $plan,
                    'source_excerpt' => mb_substr(strip_tags($excerpt), 0, 2500),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.2, $model, 'write');
        if (is_wp_error($response)) {
            Log::error('writer', 'draft', 'Writer failed', [
                'error' => $response->get_error_message(),
                'url' => $item['url'] ?? '',
            ]);

            return $response;
        }

        $response['body_html'] = Html::normalizeBody((string) ($response['body_html'] ?? ''));
        $response['body_html'] = trim($response['body_html']);

        return $response;
    }
}
