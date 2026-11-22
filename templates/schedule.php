<?php defined('ABSPATH') || exit;

use SitesSaver\Schedule;

$sitessaver_schedule = get_option('sitessaver_schedule', []);
$sitessaver_schedule = array_merge([
    'enabled'   => false,
    'retention' => 5,
    'backup_mode'      => 'full',
    'full_every'       => \SitesSaver\Index::DEFAULT_FULL_EVERY,
    'change_detection' => 'fast',
    'include_db'      => true,
    'include_media'   => true,
    'include_plugins' => true,
    'include_themes'  => true,
    'storage_local'   => true,
    'storage_gdrive'  => false,
    'notify_email'    => get_option('admin_email'),
], is_array($sitessaver_schedule) ? $sitessaver_schedule : []);

$sitessaver_frequencies = Schedule::frequencies();
$sitessaver_selected    = Schedule::selected_frequencies($sitessaver_schedule);
$sitessaver_last_runs   = Schedule::last_runs();

// WP-Cron health check.
$sitessaver_next_run     = Schedule::next_scheduled_run($sitessaver_selected);
$sitessaver_log          = get_option('sitessaver_schedule_log', []);
$sitessaver_last_log     = is_array($sitessaver_log) && !empty($sitessaver_log) ? end($sitessaver_log) : null;
$sitessaver_disable_cron = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

// Staleness is judged against the SHORTEST selected interval. That is the
// promise the user is most likely to notice being broken. Seeded from the
// selection itself rather than a fixed day, so a Monthly-only schedule is not
// reported as stale two days after its last run.
$sitessaver_intervals = array_map([Schedule::class, 'interval_for'], $sitessaver_selected);
$sitessaver_shortest_interval = $sitessaver_intervals === [] ? DAY_IN_SECONDS : min($sitessaver_intervals);

$sitessaver_is_stale = false;
if (!empty($sitessaver_schedule['enabled']) && $sitessaver_last_log) {
    $sitessaver_last_ts = strtotime((string) ($sitessaver_last_log['time'] ?? ''));
    if ($sitessaver_last_ts && (time() - $sitessaver_last_ts) > (2 * $sitessaver_shortest_interval)) {
        $sitessaver_is_stale = true;
    }
}

$sitessaver_trigger_url = Schedule::trigger_url();

// The crontab expression only decides how often WordPress is *asked*; the
// plugin still enforces each frequency itself, so pinging more often than the
// shortest selection is safe and simply makes runs more punctual.
$sitessaver_cron_expression = $sitessaver_shortest_interval <= HOUR_IN_SECONDS ? '*/15 * * * *' : '0 * * * *';

// The trigger URL contains a query string, and hosting panels paste these
// straight into a shell. Unquoted, the browser-safe `&` would background the
// command at that point and the key would never be sent. So quote it once,
// here, and reuse the quoted form everywhere.
//
// Double quotes, deliberately. The command is itself wrapped in single quotes
// for panels that run it through `bash -c '...'`, and a single-quoted URL
// nested inside that has to be escaped as '\'', which is correct POSIX but
// looks like line noise in a field the user is expected to eyeball before
// pasting. Double quotes nest cleanly and still protect the `&`.
// Escape the four characters the shell still expands inside double quotes.
$sitessaver_trigger_url_sh = '"' . addcslashes($sitessaver_trigger_url, '"$`\\') . '"';

// Command halves, without any schedule. Panels such as RunCloud, cPanel, and
// Plesk supply the schedule through their own fields, so pasting a full crontab
// line into their "command" box produces a doubled schedule that cannot run.
$sitessaver_wget_command  = sprintf('wget -q -O - %s >/dev/null 2>&1', $sitessaver_trigger_url_sh);
$sitessaver_curl_command  = sprintf('curl -s %s >/dev/null 2>&1', $sitessaver_trigger_url_sh);
$sitessaver_wpcli_command = sprintf('cd %s && wp cron event run --due-now >/dev/null 2>&1', '"' . addcslashes(rtrim(ABSPATH, '/\\'), '"$`\\') . '"');

// Full crontab lines, for `crontab -e` where the schedule IS part of the line.
$sitessaver_cron_line  = $sitessaver_cron_expression . ' ' . $sitessaver_wget_command;
$sitessaver_curl_line  = $sitessaver_cron_expression . ' ' . $sitessaver_curl_command;
$sitessaver_wpcli_line = $sitessaver_cron_expression . ' ' . $sitessaver_wpcli_command;

// Some panels ask for an interpreter separately (RunCloud's "Vendor Binary").
// Whatever follows is handed to that binary, so a bare `wget ...` is read as a
// script filename and fails; `-c '...'` is what makes it run as a command.
// $wget_command quotes its URL with double quotes, so it nests inside these
// single quotes without any escaping.
$sitessaver_panel_binary  = '/bin/bash';
$sitessaver_panel_command = sprintf("-c '%s'", $sitessaver_wget_command);

// Split the schedule into the per-field boxes those panels use.
$sitessaver_cron_fields = explode(' ', $sitessaver_cron_expression);
$sitessaver_field_labels = [
    __('Minute', 'sitessaver'),
    __('Hour', 'sitessaver'),
    __('Day of Month', 'sitessaver'),
    __('Month', 'sitessaver'),
    __('Day of Week', 'sitessaver'),
];
?>
<div class="sitessaver-wrap">
    <header class="sitessaver-header">
        <h1 class="sitessaver-title">
            <i class="ri-calendar-event-fill"></i>
            <?php esc_html_e('SitesSaver: Schedule', 'sitessaver'); ?>
        </h1>
        <div class="sitessaver-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver')); ?>" class="btn btn-outline">
                <i class="ri-arrow-left-line"></i>
                <?php esc_html_e('Back to Dashboard', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <?php if ($sitessaver_is_stale) : ?>
        <div class="sitessaver-notice sitessaver-notice-warning">
            <i class="ri-alert-line"></i>
            <div>
                <strong><?php esc_html_e('Scheduled backup missed.', 'sitessaver'); ?></strong>
                <?php esc_html_e('The last run is older than two scheduled intervals. WP-Cron only fires when someone visits the site. If traffic is low, set up the server cron below to keep the schedule reliable.', 'sitessaver'); ?>
            </div>
        </div>
    <?php elseif (!empty($sitessaver_schedule['enabled']) && $sitessaver_disable_cron) : ?>
        <div class="sitessaver-notice sitessaver-notice-info">
            <i class="ri-information-line"></i>
            <div>
                <strong><?php esc_html_e('WP-Cron is disabled on this site.', 'sitessaver'); ?></strong>
                <?php esc_html_e('DISABLE_WP_CRON is set in wp-config.php, so WordPress will not fire scheduled backups on its own. Use the server cron setup below. It does not depend on WP-Cron at all.', 'sitessaver'); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-time-line"></i>
                <?php esc_html_e('Automatic Backups', 'sitessaver'); ?>
            </h2>
            <?php if ($sitessaver_next_run && !empty($sitessaver_schedule['enabled'])) : ?>
                <span class="badge badge-blue" style="font-weight: normal;">
                    <?php
                    printf(
                        /* translators: %s: human-readable time until next scheduled run */
                        esc_html__('Next run in %s', 'sitessaver'),
                        esc_html(human_time_diff(time(), $sitessaver_next_run))
                    );
                    ?>
                </span>
            <?php endif; ?>
        </div>
        
        <div class="sitessaver-section-content">
            <form id="sitessaver-schedule-form">
                <table class="sitessaver-form-table">
                    <tr>
                        <th><?php esc_html_e('Status', 'sitessaver'); ?></th>
                        <td>
                            <div class="sitessaver-checkbox-group">
                                <label>
                                    <input type="checkbox" name="enabled" value="1" <?php checked($sitessaver_schedule['enabled']); ?> />
                                    <?php esc_html_e('Enable scheduled backups', 'sitessaver'); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Frequency', 'sitessaver'); ?></th>
                        <td>
                            <div class="sitessaver-frequency-group">
                                <?php foreach ($sitessaver_frequencies as $sitessaver_key => $sitessaver_meta) :
                                    $sitessaver_is_on    = in_array($sitessaver_key, $sitessaver_selected, true);
                                    $sitessaver_last     = $sitessaver_last_runs[$sitessaver_key] ?? 0;
                                    ?>
                                    <label class="sitessaver-frequency-option<?php echo $sitessaver_is_on ? ' is-selected' : ''; ?>">
                                        <input type="checkbox" name="frequencies[]"
                                               value="<?php echo esc_attr($sitessaver_key); ?>"
                                               <?php checked($sitessaver_is_on); ?> />
                                        <span class="sitessaver-frequency-body">
                                            <span class="sitessaver-frequency-name"><?php echo esc_html($sitessaver_meta['label']); ?></span>
                                            <span class="sitessaver-frequency-desc"><?php echo esc_html($sitessaver_meta['description']); ?></span>
                                            <?php if ($sitessaver_is_on && $sitessaver_last > 0) : ?>
                                                <span class="sitessaver-frequency-meta">
                                                    <?php
                                                    printf(
                                                        /* translators: %s: human-readable time since the last run */
                                                        esc_html__('Last run %s ago', 'sitessaver'),
                                                        esc_html(human_time_diff($sitessaver_last, time()))
                                                    );
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Pick as many as you need. Each runs on its own timer. A common setup is Daily for recent restore points plus Monthly as a long-term archive. Monthly means every 30 days.', 'sitessaver'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Inclusions', 'sitessaver'); ?></th>
                        <td>
                            <div class="sitessaver-checkbox-group">
                                <label><input type="checkbox" name="include_db" value="1" <?php checked($sitessaver_schedule['include_db']); ?> /> <?php esc_html_e('Database', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_media" value="1" <?php checked($sitessaver_schedule['include_media']); ?> /> <?php esc_html_e('Media Uploads', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_plugins" value="1" <?php checked($sitessaver_schedule['include_plugins']); ?> /> <?php esc_html_e('Plugins', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_themes" value="1" <?php checked($sitessaver_schedule['include_themes']); ?> /> <?php esc_html_e('Themes', 'sitessaver'); ?></label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Backup Mode', 'sitessaver'); ?></th>
                        <td>
                            <div class="sitessaver-radio-group">
                                <label>
                                    <input type="radio" name="backup_mode" value="full" <?php checked($sitessaver_schedule['backup_mode'], 'full'); ?> />
                                    <?php esc_html_e('Full every time', 'sitessaver'); ?>
                                </label>
                                <label>
                                    <input type="radio" name="backup_mode" value="incremental" <?php checked($sitessaver_schedule['backup_mode'], 'incremental'); ?> />
                                    <?php esc_html_e('Incremental', 'sitessaver'); ?>
                                </label>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Incremental backups archive only the files that changed since the previous run, so a daily backup of a large media library takes minutes instead of hours and a fraction of the disk. The database is always included in full, so there is no replaying of database changes on restore.', 'sitessaver'); ?>
                            </p>
                            <p class="description">
                                <strong><?php esc_html_e('Worth knowing:', 'sitessaver'); ?></strong>
                                <?php esc_html_e('an incremental backup is restored together with the full backup it follows. SitesSaver does that for you and keeps the whole chain together when deleting old backups, but it means you cannot copy a single incremental ZIP to another site on its own. Backups made by hand from the Export screen are always full and stand alone.', 'sitessaver'); ?>
                            </p>

                            <div class="sitessaver-subfield" data-sitessaver-when="backup_mode=incremental">
                                <p>
                                    <label>
                                        <?php esc_html_e('Start a new full backup every', 'sitessaver'); ?>
                                        <input type="number" name="full_every" value="<?php echo (int) $sitessaver_schedule['full_every']; ?>" min="2" max="60" class="sitessaver-input-text" style="width: 70px;" />
                                        <?php esc_html_e('runs', 'sitessaver'); ?>
                                    </label>
                                </p>
                                <p class="description">
                                    <?php esc_html_e('Caps how long a chain grows. A shorter chain restores faster and limits how much a single damaged file can cost you; a longer one saves more disk. SitesSaver also starts a fresh full backup early if the changes have added up to more than half the size of the full backup, or if the previous backup has gone missing.', 'sitessaver'); ?>
                                </p>

                                <p>
                                    <label>
                                        <?php esc_html_e('Change detection', 'sitessaver'); ?>
                                        <select name="change_detection" class="sitessaver-input-text">
                                            <option value="fast" <?php selected($sitessaver_schedule['change_detection'], 'fast'); ?>><?php esc_html_e('Fast (size and modified date)', 'sitessaver'); ?></option>
                                            <option value="thorough" <?php selected($sitessaver_schedule['change_detection'], 'thorough'); ?>><?php esc_html_e('Thorough (verify contents with a checksum)', 'sitessaver'); ?></option>
                                        </select>
                                    </label>
                                </p>
                                <p class="description">
                                    <?php esc_html_e('Fast is right for almost every site. Choose Thorough if your host or deployment process rewrites file timestamps, which would otherwise make unchanged files look changed and inflate every backup.', 'sitessaver'); ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Storage Destination', 'sitessaver'); ?></th>
                        <td>
                            <div class="sitessaver-checkbox-group">
                                <label><input type="checkbox" name="storage_local" value="1" <?php checked($sitessaver_schedule['storage_local'] ?? true); ?> /> <?php esc_html_e('Local Server', 'sitessaver'); ?></label>
                                <label>
                                    <input type="checkbox" name="storage_gdrive" value="1" <?php checked($sitessaver_schedule['storage_gdrive'] ?? false); ?> <?php disabled(!\SitesSaver\GDrive::is_connected()); ?> /> 
                                    <?php esc_html_e('Google Drive', 'sitessaver'); ?>
                                    <?php if (!\SitesSaver\GDrive::is_connected()) : ?>
                                        <span class="description" style="color: var(--sitessaver-danger); font-size: 11px;">(<?php esc_html_e('Connect in Settings first', 'sitessaver'); ?>)</span>
                                    <?php endif; ?>
                                </label>
                                <?php foreach (\SitesSaver\Destinations::extra() as $sitessaver_dest) : $sitessaver_on = $sitessaver_dest->is_connected(); ?>
                                <label>
                                    <input type="checkbox" name="storage_remotes[]" value="<?php echo esc_attr($sitessaver_dest->id()); ?>" <?php checked(in_array($sitessaver_dest->id(), (array) ($sitessaver_schedule['storage_remotes'] ?? []), true)); ?> <?php disabled(!$sitessaver_on); ?> />
                                    <?php echo esc_html($sitessaver_dest->label()); ?>
                                    <?php if (!$sitessaver_on) : ?>
                                        <span class="description" style="color: var(--sitessaver-danger); font-size: 11px;">(<?php esc_html_e('Connect in Settings first', 'sitessaver'); ?>)</span>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Retention', 'sitessaver'); ?></th>
                        <td>
                            <input type="number" name="retention" value="<?php echo (int) $sitessaver_schedule['retention']; ?>" min="1" max="100" class="sitessaver-input-text" style="width: 80px;" />
                            <p class="description"><?php esc_html_e('How many restore points to keep on this server. Applied after each scheduled run, oldest deleted first. With incremental backups a restore point is a full backup plus everything that followed it, and those are removed together, so keeping 5 restore points can mean more than 5 files in the folder. Manual backups count as one restore point each.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Email Notification', 'sitessaver'); ?></th>
                        <td>
                            <input type="email" name="notify_email" value="<?php echo esc_attr($sitessaver_schedule['notify_email']); ?>" class="sitessaver-input-text" placeholder="admin@example.com" />
                            <p class="description">
                                <?php esc_html_e('Get a report after every scheduled backup, including where the archive was stored and whether the Google Drive upload succeeded.', 'sitessaver'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-settings')); ?>"><?php esc_html_e('Customise its branding', 'sitessaver'); ?></a>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top: 24px; padding: 0; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i>
                        <?php esc_html_e('Save Schedule Settings', 'sitessaver'); ?>
                    </button>
                    <button type="button" class="btn btn-outline" id="sitessaver-run-schedule-now" <?php disabled(empty($sitessaver_schedule['enabled'])); ?>>
                        <i class="ri-play-circle-line"></i>
                        <?php esc_html_e('Run backup now', 'sitessaver'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-server-line"></i>
                <?php esc_html_e('Server Cron (recommended)', 'sitessaver'); ?>
            </h2>
            <?php if ($sitessaver_disable_cron) : ?>
                <span class="badge badge-red" style="font-weight: normal;">
                    <?php esc_html_e('WP-Cron disabled: required', 'sitessaver'); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="sitessaver-section-content">
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e('WordPress fires WP-Cron only when someone loads a page, so on a quiet site a scheduled backup can be hours or days late, and if DISABLE_WP_CRON is set, it never runs at all. The URL below triggers backups directly and does not depend on WP-Cron.', 'sitessaver'); ?>
                <?php esc_html_e('It also keeps a backup you started from the Export screen moving after you close the page, on hosts that stop background work. Run it every minute if your host allows.', 'sitessaver'); ?>
            </p>

            <div class="sitessaver-field-group">
                <label class="sitessaver-field-label" for="sitessaver-trigger-url">
                    <?php esc_html_e('Your private trigger URL', 'sitessaver'); ?>
                </label>
                <div class="sitessaver-copy-row">
                    <input type="text" id="sitessaver-trigger-url" class="sitessaver-input-text sitessaver-copy-input" readonly
                           value="<?php echo esc_attr($sitessaver_trigger_url); ?>" />
                    <button type="button" class="btn btn-outline sitessaver-copy-btn" data-copy-target="#sitessaver-trigger-url">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>
                <p class="description">
                    <strong><?php esc_html_e('Keep this URL private.', 'sitessaver'); ?></strong>
                    <?php esc_html_e('Anyone who has it can start a backup on your site. If it leaks, generate a new one below.', 'sitessaver'); ?>
                </p>
            </div>

            <div class="sitessaver-setup-tabs">
                <button type="button" class="sitessaver-setup-tab is-active" data-setup-target="sitessaver-setup-panel">
                    <i class="ri-layout-grid-line"></i>
                    <?php esc_html_e('Hosting panel', 'sitessaver'); ?>
                </button>
                <button type="button" class="sitessaver-setup-tab" data-setup-target="sitessaver-setup-crontab">
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
            <div class="sitessaver-setup-body is-active" id="sitessaver-setup-panel">
                <p class="description" style="margin-top: 0;">
                    <?php esc_html_e('Copy each value into the matching field. Do not paste the schedule into the command box. The panel adds it for you.', 'sitessaver'); ?>
                </p>

                <div class="sitessaver-field-group">
                    <label class="sitessaver-field-label" for="sitessaver-panel-binary">
                        <?php esc_html_e('Command / Vendor Binary (if the panel asks for one)', 'sitessaver'); ?>
                    </label>
                    <div class="sitessaver-copy-row">
                        <input type="text" id="sitessaver-panel-binary" class="sitessaver-input-text sitessaver-copy-input" readonly
                               value="<?php echo esc_attr($sitessaver_panel_binary); ?>" />
                        <button type="button" class="btn btn-outline sitessaver-copy-btn" data-copy-target="#sitessaver-panel-binary">
                            <i class="ri-file-copy-line"></i>
                            <?php esc_html_e('Copy', 'sitessaver'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Leave this out if your panel has no such field, and use the crontab command below instead.', 'sitessaver'); ?>
                    </p>
                </div>

                <div class="sitessaver-field-group">
                    <label class="sitessaver-field-label" for="sitessaver-panel-command">
                        <?php esc_html_e('Command', 'sitessaver'); ?>
                    </label>
                    <div class="sitessaver-copy-row">
                        <input type="text" id="sitessaver-panel-command" class="sitessaver-input-text sitessaver-copy-input" readonly
                               value="<?php echo esc_attr($sitessaver_panel_command); ?>" />
                        <button type="button" class="btn btn-outline sitessaver-copy-btn" data-copy-target="#sitessaver-panel-command">
                            <i class="ri-file-copy-line"></i>
                            <?php esc_html_e('Copy', 'sitessaver'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('The -c \'...\' wrapper is required whenever the panel runs your command through /bin/bash. Without it bash treats "wget" as a script filename and the job fails.', 'sitessaver'); ?>
                        <br />
                        <?php esc_html_e('If the panel has no binary field, paste this instead:', 'sitessaver'); ?>
                        <code><?php echo esc_html($sitessaver_wget_command); ?></code>
                    </p>
                </div>

                <div class="sitessaver-field-group">
                    <label class="sitessaver-field-label"><?php esc_html_e('Schedule fields', 'sitessaver'); ?></label>
                    <div class="sitessaver-cron-fields">
                        <?php foreach ($sitessaver_field_labels as $sitessaver_i => $sitessaver_flabel) :
                            $sitessaver_fid = 'sitessaver-cron-field-' . $sitessaver_i;
                            ?>
                            <div class="sitessaver-cron-field">
                                <label for="<?php echo esc_attr($sitessaver_fid); ?>"><?php echo esc_html($sitessaver_flabel); ?></label>
                                <input type="text" id="<?php echo esc_attr($sitessaver_fid); ?>"
                                       class="sitessaver-input-text sitessaver-copy-input sitessaver-cron-field-input" readonly
                                       value="<?php echo esc_attr($sitessaver_cron_fields[$sitessaver_i] ?? '*'); ?>" />
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: cron schedule expression, e.g. 0 * * * * */
                            esc_html__('Together these read %s. If your panel offers a preset such as "Once an hour", pick that instead.', 'sitessaver'),
                            '<code>' . esc_html($sitessaver_cron_expression) . '</code>'
                        );
                        ?>
                    </p>
                </div>
            </div>

            <div class="sitessaver-setup-body" id="sitessaver-setup-crontab">
                <div class="sitessaver-field-group">
                    <label class="sitessaver-field-label" for="sitessaver-cron-line">
                        <?php esc_html_e('Run "crontab -e" and paste this whole line', 'sitessaver'); ?>
                    </label>
                    <div class="sitessaver-copy-row">
                        <input type="text" id="sitessaver-cron-line" class="sitessaver-input-text sitessaver-copy-input" readonly
                               value="<?php echo esc_attr($sitessaver_cron_line); ?>" />
                        <button type="button" class="btn btn-outline sitessaver-copy-btn" data-copy-target="#sitessaver-cron-line">
                            <i class="ri-file-copy-line"></i>
                            <?php esc_html_e('Copy', 'sitessaver'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('This form includes the schedule, so use it only where you edit the crontab directly. In a hosting panel, use the Hosting panel tab above.', 'sitessaver'); ?>
                    </p>
                </div>
            </div>

            <details class="sitessaver-details">
                <summary><?php esc_html_e('Other ways to trigger it', 'sitessaver'); ?></summary>

                <p class="sitessaver-details-label"><?php esc_html_e('If wget is not installed, use curl:', 'sitessaver'); ?></p>
                <div class="sitessaver-copy-row">
                    <input type="text" id="sitessaver-curl-line" class="sitessaver-input-text sitessaver-copy-input" readonly
                           value="<?php echo esc_attr($sitessaver_curl_line); ?>" />
                    <button type="button" class="btn btn-outline sitessaver-copy-btn" data-copy-target="#sitessaver-curl-line">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>

                <p class="sitessaver-details-label"><?php esc_html_e('If you have WP-CLI and prefer to run every due WordPress event:', 'sitessaver'); ?></p>
                <div class="sitessaver-copy-row">
                    <input type="text" id="sitessaver-wpcli-line" class="sitessaver-input-text sitessaver-copy-input" readonly
                           value="<?php echo esc_attr($sitessaver_wpcli_line); ?>" />
                    <button type="button" class="btn btn-outline sitessaver-copy-btn" data-copy-target="#sitessaver-wpcli-line">
                        <i class="ri-file-copy-line"></i>
                        <?php esc_html_e('Copy', 'sitessaver'); ?>
                    </button>
                </div>

                <p class="sitessaver-details-label"><?php esc_html_e('No shell access?', 'sitessaver'); ?></p>
                <p class="description">
                    <?php esc_html_e('Point a free uptime monitor (UptimeRobot, cron-job.org, Better Uptime) at the trigger URL. Any service that can request a URL on a schedule works.', 'sitessaver'); ?>
                </p>

                <p class="sitessaver-details-label"><?php esc_html_e('How the timing works', 'sitessaver'); ?></p>
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
