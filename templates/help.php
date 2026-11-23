<?php defined('ABSPATH') || exit;
?>
<div class="sitessaver-wrap">
    <header class="sitessaver-header">
        <h1 class="sitessaver-title">
            <i class="ri-question-line"></i>
            <?php esc_html_e('SitesSaver: Help & Manual', 'sitessaver'); ?>
        </h1>
        <div class="sitessaver-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver')); ?>" class="btn btn-outline">
                <i class="ri-arrow-left-line"></i>
                <?php esc_html_e('Back to Dashboard', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-book-open-line"></i>
                <?php esc_html_e('User Manual', 'sitessaver'); ?>
            </h2>
        </div>
        
        <div class="sitessaver-section-content" style="padding: 30px;">
            <div style="line-height: 1.6;">
                <h3 style="margin-top: 0;"><?php esc_html_e('1. How to create a backup?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the main dashboard or the Export page. Select what you want to include (Database, Media, etc.) and click "Create Backup". The plugin will package everything into a single ZIP file.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('2. How to restore my site?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the Import page. You can drag and drop your backup ZIP file into the box, or click to select the file. Once uploaded, click "Restore" and wait for the process to finish.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('You can also restore straight from a backup already in your Google Drive. Open the Backups page and scroll to the "Google Drive Backups" section, then choose Restore on the file you want. It is downloaded and restored in one step, and the copy stays on your server afterwards.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Restoring a backup from a different domain rewrites URLs in the database, so you will be asked to sign in again afterwards. That is expected.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('3. How to use Google Drive?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to Settings and click "Connect Google Drive". Once authorized, SitesSaver will automatically create a folder in your Drive. You can then manually upload backups or set up a schedule to do it automatically.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('4. Scheduling automatic backups', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the Schedule page to enable automated backups. Tick as many frequencies as you need. Each runs on its own timer, so Daily plus Monthly gives you recent restore points alongside a long-term archive. You also decide whether to keep them on your server, upload to Google Drive, or both.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Retention controls how many backups stay on your server. After each scheduled run, anything beyond that count is deleted oldest-first so the disk does not fill up. It only counts backups the schedule made: backups you create yourself and files you upload are never deleted by it. Files already uploaded to Google Drive are never deleted by retention.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Not sure the schedule works? Use "Run backup now" on the same page. It performs a real scheduled backup immediately using your saved settings, without waiting for the next cron window.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('5. My scheduled backups run late, or never', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('WordPress fires WP-Cron only when someone loads a page, so a quiet site can be hours or days behind, and if DISABLE_WP_CRON is set in wp-config.php, scheduled backups never run at all.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('The Schedule page has a Server Cron panel with a private trigger URL and ready-made crontab, curl, and WP-CLI lines. No shell access? Point a free uptime monitor at the same URL. It is safe to call more often than your chosen frequency: SitesSaver answers "not due yet" until a backup is due. Keep the URL private, and regenerate it from that panel if it ever leaks.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('6. Email notifications after each backup', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Enter an address in the Email Notification field on the Schedule page and you will get a report after every scheduled run. The report says which file was created and how big it is, which schedule triggered it, what the archive contains, and whether the copy was kept on your server, uploaded to Google Drive, or both, with a link to your Drive folder.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('If a backup fails, or the archive was created but the Drive upload did not go through, the email says so explicitly rather than reporting plain success. That distinction matters: a backup that only exists on the same server is lost with the server.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('7. Putting your own branding on those emails', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Settings > Email Branding controls how the report looks. Add your logo, pick an accent colour for the header and buttons, and set the sender name and address your clients will see in their inbox. A support link and a footer note are optional.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Use "Send test email" to see the result before the next backup runs. It saves your changes first and then delivers a real sample to your notification address. If you prefer plain text, untick "Send branded HTML emails". A text version is always included either way.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('For the sender address, use one on this domain, such as noreply@yourdomain.com. An address from another domain is far more likely to be filtered as spam.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('8. Keeping the plugin up to date', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('This copy of SitesSaver was installed from GitHub, and it updates itself from the releases published there. It checks for a new release automatically, and when one is available the update appears on your Plugins screen like any other plugin. Click Update and you are done, or turn on automatic updates for it there. Each download is checked against the SHA-256 checksum GitHub publishes for it before anything is installed.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Your backups, schedule, Google Drive connection, and email branding all survive an update. Backups are stored outside the plugin folder specifically so that updating can never touch them.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Settings > Plugin Updates shows which version you are running, and has a button to check now instead of waiting for the next automatic check.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;" id="sitessaver-storage"><?php esc_html_e('9. Where are my backups stored?', 'sitessaver'); ?></h3>
                <p><?php echo esc_html(sprintf(/* translators: %s: storage folder, such as wp-content/sitessaver-backups/ */ __('In %s on your server, deliberately outside the plugin folder so that updating or reinstalling SitesSaver never deletes them. The folder cannot be listed, and backup files have long random names, so nobody can find them by browsing. A copy in Google Drive is still the best protection: a backup stored only on the server it is protecting will not help you when that server is the thing that fails.', 'sitessaver'), sitessaver_storage_display_path())); ?></p>
                <?php
                $sitessaver_info = sitessaver_storage_exposure_info();
                $sitessaver_rel  = sitessaver_storage_url_path();
                $sitessaver_rule = $sitessaver_rel === null ? '' : 'location ^~ ' . $sitessaver_rel . ' { deny all; }';
                ?>
                <div class="sitessaver-storage-status">
                    <?php if ($sitessaver_info === null || $sitessaver_info['state'] === 'unknown') : ?>
                        <p><i class="ri-time-line" aria-hidden="true"></i> <?php esc_html_e('Folder check: not run yet. It runs in the background; reload this page in a minute.', 'sitessaver'); ?></p>
                    <?php elseif ($sitessaver_info['state'] === 'protected') : ?>
                        <p><i class="ri-shield-check-line" aria-hidden="true"></i> <?php esc_html_e('Folder check: closed to direct downloads.', 'sitessaver'); ?></p>
                    <?php elseif ($sitessaver_rule !== '') : ?>
                        <p><i class="ri-information-line" aria-hidden="true"></i> <?php esc_html_e('Folder check: a backup can be downloaded by someone who knows its exact file name. Names are long and random, so this is not something to worry about, but you can close the folder completely:', 'sitessaver'); ?></p>
                        <details>
                            <summary><?php esc_html_e('How to close it', 'sitessaver'); ?></summary>
                            <?php if ($sitessaver_info['server'] === 'runcloud') : ?>
                                <p><?php esc_html_e('This site runs on RunCloud (Nginx in front of Apache). Nginx sends existing files straight to the visitor, before Apache reads the .htaccess file in the folder. In RunCloud, open the web application > NGINX Config > add a new config (type location.main-before) with:', 'sitessaver'); ?></p>
                            <?php elseif ($sitessaver_info['server'] === 'nginx') : ?>
                                <p><?php esc_html_e('This site runs on Nginx, which ignores .htaccess files. Ask your host to add this to the Nginx configuration of the site:', 'sitessaver'); ?></p>
                            <?php else : ?>
                                <p><?php esc_html_e('This server does not apply the .htaccess file in the folder. Ask your host to block web access to the folder, for example with this Nginx rule:', 'sitessaver'); ?></p>
                            <?php endif; ?>
                            <code class="sitessaver-notice-code"><?php echo esc_html($sitessaver_rule); ?></code>
                        </details>
                    <?php endif; ?>
                </div>

                <h3 style="margin-top: 24px;" id="sitessaver-blocked"><?php esc_html_e('10. "Your server blocked the restore" (Imunify360 and other security software)', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Many cPanel hosts run security software, most often Imunify360, that watches what PHP writes. A restore writes a database file and thousands of site files in a few minutes, which can look suspicious to it, so it refuses the write or stops the request. SitesSaver checks this before it changes anything: if the server will not let it write, the restore stops with "Your server blocked the restore" and your site stays exactly as it was. Your backup file is fine, so downloading it again will not help.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('To get past it: in cPanel open Imunify360 > Proactive Defense and set the mode to "Log only", run the restore, then set it back. Alternatively, add wp-content/plugins/sitessaver/ to the Proactive Defense Ignore list. If your cPanel has no Imunify360 settings, send your host the reference code from the error and ask them to allow SitesSaver to write to wp-content while you restore.', 'sitessaver'); ?></p>
                <?php $sitessaver_layers = \SitesSaver\Environment::security_layers(); ?>
                <div class="sitessaver-storage-status">
                    <?php if (in_array('imunify360', $sitessaver_layers, true)) : ?>
                        <p><i class="ri-information-line" aria-hidden="true"></i> <?php esc_html_e('This server: Imunify360 detected. If a restore or backup is blocked, follow the steps above.', 'sitessaver'); ?></p>
                    <?php elseif ($sitessaver_layers !== []) : ?>
                        <p><i class="ri-information-line" aria-hidden="true"></i> <?php echo esc_html(sprintf(/* translators: %s: comma-separated list such as "cloudlinux, wordfence". */ __('This server: %s detected. These can slow or stop long backups and restores.', 'sitessaver'), implode(', ', $sitessaver_layers))); ?></p>
                    <?php else : ?>
                        <p><i class="ri-shield-check-line" aria-hidden="true"></i> <?php esc_html_e('This server: no known security software detected from inside PHP. Some scanners cannot be seen from here, so the steps above still apply if a restore is blocked.', 'sitessaver'); ?></p>
                    <?php endif; ?>
                </div>

                <h3 style="margin-top: 24px;" id="sitessaver-litespeed"><?php esc_html_e('11. Background backups on LiteSpeed', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('LiteSpeed can stop a PHP request once the browser that started it goes away. A backup that keeps running after you close the tab needs LiteSpeed told not to do that for SitesSaver\'s own background requests. SitesSaver can add a short rule for this to the .htaccess file in your site root. It only applies to requests that carry sitessaver_bg=1 and to wp-cron.php. Nothing is added unless you click the button, and you can remove it here at any time. It is also removed when you deactivate SitesSaver.', 'sitessaver'); ?></p>
                <?php
                $sitessaver_ls_on    = \SitesSaver\Background::is_litespeed();
                $sitessaver_ls_has   = \SitesSaver\Background::rules_installed();
                $sitessaver_ls_state = '';
                if (isset($_GET['sitessaver_rule'], $_GET['sitessaver_rule_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['sitessaver_rule_nonce'])), 'sitessaver_rule')) {
                    $sitessaver_ls_state = sanitize_key(wp_unslash($_GET['sitessaver_rule']));
                }
                ?>
                <div class="sitessaver-storage-status">
                    <?php if ($sitessaver_ls_state === 'added') : ?>
                        <p><i class="ri-shield-check-line" aria-hidden="true"></i> <?php esc_html_e('The rule was added to .htaccess.', 'sitessaver'); ?></p>
                    <?php elseif ($sitessaver_ls_state === 'removed') : ?>
                        <p><i class="ri-shield-check-line" aria-hidden="true"></i> <?php esc_html_e('The rule was removed from .htaccess.', 'sitessaver'); ?></p>
                    <?php elseif ($sitessaver_ls_state === 'failed') : ?>
                        <p><i class="ri-information-line" aria-hidden="true"></i> <?php esc_html_e('The .htaccess file is missing or not writable. You can add the rule below by hand.', 'sitessaver'); ?></p>
                    <?php endif; ?>
                    <p>
                        <?php if ($sitessaver_ls_has) : ?>
                            <i class="ri-shield-check-line" aria-hidden="true"></i> <?php esc_html_e('Rule status: added.', 'sitessaver'); ?>
                        <?php elseif ($sitessaver_ls_on) : ?>
                            <i class="ri-information-line" aria-hidden="true"></i> <?php esc_html_e('Rule status: this server is LiteSpeed and the rule is not added.', 'sitessaver'); ?>
                        <?php else : ?>
                            <i class="ri-information-line" aria-hidden="true"></i> <?php esc_html_e('Rule status: this server does not look like LiteSpeed, so the rule is not needed.', 'sitessaver'); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($sitessaver_ls_on || $sitessaver_ls_has) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr(\SitesSaver\Background::RULE_ACTION); ?>">
                            <?php wp_nonce_field(\SitesSaver\Background::RULE_ACTION); ?>
                            <?php if ($sitessaver_ls_has) : ?>
                                <button type="submit" name="op" value="remove" class="btn btn-outline" id="sitessaver-litespeed-remove"><?php esc_html_e('Remove the LiteSpeed rule', 'sitessaver'); ?></button>
                            <?php else : ?>
                                <button type="submit" name="op" value="add" class="btn btn-primary" id="sitessaver-litespeed-add"><?php esc_html_e('Add the LiteSpeed rule to .htaccess', 'sitessaver'); ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <details>
                        <summary><?php esc_html_e('Show the rule', 'sitessaver'); ?></summary>
                        <pre class="sitessaver-notice-code"><?php echo esc_html(implode("\n", \SitesSaver\Background::htaccess_lines())); ?></pre>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <?php
    $sitessaver_log_entries = \SitesSaver\Log::entries(50);
    $sitessaver_catalogue   = \SitesSaver\Errors::catalogue();
    ?>
    <div class="sitessaver-section" id="sitessaver-log">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-time-line"></i>
                <?php esc_html_e('Troubleshooting Log', 'sitessaver'); ?>
            </h2>
            <?php if (!empty($sitessaver_log_entries)) : ?>
                <div class="sitessaver-log-actions">
                    <a class="btn btn-outline" href="<?php echo esc_url(add_query_arg(['action' => 'sitessaver_log_download', 'nonce' => wp_create_nonce('sitessaver_nonce')], admin_url('admin-ajax.php'))); ?>">
                        <i class="ri-download-2-line"></i>
                        <?php esc_html_e('Download log', 'sitessaver'); ?>
                    </a>
                    <button type="button" class="btn btn-outline" id="sitessaver-log-clear">
                        <i class="ri-delete-bin-line"></i>
                        <?php esc_html_e('Clear', 'sitessaver'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <p class="sitessaver-log-intro">
            <?php esc_html_e('When a backup or restore runs into trouble, SitesSaver writes down what it was doing and why it stopped. Each problem has a reference code (the same one shown in the error message), so whoever helps you can find it quickly. Send them the downloaded log; it contains no passwords or keys.', 'sitessaver'); ?>
        </p>

        <?php if (empty($sitessaver_log_entries)) : ?>
            <div class="sitessaver-empty-state">
                <i class="ri-checkbox-circle-line sitessaver-empty-icon"></i>
                <p><?php esc_html_e('Nothing to report. Problems with backups and restores will show up here.', 'sitessaver'); ?></p>
            </div>
        <?php else : ?>
            <table class="sitessaver-table sitessaver-log-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('When', 'sitessaver'); ?></th>
                        <th><?php esc_html_e('What happened', 'sitessaver'); ?></th>
                        <th><?php esc_html_e('Reference', 'sitessaver'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sitessaver_log_entries as $sitessaver_e) :
                        $sitessaver_level   = (string) ($sitessaver_e['level'] ?? 'info');
                        $sitessaver_code    = (string) ($sitessaver_e['code'] ?? '');
                        $sitessaver_time    = (int) ($sitessaver_e['time'] ?? 0);
                        $sitessaver_human   = isset($sitessaver_catalogue[$sitessaver_code]) ? $sitessaver_catalogue[$sitessaver_code]['title'] : (string) ($sitessaver_e['message'] ?? '');
                        $sitessaver_icon    = $sitessaver_level === 'error' ? 'ri-error-warning-line' : ($sitessaver_level === 'warning' ? 'ri-alert-line' : 'ri-information-line');
                        $sitessaver_context = !empty($sitessaver_e['context']) ? wp_json_encode($sitessaver_e['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                        ?>
                        <tr class="sitessaver-log-row is-<?php echo esc_attr($sitessaver_level); ?>">
                            <td class="sitessaver-log-when">
                                <span title="<?php echo esc_attr(wp_date('Y-m-d H:i:s T', $sitessaver_time)); ?>">
                                    <?php
                                    /* translators: %s: human time difference, e.g. "5 mins". */
                                    echo esc_html(sprintf(__('%s ago', 'sitessaver'), human_time_diff($sitessaver_time)));
                                    ?>
                                </span>
                            </td>
                            <td class="sitessaver-log-what">
                                <span class="sitessaver-log-title"><i class="<?php echo esc_attr($sitessaver_icon); ?>"></i><?php echo esc_html($sitessaver_human); ?></span>
                                <details>
                                    <summary><?php esc_html_e('Technical details', 'sitessaver'); ?></summary>
                                    <code><?php echo esc_html((string) ($sitessaver_e['message'] ?? '')); ?><?php echo $sitessaver_context !== '' ? "\n" . esc_html($sitessaver_context) : ''; ?></code>
                                </details>
                            </td>
                            <td class="sitessaver-log-ref"><?php echo esc_html('SS-' . (string) ($sitessaver_e['ref'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="sitessaver-section" style="margin-top: 32px; border: 1px solid rgba(255, 209, 64, 0.3); background: rgba(255, 209, 64, 0.05);">
        <div class="sitessaver-section-header" style="border-bottom: 1px solid rgba(255, 209, 64, 0.2);">
            <h2 class="sitessaver-section-title" style="color: #856404;">
                <i class="ri-heart-fill" style="color: #e21d1d;"></i>
                <?php esc_html_e('Support this Project', 'sitessaver'); ?>
            </h2>
        </div>
        <div class="sitessaver-section-content" style="padding: 40px; text-align: center;">
            <h3 style="margin-bottom: 16px;"><?php esc_html_e('Buy Me A Coffee', 'sitessaver'); ?></h3>
            <p style="margin-bottom: 24px; color: var(--sitessaver-text-muted);">
                <?php esc_html_e('If SitesSaver has helped you, consider supporting its development. Your donations help keep this plugin free and restriction-free for everyone.', 'sitessaver'); ?>
            </p>
            
            <div style="display:inline-grid;justify-items:center;align-content:start;gap:0.5rem;">
                <a class="sitessaver-donate" href="https://www.paypal.com/ncp/payment/4WBDKC57TT5DL" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Donate', 'sitessaver'); ?></a>
                <span style="font-size: 0.75rem;"><?php esc_html_e('Opens PayPal in a new tab.', 'sitessaver'); ?></span>
            </div>
        </div>
    </div>
</div>
