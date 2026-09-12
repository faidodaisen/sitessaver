<?php defined('ABSPATH') || exit; 

// Handle OAuth callback from proxy.
$auth_msg = '';
if (isset($_GET['sitessaver_gdrive_token']) && current_user_can('manage_options')) {
    $result    = \SitesSaver\GDrive::handle_callback();
    $auth_msg  = $result['message'] ?? '';
}

$settings = get_option('sitessaver_settings', []);
$token    = get_option('sitessaver_gdrive_token', []);
$is_connected = !empty($token['refresh_token']);

$brand        = \SitesSaver\Mailer::branding();
$notify_email = get_option('sitessaver_schedule', []);
$notify_email = is_array($notify_email) ? ($notify_email['notify_email'] ?? '') : '';
if ($notify_email === '') {
    $notify_email = (string) get_option('admin_email');
}
?>
<div class="sitessaver-wrap">
    <header class="ss-header">
        <h1 class="ss-title">
            <i class="ri-settings-5-fill"></i>
            <?php esc_html_e('SitesSaver — Settings', 'sitessaver'); ?>
        </h1>
        <div class="ss-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver')); ?>" class="btn btn-outline">
                <i class="ri-arrow-left-line"></i>
                <?php esc_html_e('Back to Dashboard', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <?php if (!empty($auth_msg)) : ?>
        <div style="margin: 20px 24px; padding: 12px 16px; border-radius: 6px; background: <?php echo $is_connected ? '#e6f4ea' : '#fce8e6'; ?>; color: <?php echo $is_connected ? '#137333' : '#c5221f'; ?>; border: 1px solid <?php echo $is_connected ? '#ceead6' : '#fad2cf'; ?>;">
            <?php echo esc_html($auth_msg); ?>
        </div>
    <?php endif; ?>

    <div class="ss-section">
        <div class="ss-section-header">
            <h2 class="ss-section-title">

                <i class="ri-drive-line"></i>
                <?php esc_html_e('Google Drive Integration', 'sitessaver'); ?>
            </h2>
            <?php if ($is_connected) : ?>
                <span class="badge badge-blue"><?php esc_html_e('Connected', 'sitessaver'); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="ss-section-content">
            <?php if (!$is_connected) : ?>
                <div style="text-align: center; padding: 40px 0; color: var(--ss-text-light);">
                    <i class="ri-drive-fill" style="font-size: 48px; color: var(--ss-border); display: block; margin-bottom: 20px;"></i>
                    <h3 style="margin-top: 0; color: var(--ss-text-main);"><?php esc_html_e('Cloud Storage Not Configured', 'sitessaver'); ?></h3>
                    <p style="margin-bottom: 24px; font-size: 16px; color: var(--ss-text-muted);">
                        <?php esc_html_e('Connect your Google Drive to automatically store backups in the cloud and sync across multiple sites.', 'sitessaver'); ?>
                    </p>
                    <a href="<?php echo esc_url(\SitesSaver\GDrive::get_auth_url()); ?>" class="btn btn-success" style="padding: 12px 24px;">
                        <i class="ri-google-fill"></i>
                        <?php esc_html_e('Connect Google Drive', 'sitessaver'); ?>
                    </a>
                </div>

            <?php else : ?>
                <div style="background: rgba(15, 157, 88, 0.05); border: 1px solid rgba(15, 157, 88, 0.2); padding: 24px; border-radius: var(--ss-radius-card); text-align: center;">
                    <i class="ri-checkbox-circle-fill" style="font-size: 32px; color: #0F9D58; display: block; margin-bottom: 12px;"></i>
                    <h3 style="margin: 0 0 8px 0; color: var(--ss-text-main);"><?php esc_html_e('Google Drive Connected Successfully', 'sitessaver'); ?></h3>
                    <p style="margin: 0; color: var(--ss-text-muted);">
                        <?php esc_html_e('Your site is linked. A backup folder has been automatically created in your Google Drive root.', 'sitessaver'); ?>
                    </p>
                </div>

                <div style="margin-top: 32px; display: flex; justify-content: center; padding-top: 24px; border-top: 1px solid var(--ss-border-light);">
                    <button type="button" id="sitessaver-gdrive-disconnect" class="btn btn-outline" style="color: var(--ss-danger); border-color: var(--ss-danger);">
                        <i class="ri-logout-circle-line"></i>
                        <?php esc_html_e('Disconnect Google Drive Account', 'sitessaver'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ss-section">
        <div class="ss-section-header">
            <h2 class="ss-section-title">
                <i class="ri-mail-star-line"></i>
                <?php esc_html_e('Email Branding', 'sitessaver'); ?>
            </h2>
            <span class="badge <?php echo $brand['enabled'] !== '0' ? 'badge-blue' : ''; ?>">
                <?php echo $brand['enabled'] !== '0'
                    ? esc_html__('HTML email on', 'sitessaver')
                    : esc_html__('Plain text', 'sitessaver'); ?>
            </span>
        </div>

        <div class="ss-section-content">
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e('Controls how backup notification emails look. Your logo, colour, and sender name are applied to every scheduled backup report.', 'sitessaver'); ?>
            </p>

            <form id="sitessaver-email-brand-form">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Email Format', 'sitessaver'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($brand['enabled'] !== '0'); ?> />
                                <?php esc_html_e('Send branded HTML emails', 'sitessaver'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Uncheck to send plain text only. A text version is always included as a fallback.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Logo URL', 'sitessaver'); ?></th>
                        <td>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                <input type="url" name="logo_url" value="<?php echo esc_attr($brand['logo_url']); ?>" class="ss-input-text" style="min-width: 320px;" placeholder="https://example.com/logo.png" />
                                <button type="button" class="btn btn-outline" id="sitessaver-pick-logo">
                                    <i class="ri-image-line"></i>
                                    <?php esc_html_e('Choose from Media', 'sitessaver'); ?>
                                </button>
                            </div>
                            <p class="description"><?php esc_html_e('Shown at the top of the email. A transparent PNG about 300px wide works best. Leave empty to use your site name as a wordmark.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Accent Colour', 'sitessaver'); ?></th>
                        <td>
                            <input type="color" name="accent" value="<?php echo esc_attr($brand['accent']); ?>" style="width: 56px; height: 36px; padding: 2px; vertical-align: middle; border: 1px solid var(--ss-border); border-radius: var(--ss-radius); background: #fff; cursor: pointer;" />
                            <p class="description"><?php esc_html_e('Used for the header band, buttons, and links.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sender Name', 'sitessaver'); ?></th>
                        <td>
                            <input type="text" name="from_name" value="<?php echo esc_attr($brand['from_name']); ?>" class="ss-input-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                            <p class="description"><?php esc_html_e('The name recipients see in their inbox.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sender Address', 'sitessaver'); ?></th>
                        <td>
                            <input type="email" name="from_email" value="<?php echo esc_attr($brand['from_email']); ?>" class="ss-input-text" placeholder="noreply@<?php echo esc_attr(wp_parse_url(home_url(), PHP_URL_HOST) ?: 'example.com'); ?>" />
                            <p class="description"><?php esc_html_e('Leave empty to use the WordPress default. Use an address on this domain so the email is not flagged as spam.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Support Link', 'sitessaver'); ?></th>
                        <td>
                            <input type="url" name="support_url" value="<?php echo esc_attr($brand['support_url']); ?>" class="ss-input-text" style="min-width: 320px;" placeholder="https://example.com/support" />
                            <p class="description"><?php esc_html_e('Optional. Adds a "Need help with this backup?" link in the footer.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Footer Note', 'sitessaver'); ?></th>
                        <td>
                            <textarea name="footer_note" rows="2" class="ss-input-text" style="min-width: 320px; width: 100%; max-width: 520px;" placeholder="<?php esc_attr_e('e.g. Managed hosting and backups by Your Agency', 'sitessaver'); ?>"><?php echo esc_textarea($brand['footer_note']); ?></textarea>
                            <p class="description"><?php esc_html_e('Optional line at the bottom of every report.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top: 24px; padding: 0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i>
                        <?php esc_html_e('Save Email Branding', 'sitessaver'); ?>
                    </button>
                    <button type="button" class="btn btn-outline" id="sitessaver-send-test-email" data-email="<?php echo esc_attr($notify_email); ?>">
                        <i class="ri-send-plane-line"></i>
                        <?php esc_html_e('Send test email', 'sitessaver'); ?>
                    </button>
                    <span class="description" style="margin: 0;">
                        <?php
                        printf(
                            /* translators: %s: email address */
                            esc_html__('Test goes to %s', 'sitessaver'),
                            '<code>' . esc_html($notify_email) . '</code>'
                        );
                        ?>
                    </span>
                </p>
            </form>
        </div>
    </div>
</div>
