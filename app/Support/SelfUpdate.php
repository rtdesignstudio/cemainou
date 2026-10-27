<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Codul unei versiuni noi, din arhiva încărcată de admin: o dezarhivează separat (data/updates), verifică semnătura RT și fiecare fișier
 * (ReleaseManifest), apoi înlocuiește folderele de cod prin redenumiri (o clipă), fără să atingă data/, .env sau ce a pus hostingul
 * (public/.well-known, .user.ini). Codul vechi rămâne în data/updates/previous-<versiune>, pentru o revenire de mână; dacă o redenumire pică,
 * totul se pune la loc. Baza de date o actualizează apoi pasul obișnuit (Updater), într-o cerere nouă, cu codul nou.
 */
class SelfUpdate
{
    /** ce nu se înlocuiește niciodată: datele echipei și ce a pus hostingul în folderele aplicației */
    private const KEEP = ['data', '.env', '.user.ini', 'public/.well-known', 'public/.user.ini'];

    public function __construct(private readonly string $root) {}

    /** Aplicația de aici (în teste, un folder temporar: cemainou.self_update_root). */
    public static function here(): self
    {
        return new self(config('cemainou.self_update_root') ?: base_path());
    }

    /** null = aplicația își poate înlocui codul aici; altfel de ce nu (atunci arhiva se urcă prin FTP, ca la instalare). */
    public function blocker(): ?string
    {
        return match (true) {
            ! extension_loaded('sodium') => __('the PHP sodium extension is missing'),
            ! class_exists(ZipArchive::class) => __('the PHP zip extension is missing'),
            collect(['', '/app', '/vendor', '/public', '/data'])->contains(fn (string $dir) => ! is_writable($this->root.$dir)) => __('PHP cannot write to the application folder'),
            default => null,
        };
    }

    /** Pune codul din arhivă; întoarce versiunea pusă. O excepție înainte de înlocuire nu schimbă nimic. */
    public function install(string $zip): string
    {
        $work = $this->root.'/data/updates';
        File::ensureDirectoryExists($work, 0700);
        $stage = "{$work}/stage-".bin2hex(random_bytes(4));
        $current = self::versionIn($this->root) ?? '0.0.0';

        try {
            $this->extract($zip, $stage);
            $manifest = ReleaseManifest::verify($stage, (string) config('cemainou.release_key'));
            $version = $manifest['version'];
            if (! version_compare($version, $current, '>')) {
                throw new RuntimeException(__('The archive is cemainou :version; this server already has :current. Nothing was changed.', ['version' => $version, 'current' => $current]));
            }
            if (version_compare(PHP_VERSION, $manifest['php'] ?? '8.4', '<')) {
                throw new RuntimeException(__('cemainou :version needs PHP :php; this server has :here. Nothing was changed.', ['version' => $version, 'php' => $manifest['php'], 'here' => PHP_VERSION]));
            }
            if (self::versionIn($stage) !== $version || ! is_file("{$stage}/artisan") || ! is_file("{$stage}/vendor/autoload.php") || ! is_file("{$stage}/public/index.php")) {
                throw new RuntimeException(__('The archive is not a complete cemainou; nothing was changed.'));
            }
            foreach (glob("{$work}/previous-*", GLOB_ONLYDIR) ?: [] as $old) {
                File::deleteDirectory($old);                                     // doar versiunea de dinainte rămâne, pentru o revenire de mână
            }
            $this->swap($stage, "{$work}/previous-{$current}");
            @file_put_contents("{$work}/previous-{$current}/.replaced_at", now()->toIso8601String());   // curățenia o șterge după o săptămână
        } finally {
            File::deleteDirectory($stage);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $version;
    }

    /** Versiunea din config/cemainou.php al unui folder de aplicație (sau din config/whatsupai.php, la codul de dinainte de 0.1.257). */
    public static function versionIn(string $dir): ?string
    {
        foreach (['cemainou', 'whatsupai'] as $name) {
            $file = "{$dir}/config/{$name}.php";
            if (is_file($file) && preg_match("/'version' => '([^']+)'/", file_get_contents($file), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function extract(string $zip, string $stage): void
    {
        $archive = new ZipArchive;
        if ($archive->open($zip) !== true) {
            throw new RuntimeException(__('The file is not a zip archive; nothing was changed.'));
        }
        try {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = (string) $archive->getNameIndex($i);
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, ':') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new RuntimeException(__('The archive has an unsafe path; nothing was changed.'));
                }
            }
            File::ensureDirectoryExists($stage, 0755);
            if (! $archive->extractTo($stage)) {
                throw new RuntimeException(__('The archive cannot be unpacked; nothing was changed.'));
            }
        } finally {
            $archive->close();
        }
    }

    /** Folderele de cod: cele vechi în $previous, cele noi la locul lor; la o eroare, totul înapoi. */
    private function swap(string $stage, string $previous): void
    {
        $keep = self::KEEP;
        if (! is_dir("{$this->root}/data/storage/framework")) {
            $keep[] = 'storage';                                                // sesiunile și jurnalele stau încă în storage/, nu în data/
        }
        File::ensureDirectoryExists($previous, 0700);
        $entries = array_values(array_filter(array_diff(scandir($stage), ['.', '..']), fn (string $entry) => ! in_array($entry, $keep, true)));
        [$moved, $placed] = [[], []];

        try {
            foreach ($entries as $entry) {
                if (file_exists("{$this->root}/{$entry}") || is_link("{$this->root}/{$entry}")) {
                    $this->move("{$this->root}/{$entry}", "{$previous}/{$entry}");
                    $moved[] = $entry;
                }
                $this->move("{$stage}/{$entry}", "{$this->root}/{$entry}");
                $placed[] = $entry;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($placed) as $entry) {
                @rename("{$this->root}/{$entry}", "{$stage}/{$entry}");
            }
            foreach (array_reverse($moved) as $entry) {
                @rename("{$previous}/{$entry}", "{$this->root}/{$entry}");
            }
            throw new RuntimeException(__('The code could not be replaced (:error); the previous version was put back.', ['error' => $e->getMessage()]), previous: $e);
        }

        foreach (array_filter($keep, fn (string $path) => str_contains($path, '/')) as $path) {   // ce pusese hostingul într-un folder înlocuit revine
            if (file_exists("{$previous}/{$path}") && ! file_exists("{$this->root}/{$path}")) {
                File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));
                @rename("{$previous}/{$path}", "{$this->root}/{$path}");
            }
        }
    }

    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RuntimeException("cannot move {$from}");
        }
    }
}
