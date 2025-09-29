<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Pipeline;

use Moodbooster\AutoPub\OpenAI\Client;
use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Translate
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return string|WP_Error
     */
    public function toSlovak(string $text)
    {
        if (trim($text) === '') {
            return $text;
        }

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Translation',
            'type' => 'object',
            'required' => ['sk'],
            'properties' => [
                'sk' => ['type' => 'string', 'minLength' => 1],
            ],
        ];

        $input = [
            [
                'role' => 'system',
                'content' => __('Translate the provided text into Slovak (sk_SK) while keeping named entities in original form.', 'moodbooster-autopub'),
            ],
            [
                'role' => 'user',
                'content' => $text,
            ],
        ];

        $result = $this->client->structured($input, $schema, 0.0);
        if (is_wp_error($result)) {
            Log::error('translate', 'to_slovak', 'Translation failed', [
                'error' => $result->get_error_message(),
            ]);

            return $result;
        }

        return (string) ($result['sk'] ?? $text);
    }
}