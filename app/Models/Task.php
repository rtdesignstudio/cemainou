<?php

namespace App\Models;

use App\Enums\Source;
use App\Enums\TaskEventType;
use App\Enums\TaskRepeat;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Channels;
use App\Support\Checklist;
use App\Support\Inbox;
use App\Support\Mentions;
use App\Support\TaskNotices;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * O sarcină pe un proiect. Liberă = fără om, și atunci mereu „De făcut". Orice schimbare trece prin metodele de aici, fiecare într-o
 * tranzacție (sarcina și evenimentul din istorie se scriu împreună sau deloc); cine are voie să le cheme decide TaskPolicy.
 */
#[Fillable(['project_id', 'title', 'notes', 'due_on', 'repeat', 'repeated_from_id', 'status', 'blocked_reason', 'created_by', 'assignee_id', 'assignee_seen_at', 'started_at', 'done_at', 'source'])]
class Task extends Model
{
    /** Cât pot avea detaliile (descrierea) unei sarcini, cu pașii ei: aceeași limită pe web, la agenți și în șabloane. */
    public const MAX_NOTES = 10000;

    use HasFactory, StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['notes' => null, 'due_on' => null, 'repeat' => null, 'repeated_from_id' => null, 'status' => 'todo', 'blocked_reason' => null, 'created_by' => null, 'assignee_id' => null, 'assignee_seen_at' => null, 'started_at' => null, 'done_at' => null, 'source' => 'web'];

    /** Regulile stau aici, nu doar în formulare și unelte: niciun proiect arhivat, niciun cont inactiv. */
    public static function add(Project $project, User $by, string $title, ?string $notes = null, ?User $assignee = null, Source $source = Source::Web, ?Update $update = null, bool $idea = false, ?string $due = null, ?TaskRepeat $repeat = null): self
    {
        if ($project->is_archived) {
            throw new InvalidArgumentException("{$project->name} is archived: no new tasks on it.");
        }
        if ($assignee && ! $assignee->is_active) {
            throw new InvalidArgumentException("{$assignee->name}'s account is inactive: it cannot get tasks.");
        }
        if ($assignee && ! $assignee->canSeeProject($project)) {                 // și un om al clientului: doar pe proiectele firmei lui vizibile lui
            throw new InvalidArgumentException(self::noAccess($assignee, $project));
        }

        $task = DB::transaction(function () use ($project, $by, $title, $notes, $assignee, $source, $update, $idea, $due, $repeat) {
            $task = self::create([
                'project_id' => $project->id,
                'title' => trim($title),
                'notes' => self::clean($notes),
                'due_on' => $idea ? null : ($due ?: null),
                'repeat' => $idea ? null : $repeat,
                'status' => $idea ? TaskStatus::Idea : TaskStatus::Todo,             // o idee: notată, fără om, în afara celor deschise
                'created_by' => $by->id,
                'assignee_id' => $idea ? null : $assignee?->id,
                'assignee_seen_at' => $assignee?->is($by) ? now() : null,
                'source' => $source,
            ]);
            $task->record(TaskEventType::Created, $by, to: $assignee?->name, update: $update);

            return $task;
        });
        if (! $idea) {                                                   // o idee nu cheamă pe nimeni
            TaskNotices::added($task);                                   // de la client: liderul primește un email
            Inbox::added($task, $by);                                    // clopoțelul: omul căruia i s-a dat, liderul (de la client)
        }
        // @menționările din titlu sau detalii anunță omul (în afară de cel căruia i s-a dat, care știe deja)
        Inbox::mentioned(Mentions::in($task->title."\n".$task->notes, $by)->reject(fn (User $person) => $person->id === $task->assignee_id)
            ->filter(fn (User $person) => self::visibleTo($person)->whereKey($task->id)->exists()),
            $by, '#'.$task->id.' '.$task->title.' · '.$project->name, $task->notes ?: $task->title, TaskResource::getUrl('view', ['record' => $task]));

        return $task;
    }

    /** „Iau eu": sarcina devine a mea, trece în lucru și îmi devine focusul pe dashboard. */
    public function take(User $by, ?Update $update = null): void
    {
        DB::transaction(function () use ($by, $update) {
            $this->assignTo($by, $by, $update);
            if ($this->status !== TaskStatus::Doing) {
                $this->moveTo(TaskStatus::Doing, $by, update: $update);
            }
            $by->focusOn($this->title, $this->project_id, taskId: $this->id, source: 'task');
        });
    }

    /** De ce nu poate primi sarcina: pentru un om al clientului, ce anume lipsește (alt client sau proiectul nu e pornit ca vizibil clientului). */
    private static function noAccess(User $assignee, Project $project): string
    {
        if ($assignee->isClient()) {
            return $project->client_id && $project->client_id === $assignee->client_id
                ? "{$project->name} is not visible to the client yet, so {$assignee->name} cannot see it: turn on \"Visible to client\" in the project's settings first."
                : "{$assignee->name} is a person of another client and cannot see {$project->name}.";
        }

        return "{$assignee->name} does not have access to {$project->name}: add them to the project first.";
    }

    /** Dă sarcina cuiva sau nimănui (atunci o sarcină deschisă redevine liberă și „De făcut"). */
    public function assignTo(?User $assignee, User $by, ?Update $update = null): void
    {
        if ($assignee && ! $assignee->canSeeProject($this->project)) {           // și un om al clientului: doar pe proiectele firmei lui vizibile lui
            throw new InvalidArgumentException(self::noAccess($assignee, $this->project));
        }
        if ($this->assignee_id === $assignee?->id) {
            return;
        }
        DB::transaction(function () use ($assignee, $by, $update) {
            $previous = $this->assignee;
            $from = $previous?->name;
            $this->forceFill(['assignee_id' => $assignee?->id, 'assignee_seen_at' => $assignee?->is($by) ? now() : null])->save();
            $this->unsetRelation('assignee');
            $this->record($assignee?->is($by) ? TaskEventType::Taken : TaskEventType::Assigned, $by, $from, $assignee?->name, $update);

            if (! $assignee && $this->status->isOpen() && $this->status !== TaskStatus::Todo) {
                $this->moveTo(TaskStatus::Todo, $by, update: $update);
            }
            Inbox::assigned($this, $by);                                 // clopoțelul: dată de altcineva
            Inbox::handedOver($this, $previous, $by);                    // și cui o avea: preluată sau dată altcuiva de un coleg
        });
    }

    /**
     * Mută sarcina în altă stare. O sarcină liberă mutată în lucru, blocată sau gata devine a celui care o mută.
     * Aceeași stare venită dintr-un update e o pomenire („încă în lucru").
     */
    public function moveTo(TaskStatus $status, User $by, ?string $reason = null, ?Update $update = null): void
    {
        DB::transaction(function () use ($status, $by, $reason, $update) {
            if ($this->assignee_id === null && in_array($status, [TaskStatus::Doing, TaskStatus::Blocked, TaskStatus::Done], true)) {
                $this->assignTo($by, $by, $update);
            }

            $reason = $status === TaskStatus::Blocked ? (self::clean($reason) ?? $this->blocked_reason) : null;
            if ($status === $this->status && $reason === $this->blocked_reason) {
                if ($update) {
                    $this->mention($by, $update);
                }

                return;
            }

            $from = $this->status->value;
            $this->forceFill([
                'status' => $status,
                'blocked_reason' => $reason,
                'started_at' => $status === TaskStatus::Doing ? ($this->started_at ?? now()) : $this->started_at,
                'done_at' => $status === TaskStatus::Done ? now() : null,
            ])->save();
            $this->record(TaskEventType::Status, $by, $from, $status->value, $update);
            if (! $update) {
                Channels::taskLater($this, $by);                          // închisă sau blocată: în canalul proiectului (dintr-un update o spune update-ul)
            }
            if ($status === TaskStatus::Done) {
                TaskNotices::done($this, $by);                           // de la client: clientul află că e gata
                Inbox::done($this, $by);                                 // clopoțelul: cine a cerut-o și omul ei
                $this->repeatNext($by);                                  // recurentă: următoarea, cu termenul mutat
            }
        });
    }

    /**
     * O sarcină recurentă terminată aduce următoarea: același proiect, titlu, om și detalii (pașii debifați), termenul mutat cu un interval
     * de la termenul ei (sau de azi, dacă n-avea), sărind peste intervalele deja trecute. O singură dată: redeschisă și închisă din nou, nu dublează.
     */
    public function repeatNext(User $by): ?self
    {
        if (! $this->repeat || $this->project?->is_archived || self::where('repeated_from_id', $this->id)->exists()) {
            return null;
        }
        $tz = ($this->assignee ?? $by)->tz();
        $today = now($tz)->startOfDay();
        $next = $this->repeat->next(Carbon::parse(($this->due_on ?? $today)->toDateString(), $tz));
        while ($next->lt($today)) {
            $next = $this->repeat->next($next);
        }
        $task = self::add($this->project, $by, $this->title, Checklist::reset($this->notes), $this->assignee?->is_active ? $this->assignee : null,
            $this->source instanceof Source ? $this->source : Source::Web, due: $next->toDateString(), repeat: $this->repeat);
        $task->forceFill(['repeated_from_id' => $this->id])->saveQuietly();

        return $task;
    }

    /** Pomenită într-un update fără să-și schimbe starea. */
    public function mention(User $by, Update $update): void
    {
        $this->record(TaskEventType::Mentioned, $by, to: $this->status->value, update: $update);
    }

    public function edit(User $by, string $title, ?string $notes, string|false|null $due = false, TaskRepeat|false|null $repeat = false): void
    {
        DB::transaction(function () use ($by, $title, $notes, $due, $repeat) {
            $this->forceFill(['title' => trim($title), 'notes' => self::clean($notes)]
                + ($due === false ? [] : ['due_on' => $due ?: null])                             // false: termenul rămâne
                + ($repeat === false ? [] : ['repeat' => $repeat]))->save();                     // false: recurența rămâne
            $this->record(TaskEventType::Edited, $by);
        });
    }

    /**
     * Mută sarcina pe alt proiect, cu fișierele ei. Omul rămâne pe ea doar dacă poate lucra și pe proiectul nou (altfel devine liberă).
     * Cine are voie (poate edita sarcina și vede ambele proiecte) verifică apelantul. Rămâne în istoria sarcinii.
     */
    public function moveToProject(Project $project, User $by): void
    {
        DB::transaction(function () use ($project, $by) {
            $from = $this->project?->name;
            $keep = $this->assignee_id && User::assignableOn($project)->whereKey($this->assignee_id)->exists();
            $this->forceFill(['project_id' => $project->id] + ($keep ? [] : ['assignee_id' => null]))->save();
            $this->attachments()->update(['project_id' => $project->id]);
            $this->record(TaskEventType::Edited, $by, $from, $project->name);
        });
        $this->unsetRelation('project');
    }

    /** Pașii bifați din lista de verificat a detaliilor („- [ ]"), dacă are. @return array{done: int, total: int}|null */
    public function checklist(): ?array
    {
        return Checklist::count($this->notes);
    }

    /** Câți pași din lista de verificat nu sunt bifați (0 fără listă): la „Gata", un avertisment, nu o oprire. */
    public function uncheckedSteps(): int
    {
        return ($list = $this->checklist()) ? $list['total'] - $list['done'] : 0;
    }

    /** Închide sarcina, cu dovada (commit, adresa unde se vede, o notă), dacă e. */
    public function close(User $by, ?string $proof = null, ?Update $update = null): void
    {
        if (filled($proof = self::clean($proof))) {
            $this->forceFill(['proof' => mb_substr($proof, 0, 500)])->save();
        }
        $this->moveTo(TaskStatus::Done, $by, update: $update);
    }

    /** Termenul, scurt și pe înțeles („azi", „mâine", „joi", „12 oct."); depășit, dacă a trecut și sarcina e încă deschisă. @return array{label: string, overdue: bool}|null */
    public function due(?User $viewer = null): ?array
    {
        if (! $this->due_on) {
            return null;
        }
        $tz = ($viewer ?? auth()->user())?->tz() ?? config('app.timezone');
        $today = now($tz)->startOfDay();
        $date = Carbon::parse($this->due_on->toDateString(), $tz);
        $days = (int) $today->diffInDays($date, false);
        $label = match (true) {
            $days === 0 => __('today'),
            $days === 1 => __('tomorrow'),
            $days > 1 && $days < 7 => $date->locale(app()->getLocale())->isoFormat('dddd'),
            default => $date->locale(app()->getLocale())->isoFormat('D MMM'),
        };

        return ['label' => $label, 'overdue' => $days < 0 && $this->status->isOpen(), 'today' => $days === 0 && $this->status->isOpen(), 'days' => $days];
    }

    /** „Nouă": dată cuiva de altcineva și încă nevăzută de el. */
    public function isNew(): bool
    {
        return $this->assignee_id !== null && $this->assignee_seen_at === null;
    }

    public function markSeen(): void
    {
        if ($this->isNew()) {
            $this->forceFill(['assignee_seen_at' => now()])->saveQuietly();
        }
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::openValues());
    }

    /** Deschise și ale cuiva: „sarcinile mele" (dashboard, listă, last_update). */
    public function scopeOpenFor(Builder $query, User $user): void
    {
        $query->open()->where('assignee_id', $user->id);
    }

    /** Gata în ultimele zile (implicit 7), pentru listele care arată și ce s-a terminat de curând. */
    public function scopeRecentlyDone(Builder $query, int $days = 7): void
    {
        $query->where('status', TaskStatus::Done->value)->where('done_at', '>=', now()->subDays($days));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** Istoria; fără ordine fixată aici, ca latest() / oldest() să meargă - ordinea se cere acolo unde e nevoie. */
    /** Ce sarcini vede omul: echipa pe toate; clientul doar pe proiectele vizibile lui. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {                                                  // proiectele pe care nu le vede (al clientului, colaboratorului, restrânse)
            $query->whereIn('project_id', Project::visibleTo($user)->select('id'));
        }
    }

    /** Scrisă de cineva de la client (o arată lista și pagina sarcinii). */
    public function fromClient(): bool
    {
        return $this->creator?->isClient() ?? false;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    /** A mișcat-o cineva (altceva decât crearea) sau apare într-un update: atunci nu se mai șterge, se abandonează. */
    public function hasHistory(): bool
    {
        return $this->events()->where('type', '!=', TaskEventType::Created->value)->exists() || $this->items()->exists();
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    /** Commit-urile care o pomenesc în mesaj (#12), cele noi întâi. */
    public function commits(): HasMany
    {
        return $this->hasMany(TaskCommit::class)->orderByDesc('committed_on')->orderByDesc('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(UpdateItem::class);
    }

    private function record(TaskEventType $type, User $by, ?string $from = null, ?string $to = null, ?Update $update = null): void
    {
        $this->events()->create(['user_id' => $by->id, 'type' => $type, 'from' => $from, 'to' => $to, 'update_id' => $update?->id]);
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'source' => Source::class,
            'site_context' => 'array',
            'assignee_seen_at' => 'datetime',
            'started_at' => 'datetime',
            'done_at' => 'datetime',
            'due_on' => 'date',
            'repeat' => TaskRepeat::class,
        ];
    }
}
