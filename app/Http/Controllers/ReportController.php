<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\User;
use App\Support\ProjectReportData;
use App\Support\Settings;
use App\Support\TeamMail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Raportul lunar, la linkul trimis clientului (/r/{token}): fără logare, doar cu linkul; în limba echipei; prima deschidere se notează.
 * Echipa îl deschide din aplicație (/reports/{proiect}/{lună}), mereu gata, fără să-l „facă": deasupra, o bară cu lunile, orele, câteva cuvinte
 * la început, linkul și „Trimite clientului" (singurul pas care îl scoate în afară). Linkul /r/ e același; până la trimitere nu-l are nimeni.
 */
class ReportController
{
    public function show(string $token): View
    {
        $report = ProjectReport::with('project.client')->where('token', $token)->firstOrFail();
        app()->setLocale(Settings::get('locale'));
        $viewer = auth()->user();
        if (! $report->viewed_at && ! $viewer?->seesWholeTeam()) {               // deschis de altcineva decât echipa: clientul l-a văzut
            $report->forceFill(['viewed_at' => now()])->save();
        }
        $team = self::sends($viewer, $report) ? self::bar($viewer, $report) : null;

        return view('reports.month', ['r' => ProjectReportData::for($report), 'team' => $team]);
    }

    /** Raportul unei luni, din aplicație: luna trecută implicit; se pregătește la prima deschidere, fără să plece nimic. */
    public function team(Project $project, ?string $month = null): View
    {
        $user = auth()->user();
        abort_unless($user->is_active && ! $user->isClient() && $user->canSeeProject($project), 404);
        $now = now($user->tz());
        $month ??= $now->copy()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        abort_if($month > $now->format('Y-m'), 404);                         // lunile trecute, oricât de vechi (și istoria adusă din git); viitorul, nu
        $report = ProjectReport::for($project, $month, $user);
        app()->setLocale(Settings::get('locale'));

        return view('reports.month', ['r' => ProjectReportData::for($report->load('project.client')), 'team' => self::bar($user, $report, true)]);
    }

    /** Din bara echipei: orele (doar cine le poate vedea) și câteva cuvinte la început. */
    public function save(Request $request, Project $project, string $month): RedirectResponse
    {
        $user = auth()->user();
        $report = ProjectReport::where('project_id', $project->id)->where('month', $month)->firstOrFail();
        abort_unless(self::sends($user, $report), 403);
        $data = $request->validate(['intro' => ['nullable', 'string', 'max:1500'], 'include_hours' => ['nullable', 'boolean']]);
        if ($request->has('intro')) {
            $report->intro = filled($data['intro'] ?? null) ? trim($data['intro']) : null;
        }
        if ($request->has('include_hours') && $user->can('viewMinutes', $project)) {
            $report->include_hours = (bool) $data['include_hours'];
        }
        $report->save();

        return redirect()->route('report.team', [$project, $month]);
    }

    /** Bara de deasupra, doar pentru echipă: trimis sau nu, văzut de client, lunile vecine, orele, introducerea, linkul. */
    private static function bar(User $viewer, ProjectReport $report, bool $inApp = false): array
    {
        $date = fn (?Carbon $at) => $at?->copy()->setTimezone($viewer->tz())->locale(app()->getLocale())->isoFormat('D MMM');
        $oldest = collect([$report->project->created_at, $report->project->updates()->min('created_at')])->filter()->map(fn ($t) => Carbon::parse($t, 'UTC'))->min();
        $first = $oldest->copy()->setTimezone($viewer->tz())->format('Y-m');                 // săgeata înapoi merge până la primul update al proiectului
        $month = Carbon::parse($report->month.'-01', $viewer->tz());
        $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
        $next = $month->copy()->addMonthNoOverflow()->format('Y-m');

        return [
            'inApp' => $inApp,
            'sentAt' => $date($report->sent_at),
            'viewedAt' => $report->sent_at ? $date($report->viewed_at) : null,
            'people' => $report->project->clientPeople()->pluck('name')->join(', '),
            'mail' => TeamMail::ready(),
            'sendUrl' => route('report.send', $report->token),
            'saveUrl' => route('report.save', [$report->project, $report->month]),
            'link' => $report->url(),
            'intro' => $report->intro,
            'hours' => $viewer->can('viewMinutes', $report->project) ? $report->include_hours : null,
            'prev' => $prev >= $first ? route('report.team', [$report->project, $prev]) : null,
            'next' => $next <= now($viewer->tz())->format('Y-m') ? route('report.team', [$report->project, $next]) : null,
            'current' => $report->month === now($viewer->tz())->format('Y-m'),
            'projectUrl' => ProjectResource::getUrl('view', ['record' => $report->project]),
        ];
    }

    /** „Trimite clientului" din bara echipei: o dată (după, bara arată când a plecat). */
    public function send(string $token): RedirectResponse
    {
        $report = ProjectReport::with('project')->where('token', $token)->firstOrFail();
        $user = auth()->user();
        abort_unless(self::sends($user, $report), 403);
        $sent = $report->sent_at ? 0 : $report->send($user);

        return redirect()->route('report.team', [$report->project, $report->month])->with('report-sent', $sent ?: null);
    }

    private static function sends(?User $user, ProjectReport $report): bool
    {
        return $user !== null && $user->is_active && ! $user->isClient() && $user->canSeeProject($report->project);
    }
}
