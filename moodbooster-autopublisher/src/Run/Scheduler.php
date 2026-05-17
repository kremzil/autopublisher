<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Run;

use Moodbooster\AutoPub\Http\Client as HttpClient;
use Moodbooster\AutoPub\OpenAI\Client as OpenAiClient;
use Moodbooster\AutoPub\Pipeline\Dedup;
use Moodbooster\AutoPub\Pipeline\Editor;
use Moodbooster\AutoPub\Pipeline\FactBrief;
use Moodbooster\AutoPub\Pipeline\FactCheck;
use Moodbooster\AutoPub\Pipeline\Headline;
use Moodbooster\AutoPub\Pipeline\Images;
use Moodbooster\AutoPub\Pipeline\Planner;
use Moodbooster\AutoPub\Pipeline\Publisher;
use Moodbooster\AutoPub\Pipeline\Repair;
use Moodbooster\AutoPub\Pipeline\Writer;
use Moodbooster\AutoPub\Sources\AbstractHtmlSource;
use Moodbooster\AutoPub\Sources\EllePolska;
use Moodbooster\AutoPub\Sources\EuropaWire;
use Moodbooster\AutoPub\Sources\FashionPost;
use Moodbooster\AutoPub\Sources\FashionStreetHU;
use Moodbooster\AutoPub\Sources\BratislavskeNoviny;
use Moodbooster\AutoPub\Sources\LOfficielBE;
use Moodbooster\AutoPub\Sources\MarieClaireHU;
use Moodbooster\AutoPub\Sources\MiastoKobiet;
use Moodbooster\AutoPub\Sources\NaseKosice;
use Moodbooster\AutoPub\Sources\SourceInterface;
use Moodbooster\AutoPub\Sources\TogetherMagazineBE;
use Moodbooster\AutoPub\Storage\ArtifactRepository;
use Moodbooster\AutoPub\Storage\Database;
use Moodbooster\AutoPub\Storage\QueueRepository;
use Moodbooster\AutoPub\Storage\RunRepository;
use Moodbooster\AutoPub\Util\ContentExtractor;
use Moodbooster\AutoPub\Util\Html;
use Moodbooster\AutoPub\Util\Log;
use Moodbooster\AutoPub\Util\Settings;
use WP_Error;

class Scheduler
{
    public static function activate(): void
    {
        Database::install();

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
        $queued = $this->ingest($context);
        $created = $this->generateQueued($context);

        Log::info('scheduler', 'run', 'Run completed', [
            'queued' => $queued,
            'created' => $created,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function ingest(array $context = []): int
    {
        Database::install();

        $options = Settings::all();
        $sources = array_filter($options['sources'] ?? [], static fn($enabled) => (bool) $enabled);
        if (!empty($context['source'])) {
            $requested = array_map('sanitize_key', (array) $context['source']);
            $sources = array_intersect_key($sources, array_flip($requested));
        }

        if ($sources === []) {
            Log::warn('scheduler', 'ingest', 'No sources enabled');

            return 0;
        }

        $http = new HttpClient();
        $dedup = new Dedup();
        $queue = new QueueRepository();
        $limit = isset($context['fetch_limit']) ? max(1, (int) $context['fetch_limit']) : max(10, (int) ($options['max_per_run'] ?? 3) * 3);
        $queued = 0;
        $sourceKeys = array_keys($sources);

        foreach ($sourceKeys as $sourceKey) {
            $source = $this->makeSource($sourceKey, $http);
            if (!$source) {
                continue;
            }
            if (($options['fetch_mode'] ?? 'rss_html') === 'html_only' && !$source instanceof AbstractHtmlSource) {
                Log::warn($sourceKey, 'ingest', 'HTML-only discovery is not supported for this source');
                continue;
            }

            $items = $source->fetch($limit);
            $removedQueued = $queue->deleteQueuedForSources([$sourceKey]);
            if ($removedQueued > 0) {
                Log::info($sourceKey, 'ingest', 'Cleared stale queued source items after scan', [
                    'removed' => $removedQueued,
                ]);
            }

            foreach ($items as $item) {
                $evaluation = $dedup->evaluate($item, $options);
                if (($evaluation['action'] ?? '') !== 'create') {
                    Log::info($item['source'] ?? $sourceKey, 'ingest', 'Skipping existing or related item', [
                        'reason' => $evaluation['reason'] ?? 'dedupe',
                        'post_id' => $evaluation['post_id'] ?? null,
                        'url' => $item['url'] ?? '',
                    ]);
                    continue;
                }

                $itemId = $queue->upsertItem($item);
                if ($itemId > 0) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generateQueued(array $context = []): int
    {
        Database::install();

        $options = Settings::all();
        $limit = isset($context['limit']) ? max(1, (int) $context['limit']) : max(1, (int) ($options['max_per_run'] ?? 3));
        $source = isset($context['source']) && !is_array($context['source']) ? sanitize_key((string) $context['source']) : null;
        $queue = new QueueRepository();
        $items = $queue->nextQueued($limit, $source);
        $created = 0;

        foreach ($items as $row) {
            $result = $this->generateItem((int) $row['id']);
            if (!is_wp_error($result)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return int|WP_Error Post ID on success.
     */
    public function generateItem(int $itemId)
    {
        Database::install();

        $queue = new QueueRepository();
        $runs = new RunRepository();
        $artifacts = new ArtifactRepository();
        $row = $queue->find($itemId);
        if (!$row) {
            return new WP_Error('mb_queue_missing', __('Queue item not found', 'moodbooster-autopub'));
        }

        $item = $queue->decodeItem($row);
        if ($item === []) {
            $item = [
                'source' => $row['source'] ?? 'unknown',
                'url' => $row['url'] ?? '',
                'title' => $row['title'] ?? '',
                'fingerprint' => $row['fingerprint'] ?? '',
            ];
        }

        $options = Settings::all();
        $models = Settings::modelMap();
        $runId = $runs->start($itemId, $models);
        $queue->markRunning($itemId);

        try {
            $http = new HttpClient();
            $article = $this->fetchArticle((string) ($item['url'] ?? ''), $http);
            if (!$article) {
                return $this->failItem($queue, $runs, $itemId, $runId, 'Unable to retrieve article body');
            }
            $artifacts->save($itemId, $runId, 'source_article', [
                'url' => $item['url'] ?? '',
                'content' => $article['content'] ?? '',
                'image' => $article['image'] ?? null,
                'title' => $article['title'] ?? null,
            ]);

            if (($item['processing_mode'] ?? '') === 'import_only') {
                return $this->generateImportOnlyItem($itemId, $runId, $item, $article, $options, $queue, $runs, $artifacts);
            }

            $openAi = new OpenAiClient((string) ($options['api_key'] ?? ''), $http);
            $briefStage = new FactBrief($openAi);
            $planner = new Planner($openAi);
            $writer = new Writer($openAi);
            $factChecker = new FactCheck($openAi);
            $repairer = new Repair($openAi);
            $editor = new Editor($openAi);
            $headlineStage = new Headline($openAi);
            $publisher = new Publisher(new Dedup());
            $images = new Images($http);

            $brief = $briefStage->extract($item, (string) $article['content'], $models['brief']);
            if (is_wp_error($brief)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $brief->get_error_message());
            }
            $artifacts->save($itemId, $runId, 'fact_brief', $brief);

            $plan = $planner->plan($item, $brief, $this->internalLinkCandidates($item), $models['plan']);
            if (is_wp_error($plan)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $plan->get_error_message());
            }
            $artifacts->save($itemId, $runId, 'plan', $plan);

            $draft = $writer->write($item, $brief, $plan, (string) $article['content'], $models['write'], (string) ($options['editorial_style'] ?? ''));
            if (is_wp_error($draft)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $draft->get_error_message());
            }
            $artifacts->save($itemId, $runId, 'draft', $draft);

            $factcheck = $factChecker->check($item, $brief, $draft, (string) $article['content'], $models['check']);
            if (is_wp_error($factcheck)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $factcheck->get_error_message());
            }
            $artifacts->save($itemId, $runId, 'factcheck', $factcheck);

            if (empty($factcheck['supported']) && !empty($options['repair_enabled'])) {
                $repaired = $repairer->repair($item, $brief, $plan, $draft, $factcheck, $models['write']);
                if (!is_wp_error($repaired)) {
                    $draft = $repaired;
                    $artifacts->save($itemId, $runId, 'repair', $draft);
                    $rechecked = $factChecker->check($item, $brief, $draft, (string) $article['content'], $models['check']);
                    if (!is_wp_error($rechecked)) {
                        $factcheck = $rechecked;
                        $artifacts->save($itemId, $runId, 'factcheck', $factcheck);
                    }
                }
            }

            $review = $editor->review($draft, $brief, is_array($factcheck) ? $factcheck : [], $models['check']);
            if (is_wp_error($review)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $review->get_error_message());
            }
            $review = $this->applyQualityThreshold($review, (float) ($options['quality_threshold'] ?? 0.7));
            $artifacts->save($itemId, $runId, 'editor_gate', $review);

            $headline = $headlineStage->generate($item, $brief, $plan, $draft, $models['headline']);
            if (is_wp_error($headline)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $headline->get_error_message());
            }
            $artifacts->save($itemId, $runId, 'headline', $headline);

            $post = $publisher->publish($item, $plan, $draft, $options, $review, $headline, is_array($factcheck) ? $factcheck : [], $itemId, $runId);
            if (is_wp_error($post)) {
                return $this->failItem($queue, $runs, $itemId, $runId, $post->get_error_message());
            }

            $imageContext = [
                'brief' => $brief,
                'plan' => $plan,
                'draft' => $draft,
                'headline' => $headline,
            ];
            $imageResult = $images->pick($item, $options, (string) ($article['original_html'] ?? ''), $imageContext);
            if (!is_wp_error($imageResult)) {
                $artifacts->save($itemId, $runId, 'image_selection', [
                    'status' => 'selected',
                    'source_url' => $imageResult['source_url'] ?? '',
                    'selection' => $imageResult['selection'] ?? [],
                    'candidates' => $imageResult['candidates'] ?? [],
                ]);

                $attachmentId = $images->attach($imageResult, (int) $post['post_id']);
                if (!is_wp_error($attachmentId)) {
                    update_post_meta((int) $post['post_id'], '_mb_image_source_url', esc_url_raw($imageResult['source_url'] ?? ''));
                    if (!empty($imageResult['selection']) && is_array($imageResult['selection'])) {
                        update_post_meta((int) $post['post_id'], '_mb_image_selection', wp_json_encode($imageResult['selection'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    }
                }
            } else {
                $imageErrorData = $imageResult->get_error_data($imageResult->get_error_code());
                $artifacts->save($itemId, $runId, 'image_selection', [
                    'status' => 'failed',
                    'error' => $imageResult->get_error_message(),
                    'candidates' => is_array($imageErrorData) ? ($imageErrorData['candidates'] ?? []) : [],
                ]);
            }

            if (is_wp_error($imageResult) && !empty($options['image_skip_under_min'])) {
                Log::warn($item['source'] ?? 'source', 'image', 'Draft created without valid image', [
                    'post_id' => $post['post_id'],
                    'url' => $item['url'] ?? '',
                    'reason' => $imageResult->get_error_message(),
                ]);
            }

            $needsReview = empty($review['approval']) || empty($factcheck['supported']) || !empty($factcheck['needs_human_review']);
            $queue->markDraft($itemId, (int) $post['post_id'], $needsReview);
            $runs->finish($runId, $needsReview ? 'needs_review' : 'success');

            return (int) $post['post_id'];
        } catch (\Throwable $e) {
            return $this->failItem($queue, $runs, $itemId, $runId, $e->getMessage());
        }
    }

    private function failItem(QueueRepository $queue, RunRepository $runs, int $itemId, int $runId, string $error): WP_Error
    {
        $queue->markFailed($itemId, $error);
        $runs->finish($runId, 'failed', $error);
        Log::error('scheduler', 'generate', 'Queue item failed', [
            'item_id' => $itemId,
            'run_id' => $runId,
            'error' => $error,
        ]);

        return new WP_Error('mb_v2_generate_failed', $error);
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
            case 'bratislavskenoviny':
                return new BratislavskeNoviny($http);
            case 'nasekosice':
                return new NaseKosice($http);
            default:
                return null;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $article
     * @param array<string, mixed> $options
     * @return int|WP_Error
     */
    private function generateImportOnlyItem(
        int $itemId,
        int $runId,
        array $item,
        array $article,
        array $options,
        QueueRepository $queue,
        RunRepository $runs,
        ArtifactRepository $artifacts
    ) {
        $title = sanitize_text_field((string) ($article['title'] ?? $item['title'] ?? ''));
        if ($title === '') {
            return $this->failItem($queue, $runs, $itemId, $runId, 'Import-only item has no title');
        }

        $body = $this->cleanImportOnlyBody(
            trim((string) ($article['content'] ?? '')),
            $title,
            (string) ($item['source'] ?? '')
        );
        if (strlen(strip_tags($body)) < 200) {
            return $this->failItem($queue, $runs, $itemId, $runId, 'Import-only article body is too short');
        }
        $body = $this->removeFeaturedImageFromBody(
            $body,
            (string) ($item['image_url'] ?? '')
        );

        $plain = Html::plainText($body);
        $excerptSource = (string) ($item['summary'] ?? '');
        if ($excerptSource === '') {
            $excerptSource = mb_substr($plain, 0, 157) . (mb_strlen($plain) > 157 ? '...' : '');
        }

        $url = esc_url_raw((string) ($item['url'] ?? ''));
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $content = $body . sprintf(
            '<p><em>%s</em> <a href="%s" rel="noopener" target="_blank">%s</a></p>',
            esc_html__('Zdroj:', 'moodbooster-autopub'),
            esc_url($url),
            esc_html((string) $host)
        );

        $status = ($options['publish_mode'] ?? 'draft') === 'publish' ? 'publish' : 'draft';
        $postArgs = [
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field($excerptSource),
            'post_status' => $status,
            'post_type' => 'post',
            'post_author' => get_current_user_id() ?: 1,
        ];

        /** @var array<string, mixed> $postArgs */
        $postArgs = apply_filters('moodbooster_autopub_post_args', $postArgs, $item, [], [
            'body_html' => $content,
            'excerpt' => $excerptSource,
        ]);

        $postId = wp_insert_post($postArgs, true);
        if (is_wp_error($postId)) {
            return $this->failItem($queue, $runs, $itemId, $runId, $postId->get_error_message());
        }

        if (!empty($options['category'])) {
            wp_set_post_categories((int) $postId, [(int) $options['category']]);
        }

        $meta = [
            '_mb_source' => sanitize_text_field((string) ($item['source'] ?? 'unknown')),
            '_mb_source_url' => $url,
            '_mb_source_fp' => $item['fingerprint'] ?? sha1(($item['source'] ?? '') . $url),
            '_mb_author_original' => sanitize_text_field((string) ($item['author'] ?? '')),
            '_mb_published_original' => sanitize_text_field((string) ($item['dt'] ?? '')),
            '_mb_image_source_url' => $item['image_url'] ?? '',
            '_mb_pipeline_version' => \Moodbooster\AutoPub\VERSION,
            '_mb_processing_mode' => 'import_only',
            '_mb_factcheck_status' => 'import_only',
            '_mb_v2_item_id' => $itemId,
            '_mb_v2_run_id' => $runId,
        ];

        $meta = apply_filters('moodbooster_autopub_post_meta', $meta, $item, [], [
            'body_html' => $content,
            'excerpt' => $excerptSource,
        ], ['approval' => true]);
        foreach ($meta as $key => $value) {
            update_post_meta((int) $postId, $key, $value);
        }

        $images = new Images(new HttpClient());
        $imageResult = $images->pick($item, $options, (string) ($article['original_html'] ?? ''), []);
        if (!is_wp_error($imageResult)) {
            $artifacts->save($itemId, $runId, 'image_selection', [
                'status' => 'selected',
                'source_url' => $imageResult['source_url'] ?? '',
                'selection' => $imageResult['selection'] ?? [],
                'candidates' => $imageResult['candidates'] ?? [],
            ]);

            $attachmentId = $images->attach($imageResult, (int) $postId);
            if (!is_wp_error($attachmentId)) {
                update_post_meta((int) $postId, '_mb_image_source_url', esc_url_raw($imageResult['source_url'] ?? ''));
            }
        } else {
            $imageErrorData = $imageResult->get_error_data($imageResult->get_error_code());
            $artifacts->save($itemId, $runId, 'image_selection', [
                'status' => 'failed',
                'error' => $imageResult->get_error_message(),
                'candidates' => is_array($imageErrorData) ? ($imageErrorData['candidates'] ?? []) : [],
            ]);
        }

        $artifacts->save($itemId, $runId, 'import_only', [
            'post_id' => (int) $postId,
            'status' => $status,
            'source_url' => $url,
        ]);

        $queue->markDraft($itemId, (int) $postId, false);
        $runs->finish($runId, 'success');

        Log::info($item['source'] ?? 'source', 'publish', 'Import-only post created', [
            'post_id' => (int) $postId,
            'status' => $status,
        ]);

        return (int) $postId;
    }

    private function cleanImportOnlyBody(string $body, string $title, string $source): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (strpos($body, '<p') !== false) {
            $prefix = preg_split('/<p\b/i', $body, 2);
            if (is_array($prefix) && count($prefix) === 2) {
                $prefixText = Html::plainText($prefix[0]);
                if ($this->sameTextPrefix($prefixText, $title) || $source === 'bratislavskenoviny' || $source === 'nasekosice') {
                    $body = '<p' . $prefix[1];
                }
            }
        }

        if ($source === 'bratislavskenoviny') {
            $body = preg_replace('/Páčil sa vám článok\\?/iu', '', $body) ?? $body;
            $body = preg_replace('#<p[^>]*>\\s*(?:<em>\\s*)?Zdroj:\\s*.*?</p>#isu', '', $body) ?? $body;
            $body = preg_replace('#<p[^>]*>\\s*0\\s*</p>#isu', '', $body) ?? $body;
        }

        if ($source === 'nasekosice') {
            $body = $this->removeNaseKosiceSummaryBlocks($body);
        }

        $body = preg_replace('#<p[^>]*>\\s*</p>#isu', '', $body) ?? $body;
        $body = preg_replace('/[ \\t]{2,}/', ' ', $body) ?? $body;

        return trim($body);
    }

    private function removeFeaturedImageFromBody(string $body, string $imageUrl): string
    {
        $featuredUrl = $this->canonicalImageUrl($imageUrl);
        if ($featuredUrl === '') {
            return $body;
        }

        if (stripos($body, '<img') === false) {
            return $body;
        }

        $body = preg_replace_callback(
            '#<figure\b[^>]*>\s*(<img\b[^>]*>)\s*</figure>#isu',
            function (array $matches) use ($featuredUrl): string {
                return $this->imgTagMatchesUrl($matches[1], $featuredUrl) ? '' : $matches[0];
            },
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '#<img\b[^>]*>#isu',
            function (array $matches) use ($featuredUrl): string {
                return $this->imgTagMatchesUrl($matches[0], $featuredUrl) ? '' : $matches[0];
            },
            $body
        ) ?? $body;

        $body = preg_replace('#<figure\b[^>]*>\s*</figure>#isu', '', $body) ?? $body;
        $body = preg_replace('#<p[^>]*>\s*</p>#isu', '', $body) ?? $body;

        return trim($body);
    }

    private function imgTagMatchesUrl(string $imgTag, string $featuredUrl): bool
    {
        if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/isu', $imgTag, $match) !== 1) {
            return false;
        }

        return $this->canonicalImageUrl($match[2]) === $featuredUrl;
    }

    private function canonicalImageUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') {
            return '';
        }

        return esc_url_raw($url);
    }

    private function sameTextPrefix(string $candidate, string $title): bool
    {
        $candidate = mb_strtolower(trim(preg_replace('/\\s+/u', ' ', $candidate) ?? $candidate));
        $title = mb_strtolower(trim(preg_replace('/\\s+/u', ' ', $title) ?? $title));
        if ($candidate === '' || $title === '') {
            return false;
        }

        return strpos($candidate, $title) === 0 || strpos($title, $candidate) === 0;
    }

    private function removeNaseKosiceSummaryBlocks(string $body): string
    {
        $body = preg_replace('/Zobraziť rýchly súhrn|Schovať rýchly súhrn/iu', '', $body) ?? $body;

        return preg_replace_callback(
            '#<p[^>]*>.*?</p>#isu',
            static function (array $matches): string {
                $plain = Html::plainText($matches[0]);
                if (stripos($plain, 'Rýchly súhrn článku') !== false) {
                    return '';
                }

                return $matches[0];
            },
            $body
        ) ?? $body;
    }

    /**
     * @return array<string, mixed>|null
     */
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

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function applyQualityThreshold(array $review, float $threshold): array
    {
        $scores = $review['quality_scores'] ?? [];
        if (!is_array($scores)) {
            return $review;
        }

        foreach (['helpful', 'originality', 'clarity'] as $key) {
            if (isset($scores[$key]) && (float) $scores[$key] < $threshold) {
                $review['approval'] = false;
                $reasons = isset($review['reasons']) && is_array($review['reasons']) ? $review['reasons'] : [];
                $reasons[] = sprintf('Quality score %s is below threshold %.2f', $key, $threshold);
                $review['reasons'] = array_values(array_unique($reasons));
            }
        }

        return $review;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, array{title:string,url:string,reason:string}>
     */
    private function internalLinkCandidates(array $item): array
    {
        $query = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        $candidates = [];
        foreach ($query->posts as $postId) {
            $url = get_permalink((int) $postId);
            if (!$url || $url === ($item['url'] ?? '')) {
                continue;
            }
            $candidates[] = [
                'title' => get_the_title((int) $postId),
                'url' => $url,
                'reason' => 'recent_published_post',
            ];
        }

        return $candidates;
    }
}
