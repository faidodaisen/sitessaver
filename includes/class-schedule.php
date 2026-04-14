<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Scheduled backups via WP Cron.
 */
final class Schedule {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function init(): void {
        add_action('sitessaver_scheduled_backup', [$this, 'run_scheduled_backup']);
        add_filter('cron_schedules', [$this, 'add_weekly_schedule']);
    }

    /**
     * Add weekly schedule if not already registered.
     */
    public function add_weekly_schedule(array $schedules): array {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly', 'sitessaver'),
            ];
        }
        return $schedules;
    }

    /**
     * Execute a scheduled backup.
     */
    public function run_scheduled_backup(): void {
        $settings = get_option('sitessaver_schedule', []);

        if (empty($settings['enabled'])) {
            return;
        }

        // 1. Run Export.
        $result = Export::run([
            'include_db'      => $settings['include_db'] ?? true,
            'include_media'   => $settings['include_media'] ?? true,
            'include_plugins' => $settings['include_plugins'] ?? true,
            'include_themes'  => $settings['include_themes'] ?? true,
        ]);

        if (!$result['success']) {
            $this->finalize_backup($result, $settings);
            return;
        }

        $file_uploaded = false;

        // 2. Handle Google Drive Storage.
        if (!empty($settings['storage_gdrive'])) {
            $up_res = GDrive::upload($result['path'], $result['file']);
            if ($up_res['success']) {
                $file_uploaded = true;
            } else {
                $result['message'] .= ' (GDrive Upload Failed: ' . $up_res['message'] . ')';
            }
        }

        // 3. Handle Local Storage.
        if (empty($settings['storage_local']) && $file_uploaded) {
            // User only wants GDrive and it succeeded — delete local file.
            @unlink($result['path']);
            $result['message'] .= ' ' . __('(Local copy removed as per settings)', 'sitessaver');
        } else {
            // Apply retention policy for local backups.
            $retention = (int) ($settings['retention'] ?? 5);
            if ($retention > 0) {
                self::apply_retention($retention);
            }
        }

        $this->finalize_backup($result, $settings);
    }

    /**
     * Finalize backup: notify and log.
     */
    private function finalize_backup(array $result, array $settings): void {
        // Send notification email.
        $email = $settings['notify_email'] ?? '';
        if (!empty($email) && is_email($email)) {
            self::send_notification($email, $result);
        }

        // Log result.
        $log = get_option('sitessaver_schedule_log', []);
        $log[] = [
            'time'    => current_time('mysql'),
            'success' => $result['success'],
            'file'    => $result['file'] ?? '',
            'message' => $result['message'] ?? '',
        ];

        // Keep last 50 log entries.
        $log = array_slice($log, -50);
        update_option('sitessaver_schedule_log', $log);
    }

    /**
     * Delete old backups beyond retention count.
     */
    private static function apply_retention(int $keep): void {
        $backups = sitessaver_get_backups();

        if (count($backups) <= $keep) {
            return;
        }

        $to_delete = array_slice($backups, $keep);

        foreach ($to_delete as $backup) {
            if (file_exists($backup['path'])) {
                @unlink($backup['path']);
            }

            // Remove label.
            $labels = get_option('sitessaver_backup_labels', []);
            unset($labels[$backup['file']]);
            update_option('sitessaver_backup_labels', $labels);
        }
    }

    /**
     * Send email notification about backup result.
     */
    private static function send_notification(string $email, array $result): void {
        $site_name = get_bloginfo('name');

        if ($result['success']) {
            $subject = sprintf('[%s] Scheduled backup completed', $site_name);
            $body    = sprintf(
                "Backup completed successfully.\n\nFile: %s\nSize: %s\nTime: %s",
                $result['file'] ?? 'N/A',
                $result['size'] ?? 'N/A',
                current_time('mysql')
            );
        } else {
            $subject = sprintf('[%s] Scheduled backup FAILED', $site_name);
            $body    = sprintf(
                "Backup failed.\n\nError: %s\nTime: %s",
                $result['message'] ?? 'Unknown error',
                current_time('mysql')
            );
        }

        wp_mail($email, $subject, $body);
    }
}
