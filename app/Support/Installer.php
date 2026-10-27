<?php

namespace App\Support;

use App\Enums\Role;
use App\Http\Middleware\SetLocale;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use PDO;
use Throwable;

/**
 * Instalarea pe un server nou (ca la Umami): codul se urcă, iar la prima vizită pagina /install verifică cerințele, întreabă adresa, limba și fusul
 * echipei, primul admin și baza de date (SQLite, implicit, sau MySQL), apoi scrie .env, rulează migrările și creează adminul.
 * Tot ce e al instalării stă în data/ (.env, baza SQLite, fișierele, backup-urile, sesiunile, cache-ul, jurnalele): un update al codului nu-l atinge.
 * Local, fără data/.env, rămâne .env din rădăcină (dezvoltare).
 */
class Installer
{
    private static ?bool $installed = null;

    /** Din bootstrap/app.php, înainte de pornire: pe un server proaspăt pregătește data/ (.env cu o cheie nouă, storage) și îl folosește. */
    public static function paths(Application $app): void
    {
        $base = $app->basePath();
        $data = $base.'/data';
        // o clonă git e dezvoltare (.env din rădăcină, README): nu se pregătește ca server proaspăt (composer install ar scrie un data/.env de producție)
        if (! is_file($base.'/.env') && ! is_file($data.'/.env') && ! is_dir($base.'/.git') && is_dir($data) && is_writable($data)) {
            self::prepare($data);
        }
        if (is_file($data.'/.env')) {
            $app->useEnvironmentPath($data);
        }
        if (is_dir($data.'/storage/framework')) {
            $app->useStoragePath($data.'/storage');
        }
    }

    private static function prepare(string $data): void
    {
        foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'files', 'backups'] as $dir) {
            is_dir("{$data}/{$dir}") || mkdir("{$data}/{$dir}", 0755, true);
        }
        is_file("{$data}/database.sqlite") || touch("{$data}/database.sqlite");
        file_put_contents("{$data}/.env", implode("\n", [
            'APP_NAME=cemainou', 'APP_ENV=production', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG=false', 'APP_URL=',
            'LOG_CHANNEL=daily', 'LOG_LEVEL=warning', 'DB_CONNECTION=sqlite', 'SESSION_DRIVER=file', 'CACHE_STORE=file', 'QUEUE_CONNECTION=sync', '',
        ]));
        chmod("{$data}/.env", 0600);
    }

    public static function installed(): bool
    {
        if (app()->runningUnitTests() && ! config('cemainou.installer_test')) {
            return true;
        }
        if (self::$installed !== null) {
            return self::$installed;
        }
        if (is_file(self::lockPath())) {
            return self::$installed = true;
        }
        try {
            if (Schema::hasTable('users') && User::where('role', Role::Admin->value)->exists()) {   // instalat înainte de installer: semnul se pune acum
                @touch(self::lockPath());

                return self::$installed = true;
            }
        } catch (Throwable) {
            // baza nu răspunde (MySQL oprit), dar instalarea are deja o adresă: rămâne închisă (nu se redeschide /install pentru oricine)
            $env = base_path('data/.env');
            if (is_file($env) && preg_match('/^APP_URL=\S+/m', (string) file_get_contents($env))) {
                return true;
            }
        }

        return self::$installed = false;
    }

    public static function forget(): void
    {
        self::$installed = null;
    }

    public static function lockPath(): string
    {
        return config('cemainou.installed_lock') ?: base_path('data/installed');
    }

    /** @return list<array{label: string, ok: bool, detail: string, required: bool}> */
    public static function requirements(): array
    {
        $writable = fn (string $path) => ['label' => __('Writable: :path', ['path' => str_replace(base_path().'/', '', $path)]), 'ok' => is_dir($path) && is_writable($path), 'detail' => '', 'required' => true];
        $rows = [['label' => 'PHP 8.4+', 'ok' => version_compare(PHP_VERSION, '8.4.0', '>='), 'detail' => PHP_VERSION, 'required' => true]];   // Symfony 8 (sub Laravel 13) cere 8.4
        foreach (['pdo_sqlite', 'mbstring', 'openssl', 'fileinfo', 'intl', 'zip', 'ctype', 'tokenizer', 'dom'] as $ext) {
            $rows[] = ['label' => "PHP {$ext}", 'ok' => extension_loaded($ext), 'detail' => '', 'required' => $ext !== 'pdo_sqlite'];
        }
        $rows[] = ['label' => 'PHP pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'detail' => __('only for MySQL'), 'required' => false];
        $rows[] = ['label' => 'PHP sodium', 'ok' => extension_loaded('sodium'), 'detail' => __('only for updates uploaded in the browser'), 'required' => false];
        $rows[] = $writable(base_path('data'));
        $rows[] = $writable(storage_path());
        $rows[] = $writable(base_path('bootstrap/cache'));
        $rows[] = ['label' => __('Largest upload'), 'ok' => Files::phpLimitMb() >= 8, 'detail' => Files::phpLimitMb().' MB', 'required' => false];

        return $rows;
    }

    public static function ready(): bool
    {
        return collect(self::requirements())->every(fn (array $r) => $r['ok'] || ! $r['required']);
    }

    /** Regulile datelor de instalare, aceleași în browser (/install) și în terminal (cemainou:install); parola și-o validează fiecare. */
    public static function rules(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:255'],
            'locale' => ['required', Rule::in(array_keys(SetLocale::LOCALES))],
            'timezone' => ['required', 'timezone:all'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'database' => ['required', Rule::in(['sqlite', 'mysql'])],
            'db_host' => ['required_if:database,mysql', 'nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_name' => ['required_if:database,mysql', 'nullable', 'string', 'max:64'],
            'db_user' => ['required_if:database,mysql', 'nullable', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'feedback_to_rt' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Instalarea propriu-zisă. $data: url, locale, timezone, name, email, password, database (sqlite|mysql) și, pentru MySQL, db_host, db_port, db_name, db_user, db_password.
     */
    public static function install(array $data): User
    {
        $env = ['APP_URL' => rtrim($data['url'], '/')];
        if (($data['database'] ?? 'sqlite') === 'mysql') {
            $mysql = ['host' => $data['db_host'], 'port' => (int) ($data['db_port'] ?: 3306), 'database' => $data['db_name'], 'username' => $data['db_user'], 'password' => (string) ($data['db_password'] ?? '')];
            new PDO("mysql:host={$mysql['host']};port={$mysql['port']};dbname={$mysql['database']}", $mysql['username'], $mysql['password'], [PDO::ATTR_TIMEOUT => 5]);   // aruncă dacă nu se poate
            config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), $mysql), 'database.default' => 'mysql']);
            DB::purge('mysql');
            $env += ['DB_CONNECTION' => 'mysql', 'DB_HOST' => $mysql['host'], 'DB_PORT' => $mysql['port'], 'DB_DATABASE' => $mysql['database'], 'DB_USERNAME' => $mysql['username'], 'DB_PASSWORD' => $mysql['password']];
        } elseif (config('database.default') === 'sqlite' && ($path = config('database.connections.sqlite.database')) !== ':memory:' && ! is_file($path)) {
            touch($path);
        }

        Artisan::call('migrate', ['--force' => true]);
        $admin = User::create([
            'name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'],
            'role' => Role::Admin, 'timezone' => $data['timezone'], 'locale' => $data['locale'],
        ]);
        Settings::save(['locale' => $data['locale'], 'timezone' => $data['timezone'], 'feedback_to_rt' => (bool) ($data['feedback_to_rt'] ?? false)], $admin);   // feedback la RT: alegerea adminului, la instalare
        app()->setLocale($data['locale']);
        ProjectTemplate::addStarters($admin);                             // șabloanele de pornire, în limba aleasă: echipa are de unde începe
        Updater::remember();                                              // versiunea instalată: o actualizare ulterioară știe de la ce pleacă
        self::writeEnv($env);
        File::put(self::lockPath(), now()->toIso8601String());
        self::$installed = true;

        return $admin;
    }

    /** Pune valorile în .env (le înlocuiește pe cele existente, adaugă ce lipsește). */
    public static function writeEnv(array $values): void
    {
        $path = config('cemainou.installer_env') ?: app()->environmentFilePath();
        $content = is_file($path) ? File::get($path) : '';
        foreach ($values as $key => $value) {
            $line = $key.'='.(preg_match('/^[\w.\/:@-]*$/', (string) $value) ? $value : '"'.addcslashes((string) $value, '"\\$').'"');
            $content = preg_match("/^{$key}=.*$/m", $content) ? preg_replace("/^{$key}=.*$/m", str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content) : rtrim($content)."\n".$line."\n";
        }
        File::put($path, $content);
        @chmod($path, 0600);
        if (app()->configurationIsCached()) {
            @unlink(app()->getCachedConfigPath());                 // configurația din cache nu mai e cea din .env: se recitește la cererea următoare
        }
    }
}
