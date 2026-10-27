<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Google Drive as a Destination. A thin adapter over the static GDrive
 * client, so the 1.5.x Drive code paths and options stay exactly as they
 * were.
 */
final class GDrive_Destination extends Destination {

    public function id(): string {
        return 'gdrive';
    }

    public function label(): string {
        return __('Google Drive', 'sitessaver');
    }

    public function icon(): string {
        return 'ri-google-line';
    }

    public function is_connected(): bool {
        return GDrive::is_connected();
    }

    public function auth_url(): string {
        return GDrive::get_auth_url();
    }

    public function callback_param(): string {
        return 'sitessaver_gdrive_token';
    }

    public function handle_callback(): array {
        return GDrive::handle_callback();
    }

    public function disconnect(): void {
        GDrive::disconnect();
    }

    public function folder_url(): string {
        return GDrive::get_folder_url();
    }

    public function upload_step(string $file_path, string $filename, string $job_id, array $state, ?int $deadline): array {
        return GDrive::upload_step($file_path, $filename, $job_id, $state, $deadline);
    }

    public function upload(string $file_path, string $filename, string $job_id = ''): array {
        return GDrive::upload($file_path, $filename, $job_id);
    }

    /** GDrive writes its own progress transient (sitessaver_gdrive_job_*). */
    public function upload_progress(string $job_id): array {
        $status = get_transient('sitessaver_gdrive_job_' . $job_id);
        return is_array($status) ? $status : ['progress' => 0, 'status' => 'waiting'];
    }

    public function list_files(): array {
        return GDrive::list_files();
    }

    public function download(string $file_id): array {
        return GDrive::download($file_id);
    }

    public function delete(string $file_id): array {
        return GDrive::delete($file_id);
    }
}
