<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Storage;

final class Database
{
    public const SCHEMA_VERSION = '2.0.0';
    public const OPTION_SCHEMA_VERSION = 'mb_autopub_schema_version';

    public static function install(): void
    {
        global $wpdb;

        if (get_option(self::OPTION_SCHEMA_VERSION) === self::SCHEMA_VERSION) {
            $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::itemsTable()));
            if ($existing === self::itemsTable()) {
                return;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $items = self::itemsTable();
        $runs = self::runsTable();
        $artifacts = self::artifactsTable();

        dbDelta("
CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(64) NOT NULL,
  url text NOT NULL,
  canonical_url varchar(2048) NOT NULL,
  fingerprint char(40) NOT NULL,
  title text NOT NULL,
  item_json longtext NOT NULL,
  status varchar(32) NOT NULL DEFAULT 'queued',
  post_id bigint(20) unsigned DEFAULT NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  last_error text DEFAULT NULL,
  locked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_fingerprint (source, fingerprint),
  KEY status_updated (status, updated_at),
  KEY post_id (post_id)
) {$charsetCollate};
        ");

        dbDelta("
CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  status varchar(32) NOT NULL DEFAULT 'running',
  model_map longtext DEFAULT NULL,
  usage_json longtext DEFAULT NULL,
  error_summary text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY item_id (item_id),
  KEY status (status)
) {$charsetCollate};
        ");

        dbDelta("
CREATE TABLE {$artifacts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  run_id bigint(20) unsigned DEFAULT NULL,
  stage varchar(64) NOT NULL,
  payload longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY item_stage (item_id, stage),
  KEY run_id (run_id)
) {$charsetCollate};
        ");

        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
    }

    public static function itemsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mb_autopub_items';
    }

    public static function runsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mb_autopub_runs';
    }

    public static function artifactsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mb_autopub_artifacts';
    }
}
