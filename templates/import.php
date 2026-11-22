<?php defined('ABSPATH') || exit; ?>
<div class="sitessaver-wrap">
    <header class="sitessaver-header">
        <h1 class="sitessaver-title">
            <i class="ri-download-2-fill"></i>
            <?php esc_html_e('SitesSaver: Import', 'sitessaver'); ?>
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
                <i class="ri-upload-cloud-2-line"></i>
                <?php esc_html_e('Upload Backup File', 'sitessaver'); ?>
            </h2>
        </div>
        
        <div class="sitessaver-section-content">
            <p style="margin-bottom: 20px; color: var(--sitessaver-text-muted);">
                <?php esc_html_e('Upload a SitesSaver backup ZIP to restore or migrate your site. Large files are uploaded in chunks.', 'sitessaver'); ?>
            </p>

            <div id="sitessaver-drop-zone" class="sitessaver-upload-zone" style="border: 2px dashed var(--sitessaver-border); padding: 40px; text-align: center; border-radius: var(--sitessaver-radius-card); transition: all 0.2s; cursor: pointer;">
                <i class="ri-file-zip-line" style="font-size: 48px; color: var(--sitessaver-border); display: block; margin-bottom: 12px;"></i>
                <p class="sitessaver-upload-text" style="font-size: 16px; font-weight: 500; margin-bottom: 8px;">
                    <?php esc_html_e('Drop your backup ZIP here', 'sitessaver'); ?>
                </p>
                <p style="color: var(--sitessaver-text-muted); font-size: 14px; margin-bottom: 16px;"><?php esc_html_e('or', 'sitessaver'); ?></p>
                <input type="file" id="sitessaver-import-file" style="display:none;" accept=".zip" />
                <button type="button" class="btn btn-primary" onclick="document.getElementById('sitessaver-import-file').click();">
                    <?php esc_html_e('Select File', 'sitessaver'); ?>
                </button>
                <p style="margin-top: 16px; font-size: 12px; color: var(--sitessaver-text-muted);">
                    <?php
                    /* translators: %s: maximum upload size, for example 64 MB. */
                    printf(esc_html__('Max file size: %s', 'sitessaver'), esc_html(sitessaver_format_size(sitessaver_max_upload_size())));
                    ?>
                </p>
            </div>

            <div class="sitessaver-progress" style="display:none;">
                <div class="sitessaver-progress-text">
                    <span class="step-label"><?php esc_html_e('Uploading...', 'sitessaver'); ?></span>
                    <span class="step-pct">0%</span>
                </div>
                <div class="sitessaver-progress-bar">
                    <div class="sitessaver-progress-fill"></div>
                </div>
            </div>

            <div class="sitessaver-result" style="display:none;">
                <div class="sitessaver-result-card">
                    <i></i>
                    <div>
                        <strong style="display:block;" class="sitessaver-result-title"></strong>
                        <span class="sitessaver-result-text"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
