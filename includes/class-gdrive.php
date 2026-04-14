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

    /**
     * Get the OAuth authorization URL (points to proxy).
     * Proxy handles Google OAuth and redirects back with refresh_token.
     */
    public static function get_auth_url(): string {
        $callback_url = admin_url('admin.php?page=sitessaver-settings');

        return self::PROXY_URL . '/v1/gdrive/authorize?' . http_build_query([
            'callback_url' => $callback_url,
        ]);
    }

    /**
     * Handle the OAuth callback from proxy.
     * Called when proxy redirects back with ?sitessaver_gdrive_token=xxx
     */
    public static function handle_callback(): array {
        $refresh_token = sanitize_text_field(wp_unslash($_GET['sitessaver_gdrive_token'] ?? ''));
        $status        = sanitize_text_field(wp_unslash($_GET['sitessaver_gdrive_status'] ?? ''));

        if ($refresh_token === '' || $status !== 'connected') {
            return ['success' => false, 'message' => __('Google Drive connection failed.', 'sitessaver')];
        }

        // Get initial access token via proxy.
        $refreshed = self::refresh_token($refresh_token);

        if ($refreshed === null) {
            return ['success' => false, 'message' => __('Failed to obtain access token.', 'sitessaver')];
        }

        return ['success' => true, 'message' => __('Google Drive connected.', 'sitessaver')];
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
            update_option('sitessaver_gdrive_token', $token_data);

            return $token_data;
        }

        return null;
    }

    /**
     * Upload a file to Google Drive (direct to Google API).
     */
    public static function upload(string $file_path, string $filename): array {
        @set_time_limit(0);
        wp_raise_memory_limit('admin');

        $token = self::get_token();
        if ($token === null) {
            return ['success' => false, 'message' => __('Google Drive not connected.', 'sitessaver')];
        }

        $settings  = self::settings();
        $folder_id = $settings['gdrive_folder_id'] ?? '';

        $metadata = ['name' => $filename];

        if (!empty($folder_id)) {
            $metadata['parents'] = [$folder_id];
        }

        $boundary  = wp_generate_password(24, false);
        
        // Attempt to read file. Large files may hit memory limits here.
        $file_data = @file_get_contents($file_path);

        if ($file_data === false) {
            return [
                'success' => false, 
                'message' => __('Cannot read backup file. It may be too large for server memory limits.', 'sitessaver')
            ];
        }


        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= wp_json_encode($metadata) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/zip\r\n\r\n";
        $body .= $file_data . "\r\n";
        $body .= "--{$boundary}--";

        $response = wp_remote_post(self::UPLOAD_URL . '/files?uploadType=multipart', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ],
            'body'    => $body,
            'timeout' => 300,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($result['id'])) {
            return [
                'success' => true,
                'file_id' => $result['id'],
                'message' => __('Uploaded to Google Drive.', 'sitessaver'),
            ];
        }

        return [
            'success' => false,
            'message' => $result['error']['message'] ?? __('Upload failed.', 'sitessaver'),
        ];
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
            $query .= " and '{$folder_id}' in parents";
        }

        $response = wp_remote_get(self::API_URL . '/files?' . http_build_query([
            'q'        => $query,
            'fields'   => 'files(id,name,size,createdTime)',
            'orderBy'  => 'createdTime desc',
            'pageSize' => 50,
        ]), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        if (is_wp_error($response)) {
            return ['files' => [], 'connected' => true, 'error' => $response->get_error_message()];
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
     */
    public static function download(string $file_id): array {
        $token = self::get_token();
        if ($token === null) {
            return ['success' => false, 'message' => __('Google Drive not connected.', 'sitessaver')];
        }

        // Get file metadata.
        $meta_response = wp_remote_get(self::API_URL . "/files/{$file_id}?fields=name,size", [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        if (is_wp_error($meta_response)) {
            return ['success' => false, 'message' => $meta_response->get_error_message()];
        }

        $meta     = json_decode(wp_remote_retrieve_body($meta_response), true);
        $filename = sanitize_file_name($meta['name'] ?? 'backup.zip');
        $dest     = SITESSAVER_STORAGE_DIR . '/' . $filename;

        // Download file content.
        $response = wp_remote_get(self::API_URL . "/files/{$file_id}?alt=media", [
            'headers'  => ['Authorization' => 'Bearer ' . $token],
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $dest,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        if (file_exists($dest)) {
            return [
                'success' => true,
                'file'    => $filename,
                'size'    => sitessaver_format_size((int) filesize($dest)),
                'message' => __('Downloaded from Google Drive.', 'sitessaver'),
            ];
        }

        return ['success' => false, 'message' => __('Download failed.', 'sitessaver')];
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
