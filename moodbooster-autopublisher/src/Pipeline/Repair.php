<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Html;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Repair
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'RepairedDraft',
        'type' => 'object',
        'properties' => [
            'body_html' => ['type' => 'string', 'minLength' => 1200],
            'excerpt' => ['type' => 'string', 'minLength' => 80, 'maxLength' => 180],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 8],
            'internal_links' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string'],
                        'anchor' => ['type' => 'string'],
                    ],
                ],
            ],
            'citations' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0],
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
     * @param array<string, mixed> $brief
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $factcheck
     * @return array<string, mixed>|WP_Error
     */
    public function repair(array $item, array $brief, array $plan, array $draft, array $factcheck, string $model = 'gpt-4o-mini')
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('Repair the Slovak draft so every factual claim is supported by the fact brief. Remove unsupported claims, add missing required facts, keep the magazine-news style, and use only allowed HTML tags: p, h3, strong, em, blockquote, br. Respond strictly with JSON.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode([
                    'source_item' => $item,
                    'fact_brief' => $brief,
                    'plan' => $plan,
                    'draft' => $draft,
                    'factcheck' => $factcheck,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.1, $model, 'repair');
        if (is_wp_error($response)) {
            Log::error('repair', 'draft', 'Repair failed', [
                'error' => $response->get_error_message(),
                'url' => $item['url'] ?? '',
            ]);

            return $response;
        }

        $response['body_html'] = trim(Html::normalizeBody((string) ($response['body_html'] ?? '')));

        return $response;
    }
}
