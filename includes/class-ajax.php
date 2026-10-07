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
            'sitessaver_export_work'    => 'handle_export_work',
            'sitessaver_resume_export'  => 'handle_resume_export',
            'sitessaver_get_export_status' => 'handle_get_export_status',
            'sitessaver_cancel_export'     => 'handle_cancel_export',
            'sitessaver_import'         => 'handle_import',
            'sitessaver_get_import_status' => 'handle_get_import_status',
            'sitessaver_import_upload'  => 'handle_import_upload',
            'sitessaver_delete_backup'  => 'handle_delete',
            'sitessaver_download_backup'=> 'handle_download',
            'sitessaver_add_label'      => 'handle_label',
            'sitessaver_save_schedule'  => 'handle_save_schedule',
            'sitessaver_regenerate_cron_key' => 'handle_regenerate_cron_key',
            'sitessaver_run_schedule_now'    => 'handle_run_schedule_now',
            'sitessaver_save_settings'  => 'handle_save_settings',
            'sitessaver_save_email_brand' => 'handle_save_email_brand',
            'sitessaver_send_test_email'  => 'handle_send_test_email',
            'sitessaver_check_update'     => 'handle_check_update',
            'sitessaver_dismiss_update_notice' => 'handle_dismiss_update_notice',
            'sitessaver_gdrive_disconnect' => 'handle_gdrive_disconnect',
            'sitessaver_gdrive_upload'  => 'handle_gdrive_upload',
            'sitessaver_get_gdrive_upload_status' => 'handle_get_gdrive_upload_status',
            'sitessaver_gdrive_list'    => 'handle_gdrive_list',
            'sitessaver_gdrive_download'=> 'handle_gdrive_download',
            'sitessaver_gdrive_restore' => 'handle_gdrive_restore',
            'sitessaver_gdrive_delete'  => 'handle_gdrive_delete',
            'sitessaver_upload_chunk'   => 'handle_upload_chunk',
            'sitessaver_upload_status'  => 'handle_upload_status',
            'sitessaver_cleanup_chunks' => 'handle_cleanup_chunks',
            'sitessaver_finalize_restore' => 'handle_finalize_restore',
            'sitessaver_restore_start'  => 'handle_restore_start',
            'sitessaver_restore_status' => 'handle_restore_status',
            'sitessaver_restore_run'    => 'handle_restore_run',
            'sitessaver_restore_worker' => 'handle_restore_worker',
            'sitessaver_client_error'   => 'handle_client_error',
            'sitessaver_log_download'   => 'handle_log_download',
            'sitessaver_log_clear'      => 'handle_log_clear',
        ];



        foreach ($actions as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
        }

        // The background worker is reached by a detached loopback request that
        // carries no auth cookie, so it can only ever arrive as "logged out".
        // Registering it for wp_ajax_ alone made every spawn 400 and silently
        // fall back to browser-driven steps — the exact thing the worker
        // exists to avoid. Authorization is the single-use key checked inside
        // handle_export_work(), not the session.
        add_action('wp_ajax_nopriv_sitessaver_export_work', [$this, 'handle_export_work']);

        // Same for the restore worker. The restore STATUS poll is also open
        // to logged-out requests, because halfway through a restore the
        // database (and with it the user's login session) is replaced — the
        // browser can no longer prove who it is with a cookie or nonce. It
        // proves possession of the job's own random token instead.
        add_action('wp_ajax_nopriv_sitessaver_restore_worker', [$this, 'handle_restore_worker']);
        add_action('wp_ajax_nopriv_sitessaver_restore_status', [$this, 'handle_restore_status']);
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
        // Step weights differ when the backup is also uploaded to Drive, so the
        // table has to be built for THIS export's destination.
        $steps  = Export::get_steps($destination);

        // Hand the actual work to a detached background request and answer the
        // browser immediately. Running the steps inline is what produced the
        // bogus "An error occurred": a big site blows past the gateway's
        // request timeout, nginx replies 504 with an HTML body, and the client
        // — expecting JSON — falls back to a generic error while PHP is still
        // happily working. The browser now only ever polls for status, so no
        // single request has to outlive the gateway's patience.
        $spawned = $this->spawn_export_worker($status['uid']);

        wp_send_json_success([
            'status'        => $status,
            'steps'         => $steps,
            'gdrive_job_id' => Export::gdrive_job_id($status['uid']),
            // False means the loopback request could not be made (some hosts
            // block self-requests); the client then drives the steps itself.
            'background'    => $spawned,
        ]);
    }

    /**
     * Fire a non-blocking loopback request that runs the export to completion.
     *
     * `blocking => false` makes WP write the request and return without
     * reading the response, so this call costs milliseconds regardless of how
     * long the backup takes. The worker authenticates with a single-use key
     * rather than the user's cookies, because the detached request carries no
     * session.
     */
    private function spawn_export_worker(string $uid): bool {
        return Export::spawn_worker($uid);
    }

    /**
     * Background worker: runs every remaining export step in this request.
     *
     * No nonce and no capability check — a detached loopback request has no
     * cookies, so neither would pass. Authorization is the single-use key
     * handed out by handle_export() and stored server-side; it is deleted the
     * moment it is used, so the endpoint cannot be replayed.
     */
    public function handle_export_work(): void {
        $uid = sanitize_text_field(wp_unslash($_POST['uid'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));

        $expected = get_transient('sitessaver_worker_' . $uid);
        if ($uid === '' || !is_string($expected) || !hash_equals($expected, $key)) {
            wp_send_json_error(['message' => __('Invalid worker key.', 'sitessaver')], 403);
        }

        delete_transient('sitessaver_worker_' . $uid);

        // The browser has taken this export over (background requests kept
        // dying on this host). A late worker must not race it.
        $current = Export::get_status($uid);
        if (($current['driver'] ?? '') === 'browser') {
            wp_send_json_success(['message' => 'browser-driven']);
        }

        // The caller already hung up. Keep running anyway, and do not let a
        // half-written response abort the backup.
        @ignore_user_abort(true);
        @set_time_limit(0);

        $result = Export::work($uid);

        // The slice is used up: hand over to a fresh request before this one
        // gets anywhere near the host's time limit.
        if (!empty($result['continue'])) {
            $this->spawn_export_worker($uid);
        }

        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? '']);
        }

        wp_send_json_success(['message' => 'ok']);
    }

    /**
     * Run a single step of the export.
     */
    public function handle_export_step(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $uid    = sanitize_text_field(wp_unslash($_POST['uid'] ?? ''));
        $status = Export::get_status($uid);

        // `auto`: the browser has taken over from a background chain and asks
        // the server which step is next, rather than counting steps itself.
        $index = !empty($_POST['auto'])
            ? (int) ($status['step_index'] ?? 0)
            : (int) ($_POST['step_index'] ?? 0);

        // This request now owns the export, and works in a slice short enough
        // to return before PHP's or the gateway's time limit.
        Export::claim_for_browser($uid);
        Export::begin_ticks($uid);
        Export::begin_slice(Export::browser_budget());
        try {
            $result = Export::run_step($uid, $index);
        } finally {
            Export::end_slice();
            Export::end_ticks();
        }

        $after           = Export::get_status($uid);
        $result['state'] = (string) ($after['status'] ?? 'gone');

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Resume an interrupted export by spawning a fresh background worker.
     *
     * Used by the "Resume" banner. The worker reads the persisted step_index
     * (and, mid-upload, the Drive byte offset), so it continues rather than
     * starting over.
     */
    public function handle_resume_export(): void {
        sitessaver_verify_ajax();

        $uid = sanitize_text_field(wp_unslash($_POST['uid'] ?? ''));
        if ($uid === '') {
            $uid = (string) (get_transient('sitessaver_active_export_id') ?: '');
        }

        $status = $uid !== '' ? Export::get_status($uid) : [];
        if (empty($status) || ($status['status'] ?? '') !== 'running') {
            wp_send_json_error(['message' => __('No active export found.', 'sitessaver')]);
        }

        wp_send_json_success([
            'background' => $this->spawn_export_worker($uid),
            'uid'        => $uid,
        ]);
    }

    /**
     * Get the current status of the active export.
     */
    public function handle_get_export_status(): void {
        sitessaver_verify_ajax();

        $uid    = sanitize_text_field((string) ($_POST['uid'] ?? get_transient('sitessaver_active_export_id') ?? ''));
        $status = $uid !== '' ? Export::get_status($uid) : [];

        if (empty($status)) {
            wp_send_json_error(['message' => __('No active export found.', 'sitessaver')]);
        }

        // An Export tab is watching: it sees the result itself, no email.
        Background::mark_watched($uid);

        // Rebuild the step table for the destination this export was started
        // with. Resuming an orphaned export used to fall back to the default
        // local table, which mislabelled the steps of a Drive export.
        $destination = (string) ($status['options']['export_destination'] ?? 'local');
        $steps       = Export::get_steps($destination);

        // Everything the client needs to render progress without running the
        // steps itself: which step the worker is on, how long since it last
        // reported in, and whether it looks dead.
        $index   = (int) ($status['step_index'] ?? 0);
        $current = $steps[$index] ?? null;
        $since   = time() - (int) ($status['last_update'] ?? $status['start_time'] ?? time());

        // Most phases use the normal stall threshold. A handful of phases
        // are a single uninterruptible blocking call with no way to tick
        // from inside them (currently: ZipArchive::close(), tagged
        // 'zip-finalizing' by Archive::create()) — those get a much longer
        // grace period instead of being flagged dead mid-compress on a
        // large backup. See Export::STALL_SECONDS_FINALIZING's docblock.
        $phase_note      = (string) ($status['detail']['note'] ?? '');
        $stall_threshold = $phase_note === 'zip-finalizing'
            ? Export::STALL_SECONDS_FINALIZING
            : Export::STALL_SECONDS;

        $running  = ($status['status'] ?? '') === 'running';
        $driver   = (string) ($status['driver'] ?? 'worker');
        $takeover = $running && $driver === 'browser';

        // A background chain hands over every slice and checkpoints every
        // few seconds, so a minute of silence means the host stopped the
        // worker. Start a new one with a shorter slice (it continues from the
        // saved cursor); if that keeps failing, this host does not let
        // background requests live, and the open browser tab runs the slices
        // itself instead of the export failing.
        if ($running && $driver !== 'browser' && $phase_note !== 'zip-finalizing' && $since > Export::RESPAWN_SECONDS) {
            $diag = Export::diagnostics($status);
            // No worker ever started: this host does not deliver loopback
            // requests at all, so a second one would not arrive either.
            $never = empty($status['workers']);
            if (!$never && (int) ($status['resumes'] ?? 0) < Export::AUTO_RESUMES) {
                $status['resumes']     = (int) ($status['resumes'] ?? 0) + 1;
                $status['slice']       = max(5, intdiv(Export::slice_budget($status), 2));
                $status['last_update'] = time();
                Export::save_status_public($uid, $status);
                Log::warning('export_auto_resumed', sprintf('No progress for %ds during step "%s"; started a new worker with a %ds slice (attempt %d of %d).', $since, $current['id'] ?? $index, $status['slice'], $status['resumes'], Export::AUTO_RESUMES), $diag);
                $this->spawn_export_worker($uid);
            } else {
                $status['driver']      = 'browser';
                $status['worker']      = 'browser';
                $status['last_update'] = time();
                Export::save_status_public($uid, $status);
                Log::warning('export_browser_takeover', sprintf($never
                    ? 'Background requests never started on this host (step "%s"); the browser continues the export.'
                    : 'Background workers keep stopping on this host (step "%s"); the browser continues the export.', $current['id'] ?? $index), $diag);
                $takeover = true;
            }
            $since = 0;
        }

        $stalled = $running && $since > $stall_threshold;

        // Log a stall once (the browser keeps polling) and give the user a
        // plain-language message with a reference code.
        $error = null;
        if ($stalled) {
            $ref = get_transient('sitessaver_export_stall_ref_' . $uid);
            if (!is_string($ref) || $ref === '') {
                $ref = Log::error('export_stalled', sprintf('No progress for %ds during step "%s".', $since, $current['id'] ?? $index), Export::diagnostics($status));
                set_transient('sitessaver_export_stall_ref_' . $uid, $ref, DAY_IN_SECONDS);
            }
            $detail = is_array($status['detail'] ?? null) ? $status['detail'] : [];
            if (!empty($detail['file']) && isset($detail['size'])) {
                $error = Errors::payload('export_stalled_file', sprintf(
                    'No progress for %ds while copying %s (%s, %s copied).',
                    $since,
                    $detail['file'],
                    size_format((int) $detail['size']),
                    size_format((int) ($detail['copied'] ?? 0))
                ), $ref);
            } else {
                $error = Errors::payload('export_stalled', sprintf('No progress for %ds during "%s".', $since, $current['label'] ?? ''), $ref);
            }
        } elseif (($status['status'] ?? '') === 'error') {
            $error = Errors::payload((string) ($status['error_code'] ?? 'export_failed'), (string) ($status['message'] ?? ''), $status['ref'] ?? null);
        }

        wp_send_json_success([
            'status'        => $status,
            'steps'         => $steps,
            'gdrive_job_id' => Export::gdrive_job_id($uid),
            'step_index'    => $index,
            'step_id'       => $current['id'] ?? '',
            'step_label'    => $current['label'] ?? '',
            'step_pct'      => (int) ($current['pct'] ?? 0),
            'step_from'     => (int) ($current['from'] ?? 0),
            'poll'          => $current['poll'] ?? '',
            'detail'        => $status['detail'] ?? null,
            'takeover'      => $takeover,
            'seconds_since_update' => $since,
            // The worker refreshes last_update at least every 10s from inside
            // long loops, so a long silence means the process is gone (OOM,
            // host kill) rather than merely busy — EXCEPT during a tagged
            // "finalizing" phase, which uses the extended threshold above.
            'stalled'       => $stalled,
            'error'         => $error,
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
            if (!empty($status)) {
                Export::discard_work($status);
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
            // Post-restore authorization token. The browser's cookie was
            // issued against the PRE-restore DB; after restore the new user
            // table + auth salts may not recognise it, so the finalize AJAX
            // call can't rely on the standard nonce/capability check. Hand
            // the client this short-lived token to present on finalize.
            $result['finalize_token'] = Import::current_finalize_token();
            $result['finalize_url']   = Import::build_finalize_redirect_url();
            wp_send_json_success($result);
        } else {
            wp_send_json_error($this->restore_failure_payload($result));
        }
    }

    /**
     * Turn a failed Import result into the plain-language error payload,
     * logging the technical message under a reference code.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function restore_failure_payload(array $result): array {
        $technical = (string) ($result['message'] ?? '');
        $phase     = (string) (Import::get_progress()['phase'] ?? '');
        return Errors::report(Errors::classify_restore($technical, $phase), $technical, ['phase' => $phase, 'mode' => 'legacy']);
    }

    /**
     * Status poll for a restore in progress.
     *
     * The restore itself runs inside ONE blocking sitessaver_import request
     * (see handle_import() — it can't be safely backgrounded, the browser
     * holds the admin session that authorized it) but Import::tick() writes
     * a transient at each phase boundary, which THIS separate concurrent
     * request can read while that blocking call is still in flight — so the
     * step checklist can show real progress instead of one static spinner.
     */
    public function handle_get_import_status(): void {
        sitessaver_verify_ajax();

        wp_send_json_success(['progress' => Import::get_progress()]);
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
            $result['finalize_token'] = Import::current_finalize_token();
            $result['finalize_url']   = Import::build_finalize_redirect_url();
            wp_send_json_success($result);
        } else {
            wp_send_json_error($this->restore_failure_payload($result));
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
     * Backward-compat AJAX stub. The redirect-based finalize flow (added in
     * 1.1.6) means this endpoint is no longer called by current JS — the
     * client navigates straight to wp-login.php and the deferred work runs
     * in Admin::handle_post_import_finalisation under a clean session.
     *
     * We keep the endpoint registered so that any in-flight browser tab
     * still on cached 1.1.5 JS can complete gracefully: we simply return the
     * redirect URL and let the client navigate there.
     */
    public function handle_finalize_restore(): void {
        // SECURITY: returning this URL unconditionally leaked the embedded
        // one-time finalize token, which is what authorises the deferred work
        // (activating the backup's plugin set, switching its theme) on the
        // landing page. A plain subscriber could read it straight out of this
        // JSON body — verified with a subscriber session.
        //
        // A nonce alone is not sufficient here: after a restore the browser's
        // cookie was minted against the PRE-restore DB, so the legitimate user
        // can fail a capability check through no fault of their own. We accept
        // EITHER a real admin session OR possession of the token itself, which
        // the client already received in the import response.
        $supplied  = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $expected  = Import::current_finalize_token();
        $has_token = $expected !== '' && $supplied !== '' && hash_equals($expected, $supplied);

        if (!$has_token && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'sitessaver')], 403);
            return;
        }

        wp_send_json_success([
            'redirect' => Import::build_finalize_redirect_url(),
            'message'  => __('Please log in with your restored credentials to complete the restore.', 'sitessaver'),
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

        // Forget the chain membership and cached index too. Leaving them
        // behind would let plan() pick a parent whose ZIP no longer exists,
        // producing an incremental that can never be restored.
        \SitesSaver\Index::drop($file);

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

        // The form posts frequencies[] so several cadences can run together
        // (e.g. Daily for recent restore points plus Monthly as an archive).
        $posted = $_POST['frequencies'] ?? [];
        if (is_string($posted)) {
            // Tolerate a single scalar, which is what a legacy client or a
            // hand-written request is most likely to send.
            $posted = [$posted];
        }

        // Only accept keys we actually offer, plus the 'monthly' alias.
        // normalize_frequency() maps anything unknown to 'daily', so filtering
        // BEFORE normalising is what stops a typo becoming an unrequested
        // daily backup.
        $allowed = array_keys(Schedule::frequencies());
        $allowed[] = 'monthly';

        $frequencies = [];
        if (is_array($posted)) {
            foreach ($posted as $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $key = sanitize_text_field(wp_unslash($value));
                if (in_array($key, $allowed, true)) {
                    $frequencies[] = Schedule::normalize_frequency($key);
                }
            }
        }

        $frequencies = array_values(array_unique($frequencies));

        // A schedule with nothing selected would be enabled but silent, which
        // is worse than telling the user outright.
        if (!empty($_POST['enabled']) && $frequencies === []) {
            wp_send_json_error([
                'message' => __('Choose at least one backup frequency.', 'sitessaver'),
            ]);
        }

        // Preserve the canonical ordering used everywhere else.
        $frequencies = Schedule::selected_frequencies(['frequencies' => $frequencies]);

        // Clamp rather than trust: the form caps retention at 100, but a
        // hand-crafted POST could otherwise store 0 (delete everything on the
        // next run) or a negative value.
        $retention = (int) ($_POST['retention'] ?? 5);
        $retention = max(1, min(100, $retention));

        $schedule = [
            'enabled'     => (bool) ($_POST['enabled'] ?? false),
            'frequencies' => $frequencies,
            // Kept in sync for anything still reading the old single-value
            // key (and so downgrading the plugin does not lose the setting).
            'frequency'   => $frequencies[0] ?? 'daily',
            'retention'   => $retention,
            // Backup mode. Anything that isn't an explicit 'incremental'
            // means full — an unrecognised value must never silently put a
            // site onto chained backups it did not ask for.
            'backup_mode'      => ($_POST['backup_mode'] ?? 'full') === 'incremental' ? 'incremental' : 'full',
            'full_every'       => max(2, min(60, (int) ($_POST['full_every'] ?? \SitesSaver\Index::DEFAULT_FULL_EVERY))),
            'change_detection' => ($_POST['change_detection'] ?? 'fast') === 'thorough' ? 'thorough' : 'fast',
            'include_db'      => (bool) ($_POST['include_db'] ?? true),
            'include_media'   => (bool) ($_POST['include_media'] ?? true),
            'include_plugins' => (bool) ($_POST['include_plugins'] ?? true),
            'include_themes'  => (bool) ($_POST['include_themes'] ?? true),
            'storage_local'   => (bool) ($_POST['storage_local'] ?? true),
            'storage_gdrive'  => (bool) ($_POST['storage_gdrive'] ?? false),
            'notify_email'    => sanitize_email(wp_unslash($_POST['notify_email'] ?? '')),
        ];

        update_option('sitessaver_schedule', $schedule, false);

        // Rebuild the cron events to match the new selection exactly.
        Schedule::sync_cron_events($frequencies, $schedule['enabled']);

        if ($schedule['enabled']) {
            // Seed the last-run markers so the external trigger endpoint
            // agrees with WP-Cron about when the first backup is owed.
            // Without this a server cron would fire a backup immediately
            // after saving, since "never run before" reads as due.
            Schedule::seed_last_runs($frequencies);
        }

        wp_send_json_success([
            'message'  => __('Schedule saved.', 'sitessaver'),
            'next_run' => $schedule['enabled'] ? Schedule::next_scheduled_run($frequencies) : 0,
        ]);
    }

    /**
     * Issue a fresh external-trigger key, invalidating the old URL.
     */
    public function handle_regenerate_cron_key(): void {
        sitessaver_verify_ajax();

        Schedule::regenerate_trigger_key();

        wp_send_json_success([
            'message' => __('New trigger URL generated. Update your server cron with the new URL.', 'sitessaver'),
            'url'     => Schedule::trigger_url(),
        ]);
    }

    /**
     * Run the scheduled backup immediately, using the saved schedule settings.
     *
     * Lets the user prove the schedule works without waiting for the next
     * cron window — the most common support question about scheduled backups
     * is "is this actually configured correctly?".
     */
    public function handle_run_schedule_now(): void {
        sitessaver_verify_ajax();

        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $settings = get_option('sitessaver_schedule', []);

        if (empty($settings['enabled'])) {
            wp_send_json_error([
                'message' => __('Enable scheduled backups and save before running a test backup.', 'sitessaver'),
            ]);
        }

        $result = Schedule::instance()->run_scheduled_backup();

        if ($result === null) {
            wp_send_json_error([
                'message' => __('A scheduled backup is already running. Try again once it finishes.', 'sitessaver'),
            ]);
        }

        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => $result['message'] ?? __('Backup failed.', 'sitessaver'),
            ]);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: backup filename, 2: human-readable file size */
                __('Test backup created: %1$s (%2$s)', 'sitessaver'),
                (string) ($result['file'] ?? ''),
                (string) ($result['size'] ?? '')
            ),
        ]);
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
     * Save the email branding fields used by notification emails.
     */
    public function handle_save_email_brand(): void {
        sitessaver_verify_ajax();

        $accent = strtoupper(sanitize_text_field(wp_unslash($_POST['accent'] ?? '')));
        if (!preg_match('/^#(?:[0-9A-F]{3}|[0-9A-F]{6})$/', $accent)) {
            // An invalid colour would leak into every inline style in the
            // template, so refuse rather than render a broken email.
            $accent = '#2271B1';
        }

        $from_email = sanitize_email(wp_unslash($_POST['from_email'] ?? ''));
        if ($from_email !== '' && !is_email($from_email)) {
            wp_send_json_error(['message' => __('That From address is not a valid email.', 'sitessaver')]);
        }

        $brand = [
            'enabled'     => empty($_POST['enabled']) ? '0' : '1',
            'logo_url'    => esc_url_raw(wp_unslash($_POST['logo_url'] ?? '')),
            'accent'      => $accent,
            'from_name'   => sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')),
            'from_email'  => $from_email,
            'footer_note' => sanitize_textarea_field(wp_unslash($_POST['footer_note'] ?? '')),
            'support_url' => esc_url_raw(wp_unslash($_POST['support_url'] ?? '')),
        ];

        update_option(Mailer::BRAND_OPTION, $brand, false);

        wp_send_json_success(['message' => __('Email branding saved.', 'sitessaver')]);
    }

    /**
     * Send a sample notification so the user can see their branding for real.
     *
     * Uses the same renderer as a live report with representative data, so
     * what lands in the inbox is exactly what a real backup would produce.
     */
    public function handle_send_test_email(): void {
        sitessaver_verify_ajax();

        $to = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if ($to === '' || !is_email($to)) {
            $to = (string) get_option('admin_email');
        }

        if (!is_email($to)) {
            wp_send_json_error(['message' => __('No valid recipient address available.', 'sitessaver')]);
        }

        $site_name = get_bloginfo('name');
        $next      = Schedule::next_scheduled_run();

        $data = [
            'success' => true,
            'title'   => __('Backup completed', 'sitessaver'),
            'intro'   => sprintf(
                /* translators: %s: site name */
                __('This is a preview of the backup report for %s. No backup was actually run.', 'sitessaver'),
                $site_name
            ),
            'rows' => array_values(array_filter([
                [__('Site', 'sitessaver'), $site_name],
                [__('File', 'sitessaver'), sitessaver_backup_filename()],
                [__('Size', 'sitessaver'), '351.15 MB'],
                [__('Completed', 'sitessaver'), current_time('mysql')],
                [__('Includes', 'sitessaver'), __('database, media, plugins, themes', 'sitessaver')],
                $next > 0 ? [__('Next backup', 'sitessaver'), wp_date('j M Y, g:i a', $next)] : null,
            ])),
            'storage' => [
                [
                    'state'  => 'ok',
                    'label'  => __('This server', 'sitessaver'),
                    'detail' => __('Stored in the SitesSaver backups folder on your hosting account.', 'sitessaver'),
                ],
                [
                    'state'  => GDrive::is_connected() ? 'ok' : 'muted',
                    'label'  => __('Google Drive', 'sitessaver'),
                    'detail' => GDrive::is_connected()
                        ? sprintf(
                            /* translators: %s: site name */
                            __('Uploaded to the "SitesSaver Backups (%s)" folder in your Drive.', 'sitessaver'),
                            $site_name
                        )
                        : __('Not connected. Connect Google Drive to keep a copy off this server.', 'sitessaver'),
                    'url' => GDrive::is_connected() ? GDrive::get_folder_url() : '',
                ],
            ],
            'notes' => [
                __('This is a test message sent from the SitesSaver settings page.', 'sitessaver'),
                __('Keep at least one copy away from this server. A backup that only lives on the same host is lost with the host.', 'sitessaver'),
            ],
            'actions' => [
                [
                    'label'   => __('View backups', 'sitessaver'),
                    'url'     => admin_url('admin.php?page=sitessaver'),
                    'primary' => true,
                ],
                [
                    'label' => __('Schedule settings', 'sitessaver'),
                    'url'   => admin_url('admin.php?page=sitessaver-schedule'),
                ],
            ],
        ];

        $sent = Mailer::send(
            $to,
            sprintf(
                /* translators: %s: site name */
                __('[%s] Test backup notification', 'sitessaver'),
                $site_name
            ),
            Mailer::render_html($data),
            Mailer::render_text($data)
        );

        if (!$sent) {
            wp_send_json_error([
                'message' => __('WordPress could not send the email. Check your SMTP or mail plugin configuration.', 'sitessaver'),
            ]);
        }

        wp_send_json_success([
            /* translators: %s: recipient email address */
            'message' => sprintf(__('Test email sent to %s.', 'sitessaver'), $to),
        ]);
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
     * Force a fresh GitHub release lookup and report the outcome.
     */
    public function handle_check_update(): void {
        sitessaver_verify_ajax();

        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('You do not have permission to check for updates.', 'sitessaver')]);
        }

        Updater::instance()->clear_cache();
        $release = Updater::latest_release(true);

        if ($release === null) {
            $error = Updater::last_error();
            wp_send_json_error([
                'message' => $error !== ''
                    ? $error
                    : __('No releases found in that repository.', 'sitessaver'),
            ]);
        }

        if (!Updater::is_newer($release['version'])) {
            wp_send_json_success([
                'update'  => false,
                'version' => $release['version'],
                'message' => sprintf(
                    /* translators: %s: version number */
                    __('You are running the latest version (%s).', 'sitessaver'),
                    SITESSAVER_VERSION
                ),
            ]);
        }

        // Make WordPress re-evaluate so the Plugins screen row appears without
        // waiting for its own twice-daily cron.
        delete_site_transient('update_plugins');

        wp_send_json_success([
            'update'  => true,
            'version' => $release['version'],
            'url'     => $release['url'],
            'message' => sprintf(
                /* translators: 1: new version, 2: installed version */
                __('Version %1$s is available. You are running %2$s.', 'sitessaver'),
                $release['version'],
                SITESSAVER_VERSION
            ),
        ]);
    }

    /**
     * Remember that this user dismissed the update banner for this version.
     */
    public function handle_dismiss_update_notice(): void {
        sitessaver_verify_ajax();

        $version = sanitize_text_field(wp_unslash($_POST['version'] ?? ''));

        update_user_meta(get_current_user_id(), 'sitessaver_dismissed_update', $version);

        wp_send_json_success();
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
            $restore['finalize_token'] = Import::current_finalize_token();
            $restore['finalize_url']   = Import::build_finalize_redirect_url();
            wp_send_json_success($restore);
        } else {
            wp_send_json_error($this->restore_failure_payload($restore));
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
     * Receive one chunk of an uploaded backup.
     *
     * Receives: chunk (file blob), chunk_index, total_chunks, filename, upload_id.
     *
     * Each chunk is APPENDED to a single growing .part file the moment it
     * arrives, so every request does the same small amount of work. This
     * replaced "save each chunk separately, then glue them all together when
     * the last one lands": that final request had to copy the whole backup
     * twice over, which on a large site and a throttled shared disk took
     * minutes — the web server cut it off at its request limit (60s on many
     * LiteSpeed hosts), the browser showed "An error occurred." at 100%, and
     * the half-built file was then deleted. Now the last chunk only appends
     * its own few MB and renames the result into place.
     *
     * Idempotent per chunk: if the browser retries a chunk whose response it
     * never saw, the server recognises it as already written and says so,
     * instead of appending it twice.
     */
    public function handle_upload_chunk(): void {
        sitessaver_verify_ajax();

        $upload_id = sanitize_key(wp_unslash($_POST['upload_id'] ?? ''));
        if (empty($upload_id) || !preg_match('/^[a-z0-9]{8,32}$/', $upload_id)) {
            wp_send_json_error(Errors::payload('upload_page_outdated', 'Invalid upload ID.'), 400);
        }

        $chunk_index  = (int) ($_POST['chunk_index'] ?? -1);
        $total_chunks = (int) ($_POST['total_chunks'] ?? 0);
        $filename     = sanitize_file_name(wp_unslash($_POST['filename'] ?? ''));

        if ($total_chunks < 1 || $total_chunks > 100000 || $chunk_index < 0 || $chunk_index >= $total_chunks) {
            wp_send_json_error(Errors::payload('upload_page_outdated', "Invalid chunk index {$chunk_index}/{$total_chunks}."), 400);
        }

        if (empty($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            wp_send_json_error(Errors::payload('upload_not_zip', 'Rejected file name: ' . $filename), 400);
        }

        if (empty($_FILES['chunk']) || (int) $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            $php_err = (int) ($_FILES['chunk']['error'] ?? -1);
            wp_send_json_error(Errors::report(
                $php_err === UPLOAD_ERR_CANT_WRITE ? 'upload_disk_full' : 'upload_failed',
                'PHP rejected chunk upload (UPLOAD_ERR ' . $php_err . ').',
                ['upload_id' => $upload_id, 'chunk' => $chunk_index, 'total' => $total_chunks]
            ));
        }

        $chunk_dir = SITESSAVER_TEMP_DIR . '/chunks/' . $upload_id;
        if (!is_dir($chunk_dir)) {
            wp_mkdir_p($chunk_dir);
        }

        // One request at a time per upload: a retried chunk can overlap the
        // original if the network hiccupped, and both must not append.
        $lock = @fopen($chunk_dir . '/.lock', 'c');
        if ($lock === false) {
            wp_send_json_error(Errors::report('upload_failed', 'Cannot create upload lock in ' . $chunk_dir, ['upload_id' => $upload_id]));
        }
        flock($lock, LOCK_EX);

        $state = $this->upload_state($chunk_dir);

        // Already finished (the browser is retrying the last chunk after
        // missing our reply): hand back the same answer again.
        if (!empty($state['assembled_file'])) {
            flock($lock, LOCK_UN);
            fclose($lock);
            wp_send_json_success([
                'chunk_index'    => $chunk_index,
                'assembled'      => true,
                'assembled_file' => $state['assembled_file'],
                'message'        => __('Upload complete.', 'sitessaver'),
            ]);
        }

        $received = (int) ($state['received'] ?? 0);

        if ($chunk_index < $received) {
            // Duplicate of a chunk already written.
            flock($lock, LOCK_UN);
            fclose($lock);
            wp_send_json_success(['chunk_index' => $chunk_index, 'assembled' => false, 'next_index' => $received, 'duplicate' => true]);
        }

        if ($chunk_index > $received) {
            flock($lock, LOCK_UN);
            fclose($lock);
            wp_send_json_error(array_merge(
                Errors::payload('upload_interrupted', "Out-of-order chunk {$chunk_index}, expected {$received}."),
                ['next_index' => $received]
            ), 409);
        }

        // Append.
        $part   = $chunk_dir . '/upload.part';
        clearstatcache(true, $part);
        $before = is_file($part) ? (int) filesize($part) : 0;
        $in     = @fopen($_FILES['chunk']['tmp_name'], 'rb');
        $out    = @fopen($part, 'ab');
        $size   = (int) ($_FILES['chunk']['size'] ?? 0);
        $copied = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
        if ($in) {
            fclose($in);
        }
        if ($out) {
            fflush($out);
            fclose($out);
        }

        if ($copied === false || (int) $copied !== $size) {
            // Roll the partial write back so a retry starts clean.
            if (is_file($part)) {
                $h = @fopen($part, 'r+');
                if ($h) {
                    ftruncate($h, $before);
                    fclose($h);
                }
            }
            $free = @disk_free_space($chunk_dir);
            flock($lock, LOCK_UN);
            fclose($lock);
            wp_send_json_error(Errors::report(
                ($free !== false && $free < $size * 4) ? 'upload_disk_full' : 'upload_failed',
                sprintf('Appending chunk %d failed: wrote %s of %d bytes.', $chunk_index, var_export($copied, true), $size),
                ['upload_id' => $upload_id, 'free_bytes' => $free === false ? 'unknown' : (int) $free]
            ));
        }

        $state = [
            'filename' => $filename,
            'total'    => $total_chunks,
            'received' => $chunk_index + 1,
            'bytes'    => $before + $size,
            'updated'  => time(),
        ];
        $this->save_upload_state($chunk_dir, $state);
        @touch($chunk_dir); // keeps the 6h stale-chunk sweep away from a slow upload

        if ($chunk_index < $total_chunks - 1) {
            flock($lock, LOCK_UN);
            fclose($lock);
            wp_send_json_success(['chunk_index' => $chunk_index, 'assembled' => false, 'next_index' => $chunk_index + 1]);
        }

        // Last chunk: verify and move into storage. A rename, not a copy.
        $error = null;
        $dest  = $this->finish_upload($part, $filename, $error);

        if ($dest === null) {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->remove_chunk_dir($chunk_dir);
            wp_send_json_error(Errors::report(
                $error === 'not_zip' ? 'upload_not_zip' : 'upload_failed',
                'Finishing the upload failed: ' . (string) $error,
                ['upload_id' => $upload_id, 'bytes' => $state['bytes'], 'file' => $filename]
            ));
        }

        $state['assembled_file'] = basename($dest);
        $this->save_upload_state($chunk_dir, $state);

        flock($lock, LOCK_UN);
        fclose($lock);

        Log::info('upload_complete', 'Backup uploaded.', [
            'file'   => basename($dest),
            'size'   => size_format((int) $state['bytes']),
            'chunks' => $total_chunks,
        ]);

        wp_send_json_success([
            'chunk_index'    => $chunk_index,
            'assembled'      => true,
            'assembled_file' => basename($dest),
            'message'        => __('Upload complete.', 'sitessaver'),
        ]);
    }

    /**
     * Where an upload stands, so the browser can recover after a failed
     * chunk request instead of guessing: retry the same chunk, skip ahead
     * (it landed, only the reply was lost), or pick up the finished file.
     */
    public function handle_upload_status(): void {
        sitessaver_verify_ajax();

        $upload_id = sanitize_key(wp_unslash($_POST['upload_id'] ?? ''));
        if (empty($upload_id) || !preg_match('/^[a-z0-9]{8,32}$/', $upload_id)) {
            wp_send_json_error(Errors::payload('upload_page_outdated', 'Invalid upload ID.'), 400);
        }

        $state = $this->upload_state(SITESSAVER_TEMP_DIR . '/chunks/' . $upload_id);

        wp_send_json_success([
            'received'       => (int) ($state['received'] ?? 0),
            'total'          => (int) ($state['total'] ?? 0),
            'assembled_file' => $state['assembled_file'] ?? null,
        ]);
    }

    /**
     * Validate the finished .part file and move it into the storage dir.
     *
     * @return string|null Final path, or null with $error set.
     */
    private function finish_upload(string $part, string $filename, ?string &$error): ?string {
        $handle = @fopen($part, 'rb');
        if (!$handle) {
            $error = 'part file missing';
            return null;
        }
        $header = fread($handle, 4);
        fclose($handle);

        // PK\x03\x04 = local file header, PK\x05\x06 = empty archive.
        if ($header !== "PK\x03\x04" && $header !== "PK\x05\x06") {
            $error = 'not_zip';
            return null;
        }

        $dir  = sitessaver_storage_dir();
        $dest = $dir . '/' . sanitize_file_name($filename);
        if (file_exists($dest)) {
            $dest = $dir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '-' . wp_generate_password(4, false) . '.zip';
        }

        if (@rename($part, $dest)) {
            return $dest;
        }

        // Different filesystem (unusual): fall back to a streamed copy.
        $in  = @fopen($part, 'rb');
        $out = @fopen($dest, 'wb');
        $ok  = $in && $out && stream_copy_to_stream($in, $out) !== false;
        if ($in) {
            fclose($in);
        }
        if ($out) {
            fclose($out);
        }
        if (!$ok) {
            @unlink($dest);
            $error = 'could not move upload into ' . $dir;
            return null;
        }
        @unlink($part);
        return $dest;
    }

    /**
     * @return array<string, mixed>
     */
    private function upload_state(string $chunk_dir): array {
        $file = $chunk_dir . '/state.json';
        if (!is_readable($file)) {
            return [];
        }
        $state = json_decode((string) file_get_contents($file), true);
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save_upload_state(string $chunk_dir, array $state): void {
        file_put_contents($chunk_dir . '/state.json', wp_json_encode($state), LOCK_EX);
    }

    /**
     * Remove a chunk directory and all its contents.
     */
    private function remove_chunk_dir(string $dir): void {
        // sitessaver_rm_recursive() also removes dotfiles (.lock) and does
        // not depend on GLOB_BRACE, which musl-based PHP builds lack.
        sitessaver_rm_recursive($dir);
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

    // ------------------------------------------------------------------
    // Restore jobs
    // ------------------------------------------------------------------

    /**
     * Start a restore. Returns at once with a job id + token; the work runs
     * in a background request (or, if the host blocks those, in a request the
     * browser makes to handle_restore_run()). The browser follows progress
     * through handle_restore_status().
     *
     * Accepts `file` (a backup in storage) or `gdrive_file_id`.
     */
    public function handle_restore_start(): void {
        sitessaver_verify_ajax();

        $file     = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));
        $drive_id = sanitize_text_field(wp_unslash($_POST['gdrive_file_id'] ?? ''));

        if ($file === '' && $drive_id === '') {
            wp_send_json_error(Errors::payload('restore_file_missing', 'No file specified.'), 400);
        }

        if ($file !== '' && !is_readable(sitessaver_storage_dir() . '/' . $file)) {
            wp_send_json_error(Errors::report('restore_file_missing', 'Backup file not found: ' . $file));
        }

        $job = $file !== ''
            ? Restore_Job::create('file', $file)
            : Restore_Job::create('gdrive', $drive_id);

        $spawned = Restore_Job::spawn($job['id'], $job['worker_key']);

        wp_send_json_success([
            'job'        => $job['id'],
            'token'      => $job['token'],
            'background' => $spawned,
        ]);
    }

    /**
     * Background worker for a restore job (detached loopback; no session).
     * Authorized by the single-use worker key stored hashed in the job.
     */
    public function handle_restore_worker(): void {
        $id  = sanitize_key(wp_unslash($_POST['job'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));

        $job = Restore_Job::get($id);
        if ($job === null || !Restore_Job::verify_worker_key($job, $key) || (int) ($job['blog_id'] ?? 0) !== get_current_blog_id()) {
            wp_send_json_error(['message' => 'Invalid worker key.'], 403);
        }

        if (!Restore_Job::claim($id)) {
            wp_send_json_success(['message' => 'already running']);
        }

        Restore_Job::run($id, 'background');

        // Nobody is listening; just end cleanly.
        wp_send_json_success(['message' => 'ok']);
    }

    /**
     * In-browser fallback: run a job that the background worker never picked
     * up. The response may be cut off by the web server on a long restore —
     * the browser ignores this request's outcome and keeps following the job
     * through the status poll, which tells the truth either way.
     */
    public function handle_restore_run(): void {
        sitessaver_verify_ajax();

        $id    = sanitize_key(wp_unslash($_POST['job'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));

        $job = Restore_Job::get($id);
        if ($job === null || !Restore_Job::verify_token($job, $token)) {
            wp_send_json_error(Errors::payload('upload_page_outdated', 'Unknown restore job.'), 403);
        }

        if (($job['status'] ?? '') !== 'queued' || !Restore_Job::claim($id)) {
            wp_send_json_success(['message' => 'already running']);
        }

        Log::info('restore_inline', 'Background restore did not start; running it in the browser request.', ['job' => $id]);

        ob_start();
        Restore_Job::run($id, 'inline');
        self::discard_output_buffer();

        wp_send_json_success(['message' => 'ok']);
    }

    /**
     * Restore progress for the browser. Token-authorized (see init()).
     */
    public function handle_restore_status(): void {
        // Never cache this — some hosts cache admin-ajax GET/POST responses
        // for logged-out visitors, which is exactly what this request looks
        // like after the database swap.
        nocache_headers();

        $id    = sanitize_key(wp_unslash($_POST['job'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));

        $job = Restore_Job::get($id);
        if ($job === null || !Restore_Job::verify_token($job, $token)) {
            wp_send_json_error(['message' => 'Unknown restore job.'], 404);
        }

        wp_send_json_success(Restore_Job::public_view($job));
    }

    /**
     * Failures only the browser can see (a dropped connection, a gateway
     * timeout returning HTML) are reported here so they reach the log too
     * and get a reference code the user can quote.
     */
    public function handle_client_error(): void {
        sitessaver_verify_ajax();

        $code   = sanitize_key(wp_unslash($_POST['code'] ?? 'generic'));
        $detail = sanitize_textarea_field(wp_unslash($_POST['detail'] ?? ''));
        $where  = sanitize_text_field(wp_unslash($_POST['where'] ?? ''));

        $known = Errors::catalogue();
        if (!isset($known[$code])) {
            $code = 'generic';
        }

        $ref = Log::error($code, $detail !== '' ? $detail : 'Reported by the browser.', [
            'where'      => $where,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 200) : '',
        ]);

        wp_send_json_success(['ref' => $ref]);
    }

    /**
     * Download the troubleshooting log as a .txt file.
     */
    public function handle_log_download(): void {
        if (!check_ajax_referer('sitessaver_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'sitessaver'), '', ['response' => 403]);
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="sitessaver-log-' . gmdate('Ymd-His') . '.txt"');
        echo Log::as_text(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text download.
        exit;
    }

    public function handle_log_clear(): void {
        sitessaver_verify_ajax();
        Log::clear();
        wp_send_json_success(['message' => __('Log cleared.', 'sitessaver')]);
    }
}
