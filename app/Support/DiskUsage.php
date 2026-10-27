<?php

namespace App\Support;

use App\Models\Attachment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Spațiul ocupat pe server de cemainou, pe părți (fișierele echipei, copiile de siguranță, baza de date, copiile vechi ale codului, jurnalele,
 * cache-ul, codul), ca adminul să vadă ce consumă și ce se poate elibera. Măsurarea parcurge folderele: se ține minte 10 minute.
 */
class DiskUsage
{
    public const CACHE_KEY = 'cemainou.disk-usage';

    /** Ce s-a măsurat ultima dată, fără să măsoare (pagina se deschide pe loc; dacă lipsește, măsoară după ce s-a deschis). */
    public static function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * O singură trecere prin folderul aplicației (înainte erau trei: fiecare parte, apoi tot, apoi data/ - zeci de mii de fișiere de trei ori);
     * fiecare fișier merge la partea lui după cale. Se ține minte o oră; „Măsoară din nou" și ștergerile o refac.
     *
     * @return array{areas: list<array<string, mixed>>, app: int, data: int, disk_free: ?int, disk_total: ?int, measured_at: string}
     */
    public static function measure(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $base = rtrim(base_path(), '/').'/';
            $database = self::databaseFiles();
            $backups = rtrim(Backups::dir(), '/').'/';
            // de la cel mai adânc la cel mai puțin adânc: primul prefix care se potrivește câștigă
            $prefixes = [
                $base.'data/files/feedback/' => 'feedback', $base.'data/files/branding/' => 'feedback', $base.'data/files/' => 'files',
                $backups => 'backups', $base.'data/updates/' => 'updates', $base.'storage/logs/' => 'logs',
                $base.'storage/framework/' => 'cache', $base.'storage/app/' => 'cache',
            ];
            $sizes = array_fill_keys(['files', 'backups', 'database', 'updates', 'logs', 'cache', 'feedback', 'code'], 0);
            [$app, $data] = [0, 0];
            foreach (self::files(rtrim($base, '/')) as $path => $bytes) {
                $app += $bytes;
                if (str_starts_with($path, $base.'data/')) {
                    $data += $bytes;
                }
                $area = isset($database[$path]) ? 'database' : 'code';
                if ($area === 'code') {
                    foreach ($prefixes as $prefix => $key) {
                        if (str_starts_with($path, $prefix)) {
                            $area = $key;
                            break;
                        }
                    }
                }
                $sizes[$area] += $bytes;
            }
            if (! str_starts_with($backups, $base)) {                                  // backup-urile în altă parte (backup_path): măsurate separat
                $sizes['backups'] = self::size($backups);
            }
            $outside = array_filter(array_keys($database), fn (string $f) => ! str_starts_with($f, $base));
            $sizes['database'] += array_sum(array_map(fn (string $f) => self::size($f), $outside));

            return [
                'areas' => [
                    ['key' => 'files', 'size' => $sizes['files'], 'count' => Attachment::where('kind', 'file')->count()],
                    ['key' => 'backups', 'size' => $sizes['backups'], 'count' => count(Backups::all())],
                    ['key' => 'database', 'size' => $database === [] ? null : $sizes['database']],
                    ['key' => 'updates', 'size' => $sizes['updates'], 'count' => count(glob(base_path('data/updates/previous-*'), GLOB_ONLYDIR) ?: [])],
                    ['key' => 'logs', 'size' => $sizes['logs'], 'count' => count(glob(storage_path('logs/*.log')) ?: [])],
                    ['key' => 'cache', 'size' => $sizes['cache']],
                    ['key' => 'feedback', 'size' => $sizes['feedback']],
                    ['key' => 'code', 'size' => $sizes['code']],                       // restul: codul aplicației (vendor, public, app)
                ],
                'app' => $app, 'data' => $data,
                'disk_free' => self::safe(fn () => disk_free_space($base)), 'disk_total' => self::safe(fn () => disk_total_space($base)),
                'measured_at' => now()->toIso8601String(),
            ];
        });
    }

    /** Fișierele de sub un folder, cale => octeți; legăturile simbolice nu se urmează. @return \Generator<string, int> */
    private static function files(string $dir): \Generator
    {
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    yield $file->getPathname() => $file->getSize();
                }
            }
        } catch (Throwable) {
            // un folder fără drept de citire: se socotește ce s-a putut
        }
    }

    /** Mărimea unui folder (sau fișier), în octeți; 0 dacă lipsește. Legăturile simbolice nu se urmează. */
    public static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        if (! is_dir($path)) {
            return 0;
        }
        $total = 0;
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $total += $file->getSize();
                }
            }
        } catch (Throwable) {
            // un folder fără drept de citire: se socotește ce s-a putut
        }

        return $total;
    }

    /** Fișierele bazei SQLite cu jurnalele ei (-wal, -shm), cale => true; gol pentru MySQL / PostgreSQL (stă pe serverul bazei, nu aici). @return array<string, true> */
    private static function databaseFiles(): array
    {
        if (config('database.default') !== 'sqlite') {
            return [];
        }
        $file = (string) config('database.connections.sqlite.database');

        return array_fill_keys(array_filter([$file, "{$file}-wal", "{$file}-shm"], 'is_file'), true) ?: [$file => true];
    }

    private static function safe(callable $fn): ?int
    {
        try {
            $value = $fn();

            return $value === false ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /** Copiile vechi ale codului, păstrate la o actualizare pentru o revenire de mână (data/updates/previous-*). @return int octeții eliberați */
    public static function deleteOldCode(): int
    {
        $freed = 0;
        foreach (glob(base_path('data/updates/previous-*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $freed += self::size($dir);
            File::deleteDirectory($dir);
        }
        Cache::forget(self::CACHE_KEY);

        return $freed;
    }

    /** Copiile vechi ale codului mai vechi de $days zile (după data înlocuirii, din .replaced_at, sau a folderului): la curățenia zilnică. @return int octeții eliberați */
    public static function pruneOldCode(int $days = 7): int
    {
        $freed = 0;
        foreach (glob(base_path('data/updates/previous-*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $at = is_file("{$dir}/.replaced_at") ? strtotime((string) file_get_contents("{$dir}/.replaced_at")) : filemtime($dir);
            if ($at && $at < now()->subDays($days)->getTimestamp()) {
                $freed += self::size($dir);
                File::deleteDirectory($dir);
            }
        }
        if ($freed) {
            Cache::forget(self::CACHE_KEY);
        }

        return $freed;
    }

    /** Jurnalele aplicației, în afară de cel de azi. @return int octeții eliberați */
    public static function deleteOldLogs(): int
    {
        $freed = 0;
        $today = 'laravel-'.now()->toDateString().'.log';
        foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
            if (basename($file) !== $today && basename($file) !== 'laravel.log') {
                $freed += (int) filesize($file);
                @unlink($file);
            }
        }
        Cache::forget(self::CACHE_KEY);

        return $freed;
    }

    /** Copiile de siguranță, în afară de cele mai noi $keep. @return int octeții eliberați */
    public static function pruneBackups(int $keep): int
    {
        $before = self::size(Backups::dir());
        Backups::prune(max(1, $keep));
        Cache::forget(self::CACHE_KEY);

        return max(0, $before - self::size(Backups::dir()));
    }
}
