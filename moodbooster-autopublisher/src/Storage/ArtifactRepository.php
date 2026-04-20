<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Storage;

final class ArtifactRepository
{
    /**
     * @param mixed $payload
     */
    public function save(int $itemId, ?int $runId, string $stage, $payload): void
    {
        global $wpdb;

        $wpdb->insert(Database::artifactsTable(), [
            'item_id' => $itemId,
            'run_id' => $runId,
            'stage' => sanitize_key($stage),
            'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => current_time('mysql', true),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forItem(int $itemId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Database::artifactsTable() . " WHERE item_id = %d ORDER BY id ASC",
                $itemId
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['payload'] ?? ''), true);
            $row['decoded_payload'] = is_array($decoded) ? $decoded : $row['payload'];
        }
        unset($row);

        return $rows;
    }
}
