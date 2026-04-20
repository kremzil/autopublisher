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
        'properties' => [
            'topic' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 140],
            'why_now' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 400],
            'intent' => ['type' => 'string', 'enum' => ['informational', 'howto', 'listicle', 'analysis', 'news']],
            'audience' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 140],
            'news_angle' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 240],
            'reader_takeaway' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 240],
            'lede' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 320],
            'structure_type' => ['type' => 'string', 'enum' => ['inverted_pyramid', 'explainer', 'timeline', 'listicle']],
            'must_include_facts' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 12,
                'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 180],
            ],
            'must_not_say' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 10,
                'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 180],
            ],
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
                'minItems' => 0,
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
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
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
    public function plan(array $item, array $brief, array $internalLinkCandidates = [], string $model = 'gpt-4o-mini')
    {
        $input = [
            [
                'role' => 'system',
                'content' => __('You are a Slovak editorial strategist for a lifestyle magazine. Plan a source-only news article from the fact brief. Select internal links only from provided candidates. Do not invent facts or links. Respond strictly via JSON that conforms to the provided schema.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => wp_json_encode([
                    'source_item' => $item,
                    'fact_brief' => $brief,
                    'internal_link_candidates' => $internalLinkCandidates,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        $response = $this->client->structured($input, self::SCHEMA, 0.1, $model, 'plan');
        if (is_wp_error($response)) {
            Log::error('planner', 'plan', 'Planner failed', [
                'error' => $response->get_error_message(),
                'source' => $item['source'] ?? '',
                'url' => $item['url'] ?? '',
            ]);

            return $response;
        }

        if (!empty($response['internal_links']) && is_array($response['internal_links'])) {
            $allowed = array_column($internalLinkCandidates, 'url');
            $links = array_map('strval', $response['internal_links']);
            $links = array_unique($links);
            $links = array_values(array_filter($links, static fn($url) => filter_var($url, FILTER_VALIDATE_URL)));
            $links = $allowed === [] ? [] : array_values(array_intersect($links, $allowed));
            $response['internal_links'] = $links;
        }

        return $response;
    }
}


