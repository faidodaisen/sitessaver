<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * File index and backup chains — the machinery behind incremental backups.
 *
 * Every backup carries a COMPLETE index of the site's files at the moment it
 * ran: `relative path => [mtime, size]`. Not just the files inside that ZIP.
 * That single decision is what keeps the rest simple — the baseline for the
 * next incremental is just "the parent's index", so nothing has to merge a
 * stack of deltas together before it can answer "did this file change".
 *
 * See PLAN-incremental-backup.md for the full design.
 */
final class Index {

    /** Index copy carried inside the backup ZIP (gzipped JSON). */
    public const ARCHIVE_ENTRY = 'fileindex.json.gz';

    /** Subdirectory of the storage dir holding the local index cache. */
    public const CACHE_DIR = '.sitessaver-index';

    /** Option holding chain membership, keyed by chain id. */
    public const CHAINS_OPTION = 'sitessaver_backup_chains';

    /** Option holding the id of the chain a new incremental would extend. */
    public const CURRENT_CHAIN_OPTION = 'sitessaver_current_chain';

    /** Default number of runs before a fresh full backup is forced. */
    public const DEFAULT_FULL_EVERY = 7;

    /**
     * Once the incrementals in a chain total more than this share of the
     * full backup's size, the chain has stopped paying for itself and the
     * next run starts over with a full.
     */
    private const GROWTH_LIMIT = 0.5;

    /**
     * Archive areas and the index path prefix each one owns.
     *
     * The prefixes are what scopes a deletion scan: if a run did not include
     * themes, themes must not be reported as deleted just because they are
     * absent from this run's index.
     *
     * @return array<string, string>
     */
    public static function area_prefixes(): array {
        return [
            'uploads'    => 'wp-content/uploads/',
            'plugins'    => 'wp-content/plugins/',
            'themes'     => 'wp-content/themes/',
            'mu-plugins' => 'wp-content/mu-plugins/',
        ];
    }

    // -----------------------------------------------------------------
    // Index entries
    // -----------------------------------------------------------------

    /**
     * Build the index entry for one file.
     *
     * @param string $detection fast|thorough
     * @return list<int|string> [mtime, size] or [mtime, size, crc32]
     */
    public static function entry_for(string $path, string $detection = 'fast'): array {
        $mtime = (int) @filemtime($path);
        $size  = (int) @filesize($path);

        if ($detection !== 'thorough') {
            return [$mtime, $size];
        }

        // crc32b, not a cryptographic hash: this detects change, it does not
        // defend against a crafted collision, and it is several times faster
        // than sha256 over a media library.
        $hash = @hash_file('crc32b', $path);

        return [$mtime, $size, is_string($hash) ? $hash : ''];
    }

    /**
     * Decide whether a file needs to go into an incremental archive.
     *
     * fast     — size differs OR mtime differs (rsync's default rule).
     * thorough — contents differ, mtime ignored entirely. For hosts where
     *            mtime is rewritten wholesale by a deploy or a restore and
     *            `fast` would re-archive the entire site every run.
     *
     * Unknown to the parent index means new, which means changed.
     *
     * @param list<int|string>      $entry  Entry built for the live file.
     * @param array<string, mixed>  $parent Parent index.
     */
    public static function is_changed(string $relative, array $entry, array $parent, string $detection = 'fast'): bool {
        $was = $parent[$relative] ?? null;

        if (!is_array($was)) {
            return true;
        }

        if ($detection === 'thorough') {
            $now_hash = (string) ($entry[2] ?? '');
            $old_hash = (string) ($was[2] ?? '');

            // An older index taken in fast mode has no hash to compare
            // against. Fall back to size+mtime for that one run rather than
            // declaring the whole site changed.
            if ($now_hash === '' || $old_hash === '') {
                return ((int) ($was[1] ?? -1)) !== ((int) ($entry[1] ?? -2))
                    || ((int) ($was[0] ?? -1)) !== ((int) ($entry[0] ?? -2));
            }

            return $now_hash !== $old_hash;
        }

        return ((int) ($was[1] ?? -1)) !== ((int) ($entry[1] ?? -2))
            || ((int) ($was[0] ?? -1)) !== ((int) ($entry[0] ?? -2));
    }

    /**
     * Paths present in the parent index but gone from the current one.
     *
     * Scoped to the prefixes actually scanned this run — an area that was
     * switched off did not "delete" anything, it simply was not looked at.
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $current
     * @param list<string>         $prefixes
     * @return list<string>
     */
    public static function deleted_paths(array $parent, array $current, array $prefixes): array {
        if ($prefixes === []) {
            return [];
        }

        $gone = [];

        foreach ($parent as $relative => $_entry) {
            if (isset($current[$relative])) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $relative, $prefix)) {
                    $gone[] = (string) $relative;
                    break;
                }
            }
        }

        return $gone;
    }

    // -----------------------------------------------------------------
    // Index cache on disk
    // -----------------------------------------------------------------

    public static function cache_dir(): string {
        $dir = sitessaver_storage_dir() . '/' . self::CACHE_DIR;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            sitessaver_protect_directory($dir);
        }

        return $dir;
    }

    public static function cache_path(string $backup_file): string {
        return self::cache_dir() . '/' . sanitize_file_name($backup_file) . '.json.gz';
    }

    /**
     * @param array<string, mixed> $index
     */
    public static function save(string $backup_file, array $index): bool {
        $json = wp_json_encode($index, JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return false;
        }

        $gz = gzencode($json, 6);
        if ($gz === false) {
            return false;
        }

        return file_put_contents(self::cache_path($backup_file), $gz) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function load(string $backup_file): ?array {
        $path = self::cache_path($backup_file);

        if (!is_readable($path)) {
            return null;
        }

        $gz = file_get_contents($path);
        if ($gz === false) {
            return null;
        }

        return self::decode($gz);
    }

    public static function forget(string $backup_file): void {
        $path = self::cache_path($backup_file);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Decode a gzipped index payload lifted out of a ZIP.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $gz): ?array {
        // Check the gzip magic before decoding. `@gzdecode()` on a payload
        // that is not gzip still raises a warning under a custom error
        // handler (`@` suppresses display, not the handler), and a ZIP entry
        // truncated by a failed upload is a realistic input here.
        if (strlen($gz) < 2 || substr($gz, 0, 2) !== "\x1f\x8b") {
            return null;
        }

        $json = @gzdecode($gz);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    // -----------------------------------------------------------------
    // Chains
    // -----------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function chains(): array {
        $chains = get_option(self::CHAINS_OPTION, []);

        return is_array($chains) ? $chains : [];
    }

    /**
     * @param array<string, array<string, mixed>> $chains
     */
    private static function save_chains(array $chains): void {
        update_option(self::CHAINS_OPTION, $chains, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function chain(string $chain_id): ?array {
        $chains = self::chains();

        return isset($chains[$chain_id]) && is_array($chains[$chain_id]) ? $chains[$chain_id] : null;
    }

    /**
     * The chain a given backup file belongs to, if any.
     *
     * @return array{id: string, chain: array<string, mixed>}|null
     */
    public static function chain_of(string $backup_file): ?array {
        foreach (self::chains() as $id => $chain) {
            $members = is_array($chain['members'] ?? null) ? $chain['members'] : [];
            if (in_array($backup_file, $members, true)) {
                return ['id' => (string) $id, 'chain' => $chain];
            }
        }

        return null;
    }

    /**
     * Ordered chain members up to and including the given backup.
     *
     * This is the restore set: extract these in order and you have the site
     * as it stood when $backup_file was taken.
     *
     * @return list<string>
     */
    public static function restore_set(string $backup_file): array {
        $found = self::chain_of($backup_file);

        if ($found === null) {
            return [$backup_file];
        }

        $members = is_array($found['chain']['members'] ?? null) ? $found['chain']['members'] : [];
        $at      = array_search($backup_file, $members, true);

        if ($at === false) {
            return [$backup_file];
        }

        return array_values(array_slice($members, 0, ((int) $at) + 1));
    }

    /**
     * Decide what kind of backup the next run should produce.
     *
     * Falls back to a full backup whenever a chain cannot be safely
     * extended — a broken or missing parent must degrade to a slightly
     * larger backup, never to a failed one.
     *
     * @param array<string, mixed> $options
     * @return array{type: string, chain_id: string, seq: int, parent: string, full: string, detection: string, reason: string}
     */
    public static function plan(array $options): array {
        $detection = ($options['change_detection'] ?? 'fast') === 'thorough' ? 'thorough' : 'fast';

        $fresh = [
            'type'      => 'full',
            'chain_id'  => 'c' . wp_generate_password(10, false, false),
            'seq'       => 0,
            'parent'    => '',
            'full'      => '',
            'detection' => $detection,
            'reason'    => '',
        ];

        if (($options['backup_mode'] ?? 'full') !== 'incremental') {
            $fresh['reason'] = 'mode_full';
            return $fresh;
        }

        $chain_id = (string) get_option(self::CURRENT_CHAIN_OPTION, '');
        $chain    = $chain_id !== '' ? self::chain($chain_id) : null;

        if ($chain === null) {
            $fresh['reason'] = 'no_chain';
            return $fresh;
        }

        $members = is_array($chain['members'] ?? null) ? array_values($chain['members']) : [];
        if ($members === []) {
            $fresh['reason'] = 'empty_chain';
            return $fresh;
        }

        $full_every = (int) ($options['full_every'] ?? self::DEFAULT_FULL_EVERY);
        $full_every = max(2, min(30, $full_every));

        if (count($members) >= $full_every) {
            $fresh['reason'] = 'chain_length';
            return $fresh;
        }

        $full_size  = (int) ($chain['full_size'] ?? 0);
        $inc_bytes  = (int) ($chain['inc_bytes'] ?? 0);
        if ($full_size > 0 && $inc_bytes > (int) ($full_size * self::GROWTH_LIMIT)) {
            $fresh['reason'] = 'growth_limit';
            return $fresh;
        }

        // Every member has to still be on disk, or the chain cannot be
        // restored and there is no point adding to it. Checked here rather
        // than trusted from the option, because retention, a manual delete,
        // or a gdrive-only destination can all remove a file behind our back.
        $storage = sitessaver_storage_dir();
        foreach ($members as $member) {
            if (!is_readable($storage . '/' . $member)) {
                $fresh['reason'] = 'member_missing';
                return $fresh;
            }
        }

        $parent = (string) end($members);
        if (self::load($parent) === null) {
            $fresh['reason'] = 'no_parent_index';
            return $fresh;
        }

        return [
            'type'      => 'incremental',
            'chain_id'  => $chain_id,
            'seq'       => count($members),
            'parent'    => $parent,
            'full'      => (string) ($chain['full'] ?? $members[0]),
            'detection' => $detection,
            'reason'    => '',
        ];
    }

    /**
     * Record a finished backup as a member of its chain.
     *
     * @param array{type: string, chain_id: string, seq: int, full: string} $plan
     */
    public static function record(array $plan, string $backup_file, int $size): void {
        $chains   = self::chains();
        $chain_id = (string) $plan['chain_id'];

        if (($plan['type'] ?? 'full') === 'full' || !isset($chains[$chain_id])) {
            $chains[$chain_id] = [
                'full'       => $backup_file,
                'members'    => [$backup_file],
                'created'    => time(),
                'full_size'  => $size,
                'inc_bytes'  => 0,
            ];
        } else {
            $chain              = $chains[$chain_id];
            $members            = is_array($chain['members'] ?? null) ? $chain['members'] : [];
            $members[]          = $backup_file;
            $chain['members']   = array_values(array_unique($members));
            $chain['inc_bytes'] = (int) ($chain['inc_bytes'] ?? 0) + $size;
            $chains[$chain_id]  = $chain;
        }

        // Cap the stored history. Retention deletes chains long before this
        // matters, but an option that only ever grows is a slow leak.
        if (count($chains) > 200) {
            uasort($chains, static fn($a, $b) => ((int) ($b['created'] ?? 0)) <=> ((int) ($a['created'] ?? 0)));
            $chains = array_slice($chains, 0, 200, true);
        }

        self::save_chains($chains);
        update_option(self::CURRENT_CHAIN_OPTION, $chain_id, false);
    }

    /**
     * Drop a backup from its chain, and the chain itself once it is empty.
     *
     * Deleting a chain member in the middle breaks every later member, so
     * the whole tail goes with it. Silent half-broken chains are exactly the
     * failure mode this feature has to avoid.
     *
     * @return list<string> Other backups removed from the chain as a result.
     */
    public static function drop(string $backup_file): array {
        $found = self::chain_of($backup_file);
        self::forget($backup_file);

        if ($found === null) {
            return [];
        }

        $chain_id = $found['id'];
        $chains   = self::chains();
        $members  = is_array($chains[$chain_id]['members'] ?? null) ? array_values($chains[$chain_id]['members']) : [];
        $at       = array_search($backup_file, $members, true);

        if ($at === false) {
            return [];
        }

        // Everything after the removed member is now unrestorable.
        $orphaned = array_values(array_slice($members, ((int) $at) + 1));
        $kept     = array_values(array_slice($members, 0, (int) $at));

        foreach ($orphaned as $orphan) {
            self::forget($orphan);
        }

        if ($kept === []) {
            unset($chains[$chain_id]);
            if ((string) get_option(self::CURRENT_CHAIN_OPTION, '') === $chain_id) {
                delete_option(self::CURRENT_CHAIN_OPTION);
            }
        } else {
            $chains[$chain_id]['members'] = $kept;
        }

        self::save_chains($chains);

        return $orphaned;
    }

    /**
     * Summarise a backup's place in its chain, for the Backups list.
     *
     * Returns 'standalone' for anything the chain records know nothing
     * about — every manual export, and every backup made before 1.4.0. Those
     * are complete on their own and the UI should not decorate them.
     *
     * @return array{type: string, restore_count: int, followers: int, chain_id: string}
     */
    public static function describe(string $backup_file): array {
        $none = ['type' => 'standalone', 'restore_count' => 1, 'followers' => 0, 'chain_id' => ''];

        $found = self::chain_of($backup_file);
        if ($found === null) {
            return $none;
        }

        $chain = $found['chain'];

        $members = array_values(array_filter(
            is_array($chain['members'] ?? null) ? $chain['members'] : [],
            'is_string'
        ));

        $position = array_search($backup_file, $members, true);
        if ($position === false) {
            return $none;
        }

        // A chain of one is a full backup nothing was ever built on, which is
        // indistinguishable from a standalone backup as far as the user is
        // concerned — so present it as one rather than as a lonely "Full".
        if (count($members) === 1) {
            return $none;
        }

        return [
            // Position 0 is the full backup at the head of the chain.
            'type'          => $position === 0 ? 'full' : 'incremental',
            // Restoring member N needs members 0..N, so N+1 files.
            'restore_count' => $position + 1,
            // How many later backups would be stranded by deleting this one.
            'followers'     => count($members) - $position - 1,
            'chain_id'      => (string) $found['id'],
        ];
    }

    /**
     * Group backups into restore points, newest first.
     *
     * A restore point is a full backup plus every incremental descended from
     * it. A standalone full — a manual export, an imported ZIP — is a
     * restore point of one. This is the unit retention counts, because
     * counting files would happily delete the full at the head of a chain
     * and leave a pile of incrementals that restore to nothing.
     *
     * @param list<array<string, mixed>> $backups Output of sitessaver_get_backups().
     * @return list<array{key: string, files: list<string>, created: int}>
     */
    public static function restore_points(array $backups): array {
        $chains = self::chains();

        // file => chain id, for the chains we know about.
        $membership = [];
        foreach ($chains as $id => $chain) {
            foreach ((array) ($chain['members'] ?? []) as $member) {
                $membership[(string) $member] = (string) $id;
            }
        }

        $points = [];

        foreach ($backups as $backup) {
            $file    = (string) ($backup['file'] ?? '');
            $created = (int) ($backup['created'] ?? 0);

            if ($file === '') {
                continue;
            }

            $key = $membership[$file] ?? ('solo:' . $file);

            if (!isset($points[$key])) {
                $points[$key] = ['key' => $key, 'files' => [], 'created' => $created];
            }

            $points[$key]['files'][] = $file;
            // A chain is as recent as its newest member: a chain still being
            // extended must not age out while it is in active use.
            $points[$key]['created'] = max($points[$key]['created'], $created);
        }

        $points = array_values($points);
        usort($points, static fn($a, $b) => $b['created'] <=> $a['created']);

        return $points;
    }
}
