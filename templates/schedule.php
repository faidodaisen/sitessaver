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

// The trigger URL contains a query string, and hosting panels paste these
// straight into a shell. Unquoted, the browser-safe `&` would background the
// command at that point and the key would never be sent — so quote it once,
// here, and reuse the quoted form everywhere.
//
// Double quotes, deliberately. The command is itself wrapped in single quotes
// for panels that run it through `bash -c '...'`, and a single-quoted URL
// nested inside that has to be escaped as '\'' — which is correct POSIX but
// looks like line noise in a field the user is expected to eyeball before
// pasting. Double quotes nest cleanly and still protect the `&`.
// Escape the four characters the shell still expands inside double quotes.
$trigger_url_sh = '"' . addcslashes($trigger_url, '"$`\\') . '"';

// Command halves, without any schedule. Panels such as RunCloud, cPanel, and
// Plesk supply the schedule through their own fields, so pasting a full crontab
// line into their "command" box produces a doubled schedule that cannot run.
$wget_command  = sprintf('wget -q -O - %s >/dev/null 2>&1', $trigger_url_sh);
$curl_command  = sprintf('curl -s %s >/dev/null 2>&1', $trigger_url_sh);
$wpcli_command = sprintf('cd %s && wp cron event run --due-now >/dev/null 2>&1', escapeshellarg(rtrim(ABSPATH, '/\\')));

// Full crontab lines, for `crontab -e` where the schedule IS part of the line.
$cron_line  = $cron_expression . ' ' . $wget_command;
$curl_line  = $cron_expression . ' ' . $curl_command;
$wpcli_line = $cron_expression . ' ' . $wpcli_command;

// Some panels ask for an interpreter separately (RunCloud's "Vendor Binary").
// Whatever follows is handed to that binary, so a bare `wget ...` is read as a
// script filename and fails; `-c '...'` is what makes it run as a command.
// $wget_command quotes its URL with double quotes, so it nests inside these
// single quotes without any escaping.
$panel_binary  = '/bin/bash';
$panel_command = sprintf("-c '%s'", $wget_command);

// Split the schedule into the per-field boxes those panels use.
$cron_fields = explode(' ', $cron_expression);
$field_labels = [
    __('Minute', 'sitessaver'),
    __('Hour', 'sitessaver'),
    __('Day of Month', 'sitessaver'),
    __('Month', 'sitessaver'),
    __('Day of Week', 'sitessaver'),
];
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

            <div class="ss-setup-tabs">
                <button type="button" class="ss-setup-tab is-active" data-setup-target="ss-setup-panel">
                    <i class="ri-layout-grid-line"></i>
                    <?php esc_html_e('Hosting panel', 'sitessaver'); ?>
                </button>
                <button type="button" class="ss-setup-tab" data-setup-target="ss-setup-crontab">
                    <i class="ri-terminal-box-line"></i>
                    <?php esc_html_e('crontab -e', 'sitessaver'); ?>
                </button>
            </div>

            <?php
            /*
             * Panels (RunCloud, cPanel, Plesk, CyberPanel) collect the schedule
             * in their own fields and the command in another. Pasting a whole
             * crontab line into the command box yields a doubled schedule like
             * `* * * * * /bin/bash 0 * * * * wget ...`, which silently never
             * runs. Splitting the parts out is what stops that.
             */
            ?>
            <div class="ss-setup-body is-active" id="ss-setup-panel">
                <p class="description" style="margin-top: 0;">
                    <?php esc_html_e('Copy each value into the matching field. Do not paste the schedule into the command box — the panel adds it for you.', 'sitessaver'); ?>
                </p>

                <div class="ss-field-group">
                    <label class="ss-field-label" for="sitessaver-panel-binary">
                        <?php esc_html_e('Command / Vendor Binary (if the panel asks for one)', 'sitessaver'); ?>
                    </label>
                    <div class="ss-copy-row">
                        <input type="text" id="sitessaver-panel-binary" class="ss-input-text ss-copy-input" readonly
                               value="<?php echo esc_attr($panel_binary); ?>" />
                        <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-panel-binary">
                            <i class="ri-file-copy-line"></i>
                            <?php esc_html_e('Copy', 'sitessaver'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Leave this out if your panel has no such field, and use the crontab command below instead.', 'sitessaver'); ?>
                    </p>
                </div>

                <div class="ss-field-group">
                    <label class="ss-field-label" for="sitessaver-panel-command">
                        <?php esc_html_e('Command', 'sitessaver'); ?>
                    </label>
                    <div class="ss-copy-row">
                        <input type="text" id="sitessaver-panel-command" class="ss-input-text ss-copy-input" readonly
                               value="<?php echo esc_attr($panel_command); ?>" />
                        <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-panel-command">
                            <i class="ri-file-copy-line"></i>
                            <?php esc_html_e('Copy', 'sitessaver'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('The -c \'...\' wrapper is required whenever the panel runs your command through /bin/bash. Without it bash treats "wget" as a script filename and the job fails.', 'sitessaver'); ?>
                        <br />
                        <?php esc_html_e('If the panel has no binary field, paste this instead:', 'sitessaver'); ?>
                        <code><?php echo esc_html($wget_command); ?></code>
                    </p>
                </div>

                <div class="ss-field-group">
                    <label class="ss-field-label"><?php esc_html_e('Schedule fields', 'sitessaver'); ?></label>
                    <div class="ss-cron-fields">
                        <?php foreach ($field_labels as $i => $flabel) :
                            $fid = 'sitessaver-cron-field-' . $i;
                            ?>
                            <div class="ss-cron-field">
                                <label for="<?php echo esc_attr($fid); ?>"><?php echo esc_html($flabel); ?></label>
                                <input type="text" id="<?php echo esc_attr($fid); ?>"
                                       class="ss-input-text ss-copy-input ss-cron-field-input" readonly
                                       value="<?php echo esc_attr($cron_fields[$i] ?? '*'); ?>" />
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: cron schedule expression, e.g. 0 * * * * */
                            esc_html__('Together these read %s. If your panel offers a preset such as "Once an hour", pick that instead.', 'sitessaver'),
                            '<code>' . esc_html($cron_expression) . '</code>'
                        );
                        ?>
                    </p>
                </div>
            </div>

            <div class="ss-setup-body" id="ss-setup-crontab">
                <div class="ss-field-group">
                    <label class="ss-field-label" for="sitessaver-cron-line">
                        <?php esc_html_e('Run "crontab -e" and paste this whole line', 'sitessaver'); ?>
                    </label>
                    <div class="ss-copy-row">
                        <input type="text" id="sitessaver-cron-line" class="ss-input-text ss-copy-input" readonly
                               value="<?php echo esc_attr($cron_line); ?>" />
                        <button type="button" class="btn btn-outline ss-copy-btn" data-copy-target="#sitessaver-cron-line">
                            <i class="ri-file-copy-line"></i>
                            <?php esc_html_e('Copy', 'sitessaver'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('This form includes the schedule, so use it only where you edit the crontab directly. In a hosting panel, use the Hosting panel tab above.', 'sitessaver'); ?>
                    </p>
                </div>
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
