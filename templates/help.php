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

                <h3 style="margin-top: 24px;"><?php esc_html_e('3. How to use Google Drive?', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to Settings and click "Connect Google Drive". Once authorized, SitesSaver will automatically create a folder in your Drive. You can then manually upload backups or set up a schedule to do it automatically.', 'sitessaver'); ?></p>

                <h3 style="margin-top: 24px;"><?php esc_html_e('4. Scheduling automatic backups', 'sitessaver'); ?></h3>
                <p><?php esc_html_e('Go to the Schedule page to enable automated backups. You can choose the frequency (Daily, Weekly, etc.) and decide whether to keep them on your server, upload to Google Drive, or both.', 'sitessaver'); ?></p>
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
