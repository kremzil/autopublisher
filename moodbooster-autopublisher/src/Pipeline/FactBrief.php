<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class FactBrief
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'FactBrief',
        'type' => 'object',
        'properties' => [
            'main_event' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 240],
            'who' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 12],
            'what_happened' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
            'where' => ['type' => 'string', 'maxLength' => 160],
            'when' => ['type' => 'string', 'maxLength' => 160],
            'why_it_matters' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
            'confirmed_facts' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 12],
            'soft_claims' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 8],
            'quotes' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 8],
            'numbers' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 8],
            'unknowns' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 8],
            'do_not_infer' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 10],
        ],
    ];

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|WP_Error
     */
    public function extract(array $item, string $content, string $model = 'gpt-4o-mini')
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('Extract a source-only fact brief for a Slovak news rewrite. Include only facts supported by the provided source text. Mark uncertainty clearly and list what must not be inferred. Respond strictly with JSON.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode([
                    'source_item' => $item,
                    'source_content' => mb_substr(strip_tags($content), 0, 10000),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.0, $model, 'fact_brief');
        if (is_wp_error($response)) {
            Log::error('fact_brief', 'extract', 'Fact brief failed', [
                'error' => $response->get_error_message(),
                'url' => $item['url'] ?? '',
            ]);
        }

        return $response;
    }
}
