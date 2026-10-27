<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Registry of remote backup destinations.
 *
 * Google Drive is built in. Addons register more through the
 * `sitessaver_destinations` filter, from their `sitessaver_loaded` callback
 * or any time before admin pages render:
 *
 *     add_filter('sitessaver_destinations', function (array $list) {
 *         $list['onedrive'] = new My_OneDrive_Destination();
 *         return $list;
 *     });
 *
 * Export destination strings (Export screen radio, `export_destination`
 * option): 'local', '<id>[,<id>]' (remote only) or '<id>[,<id>]+local'.
 * The 1.5.x values 'gdrive' and 'both' keep their meaning.
 */
final class Destinations {

    /** @var array<string, Destination>|null */
    private static ?array $cache = null;

    /** @return array<string, Destination> */
    public static function all(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $list = ['gdrive' => new GDrive_Destination()];

        /** @var mixed $filtered */
        $filtered = apply_filters('sitessaver_destinations', $list);

        $clean = [];
        foreach ((array) $filtered as $dest) {
            if ($dest instanceof Destination && preg_match('/^[a-z0-9]{2,20}$/', $dest->id())) {
                $clean[$dest->id()] = $dest;
            }
        }
        // Google Drive cannot be filtered out by accident.
        $clean += ['gdrive' => $list['gdrive']];

        return self::$cache = $clean;
    }

    /** Drop the cache (tests, or after an addon registers late). */
    public static function reset(): void {
        self::$cache = null;
    }

    public static function get(string $id): ?Destination {
        return self::all()[$id] ?? null;
    }

    /** @return array<string, Destination> Destinations other than Google Drive. */
    public static function extra(): array {
        $all = self::all();
        unset($all['gdrive']);
        return $all;
    }

    /**
     * Split an export destination string.
     *
     * Grammar: 'local' | '<id>[,<id>...][+local]'. 'both' (1.5.x) is
     * 'gdrive+local'. Unknown ids are dropped; with no remote left the
     * backup stays local, so a deactivated addon never costs a backup.
     *
     * @return array{remote: string, remotes: list<string>, keep_local: bool}
     */
    public static function parse(string $value): array {
        if ($value === 'both') {
            $value = 'gdrive+local';
        }
        $keep = str_ends_with($value, '+local');
        $ids  = $keep ? substr($value, 0, -6) : $value;

        $remotes = [];
        foreach (explode(',', $ids) as $id) {
            $id = trim($id);
            if ($id !== '' && $id !== 'local' && self::get($id) !== null && !in_array($id, $remotes, true)) {
                $remotes[] = $id;
            }
        }

        if ($remotes === []) {
            return ['remote' => '', 'remotes' => [], 'keep_local' => true];
        }
        return ['remote' => $remotes[0], 'remotes' => $remotes, 'keep_local' => $keep];
    }

    /** Build a destination string from parts (inverse of parse()). */
    public static function build(array $remotes, bool $keep_local): string {
        $remotes = array_values(array_filter($remotes, static fn ($id): bool => is_string($id) && self::get($id) !== null));
        if ($remotes === []) {
            return 'local';
        }
        return implode(',', $remotes) . ($keep_local ? '+local' : '');
    }

    /** Whether a destination string names a known value. */
    public static function is_valid(string $value): bool {
        return $value === 'local' || self::parse($value)['remote'] !== '';
    }

    /** @return list<array<string, mixed>> For wp_localize_script. */
    public static function to_client(): array {
        return array_values(array_map(static fn (Destination $d): array => $d->to_client(), self::all()));
    }
}
