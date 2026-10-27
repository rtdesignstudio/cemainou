<?php

namespace App\Console\Commands;

use App\Support\InboundMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cemainou:inbound-mail')]
#[Description('Citește un email brut de la intrarea standard (forwarder „pipe" pe serverul de email) și îl face sarcină pe proiectul din adresă')]
class CemainouInboundMail extends Command
{
    /** numele vechi (până la 0.1.256): scripturile, cron-urile și forwarderele de email puse deja */
    protected $aliases = ['whatsupai:inbound-mail'];

    public function handle(): int
    {
        $raw = stream_get_contents(STDIN, InboundMail::MAX_BYTES + 1);
        $task = InboundMail::receive((string) $raw);
        if ($task) {
            $this->line("Task #{$task->id} on {$task->project->name}");
        }

        return self::SUCCESS;                                           // mereu 0: un cod de eroare ar trimite expeditorului un mesaj de respingere
    }
}
