<?php
if (!defined('ABSPATH')) exit;

class SUITE_BARRIO_Cron {
    const HOOK         = 'suite_barrio_cb_monthly_scan';
    const SCHEDULE_KEY = 'suite_barrio_cb_monthly';

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'add_schedule']);
        add_action(self::HOOK, [self::class, 'run']);
        // Assicura che il cron sia schedulato quando il modulo è attivo
        add_action('init', [self::class, 'ensure_scheduled'], 20);
    }

    public static function add_schedule(array $schedules): array {
        $schedules[self::SCHEDULE_KEY] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => 'Ogni 30 giorni',
        ];
        return $schedules;
    }

    public static function ensure_scheduled(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, self::SCHEDULE_KEY, self::HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run(): void {
        SUITE_BARRIO_Scanner::run();
    }
}

SUITE_BARRIO_Cron::init();
