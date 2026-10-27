<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Anunțul versiunilor noi, din lista publică de pe rt.md (cemainou.release_feed): cea mai nouă versiune, mai nouă decât cea de acum, care merge
 * pe PHP-ul de aici. Lista se citește o dată pe zi (curățenia) și când adminul deschide actualizarea; o eroare de rețea nu strică nimic, doar nu
 * află. Lista doar anunță: arhiva o descarcă și o încarcă adminul, iar încrederea vine din semnătura din arhivă (ReleaseManifest).
 */
class Releases
{
    private const CACHE = 'cemainou.releases.latest';

    /** Cea mai nouă versiune aflată (din cache), dacă e mai nouă decât codul de acum. */
    public static function available(): ?array
    {
        $release = Cache::get(self::CACHE);

        return is_array($release) && version_compare($release['version'], config('cemainou.version'), '>') ? $release : null;
    }

    /** Citește lista acum (dacă verificarea e pornită în Setări). */
    public static function refresh(): ?array
    {
        if (! Settings::get('update_check') || ! config('cemainou.release_feed')) {
            Cache::forget(self::CACHE);

            return null;
        }
        try {
            $feed = Http::timeout(8)->withUserAgent('cemainou/'.config('cemainou.version'))->acceptJson()->get(config('cemainou.release_feed'))->throw()->json();
        } catch (Throwable) {
            return self::available();
        }
        ($release = self::newest((array) ($feed['releases'] ?? []))) ? Cache::forever(self::CACHE, $release) : Cache::forget(self::CACHE);

        return self::available();
    }

    /** Din listă: cea mai nouă versiune bine formată, mai nouă decât cea de acum, care merge pe acest PHP. */
    public static function newest(array $releases): ?array
    {
        return collect($releases)
            ->filter(fn ($r) => is_array($r) && is_string($r['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $r['version'])
                && is_string($r['url'] ?? null) && str_starts_with($r['url'], 'https://'))
            ->filter(fn (array $r) => version_compare($r['version'], config('cemainou.version'), '>') && version_compare(PHP_VERSION, (string) ($r['php'] ?? '8.4'), '>='))
            ->sort(fn (array $a, array $b) => version_compare($b['version'], $a['version']))
            ->map(fn (array $r) => array_intersect_key($r, array_flip(['version', 'date', 'url', 'size', 'php', 'notes'])))
            ->first();
    }
}
