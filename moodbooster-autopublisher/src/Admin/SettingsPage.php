<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Admin;

use Moodbooster\AutoPub\Run\Scheduler;
use Moodbooster\AutoPub\Util\Log;
use Moodbooster\AutoPub\Util\Settings;
use const Moodbooster\AutoPub\PLUGIN_FILE;
use const Moodbooster\AutoPub\VERSION;

final class SettingsPage
{
    public const SLUG = 'mb-autopub';

    public function register(): void
    {
        add_options_page(
            __('Moodbooster Autopublisher', 'moodbooster-autopub'),
            __('Moodbooster Autopublisher', 'moodbooster-autopub'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );

        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function register_settings(): void
    {
        register_setting(Settings::OPTION_KEY, Settings::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);

        add_settings_section('mb_main', __('General', 'moodbooster-autopub'), '__return_false', self::SLUG);
        add_settings_field('api_key', __('OpenAI API Key', 'moodbooster-autopub'), [$this, 'field_api_key'], self::SLUG, 'mb_main');
        add_settings_field('fetch_mode', __('Fetch mode', 'moodbooster-autopub'), [$this, 'field_fetch_mode'], self::SLUG, 'mb_main');
        add_settings_field('publish_mode', __('Publishing mode', 'moodbooster-autopub'), [$this, 'field_publish_mode'], self::SLUG, 'mb_main');
        add_settings_field('cadence', __('Run cadence', 'moodbooster-autopub'), [$this, 'field_cadence'], self::SLUG, 'mb_main');
        add_settings_field('max_per_run', __('Max new posts per run', 'moodbooster-autopub'), [$this, 'field_max_per_run'], self::SLUG, 'mb_main');
        add_settings_field('category', __('Publication category', 'moodbooster-autopub'), [$this, 'field_category'], self::SLUG, 'mb_main');

        add_settings_section('mb_sources', __('Sources', 'moodbooster-autopub'), '__return_false', self::SLUG);
        add_settings_field('sources', __('Enabled sources', 'moodbooster-autopub'), [$this, 'field_sources'], self::SLUG, 'mb_sources');

        add_settings_section('mb_images', __('Images', 'moodbooster-autopub'), '__return_false', self::SLUG);
        add_settings_field('image_rules', __('Image requirements', 'moodbooster-autopub'), [$this, 'field_image_rules'], self::SLUG, 'mb_images');

        add_settings_section('mb_dedupe', __('Deduplication', 'moodbooster-autopub'), '__return_false', self::SLUG);
        add_settings_field('dedupe', __('Strategies', 'moodbooster-autopub'), [$this, 'field_dedupe'], self::SLUG, 'mb_dedupe');
        add_settings_field('title_blocklist', __('Title blocklist', 'moodbooster-autopub'), [$this, 'field_title_blocklist'], self::SLUG, 'mb_dedupe');

        add_settings_section('mb_misc', __('Miscellaneous', 'moodbooster-autopub'), '__return_false', self::SLUG);
        add_settings_field('attribution_footer', __('Attribution footer', 'moodbooster-autopub'), [$this, 'field_attribution'], self::SLUG, 'mb_misc');
        add_settings_field('log_retention_days', __('Log retention (days)', 'moodbooster-autopub'), [$this, 'field_log_retention'], self::SLUG, 'mb_misc');
    }

    /**
     * @param array<string, mixed>|false $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $defaults = Settings::defaults();
        $input = \is_array($input) ? wp_unslash($input) : [];
        $clean = [];

        $clean['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : $defaults['api_key'];

        $clean['fetch_mode'] = in_array($input['fetch_mode'] ?? '', ['rss_html', 'html_only'], true) ? $input['fetch_mode'] : $defaults['fetch_mode'];
        $clean['publish_mode'] = in_array($input['publish_mode'] ?? '', ['draft', 'publish'], true) ? $input['publish_mode'] : $defaults['publish_mode'];
        $clean['cadence'] = in_array($input['cadence'] ?? '', ['hourly', 'twicedaily', 'daily'], true) ? $input['cadence'] : $defaults['cadence'];
        $clean['max_per_run'] = max(1, (int) ($input['max_per_run'] ?? $defaults['max_per_run']));
        $clean['category'] = max(0, (int) ($input['category'] ?? 0));

        $sources = $defaults['sources'];
        foreach ($sources as $key => $value) {
            $sources[$key] = !empty($input['sources'][$key]);
        }
        $clean['sources'] = $sources;

        $clean['image_min_width'] = max(320, (int) ($input['image_min_width'] ?? $defaults['image_min_width']));
        $clean['image_min_height'] = max(180, (int) ($input['image_min_height'] ?? $defaults['image_min_height']));
        $clean['image_force_ratio'] = !empty($input['image_force_ratio']);
        $clean['image_skip_under_min'] = !empty($input['image_skip_under_min']);

        $clean['dedupe_url'] = !empty($input['dedupe_url']);
        $clean['dedupe_title'] = !empty($input['dedupe_title']);
        $clean['dedupe_embeddings'] = !empty($input['dedupe_embeddings']);

        $blocklist = $input['title_blocklist'] ?? [];
        if (!\is_array($blocklist)) {
            $blocklist = explode("\n", (string) $blocklist);
        }
        $blocklist = array_filter(array_map(static fn($item) => sanitize_text_field((string) $item), $blocklist));
        $clean['title_blocklist'] = array_values(array_unique($blocklist));

        $clean['attribution_footer'] = !empty($input['attribution_footer']);
        $clean['log_retention_days'] = max(1, (int) ($input['log_retention_days'] ?? $defaults['log_retention_days']));

        Scheduler::maybe_reschedule($clean['cadence']);

        return $clean;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = Settings::all();
        $nonce_run = wp_create_nonce('mb_autopub_run_now');
        $nonce_purge = wp_create_nonce('mb_autopub_purge_logs');
        $nonce_download = wp_create_nonce('mb_autopub_download_logs');

        echo '<div class="wrap moodbooster-autopub">';
        echo '<h1>' . esc_html__('Moodbooster Autopublisher', 'moodbooster-autopub') . '</h1>';

        settings_errors(Settings::OPTION_KEY);

        echo '<form method="post" action="options.php" class="mb-settings">';
        settings_fields(Settings::OPTION_KEY);
        do_settings_sections(self::SLUG);
        submit_button(__('Save Settings', 'moodbooster-autopub'));
        echo '</form>';

        echo '<hr />';
        echo '<div class="mb-actions">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mb_autopub_run_now" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_run) . '" />';
        submit_button(__('Run now', 'moodbooster-autopub'), 'primary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mb_autopub_purge_logs" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_purge) . '" />';
        submit_button(__('Purge logs', 'moodbooster-autopub'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mb_autopub_download_logs" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_download) . '" />';
        submit_button(__('Download logs (zip)', 'moodbooster-autopub'), 'secondary', 'submit', false);
        echo '</form>';

        echo '</div>';
        echo '</div>';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::SLUG) {
            return;
        }

wp_enqueue_style(
    'moodbooster-autopub-admin',
    plugins_url('assets/admin.css', PLUGIN_FILE),
    [],
    VERSION
);
    }

    public static function handle_run_now(): void
    {
        check_admin_referer('mb_autopub_run_now');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'moodbooster-autopub'));
        }

        do_action('moodbooster_autopub_run');
        Log::info('admin', 'run_now', 'Manual run triggered');

        wp_safe_redirect(add_query_arg('mb_autopub_notice', 'run_now', wp_get_referer() ?: admin_url('options-general.php?page=' . self::SLUG)));
        exit;
    }

    public static function handle_purge_logs(): void
    {
        check_admin_referer('mb_autopub_purge_logs');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'moodbooster-autopub'));
        }

        $options = Settings::all();
        Log::purge((int) ($options['log_retention_days'] ?? 30));
        Log::info('admin', 'purge_logs', 'Logs purged');

        wp_safe_redirect(add_query_arg('mb_autopub_notice', 'purged', wp_get_referer() ?: admin_url('options-general.php?page=' . self::SLUG)));
        exit;
    }

    public static function handle_download_logs(): void
    {
        check_admin_referer('mb_autopub_download_logs');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'moodbooster-autopub'));
        }

        $uploads = wp_upload_dir();
        $logDir = trailingslashit($uploads['basedir']) . 'moodbooster-autopub/logs';
        if (!is_dir($logDir)) {
            wp_safe_redirect(add_query_arg('mb_autopub_notice', 'no_logs', wp_get_referer() ?: admin_url('options-general.php?page=' . self::SLUG)));
            exit;
        }

        $zipName = 'moodbooster-logs-' . gmdate('Ymd-His') . '.zip';
        $zipPath = wp_tempnam($zipName);
        if ($zipPath === false) {
            wp_safe_redirect(add_query_arg('mb_autopub_notice', 'zip_fail', wp_get_referer() ?: admin_url('options-general.php?page=' . self::SLUG)));
            exit;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            wp_safe_redirect(add_query_arg('mb_autopub_notice', 'zip_fail', wp_get_referer() ?: admin_url('options-general.php?page=' . self::SLUG)));
            exit;
        }

        $files = scandir($logDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $logDir . '/' . $file;
            if (is_file($path)) {
                $zip->addFile($path, $file);
            }
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipName) . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }

            public static function admin_notices(): void
    {
        if (!isset($_GET['mb_autopub_notice'])) {
            return;
        }

        $key = sanitize_text_field((string) $_GET['mb_autopub_notice']);
        $messages = [
            'run_now' => __('Manual run dispatched. Check logs for progress.', 'moodbooster-autopub'),
            'purged' => __('Logs purged according to retention policy.', 'moodbooster-autopub'),
            'no_logs' => __('No log files found to download.', 'moodbooster-autopub'),
            'zip_fail' => __('Unable to prepare log archive.', 'moodbooster-autopub'),
        ];

        $message = $messages[$key] ?? '';

        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
    public function field_api_key(): void
    {
        $o = Settings::all();
        printf(
            '<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" />',
            esc_attr(Settings::OPTION_KEY),
            esc_attr($o['api_key'] ?? '')
        );
        echo '<p class="description">' . esc_html__('Stored securely in the database.', 'moodbooster-autopub') . '</p>';
    }

    public function field_fetch_mode(): void
    {
        $o = Settings::all();
        $value = $o['fetch_mode'] ?? 'rss_html';
        printf(
            '<label><input type="radio" name="%1$s[fetch_mode]" value="rss_html" %2$s /> %3$s</label><br />',
            esc_attr(Settings::OPTION_KEY),
            checked('rss_html', $value, false),
            esc_html__('RSS preferred, HTML fallback', 'moodbooster-autopub')
        );
        printf(
            '<label><input type="radio" name="%1$s[fetch_mode]" value="html_only" %2$s /> %3$s</label>',
            esc_attr(Settings::OPTION_KEY),
            checked('html_only', $value, false),
            esc_html__('HTML only', 'moodbooster-autopub')
        );
    }

    public function field_publish_mode(): void
    {
        $o = Settings::all();
        $value = $o['publish_mode'] ?? 'draft';
        printf(
            '<label><input type="radio" name="%1$s[publish_mode]" value="draft" %2$s /> %3$s</label><br />',
            esc_attr(Settings::OPTION_KEY),
            checked('draft', $value, false),
            esc_html__('Draft', 'moodbooster-autopub')
        );
        printf(
            '<label><input type="radio" name="%1$s[publish_mode]" value="publish" %2$s /> %3$s</label>',
            esc_attr(Settings::OPTION_KEY),
            checked('publish', $value, false),
            esc_html__('Publish immediately', 'moodbooster-autopub')
        );
    }

    public function field_cadence(): void
    {
        $o = Settings::all();
        $value = $o['cadence'] ?? 'daily';
        $options = [
            'hourly' => __('Hourly', 'moodbooster-autopub'),
            'twicedaily' => __('Twice daily', 'moodbooster-autopub'),
            'daily' => __('Daily', 'moodbooster-autopub'),
        ];
        foreach ($options as $key => $label) {
            printf(
                '<label><input type="radio" name="%1$s[cadence]" value="%2$s" %3$s /> %4$s</label><br />',
                esc_attr(Settings::OPTION_KEY),
                esc_attr($key),
                checked($key, $value, false),
                esc_html($label)
            );
        }
    }

    public function field_max_per_run(): void
    {
        $o = Settings::all();
        printf(
            '<input type="number" min="1" max="10" name="%1$s[max_per_run]" value="%2$d" class="small-text" />',
            esc_attr(Settings::OPTION_KEY),
            (int) ($o['max_per_run'] ?? 3)
        );
    }

    public function field_category(): void
    {
        $o = Settings::all();
        $selected = (int) ($o['category'] ?? 0);
        $dropdown = wp_dropdown_categories([
            'name' => Settings::OPTION_KEY . '[category]',
            'selected' => $selected,
            'taxonomy' => 'category',
            'hide_empty' => false,
            'echo' => false,
        ]);
        if ($dropdown) {
            echo $dropdown;
        }
    }

    public function field_sources(): void
    {
        $o = Settings::all();
        $sources = $o['sources'] ?? [];
        foreach ($this->sourcesList() as $key => $label) {
            printf(
                '<label><input type="checkbox" name="%1$s[sources][%2$s]" value="1" %3$s /> %4$s</label><br />',
                esc_attr(Settings::OPTION_KEY),
                esc_attr($key),
                checked(!empty($sources[$key]), true, false),
                esc_html($label)
            );
        }
    }

    public function field_image_rules(): void
    {
        $o = Settings::all();
        printf(
            '<label>%4$s <input type="number" min="320" name="%1$s[image_min_width]" value="%2$d" class="small-text" /></label>&nbsp;&nbsp;'
            . '<label>%5$s <input type="number" min="180" name="%1$s[image_min_height]" value="%3$d" class="small-text" /></label><br />'
            . '<label><input type="checkbox" name="%1$s[image_force_ratio]" value="1" %6$s /> %7$s</label><br />'
            . '<label><input type="checkbox" name="%1$s[image_skip_under_min]" value="1" %8$s /> %9$s</label>',
            esc_attr(Settings::OPTION_KEY),
            (int) ($o['image_min_width'] ?? 1200),
            (int) ($o['image_min_height'] ?? 675),
            esc_html__('Min width', 'moodbooster-autopub'),
            esc_html__('Min height', 'moodbooster-autopub'),
            checked(!empty($o['image_force_ratio']), true, false),
            esc_html__('Force 16:9 crop', 'moodbooster-autopub'),
            checked(!empty($o['image_skip_under_min']), true, false),
            esc_html__('Skip article if image below threshold', 'moodbooster-autopub')
        );
    }

    public function field_dedupe(): void
    {
        $o = Settings::all();
        printf(
            '<label><input type="checkbox" name="%1$s[dedupe_url]" value="1" %2$s /> %3$s</label><br />'
            . '<label><input type="checkbox" name="%1$s[dedupe_title]" value="1" %4$s /> %5$s</label><br />'
            . '<label><input type="checkbox" name="%1$s[dedupe_embeddings]" value="1" %6$s /> %7$s</label>',
            esc_attr(Settings::OPTION_KEY),
            checked(!empty($o['dedupe_url']), true, false),
            esc_html__('Fingerprint (URL) dedupe', 'moodbooster-autopub'),
            checked(!empty($o['dedupe_title']), true, false),
            esc_html__('Title similarity dedupe', 'moodbooster-autopub'),
            checked(!empty($o['dedupe_embeddings']), true, false),
            esc_html__('Embedding similarity dedupe', 'moodbooster-autopub')
        );
    }

    public function field_title_blocklist(): void
    {
        $o = Settings::all();
        $value = implode("\n", $o['title_blocklist'] ?? []);
        printf(
            '<textarea name="%1$s[title_blocklist]" rows="4" cols="40" class="large-text code">%2$s</textarea>',
            esc_attr(Settings::OPTION_KEY),
            esc_textarea($value)
        );
        echo '<p class="description">' . esc_html__('One term per line. Matching titles will be skipped.', 'moodbooster-autopub') . '</p>';
    }

    public function field_attribution(): void
    {
        $o = Settings::all();
        printf(
            '<label><input type="checkbox" name="%1$s[attribution_footer]" value="1" %2$s /> %3$s</label>',
            esc_attr(Settings::OPTION_KEY),
            checked(!empty($o['attribution_footer']), true, false),
            esc_html__('Append attribution footer with source link', 'moodbooster-autopub')
        );
    }

    public function field_log_retention(): void
    {
        $o = Settings::all();
        printf(
            '<input type="number" min="1" max="365" name="%1$s[log_retention_days]" value="%2$d" class="small-text" />',
            esc_attr(Settings::OPTION_KEY),
            (int) ($o['log_retention_days'] ?? 30)
        );
    }

    /**
     * @return array<string, string>
     */
    private function sourcesList(): array
    {
        return [
            'fashionpost' => __('FashionPost.pl', 'moodbooster-autopub'),
            'europawire' => __('EuropaWire', 'moodbooster-autopub'),
            'ellepolska' => __('Elle Polska', 'moodbooster-autopub'),
            'miastokobiet' => __('Miasto Kobiet', 'moodbooster-autopub'),
            'marieclairehu' => __('Marie Claire Hungary', 'moodbooster-autopub'),
            'fashionstreethu' => __('Fashion Street Online', 'moodbooster-autopub'),
            'lofficielbe' => __('L\'Officiel Belgique', 'moodbooster-autopub'),
            'togethermag' => __('Together Magazine', 'moodbooster-autopub'),
        ];
    }
}
