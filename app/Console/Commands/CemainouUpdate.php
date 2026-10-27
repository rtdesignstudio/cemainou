<?php

namespace App\Console\Commands;

use App\Support\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * După ce s-a urcat codul nou (data/ rămâne neatins): aceiași pași ca butonul „Update" din panou (App\Support\Updater), cu site-ul în mentenanță.
 * Pe un hosting fără SSH, adminul face același lucru din browser: deschide aplicația și confirmă actualizarea.
 */
#[Signature('cemainou:update {--no-backup : Fără backup înainte}')]
#[Description('Aplică un update al codului: backup, migrări, fișierele Filament, cache-urile')]
class CemainouUpdate extends Command
{
    /** numele vechi (până la 0.1.256): scripturile, cron-urile și forwarderele de email puse deja */
    protected $aliases = ['whatsupai:update'];

    public function handle(): int
    {
        $this->call('down', ['--retry' => 30]);
        try {
            $result = Updater::run(backup: ! $this->option('no-backup'));
        } finally {
            $this->call('up');
        }
        $this->info('cemainou '.$result['to'].': update applied'.($result['backup'] ? " (backup: {$result['backup']})" : '').'.');

        return self::SUCCESS;
    }
}
