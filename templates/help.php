<?php defined('ABSPATH') || exit; ?>
<div class="sitessaver-wrap">
    <header class="ss-header">
        <h1 class="ss-title">
            <i class="ri-question-line"></i>
            <?php esc_html_e('SitesSaver — Help & Manual', 'sitessaver'); ?>
        </h1>
        <div class="ss-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver')); ?>" class="btn btn-outline">
                <i class="ri-arrow-left-line"></i>
                <?php esc_html_e('Back to Dashboard', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <div class="ss-section">
        <div class="ss-section-header">
            <h2 class="ss-section-title">
                <i class="ri-book-open-line"></i>
                <?php esc_html_e('User Manual', 'sitessaver'); ?>
            </h2>
        </div>
        
        <div class="ss-section-content" style="padding: 30px;">
            <div style="max-width: 800px; line-height: 1.6;">
                <h3 style="margin-top: 0;"><?php esc_html_e('1. How to create a backup?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the main dashboard or the Export page. Select what you want to include (Database, Media, etc.) and click "Create Backup". The plugin will package everything into a single ZIP file.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('2. How to restore my site?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the Import page. You can drag and drop your backup ZIP file into the box, or click to select the file. Once uploaded, click "Restore" and wait for the process to finish.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('You can also restore straight from a backup already in your Google Drive. Open the Backups page and scroll to the "Google Drive Backups" section, then choose Restore on the file you want. It is downloaded and restored in one step, and the copy stays on your server afterwards.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Restoring a backup from a different domain rewrites URLs in the database, so you will be asked to sign in again afterwards. That is expected.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('3. How to use Google Drive?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to Settings and click "Connect Google Drive". Once authorized, SitesSaver will automatically create a folder in your Drive. You can then manually upload backups or set up a schedule to do it automatically.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('4. Scheduling automatic backups', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the Schedule page to enable automated backups. Tick as many frequencies as you need — they each run on their own timer, so Daily plus Monthly gives you recent restore points alongside a long-term archive. You also decide whether to keep them on your server, upload to Google Drive, or both.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Retention controls how many backups stay on your server. After each scheduled run, anything beyond that count is deleted oldest-first so the disk does not fill up. Note that it counts every backup in the folder, including ones you created manually, so set it high enough to cover both. Files already uploaded to Google Drive are never deleted by retention.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Not sure the schedule works? Use "Run backup now" on the same page. It performs a real scheduled backup immediately using your saved settings, without waiting for the next cron window.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('5. My scheduled backups run late, or never', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('WordPress fires WP-Cron only when someone loads a page, so a quiet site can be hours or days behind — and if DISABLE_WP_CRON is set in wp-config.php, scheduled backups never run at all.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('The Schedule page has a Server Cron panel with a private trigger URL and ready-made crontab, curl, and WP-CLI lines. No shell access? Point a free uptime monitor at the same URL. It is safe to call more often than your chosen frequency: SitesSaver answers "not due yet" until a backup is genuinely owed. Keep the URL private, and regenerate it from that panel if it ever leaks.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('6. Email notifications after each backup', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Enter an address in the Email Notification field on the Schedule page and you will get a report after every scheduled run. The report says which file was created and how big it is, which schedule triggered it, what the archive contains, and whether the copy was kept on your server, uploaded to Google Drive, or both — with a link straight to your Drive folder.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('If a backup fails, or the archive was created but the Drive upload did not go through, the email says so explicitly rather than reporting plain success. That distinction matters: a backup that only exists on the same server is lost with the server.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('7. Putting your own branding on those emails', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Settings → Email Branding controls how the report looks. Add your logo, pick an accent colour for the header and buttons, and set the sender name and address your clients will see in their inbox. A support link and a footer note are optional.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Use "Send test email" to see the result before the next backup runs. It saves your changes first and then delivers a real sample to your notification address. If you prefer plain text, untick "Send branded HTML emails" — a text version is always included either way.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('For the sender address, use one on this domain, such as noreply@yourdomain.com. An address from another domain is far more likely to be filtered as spam.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('8. Keeping the plugin up to date', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('SitesSaver is installed manually rather than from the WordPress plugin directory, but it still updates itself. It checks for new releases automatically, and when one is available the update appears on your Plugins screen exactly like any other plugin — click Update and you are done.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Your backups, schedule, Google Drive connection, and email branding all survive an update. Backups are stored outside the plugin folder specifically so that updating can never touch them.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('Settings → Plugin Updates shows which version you are running, and has a button to check immediately instead of waiting for the next automatic check.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('9. Where are my backups stored?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('In wp-content/sitessaver-backups/ on your server, deliberately outside the plugin folder so that updating or reinstalling SitesSaver never deletes them. The folder is protected from direct download by an .htaccess rule.', 'sitessaver'); ?></p>
                <p><?php esc_html_e('On Nginx that .htaccess is ignored, so ask your host to deny public access to that path. Better still, keep a copy in Google Drive: a backup stored only on the server it is protecting will not help you when that server is the thing that fails.', 'sitessaver'); ?></p>
            </div>
        </div>
    </div>

    <div class="ss-section" style="margin-top: 32px; border: 1px solid rgba(255, 209, 64, 0.3); background: rgba(255, 209, 64, 0.05);">
        <div class="ss-section-header" style="border-bottom: 1px solid rgba(255, 209, 64, 0.2);">
            <h2 class="ss-section-title" style="color: #856404;">
                <i class="ri-heart-fill" style="color: #e21d1d;"></i>
                <?php esc_html_e('Support this Project', 'sitessaver'); ?>
            </h2>
        </div>
        <div class="ss-section-content" style="padding: 40px; text-align: center;">
            <h3 style="margin-bottom: 16px;"><?php esc_html_e('Buy Me A Coffee', 'sitessaver'); ?></h3>
            <p style="margin-bottom: 24px; color: var(--ss-text-muted);">
                <?php esc_html_e('If SitesSaver has helped you, consider supporting its development. Your donations help keep this plugin free and restriction-free for everyone.', 'sitessaver'); ?>
            </p>
            
            <div>
                <style>.pp-4WBDKC57TT5DL{text-align:center;border:none;border-radius:0.25rem;min-width:11.625rem;padding:0 2rem;height:2.625rem;font-weight:bold;background-color:#FFD140;color:#000000;font-family:"Helvetica Neue",Arial,sans-serif;font-size:1rem;line-height:1.25rem;cursor:pointer;}</style>
                <form action="https://www.paypal.com/ncp/payment/4WBDKC57TT5DL" method="post" target="_blank" style="display:inline-grid;justify-items:center;align-content:start;gap:0.5rem;">
                    <input class="pp-4WBDKC57TT5DL" type="submit" value="Buy Now" />
                    <img src="https://www.paypalobjects.com/images/Debit_Credit_APM.svg" alt="cards" />
                    <section style="font-size: 0.75rem;"> Powered by <img src="https://www.paypalobjects.com/paypal-ui/logos/svg/paypal-wordmark-color.svg" alt="paypal" style="height:0.875rem;vertical-align:middle;"/></section>
                </form>
            </div>
        </div>
    </div>
</div>
