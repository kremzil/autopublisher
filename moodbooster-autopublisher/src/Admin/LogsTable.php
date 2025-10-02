<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Admin;

use Moodbooster\AutoPub\Util\Log;

if (!class_exists('\\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LogsTable extends \WP_List_Table
{
    private const PER_PAGE = 20;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $itemsData = [];

    public static function register_page(): void
    {
        add_action('admin_post_mb_autopub_clear_logs', [self::class, 'handle_clear_logs']);
        add_management_page(
            __('Moodbooster Logs', 'moodbooster-autopub'),
            __('Moodbooster Logs', 'moodbooster-autopub'),
            'manage_options',
            'mb-autopub-logs',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        $table = new self();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Moodbooster Logs', 'moodbooster-autopub') . '</h1>';
        $notice = isset($_GET['mb_autopub_logs_notice']) ? sanitize_text_field((string) $_GET['mb_autopub_logs_notice']) : '';
        if ($notice !== '') {
            $messages = [
                'cleared' => __('Logs cleared.', 'moodbooster-autopub'),
                'clear_failed' => __('Unable to clear logs.', 'moodbooster-autopub'),
            ];
            $message = $messages[$notice] ?? '';
            if ($message !== '') {
                $class = $notice === 'cleared' ? 'notice notice-success is-dismissible' : 'notice notice-error';
                echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
            }
        }

        $recent = Log::recent();
        echo '<h2>' . esc_html__('Recent entries', 'moodbooster-autopub') . '</h2>';
        if ($recent !== []) {
            echo '<textarea class="widefat mb-log-view" rows="10" readonly>' . esc_textarea(implode("\n", $recent)) . '</textarea>';
        } else {
            echo '<p>' . esc_html__('No log entries recorded yet.', 'moodbooster-autopub') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mb-log-clear">';
        echo '<input type="hidden" name="action" value="mb_autopub_clear_logs" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('mb_autopub_clear_logs')) . '" />';
        submit_button(__('Clear log', 'moodbooster-autopub'), 'secondary', 'submit', false);
        echo '</form>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="mb-autopub-logs" />';
        foreach (['level', 'source', 'log_date'] as $keep) {
            if (isset($_REQUEST[$keep])) {
                printf('<input type="hidden" name="%s" value="%s" />', esc_attr($keep), esc_attr((string) $_REQUEST[$keep]));
            }
        }
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    public static function handle_clear_logs(): void
    {
        check_admin_referer('mb_autopub_clear_logs');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'moodbooster-autopub'));
        }

        $result = Log::clearAll();
        if ($result) {
            Log::info('admin', 'clear_logs', 'All log files cleared');
        }

        $notice = $result ? 'cleared' : 'clear_failed';
        wp_safe_redirect(add_query_arg(
            'mb_autopub_logs_notice',
            $notice,
            admin_url('tools.php?page=mb-autopub-logs')
        ));
        exit;
    }
    public function __construct()
    {
        parent::__construct([
            'plural' => 'logs',
            'singular' => 'log',
            'ajax' => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'timestamp' => __('Timestamp', 'moodbooster-autopub'),
            'level' => __('Level', 'moodbooster-autopub'),
            'source' => __('Source', 'moodbooster-autopub'),
            'action' => __('Action', 'moodbooster-autopub'),
            'post_id' => __('Post', 'moodbooster-autopub'),
            'message' => __('Message', 'moodbooster-autopub'),
        ];
    }

    protected function column_default($item, $column_name)
    {
        return $item[$column_name] ?? '';
    }

    protected function column_message($item): string
    {
        $message = esc_html($item['message'] ?? '');
        if (!empty($item['context'])) {
            $message .= '<br /><code>' . esc_html(wp_json_encode($item['context'])) . '</code>';
        }

        return $message;
    }

    protected function column_post_id($item): string
    {
        $postId = (int) ($item['post_id'] ?? 0);
        if ($postId > 0) {
            $link = get_edit_post_link($postId);
            if ($link) {
                return sprintf('<a href="%s">#%d</a>', esc_url($link), $postId);
            }
        }

        if (!empty($item['context']['url'])) {
            return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($item['context']['url']), esc_html__('External', 'moodbooster-autopub'));
        }

        return '&mdash;';
    }

    protected function get_sortable_columns(): array
    {
        return [
            'timestamp' => ['timestamp', true],
            'level' => ['level', false],
        ];
    }

    public function prepare_items(): void
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $date = isset($_REQUEST['log_date']) ? sanitize_text_field((string) $_REQUEST['log_date']) : null;
        $level = isset($_REQUEST['level']) ? sanitize_text_field((string) $_REQUEST['level']) : '';
        $source = isset($_REQUEST['source']) ? sanitize_text_field((string) $_REQUEST['source']) : '';

        $allItems = $this->load_logs($date);
        if ($level !== '') {
            $allItems = array_filter($allItems, static fn($item) => strcasecmp((string) $item['level'], $level) === 0);
        }

        if ($source !== '') {
            $allItems = array_filter($allItems, static fn($item) => stripos((string) $item['source'], $source) !== false);
        }

        $current_page = $this->get_pagenum();
        $total_items = count($allItems);
        $this->itemsData = array_values($allItems);

        $this->items = array_slice($this->itemsData, ($current_page - 1) * self::PER_PAGE, self::PER_PAGE);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => self::PER_PAGE,
        ]);
    }

    public function no_items(): void
    {
        esc_html_e('No logs found for the selected filters.', 'moodbooster-autopub');
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        $dates = $this->log_dates();
        $selected_date = sanitize_text_field((string) ($_REQUEST['log_date'] ?? ''));
        $level = sanitize_text_field((string) ($_REQUEST['level'] ?? ''));
        $source = sanitize_text_field((string) ($_REQUEST['source'] ?? ''));

        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="mb-log-date">' . esc_html__('Filter by date', 'moodbooster-autopub') . '</label>';
        echo '<select name="log_date" id="mb-log-date">';
        echo '<option value="">' . esc_html__('All dates', 'moodbooster-autopub') . '</option>';
        foreach ($dates as $date) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($date), selected($selected_date, $date, false));
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="mb-log-level">' . esc_html__('Filter by level', 'moodbooster-autopub') . '</label>';
        echo '<select name="level" id="mb-log-level">';
        echo '<option value="">' . esc_html__('All levels', 'moodbooster-autopub') . '</option>';
        foreach (['INFO', 'WARN', 'ERROR'] as $lvl) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($lvl), selected($level, $lvl, false));
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="mb-log-source">' . esc_html__('Filter by source', 'moodbooster-autopub') . '</label>';
        echo '<input type="search" name="source" id="mb-log-source" value="' . esc_attr($source) . '" placeholder="' . esc_attr__('Source', 'moodbooster-autopub') . '" />';

        submit_button(__('Filter'), '', 'filter_action', false);
        echo '</div>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function load_logs(?string $date): array
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'moodbooster-autopub/logs';
        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir) ?: [];
        $logFiles = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (!self::endsWith($file, '.log')) {
                continue;
            }
            if ($date !== null && $date !== '' && !self::startsWith($file, $date)) {
                continue;
            }
            $logFiles[] = $dir . '/' . $file;
        }

        sort($logFiles);

        $rows = [];
        foreach ($logFiles as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $rows[] = $this->parse_line($line);
            }
        }

        usort($rows, static fn($a, $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function log_dates(): array
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'moodbooster-autopub/logs';
        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir) ?: [];
        $dates = [];
        foreach ($files as $file) {
            if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})/', $file, $m)) {
                $dates[] = $m[1];
            }
        }

        $dates = array_values(array_unique($dates));
        rsort($dates);

        return $dates;
    }

    /**
     * @param string $line
     * @return array<string, mixed>
     */
    private function parse_line(string $line): array
    {
        $parts = explode("\t", $line, 7);
        $data = [
            'timestamp' => $parts[0] ?? '',
            'level' => $parts[1] ?? '',
            'source' => $parts[2] ?? '',
            'action' => $parts[3] ?? '',
            'post_id' => $parts[4] ?? '',
            'message' => $parts[5] ?? '',
            'context' => [],
        ];

        if (isset($parts[6])) {
            $decoded = json_decode($parts[6], true);
            if (\is_array($decoded)) {
                $data['context'] = $decoded;
            }
        }

        return $data;
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        if (strlen($needle) > strlen($haystack)) {
            return false;
        }

        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $length = strlen($needle);
        if ($length > strlen($haystack)) {
            return false;
        }

        return substr($haystack, -$length) === $needle;
    }
}
