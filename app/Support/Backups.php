<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Backup-ul echipei: o arhivă în data/backups cu baza de date (SQLite: o copie consistentă cu VACUUM INTO; MySQL / PostgreSQL: fiecare tabel în JSON),
 * fișierele din data/files și un manifest. APP_KEY nu intră în arhivă: fără ea, secretele din Setări (parola SMTP, canalele) trebuie puse din nou
 * după o restaurare, deci se păstrează separat. Zilnic la curățenie (dacă e pornit în Setări), sau la cerere; se păstrează ultimele N.
 */
class Backups
{
    public const NAME = '/^(cemainou|whatsupai)-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/';

    public static function dir(): string
    {
        return config('cemainou.backup_path') ?: base_path('data/backups');
    }

    public static function make(): string
    {
        File::ensureDirectoryExists(self::dir(), 0700);
        $name = 'cemainou-'.now()->format('Y-m-d-His').'.zip';
        $path = self::dir().'/'.$name;
        $tmp = self::dir().'/.tmp-'.uniqid();
        File::ensureDirectoryExists($tmp, 0700);

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot create {$path}.");
            }

            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                self::copySqlite($tmp.'/database.sqlite');
                $zip->addFile($tmp.'/database.sqlite', 'database.sqlite');
            } else {
                foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                    self::dumpTable($table, "{$tmp}/{$table}.json");
                    $zip->addFile("{$tmp}/{$table}.json", "database/{$table}.json");
                }
            }

            $files = base_path('data/files');
            $count = 0;
            if (is_dir($files)) {
                foreach (File::allFiles($files) as $file) {
                    $zip->addFile($file->getPathname(), 'files/'.$file->getRelativePathname());
                    $count++;
                }
            }
            $zip->addFromString('manifest.json', json_encode([
                'app' => 'cemainou', 'version' => config('cemainou.version'), 'created_at' => now()->toIso8601String(),
                'database' => $driver, 'files' => $count,
                'restore' => 'php artisan cemainou:restore <this file>, on the same cemainou version, with the same APP_KEY in .env (otherwise the secrets in Settings must be set again).',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
            chmod($path, 0600);
        } finally {
            File::deleteDirectory($tmp);
        }

        self::prune(Settings::get('backup_keep'));

        return $path;
    }

    /**
     * Un tabel MySQL / MariaDB în JSON, rând cu rând (un rând pe linie, tot un tablou JSON valid): fără tot tabelul în memorie,
     * și un milion de semnale încap; restaurarea îl citește la fel, linie cu linie.
     */
    private static function dumpTable(string $table, string $target): void
    {
        $out = fopen($target, 'w');
        fwrite($out, "[\n");
        $query = DB::table($table);
        $rows = Schema::hasColumn($table, 'id') ? $query->lazyById(2000) : $query->orderBy(Schema::getColumnListing($table)[0])->lazy(2000);
        $first = true;
        foreach ($rows as $row) {
            fwrite($out, ($first ? '' : ",\n").json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $first = false;
        }
        fwrite($out, "\n]\n");
        fclose($out);
    }

    /**
     * O copie consistentă a bazei SQLite: VACUUM INTO (chiar în timp ce aplicația scrie); dacă nu se poate (într-o tranzacție deschisă, ca în teste),
     * tabel cu tabel, cu schema și rândurile, într-un fișier nou.
     */
    private static function copySqlite(string $target): void
    {
        try {
            DB::statement('VACUUM INTO ?', [$target]);

            return;
        } catch (QueryException) {
            File::delete($target);
        }

        $copy = new PDO('sqlite:'.$target);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $copy->beginTransaction();
        $objects = DB::select("select type, name, sql from sqlite_master where sql is not null and name not like 'sqlite_%' order by case type when 'table' then 0 else 1 end");
        foreach ($objects as $object) {
            if ($object->type === 'table') {
                $copy->exec($object->sql);
                foreach (DB::table($object->name)->cursor() as $row) {
                    $row = (array) $row;
                    $columns = implode(', ', array_map(fn ($c) => '"'.$c.'"', array_keys($row)));
                    $copy->prepare("insert into \"{$object->name}\" ({$columns}) values (".implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
                }
            }
        }
        foreach ($objects as $object) {
            if ($object->type !== 'table') {
                $copy->exec($object->sql);                                     // indexurile și restul, după rânduri
            }
        }
        $copy->commit();
    }

    /** @return list<array{name: string, size: int, at: Carbon}> cele mai noi întâi */
    public static function all(): array
    {
        if (! is_dir(self::dir())) {
            return [];
        }

        return collect(File::files(self::dir()))->filter(fn ($f) => preg_match(self::NAME, $f->getFilename()))
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'at' => Carbon::createFromFormat('Y-m-d-His', substr($f->getFilename(), strpos($f->getFilename(), '-') + 1, 17))])   // din nume (ora aplicației), după cemainou- sau vechiul whatsupai-
            ->sortByDesc('at')->values()->all();   // după dată, nu după nume: vechile whatsupai-* s-ar fi pus înaintea celor noi
    }

    public static function path(string $name): ?string
    {
        return preg_match(self::NAME, $name) && is_file(self::dir().'/'.$name) ? self::dir().'/'.$name : null;   // doar numele noastre: nicio cale străină
    }

    public static function prune(int $keep): void
    {
        foreach (array_slice(self::all(), max(1, $keep)) as $old) {
            File::delete(self::dir().'/'.$old['name']);
        }
    }

    /** La curățenia zilnică: un backup pe zi, dacă e pornit în Setări. */
    public static function daily(): void
    {
        if (Settings::get('backup_daily') && ! collect(self::all())->contains(fn ($b) => $b['at']->isToday())) {
            self::make();
        }
    }
}
