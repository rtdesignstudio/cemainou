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

/**
 * Instanța demo publică: baza se reface de la zero cu datele inventate (DemoSeeder), în fiecare noapte (programat în routes/console.php)
 * sau de mână. Doar cu cemainou.demo pornit; altundeva nu face nimic, ca să nu șteargă vreodată datele unei echipe reale.
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
        try {
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
            $this->call('optimize:clear');
        } finally {
            $this->call('up');
        }
        $this->info('Demo data is fresh.');

        return self::SUCCESS;
    }
}
