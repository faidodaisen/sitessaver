<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * AJAX request handlers for all SitesSaver operations.
 */
final class Ajax {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function init(): void {
        $actions = [
            'sitessaver_export'         => 'handle_export',
            'sitessaver_export_step'    => 'handle_export_step',
            'sitessaver_get_export_status' => 'handle_get_export_status',
            'sitessaver_cancel_export'     => 'handle_cancel_export',
            'sitessaver_import'         => 'handle_import',
            'sitessaver_import_upload'  => 'handle_import_upload',
            'sitessaver_delete_backup'  => 'handle_delete',
            'sitessaver_download_backup'=> 'handle_download',
            'sitessaver_add_label'      => 'handle_label',
            'sitessaver_save_schedule'  => 'handle_save_schedule',
            'sitessaver_save_settings'  => 'handle_save_settings',
            'sitessaver_gdrive_disconnect' => 'handle_gdrive_disconnect',
            'sitessaver_gdrive_upload'  => 'handle_gdrive_upload',
            'sitessaver_get_gdrive_upload_status' => 'handle_get_gdrive_upload_status',
            'sitessaver_gdrive_list'    => 'handle_gdrive_list',
            'sitessaver_gdrive_download'=> 'handle_gdrive_download',
            'sitessaver_gdrive_restore' => 'handle_gdrive_restore',
            'sitessaver_gdrive_delete'  => 'handle_gdrive_delete',
            'sitessaver_upload_chunk'   => 'handle_upload_chunk',
            'sitessaver_cleanup_chunks' => 'handle_cleanup_chunks',
            'sitessaver_finalize_restore' => 'handle_finalize_restore',
        ];



        foreach ($actions as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
        }
    }

    /**
     * Start the step-based export process.
     */
    public function handle_export(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $allowed_destinations = ['local', 'gdrive', 'both'];
        $destination = sanitize_text_field(wp_unslash($_POST['export_destination'] ?? 'local'));
        if (!in_array($destination, $allowed_destinations, true)) {
            $destination = 'local';
        }

        $options = [
            'include_db'         => (bool) ($_POST['include_db'] ?? true),
            'include_media'      => (bool) ($_POST['include_media'] ?? true),
            'include_plugins'    => (bool) ($_POST['include_plugins'] ?? true),
            'include_themes'     => (bool) ($_POST['include_themes'] ?? true),
            'export_destination' => $destination,
        ];

        $status = Export::start($options);
        $steps  = Export::get_steps();

        wp_send_json_success([
            'status' => $status,
            'steps'  => $steps,
        ]);
    }

    /**
     * Run a single step of the export.
     */
    public function handle_export_step(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $uid   = sanitize_text_field($_POST['uid'] ?? '');
        $index = (int) ($_POST['step_index'] ?? 0);
        $result = Export::run_step($uid, $index);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Get the current status of the active export.
     */
    public function handle_get_export_status(): void {
        sitessaver_verify_ajax();

        $uid    = sanitize_text_field((string) ($_POST['uid'] ?? get_transient('sitessaver_active_export_id') ?? ''));
        $status = $uid !== '' ? Export::get_status($uid) : [];
        $steps  = Export::get_steps();

        if (empty($status)) {
            wp_send_json_error(['message' => __('No active export found.', 'sitessaver')]);
        }

        wp_send_json_success([
            'status' => $status,
            'steps'  => $steps,
        ]);
    }

    /**
     * Cancel a running export — clears the transient state and removes the
     * temp working directory. Used when the browser picks up an orphaned
     * export on page load and the user chooses "Discard" instead of resuming.
     */
    public function handle_cancel_export(): void {
        sitessaver_verify_ajax();

        $uid = sanitize_text_field(wp_unslash($_POST['uid'] ?? ''));
        if ($uid === '') {
            $uid = (string) (get_transient('sitessaver_active_export_id') ?: '');
        }

        if ($uid !== '') {
            $status = Export::get_status($uid);
            if (!empty($status['temp_dir'])) {
                sitessaver_cleanup_temp($status['temp_dir']);
            }
            delete_transient("sitessaver_export_{$uid}");
        }

        delete_transient('sitessaver_active_export_id');

        wp_send_json_success(['message' => __('Export cancelled.', 'sitessaver')]);
    }

    /**
     * Import/restore from existing backup file.
     *
     * Why the output buffer:
     *   Third-party plugins (Elementor, Landinghub, etc.) sometimes emit PHP
     *   notices — e.g. WP 6.7's "textdomain loaded too early" — *during* the
     *   import request. Any stray output ahead of `wp_send_json_*` corrupts
     *   the JSON response and the client shows a generic "An error occurred"
     *   with no hint of what actually ran. We buffer, discard pre-output, then
     *   send a clean JSON envelope. Notices still reach debug.log.
     */
    public function handle_import(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        ob_start();

        $file = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));

        if (empty($file)) {
            self::discard_output_buffer();
            wp_send_json_error(['message' => __('No backup file specified.', 'sitessaver')]);
        }

        $result = Import::from_backup($file);

        self::discard_output_buffer();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Import from uploaded ZIP file.
     */
    public function handle_import_upload(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        ob_start();

        if (empty($_FILES['backup'])) {
            self::discard_output_buffer();
            wp_send_json_error(['message' => __('No file uploaded.', 'sitessaver')]);
        }

        $result = Import::from_upload($_FILES['backup']);

        self::discard_output_buffer();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Discard only the buffer we opened in this handler. Leaves any outer
     * WordPress / third-party buffers untouched so we don't clobber their
     * output lifecycle.
     */
    private static function discard_output_buffer(): void {
        if (ob_get_level() === 0) {
            return;
        }
        $contents = ob_get_clean();
        if ($contents !== false && $contents !== '') {
            error_log('[SitesSaver] Stray output during AJAX import suppressed: ' . substr($contents, 0, 500));
        }
    }

    /**
     * Finalize a restore: run the deferred post-import work, log the user
     * out, and hand back a login URL that will redirect to Settings >
     * Permalinks after re-auth (mirrors the All-in-One WP Migration UX).
     */
    public function handle_finalize_restore(): void {
        sitessaver_verify_ajax();

        Import::run_deferred_finalisation();

        $redirect_after_login = admin_url('options-permalink.php?sitessaver_finalize=1');
        $login_url = wp_login_url($redirect_after_login);

        wp_logout();

        wp_send_json_success([
            'redirect' => $login_url,
            'message'  => __('Restore finalised. Please log in again.', 'sitessaver'),
        ]);
    }

    /**
     * Delete a backup file.
     */
    public function handle_delete(): void {
        sitessaver_verify_ajax();

        $file = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));
        $path = sitessaver_resolve_backup_path($file);

        if ($path === null) {
            wp_send_json_error(['message' => __('Backup not found.', 'sitessaver')]);
        }

        wp_delete_file($path);

        // Remove label if exists.
        $labels = get_option('sitessaver_backup_labels', []);
        unset($labels[$file]);
        update_option('sitessaver_backup_labels', $labels, false);

        wp_send_json_success(['message' => __('Backup deleted.', 'sitessaver')]);
    }

    /**
     * Download a backup file using chunked streaming with Range support.
     *
     * Reads and outputs the file in small chunks (8 KB) so large backups
     * never hit PHP memory limits. Supports HTTP Range requests so browsers
     * can resume interrupted downloads.
     */
    public function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'sitessaver'));
        }

        check_admin_referer('sitessaver_download', 'nonce');

        $file      = sanitize_file_name(wp_unslash($_GET['file'] ?? ''));
        $real_path = sitessaver_resolve_backup_path($file);

        if ($real_path === null) {
            wp_die(esc_html__('Backup not found.', 'sitessaver'));
        }

        $file_size = filesize($real_path);
        $start     = 0;
        $end       = $file_size - 1;
        $status    = 200;

        // Handle HTTP Range request (resume support).
        if (!empty($_SERVER['HTTP_RANGE'])) {
            // Validate format: "bytes=start-end" or "bytes=start-".
            if (!preg_match('/^bytes=(\d+)-(\d*)$/', $_SERVER['HTTP_RANGE'], $matches)) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header("Content-Range: bytes */{$file_size}");
                exit;
            }

            $start = (int) $matches[1];
            $end   = $matches[2] !== '' ? (int) $matches[2] : $file_size - 1;

            // Validate range bounds.
            if ($start > $end || $start >= $file_size || $end >= $file_size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header("Content-Range: bytes */{$file_size}");
                exit;
            }

            $status = 206;
        }

        $length = $end - $start + 1;

        // Allow unlimited execution time for large files.
        @set_time_limit(0);

        // Clear all output buffers to prevent memory bloat.
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send headers.
        if ($status === 206) {
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$file_size}");
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . $length);
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: bytes');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        // Open file and stream in 8 KB chunks.
        $handle = fopen($real_path, 'rb');

        if ($handle === false) {
            wp_die(__('Cannot read backup file.', 'sitessaver'));
        }

        if ($start > 0) {
            fseek($handle, $start);
        }

        $chunk_size = 8192; // 8 KB
        $remaining  = $length;

        while ($remaining > 0 && !feof($handle)) {
            if (connection_aborted()) {
                break;
            }

            $read_size = min($chunk_size, $remaining);
            $buffer    = fread($handle, $read_size);

            if ($buffer === false) {
                break;
            }

            echo $buffer;
            flush();

            $remaining -= strlen($buffer);
        }

        fclose($handle);
        exit;
    }

    /**
     * Add/update label on a backup.
     */
    public function handle_label(): void {
        sitessaver_verify_ajax();

        $file  = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));

        if (empty($file)) {
            wp_send_json_error(['message' => __('No file specified.', 'sitessaver')]);
        }

        $labels = get_option('sitessaver_backup_labels', []);
        $labels[$file] = $label;
        update_option('sitessaver_backup_labels', $labels, false);

        wp_send_json_success(['message' => __('Label saved.', 'sitessaver')]);
    }

    /**
     * Save schedule settings.
     */
    public function handle_save_schedule(): void {
        sitessaver_verify_ajax();

        $schedule = [
            'enabled'   => (bool) ($_POST['enabled'] ?? false),
            'frequency' => sanitize_text_field(wp_unslash($_POST['frequency'] ?? 'daily')),
            'retention' => (int) ($_POST['retention'] ?? 5),
            'include_db'      => (bool) ($_POST['include_db'] ?? true),
            'include_media'   => (bool) ($_POST['include_media'] ?? true),
            'include_plugins' => (bool) ($_POST['include_plugins'] ?? true),
            'include_themes'  => (bool) ($_POST['include_themes'] ?? true),
            'storage_local'   => (bool) ($_POST['storage_local'] ?? true),
            'storage_gdrive'  => (bool) ($_POST['storage_gdrive'] ?? false),
            'notify_email'    => sanitize_email(wp_unslash($_POST['notify_email'] ?? '')),
        ];

        update_option('sitessaver_schedule', $schedule, false);

        // Update WP Cron.
        wp_clear_scheduled_hook('sitessaver_scheduled_backup');

        if ($schedule['enabled']) {
            $valid = ['hourly', 'twicedaily', 'daily', 'weekly'];
            $freq  = in_array($schedule['frequency'], $valid, true) ? $schedule['frequency'] : 'daily';

            // Delay the first run by one full interval so saving the schedule
            // does NOT immediately trigger a backup on the next page view.
            $intervals = [
                'hourly'     => HOUR_IN_SECONDS,
                'twicedaily' => 12 * HOUR_IN_SECONDS,
                'daily'      => DAY_IN_SECONDS,
                'weekly'     => WEEK_IN_SECONDS,
            ];
            $first_run = time() + ($intervals[$freq] ?? DAY_IN_SECONDS);

            wp_schedule_event($first_run, $freq, 'sitessaver_scheduled_backup');
        }

        wp_send_json_success(['message' => __('Schedule saved.', 'sitessaver')]);
    }

    /**
     * Save general settings.
     */
    public function handle_save_settings(): void {
        sitessaver_verify_ajax();

        $settings = get_option('sitessaver_settings', []);
        $settings['gdrive_folder_id'] = sanitize_text_field(wp_unslash($_POST['gdrive_folder_id'] ?? ''));

        update_option('sitessaver_settings', $settings, false);

        wp_send_json_success(['message' => __('Settings saved.', 'sitessaver')]);
    }

    /**
     * Disconnect Google Drive.
     */
    public function handle_gdrive_disconnect(): void {
        sitessaver_verify_ajax();

        GDrive::disconnect();

        wp_send_json_success(['message' => __('Google Drive disconnected.', 'sitessaver')]);
    }

    /**
     * Upload backup to Google Drive.
     */
    public function handle_gdrive_upload(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);

        $job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        $file   = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));
        $path   = sitessaver_resolve_backup_path($file);

        if ($path === null) {
            wp_send_json_error(['message' => __('Backup not found.', 'sitessaver')]);
        }

        $result = GDrive::upload($path, $file, $job_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Get Google Drive upload progress status.
     */
    public function handle_get_gdrive_upload_status(): void {
        sitessaver_verify_ajax();

        $job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        if (empty($job_id)) {
            wp_send_json_error(['message' => __('Missing Job ID.', 'sitessaver')]);
        }

        $status = get_transient('sitessaver_gdrive_job_' . $job_id);
        if (!$status) {
            wp_send_json_success(['progress' => 0, 'status' => 'waiting']);
        } else {
            wp_send_json_success($status);
        }
    }

    /**
     * List backups on Google Drive.
     */
    public function handle_gdrive_list(): void {
        sitessaver_verify_ajax();

        $result = GDrive::list_files();
        wp_send_json_success($result);
    }

    /**
     * Download backup from Google Drive.
     */
    public function handle_gdrive_download(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);

        $file_id = sanitize_text_field(wp_unslash($_POST['file_id'] ?? ''));

        if (empty($file_id)) {
            wp_send_json_error(['message' => __('No file specified.', 'sitessaver')]);
        }

        $result = GDrive::download($file_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Download a Drive backup AND immediately restore it. The downloaded
     * ZIP is persisted to the local storage dir so it shows up in the
     * Backups list too — same behaviour as manual download + restore,
     * just one click.
     */
    public function handle_gdrive_restore(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $file_id = sanitize_text_field(wp_unslash($_POST['file_id'] ?? ''));

        if (empty($file_id)) {
            wp_send_json_error(['message' => __('No file specified.', 'sitessaver')]);
        }

        // Step 1: download from Drive to local storage.
        $dl = GDrive::download($file_id);
        if (empty($dl['success']) || empty($dl['file'])) {
            wp_send_json_error(['message' => $dl['message'] ?? __('Failed to download from Google Drive.', 'sitessaver')]);
        }

        // Step 2: restore from the downloaded file.
        $restore = Import::from_backup($dl['file']);

        if ($restore['success']) {
            wp_send_json_success($restore);
        } else {
            wp_send_json_error($restore);
        }
    }

    /**
     * Delete backup from Google Drive.
     */
    public function handle_gdrive_delete(): void {
        sitessaver_verify_ajax();

        $file_id = sanitize_text_field(wp_unslash($_POST['file_id'] ?? ''));

        if (empty($file_id)) {
            wp_send_json_error(['message' => __('No file specified.', 'sitessaver')]);
        }

        $result = GDrive::delete($file_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     *
     * Receives: chunk (file blob), chunk_index, total_chunks, filename, upload_id.
     * On the final chunk, assembles all chunks into a single ZIP in the storage dir.
     */

    public function handle_upload_chunk(): void {
        sitessaver_verify_ajax();

        // Validate upload_id — alphanumeric only, 8-32 chars.
        $upload_id = sanitize_key(wp_unslash($_POST['upload_id'] ?? ''));
        if (empty($upload_id) || !preg_match('/^[a-z0-9]{8,32}$/', $upload_id)) {
            wp_send_json_error(['message' => __('Invalid upload ID.', 'sitessaver')]);
        }

        $chunk_index  = (int) ($_POST['chunk_index'] ?? -1);
        $total_chunks = (int) ($_POST['total_chunks'] ?? 0);
        $filename     = sanitize_file_name(wp_unslash($_POST['filename'] ?? ''));

        // Basic validation.
        if ($total_chunks < 1 || $total_chunks > 10000) {
            wp_send_json_error(['message' => __('Invalid chunk count.', 'sitessaver')]);
        }

        if ($chunk_index < 0 || $chunk_index >= $total_chunks) {
            wp_send_json_error(['message' => __('Invalid chunk index.', 'sitessaver')]);
        }

        if (empty($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            wp_send_json_error(['message' => __('Only ZIP files are accepted.', 'sitessaver')]);
        }

        if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Chunk upload failed.', 'sitessaver')]);
        }

        // Create chunk directory.
        $chunk_dir = SITESSAVER_TEMP_DIR . '/chunks/' . $upload_id;
        if (!is_dir($chunk_dir)) {
            wp_mkdir_p($chunk_dir);
        }

        // Save this chunk with zero-padded index for correct ordering.
        $chunk_path = $chunk_dir . '/chunk_' . str_pad((string) $chunk_index, 5, '0', STR_PAD_LEFT);
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
            wp_send_json_error(['message' => __('Failed to save chunk.', 'sitessaver')]);
        }

        // If this is the last chunk, assemble them.
        if ($chunk_index === $total_chunks - 1) {
            $assembled = $this->assemble_chunks($chunk_dir, $total_chunks, $filename);

            if ($assembled === null) {
                wp_send_json_error(['message' => __('Failed to assemble uploaded file.', 'sitessaver')]);
            }

            wp_send_json_success([
                'chunk_index'    => $chunk_index,
                'assembled'      => true,
                'assembled_file' => basename($assembled),
                'message'        => __('Upload complete.', 'sitessaver'),
            ]);
        }

        wp_send_json_success([
            'chunk_index' => $chunk_index,
            'assembled'   => false,
        ]);
    }

    /**
     * Assemble chunk files into a single ZIP in the storage directory.
     *
     * @return string|null Full path to assembled file, or null on failure.
     */
    private function assemble_chunks(string $chunk_dir, int $total_chunks, string $filename): ?string {
        $dest = SITESSAVER_STORAGE_DIR . '/' . sanitize_file_name($filename);

        // Avoid overwriting — add suffix if file exists.
        if (file_exists($dest)) {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $dest = SITESSAVER_STORAGE_DIR . '/' . $base . '-' . wp_generate_password(4, false) . '.zip';
        }

        $out = fopen($dest, 'wb');
        if (!$out) {
            $this->remove_chunk_dir($chunk_dir);
            return null;
        }

        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_path = $chunk_dir . '/chunk_' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);

            if (!file_exists($chunk_path)) {
                fclose($out);
                @unlink($dest);
                $this->remove_chunk_dir($chunk_dir);
                return null;
            }

            $in = fopen($chunk_path, 'rb');
            if (!$in) {
                fclose($out);
                @unlink($dest);
                $this->remove_chunk_dir($chunk_dir);
                return null;
            }

            while (!feof($in)) {
                fwrite($out, fread($in, 8192));
            }

            fclose($in);
        }

        fclose($out);

        // Validate assembled file is actually a ZIP (PK magic bytes).
        $handle = fopen($dest, 'rb');
        if ($handle) {
            $header = fread($handle, 4);
            fclose($handle);

            // PK\x03\x04 = local file header, PK\x05\x06 = empty archive.
            if ($header !== "PK\x03\x04" && $header !== "PK\x05\x06") {
                @unlink($dest);
                $this->remove_chunk_dir($chunk_dir);
                return null;
            }
        }

        // Clean up chunk directory.
        $this->remove_chunk_dir($chunk_dir);

        return $dest;
    }

    /**
     * Remove a chunk directory and all its contents.
     */
    private function remove_chunk_dir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * Clean up orphaned chunks for a specific upload ID (called on client-side error).
     */
    public function handle_cleanup_chunks(): void {
        sitessaver_verify_ajax();

        $upload_id = sanitize_key(wp_unslash($_POST['upload_id'] ?? ''));
        if (empty($upload_id) || !preg_match('/^[a-z0-9]{8,32}$/', $upload_id)) {
            wp_send_json_error(['message' => __('Invalid upload ID.', 'sitessaver')]);
        }

        $chunk_dir = SITESSAVER_TEMP_DIR . '/chunks/' . $upload_id;
        $this->remove_chunk_dir($chunk_dir);

        wp_send_json_success(['message' => __('Chunks cleaned up.', 'sitessaver')]);
    }
}
