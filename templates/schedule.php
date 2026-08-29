<?php defined('ABSPATH') || exit;

use SitesSaver\Schedule;

$schedule = get_option('sitessaver_schedule', []);
$schedule = array_merge([
    'enabled'   => false,
    'retention' => 5,
    'include_db'      => true,
    'include_media'   => true,
    'include_plugins' => true,
    'include_themes'  => true,
    'storage_local'   => true,
    'storage_gdrive'  => false,
    'notify_email'    => get_option('admin_email'),
], is_array($schedule) ? $schedule : []);

$frequencies = Schedule::frequencies();
$selected    = Schedule::selected_frequencies($schedule);
$last_runs   = Schedule::last_runs();

// WP-Cron health check.
$next_run     = Schedule::next_scheduled_run($selected);
$log          = get_option('sitessaver_schedule_log', []);
$last_log     = is_array($log) && !empty($log) ? end($log) : null;
$disable_cron = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

// Staleness is judged against the SHORTEST selected interval — that is the
// promise the user is most likely to notice being broken. Seeded from the
// selection itself rather than a fixed day, so a Monthly-only schedule is not
// reported as stale two days after its last run.
$intervals = array_map([Schedule::class, 'interval_for'], $selected);
$shortest_interval = $intervals === [] ? DAY_IN_SECONDS : min($intervals);

$is_stale = false;
if (!empty($schedule['enabled']) && $last_log) {
    $last_ts = strtotime((string) ($last_log['time'] ?? ''));
    if ($last_ts && (time() - $last_ts) > (2 * $shortest_interval)) {
        $is_stale = true;
    }
}

$trigger_url = Schedule::trigger_url();

// The crontab expression only decides how often WordPress is *asked*; the
// plugin still enforces each frequency itself, so pinging more often than the
// shortest selection is safe and simply makes runs more punctual.
$cron_expression = $shortest_interval <= HOUR_IN_SECONDS ? '*/15 * * * *' : '0 * * * *';
$cron_line       = sprintf('%s wget -q -O - %s >/dev/null 2>&1', $cron_expression, $trigger_url);
$curl_line       = sprintf('%s curl -s %s >/dev/null 2>&1', $cron_expression, $trigger_url);
$wpcli_line      = sprintf('%s cd %s && wp cron event run --due-now >/dev/null 2>&1', $cron_expression, ABSPATH);
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
        <div class="ss-notice ss-notice-warning">
            <i class="ri-alert-line"></i>
            <div>
                <strong><?php esc_html_e('Scheduled backup missed.', 'sitessaver'); ?></strong>
                <?php esc_html_e('The last run is older than two scheduled intervals. WP-Cron only fires when someone visits the site — if traffic is low, set up the server cron below to keep the schedule reliable.', 'sitessaver'); ?>
            </div>
        </div>
    <?php elseif (!empty($schedule['enabled']) && $disable_cron) : ?>
        <div class="ss-notice ss-notice-info">
            <i class="ri-information-line"></i>
            <div>
                <strong><?php esc_html_e('WP-Cron is disabled on this site.', 'sitessaver'); ?></strong>
                <?php esc_html_e('DISABLE_WP_CRON is set in wp-config.php, so WordPress will not fire scheduled backups on its own. Use the server cron setup below — it does not depend on WP-Cron at all.', 'sitessaver'); ?>
            </div>
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
                            <div class="ss-frequency-group">
                                <?php foreach ($frequencies as $key => $meta) :
                                    $is_on    = in_array($key, $selected, true);
                                    $last     = $last_runs[$key] ?? 0;
                                    ?>
                                    <label class="ss-frequency-option<?php echo $is_on ? ' is-selected' : ''; ?>">
                                        <input type="checkbox" name="frequencies[]"
                                               value="<?php echo esc_attr($key); ?>"
                                               <?php checked($is_on); ?> />
                                        <span class="ss-frequency-body">
                                            <span class="ss-frequency-name"><?php echo esc_html($meta['label']); ?></span>
                                            <span class="ss-frequency-desc"><?php echo esc_html($meta['description']); ?></span>
                                            <?php if ($is_on && $last > 0) : ?>
                                                <span class="ss-frequency-meta">
                                                    <?php
                                                    printf(
                                                        /* translators: %s: human-readable time since the last run */
                                                        esc_html__('Last run %s ago', 'sitessaver'),
                                                        esc_html(human_time_diff($last, time()))
                                                    );
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Pick as many as you need — each runs on its own timer. A common setup is Daily for recent restore points plus Monthly as a long-term archive. Monthly means every 30 days.', 'sitessaver'); ?>
                            </p>
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
                            <p class="description"><?php esc_html_e('Number of scheduled backups to keep locally, counted across all frequencies.', 'sitessaver'); ?></p>
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

                <p class="submit" style="margin-top: 24px; padding: 0; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i>
                        <?php esc_html_e('Save Schedule Settings', 'sitessaver'); ?>
                    </button>
                    <button type="button" class="btn btn-outline" id="sitessaver-run-schedule-now" <?php disabled(empty($schedule['enabled'])); ?>>
                        <i class="ri-play-circle-line"></i>
                        <?php esc_html_e('Run backup now', 'sitessaver'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div class="ss-section">
        <div class="ss-section-header">
            <h2 class="ss-section-title">
                <i class="ri-server-line"></i>
                <?php esc_html_e('Server Cron (recommended)', 'sitessaver'); ?>
            </h2>
            <?php if ($disable_cron) : ?>
                <span class="badge badge-red" style="font-weight: normal;">
                    <?php esc_html_e('WP-Cron disabled — required', 'sitessaver'); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="ss-section-content">
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e('WordPress fires WP-Cron only when someone loads a page, so on a quiet site a scheduled backup can be hours or days late — and if DISABLE_WP_CRON is set, it never runs at all. The URL below triggers backups directly and does not depend on WP-Cron.', 'sitessaver'); ?>
            </p>

            <div class="ss-field-group">
                <label class="ss-field-label" for="sitessaver-trigger-url">
                    <?php esc_html_e('Your private trigger URL', 'sitessaver'); ?>
                </label>
                <div class="ss-copy-row">
                    <input type="text" id="sitessaver-trigger-url" class="ss-input-text ss-copy-input" readonly
                           value="<?php echo esc_attr($trigger_url); ?>" />
                    <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-trigger-url">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>
                <p class="description">
                    <strong><?php esc_html_e('Keep this URL private.', 'sitessaver'); ?></strong>
                    <?php esc_html_e('Anyone who has it can start a backup on your site. If it leaks, generate a new one below.', 'sitessaver'); ?>
                </p>
            </div>

            <div class="ss-field-group">
                <label class="ss-field-label"><?php esc_html_e('Add this line to your server crontab', 'sitessaver'); ?></label>
                <div class="ss-copy-row">
                    <input type="text" id="sitessaver-cron-line" class="ss-input-text ss-copy-input" readonly
                           value="<?php echo esc_attr($cron_line); ?>" />
                    <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-cron-line">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>
                <p class="description">
                    <?php esc_html_e('Run "crontab -e" on your server and paste the line. On cPanel or Plesk, use the Cron Jobs page instead and paste only the command part.', 'sitessaver'); ?>
                </p>
            </div>

            <details class="ss-details">
                <summary><?php esc_html_e('Other ways to trigger it', 'sitessaver'); ?></summary>

                <p class="ss-details-label"><?php esc_html_e('If wget is not installed, use curl:', 'sitessaver'); ?></p>
                <div class="ss-copy-row">
                    <input type="text" id="sitessaver-curl-line" class="ss-input-text ss-copy-input" readonly
                           value="<?php echo esc_attr($curl_line); ?>" />
                    <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-curl-line">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>

                <p class="ss-details-label"><?php esc_html_e('If you have WP-CLI and prefer to run every due WordPress event:', 'sitessaver'); ?></p>
                <div class="ss-copy-row">
                    <input type="text" id="sitessaver-wpcli-line" class="ss-input-text ss-copy-input" readonly
                           value="<?php echo esc_attr($wpcli_line); ?>" />
                    <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-wpcli-line">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>

                <p class="ss-details-label"><?php esc_html_e('No shell access?', 'sitessaver'); ?></p>
                <p class="description">
                    <?php esc_html_e('Point a free uptime monitor (UptimeRobot, cron-job.org, Better Uptime) at the trigger URL. Any service that can request a URL on a schedule works.', 'sitessaver'); ?>
                </p>

                <p class="ss-details-label"><?php esc_html_e('How the timing works', 'sitessaver'); ?></p>
                <p class="description">
                    <?php esc_html_e('The URL is safe to call more often than your chosen frequencies. SitesSaver tracks each frequency separately and replies "not due yet" until one of them is owed a backup, so an hourly cron with Daily + Monthly selected still produces exactly one backup a day and one a month. Append &force=1 to the URL to bypass that check and back up immediately.', 'sitessaver'); ?>
                </p>
            </details>

            <p style="margin-bottom: 0;">
                <button type="button" class="btn btn-outline" id="sitessaver-regenerate-cron-key">
                    <i class="ri-refresh-line"></i>
                    <?php esc_html_e('Generate a new URL', 'sitessaver'); ?>
                </button>
            </p>
        </div>
    </div>
</div>
