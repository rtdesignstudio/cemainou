<?php

namespace App\Console\Commands;

use App\Models\ProjectTemplate;
use App\Models\User;
use App\Support\Demo;
use App\Support\Settings;
use App\Support\Updater;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Instanța demo publică: baza se reface de la zero cu datele inventate (DemoSeeder), din oră în oră (programat în routes/console.php)
 * sau de mână. Doar cu cemainou.demo pornit; altundeva nu face nimic, ca să nu șteargă vreodată datele unei echipe reale.
 * Pe SQLite, baza nouă se face într-un fișier alături și îl înlocuiește dintr-o mișcare pe cel vechi (rename): o cerere sau o comandă care
 * ține baza deschisă în timpul refacerii nu mai poate lăsa fișierul stricat (30 oct 2026: „database is locked", apoi „file is not a database").
 */
#[Signature('cemainou:demo-reset')]
#[Description('Instanța demo: reface baza cu datele inventate (doar cu CEMAINOU_DEMO=true)')]
class CemainouDemoReset extends Command
{
    public function handle(): int
    {
        if (! Demo::on()) {
            $this->error('Not a demo installation (CEMAINOU_DEMO is not true): nothing was changed.');

            return self::FAILURE;
        }
        $this->call('down', ['--retry' => 15]);
        $connection = config('database.default');
        $file = (string) config("database.connections.{$connection}.database");
        $live = config("database.connections.{$connection}.driver") === 'sqlite' && $file !== ':memory:' && is_file($file) ? $file : null;
        $fresh = $live ? $live.'.fresh' : null;
        try {
            if ($fresh) {                                                       // baza nouă, alături; cea veche rămâne neatinsă până la înlocuire
                @unlink($fresh);
                touch($fresh);
                config(["database.connections.{$connection}.database" => $fresh]);
                DB::purge($connection);
            }
            $this->call('migrate:fresh', ['--force' => true]);
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $ana = User::where('email', 'ana@cemainou.test')->firstOrFail();
            $lang = config('cemainou.demo_locale') === 'en' ? 'en' : 'ro';
            Settings::save(['locale' => $lang, 'timezone' => 'Europe/Chisinau', 'mail_mailer' => 'off', 'update_check' => false, 'feedback_to_rt' => false,
                'privacy_public' => true, 'backup_daily' => false], $ana);
            User::query()->update(['locale' => $lang]);                       // limba echipei; cine alege alta la intrare o păstrează (SetLocale, în demo)
            app()->setLocale($lang);
            ProjectTemplate::addStarters($ana);
            Updater::remember();
            if ($fresh) {
                DB::disconnect($connection);
                foreach (['-wal', '-shm', '-journal'] as $side) {
                    @unlink($live.$side);                                       // fișierele de lângă baza veche nu se potrivesc cu cea nouă
                }
                if (! rename($fresh, $live)) {
                    throw new RuntimeException('The fresh demo database could not replace the old one.');
                }
                config(["database.connections.{$connection}.database" => $live]);
                DB::purge($connection);
            }
            $this->call('optimize:clear');
        } finally {
            if ($fresh) {
                @unlink($fresh);
                config(["database.connections.{$connection}.database" => $live]);
                DB::purge($connection);
            }
            $this->call('up');
        }
        $this->info('Demo data is fresh.');

        return self::SUCCESS;
    }
}
