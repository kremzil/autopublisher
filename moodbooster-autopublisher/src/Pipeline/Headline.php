<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Headline
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'HeadlineOutput',
        'type' => 'object',
        'properties' => [
            'headline' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 80],
            'title_variants' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 7],
            'seo_title' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 70],
            'seo_description' => ['type' => 'string', 'minLength' => 50, 'maxLength' => 170],
            'excerpt' => ['type' => 'string', 'minLength' => 80, 'maxLength' => 180],
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
     * @return array<string, mixed>|WP_Error
     */
    public function generate(array $item, array $brief, array $plan, array $draft, string $model = 'gpt-4o-mini')
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('Create accurate Slovak headlines and SEO metadata after the body is final. Do not add claims that are not present in the draft or fact brief. Respond strictly with JSON.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode([
                    'source_item' => $item,
                    'fact_brief' => $brief,
                    'plan' => $plan,
                    'draft' => $draft,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.1, $model, 'headline');
        if (is_wp_error($response)) {
            Log::error('headline', 'generate', 'Headline generation failed', [
                'error' => $response->get_error_message(),
                'url' => $item['url'] ?? '',
            ]);
        }

        return $response;
    }
}
