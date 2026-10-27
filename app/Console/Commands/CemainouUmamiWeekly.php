<?php

namespace App\Console\Commands;

use App\Enums\Source;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Doar la RT: rezumatul săptămânii din Umami (rt.md, d.rt.md, aplicația), scris ca notiță pe proiectul ales (CEMAINOU_UMAMI_SUMMARY_PROJECT).
 * Cifrele vin printr-o cheie SSH care pe serverul de statistici poate rula doar scriptul de rezumat (CEMAINOU_UMAMI_SUMMARY_SSH); doar citire.
 */
class CemainouUmamiWeekly extends Command
{
    protected $signature = 'cemainou:umami-weekly {--dry : Show the note, do not save it}';

    protected $description = 'Writes last week\'s visit summary from Umami as a note on the chosen project (RT only)';

    public function handle(): int
    {
        $ssh = config('cemainou.umami_summary_ssh');
        $project = Project::where('slug', config('cemainou.umami_summary_project'))->first();
        if (blank($ssh) || ! $project) {
            $this->line('Not set up (CEMAINOU_UMAMI_SUMMARY_SSH, CEMAINOU_UMAMI_SUMMARY_PROJECT): nothing to do.');

            return self::SUCCESS;
        }
        $run = Process::timeout(60)->run(['ssh', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-i', config('cemainou.umami_summary_key'), $ssh]);
        $data = json_decode(trim($run->output()), true);
        if (! $run->successful() || ! is_array($data)) {
            $this->error('No summary from the statistics server.');

            return self::FAILURE;
        }
        $body = self::note($data);
        if ($this->option('dry')) {
            $this->line($body);

            return self::SUCCESS;
        }
        $project->notes()->create(['user_id' => $project->lead_id ?? User::where('role', 'admin')->value('id'), 'body' => $body, 'source' => Source::Other]);
        $this->info('Summary noted on '.$project->name.'.');

        return self::SUCCESS;
    }

    /** Notița, pe scurt: fiecare domeniu cu vizitatorii și schimbarea față de săptămâna dinainte, paginile, sursele, țările, evenimentele. */
    public static function note(array $d): string
    {
        $prev = collect($d['previous'] ?? [])->keyBy('hostname');
        $hosts = collect($d['hosts'] ?? [])->map(function (array $h) use ($prev) {
            $before = $prev->get($h['hostname'])['visitors'] ?? null;
            $change = $before ? ' ('.($h['visitors'] >= $before ? '+' : '').round(($h['visitors'] - $before) * 100 / $before).'%)' : '';

            return "**{$h['hostname']}**: {$h['visitors']} vizitatori{$change}, {$h['views']} vizualizări";
        });
        $lines = ['**Vizite, ultimele 7 zile** (Umami, fără cookie-uri)', $hosts->isNotEmpty() ? $hosts->map(fn ($l) => '- '.$l)->join("\n") : '- nicio vizită'];
        if ($pages = collect($d['pages'] ?? [])->take(6)->map(fn ($p) => ($p['hostname'] === 'rt.md' ? '' : $p['hostname']).$p['url_path'].' · '.$p['views'])->join(', ')) {
            $lines[] = 'Pagini: '.$pages;
        }
        if ($refs = collect($d['referrers'] ?? [])->map(fn ($r) => $r['referrer_domain'].' · '.$r['visitors'])->join(', ')) {
            $lines[] = 'Surse: '.$refs;
        }
        if ($countries = collect($d['countries'] ?? [])->map(fn ($c) => $c['country'].' · '.$c['visitors'])->join(', ')) {
            $lines[] = 'Țări: '.$countries;
        }
        if ($events = collect($d['events'] ?? [])->map(fn ($e) => $e['event_name'].' · '.$e['n'])->join(', ')) {
            $lines[] = 'Acțiuni: '.$events;
        }

        return implode("\n\n", $lines);
    }
}
