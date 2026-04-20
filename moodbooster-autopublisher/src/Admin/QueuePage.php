<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Admin;

use Moodbooster\AutoPub\Run\Scheduler;
use Moodbooster\AutoPub\Storage\ArtifactRepository;
use Moodbooster\AutoPub\Storage\Database;
use Moodbooster\AutoPub\Storage\QueueRepository;
use Moodbooster\AutoPub\Storage\RunRepository;
use Moodbooster\AutoPub\Util\Settings;
use const Moodbooster\AutoPub\PLUGIN_FILE;
use const Moodbooster\AutoPub\VERSION;

final class QueuePage
{
    public const SLUG = 'mb-autopub-queue';

    public static function register_page(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);

        add_management_page(
            __('Moodbooster Queue', 'moodbooster-autopub'),
            __('Moodbooster Queue', 'moodbooster-autopub'),
            'manage_options',
            self::SLUG,
            [self::class, 'render_page']
        );
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::SLUG) {
            return;
        }

        wp_enqueue_style(
            'moodbooster-autopub-admin',
            plugins_url('assets/admin.css', PLUGIN_FILE),
            [],
            VERSION
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        Database::install();

        $itemId = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        echo '<div class="wrap moodbooster-autopub">';
        echo '<h1>' . esc_html__('Moodbooster Autopublisher v2 Queue', 'moodbooster-autopub') . '</h1>';

        self::render_notice();

        if ($itemId > 0) {
            self::render_detail($itemId);
        } else {
            self::render_list();
        }

        echo '</div>';
    }

    public static function handle_action(): void
    {
        check_admin_referer('mb_autopub_queue_action');
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'moodbooster-autopub'));
        }

        Database::install();

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $action = sanitize_key((string) ($_POST['queue_action'] ?? ''));
        $queue = new QueueRepository();
        $notice = 'updated';

        if ($itemId <= 0) {
            $notice = 'missing';
        } elseif ($action === 'reject') {
            $queue->updateStatus($itemId, QueueRepository::STATUS_REJECTED, null, ['locked_at' => null]);
            $notice = 'rejected';
        } elseif ($action === 'restore') {
            $queue->updateStatus($itemId, QueueRepository::STATUS_QUEUED, null, ['locked_at' => null]);
            $notice = 'restored';
        } elseif ($action === 'generate' || $action === 'retry') {
            if ($action === 'retry') {
                $queue->updateStatus($itemId, QueueRepository::STATUS_QUEUED, null, ['locked_at' => null]);
            }
            $result = (new Scheduler())->generateItem($itemId);
            $notice = is_wp_error($result) ? 'failed' : 'generated';
        }

        wp_safe_redirect(add_query_arg(
            [
                'page' => self::SLUG,
                'item_id' => $itemId,
                'mb_queue_notice' => $notice,
            ],
            admin_url('tools.php')
        ));
        exit;
    }

    private static function render_notice(): void
    {
        $notice = isset($_GET['mb_queue_notice']) ? sanitize_key((string) $_GET['mb_queue_notice']) : '';
        if ($notice === '') {
            return;
        }

        $messages = [
            'generated' => __('Draft generated.', 'moodbooster-autopub'),
            'failed' => __('Generation failed. Check item error and artifacts.', 'moodbooster-autopub'),
            'rejected' => __('Item rejected.', 'moodbooster-autopub'),
            'restored' => __('Item restored to queue.', 'moodbooster-autopub'),
            'missing' => __('Queue item missing.', 'moodbooster-autopub'),
            'updated' => __('Queue item updated.', 'moodbooster-autopub'),
        ];

        $message = $messages[$notice] ?? '';
        if ($message !== '') {
            $class = $notice === 'failed' ? 'notice notice-error' : 'notice notice-success is-dismissible';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
        }
    }

    private static function render_list(): void
    {
        $queue = new QueueRepository();
        $settings = Settings::all();
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        $source = isset($_GET['source']) ? sanitize_key((string) $_GET['source']) : '';
        $date = isset($_GET['queue_date']) ? sanitize_text_field((string) $_GET['queue_date']) : '';
        $filters = array_filter([
            'status' => $status,
            'source' => $source,
            'date' => $date,
        ]);
        $limit = (int) ($settings['dashboard_per_page'] ?? 50);
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * $limit;
        $rows = $queue->list($filters, $limit, $offset);
        $total = $queue->count($filters);

        echo '<form method="get" class="mb-queue-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
        echo '<select name="status">';
        echo '<option value="">' . esc_html__('All statuses', 'moodbooster-autopub') . '</option>';
        foreach ([QueueRepository::STATUS_QUEUED, QueueRepository::STATUS_RUNNING, QueueRepository::STATUS_DRAFT, QueueRepository::STATUS_NEEDS_REVIEW, QueueRepository::STATUS_FAILED, QueueRepository::STATUS_REJECTED] as $option) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($option), selected($status, $option, false));
        }
        echo '</select> ';
        echo '<input type="search" name="source" value="' . esc_attr($source) . '" placeholder="' . esc_attr__('Source', 'moodbooster-autopub') . '" /> ';
        echo '<input type="date" name="queue_date" value="' . esc_attr($date) . '" /> ';
        submit_button(__('Filter'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        foreach ([__('ID', 'moodbooster-autopub'), __('Status', 'moodbooster-autopub'), __('Source', 'moodbooster-autopub'), __('Title', 'moodbooster-autopub'), __('Attempts', 'moodbooster-autopub'), __('Post', 'moodbooster-autopub'), __('Updated', 'moodbooster-autopub')] as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="7">' . esc_html__('No queue items found.', 'moodbooster-autopub') . '</td></tr>';
        }

        foreach ($rows as $row) {
            $detailUrl = add_query_arg(['page' => self::SLUG, 'item_id' => (int) $row['id']], admin_url('tools.php'));
            echo '<tr>';
            echo '<td><a href="' . esc_url($detailUrl) . '">#' . (int) $row['id'] . '</a></td>';
            echo '<td><code>' . esc_html((string) $row['status']) . '</code></td>';
            echo '<td>' . esc_html((string) $row['source']) . '</td>';
            echo '<td><a href="' . esc_url($detailUrl) . '">' . esc_html((string) $row['title']) . '</a></td>';
            echo '<td>' . (int) $row['attempts'] . '</td>';
            echo '<td>' . self::post_link((int) ($row['post_id'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) $row['updated_at']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($total > $limit) {
            $totalPages = (int) ceil($total / $limit);
            echo '<p class="tablenav-pages">';
            for ($i = 1; $i <= $totalPages; $i++) {
                $url = add_query_arg(array_merge($_GET, ['paged' => $i]), admin_url('tools.php'));
                echo $i === $page ? ' <strong>' . (int) $i . '</strong> ' : ' <a href="' . esc_url($url) . '">' . (int) $i . '</a> ';
            }
            echo '</p>';
        }
    }

    private static function render_detail(int $itemId): void
    {
        $queue = new QueueRepository();
        $runs = new RunRepository();
        $artifacts = new ArtifactRepository();
        $row = $queue->find($itemId);

        echo '<p><a href="' . esc_url(add_query_arg(['page' => self::SLUG], admin_url('tools.php'))) . '">&larr; ' . esc_html__('Back to queue', 'moodbooster-autopub') . '</a></p>';

        if (!$row) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Queue item not found.', 'moodbooster-autopub') . '</p></div>';
            return;
        }

        $item = $queue->decodeItem($row);
        echo '<h2>' . esc_html((string) ($row['title'] ?? '')) . '</h2>';
        echo '<p><strong>' . esc_html__('Status:', 'moodbooster-autopub') . '</strong> <code>' . esc_html((string) $row['status']) . '</code></p>';
        if (!empty($row['last_error'])) {
            echo '<p><strong>' . esc_html__('Last error:', 'moodbooster-autopub') . '</strong> ' . esc_html((string) $row['last_error']) . '</p>';
        }
        echo '<p><strong>' . esc_html__('Source:', 'moodbooster-autopub') . '</strong> ' . esc_html((string) $row['source']) . ' ';
        echo '<a href="' . esc_url((string) $row['url']) . '" target="_blank" rel="noopener">' . esc_html__('Open original', 'moodbooster-autopub') . '</a></p>';
        echo '<p><strong>' . esc_html__('Post:', 'moodbooster-autopub') . '</strong> ' . self::post_link((int) ($row['post_id'] ?? 0)) . '</p>';

        self::action_form($itemId, 'generate', __('Generate', 'moodbooster-autopub'), 'primary');
        self::action_form($itemId, 'retry', __('Retry', 'moodbooster-autopub'), 'secondary');
        self::action_form($itemId, 'reject', __('Reject', 'moodbooster-autopub'), 'secondary');
        self::action_form($itemId, 'restore', __('Restore to queue', 'moodbooster-autopub'), 'secondary');

        echo '<h3>' . esc_html__('Source item', 'moodbooster-autopub') . '</h3>';
        self::json_block($item);

        echo '<h3>' . esc_html__('Runs', 'moodbooster-autopub') . '</h3>';
        self::json_block($runs->forItem($itemId));

        echo '<h3>' . esc_html__('Artifacts', 'moodbooster-autopub') . '</h3>';
        foreach ($artifacts->forItem($itemId) as $artifact) {
            echo '<details class="mb-artifact" open>';
            echo '<summary><strong>' . esc_html((string) $artifact['stage']) . '</strong> #' . (int) $artifact['id'] . ' ' . esc_html((string) $artifact['created_at']) . '</summary>';
            self::json_block($artifact['decoded_payload'] ?? $artifact['payload']);
            echo '</details>';
        }
    }

    private static function action_form(int $itemId, string $action, string $label, string $class): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mb-inline-action">';
        echo '<input type="hidden" name="action" value="mb_autopub_queue_action" />';
        echo '<input type="hidden" name="queue_action" value="' . esc_attr($action) . '" />';
        echo '<input type="hidden" name="item_id" value="' . (int) $itemId . '" />';
        wp_nonce_field('mb_autopub_queue_action');
        submit_button($label, $class, 'submit', false);
        echo '</form> ';
    }

    private static function json_block($value): void
    {
        echo '<pre class="mb-json-block">' . esc_html(wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }

    private static function post_link(int $postId): string
    {
        if ($postId <= 0) {
            return '&mdash;';
        }

        $link = get_edit_post_link($postId);
        if (!$link) {
            return '#' . (int) $postId;
        }

        return '<a href="' . esc_url($link) . '">#' . (int) $postId . '</a>';
    }
}
