<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Doar la RT: câte instalări cemainou verifică versiunile, din jurnalele site-ului rt.md (CEMAINOU_INSTALL_LOGS), nu din instanțe.
 * O cerere la lista de versiuni cu User-Agent „cemainou/<versiune>" = o instalare; o adresă distinctă pe zi. Se păstrează doar cifrele pe zile, niciodată adresele.
 */
class CemainouInstallEstimate extends Command
{
    protected $signature = 'cemainou:install-estimate';

    protected $description = 'Counts the installations that checked for new versions (from the rt.md access logs; RT only)';

    public function handle(): int
    {
        $dir = config('cemainou.install_logs');
        if (blank($dir) || ! is_dir($dir)) {
            $this->line('No access logs configured (CEMAINOU_INSTALL_LOGS): nothing to count.');

            return self::SUCCESS;
        }
        $days = [];
        foreach (glob(rtrim($dir, '/').'/*.tar.gz*') ?: [] as $archive) {
            if (filemtime($archive) < now()->subDays(40)->getTimestamp()) {
                continue;
            }
            $out = Process::timeout(60)->run(['tar', 'xzf', $archive, '-O'])->output();
            foreach (preg_split('/\n/', $out) as $line) {
                if (! preg_match('#^(\S+) .*\[(\d{2}/\w{3}/\d{4}):.*"GET /(?:cemainou|whatsupai)/releases\.json[^"]*" \d{3} .*"(?:cemainou|whatsupai)/[\d.]+"$#', $line, $m)) {
                    continue;
                }
                $day = \DateTime::createFromFormat('d/M/Y', $m[2])?->format('Y-m-d');
                if ($day) {
                    $days[$day][$m[1]] = true;
                }
            }
        }
        $counts = collect($days)->map(fn (array $ips) => count($ips))->sortKeys()->filter(fn ($n, $day) => $day >= now()->subDays(30)->toDateString());
        Setting::updateOrCreate(['key' => 'install_estimate'], ['value' => [
            'max' => (int) ($counts->max() ?? 0), 'typical' => (int) round($counts->median() ?? 0), 'days' => $counts->count(), 'at' => now()->toIso8601String(),
        ]]);
        $this->info('Installations per day, last 30 days: up to '.($counts->max() ?? 0).'.');

        return self::SUCCESS;
    }
}
