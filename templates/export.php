<?php defined('ABSPATH') || exit; ?>
<div class="sitessaver-wrap">
    <header class="ss-header">
        <h1 class="ss-title">
            <i class="ri-upload-2-fill"></i>
            <?php esc_html_e('SitesSaver — Export', 'sitessaver'); ?>
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
                <i class="ri-settings-4-line"></i>
                <?php esc_html_e('Configure Export', 'sitessaver'); ?>
            </h2>
        </div>
        
        <div class="ss-section-content">
            <form id="sitessaver-export-form">
                <table class="ss-form-table">
                    <tr>
                        <th><?php esc_html_e('Inclusions', 'sitessaver'); ?></th>
                        <td>
                            <div class="ss-checkbox-group">
                                <label><input type="checkbox" name="include_db" value="1" checked /> <?php esc_html_e('Database (SQL Dump)', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_media" value="1" checked /> <?php esc_html_e('Media Uploads', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_plugins" value="1" checked /> <?php esc_html_e('Plugins Content', 'sitessaver'); ?></label>
                                <label><input type="checkbox" name="include_themes" value="1" checked /> <?php esc_html_e('Active & Inactive Themes', 'sitessaver'); ?></label>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="sitessaver-progress" style="display:none;">
                    <div class="sitessaver-progress-text">
                        <span class="step-label"><?php esc_html_e('Preparing...', 'sitessaver'); ?></span>
                        <span class="step-pct">0%</span>
                    </div>
                    <div class="sitessaver-progress-bar">
                        <div class="sitessaver-progress-fill"></div>
                    </div>
                </div>

                <div class="sitessaver-result" style="display:none;">
                    <div class="ss-result-card success">
                        <i class="ri-checkbox-circle-fill"></i>
                        <div>
                            <strong style="display:block;"><?php esc_html_e('Success!', 'sitessaver'); ?></strong>
                            <span class="sitessaver-result-text"></span>
                        </div>
                    </div>
                </div>

                <p class="submit" style="margin-top: 24px; padding: 0;">
                    <button type="submit" class="btn btn-success" id="sitessaver-export-btn" style="padding: 12px 24px; font-size: 16px;">
                        <i class="ri-play-circle-line"></i>
                        <?php esc_html_e('Start Export Process', 'sitessaver'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>
