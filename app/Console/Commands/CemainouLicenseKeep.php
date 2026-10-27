<?php

namespace App\Console\Commands;

use App\Models\IssuedLicense;
use App\Support\FeedbackUpstream;
use Illuminate\Console\Command;

/**
 * Doar la RT: ține minte o cheie de licență tocmai emisă (scripts/release-tools.php o trimite singur, prin SSH), în Admin → cemainou → Licențe.
 * Cheia vine pe intrarea standard, nu ca argument (s-ar vedea în lista proceselor); se păstrează doar amprenta ei.
 */
class CemainouLicenseKeep extends Command
{
    protected $signature = 'cemainou:license-keep {--note= : A note, e.g. the invoice}';

    protected $description = 'Keeps an issued license key in Admin → cemainou → Licenses (key on standard input; RT only)';

    public function handle(): int
    {
        if (! FeedbackUpstream::inbox() && blank(config('cemainou.access_requests'))) {
            $this->error('Not the RT instance: licenses are kept only there.');

            return self::FAILURE;
        }
        $kept = IssuedLicense::keep(trim((string) stream_get_contents(STDIN)), $this->option('note'));
        if (! $kept) {
            $this->error('This is not a cemainou license key signed by RT.');

            return self::FAILURE;
        }
        $this->info("Kept: {$kept->licensee}, until {$kept->until->toDateString()}.");

        return self::SUCCESS;
    }
}
