<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Raportul lunar, pregătit singur (din curățenia zilnică): în primele zile ale lunii, pentru fiecare proiect activ al unui client, vizibil lui,
 * cu ceva de arătat luna trecută (un update neintern sau o sarcină încheiată), se face raportul lunii trecute și liderul află din clopoțel
 * („Raportul lui octombrie pentru X e gata"). Nu pleacă nimic la client: liderul îl deschide și îl trimite de pe pagina raportului.
 * O singură dată pe lună și proiect: dacă raportul există deja (făcut de mână sau de aici), nu se mai anunță.
 */
class MonthlyReports
{
    /** Până în ziua aceasta a lunii (în fusul liderului) se mai pregătește raportul lunii trecute; după, nu mai e „la început de lună". */
    public const UNTIL_DAY = 7;

    public static function prepare(): int
    {
        $made = 0;
        Project::active()->whereNotNull('client_id')->where('client_visible', true)->whereNotNull('lead_id')->with('lead')->get()
            ->each(function (Project $project) use (&$made) {
                $lead = $project->lead;
                if (! $lead?->is_active || ! $lead->seesWholeTeam()) {
                    return;
                }
                $now = now($lead->tz());
                if ($now->day > self::UNTIL_DAY) {
                    return;
                }
                $start = $now->copy()->startOfMonth()->subMonthNoOverflow();
                $month = $start->format('Y-m');
                if (ProjectReport::where('project_id', $project->id)->where('month', $month)->exists() || ! self::hasSomething($project, $start)) {
                    return;
                }
                $report = ProjectReport::for($project, $month, $lead);
                self::announce($report, $project, $lead, $start);
                $made++;
            });

        return $made;
    }

    /** Ceva de arătat clientului în luna aceea: un update neintern sau o sarcină încheiată. */
    private static function hasSomething(Project $project, Carbon $start): bool
    {
        [$from, $to] = [$start->copy()->utc(), $start->copy()->endOfMonth()->utc()];

        return Update::where('project_id', $project->id)->where('is_internal', false)->whereBetween('created_at', [$from, $to])->exists()
            || Task::where('project_id', $project->id)->where('status', TaskStatus::Done->value)->whereBetween('done_at', [$from, $to])->exists();
    }

    private static function announce(ProjectReport $report, Project $project, User $lead, Carbon $start): void
    {
        $locale = $lead->locale ?: config('app.locale');
        $month = $start->copy()->locale($locale)->isoFormat('MMMM');
        $people = $project->clientPeople()->pluck('name')->join(', ');
        Inbox::notify($lead, __('The :month report for :project is ready', ['month' => $month, 'project' => $project->name], $locale),
            $people !== '' ? __('Look it over, then send it to :people from the report page.', ['people' => $people], $locale)
                : __('Look it over; the client has no account yet, so send them the link.', [], $locale),
            route('report.team', [$project, $report->month]), Heroicon::OutlinedDocumentChartBar, $locale, 'reports');
    }
}
