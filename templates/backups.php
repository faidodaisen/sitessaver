<?php defined('ABSPATH') || exit; 
$backups = sitessaver_get_backups();
$sitessaver_drive_on = class_exists('SitesSaver\\GDrive') && \SitesSaver\GDrive::is_connected();
$stats   = [
    'count'      => count($backups),
    'total_size' => sitessaver_format_size(array_sum(array_column($backups, 'size'))),
    'db_size'    => class_exists('SitesSaver\Database') ? sitessaver_format_size(\SitesSaver\Database::get_size()) : '0 B',
];
?>

<div class="sitessaver-wrap" id="sitessaver-backups-page">
    
    <div class="sitessaver-progress" style="display:none;">
        <div class="sitessaver-progress-info">
            <span class="step-label"></span>
            <span class="step-pct">0%</span>
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

    <?php
    // Only worth a word when it matters: the folder is reachable by direct
    // link AND there are backups with the short pre-1.5.1 names, the only
    // ones a stranger could realistically guess. Everything else lives in
    // Help → "Where are my backups stored?".
    $sitessaver_short = sitessaver_count_short_named_backups($backups);
    if ($sitessaver_short > 0
        && sitessaver_storage_exposure() === 'exposed'
        && !get_user_meta(get_current_user_id(), 'sitessaver_dismissed_storage_notice', true)) : ?>
        <div class="ss-notice ss-notice-info ss-notice-compact" id="ss-storage-notice">
            <i class="ri-information-line" aria-hidden="true"></i>
            <div>
                <?php
                printf(
                    /* translators: %d: number of older backups. */
                    esc_html(_n(
                        '%d older backup on this server has a short file name. Delete it if you no longer need it — newer backups have long, unguessable names.',
                        '%d older backups on this server have short file names. Delete the ones you no longer need — newer backups have long, unguessable names.',
                        $sitessaver_short,
                        'sitessaver'
                    )),
                    (int) $sitessaver_short
                );
                ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-help#ss-storage')); ?>"><?php esc_html_e('Why?', 'sitessaver'); ?></a>
            </div>
            <button type="button" class="ss-notice-dismiss" id="ss-storage-notice-dismiss" aria-label="<?php esc_attr_e('Dismiss', 'sitessaver'); ?>"><i class="ri-close-line" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>

    <div class="ss-stats-grid">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue">
                <i class="ri-database-2-line"></i>
            </div>
            <div class="ss-stat-content">
                <span class="ss-stat-label"><?php esc_html_e('Backups Created', 'sitessaver'); ?></span>
                <span class="ss-stat-value" id="ss-stat-count"><?php echo esc_html($stats['count']); ?></span>
                <?php if ($sitessaver_drive_on) : ?>
                    <span class="ss-stat-sub" id="ss-stat-count-sub"><?php esc_html_e('Checking Google Drive…', 'sitessaver'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="ss-stat-card">
            <div class="ss-stat-icon purple">
                <i class="ri-hard-drive-2-line"></i>
            </div>
            <div class="ss-stat-content">
                <span class="ss-stat-label"><?php esc_html_e('Total Size', 'sitessaver'); ?></span>
                <span class="ss-stat-value" id="ss-stat-size"><?php echo esc_html($stats['total_size']); ?></span>
                <?php if ($sitessaver_drive_on) : ?>
                    <span class="ss-stat-sub" id="ss-stat-size-sub">&nbsp;</span>
                <?php endif; ?>
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
            <div class="ss-empty-state" id="ss-local-empty">
                <i class="ri-folder-open-line ss-empty-icon"></i>
                <p data-drive-text="<?php esc_attr_e('No backups on this server. Your backups are in Google Drive below.', 'sitessaver'); ?>"><?php esc_html_e('No backups found. Create your first backup to secure your site.', 'sitessaver'); ?></p>
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
                    <?php foreach ($backups as $b) :
                        $chain_info = \SitesSaver\Index::describe($b['file']);
                        $is_inc     = $chain_info['type'] === 'incremental';
                    ?>
                        <tr<?php echo $is_inc ? ' class="is-chain-child"' : ''; ?>>
                            <td>
                                <div class="cell-filename">
                                    <i class="<?php echo $is_inc ? 'ri-git-commit-line' : 'ri-file-zip-line'; ?>"></i>
                                    <?php echo esc_html($b['file']); ?>
                                </div>
                                <?php if ($chain_info['type'] !== 'standalone') : ?>
                                    <div class="cell-chain">
                                        <?php if ($is_inc) : ?>
                                            <span class="badge badge-blue"><?php esc_html_e('Incremental', 'sitessaver'); ?></span>
                                            <span class="cell-meta">
                                                <?php
                                                printf(
                                                    /* translators: %d: number of backup files needed to restore. */
                                                    esc_html(_n(
                                                        'Restores with %d file',
                                                        'Restores with %d files',
                                                        (int) $chain_info['restore_count'],
                                                        'sitessaver'
                                                    )),
                                                    (int) $chain_info['restore_count']
                                                );
                                                ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="badge badge-gray"><?php esc_html_e('Full', 'sitessaver'); ?></span>
                                            <?php if ($chain_info['followers'] > 0) : ?>
                                                <span class="cell-meta">
                                                    <?php
                                                    printf(
                                                        /* translators: %d: number of incremental backups built on this one. */
                                                        esc_html(_n(
                                                            'Base for %d incremental backup',
                                                            'Base for %d incremental backups',
                                                            (int) $chain_info['followers'],
                                                            'sitessaver'
                                                        )),
                                                        (int) $chain_info['followers']
                                                    );
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
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

                                    <button class="btn-icon danger sitessaver-delete-btn"
                                            data-file="<?php echo esc_attr($b['file']); ?>"
                                            data-dependents="<?php echo (int) $chain_info['followers']; ?>"
                                            title="<?php esc_attr_e('Delete', 'sitessaver'); ?>">
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
            <div id="sitessaver-gdrive-files"
                 data-autoload="1"
                 data-local="<?php echo esc_attr(wp_json_encode(array_map(static fn($b) => ['name' => $b['file'], 'size' => (int) $b['size']], $backups))); ?>">
                <p style="padding: 24px; text-align: center; color: var(--ss-text-muted);">
                    <?php esc_html_e('Loading cloud backups…', 'sitessaver'); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

</div>
