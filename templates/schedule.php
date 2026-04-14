<?php defined('ABSPATH') || exit;
$schedule = get_option('sitessaver_schedule', [
    'enabled'   => false,
    'frequency' => 'daily',
    'retention' => 5,
    'include_db'      => true,
    'include_media'   => true,
    'include_plugins' => true,
    'include_themes'  => true,
    'storage_local'   => true,
    'storage_gdrive'  => false,
    'notify_email'    => get_option('admin_email'),
]);

// WP-Cron health check.
$next_run     = wp_next_scheduled('sitessaver_scheduled_backup');
$log          = get_option('sitessaver_schedule_log', []);
$last_log     = is_array($log) && !empty($log) ? end($log) : null;
$disable_cron = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

$interval_secs = [
    'hourly'     => HOUR_IN_SECONDS,
    'twicedaily' => 12 * HOUR_IN_SECONDS,
    'daily'      => DAY_IN_SECONDS,
    'weekly'     => WEEK_IN_SECONDS,
][$schedule['frequency']] ?? DAY_IN_SECONDS;

$is_stale = false;
if (!empty($schedule['enabled']) && $last_log) {
    $last_ts = strtotime((string) ($last_log['time'] ?? ''));
    if ($last_ts && (time() - $last_ts) > (2 * $interval_secs)) {
        $is_stale = true;
    }
}
?>
<div class="sitessaver-wrap">
    <header class="ss-header">
        <h1 class="ss-title">
            <i class="ri-calendar-event-fill"></i>
            <?php esc_html_e('SitesSaver — Schedule', 'sitessaver'); ?>
        </h1>
        <div class="ss-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver')); ?>" class="btn btn-outline">
                <i class="ri-arrow-left-line"></i>
                <?php esc_html_e('Back to Dashboard', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <?php if ($is_stale) : ?>
        <div style="margin: 20px 24px; padding: 12px 16px; border-radius: 6px; background: #fef7e0; color: #9a6400; border: 1px solid #fde293;">
            <strong><?php esc_html_e('Scheduled backup missed.', 'sitessaver'); ?></strong>
            <?php esc_html_e('The last run is older than two scheduled intervals. WP-Cron only fires when someone visits the site — if traffic is low, add a real server cron hitting wp-cron.php to keep the schedule reliable.', 'sitessaver'); ?>
        </div>
    <?php elseif (!empty($schedule['enabled']) && $disable_cron) : ?>
        <div style="margin: 20px 24px; padding: 12px 16px; border-radius: 6px; background: #e8f0fe; color: #1967d2; border: 1px solid #c9d7ef;">
            <?php esc_html_e('WP-Cron is disabled (DISABLE_WP_CRON). Make sure a real system cron is hitting wp-cron.php, otherwise scheduled backups will not run.', 'sitessaver'); ?>
        </div>
    <?php endif; ?>

    <div class="ss-section">
        <div class="ss-section-header">
            <h2 class="ss-section-title">
                <i class="ri-time-line"></i>
                <?php esc_html_e('Automatic Backups', 'sitessaver'); ?>
            </h2>
            <?php if ($next_run && !empty($schedule['enabled'])) : ?>
                <span class="badge badge-blue" style="font-weight: normal;">
                    <?php
                    printf(
                        /* translators: %s: human-readable time until next scheduled run */
                        esc_html__('Next run in %s', 'sitessaver'),
                        esc_html(human_time_diff(time(), $next_run))
                    );
                    ?>
                </span>
            <?php endif; ?>
        </div>
        
        <div class="ss-section-content">
            <form id="sitessaver-schedule-form">
                <table class="ss-form-table">
                    <tr>
                        <th><?php esc_html_e('Status', 'sitessaver'); ?></th>
                        <td>
                            <div class="ss-checkbox-group">
                                <label>
                                    <input type="checkbox" name="enabled" value="1" <?php checked($schedule['enabled']); ?> />
                                    <?php esc_html_e('Enable scheduled backups', 'sitessaver'); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Frequency', 'sitessaver'); ?></th>
                        <td>
                            <select name="frequency" class="ss-input-text">
                                <option value="hourly" <?php selected($schedule['frequency'], 'hourly'); ?>><?php esc_html_e('Every Hour', 'sitessaver'); ?></option>
                                <option value="twicedaily" <?php selected($schedule['frequency'], 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'sitessaver'); ?></option>
                                <option value="daily" <?php selected($schedule['frequency'], 'daily'); ?>><?php esc_html_e('Daily', 'sitessaver'); ?></option>
                                <option value="weekly" <?php selected($schedule['frequency'], 'weekly'); ?>><?php esc_html_e('Weekly', 'sitessaver'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Inclusions', 'sitessaver'); ?></th>
                        <td>
                            <div class="ss-checkbox-group">
                                <label><input type="checkbox" name="include_db" value="1" <?php checked($schedule['include_db']); ?> /> <?php esc_html_e('Database', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_media" value="1" <?php checked($schedule['include_media']); ?> /> <?php esc_html_e('Media Uploads', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_plugins" value="1" <?php checked($schedule['include_plugins']); ?> /> <?php esc_html_e('Plugins', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_themes" value="1" <?php checked($schedule['include_themes']); ?> /> <?php esc_html_e('Themes', 'sitessaver'); ?></label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Storage Destination', 'sitessaver'); ?></th>
                        <td>
                            <div class="ss-checkbox-group">
                                <label><input type="checkbox" name="storage_local" value="1" <?php checked($schedule['storage_local'] ?? true); ?> /> <?php esc_html_e('Local Server', 'sitessaver'); ?></label>
                                <label>
                                    <input type="checkbox" name="storage_gdrive" value="1" <?php checked($schedule['storage_gdrive'] ?? false); ?> <?php disabled(!\SitesSaver\GDrive::is_connected()); ?> /> 
                                    <?php esc_html_e('Google Drive', 'sitessaver'); ?>
                                    <?php if (!\SitesSaver\GDrive::is_connected()) : ?>
                                        <span class="description" style="color: var(--ss-danger); font-size: 11px;">(<?php esc_html_e('Connect in Settings first', 'sitessaver'); ?>)</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Retention', 'sitessaver'); ?></th>
                        <td>
                            <input type="number" name="retention" value="<?php echo (int) $schedule['retention']; ?>" min="1" max="100" class="ss-input-text" style="width: 80px;" />
                            <p class="description"><?php esc_html_e('Number of scheduled backups to keep locally.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Email Notification', 'sitessaver'); ?></th>
                        <td>
                            <input type="email" name="notify_email" value="<?php echo esc_attr($schedule['notify_email']); ?>" class="ss-input-text" placeholder="admin@example.com" />
                            <p class="description"><?php esc_html_e('Receive an email after each scheduled backup completion.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top: 24px; padding: 0;">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i>
                        <?php esc_html_e('Save Schedule Settings', 'sitessaver'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>
