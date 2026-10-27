<?php

namespace App\Support;

use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Restaurarea unui backup (Backups::make) în baza curentă, oricare ar fi ea: rândurile din database.sqlite (backup SQLite) sau din
 * database/*.json (backup MySQL / MariaDB) înlocuiesc tabelele, într-o singură tranzacție, apoi fișierele se pun în data/files.
 * Merge și între motoare: un backup SQLite se poate restaura pe MySQL (așa se mută o echipă de pe SQLite pe MySQL).
 * Cere aceeași versiune cemainou ca backup-ul: schema trebuie să fie aceeași (o versiune mai nouă: instalezi versiunea backup-ului,
 * restaurezi, apoi actualizezi).
 */
class Restore
{
    /** ce nu se restaurează: cache-ul (stări vechi, ca „de actualizat") și sesiunile (oricum se intră din nou) */
    private const SKIP = ['cache', 'cache_locks', 'sessions'];

    /** @return array{version: string, database: string, created_at: string} */
    public static function manifest(string $zipPath): array
    {
        $zip = self::open($zipPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        if (! is_array($manifest) || ! in_array($manifest['app'] ?? null, ['cemainou', 'whatsupai'], true)) {
            throw new RuntimeException('This is not a cemainou backup (manifest.json is missing).');
        }

        return $manifest;
    }

    /** @return array{tables: int, rows: int, files: int} */
    public static function run(string $zipPath, ?string $filesDir = null): array
    {
        $manifest = self::manifest($zipPath);
        if (($manifest['version'] ?? null) !== config('cemainou.version')) {
            throw new RuntimeException("The backup is from cemainou {$manifest['version']}, this is ".config('cemainou.version').': install that version, restore, then update.');
        }

        $zip = self::open($zipPath);
        $tmp = Backups::dir().'/.restore-'.uniqid();
        File::ensureDirectoryExists($tmp, 0700);
        $tables = 0;
        $rows = 0;

        try {
            $sources = self::sources($zip, $tmp);
            $existing = Schema::getTableListing(schemaQualified: false);
            Schema::disableForeignKeyConstraints();                         // înainte de tranzacție: SQLite nu-l schimbă în una deschisă
            DB::beginTransaction();
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = ON');            // și într-o tranzacție deja deschisă: legăturile se verifică abia la sfârșit
            }
            try {
                foreach ($existing as $table) {
                    if (! in_array($table, self::SKIP, true) && isset($sources[$table])) {
                        DB::table($table)->delete();                         // delete, nu truncate: truncate închide tranzacția pe MySQL
                    }
                }
                foreach ($sources as $table => $read) {
                    if (in_array($table, self::SKIP, true) || ! in_array($table, $existing, true)) {
                        continue;
                    }
                    $columns = array_flip(Schema::getColumnListing($table));
                    $batch = [];
                    foreach ($read() as $row) {
                        $batch[] = array_intersect_key($row, $columns);
                        if (count($batch) === 500) {
                            DB::table($table)->insert($batch);
                            $rows += 500;
                            $batch = [];
                        }
                    }
                    if ($batch) {
                        DB::table($table)->insert($batch);
                        $rows += count($batch);
                    }
                    $tables++;
                }
                Updater::remember();                                         // versiunea înregistrată în sursă poate fi mai veche: e aceeași cu codul (verificat sus)
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            $files = self::files($zip, $filesDir ?? base_path('data/files'));
        } finally {
            $zip->close();
            File::deleteDirectory($tmp);
        }
        Cache::flush();

        return ['tables' => $tables, 'rows' => $rows, 'files' => $files];
    }

    private static function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;
        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open {$zipPath}.");
        }

        return $zip;
    }

    /** @return array<string, callable(): Generator<array>> fiecare tabel din backup, cu rândurile lui citite pe rând */
    private static function sources(ZipArchive $zip, string $tmp): array
    {
        if ($zip->locateName('database.sqlite') !== false) {
            $zip->extractTo($tmp, 'database.sqlite');
            $pdo = new PDO('sqlite:'.$tmp.'/database.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $names = $pdo->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

            return collect($names)->mapWithKeys(fn ($table) => [$table => function () use ($pdo, $table): Generator {
                $statement = $pdo->query('select * from "'.str_replace('"', '""', $table).'"');
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    yield $row;
                }
            }])->all();
        }

        $sources = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^database/([A-Za-z0-9_]+)\.json$#', (string) $zip->getNameIndex($i), $m)) {
                $sources[$m[1]] = fn (): Generator => self::jsonRows($zip, $m[0]);
            }
        }

        return $sources;
    }

    /** Un rând pe linie (backup-urile de la 0.1.342); cele mai vechi, un tablou pe o singură linie, se citesc întregi. */
    private static function jsonRows(ZipArchive $zip, string $name): Generator
    {
        $stream = $zip->getStream($name);
        $first = trim((string) fgets($stream));
        if ($first !== '[') {
            $rest = $first.stream_get_contents($stream);
            fclose($stream);
            foreach (json_decode($rest, true, flags: JSON_THROW_ON_ERROR) as $row) {
                yield $row;
            }

            return;
        }
        while (($line = fgets($stream)) !== false) {
            $line = rtrim(trim($line), ',');
            if ($line !== '' && $line !== ']') {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        fclose($stream);
    }

    /** Fișierele echipei înapoi în data/files; doar căi din interior (niciun „..", nicio cale absolută). */
    private static function files(ZipArchive $zip, string $dir): int
    {
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, 6);
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
                continue;
            }
            File::ensureDirectoryExists(dirname($dir.'/'.$relative), 0755);
            $in = $zip->getStream($name);
            $out = fopen($dir.'/'.$relative, 'w');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $count++;
        }

        return $count;
    }
}
