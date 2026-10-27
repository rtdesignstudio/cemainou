<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Actualizarea după ce s-a urcat codul nou (data/ rămâne neatins), la fel din panou (/update, doar adminul) și din consolă (cemainou:update):
 * întâi un backup (dacă pică, nu se schimbă nimic), apoi migrările (care doar adaugă), fișierele Filament și cache-urile; fără shell, totul prin Artisan.
 * Cât timp baza e în urma codului (migrări nerulate), oamenii primesc „se actualizează", agenții 503, iar adminul e trimis la actualizare.
 */
class Updater
{
    /** cheia din `settings` cu versiunea aplicată ultima dată */
    public const VERSION_KEY = 'app_version';

    /** instalările de dinainte de 0.1.1 nu-și notau versiunea: singura de atunci e 0.1.0 */
    private const BEFORE_MARKER = '0.1.0';

    /** null = la zi; altfel versiunea de acum, cea nouă și migrările de rulat. Se verifică o dată pe versiune de cod (cache). @return array{from: string, to: string, migrations: list<string>}|null */
    public static function pending(): ?array
    {
        if (app()->runningUnitTests() && ! config('cemainou.updater_test')) {
            return null;
        }
        $files = self::migrationFiles();

        return Cache::rememberForever(self::cacheKey($files), fn () => self::check($files)) ?: null;
    }

    /** După orice rulare de migrări (și una de mână, `php artisan migrate`): starea „de actualizat" se recalculează, altfel aplicația ar rămâne pe „se actualizează". */
    public static function forgetPending(): void
    {
        Cache::forget(self::cacheKey(self::migrationFiles()));
    }

    /** Cheia stării „de actualizat", pe versiunea de cod (după actualizare se uită: restul cache-ului aplicației rămâne). @param array<string, string> $files */
    private static function cacheKey(array $files): string
    {
        return 'cemainou.update.'.md5(config('cemainou.version').'|'.implode(',', array_keys($files)));
    }

    /** Doar o versiune nouă, fără migrări: nimic nu se blochează, dar adminul o confirmă. */
    public static function blocks(): bool
    {
        return (bool) (self::pending()['migrations'] ?? false);
    }

    /**
     * Backup, migrări, fișiere, cache-uri; două actualizări odată nu se pot porni. Dacă backup-ul pică, nu se atinge nimic.
     *
     * @return array{from: string, to: string, migrations: list<string>, backup: ?string}
     */
    public static function run(bool $backup = true): array
    {
        return self::exclusive(function () use ($backup) {
            $state = self::check(self::migrationFiles()) ?: ['from' => config('cemainou.version'), 'to' => config('cemainou.version'), 'migrations' => []];
            $made = $backup ? basename(Backups::make()) : null;
            // ce ține de cod se golește; cache-ul aplicației nu (optimize:clear îl golea și, cu el, semnele „a plecat” - rezumatul pleca iar după fiecare lansare)
            foreach ([['migrate', ['--force' => true]], ['package:discover', []], ['filament:upgrade', []], ['config:clear', []], ['route:clear', []], ['view:clear', []], ['event:clear', []], ['clear-compiled', []]] as [$command, $options]) {
                if (Artisan::call($command, $options) !== 0) {
                    throw new RuntimeException(trim(Artisan::output()) ?: "{$command} failed.");
                }
            }
            Cache::forget(self::cacheKey(self::migrationFiles()));            // starea „de actualizat" a versiunii acesteia: acum e la zi
            if (app()->isProduction()) {
                // cache-urile care scurtează fiecare cerere: configurația (dacă .env se schimbă, Installer::writeEnv o șterge), vederile, rutele
                // (Filament nu le mai înregistrează la fiecare cerere), evenimentele, componentele și iconițele Filament
                foreach (['config:cache', 'view:cache', 'route:cache', 'event:cache', 'filament:optimize'] as $command) {
                    try {
                        Artisan::call($command);
                    } catch (Throwable $e) {
                        report($e);                                       // un cache care nu se face nu oprește actualizarea; merge și fără el
                    }
                }
            }
            @file_put_contents(base_path('bootstrap/cache/opcache-stamp'), (string) microtime(true));   // public/index.php golește OPcache la prima cerere
            self::remember();

            return $state + ['backup' => $made];
        });
    }

    /** O singură actualizare odată (baza sau codul): a doua așteaptă să se termine prima. Continuă și dacă browserul închide pagina. */
    public static function exclusive(callable $work): mixed
    {
        $lock = fopen(storage_path('framework/cemainou-update.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException(__('An update is already running. Wait a minute and reload the page.'));
        }
        @set_time_limit(600);
        ignore_user_abort(true);

        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Noutățile dintre două versiuni, din CHANGELOG.md (Markdown): secțiunile mai noi decât $from, până la $to inclusiv. */
    /** Noutățile, în limba omului dacă arhiva le are (CHANGELOG.ro.md), altfel în engleză (CHANGELOG.md). */
    public static function changelogPath(): string
    {
        $localized = base_path('CHANGELOG.'.app()->getLocale().'.md');

        return is_file($localized) ? $localized : base_path('CHANGELOG.md');
    }

    public static function notes(?string $from, string $to): string
    {
        $path = self::changelogPath();
        $text = is_file($path) ? file_get_contents($path) : '';
        $sections = preg_split('/^(?=## )/m', $text);

        return trim(implode('', array_filter($sections, function (string $section) use ($from, $to) {
            if (! preg_match('/^## (\d+\.\d+\.\d+) - /', $section, $m)) {
                return false;
            }

            return version_compare($m[1], $to, '<=') && ($from === null || version_compare($m[1], $from, '>'));
        })));
    }

    /** Ce urmează de aplicat (fără cache); [] = la zi. */
    private static function check(array $files): array
    {
        try {
            if (! Schema::hasTable('migrations') || ! Schema::hasTable('settings')) {
                return [];                                                // neinstalat: se ocupă installer-ul
            }
            $missing = array_values(array_diff(array_keys($files), app('migrator')->getRepository()->getRan()));
            $stored = Setting::find(self::VERSION_KEY)?->value;
        } catch (Throwable) {
            return [];
        }
        $version = config('cemainou.version');
        if ($stored === null && ! $missing) {                             // instalare la zi, de dinainte să se noteze versiunea: se notează acum
            self::remember();

            return [];
        }
        if (! $missing && $stored === $version) {
            return [];
        }

        return ['from' => $stored ?? self::BEFORE_MARKER, 'to' => $version, 'migrations' => $missing];
    }

    /** Versiunea codului devine cea aplicată. */
    public static function remember(): void
    {
        Setting::updateOrCreate(['key' => self::VERSION_KEY], ['value' => config('cemainou.version')]);
    }

    /** @return array<string, string> numele migrării → fișierul */
    private static function migrationFiles(): array
    {
        $migrator = app('migrator');

        return $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
    }
}
