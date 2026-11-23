<?php defined('ABSPATH') || exit; 

// Result of a storage connection, handled in Admin::handle_remote_callback().
$sitessaver_auth_msg  = '';
$sitessaver_auth_ok   = false;
$sitessaver_notice = get_transient('sitessaver_auth_notice_' . get_current_user_id());
if (is_array($sitessaver_notice)) {
    delete_transient('sitessaver_auth_notice_' . get_current_user_id());
    $sitessaver_auth_msg = (string) ($sitessaver_notice['message'] ?? '');
    $sitessaver_auth_ok  = !empty($sitessaver_notice['ok']);
}

$sitessaver_settings = get_option('sitessaver_settings', []);
$sitessaver_token    = get_option('sitessaver_gdrive_token', []);
$sitessaver_is_connected = !empty($sitessaver_token['refresh_token']);

$sitessaver_brand        = \SitesSaver\Mailer::branding();
$sitessaver_notify_email = get_option('sitessaver_schedule', []);
$sitessaver_notify_email = is_array($sitessaver_notify_email) ? ($sitessaver_notify_email['notify_email'] ?? '') : '';
if ($sitessaver_notify_email === '') {
    $sitessaver_notify_email = (string) get_option('admin_email');
}

$sitessaver_latest           = \SitesSaver\Updater::latest_release();
$sitessaver_update_available = $sitessaver_latest !== null && \SitesSaver\Updater::is_newer($sitessaver_latest['version']);
$sitessaver_update_error     = \SitesSaver\Updater::last_error();
?>
<div class="sitessaver-wrap">
    <header class="sitessaver-header">
        <h1 class="sitessaver-title">
            <i class="ri-settings-5-fill"></i>
            <?php esc_html_e('SitesSaver: Settings', 'sitessaver'); ?>
        </h1>
        <div class="sitessaver-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver')); ?>" class="btn btn-outline">
                <i class="ri-arrow-left-line"></i>
                <?php esc_html_e('Back to Dashboard', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <?php if (!empty($sitessaver_auth_msg)) : ?>
        <div style="margin: 20px 24px; padding: 12px 16px; border-radius: 6px; background: <?php echo $sitessaver_auth_ok ? '#e6f4ea' : '#fce8e6'; ?>; color: <?php echo $sitessaver_auth_ok ? '#137333' : '#c5221f'; ?>; border: 1px solid <?php echo $sitessaver_auth_ok ? '#ceead6' : '#fad2cf'; ?>;" role="<?php echo $sitessaver_auth_ok ? 'status' : 'alert'; ?>">
            <?php echo esc_html($sitessaver_auth_msg); ?>
        </div>
    <?php endif; ?>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">

                <i class="ri-drive-line"></i>
                <?php esc_html_e('Google Drive Integration', 'sitessaver'); ?>
            </h2>
            <?php if ($sitessaver_is_connected) : ?>
                <span class="badge badge-blue"><?php esc_html_e('Connected', 'sitessaver'); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="sitessaver-section-content">
            <?php if (!$sitessaver_is_connected) : ?>
                <div style="text-align: center; padding: 40px 0; color: var(--sitessaver-text-light);">
                    <i class="ri-drive-fill" style="font-size: 48px; color: var(--sitessaver-border); display: block; margin-bottom: 20px;"></i>
                    <h3 style="margin-top: 0; color: var(--sitessaver-text-main);"><?php esc_html_e('Cloud Storage Not Configured', 'sitessaver'); ?></h3>
                    <p style="margin-bottom: 24px; font-size: 16px; color: var(--sitessaver-text-muted);">
                        <?php esc_html_e('Connect your Google Drive to automatically store backups in the cloud and sync across multiple sites.', 'sitessaver'); ?>
                    </p>
                    <a href="<?php echo esc_url(\SitesSaver\GDrive::get_auth_url()); ?>" class="btn btn-success" style="padding: 12px 24px;">
                        <i class="ri-google-fill"></i>
                        <?php esc_html_e('Connect Google Drive', 'sitessaver'); ?>
                    </a>
                </div>

            <?php else : ?>
                <div style="background: rgba(15, 157, 88, 0.05); border: 1px solid rgba(15, 157, 88, 0.2); padding: 24px; border-radius: var(--sitessaver-radius-card); text-align: center;">
                    <i class="ri-checkbox-circle-fill" style="font-size: 32px; color: #0F9D58; display: block; margin-bottom: 12px;"></i>
                    <h3 style="margin: 0 0 8px 0; color: var(--sitessaver-text-main);"><?php esc_html_e('Google Drive Connected Successfully', 'sitessaver'); ?></h3>
                    <p style="margin: 0; color: var(--sitessaver-text-muted);">
                        <?php esc_html_e('Your site is linked. A backup folder has been automatically created in your Google Drive root.', 'sitessaver'); ?>
                    </p>
                </div>

                <div style="margin-top: 32px; display: flex; justify-content: center; padding-top: 24px; border-top: 1px solid var(--sitessaver-border-light);">
                    <button type="button" id="sitessaver-gdrive-disconnect" class="btn btn-outline" style="color: var(--sitessaver-danger); border-color: var(--sitessaver-danger);">
                        <i class="ri-logout-circle-line"></i>
                        <?php esc_html_e('Disconnect Google Drive Account', 'sitessaver'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach (\SitesSaver\Destinations::extra() as $sitessaver_dest) : $sitessaver_on = $sitessaver_dest->is_connected(); ?>
    <div class="sitessaver-section" id="sitessaver-dest-<?php echo esc_attr($sitessaver_dest->id()); ?>">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="<?php echo esc_attr($sitessaver_dest->icon()); ?>"></i>
                <?php
                /* translators: %s: provider name */
                echo esc_html(sprintf(__('%s Integration', 'sitessaver'), $sitessaver_dest->label()));
                ?>
            </h2>
            <?php if ($sitessaver_on) : ?>
                <span class="badge badge-blue"><?php esc_html_e('Connected', 'sitessaver'); ?></span>
            <?php endif; ?>
        </div>
        <div class="sitessaver-section-content">
            <?php if (!$sitessaver_on) : ?>
                <div style="text-align: center; padding: 40px 0; color: var(--sitessaver-text-light);">
                    <i class="<?php echo esc_attr($sitessaver_dest->icon()); ?>" style="font-size: 48px; color: var(--sitessaver-border); display: block; margin-bottom: 20px;"></i>
                    <p style="margin-bottom: 24px; font-size: 16px; color: var(--sitessaver-text-muted);"><?php echo esc_html($sitessaver_dest->description()); ?></p>
                    <a href="<?php echo esc_url($sitessaver_dest->auth_url()); ?>" class="btn btn-success" style="padding: 12px 24px;">
                        <i class="<?php echo esc_attr($sitessaver_dest->icon()); ?>"></i>
                        <?php
                        /* translators: %s: provider name */
                        echo esc_html(sprintf(__('Connect %s', 'sitessaver'), $sitessaver_dest->label()));
                        ?>
                    </a>
                </div>
            <?php else : ?>
                <div style="background: rgba(15, 157, 88, 0.05); border: 1px solid rgba(15, 157, 88, 0.2); padding: 24px; border-radius: var(--sitessaver-radius-card); text-align: center;">
                    <i class="ri-checkbox-circle-fill" style="font-size: 32px; color: #0F9D58; display: block; margin-bottom: 12px;"></i>
                    <h3 style="margin: 0 0 8px 0; color: var(--sitessaver-text-main);">
                        <?php
                        /* translators: %s: provider name */
                        echo esc_html(sprintf(__('%s Connected Successfully', 'sitessaver'), $sitessaver_dest->label()));
                        ?>
                    </h3>
                    <p style="margin: 0; color: var(--sitessaver-text-muted);">
                        <a href="<?php echo esc_url($sitessaver_dest->folder_url()); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open the backup folder', 'sitessaver'); ?></a>
                    </p>
                </div>
                <div style="margin-top: 32px; display: flex; justify-content: center; padding-top: 24px; border-top: 1px solid var(--sitessaver-border-light);">
                    <button type="button" class="btn btn-outline sitessaver-remote-disconnect" data-dest="<?php echo esc_attr($sitessaver_dest->id()); ?>" data-label="<?php echo esc_attr($sitessaver_dest->label()); ?>" style="color: var(--sitessaver-danger); border-color: var(--sitessaver-danger);">
                        <i class="ri-logout-circle-line"></i>
                        <?php
                        /* translators: %s: provider name */
                        echo esc_html(sprintf(__('Disconnect %s Account', 'sitessaver'), $sitessaver_dest->label()));
                        ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php
    $sitessaver_en = get_option('sitessaver_export_notify', []);
    $sitessaver_en = is_array($sitessaver_en) ? $sitessaver_en : [];
    $sitessaver_en_on = ($sitessaver_en['enabled'] ?? '1') !== '0';
    ?>
    <div class="sitessaver-section" id="sitessaver-export-notify">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-notification-3-line"></i>
                <?php esc_html_e('Backup Notifications', 'sitessaver'); ?>
            </h2>
        </div>

        <div class="sitessaver-section-content">
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e('When you start a backup and leave the Export page, SitesSaver can email you once it has finished or stopped. Scheduled backups have their own email setting on the Schedule page.', 'sitessaver'); ?>
            </p>

            <form id="sitessaver-export-notify-form" novalidate>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Email when done', 'sitessaver'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="notify_enabled" value="1" <?php checked($sitessaver_en_on); ?> />
                                <?php esc_html_e('Email me when a backup I started finishes and the page is closed', 'sitessaver'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="sitessaver-notify-email-row"<?php echo $sitessaver_en_on ? '' : ' hidden'; ?>>
                        <th><label for="sitessaver-notify-email"><?php esc_html_e('Send to', 'sitessaver'); ?></label></th>
                        <td>
                            <input type="email" id="sitessaver-notify-email" name="notify_email" value="<?php echo esc_attr((string) ($sitessaver_en['email'] ?? '')); ?>" class="sitessaver-input-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>" />
                            <p class="description"><?php esc_html_e('Leave empty to use the site admin email shown in the box.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top: 16px; padding: 0;">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i>
                        <?php esc_html_e('Save Notifications', 'sitessaver'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-mail-star-line"></i>
                <?php esc_html_e('Email Branding', 'sitessaver'); ?>
            </h2>
            <span class="badge <?php echo $sitessaver_brand['enabled'] !== '0' ? 'badge-blue' : ''; ?>">
                <?php echo $sitessaver_brand['enabled'] !== '0'
                    ? esc_html__('HTML email on', 'sitessaver')
                    : esc_html__('Plain text', 'sitessaver'); ?>
            </span>
        </div>

        <div class="sitessaver-section-content">
            <p class="description" style="margin-top: 0;">
                <?php esc_html_e('Controls how backup notification emails look. Your logo, colour, and sender name are applied to every scheduled backup report.', 'sitessaver'); ?>
            </p>

            <form id="sitessaver-email-brand-form">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Email Format', 'sitessaver'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($sitessaver_brand['enabled'] !== '0'); ?> />
                                <?php esc_html_e('Send branded HTML emails', 'sitessaver'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Uncheck to send plain text only. A text version is always included as a fallback.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Logo URL', 'sitessaver'); ?></th>
                        <td>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                <input type="url" name="logo_url" value="<?php echo esc_attr($sitessaver_brand['logo_url']); ?>" class="sitessaver-input-text" style="min-width: 320px;" placeholder="https://example.com/logo.png" />
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
                            <input type="color" name="accent" value="<?php echo esc_attr($sitessaver_brand['accent']); ?>" style="width: 56px; height: 36px; padding: 2px; vertical-align: middle; border: 1px solid var(--sitessaver-border); border-radius: var(--sitessaver-radius); background: #fff; cursor: pointer;" />
                            <p class="description"><?php esc_html_e('Used for the header band, buttons, and links.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sender Name', 'sitessaver'); ?></th>
                        <td>
                            <input type="text" name="from_name" value="<?php echo esc_attr($sitessaver_brand['from_name']); ?>" class="sitessaver-input-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                            <p class="description"><?php esc_html_e('The name recipients see in their inbox.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sender Address', 'sitessaver'); ?></th>
                        <td>
                            <input type="email" name="from_email" value="<?php echo esc_attr($sitessaver_brand['from_email']); ?>" class="sitessaver-input-text" placeholder="noreply@<?php echo esc_attr(wp_parse_url(home_url(), PHP_URL_HOST) ?: 'example.com'); ?>" />
                            <p class="description"><?php esc_html_e('Leave empty to use the WordPress default. Use an address on this domain so the email is not flagged as spam.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Support Link', 'sitessaver'); ?></th>
                        <td>
                            <input type="url" name="support_url" value="<?php echo esc_attr($sitessaver_brand['support_url']); ?>" class="sitessaver-input-text" style="min-width: 320px;" placeholder="https://example.com/support" />
                            <p class="description"><?php esc_html_e('Optional. Adds a "Need help with this backup?" link in the footer.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Footer Note', 'sitessaver'); ?></th>
                        <td>
                            <textarea name="footer_note" rows="2" class="sitessaver-input-text" style="min-width: 320px; width: 100%; max-width: 520px;" placeholder="<?php esc_attr_e('e.g. Managed hosting and backups by Your Agency', 'sitessaver'); ?>"><?php echo esc_textarea($sitessaver_brand['footer_note']); ?></textarea>
                            <p class="description"><?php esc_html_e('Optional line at the bottom of every report.', 'sitessaver'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top: 24px; padding: 0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-line"></i>
                        <?php esc_html_e('Save Email Branding', 'sitessaver'); ?>
                    </button>
                    <button type="button" class="btn btn-outline" id="sitessaver-send-test-email" data-email="<?php echo esc_attr($sitessaver_notify_email); ?>">
                        <i class="ri-send-plane-line"></i>
                        <?php esc_html_e('Send test email', 'sitessaver'); ?>
                    </button>
                    <span class="description" style="margin: 0;">
                        <?php
                        printf(
                            /* translators: %s: email address */
                            esc_html__('Test goes to %s', 'sitessaver'),
                            '<code>' . esc_html($sitessaver_notify_email) . '</code>'
                        );
                        ?>
                    </span>
                </p>
            </form>
        </div>
    </div>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-refresh-line"></i>
                <?php esc_html_e('Plugin Updates', 'sitessaver'); ?>
            </h2>
            <?php if ($sitessaver_update_available) : ?>
                <span class="badge badge-red"><?php
                    printf(
                        /* translators: %s: version number */
                        esc_html__('v%s available', 'sitessaver'),
                        esc_html($sitessaver_latest['version'])
                    );
                ?></span>
            <?php else : ?>
                <span class="badge badge-blue"><?php
                    printf(
                        /* translators: %s: version number */
                        esc_html__('v%s installed', 'sitessaver'),
                        esc_html(SITESSAVER_VERSION)
                    );
                ?></span>
            <?php endif; ?>
        </div>

        <div class="sitessaver-section-content" id="sitessaver-plugin-updates">
            <?php if ($sitessaver_update_available) : ?>
                <div style="background: rgba(var(--sitessaver-danger-rgb), 0.05); border: 1px solid rgba(var(--sitessaver-danger-rgb), 0.2); padding: 18px 20px; border-radius: var(--sitessaver-radius-card);">
                    <h3 style="margin: 0 0 6px 0; font-size: 15px; color: var(--sitessaver-text-main);">
                        <?php
                        printf(
                            /* translators: 1: new version, 2: installed version */
                            esc_html__('SitesSaver %1$s is available. You are running %2$s.', 'sitessaver'),
                            esc_html($sitessaver_latest['version']),
                            esc_html(SITESSAVER_VERSION)
                        );
                        ?>
                    </h3>
                    <p style="margin: 0 0 14px 0; color: var(--sitessaver-text-muted); font-size: 13px;">
                        <?php esc_html_e('Your backups, schedule, and Google Drive connection are kept when you update.', 'sitessaver'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('plugins.php?s=sitessaver&plugin_status=all')); ?>" class="btn btn-primary">
                        <i class="ri-download-2-line"></i>
                        <?php esc_html_e('Go to Plugins to update', 'sitessaver'); ?>
                    </a>
                    <?php if (!empty($sitessaver_latest['url'])) : ?>
                        <a href="<?php echo esc_url($sitessaver_latest['url']); ?>" target="_blank" rel="noopener" class="btn btn-outline">
                            <i class="ri-file-list-3-line"></i>
                            <?php esc_html_e('What\'s new', 'sitessaver'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 260px;">
                        <h3 style="margin: 0 0 4px 0; font-size: 15px; color: var(--sitessaver-text-main);">
                            <?php esc_html_e('SitesSaver is up to date', 'sitessaver'); ?>
                        </h3>
                        <p style="margin: 0; color: var(--sitessaver-text-muted); font-size: 13px;">
                            <?php
                            printf(
                                /* translators: %s: version number */
                                esc_html__('Running version %s. New versions are found automatically and appear on your Plugins screen.', 'sitessaver'),
                                esc_html(SITESSAVER_VERSION)
                            );
                            ?>
                        </p>
                        <?php if ($sitessaver_update_error !== '') : ?>
                            <p style="margin: 8px 0 0 0; color: var(--sitessaver-warning-hover); font-size: 12px;">
                                <i class="ri-alert-line"></i>
                                <?php
                                printf(
                                    /* translators: %s: reason the check failed */
                                    esc_html__('Last check could not reach GitHub: %s', 'sitessaver'),
                                    esc_html($sitessaver_update_error)
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php if (current_user_can('update_plugins')) : ?>
                        <button type="button" class="btn btn-outline" id="sitessaver-check-update">
                            <i class="ri-refresh-line"></i>
                            <?php esc_html_e('Check for updates', 'sitessaver'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
