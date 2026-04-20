<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class FactCheck
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'FactCheck',
        'type' => 'object',
        'properties' => [
            'supported' => ['type' => 'boolean'],
            'unsupported_claims' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 12],
            'missing_required_facts' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 12],
            'overstated_claims' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 0, 'maxItems' => 12],
            'needs_human_review' => ['type' => 'boolean'],
            'summary' => ['type' => 'string', 'maxLength' => 500],
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
     * @param array<string, mixed> $draft
     * @return array<string, mixed>|WP_Error
     */
    public function check(array $item, array $brief, array $draft, string $sourceContent, string $model = 'gpt-4o-mini')
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('You are a strict source-only fact checker. Compare the Slovak draft against the source article and fact brief. Flag unsupported, missing, or overstated claims. Respond strictly with JSON.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode([
                    'source_item' => $item,
                    'fact_brief' => $brief,
                    'draft' => $draft,
                    'source_content' => mb_substr(strip_tags($sourceContent), 0, 10000),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.0, $model, 'factcheck');
        if (is_wp_error($response)) {
            Log::error('factcheck', 'check', 'Fact check failed', [
                'error' => $response->get_error_message(),
                'url' => $item['url'] ?? '',
            ]);
        }

        return $response;
    }
}
