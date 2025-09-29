<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Run;

use Moodbooster\AutoPub\Http\Client as HttpClient;
use Moodbooster\AutoPub\OpenAI\Client as OpenAiClient;
use Moodbooster\AutoPub\Pipeline\Editor;
use Moodbooster\AutoPub\Pipeline\Planner;
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
use Moodbooster\AutoPub\Util\Settings;
use WP_CLI;
use WP_CLI_Command;

final class Cli extends WP_CLI_Command
{
    /**
     * Generates planning preview for enabled sources.
     *
     * ## OPTIONS
     *
     * [--source=<source>]
     * : Limit planning to a specific source key.
     */
    public function plan(array $args, array $assocArgs): void
    {
        $options = Settings::all();
        $http = new HttpClient();
        $openAi = new OpenAiClient((string) ($options['api_key'] ?? ''), $http);
        $planner = new Planner($openAi);
        $writer = new Writer($openAi);
        $editor = new Editor($openAi);

        $sourceKey = $assocArgs['source'] ?? null;
        $sources = array_filter($options['sources'] ?? [], static fn($enabled) => (bool) $enabled);
        if ($sourceKey) {
            $sources = array_intersect_key($sources, [$sourceKey => true]);
        }

        foreach (array_keys($sources) as $key) {
            $sourceInstance = $this->makeSource($key, $http);
            if (!$sourceInstance) {
                WP_CLI::warning("Source {$key} not available");
                continue;
            }
            $items = $sourceInstance->fetch(1);
            if ($items === []) {
                WP_CLI::warning("No items for {$key}");
                continue;
            }
            $item = $items[0];
            $article = $this->fetchArticle($item['url'] ?? '', $http);
            if (!$article) {
                WP_CLI::warning("Failed to fetch article body for {$item['url']}");
                continue;
            }
            $plan = $planner->plan($item, $article['content']);
            if (is_wp_error($plan)) {
                WP_CLI::warning($plan->get_error_message());
                continue;
            }
            $draft = $writer->write($item, $plan, $article['content']);
            if (is_wp_error($draft)) {
                WP_CLI::warning($draft->get_error_message());
                continue;
            }
            $review = $editor->review($draft);
            if (is_wp_error($review)) {
                WP_CLI::warning($review->get_error_message());
                continue;
            }

            WP_CLI::line(str_repeat('=', 40));
            WP_CLI::success("Plan for {$key}");
            WP_CLI::line('Title suggestion: ' . ($draft['title_variants'][0] ?? 'n/a'));
            WP_CLI::line('Outline: ' . wp_json_encode($plan['outline'] ?? []));
            WP_CLI::line('Editor approval: ' . (!empty($review['approval']) ? 'yes' : 'needs review'));
        }
    }

    /**
     * Triggers the autopublisher run from CLI.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Override max posts per run.
     *
     * [--source=<source>]
     * : Limit to specific source key.
     */
    public function run(array $args, array $assocArgs): void
    {
        $context = [];
        if (isset($assocArgs['limit'])) {
            $context['limit'] = (int) $assocArgs['limit'];
        }
        if (isset($assocArgs['source'])) {
            $context['source'] = $assocArgs['source'];
        }

        (new Scheduler())->run_batch($context);
        WP_CLI::success('Run triggered');
    }

    /**
     * Reprocesses featured image crops for a post.
     *
     * ## OPTIONS
     *
     * --post=<id>
     * : Post ID to rehash images for.
     */
    public function rehash_images(array $args, array $assocArgs): void
    {
        if (empty($assocArgs['post'])) {
            WP_CLI::error('Please provide --post=<id>');
        }

        $postId = (int) $assocArgs['post'];
        $thumb = get_post_thumbnail_id($postId);
        if (!$thumb) {
            WP_CLI::error('Post lacks featured image');
        }

        $file = get_attached_file($thumb);
        if (!$file) {
            WP_CLI::error('Unable to locate attachment file');
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            WP_CLI::error($editor->get_error_message());
        }

        $size = $editor->get_size();
        $width = $size['width'] ?? 0;
        $height = $size['height'] ?? 0;
        if ($width === 0 || $height === 0) {
            WP_CLI::error('Invalid image dimensions');
        }

        $targetRatio = 16 / 9;
        $newHeight = (int) round($width / $targetRatio);
        if ($newHeight > $height) {
            $newWidth = (int) round($height * $targetRatio);
            $x = (int) max(0, ($width - $newWidth) / 2);
            $editor->crop($x, 0, $newWidth, $height);
        } else {
            $y = (int) max(0, ($height - $newHeight) / 2);
            $editor->crop(0, $y, $width, $newHeight);
        }

        $editor->save($file);
        wp_update_attachment_metadata($thumb, wp_generate_attachment_metadata($thumb, $file));

        WP_CLI::success('Image rehashed and cropped to 16:9');
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

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return null;
        }

        $extracted = ContentExtractor::extract($body, $url);
        $extracted['original_html'] = $body;

        return $extracted;
    }
}
