<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\OpenAI;

use Moodbooster\AutoPub\Http\Client as HttpClient;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Client
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** @var string */
    private $apiKey;

    /** @var HttpClient */
    private $http;

    public function __construct(string $apiKey, HttpClient $http)
    {
        $this->apiKey = $apiKey;
        $this->http   = $http;
    }

    /**
     * @param array<int, array<string, mixed>|string> $input
     * @param array<string, mixed> $schema
     * @return array<string, mixed>|WP_Error
     */
    public function structured(
        array $input,
        array $schema,
        float $temperature = 0.1,
        string $model = 'gpt-4o-mini'
    ) {
        if ($this->apiKey === '') {
            return new WP_Error('mb_no_api_key', __('OpenAI API key is missing', 'moodbooster-autopub'));
        }

        $schemaBody = isset($schema['schema']) ? $schema['schema'] : $schema;
        $schemaName = isset($schema['name']) ? (string) $schema['name'] : 'ArticlePlan';
        $schemaStrict = array_key_exists('strict', $schema) ? (bool) $schema['strict'] : true;

        $text = [
            'format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $schemaBody,
                    'strict' => $schemaStrict,
                ],
            ],
        ];

        $body = [
            'model' => $model,
            'input' => $input,
            'temperature' => $temperature,
            'text' => $text,
        ];

        $json = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Log::info('openai', 'structured_request', 'Payload', ['body' => $json]);

        $response = $this->http->request('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => $json,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);

        if ($code >= 400) {
            Log::error('openai', 'structured', 'API error', ['code' => $code, 'body' => $raw]);
            return new WP_Error('mb_openai_error', sprintf('OpenAI API returned %d', $code));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Log::error('openai', 'structured', 'Invalid JSON from API', ['body' => $raw]);
            return new WP_Error('mb_openai_json', __('Invalid JSON from OpenAI', 'moodbooster-autopub'));
        }

        $final = [];
        $output = $decoded['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (isset($item['text']) && is_string($item['text'])) {
                    $parsed = json_decode($item['text'], true);
                    if (is_array($parsed) && $parsed) {
                        $final = $parsed;
                        break;
                    }
                }

                if (isset($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $chunk) {
                        $txt = is_array($chunk) && isset($chunk['text'])
                            ? $chunk['text']
                            : (is_string($chunk) ? $chunk : null);
                        if (is_string($txt)) {
                            $parsed = json_decode($txt, true);
                            if (is_array($parsed) && $parsed) {
                                $final = $parsed;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        if ($final === []) {
            return new WP_Error('mb_openai_empty', __('Empty structured response', 'moodbooster-autopub'));
        }

        return $final;
    }
}