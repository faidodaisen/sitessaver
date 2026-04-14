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
                <form id="sitessaver-settings-form">
                    <table class="ss-form-table">
                        <tr>
                            <th><?php esc_html_e('Destination Folder ID', 'sitessaver'); ?></th>
                            <td>
                                <input type="text" name="gdrive_folder_id" value="<?php echo esc_attr($settings['gdrive_folder_id'] ?? ''); ?>" class="ss-input-text" placeholder="Folder ID from URL" />
                                <p class="description">
                                    <?php esc_html_e('Optional: Enter a Folder ID (the last part of the Google Drive URL) to store backups in a specific folder. Leave empty for root.', 'sitessaver'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 32px; display: flex; justify-content: space-between; align-items: center; padding-top: 24px; border-top: 1px solid var(--ss-border-light);">
                        <button type="submit" class="btn btn-primary">
                            <i class="ri-save-line"></i>
                            <?php esc_html_e('Save Cloud Settings', 'sitessaver'); ?>
                        </button>
                        <button type="button" id="sitessaver-gdrive-disconnect" class="btn btn-outline" style="color: var(--ss-danger); border-color: var(--ss-danger);">
                            <i class="ri-logout-circle-line"></i>
                            <?php esc_html_e('Disconnect Google Drive', 'sitessaver'); ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
