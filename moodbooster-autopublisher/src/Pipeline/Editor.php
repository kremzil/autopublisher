<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Editor
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'EditorGate',
        'type' => 'object',
        'required' => ['approval', 'reasons', 'quality_scores'],
        'properties' => [
            'approval' => ['type' => 'boolean'],
            'reasons' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 3], 'minItems' => 0],
            'quality_scores' => [
                'type' => 'object',
                'required' => ['helpful', 'originality', 'clarity'],
                'properties' => [
                    'helpful' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'originality' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'clarity' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                ],
            ],
            'fixes_suggested' => [
                'type' => 'object',
                'properties' => [
                    'headline_to_use' => ['type' => 'string'],
                    'sections_to_expand' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'add_faq' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ],
    ];

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>|WP_Error
     */
    public function review(array $draft)
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('You are a quality editor ensuring the article is people-first, original, and clear. Approve only if the content is ready to publish; otherwise flag reasons. Respond with JSON only.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode($draft),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.1);
        if (is_wp_error($response)) {
            Log::error('editor', 'review', 'Editor review failed', [
                'error' => $response->get_error_message(),
            ]);

            return $response;
        }

        return $response;
    }
}