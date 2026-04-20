<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Storage;

final class RunRepository
{
    public function start(int $itemId, array $modelMap): int
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->insert(Database::runsTable(), [
            'item_id' => $itemId,
            'status' => 'running',
            'model_map' => wp_json_encode($modelMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    public function finish(int $runId, string $status, ?string $error = null, array $usage = []): void
    {
        global $wpdb;

        $wpdb->update(Database::runsTable(), [
            'status' => sanitize_key($status),
            'usage_json' => $usage === [] ? null : wp_json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_summary' => $error,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $runId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forItem(int $itemId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Database::runsTable() . " WHERE item_id = %d ORDER BY id DESC",
                $itemId
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
