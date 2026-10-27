<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AgentCommands;
use App\Support\ConnectCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('cemainou:connect {email : Emailul contului} {--device=laptop - Claude Code : Numele dispozitivului, pentru lista de chei} {--install : Conectează singur Claude Code (serverul MCP, comenzile scurte și hook-urile pentru timp)}')]
#[Description('Conectează Claude Code (cu --install) sau afișează comanda de instalare, cu un cod de unică folosință: cheia nu apare nicăieri')]
class CemainouConnect extends Command
{
    /** numele vechi (până la 0.1.256): scripturile, cron-urile și forwarderele de email puse deja */
    protected $aliases = ['whatsupai:connect'];

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Nu există un cont cu acest email. Creează-l întâi: php artisan make:filament-user');

            return self::FAILURE;
        }

        // reconectarea aceluiași dispozitiv înlocuiește cheia lui, abia când instalarea primește cheia nouă
        $code = ConnectCode::issue($user, $this->option('device'), replace: true);

        return $this->option('install') ? $this->install($user, $code) : $this->show($user, $code);
    }

    /** Scriptul de instalare schimbă codul pe cheie și o pune direct în configurația Claude Code: cheia nu trece prin ecran sau prin argumente. */
    private function install(User $user, string $code): int
    {
        $run = Process::timeout(120)->run(['sh', resource_path('agent/install.sh'), AgentCommands::baseUrl(), $code]);

        if ($run->exitCode() === 3) {                 // conectat și comenzile puse, dar fără hook-uri (lipsesc node și python3)
            $this->warn(trim($run->errorOutput()));
            $this->info(trim($run->output()));

            return self::SUCCESS;
        }
        if ($run->failed()) {
            ConnectCode::forget($code);
            $this->error('Instalarea nu a reușit: '.trim($run->errorOutput()).' (Serverul trebuie să fie pornit la '.AgentCommands::baseUrl().'.)');

            return self::FAILURE;
        }

        $this->info(trim($run->output()) ?: "Gata: Claude Code e conectat la cemainou ca {$user->name}; comenzile /upd, /brief și hook-urile sunt instalate. Deschide o sesiune nouă.");

        return self::SUCCESS;
    }

    private function show(User $user, string $code): int
    {
        $this->info("Comandă pentru {$user->name} ({$this->option('device')}), valabilă ".ConnectCode::MINUTES.' minute, o singură dată. Rulează-o într-un terminal:');
        $this->newLine();
        $this->line(AgentCommands::install($code));
        $this->newLine();
        $this->comment('Conectează Claude Code la serverul MCP și instalează /upd, /brief și hook-urile care leagă sesiunea de proiect și îi măsoară timpul. Cheia se creează abia când rulezi comanda și nu apare nicăieri.');

        return self::SUCCESS;
    }
}
