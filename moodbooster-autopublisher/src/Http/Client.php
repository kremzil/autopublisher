<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Http;

use Moodbooster\AutoPub\Util\Log;
use WP_Error;

final class Client
{
    /**
     * @var string
     */
    private $userAgent;

    public function __construct(?string $userAgent = null)
    {
        $this->userAgent = $userAgent ?: 'MoodboosterAutopublisher/1.0 (+site)';
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function request(string $method, string $url, array $args = [])
    {
        $method = strtoupper($method);
        $args['method'] = $method;

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['User-Agent'] = $this->userAgent;
        $args['redirection'] = $args['redirection'] ?? 3;
        $args['timeout'] = $args['timeout'] ?? 15;

        if (array_key_exists('body', $args)) {
            if (is_array($args['body'])) {
                $args['body'] = wp_json_encode($args['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (empty($args['headers']['Content-Type'])) {
                    $args['headers']['Content-Type'] = 'application/json';
                }
            }

            if (!is_string($args['body'])) {
                $args['body'] = (string) $args['body'];
            }
        }

        try {
            usleep(random_int(500000, 1000000));
        } catch (\Throwable $e) {
            // ignore sleep failure
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            Log::error('http', 'request', sprintf('%s %s failed', $method, $url), [
                'error' => $response->get_error_message(),
            ]);

            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        Log::info('http', 'request', sprintf('%s %s => %d', $method, $url, $code));

        return $response;
    }

    /**
     * @param string $url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function get(string $url, array $args = [])
    {
        return $this->request('GET', $url, $args);
    }

    /**
     * @param string $url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function head(string $url, array $args = [])
    {
        return $this->request('HEAD', $url, $args);
    }
}