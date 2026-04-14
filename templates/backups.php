<?php defined('ABSPATH') || exit; 
$backups = sitessaver_get_backups();
$stats   = [
    'count'      => count($backups),
    'total_size' => sitessaver_format_size(array_sum(array_column($backups, 'size'))),
    'db_size'    => class_exists('SitesSaver\Database') ? sitessaver_format_size(\SitesSaver\Database::get_size()) : '0 B',
];
?>

<div class="sitessaver-wrap" id="sitessaver-backups-page">
    
    <div class="sitessaver-progress" style="display:none;">
        <div class="sitessaver-progress-info">
            <span class="sitessaver-progress-label"></span>
            <span class="sitessaver-progress-pct">0%</span>
        </div>
        <div class="sitessaver-progress-bar">
            <div class="sitessaver-progress-fill"></div>
        </div>
    </div>

    <div class="sitessaver-result" style="display:none;"></div>
    
    <header class="ss-header">
        <h1 class="ss-title">
            <i class="ri-shield-check-fill"></i>
            <?php esc_html_e('SitesSaver — Backups', 'sitessaver'); ?>
        </h1>
        <div class="ss-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-import')); ?>" class="btn btn-outline">
                <i class="ri-upload-cloud-2-line"></i>
                <?php esc_html_e('Import Backup', 'sitessaver'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-export')); ?>" class="btn btn-success">
                <i class="ri-add-circle-line"></i>
                <?php esc_html_e('Create Backup', 'sitessaver'); ?>
            </a>
        </div>
    </header>

    <div class="ss-stats-grid">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue">
                <i class="ri-database-2-line"></i>
            </div>
            <div class="ss-stat-content">
                <span class="ss-stat-label"><?php esc_html_e('Backups Created', 'sitessaver'); ?></span>
                <span class="ss-stat-value"><?php echo esc_html($stats['count']); ?></span>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon purple">
                <i class="ri-hard-drive-2-line"></i>
            </div>
            <div class="ss-stat-content">
                <span class="ss-stat-label"><?php esc_html_e('Total Size', 'sitessaver'); ?></span>
                <span class="ss-stat-value"><?php echo esc_html($stats['total_size']); ?></span>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon orange">
                <i class="ri-server-line"></i>
            </div>
            <div class="ss-stat-content">
                <span class="ss-stat-label"><?php esc_html_e('Database Size', 'sitessaver'); ?></span>
                <span class="ss-stat-value"><?php echo esc_html($stats['db_size']); ?></span>
            </div>
        </div>
    </div>

    <div class="ss-section">
        <div class="ss-section-header">
            <h2 class="ss-section-title">
                <i class="ri-folder-shield-2-line"></i>
                <?php esc_html_e('Local Server Backups', 'sitessaver'); ?>
            </h2>
        </div>
        
        <?php if (empty($backups)) : ?>
            <div class="ss-empty-state">
                <i class="ri-folder-open-line ss-empty-icon"></i>
                <p><?php esc_html_e('No backups found. Create your first backup to secure your site.', 'sitessaver'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-export')); ?>" class="btn btn-primary" style="margin-top: 20px;">
                    <?php esc_html_e('Take a Backup Now', 'sitessaver'); ?>
                </a>
            </div>
        <?php else : ?>
            <table class="ss-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Backup File', 'sitessaver'); ?></th>
                        <th><?php esc_html_e('Label', 'sitessaver'); ?></th>
                        <th><?php esc_html_e('Size', 'sitessaver'); ?></th>
                        <th><?php esc_html_e('Created On', 'sitessaver'); ?></th>
                        <th><?php esc_html_e('Actions', 'sitessaver'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b) : ?>
                        <tr>
                            <td>
                                <div class="cell-filename">
                                    <i class="ri-file-zip-line"></i>
                                    <?php echo esc_html($b['file']); ?>
                                </div>
                            </td>
                            <td>
                                <div class="cell-label"><?php echo esc_html($b['label'] ?: '—'); ?></div>
                                <span class="badge badge-gray" style="margin-top: 4px;"><?php esc_html_e('Local', 'sitessaver'); ?></span>
                            </td>
                            <td class="cell-meta"><?php echo esc_html($b['size_h']); ?></td>
                            <td class="cell-meta"><?php echo esc_html($b['created_h']); ?></td>
                            <td>
                                <div class="action-btns">
                                    <button class="btn-icon sitessaver-label-btn" data-file="<?php echo esc_attr($b['file']); ?>" title="<?php esc_attr_e('Edit Label', 'sitessaver'); ?>">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button class="btn-icon sitessaver-restore-btn" data-file="<?php echo esc_attr($b['file']); ?>" title="<?php esc_attr_e('Restore', 'sitessaver'); ?>">
                                        <i class="ri-history-line"></i>
                                    </button>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=sitessaver_download_backup&file=' . $b['file']), 'sitessaver_download', 'nonce')); ?>" class="btn-icon" title="<?php esc_attr_e('Download', 'sitessaver'); ?>">
                                        <i class="ri-download-2-line"></i>
                                    </a>
                                    <button class="btn-icon sitessaver-gdrive-upload-btn" data-file="<?php echo esc_attr($b['file']); ?>" title="<?php esc_attr_e('Upload to Drive', 'sitessaver'); ?>">
                                        <i class="ri-drive-fill"></i>
                                    </button>

                                    <button class="btn-icon danger sitessaver-delete-btn" data-file="<?php echo esc_attr($b['file']); ?>" title="<?php esc_attr_e('Delete', 'sitessaver'); ?>">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php 
    $gdrive_connected = false;
    if (class_exists('SitesSaver\GDrive')) {
        $gdrive_connected = \SitesSaver\GDrive::is_connected();
    }
    ?>
    <?php if ($gdrive_connected) : ?>
        <div class="ss-section">
            <div class="ss-section-header">
                <h2 class="ss-section-title">
                    <i class="ri-drive-fill" style="color: #0F9D58;"></i>
                    <?php esc_html_e('Google Drive Backups', 'sitessaver'); ?>
                </h2>
                <div class="ss-section-actions" style="display: flex; gap: 8px;">
                    <a href="<?php echo esc_url(\SitesSaver\GDrive::get_folder_url()); ?>" target="_blank" class="btn btn-outline" style="padding: 4px 10px; font-size: 12px; color: #0F9D58; border-color: #0F9D58;">
                        <i class="ri-external-link-line"></i> <?php esc_html_e('Open Drive', 'sitessaver'); ?>
                    </a>
                    <button id="sitessaver-gdrive-refresh" class="btn btn-outline" style="padding: 4px 10px; font-size: 12px;">
                        <i class="ri-refresh-line"></i> <?php esc_html_e('Refresh', 'sitessaver'); ?>
                    </button>
                </div>
            </div>
            <div id="sitessaver-gdrive-files">
                <p style="padding: 24px; text-align: center; color: var(--ss-text-muted);">
                    <?php esc_html_e('Click refresh to load cloud backups.', 'sitessaver'); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

</div>
