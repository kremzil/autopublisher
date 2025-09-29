<?php
declare(strict_types=1);

namespace Moodbooster\AutoPub\Util;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class Log
{
    private const TRANSIENT_KEY = 'mb_autopub_recent_log';
    private const TRANSIENT_LINES = 200;

    public static function info(string $source, string $action, string $message, array $context = []): void
    {
        self::write('INFO', $source, $action, $message, $context);
    }

    public static function warn(string $source, string $action, string $message, array $context = []): void
    {
        self::write('WARN', $source, $action, $message, $context);
    }

    public static function error(string $source, string $action, string $message, array $context = []): void
    {
        self::write('ERROR', $source, $action, $message, $context);
    }

    /**
     * @param string $level
     * @param string $source
     * @param string $action
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function write(string $level, string $source, string $action, string $message, array $context = []): void
    {
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $line = sprintf(
            "%s\t%s\t%s\t%s\t%s\t%s",
            $timestamp->format(DateTimeImmutable::ATOM),
            $level,
            $source,
            $action,
            $context['post_id'] ?? '-',
            $message
        );

        $logDir = self::logDir();
        if (!is_dir($logDir) && !wp_mkdir_p($logDir)) {
            return;
        }

        $filename = $logDir . '/' . $timestamp->format('Y-m-d') . '.log';
        $contextStr = '';
        if ($context !== []) {
            $contextStr = '\t' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        file_put_contents($filename, $line . $contextStr . PHP_EOL, FILE_APPEND | LOCK_EX);
        self::cacheRecent($line . $contextStr);
    }

    /**
     * @return array<string, mixed>
     */
    public static function recent(): array
    {
        $lines = get_transient(self::TRANSIENT_KEY);
        if (!\is_array($lines)) {
            return [];
        }

        return $lines;
    }

    public static function purge(int $days): void
    {
        if ($days <= 0) {
            return;
        }

        $dir = self::logDir();
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('-%d days', $days));
        $iterator = scandir($dir);
        if ($iterator === false) {
            return;
        }

        foreach ($iterator as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }

            try {
                $date = new DateTimeImmutable(str_replace('.log', '', $entry), new DateTimeZone('UTC'));
            } catch (Exception $e) {
                continue;
            }

            if ($date < $cutoff) {
                unlink($path);
            }
        }
    }

    private static function cacheRecent(string $line): void
    {
        $lines = get_transient(self::TRANSIENT_KEY);
        if (!\is_array($lines)) {
            $lines = [];
        }

        $lines[] = $line;
        $lines = array_slice($lines, -self::TRANSIENT_LINES);
        set_transient(self::TRANSIENT_KEY, $lines, DAY_IN_SECONDS);
    }

    private static function logDir(): string
    {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'moodbooster-autopub/logs';

        return rtrim($base, '/');
    }
}
