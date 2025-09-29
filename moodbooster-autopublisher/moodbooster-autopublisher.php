<?php
declare(strict_types=1);
/**
 * Plugin Name: Moodbooster Autopublisher
 * Description: Ingests sources, translates to Slovak, picks image, dedups, and publishes.
 * Version: 1.0.1
 * Author: Moodbooster
 * Text Domain: moodbooster-autopub
 */

namespace Moodbooster\AutoPub;

use Moodbooster\AutoPub\Admin\LogsTable;
use Moodbooster\AutoPub\Admin\SettingsPage;
use Moodbooster\AutoPub\Run\Cli;
use Moodbooster\AutoPub\Run\Scheduler;

if (!defined('ABSPATH')) {
    exit;
}

// ── Константы (только константные выражения)
const VERSION     = '1.0.1';
const PLUGIN_FILE = __FILE__;
const PLUGIN_PATH = __DIR__;
const TEXT_DOMAIN = 'moodbooster-autopub';
const CRON_HOOK   = 'moodbooster_autopub_run';

// ── НЕ константа: требуется вызов функции → define()
if (!defined('MB_PLUGIN_URL')) {
    define('MB_PLUGIN_URL', \plugin_dir_url(__FILE__));
}

/**
 * PSR-4 автозагрузчик
 */
\spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    $len = \strlen($prefix);
    if (\strncmp($class, $prefix, $len) !== 0) {
        return; // не наш namespace
    }
    $relative = \substr($class, $len);
    $relative = \str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $path = __DIR__ . '/src/' . $relative . '.php';
    if (\is_file($path)) {
        require $path;
    }
});

/**
 * Загрузка переводов (правильный тайминг)
 */
\add_action('init', static function (): void {
    \load_plugin_textdomain(TEXT_DOMAIN, false, \dirname(\plugin_basename(__FILE__)) . '/languages');
});

/**
 * Активация плагина (с проверкой наличия класса/метода)
 */
\register_activation_hook(__FILE__, static function (): void {
    if (!\class_exists(Scheduler::class)) {
        $fallback = __DIR__ . '/src/Run/Scheduler.php';
        if (\is_file($fallback)) {
            require $fallback;
        }
    }
    if (!\class_exists(Scheduler::class) || !\is_callable([Scheduler::class, 'activate'])) {
        \deactivate_plugins(\plugin_basename(__FILE__));
        \wp_die('Moodbooster Autopublisher: Scheduler::activate() missing. Проверьте src/Run/Scheduler.php и namespace.');
    }
    Scheduler::activate();
});

/**
 * Деактивация
 */
\register_deactivation_hook(__FILE__, static function (): void {
    if (\class_exists(Scheduler::class) && \is_callable([Scheduler::class, 'deactivate'])) {
        Scheduler::deactivate();
    }
});

/**
 * Админ-меню
 */
\add_action('admin_menu', static function (): void {
    (new SettingsPage())->register();
    LogsTable::register_page();
});

/**
 * Регистрация настроек
 */
\add_action('admin_init', static function (): void {
    (new SettingsPage())->register_settings();
});

/**
 * Запуск через WP-Cron
 */
\add_action(CRON_HOOK, static function (): void {
    (new Scheduler())->run_batch();
});

/**
 * Notices и admin-post обработчики
 */
\add_action('admin_notices', [SettingsPage::class, 'admin_notices']);
\add_action('admin_post_mb_autopub_run_now', [SettingsPage::class, 'handle_run_now']);
\add_action('admin_post_mb_autopub_purge_logs', [SettingsPage::class, 'handle_purge_logs']);
\add_action('admin_post_mb_autopub_download_logs', [SettingsPage::class, 'handle_download_logs']);

/**
 * WP-CLI команда
 */
if (\defined('WP_CLI') && \WP_CLI) {
    \WP_CLI::add_command('mb', Cli::class);
}
