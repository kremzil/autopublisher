<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Storage;

final class QueueRepository
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DRAFT = 'draft_created';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $item
     */
    public function upsertItem(array $item): int
    {
        global $wpdb;

        $source = sanitize_key((string) ($item['source'] ?? 'unknown'));
        $url = esc_url_raw((string) ($item['url'] ?? ''));
        $fingerprint = (string) ($item['fingerprint'] ?? sha1($source . $url));
        $now = current_time('mysql', true);
        $table = Database::itemsTable();

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE source = %s AND fingerprint = %s LIMIT 1",
                $source,
                $fingerprint
            ),
            ARRAY_A
        );

        if (is_array($existing) && !empty($existing['id'])) {
            $status = (string) ($existing['status'] ?? '');
            $updates = [
                'url' => $url,
                'canonical_url' => $url,
                'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                'item_json' => wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ];

            if ($status === self::STATUS_FAILED) {
                $updates['status'] = self::STATUS_QUEUED;
                $updates['last_error'] = null;
            }

            $wpdb->update($table, $updates, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        $wpdb->insert($table, [
            'source' => $source,
            'url' => $url,
            'canonical_url' => $url,
            'fingerprint' => $fingerprint,
            'title' => sanitize_text_field((string) ($item['title'] ?? '')),
            'item_json' => wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => self::STATUS_QUEUED,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextQueued(int $limit, ?string $source = null): array
    {
        global $wpdb;

        $table = Database::itemsTable();
        $limit = max(1, $limit);
        if ($source !== null && $source !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = %s AND source = %s ORDER BY created_at ASC LIMIT %d",
                    self::STATUS_QUEUED,
                    sanitize_key($source),
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
                    self::STATUS_QUEUED,
                    $limit
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, string> $sources
     */
    public function deleteQueuedForSources(array $sources): int
    {
        global $wpdb;

        $sources = array_values(array_filter(array_map('sanitize_key', $sources)));
        if ($sources === []) {
            return 0;
        }

        $table = Database::itemsTable();
        $placeholders = implode(',', array_fill(0, count($sources), '%s'));
        $sql = $wpdb->prepare(
            "DELETE FROM {$table} WHERE status = %s AND source IN ({$placeholders})",
            array_merge([self::STATUS_QUEUED], $sources)
        );

        $wpdb->query($sql);

        return (int) $wpdb->rows_affected;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . Database::itemsTable() . " WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        global $wpdb;

        $where = ['1=1'];
        $args = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $args[] = sanitize_key((string) $filters['status']);
        }
        if (!empty($filters['source'])) {
            $where[] = 'source = %s';
            $args[] = sanitize_key((string) $filters['source']);
        }
        if (!empty($filters['date'])) {
            $where[] = 'created_at LIKE %s';
            $args[] = sanitize_text_field((string) $filters['date']) . '%';
        }

        $sql = "SELECT * FROM " . Database::itemsTable() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
        $args[] = max(1, $limit);
        $args[] = max(0, $offset);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function count(array $filters = []): int
    {
        global $wpdb;

        $where = ['1=1'];
        $args = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $args[] = sanitize_key((string) $filters['status']);
        }
        if (!empty($filters['source'])) {
            $where[] = 'source = %s';
            $args[] = sanitize_key((string) $filters['source']);
        }
        if (!empty($filters['date'])) {
            $where[] = 'created_at LIKE %s';
            $args[] = sanitize_text_field((string) $filters['date']) . '%';
        }

        $sql = "SELECT COUNT(*) FROM " . Database::itemsTable() . ' WHERE ' . implode(' AND ', $where);

        return (int) $wpdb->get_var($args === [] ? $sql : $wpdb->prepare($sql, $args));
    }

    public function markRunning(int $itemId): void
    {
        $this->updateStatus($itemId, self::STATUS_RUNNING, null, [
            'locked_at' => current_time('mysql', true),
        ]);
    }

    public function markDraft(int $itemId, int $postId, bool $needsReview): void
    {
        $this->updateStatus($itemId, $needsReview ? self::STATUS_NEEDS_REVIEW : self::STATUS_DRAFT, null, [
            'post_id' => $postId,
            'locked_at' => null,
        ]);
    }

    public function markFailed(int $itemId, string $error): void
    {
        $this->updateStatus($itemId, self::STATUS_FAILED, $error, [
            'locked_at' => null,
        ]);
    }

    public function updateStatus(int $itemId, string $status, ?string $error = null, array $extra = []): void
    {
        global $wpdb;

        $data = array_merge([
            'status' => sanitize_key($status),
            'last_error' => $error,
            'updated_at' => current_time('mysql', true),
        ], $extra);

        if ($status === self::STATUS_RUNNING) {
            $data['attempts'] = new \stdClass();
        }

        $attemptsSql = '';
        if (isset($data['attempts']) && $data['attempts'] instanceof \stdClass) {
            unset($data['attempts']);
            $attemptsSql = ', attempts = attempts + 1';
        }

        $sets = [];
        $args = [];
        foreach ($data as $key => $value) {
            $sets[] = "{$key} = " . ($value === null ? 'NULL' : '%s');
            if ($value !== null) {
                $args[] = $value;
            }
        }
        $args[] = $itemId;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Database::itemsTable() . ' SET ' . implode(', ', $sets) . $attemptsSql . ' WHERE id = %d',
                $args
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeItem(array $row): array
    {
        $decoded = json_decode((string) ($row['item_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
