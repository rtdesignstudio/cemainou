<?php

namespace App\Support;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Mail\WeeklyDigestMail;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Rezumatul săptămânal pe email: în ziua și la ora din Setări (implicit luni, 8:00, în fusul echipei), fiecare om activ primește ce s-a întâmplat
 * în ultimele 7 zile pe proiectele pe care le vede: update-urile, sarcinile terminate, ce e blocat, documentele schimbate. Clientul primește doar
 * proiectele lui, fără update-urile interne și fără documente, dar cu „Pentru tine": sarcinile deschise date lui (o întrebare, un material de trimis).
 * Cine n-are nimic de citit nu primește nimic; cine l-a oprit din profil, la fel.
 * Pleacă la prima cerere de după oră (ca și curățenia, fără cron), o dată pe săptămână; semnul că a plecat stă în baza de date (Once).
 */
class WeeklyDigest
{
    public function sendIfDue(): int
    {
        $day = Settings::get('digest_day');
        if ($day === null || ! TeamMail::ready()) {
            return 0;
        }
        $now = now(Settings::get('timezone'));
        $week = $now->format('o-W');
        if ($now->dayOfWeekIso !== $day || $now->hour < Settings::get('digest_hour') || Once::done('digest', $week)) {
            return 0;
        }

        return Cache::lock('cemainou.digest.lock', 600)->get(function () use ($week, $now) {
            if (! Once::claim('digest', $week)) {                               // întâi semnul, în baza de date: mai bine un rezumat ratat decât două
                return 0;
            }

            return $this->send($now->copy()->subWeek(), $now);
        }) ?? 0;
    }

    /** Trimite rezumatul pe intervalul dat; cu $only, doar acelui om (previzualizarea din Setări). */
    public function send(CarbonInterface $from, CarbonInterface $to, ?User $only = null): int
    {
        $sent = 0;
        $people = $only ? collect([$only]) : User::where('is_active', true)->where('wants_digest', true)->get();
        foreach ($people as $person) {
            $projects = self::for($person, $from, $to);
            if ($projects === [] && ! $only) {
                continue;
            }
            try {
                Mail::to($person)->send(new WeeklyDigestMail($person, $projects, $from, $to));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Săptămâna, pe proiectele pe care le vede omul, cele cu ceva de spus.
     *
     * @return list<array{name: string, url: string, waiting: list<array{title: string, url: string}>, updates: list<array{summary: string, who: ?string, when: string}>, more: int, done: list<string>, done_more: int, blocked: list<string>, documents: list<string>}>
     */
    public static function for(User $person, CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [$from->copy()->utc(), $to->copy()->utc()];
        $tz = $person->tz();

        $updates = Update::visibleTo($person)->with('user')->whereNotNull('project_id')->whereBetween('created_at', $range)->latest()->get()->groupBy('project_id');
        // sarcinile închise în săptămână și încă închise (nu și cele redeschise între timp), cele mai noi întâi, fiecare o dată
        $done = TaskEvent::with('task')->where('type', TaskEventType::Status->value)->where('to', TaskStatus::Done->value)->whereBetween('created_at', $range)
            ->whereHas('task', fn ($task) => $task->visibleTo($person)->where('status', TaskStatus::Done->value))->latest()->latest('id')->get()
            ->unique('task_id')->groupBy('task.project_id');
        $blocked = Task::visibleTo($person)->where('status', TaskStatus::Blocked->value)->get()->groupBy('project_id');
        $documents = $person->isClient() ? collect() : DocumentVersion::with('document')->whereBetween('created_at', $range)
            ->whereHas('document', fn ($document) => $document->whereNull('archived_at'))->get()->groupBy('document.project_id');

        $waiting = $person->isClient() ? Task::visibleTo($person)->open()->where('assignee_id', $person->id)->orderBy('due_on')->orderBy('id')->get()->groupBy('project_id') : collect();   // clientul: ce așteaptă echipa de la el

        $ids = $updates->keys()->merge($done->keys())->merge($documents->keys())->merge($blocked->keys())->merge($waiting->keys())->unique();

        return Project::visibleTo($person)->whereIn('id', $ids)->orderBy('name')->get()->map(fn (Project $project) => [
            'name' => $project->name,
            'url' => ProjectResource::getUrl('view', ['record' => $project], panel: 'admin'),
            'waiting' => ($waiting[$project->id] ?? collect())->map(fn (Task $t) => ['title' => $t->title, 'url' => TaskResource::getUrl('view', ['record' => $t], panel: 'admin')])->values()->all(),
            'updates' => ($updates[$project->id] ?? collect())->take(5)->map(fn (Update $u) => [
                'summary' => Markdown::plain($u->summary), 'who' => $u->user?->name,
                'when' => $u->created_at->copy()->setTimezone($tz)->locale(app()->getLocale())->isoFormat('ddd D MMM'),
            ])->values()->all(),
            'more' => max(0, ($updates[$project->id] ?? collect())->count() - 5),
            'done' => ($done[$project->id] ?? collect())->pluck('task.title')->take(8)->values()->all(),
            'done_more' => max(0, ($done[$project->id] ?? collect())->count() - 8),
            'blocked' => ($blocked[$project->id] ?? collect())->map(fn (Task $t) => $t->title.($t->blocked_reason ? ' - '.Markdown::plain($t->blocked_reason) : ''))->values()->all(),
            'documents' => ($documents[$project->id] ?? collect())->groupBy('document_id')->map(fn ($versions) => $versions->first()->document->title)->values()->all(),
        ])->filter(fn (array $row) => $row['waiting'] || $row['updates'] || $row['done'] || $row['documents'] || $row['blocked'])->values()->all();
    }
}
