<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Google Drive integration via OAuth Proxy Relay.
 *
 * Auth flow goes through the proxy (api.sitessaver.com) so users never
 * need to create Google credentials. Upload/download/list go direct to
 * Google Drive API using the access token from the proxy.
 */
final class GDrive {

    /** Proxy relay base URL — handles OAuth on behalf of all installations. */
    private const PROXY_URL  = 'https://api.sitessaver.com';

    /** Google Drive API v3 — direct calls for file operations. */
    private const API_URL    = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3';

    /**
     * Escape a value for use inside a Google Drive v3 query `q=` parameter.
     * Per the spec: backslashes first, then single quotes. Prevents q-injection
     * via attacker-controlled folder IDs or filenames (CWE-74).
     */
    private static function drive_escape(string $value): string {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Get settings.
     */
    private static function settings(): array {
        return get_option('sitessaver_settings', []);
    }

    /**
     * Get stored access token (auto-refresh via proxy if expired).
     */
    private static function get_token(): ?string {
        $token_data = get_option('sitessaver_gdrive_token', []);

        if (empty($token_data['refresh_token'])) {
            return null;
        }

        // Check if cached access token is still valid.
        if (!empty($token_data['access_token']) && !empty($token_data['expires_at']) && time() < $token_data['expires_at']) {
            return $token_data['access_token'];
        }

        // Refresh via proxy.
        $refreshed = self::refresh_token($token_data['refresh_token']);
        if ($refreshed === null) {
            return null;
        }

        return $refreshed['access_token'];
    }

    /** Transient key storing the pending OAuth state token. */
    private const STATE_TRANSIENT = 'sitessaver_gdrive_oauth_state';

    /** OAuth state token lifetime (seconds). */
    private const STATE_TTL = 600; // 10 minutes.

    /**
     * Get the OAuth authorization URL (points to proxy).
     * Proxy handles Google OAuth and redirects back with refresh_token.
     *
     * Generates a single-use `state` token bound to the current user and stored
     * in a short-lived transient, forwarded to the proxy. The proxy must echo
     * it back on the callback so we can verify the round-trip came from our
     * own authorization request (CSRF protection, CWE-352).
     */
    public static function get_auth_url(): string {
        $callback_url = admin_url('admin.php?page=sitessaver-settings');

        $state = wp_generate_password(32, false, false);
        set_transient(self::state_key(), $state, self::STATE_TTL);

        return self::PROXY_URL . '/v1/gdrive/authorize?' . http_build_query([
            'callback_url' => $callback_url,
            'state'        => $state,
        ]);
    }

    /**
     * Per-user transient key. Scoping to user id prevents a second admin's
     * pending connect from being hijacked by the first.
     */
    private static function state_key(): string {
        return self::STATE_TRANSIENT . '_' . get_current_user_id();
    }

    /**
     * Handle the OAuth callback from proxy.
     * Called when proxy redirects back with ?sitessaver_gdrive_token=xxx
     */
    public static function handle_callback(): array {
        $refresh_token = sanitize_text_field(wp_unslash($_GET['sitessaver_gdrive_token'] ?? ''));
        $status        = sanitize_text_field(wp_unslash($_GET['sitessaver_gdrive_status'] ?? ''));
        $received_state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));

        if ($refresh_token === '' || $status !== 'connected') {
            return ['success' => false, 'message' => __('Google Drive connection failed.', 'sitessaver')];
        }

        // CSRF guard: verify the state token returned by the proxy matches the
        // single-use value we issued on get_auth_url().
        $expected_state = get_transient(self::state_key());
        delete_transient(self::state_key()); // single-use.

        if ($expected_state === false || $received_state === '' || !hash_equals((string) $expected_state, $received_state)) {
            return ['success' => false, 'message' => __('Google Drive connection rejected: invalid or expired security token. Please try connecting again.', 'sitessaver')];
        }

        // Get initial access token via proxy.
        $refreshed = self::refresh_token($refresh_token);

        if ($refreshed === null) {
            return ['success' => false, 'message' => __('Failed to obtain access token.', 'sitessaver')];
        }

        self::ensure_folder_exists($refreshed['access_token']);

        return ['success' => true, 'message' => __('Google Drive connected.', 'sitessaver')];
    }

    /**
     * Ensure a SitesSaver folder exists in Google Drive.
     * Auto-creates if missing.
     *
     * `?string` is explicit: an implicitly-nullable parameter (`string $x = null`)
     * is deprecated in PHP 8.4 and emits a notice on every call, which on an
     * AJAX endpoint can corrupt the JSON body.
     */
    public static function ensure_folder_exists(?string $token = null): string {
        $token = $token ?? self::get_token();
        if (!$token) return '';

        $settings = self::settings();
        if (!empty($settings['gdrive_folder_id'])) {
            return $settings['gdrive_folder_id'];
        }

        $folder_name  = 'SitesSaver Backups (' . get_bloginfo('name') . ')';
        $folder_name_q = self::drive_escape($folder_name);

        // Check if folder exists first. An explicit timeout is required: the WP
        // default is 5s, and a slow Drive response would otherwise abort the
        // lookup and cause a duplicate folder to be created on every backup.
        $check = wp_remote_get(self::API_URL . '/files?' . http_build_query([
            'q' => "name='{$folder_name_q}' and mimeType='application/vnd.google-apps.folder' and trashed=false",
            'fields' => 'files(id)',
        ]), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20,
        ]);

        if (!is_wp_error($check)) {
            $body = json_decode(wp_remote_retrieve_body($check), true);
            if (!empty($body['files'][0]['id'])) {
                $folder_id = $body['files'][0]['id'];
                $settings['gdrive_folder_id'] = $folder_id;
                update_option('sitessaver_settings', $settings, false);
                return $folder_id;
            }
        }

        // Create it.
        $create = wp_remote_post(self::API_URL . '/files', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'name'     => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
            'timeout' => 20,
        ]);

        if (!is_wp_error($create)) {
            $body = json_decode(wp_remote_retrieve_body($create), true);
            if (!empty($body['id'])) {
                $folder_id = $body['id'];
                $settings['gdrive_folder_id'] = $folder_id;
                update_option('sitessaver_settings', $settings, false);
                return $folder_id;
            }
        }

        return '';
    }

    /**
     * Refresh access token via proxy relay.
     * Proxy holds the Client ID/Secret — we just send our refresh_token.
     */
    private static function refresh_token(string $refresh_token): ?array {
        if (empty($refresh_token)) {
            return null;
        }

        $response = wp_remote_post(self::PROXY_URL . '/v1/gdrive/refresh', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['refresh_token' => $refresh_token]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $token_data = [
                'refresh_token' => $refresh_token,
                'access_token'  => $body['access_token'],
                'expires_at'    => time() + (int) ($body['expires_in'] ?? 3600) - 60, // 60s safety margin
            ];
            update_option('sitessaver_gdrive_token', $token_data, false);

            return $token_data;
        }

        return null;
    }

    /**
     * Upload a file to Google Drive (direct to Google API).
     */
    public static function upload(string $file_path, string $filename, string $job_id = ''): array {
        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $token = self::get_token();
        if ($token === null) {
            return ['success' => false, 'message' => __('Google Drive not connected.', 'sitessaver')];
        }

        if (!empty($job_id)) {
            set_transient('sitessaver_gdrive_job_' . $job_id, ['progress' => 0, 'status' => 'starting'], HOUR_IN_SECONDS);
        }

        $file_size = @filesize($file_path);
        if (!$file_size) {
            return ['success' => false, 'message' => __('Cannot determine file size.', 'sitessaver')];
        }

        $metadata = ['name' => $filename];
        $folder_id = self::ensure_folder_exists($token);
        
        if (!empty($folder_id)) {
            $metadata['parents'] = [$folder_id];

            // Delete existing file with the same name in this folder to avoid
            // duplicates. Explicit timeout: the WP default of 5s regularly
            // expires on Drive list calls, and a timed-out lookup silently
            // skipped the de-dupe so every scheduled backup added another copy.
            $filename_q  = self::drive_escape($filename);
            $folder_id_q = self::drive_escape($folder_id);
            $existing = wp_remote_get(self::API_URL . '/files?' . http_build_query([
                'q' => "name='{$filename_q}' and '{$folder_id_q}' in parents and trashed=false",
                'fields' => 'files(id)',
            ]), [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 20,
            ]);

            if (!is_wp_error($existing)) {
                $body = json_decode(wp_remote_retrieve_body($existing), true);
                foreach ($body['files'] ?? [] as $old_file) {
                    if (empty($old_file['id'])) {
                        continue;
                    }
                    wp_remote_request(self::API_URL . '/files/' . rawurlencode((string) $old_file['id']), [
                        'method'  => 'DELETE',
                        'headers' => ['Authorization' => 'Bearer ' . $token],
                        'timeout' => 20,
                    ]);
                }
            }
        }

        // 1. Initiate Resumable Upload Session
        $response = wp_remote_post(self::UPLOAD_URL . '/files?uploadType=resumable', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => $file_size,
            ],
            'body' => wp_json_encode($metadata),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return ['success' => false, 'message' => __('Failed to initiate upload session.', 'sitessaver')];
        }

        $session_url = wp_remote_retrieve_header($response, 'location');
        if (empty($session_url)) {
            return ['success' => false, 'message' => __('No upload session URL received.', 'sitessaver')];
        }

        // 2. Upload in chunks with per-chunk retry + session resume.
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return ['success' => false, 'message' => __('Cannot open backup file for reading.', 'sitessaver')];
        }

        $chunk_size = self::resumable_chunk_size();
        $offset     = 0;
        $completed  = false;

        // Guard against a session that keeps answering 308 without advancing.
        // Without this the while-loop could spin forever against a misbehaving
        // endpoint, pinning CPU until the request is killed.
        $stalled_rounds = 0;
        $max_stalls     = 5;

        try {
            while ($offset < $file_size) {
                if (fseek($handle, $offset) !== 0) {
                    return ['success' => false, 'message' => __('Error seeking backup file.', 'sitessaver')];
                }

                $data = fread($handle, $chunk_size);
                if ($data === false) {
                    return ['success' => false, 'message' => __('Error reading backup file.', 'sitessaver')];
                }

                $current_size = strlen($data);
                if ($current_size === 0) {
                    break;
                }

                $range_start = $offset;
                $range_end   = $offset + $current_size - 1;

                $result = self::put_chunk_with_retry($session_url, $data, $range_start, $range_end, $file_size);

                if ($result['status'] === 'complete') {
                    $offset    = $file_size;
                    $completed = true;
                    break;
                }

                if ($result['status'] === 'incomplete') {
                    // 308 — per spec, Google may have committed fewer bytes than we
                    // sent (rare, but the Range header is authoritative). Advance to
                    // whatever Google confirmed.
                    $previous = $offset;
                    $offset = $result['next_offset'] > $offset
                        ? $result['next_offset']
                        : $offset + $current_size;

                    if ($offset <= $previous) {
                        // No forward progress at all — bail out instead of spinning.
                        if (++$stalled_rounds >= $max_stalls) {
                            return [
                                'success' => false,
                                'message' => __('Upload stalled: Google Drive stopped accepting new bytes. Please retry.', 'sitessaver'),
                            ];
                        }
                    } else {
                        $stalled_rounds = 0;
                    }

                    if (!empty($job_id)) {
                        $pct = (int) round(($offset / $file_size) * 100);
                        set_transient(
                            'sitessaver_gdrive_job_' . $job_id,
                            ['progress' => min(99, max(0, $pct)), 'status' => 'uploading'],
                            HOUR_IN_SECONDS
                        );
                    }
                    continue;
                }

                // status === 'failed'
                return ['success' => false, 'message' => $result['message']];
            }

            if (!$completed && $offset < $file_size) {
                return ['success' => false, 'message' => __('Upload ended before all bytes were sent.', 'sitessaver')];
            }

            if (!empty($job_id)) {
                set_transient(
                    'sitessaver_gdrive_job_' . $job_id,
                    ['progress' => 100, 'status' => 'completed'],
                    HOUR_IN_SECONDS
                );
            }

            return ['success' => true, 'message' => __('Backup uploaded to Google Drive.', 'sitessaver')];

        } finally {
            fclose($handle);
            if (!empty($job_id) && !$completed && $offset < $file_size) {
                delete_transient('sitessaver_gdrive_job_' . $job_id);
            }
        }
    }

    /**
     * Chunk size for resumable uploads, in bytes.
     *
     * Google requires every non-final chunk to be a multiple of 256 KiB. We
     * prefer 5 MiB, but the chunk is held entirely in memory (read into a
     * string, then handed to wp_remote_request as the body — which copies it),
     * so on a shared host with a small memory_limit a 5 MiB chunk can exhaust
     * the budget mid-upload and fatal. We therefore scale down to fit, never
     * below the 256 KiB minimum, and always on a 256 KiB boundary.
     */
    private static function resumable_chunk_size(): int {
        $unit      = 256 * 1024;
        $preferred = 5 * 1024 * 1024;

        $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            // Unlimited (-1) or unparseable — keep the preferred size.
            return $preferred;
        }

        // Budget a quarter of the limit for the chunk plus its copies.
        $budget = (int) floor($limit / 4);
        $size   = (int) (floor(min($preferred, $budget) / $unit) * $unit);

        return max($unit, $size);
    }

    /**
     * PUT a single chunk with exponential-backoff retry on transient failures.
     * On failure, queries the resumable session for the last confirmed byte so
     * the caller can resume from the right offset instead of restarting the
     * entire upload.
     *
     * @return array{status: 'complete'|'incomplete'|'failed', next_offset: int, message: string}
     */
    private static function put_chunk_with_retry(
        string $session_url,
        string $data,
        int $range_start,
        int $range_end,
        int $file_size
    ): array {
        // Status codes that warrant a retry (transient server or throttling errors).
        $transient_codes = [408, 429, 500, 502, 503, 504];
        $max_attempts    = 4; // 1 initial + 3 retries.
        $attempt         = 0;

        while ($attempt < $max_attempts) {
            $attempt++;

            $response = wp_remote_request($session_url, [
                'method'  => 'PUT',
                'headers' => [
                    'Content-Length' => (string) strlen($data),
                    'Content-Range'  => "bytes {$range_start}-{$range_end}/{$file_size}",
                ],
                'body'    => $data,
                'timeout' => 300,
            ]);

            if (is_wp_error($response)) {
                // Network layer error — treat as transient unless we've exhausted retries.
                if ($attempt >= $max_attempts) {
                    return [
                        'status'      => 'failed',
                        'next_offset' => 0,
                        'message'     => $response->get_error_message(),
                    ];
                }
                self::backoff_sleep($attempt);
                // Re-query session before retrying in case server got some bytes.
                $probe = self::query_session_progress($session_url, $file_size);
                if ($probe['status'] === 'complete') {
                    return ['status' => 'complete', 'next_offset' => $file_size, 'message' => ''];
                }
                if ($probe['status'] === 'expired') {
                    return [
                        'status'      => 'failed',
                        'next_offset' => 0,
                        'message'     => __('Upload session expired. Please retry the upload.', 'sitessaver'),
                    ];
                }
                // If the server has advanced, bail out of the retry loop and let the
                // caller resume from the confirmed offset.
                if ($probe['status'] === 'incomplete' && $probe['next_offset'] > $range_start) {
                    return ['status' => 'incomplete', 'next_offset' => $probe['next_offset'], 'message' => ''];
                }
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);

            if ($code === 200 || $code === 201) {
                return ['status' => 'complete', 'next_offset' => $file_size, 'message' => ''];
            }

            if ($code === 308) {
                $next = self::parse_range_next_offset(
                    (string) wp_remote_retrieve_header($response, 'range'),
                    $range_end + 1
                );
                return ['status' => 'incomplete', 'next_offset' => $next, 'message' => ''];
            }

            if ($code === 404 || $code === 410) {
                return [
                    'status'      => 'failed',
                    'next_offset' => 0,
                    'message'     => __('Upload session expired. Please retry the upload.', 'sitessaver'),
                ];
            }

            if (in_array($code, $transient_codes, true) && $attempt < $max_attempts) {
                self::backoff_sleep($attempt);
                continue;
            }

            // Permanent error — surface Google's message if available.
            $body    = json_decode((string) wp_remote_retrieve_body($response), true);
            $message = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : sprintf(__('Upload chunk failed (HTTP %d).', 'sitessaver'), $code);

            return ['status' => 'failed', 'next_offset' => 0, 'message' => $message];
        }

        return [
            'status'      => 'failed',
            'next_offset' => 0,
            'message'     => __('Upload chunk failed after repeated retries.', 'sitessaver'),
        ];
    }

    /**
     * Query a resumable upload session for the last committed byte so uploads
     * can resume after a network drop.
     *
     * @return array{status: 'incomplete'|'complete'|'expired'|'error', next_offset: int}
     */
    private static function query_session_progress(string $session_url, int $file_size): array {
        $response = wp_remote_request($session_url, [
            'method'  => 'PUT',
            'headers' => [
                'Content-Length' => '0',
                'Content-Range'  => "bytes */{$file_size}",
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'error', 'next_offset' => 0];
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 200 || $code === 201) {
            return ['status' => 'complete', 'next_offset' => $file_size];
        }

        if ($code === 308) {
            $next = self::parse_range_next_offset(
                (string) wp_remote_retrieve_header($response, 'range'),
                0
            );
            return ['status' => 'incomplete', 'next_offset' => $next];
        }

        if ($code === 404 || $code === 410) {
            return ['status' => 'expired', 'next_offset' => 0];
        }

        return ['status' => 'error', 'next_offset' => 0];
    }

    /**
     * Parse Google's `Range: bytes=0-<last>` header into the next byte offset.
     * Falls back to $default if the header is missing or malformed.
     */
    private static function parse_range_next_offset(string $range_header, int $default): int {
        if ($range_header === '') {
            return $default;
        }
        if (preg_match('/bytes=\d+-(\d+)/', $range_header, $m) === 1) {
            return ((int) $m[1]) + 1;
        }
        return $default;
    }

    /**
     * Exponential backoff between retry attempts: 1s, 2s, 4s...
     */
    private static function backoff_sleep(int $attempt): void {
        $seconds = 2 ** ($attempt - 1);
        sleep(min($seconds, 8));
    }

    /**
     * Get URL to the Google Drive folder.
     */
    public static function get_folder_url(): string {
        $settings  = self::settings();
        $folder_id = $settings['gdrive_folder_id'] ?? '';
        
        if (empty($folder_id)) {
            return 'https://drive.google.com/drive/my-drive';
        }
        
        return "https://drive.google.com/drive/folders/{$folder_id}";
    }

    /**
     * List backup files on Google Drive.
     */
    public static function list_files(): array {
        $token = self::get_token();
        if ($token === null) {
            return ['files' => [], 'connected' => false];
        }

        $settings  = self::settings();
        $folder_id = $settings['gdrive_folder_id'] ?? '';

        $query = "mimeType='application/zip' and trashed=false";
        if (!empty($folder_id)) {
            $folder_id_q = self::drive_escape($folder_id);
            $query .= " and '{$folder_id_q}' in parents";
        }

        $response = wp_remote_get(self::API_URL . '/files?' . http_build_query([
            'q'        => $query,
            'fields'   => 'files(id,name,size,createdTime)',
            'orderBy'  => 'createdTime desc',
            'pageSize' => 50,
        ]), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['files' => [], 'connected' => true, 'error' => $response->get_error_message()];
        }

        // A non-2xx here means the listing failed. Returning an empty file list
        // without an error made the Drive tab look like "no backups yet" when
        // the real cause was an expired token or a permissions change.
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return [
                'files'     => [],
                'connected' => true,
                'error'     => self::api_error_message($response, $code),
            ];
        }

        $body  = json_decode(wp_remote_retrieve_body($response), true);
        $files = [];

        foreach ($body['files'] ?? [] as $file) {
            $files[] = [
                'id'      => $file['id'],
                'name'    => $file['name'],
                'size'    => sitessaver_format_size((int) ($file['size'] ?? 0)),
                'created' => $file['createdTime'] ?? '',
            ];
        }

        return ['files' => $files, 'connected' => true];
    }

    /**
     * Download a file from Google Drive to local storage.
     *
     * Correctness notes (all three were real data-loss bugs):
     *  1. `wp_remote_get(..., ['stream' => true])` writes the response body to
     *     `filename` REGARDLESS of the HTTP status. A 404/401 JSON error blob
     *     therefore landed on disk as a `.zip` and was reported as a successful
     *     download; restoring it failed later with a confusing message.
     *  2. Writing straight to the final path meant an existing local backup of
     *     the same name was destroyed even when the download failed. We stage
     *     into the temp dir and only publish after validation.
     *  3. A downloaded file that isn't actually a ZIP is rejected up front
     *     rather than at restore time.
     */
    public static function download(string $file_id): array {
        $token = self::get_token();
        if ($token === null) {
            return ['success' => false, 'message' => __('Google Drive not connected.', 'sitessaver')];
        }

        // Get file metadata.
        $meta_response = wp_remote_get(self::API_URL . "/files/" . rawurlencode($file_id) . "?fields=name,size", [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20,
        ]);

        if (is_wp_error($meta_response)) {
            return ['success' => false, 'message' => $meta_response->get_error_message()];
        }

        $meta_code = (int) wp_remote_retrieve_response_code($meta_response);
        if ($meta_code < 200 || $meta_code >= 300) {
            return [
                'success' => false,
                'message' => self::api_error_message($meta_response, $meta_code),
            ];
        }

        $meta     = json_decode(wp_remote_retrieve_body($meta_response), true);
        $filename = sanitize_file_name($meta['name'] ?? 'backup.zip');
        // Drive returns size as a string; 0 means "unknown" (rare, e.g. Docs).
        $expected_size = isset($meta['size']) ? (int) $meta['size'] : 0;
        if ($filename === '' || strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            $filename = 'gdrive-' . wp_generate_password(6, false, false) . '.zip';
        }

        // Stage into temp so a failed download can never clobber an existing
        // backup in the storage directory.
        if (!is_dir(SITESSAVER_TEMP_DIR)) {
            wp_mkdir_p(SITESSAVER_TEMP_DIR);
        }
        $staged = SITESSAVER_TEMP_DIR . '/gdrive-dl-' . wp_generate_password(8, false, false) . '.zip';

        /*
         * acknowledgeAbuse=true is REQUIRED here.
         *
         * Google runs a malware/abuse scan on Drive content. Files it cannot
         * scan — which includes any sufficiently large archive, and virtually
         * every full-site backup ZIP — are flagged. For a flagged file the v3
         * API does NOT return an error for `alt=media`: it accepts the TLS
         * connection, accepts the request, and then simply never sends a
         * response body. cURL sits there until it hits its own timeout and
         * reports "Operation timed out ... with 0 bytes received".
         *
         * Measured against a real 74.78 MB backup on a live site:
         *   without acknowledgeAbuse -> 0 bytes after 7+ minutes, then timeout
         *   with    acknowledgeAbuse -> full 78,417,557 bytes in 1.44s
         *
         * The flag only asserts that we accept the risk of downloading a file
         * Drive could not scan. It is a no-op for unflagged files, so it is
         * safe to send unconditionally. This is the user's own backup, which
         * this same plugin uploaded.
         *
         * Also pass a stall guard: if the transfer delivers less than 1 byte/s
         * for 30s straight, fail fast with a clear message instead of hanging
         * for the full timeout and letting PHP-FPM kill the request first.
         */
        $media_url = self::API_URL . '/files/' . rawurlencode($file_id)
            . '?alt=media&acknowledgeAbuse=true&supportsAllDrives=true';

        $stall_guard = static function ($handle) {
            curl_setopt($handle, CURLOPT_LOW_SPEED_LIMIT, 1);
            curl_setopt($handle, CURLOPT_LOW_SPEED_TIME, 30);
        };
        add_action('http_api_curl', $stall_guard);

        $response = wp_remote_get($media_url, [
            'headers'  => ['Authorization' => 'Bearer ' . $token],
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $staged,
        ]);

        remove_action('http_api_curl', $stall_guard);

        if (is_wp_error($response)) {
            @unlink($staged);
            $err = $response->get_error_message();
            if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
                $err = sprintf(
                    /* translators: %s: the underlying transport error */
                    __('Download from Google Drive stalled and was aborted (%s). Check the server\'s outbound connection to googleapis.com.', 'sitessaver'),
                    $err
                );
            }
            return ['success' => false, 'message' => $err];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            // The body (an error JSON) was already streamed to $staged — bin it.
            $message = self::staged_error_message($staged, $code);
            @unlink($staged);
            return ['success' => false, 'message' => $message];
        }

        if (!file_exists($staged) || filesize($staged) === 0) {
            @unlink($staged);
            return ['success' => false, 'message' => __('Download failed: empty response from Google Drive.', 'sitessaver')];
        }

        // Truncation check — a partial ZIP will fail confusingly at restore
        // time, so reject it here while we still know the expected size.
        $staged_size = (int) filesize($staged);
        if ($expected_size > 0 && $staged_size !== $expected_size) {
            @unlink($staged);
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: bytes received, 2: bytes expected */
                    __('Download from Google Drive was incomplete (got %1$s of %2$s). Please try again.', 'sitessaver'),
                    sitessaver_format_size($staged_size),
                    sitessaver_format_size($expected_size)
                ),
            ];
        }

        // Content check — must actually be a ZIP.
        $handle = @fopen($staged, 'rb');
        $magic  = $handle ? fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06") {
            @unlink($staged);
            return ['success' => false, 'message' => __('Download failed: the file from Google Drive is not a valid ZIP archive.', 'sitessaver')];
        }

        // Publish under a non-clashing name so an existing local backup with
        // the same filename is preserved.
        $dest_name = $filename;
        if (file_exists(SITESSAVER_STORAGE_DIR . '/' . $dest_name)) {
            $dest_name = pathinfo($filename, PATHINFO_FILENAME)
                . '-' . wp_generate_password(4, false, false) . '.zip';
        }
        $dest = SITESSAVER_STORAGE_DIR . '/' . $dest_name;

        if (!@rename($staged, $dest)) {
            if (!@copy($staged, $dest)) {
                @unlink($staged);
                return ['success' => false, 'message' => __('Download failed: could not write to the backups directory.', 'sitessaver')];
            }
            @unlink($staged);
        }

        return [
            'success' => true,
            'file'    => $dest_name,
            'size'    => sitessaver_format_size((int) filesize($dest)),
            'message' => __('Downloaded from Google Drive.', 'sitessaver'),
        ];
    }

    /**
     * Extract Google's error message from a JSON API response, falling back to
     * a generic HTTP-status message.
     */
    private static function api_error_message($response, int $code): string {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (is_array($body) && isset($body['error']['message'])) {
            return (string) $body['error']['message'];
        }
        /* translators: %d: HTTP status code */
        return sprintf(__('Google Drive request failed (HTTP %d).', 'sitessaver'), $code);
    }

    /**
     * Same as api_error_message() but reads the body from the staged file,
     * since stream downloads put the response on disk instead of in memory.
     */
    private static function staged_error_message(string $staged, int $code): string {
        $raw  = file_exists($staged) ? (string) @file_get_contents($staged, false, null, 0, 4096) : '';
        $body = json_decode($raw, true);
        if (is_array($body) && isset($body['error']['message'])) {
            return (string) $body['error']['message'];
        }
        /* translators: %d: HTTP status code */
        return sprintf(__('Download from Google Drive failed (HTTP %d).', 'sitessaver'), $code);
    }

    /**
     * Delete a file from Google Drive.
     */
    public static function delete(string $file_id): array {
        $token = self::get_token();
        if ($token === null) {
            return ['success' => false, 'message' => __('Google Drive not connected.', 'sitessaver')];
        }

        $response = wp_remote_request(self::API_URL . "/files/{$file_id}", [
            'method'  => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);

        // 204 No Content means success for DELETE.
        if ($code === 204 || $code === 200) {
            return ['success' => true, 'message' => __('Deleted from Google Drive.', 'sitessaver')];
        }

        return ['success' => false, 'message' => __('Delete failed.', 'sitessaver')];
    }

    /**
     * Check if Google Drive is connected.

     */
    public static function is_connected(): bool {
        $token_data = get_option('sitessaver_gdrive_token', []);
        return !empty($token_data['refresh_token']);
    }

    /**
     * Disconnect Google Drive (revoke token via proxy).
     */
    public static function disconnect(): void {
        $token_data = get_option('sitessaver_gdrive_token', []);

        if (!empty($token_data['refresh_token'])) {
            wp_remote_post(self::PROXY_URL . '/v1/gdrive/revoke', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['refresh_token' => $token_data['refresh_token']]),
                'timeout' => 10,
            ]);
        }

        delete_option('sitessaver_gdrive_token');
    }
}
