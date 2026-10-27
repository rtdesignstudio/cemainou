<?php

namespace App\Console\Commands;

use App\Support\Backups;
use App\Support\Files;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Un backup acum (din cron sau de mână): baza de date și fișierele, în data/backups; se păstrează ultimele N din Setări. */
#[Signature('cemainou:backup')]
#[Description('Face un backup (baza de date și fișierele) în data/backups; APP_KEY se păstrează separat')]
class CemainouBackup extends Command
{
    /** numele vechi (până la 0.1.256): scripturile, cron-urile și forwarderele de email puse deja */
    protected $aliases = ['whatsupai:backup'];

    public function handle(): int
    {
        $path = Backups::make();
        $this->info('Backup: '.$path.' ('.Files::size(filesize($path)).')');

        return self::SUCCESS;
    }
}
