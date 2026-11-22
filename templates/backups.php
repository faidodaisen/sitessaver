<?php defined('ABSPATH') || exit; 
$sitessaver_backups = sitessaver_get_backups();
$sitessaver_drive_on = class_exists('SitesSaver\\GDrive') && \SitesSaver\GDrive::is_connected();
$sitessaver_stats   = [
    'count'      => count($sitessaver_backups),
    'total_size' => sitessaver_format_size(array_sum(array_column($sitessaver_backups, 'size'))),
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
    
    <header class="sitessaver-header">
        <h1 class="sitessaver-title">
            <i class="ri-shield-check-fill"></i>
            <?php esc_html_e('SitesSaver: Backups', 'sitessaver'); ?>
        </h1>
        <div class="sitessaver-header-actions">
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
    // Help > "Where are my backups stored?".
    $sitessaver_short = sitessaver_count_short_named_backups($sitessaver_backups);
    if ($sitessaver_short > 0
        && sitessaver_storage_exposure() === 'exposed'
        && !get_user_meta(get_current_user_id(), 'sitessaver_dismissed_storage_notice', true)) : ?>
        <div class="sitessaver-notice sitessaver-notice-info sitessaver-notice-compact" id="sitessaver-storage-notice">
            <i class="ri-information-line" aria-hidden="true"></i>
            <div>
                <?php
                printf(
                    /* translators: %d: number of older backups. */
                    esc_html(_n(
                        '%d older backup on this server has a short file name. Delete it if you no longer need it. Newer backups have long, unguessable names.',
                        '%d older backups on this server have short file names. Delete the ones you no longer need. Newer backups have long, unguessable names.',
                        $sitessaver_short,
                        'sitessaver'
                    )),
                    (int) $sitessaver_short
                );
                ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-help#sitessaver-storage')); ?>"><?php esc_html_e('Why?', 'sitessaver'); ?></a>
            </div>
            <button type="button" class="sitessaver-notice-dismiss" id="sitessaver-storage-notice-dismiss" aria-label="<?php esc_attr_e('Dismiss', 'sitessaver'); ?>"><i class="ri-close-line" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>

    <div class="sitessaver-stats-grid">
        <div class="sitessaver-stat-card">
            <div class="sitessaver-stat-icon blue">
                <i class="ri-database-2-line"></i>
            </div>
            <div class="sitessaver-stat-content">
                <span class="sitessaver-stat-label"><?php esc_html_e('Backups Created', 'sitessaver'); ?></span>
                <span class="sitessaver-stat-value" id="sitessaver-stat-count"><?php echo esc_html($sitessaver_stats['count']); ?></span>
                <?php if ($sitessaver_drive_on) : ?>
                    <span class="sitessaver-stat-sub" id="sitessaver-stat-count-sub"><?php esc_html_e('Checking Google Drive...', 'sitessaver'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="sitessaver-stat-card">
            <div class="sitessaver-stat-icon purple">
                <i class="ri-hard-drive-2-line"></i>
            </div>
            <div class="sitessaver-stat-content">
                <span class="sitessaver-stat-label"><?php esc_html_e('Total Size', 'sitessaver'); ?></span>
                <span class="sitessaver-stat-value" id="sitessaver-stat-size"><?php echo esc_html($sitessaver_stats['total_size']); ?></span>
                <?php if ($sitessaver_drive_on) : ?>
                    <span class="sitessaver-stat-sub" id="sitessaver-stat-size-sub">&nbsp;</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="sitessaver-stat-card">
            <div class="sitessaver-stat-icon orange">
                <i class="ri-server-line"></i>
            </div>
            <div class="sitessaver-stat-content">
                <span class="sitessaver-stat-label"><?php esc_html_e('Database Size', 'sitessaver'); ?></span>
                <span class="sitessaver-stat-value"><?php echo esc_html($sitessaver_stats['db_size']); ?></span>
            </div>
        </div>
    </div>

    <div class="sitessaver-section">
        <div class="sitessaver-section-header">
            <h2 class="sitessaver-section-title">
                <i class="ri-folder-shield-2-line"></i>
                <?php esc_html_e('Local Server Backups', 'sitessaver'); ?>
            </h2>
        </div>
        
        <?php if (empty($sitessaver_backups)) : ?>
            <div class="sitessaver-empty-state" id="sitessaver-local-empty">
                <i class="ri-folder-open-line sitessaver-empty-icon"></i>
                <p data-drive-text="<?php esc_attr_e('No backups on this server. Your backups are in Google Drive below.', 'sitessaver'); ?>"><?php esc_html_e('No backups found. Create your first backup to secure your site.', 'sitessaver'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sitessaver-export')); ?>" class="btn btn-primary" style="margin-top: 20px;">
                    <?php esc_html_e('Take a Backup Now', 'sitessaver'); ?>
                </a>
            </div>
        <?php else : ?>
            <table class="sitessaver-table">
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
                    <?php foreach ($sitessaver_backups as $sitessaver_b) :
                        $sitessaver_chain_info = \SitesSaver\Index::describe($sitessaver_b['file']);
                        $sitessaver_is_inc     = $sitessaver_chain_info['type'] === 'incremental';
                    ?>
                        <tr<?php echo $sitessaver_is_inc ? ' class="is-chain-child"' : ''; ?>>
                            <td>
                                <div class="cell-filename">
                                    <i class="<?php echo $sitessaver_is_inc ? 'ri-git-commit-line' : 'ri-file-zip-line'; ?>"></i>
                                    <?php echo esc_html($sitessaver_b['file']); ?>
                                </div>
                                <?php if ($sitessaver_chain_info['type'] !== 'standalone') : ?>
                                    <div class="cell-chain">
                                        <?php if ($sitessaver_is_inc) : ?>
                                            <span class="badge badge-blue"><?php esc_html_e('Incremental', 'sitessaver'); ?></span>
                                            <span class="cell-meta">
                                                <?php
                                                printf(
                                                    /* translators: %d: number of backup files needed to restore. */
                                                    esc_html(_n(
                                                        'Restores with %d file',
                                                        'Restores with %d files',
                                                        (int) $sitessaver_chain_info['restore_count'],
                                                        'sitessaver'
                                                    )),
                                                    (int) $sitessaver_chain_info['restore_count']
                                                );
                                                ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="badge badge-gray"><?php esc_html_e('Full', 'sitessaver'); ?></span>
                                            <?php if ($sitessaver_chain_info['followers'] > 0) : ?>
                                                <span class="cell-meta">
                                                    <?php
                                                    printf(
                                                        /* translators: %d: number of incremental backups built on this one. */
                                                        esc_html(_n(
                                                            'Base for %d incremental backup',
                                                            'Base for %d incremental backups',
                                                            (int) $sitessaver_chain_info['followers'],
                                                            'sitessaver'
                                                        )),
                                                        (int) $sitessaver_chain_info['followers']
                                                    );
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="cell-label"><?php echo esc_html($sitessaver_b['label'] ?: '-'); ?></div>
                                <span class="badge badge-gray" style="margin-top: 4px;"><?php esc_html_e('Local', 'sitessaver'); ?></span>
                            </td>
                            <td class="cell-meta"><?php echo esc_html($sitessaver_b['size_h']); ?></td>
                            <td class="cell-meta"><?php echo esc_html($sitessaver_b['created_h']); ?></td>
                            <td>
                                <div class="action-btns">
                                    <button class="btn-icon sitessaver-label-btn" data-file="<?php echo esc_attr($sitessaver_b['file']); ?>" title="<?php esc_attr_e('Edit Label', 'sitessaver'); ?>">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button class="btn-icon sitessaver-restore-btn" data-file="<?php echo esc_attr($sitessaver_b['file']); ?>" title="<?php esc_attr_e('Restore', 'sitessaver'); ?>">
                                        <i class="ri-history-line"></i>
                                    </button>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=sitessaver_download_backup&file=' . $sitessaver_b['file']), 'sitessaver_download', 'nonce')); ?>" class="btn-icon" title="<?php esc_attr_e('Download', 'sitessaver'); ?>">
                                        <i class="ri-download-2-line"></i>
                                    </a>
                                    <button class="btn-icon sitessaver-gdrive-upload-btn" data-file="<?php echo esc_attr($sitessaver_b['file']); ?>" title="<?php esc_attr_e('Upload to Drive', 'sitessaver'); ?>">
                                        <i class="ri-drive-fill"></i>
                                    </button>
                                    <?php foreach (\SitesSaver\Destinations::extra() as $sitessaver_dest) : if (!$sitessaver_dest->is_connected()) { continue; } ?>
                                    <button class="btn-icon sitessaver-remote-upload-btn" data-dest="<?php echo esc_attr($sitessaver_dest->id()); ?>" data-label="<?php echo esc_attr($sitessaver_dest->label()); ?>" data-file="<?php echo esc_attr($sitessaver_b['file']); ?>" title="<?php
                                        /* translators: %s: provider name */
                                        echo esc_attr(sprintf(__('Upload to %s', 'sitessaver'), $sitessaver_dest->label())); ?>">
                                        <i class="<?php echo esc_attr($sitessaver_dest->icon()); ?>"></i>
                                    </button>
                                    <?php endforeach; ?>

                                    <button class="btn-icon danger sitessaver-delete-btn"
                                            data-file="<?php echo esc_attr($sitessaver_b['file']); ?>"
                                            data-dependents="<?php echo (int) $sitessaver_chain_info['followers']; ?>"
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
    $sitessaver_gdrive_connected = false;
    if (class_exists('SitesSaver\GDrive')) {
        $sitessaver_gdrive_connected = \SitesSaver\GDrive::is_connected();
    }
    ?>
    <?php if ($sitessaver_gdrive_connected) : ?>
        <div class="sitessaver-section">
            <div class="sitessaver-section-header">
                <h2 class="sitessaver-section-title">
                    <i class="ri-drive-fill" style="color: #0F9D58;"></i>
                    <?php esc_html_e('Google Drive Backups', 'sitessaver'); ?>
                </h2>
                <div class="sitessaver-section-actions" style="display: flex; gap: 8px;">
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
                 data-local="<?php echo esc_attr(wp_json_encode(array_map(static fn($sitessaver_b) => ['name' => $sitessaver_b['file'], 'size' => (int) $sitessaver_b['size']], $sitessaver_backups))); ?>">
                <p style="padding: 24px; text-align: center; color: var(--sitessaver-text-muted);">
                    <?php esc_html_e('Loading cloud backups...', 'sitessaver'); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach (\SitesSaver\Destinations::extra() as $sitessaver_dest) : if (!$sitessaver_dest->is_connected()) { continue; } ?>
        <div class="sitessaver-section">
            <div class="sitessaver-section-header">
                <h2 class="sitessaver-section-title">
                    <i class="<?php echo esc_attr($sitessaver_dest->icon()); ?>"></i>
                    <?php
                    /* translators: %s: provider name */
                    echo esc_html(sprintf(__('%s Backups', 'sitessaver'), $sitessaver_dest->label()));
                    ?>
                </h2>
                <div class="sitessaver-section-actions" style="display: flex; gap: 8px;">
                    <a href="<?php echo esc_url($sitessaver_dest->folder_url()); ?>" target="_blank" rel="noopener" class="btn btn-outline" style="padding: 4px 10px; font-size: 12px;">
                        <i class="ri-external-link-line"></i> <?php esc_html_e('Open folder', 'sitessaver'); ?>
                    </a>
                    <button type="button" class="btn btn-outline sitessaver-remote-refresh" data-dest="<?php echo esc_attr($sitessaver_dest->id()); ?>" style="padding: 4px 10px; font-size: 12px;">
                        <i class="ri-refresh-line"></i> <?php esc_html_e('Refresh', 'sitessaver'); ?>
                    </button>
                </div>
            </div>
            <div class="sitessaver-remote-files" data-dest="<?php echo esc_attr($sitessaver_dest->id()); ?>" data-label="<?php echo esc_attr($sitessaver_dest->label()); ?>" data-autoload="1">
                <p style="padding: 24px; text-align: center; color: var(--sitessaver-text-muted);">
                    <?php esc_html_e('Loading cloud backups...', 'sitessaver'); ?>
                </p>
            </div>
        </div>
    <?php endforeach; ?>

</div>
