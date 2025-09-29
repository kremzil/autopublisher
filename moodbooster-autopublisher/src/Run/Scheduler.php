<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Run;

use Moodbooster\AutoPub\Http\Client as HttpClient;
use Moodbooster\AutoPub\OpenAI\Client as OpenAiClient;
use Moodbooster\AutoPub\Pipeline\Dedup;
use Moodbooster\AutoPub\Pipeline\Editor;
use Moodbooster\AutoPub\Pipeline\Images;
use Moodbooster\AutoPub\Pipeline\Planner;
use Moodbooster\AutoPub\Pipeline\Publisher;
use Moodbooster\AutoPub\Pipeline\Translate;
use Moodbooster\AutoPub\Pipeline\Writer;
use Moodbooster\AutoPub\Sources\EuropaWire;
use Moodbooster\AutoPub\Sources\FashionPost;
use Moodbooster\AutoPub\Sources\FashionStreetHU;
use Moodbooster\AutoPub\Sources\EllePolska;
use Moodbooster\AutoPub\Sources\MarieClaireHU;
use Moodbooster\AutoPub\Sources\MiastoKobiet;
use Moodbooster\AutoPub\Sources\LOfficielBE;
use Moodbooster\AutoPub\Sources\SourceInterface;
use Moodbooster\AutoPub\Sources\TogetherMagazineBE;
use Moodbooster\AutoPub\Util\ContentExtractor;
use Moodbooster\AutoPub\Util\Log;
use Moodbooster\AutoPub\Util\Settings;
use WP_Error;

class Scheduler
{
    public static function activate(): void
    {
        $recurrence = get_option('mb_autopub_cadence', 'daily');
        if (!wp_next_scheduled('moodbooster_autopub_run')) {
            wp_schedule_event(time() + 60, $recurrence, 'moodbooster_autopub_run');
        }
    }

    public static function deactivate(): void
    {
        if ($ts = wp_next_scheduled('moodbooster_autopub_run')) {
            wp_unschedule_event($ts, 'moodbooster_autopub_run');
        }
    }

    public static function maybe_reschedule(string $cadence): void
    {
        $allowed = ['hourly', 'twicedaily', 'daily'];
        if (!in_array($cadence, $allowed, true)) {
            $cadence = 'daily';
        }

        update_option('mb_autopub_cadence', $cadence);
        self::deactivate();
        if (!wp_next_scheduled('moodbooster_autopub_run')) {
            wp_schedule_event(time() + 60, $cadence, 'moodbooster_autopub_run');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function run_batch(array $context = []): void
    {
        $options = Settings::all();
        $sources = array_filter($options['sources'] ?? [], static fn($enabled) => (bool) $enabled);
        if (!empty($context['source'])) {
            $requested = array_map('sanitize_key', (array) $context['source']);
            $sources = array_intersect_key($sources, array_flip($requested));
        }

        if ($sources === []) {
            Log::warn('scheduler', 'run', 'No sources enabled');

            return;
        }

        $http = new HttpClient();
        $openAi = new OpenAiClient((string) ($options['api_key'] ?? ''), $http);
        $planner = new Planner($openAi);
        $writer = new Writer($openAi);
        $editor = new Editor($openAi);
        $translator = new Translate($openAi);
        $dedup = new Dedup();
        $publisher = new Publisher($dedup);
        $images = new Images($http);

        $limit = isset($context['limit']) ? max(1, (int) $context['limit']) : max(1, (int) ($options['max_per_run'] ?? 3));
        $created = 0;

        foreach (array_keys($sources) as $sourceKey) {
            $source = $this->makeSource($sourceKey, $http);
            if (!$source) {
                continue;
            }

            $items = $source->fetch($limit * 2);
            foreach ($items as $item) {
                if ($created >= $limit) {
                    break 2;
                }

                $evaluation = $dedup->evaluate($item, $options);
                if ($evaluation['action'] === 'skip') {
                    continue;
                }

                if ($evaluation['action'] === 'update' && !empty($evaluation['post_id'])) {
                    $this->runUpdateFlow((int) $evaluation['post_id'], $item, $translator);
                    continue;
                }

                $article = $this->fetchArticle($item['url'] ?? '', $http);
                if (!$article) {
                    Log::warn($item['source'] ?? 'source', 'fetch', 'Unable to retrieve article body', [
                        'url' => $item['url'] ?? '',
                    ]);
                    continue;
                }

                $plan = $planner->plan($item, $article['content']);
                if (is_wp_error($plan)) {
                    continue;
                }

                $draft = $writer->write($item, $plan, $article['content']);
                if (is_wp_error($draft)) {
                    continue;
                }

                $review = $editor->review($draft);
                if (is_wp_error($review)) {
                    continue;
                }

                $imageData = null;
                $imageResult = $images->pick($item, $options, $article['original_html']);
                if (!is_wp_error($imageResult)) {
                    $imageData = $imageResult;
                } elseif (!empty($options['image_skip_under_min'])) {
                    Log::warn($item['source'] ?? 'source', 'image', 'Skipping due to missing image', [
                        'url' => $item['url'] ?? '',
                    ]);
                    continue;
                }

                $post = $publisher->publish($item, $plan, $draft, $options, $review);
                if (is_wp_error($post)) {
                    Log::error($item['source'] ?? 'source', 'publish', 'Failed to publish', [
                        'error' => $post->get_error_message(),
                    ]);
                    continue;
                }

                if ($imageData) {
                    $attachmentId = $images->attach($imageData, (int) $post['post_id']);
                    if (!is_wp_error($attachmentId)) {
                        update_post_meta((int) $post['post_id'], '_mb_image_source_url', esc_url_raw($imageData['source_url'] ?? ''));
                    }
                }

                $created++;
            }
        }

        Log::info('scheduler', 'run', 'Run completed', [
            'created' => $created,
            'limit' => $limit,
        ]);
    }

    private function makeSource(string $key, HttpClient $http): ?SourceInterface
    {
        switch ($key) {
            case 'fashionpost':
                return new FashionPost($http);
            case 'europawire':
                return new EuropaWire($http);
            case 'ellepolska':
                return new EllePolska($http);
            case 'miastokobiet':
                return new MiastoKobiet($http);
            case 'marieclairehu':
                return new MarieClaireHU($http);
            case 'fashionstreethu':
                return new FashionStreetHU($http);
            case 'lofficielbe':
                return new LOfficielBE($http);
            case 'togethermag':
                return new TogetherMagazineBE($http);
            default:
                return null;
        }
    }
    private function fetchArticle(string $url, HttpClient $http): ?array
    {
        if ($url === '') {
            return null;
        }

        $response = $http->get($url);
        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return null;
        }

        $extracted = ContentExtractor::extract($body, $url);
        $extracted['original_html'] = $body;

        return $extracted;
    }

    private function runUpdateFlow(int $postId, array $item, Translate $translator): void
    {
        $summary = $item['summary'] ?? '';
        if ($summary !== '') {
            $translated = $translator->toSlovak($summary);
            if (!is_wp_error($translated)) {
                $summary = (string) $translated;
            }
        }

        $block = sprintf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__('AktualizÃƒÆ’Ã‚Â¡cia:', 'moodbooster-autopub'),
            esc_html($summary)
        );

        $current = get_post_field('post_content', $postId);
        if ($current) {
            wp_update_post([
                'ID' => $postId,
                'post_content' => $block . $current,
            ]);
        }

        Log::info($item['source'] ?? 'source', 'update', 'Updated existing post', [
            'post_id' => $postId,
        ]);
    }
}
