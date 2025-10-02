<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Planner
{
    private const SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'PlannerOutput',
        'type' => 'object',
        'required' => ['topic', 'why_now', 'intent', 'audience', 'outline', 'internal_links', 'update_target_url', 'tags', 'image_subject', 'entity_type'],
        'properties' => [
            'topic' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 140],
            'why_now' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 400],
            'intent' => ['type' => 'string', 'enum' => ['informational', 'howto', 'listicle', 'analysis', 'news']],
            'audience' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 140],
            'outline' => [
                'type' => 'array',
                'minItems' => 3,
                'items' => [
                    'type' => 'object',
                    'required' => ['h2', 'bullets'],
                    'properties' => [
                        'h2' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 90],
                        'bullets' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 6,
                            'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
                        ],
                    ],
                ],
            ],
            'internal_links' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => ['type' => 'string'],
            ],
            'update_target_url' => ['type' => 'string', 'format' => 'uri'],
            'tags' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 8,
                'items' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
            ],
            'image_subject' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
            'entity_type' => ['type' => 'string', 'enum' => ['person', 'brand', 'event', 'object', 'place']],
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
    public function plan(array $item, string $content)
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('You are a Slovak editorial strategist for a lifestyle magazine. Generate structured plans that highlight new angles, timely context, and internal links. Respond strictly via JSON that conforms to the provided schema.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => sprintf(
                    "Source: %s\nTitle: %s\nURL: %s\nPublished: %s\nSummary: %s\nContent:\n%s",
                    $item['source'] ?? '',
                    $item['title'] ?? '',
                    $item['url'] ?? '',
                    $item['dt'] ?? '',
                    $item['summary'] ?? '',
                    mb_substr(strip_tags($content), 0, 4000)
                ),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.1);
        if (is_wp_error($response)) {
            Log::error('planner', 'plan', 'Planner failed', [
                'error' => $response->get_error_message(),
                'source' => $item['source'] ?? '',
                'url' => $item['url'] ?? '',
            ]);

            return $response;
        }

        if (!empty($response['internal_links']) && is_array($response['internal_links'])) {
            $links = array_map('strval', $response['internal_links']);
            $links = array_unique($links);
            $links = array_values(array_filter($links, static fn($url) => filter_var($url, FILTER_VALIDATE_URL)));
            $response['internal_links'] = $links;
        }

        return $response;
    }
}


