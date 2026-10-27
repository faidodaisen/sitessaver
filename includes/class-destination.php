<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * A remote place backups can be sent to (Google Drive, OneDrive, ...).
 *
 * The export pipeline, the scheduler, the restore job and the admin screens
 * talk to remotes only through this contract, so an addon can add a new one
 * by subclassing this and returning an instance from the
 * `sitessaver_destinations` filter (see Destinations::all()).
 *
 * Upload contract (the part that keeps big backups alive on short-lived
 * requests): upload_step() moves the transfer forward until $deadline and
 * returns. 'incomplete' carries an opaque $state the caller persists and
 * hands back on the next call, in a fresh request. A destination never
 * restarts a transfer from byte zero just because a request ended.
 */
abstract class Destination {

    /** Machine id: lowercase letters/digits only, e.g. 'gdrive', 'onedrive'. */
    abstract public function id(): string;

    /** Human name, e.g. 'Google Drive'. */
    abstract public function label(): string;

    /**
     * Remix icon class from the bundled subset (assets/css/icons.css).
     * tools/build-icons.php only bundles classes it finds in this plugin, so
     * icons for addon destinations are named here to keep them in the font:
     * ri-cloud-line ri-microsoft-fill ri-dropbox-fill
     */
    public function icon(): string {
        return 'ri-cloud-line';
    }

    abstract public function is_connected(): bool;

    /** URL that starts the OAuth connect flow. */
    abstract public function auth_url(): string;

    /**
     * Query arg whose presence on the Settings page means the OAuth provider
     * redirected back to us; handle_callback() is then called.
     */
    abstract public function callback_param(): string;

    /** @return array{success: bool, message: string} */
    abstract public function handle_callback(): array;

    abstract public function disconnect(): void;

    /** Link to the backup folder in the provider's web UI. */
    abstract public function folder_url(): string;

    /**
     * One slice of a resumable upload.
     *
     * @param array<string, mixed> $state Empty on the first call.
     * @return array{status: 'complete'|'incomplete'|'failed', state: array, message: string, progress: int}
     */
    abstract public function upload_step(string $file_path, string $filename, string $job_id, array $state, ?int $deadline): array;

    /**
     * Backups in the remote folder.
     *
     * Each file: id, name, size (formatted), size_bytes, created, created_h,
     * own (name carries this site's backup prefix).
     *
     * @return array{files: list<array<string, mixed>>, connected: bool, error?: string}
     */
    abstract public function list_files(): array;

    /**
     * Download into local backup storage.
     *
     * Must stage into the temp dir and only publish a validated ZIP, never
     * overwrite an existing local backup with a failed download.
     *
     * @return array{success: bool, message?: string, file?: string, path?: string}
     */
    abstract public function download(string $file_id): array;

    /** @return array{success: bool, message: string} */
    abstract public function delete(string $file_id): array;

    /** One-line description for the Settings card. */
    public function description(): string {
        return sprintf(
            /* translators: %s: provider name */
            __('Connect %s to keep a copy of every backup off this server.', 'sitessaver'),
            $this->label()
        );
    }

    /**
     * Upload to completion with no deadline (WP-Cron / CLI / manual button).
     *
     * @return array{success: bool, message: string}
     */
    public function upload(string $file_path, string $filename, string $job_id = ''): array {
        $state = [];
        while (true) {
            $res = $this->upload_step($file_path, $filename, $job_id, $state, null);
            if ($res['status'] === 'complete') {
                return [
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %s: provider name */
                        __('Backup uploaded to %s.', 'sitessaver'),
                        $this->label()
                    ),
                ];
            }
            if ($res['status'] === 'failed') {
                return ['success' => false, 'message' => $res['message']];
            }
            $state = $res['state'];
        }
    }

    /** Transient that carries live upload progress for a job. */
    protected function progress_key(string $job_id): string {
        return 'sitessaver_remote_job_' . $this->id() . '_' . $job_id;
    }

    /**
     * Record upload progress so the admin UI can poll it.
     *
     * @param array<string, mixed> $data progress (0-100), status, sent, total
     */
    protected function set_progress(string $job_id, array $data): void {
        if ($job_id === '') {
            return;
        }
        set_transient($this->progress_key($job_id), $data, HOUR_IN_SECONDS);
    }

    /** @return array<string, mixed> */
    public function upload_progress(string $job_id): array {
        $status = get_transient($this->progress_key($job_id));
        return is_array($status) ? $status : ['progress' => 0, 'status' => 'waiting'];
    }

    /** Shape sent to the admin JS (no secrets). */
    public function to_client(): array {
        return [
            'id'         => $this->id(),
            'label'      => $this->label(),
            'icon'       => $this->icon(),
            'connected'  => $this->is_connected(),
            'folder_url' => $this->is_connected() ? $this->folder_url() : '',
        ];
    }
}
