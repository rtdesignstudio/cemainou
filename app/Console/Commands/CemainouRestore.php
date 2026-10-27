<?php

namespace App\Console\Commands;

use App\Support\Backups;
use App\Support\Restore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** Restaurarea unui backup din terminal: înlocuiește datele echipei cu cele din arhivă (un backup al stării de acum se face întâi). */
#[Signature('cemainou:restore {file : Arhiva de backup (cemainou-....zip), din data/backups sau o cale} {--no-backup : Fără backup-ul stării de acum} {--force : Fără întrebarea de confirmare}')]
#[Description('Restaurează un backup cemainou (SQLite sau MySQL / MariaDB) în baza curentă; merge și de pe SQLite pe MySQL')]
class CemainouRestore extends Command
{
    public function handle(): int
    {
        $file = $this->argument('file');
        $path = is_file($file) ? $file : Backups::path(basename($file));
        if (! $path) {
            $this->error("Nu găsesc backup-ul {$file}.");

            return self::FAILURE;
        }

        try {
            $manifest = Restore::manifest($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("Backup din {$manifest['created_at']}, cemainou {$manifest['version']}, baza {$manifest['database']}.");
        if (! $this->option('force') && ! $this->confirm('Datele de acum se înlocuiesc cu cele din backup. Continui?')) {
            return self::FAILURE;
        }

        try {
            if (! $this->option('no-backup')) {
                $this->line('Backup-ul stării de acum: '.Backups::make());
            }
            $done = Restore::run($path);
        } catch (Throwable $e) {
            $this->error('Restaurarea s-a oprit (nimic nu s-a schimbat în baza de date): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Gata: {$done['tables']} tabele, {$done['rows']} rânduri, {$done['files']} fișiere. Cheia APP_KEY trebuie să fie cea de la backup.");

        return self::SUCCESS;
    }
}
