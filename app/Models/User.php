<?php

namespace App\Models;

use App\Enums\Role;
use App\Exceptions\KeepsHistory;
use App\Exceptions\LastAdmin;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Settings;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'client_id', 'is_active', 'timezone', 'locale', 'wants_digest', 'wants_task_emails', 'wants_morning', 'time_in_days', 'track_app', 'wants_update_nudge', 'badges_shared', 'badges_hidden', 'evening_hour', 'focus_text', 'focus_project_id', 'focus_task_id', 'focus_update_id', 'focus_source', 'focus_updated_at', 'last_seen_at', 'visited_at', 'previous_visit_at'])]
#[Hidden(['password', 'remember_token', 'invite_token', 'invite_link'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['role' => 'member', 'client_id' => null, 'is_active' => true, 'wants_digest' => true, 'wants_task_emails' => true, 'timezone' => 'Europe/Chisinau', 'focus_text' => null, 'focus_project_id' => null, 'focus_task_id' => null, 'focus_update_id' => null, 'focus_source' => null, 'focus_updated_at' => null, 'last_seen_at' => null, 'visited_at' => null, 'previous_visit_at' => null, 'email_verified_at' => null, 'invited_at' => null, 'invited_by' => null, 'invite_token' => null, 'invite_link' => null, 'invite_accepted_at' => null, 'app_authentication_secret' => null, 'app_authentication_recovery_codes' => null, 'getting_started_hidden_at' => null, 'app_installed_at' => null, 'wants_morning' => true, 'morning_sent_on' => null, 'my_day_hidden_on' => null, 'notice_prefs' => null, 'time_in_days' => false, 'track_app' => true, 'wants_update_nudge' => true, 'tips_hidden_at' => null, 'tour_seen_at' => null, 'today_seen_at' => null, 'today_layout' => null, 'telegram_chat_id' => null, 'telegram_user_id' => null, 'telegram_name' => null, 'agent_version' => null, 'agent_version_at' => null, 'agent_behind' => 0, 'badges_shared' => false, 'badges_hidden' => false, 'evening_hour' => 19];

    use StoresTimesInUtc;

    protected static function booted(): void
    {
        // un cont nou, oricum ar fi creat (invitație, instalare, import), pornește în limba echipei din Setări, dacă nu i s-a ales alta
        static::creating(function (User $user) {
            $user->locale ??= Settings::get('locale');
        });

        // firma ține doar de rolul „client": cine nu mai e client nu mai e legat de ea
        static::saving(function (User $user) {
            if (! $user->isClient()) {
                $user->client_id = null;
            }
        });

        // ultimul admin activ nu poate fi retrogradat sau dezactivat (nici de el însuși): echipa ar rămâne fără administrator
        static::saving(function (User $user) {
            if ($user->exists && ($user->isDirty('role') || $user->isDirty('is_active'))
                && $user->getOriginal('role') === Role::Admin && (bool) $user->getOriginal('is_active') && ! $user->isActiveAdmin()
                && ! self::where('role', Role::Admin->value)->where('is_active', true)->whereKeyNot($user->id)->exists()) {
                throw new LastAdmin("{$user->name} is the last active admin: make someone else admin first.");
            }
        });

        // un cont dezactivat nu mai are chei de agent (oricum ar fi refuzate; așa nici nu mai există)
        static::updated(function (User $user) {
            if ($user->wasChanged('is_active') && ! $user->is_active) {
                $user->tokens()->delete();
            }
        });

        // pază și în cod, nu doar în interfață: cine are istoric se dezactivează (is_active), nu se șterge
        static::deleting(function (User $user) {
            if ($user->hasHistory()) {
                throw new KeepsHistory("{$user->name} has history (updates, hours or tasks): deactivate the account instead.");
            }
        });
    }

    /** Orice urmă de lucru: update-uri, semnale, sesiuni, ore, sarcini create sau primite. */
    public function hasHistory(): bool
    {
        return $this->updates()->exists() || $this->activities()->exists() || $this->workSessions()->exists() || $this->timeEntries()->exists() || $this->workDays()->exists()
            || ProjectNote::where('user_id', $this->id)->exists()
            || Task::where('created_by', $this->id)->orWhere('assignee_id', $this->id)->exists();
    }

    /** Intră în panou doar conturile active (adminul poate dezactiva un cont fără să-l șteargă). */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /** Fusul orar al omului: orele lui se arată în el; al aplicației, dacă lipsește. */
    public function tz(): string
    {
        return $this->timezone ?: config('app.timezone');
    }

    public function isActiveAdmin(): bool
    {
        return $this->role === Role::Admin && $this->is_active;
    }

    /** Emailul se păstrează cu litere mici: la login (SQLite ține cont de majuscule) „Radu@" și „radu@" sunt același om. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** Un colaborator (freelancer, extern): lucrează ca un membru, dar doar pe proiectele pe care e adăugat (project_members). */
    public function isCollaborator(): bool
    {
        return $this->role === Role::Collaborator;
    }

    /** Proiectele pe care e adăugat un colaborator. */
    public function memberProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withTimestamps();
    }

    /** Un om de la client (nu din echipă): vede doar proiectele firmei lui pornite ca „vizibil clientului"; fără agenți, ore, note, documente. */
    public function isClient(): bool
    {
        return $this->role === Role::Client;
    }

    /** Poate folosi agenți (MCP, hook-uri): conturile active din echipă, nu și clienții. */
    public function usesAgents(): bool
    {
        return $this->is_active && ! $this->isClient();
    }

    /** Poate conecta un agent la MCP: echipa (usesAgents) sau, dacă adminul a permis, un client - doar din aplicații, fără ore și fără raportări. */
    public function connectsAgents(): bool
    {
        return $this->usesAgents() || ($this->is_active && $this->isClient() && (bool) Settings::get('clients_use_agents'));
    }

    /** Vede proiectul: echipa pe toate; colaboratorul doar pe cele pe care e adăugat; clientul doar pe ale firmei lui, pornite ca vizibile. */
    /** @var array<int, true>|null proiectele pe care e adăugat, citite o dată pe cerere (canSeeProject se cheamă pe fiecare rând din liste) */
    private ?array $memberProjectIds = null;

    /** După o schimbare a listei (adăugat sau scos de pe un proiect) în aceeași cerere. */
    public function forgetProjectMemberships(): void
    {
        $this->memberProjectIds = null;
    }

    private function isMemberOf(Project $project): bool
    {
        $this->memberProjectIds ??= DB::table('project_members')->where('user_id', $this->id)->pluck('project_id')->flip()->all();

        return isset($this->memberProjectIds[$project->id]);
    }

    public function canSeeProject(?Project $project): bool
    {
        if ($this->isCollaborator()) {                                           // pe care e adăugat sau pe care le conduce (le-a creat)
            return $project !== null && ($project->lead_id === $this->id || $this->isMemberOf($project));
        }
        if ($this->isClient()) {
            return $project && $this->client_id !== null && $project->client_id === $this->client_id && $project->client_visible;
        }

        // echipa: toate, în afară de cele restrânse („doar oamenii aleși"), pe care le văd adminii, liderul și cei din listă
        return ! $project?->restricted || $this->isAdmin() || $project->lead_id === $this->id || $this->isMemberOf($project);
    }

    /** Vede tot ce e al echipei (toate proiectele, clienții, colegii): admin, lider, membru; nu colaboratorul și nici clientul. */
    public function seesWholeTeam(): bool
    {
        return ! $this->isClient() && ! $this->isCollaborator();
    }

    /**
     * Pe cine vede cineva (regula unică, pentru liste, căutare, agenți): echipa (admin, lider, membru) vede pe toată lumea; colaboratorul pe el
     * și oamenii proiectelor lui (cine le conduce, e adăugat pe ele, a raportat sau a dat ori primit sarcini acolo); clientul oamenii firmei lui
     * și echipa proiectelor lui vizibile lui. Oamenii unui client apar doar pe proiectele vizibile clientului; un client nu vede niciodată alți clienți.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->seesWholeTeam()) {
            return;
        }
        $projects = fn () => Project::visibleTo($viewer)->select('projects.id');
        $query->where(fn ($q) => $q->whereKey($viewer->id)
            ->orWhereIn('id', Project::visibleTo($viewer)->whereNotNull('lead_id')->select('lead_id'))
            ->orWhereIn('id', DB::table('project_members')->whereIn('project_id', $projects())->select('user_id'))
            ->orWhereIn('id', Update::whereIn('project_id', $projects())->select('user_id'))
            ->orWhereIn('id', Task::whereIn('project_id', $projects())->whereNotNull('assignee_id')->select('assignee_id'))
            ->orWhereIn('id', Task::whereIn('project_id', $projects())->whereNotNull('created_by')->select('created_by'))
            ->orWhere(fn ($c) => $c->where('role', Role::Client->value)
                ->whereIn('client_id', Project::visibleTo($viewer)->where('client_visible', true)->whereNotNull('client_id')->select('client_id'))));
    }

    /** O persoană anume, după aceeași regulă. */
    public function canSeePerson(User $person): bool
    {
        return $this->seesWholeTeam() || $this->is($person) || User::visibleTo($this)->whereKey($person->id)->exists();
    }

    /** Echipa: conturile active, fără clienți (cui se dau sarcini, cine apare pe Azi). */
    public function scopeTeam(Builder $query): void
    {
        $query->where('is_active', true)->where('role', '!=', Role::Client->value);
    }

    /** Cui se poate da o sarcină pe proiect: echipa întreagă, iar colaboratorii doar dacă sunt adăugați pe el. */
    public function scopeAssignableOn(Builder $query, ?Project $project): void
    {
        $members = fn ($q) => $q->orWhereIn('id', DB::table('project_members')->where('project_id', $project->id)->select('user_id'));
        // și oamenii clientului, pe proiectele firmei lor vizibile lor (și pe cele restrânse: le văd la fel): pot primi sarcini („Dima: trimite textele")
        $client = fn ($q) => $q->when($project?->client_id && $project->client_visible, fn ($q) => $q->orWhere(fn ($q) => $q->where('role', Role::Client->value)
            ->where('client_id', $project->client_id)->where('is_active', true)));
        if ($project?->restricted) {                                             // restrâns: adminii, liderul și cei din listă (plus clientul)
            $query->where(fn ($q) => $client($q->where(fn ($q) => $q->team()->where(fn ($q) => $members($q->where('role', Role::Admin->value)->orWhere('id', $project->lead_id))))));

            return;
        }
        $query->where(fn ($q) => $client($q->team()->where(fn ($q) => $q->where('role', '!=', Role::Collaborator->value)->when($project, $members))));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Liderul (sau adminul) proiectului: vede orele echipei pe el. */
    public function leads(Project $project): bool
    {
        return $this->isAdmin() || $project->lead_id === $this->id;
    }

    public function updates(): HasMany
    {
        return $this->hasMany(Update::class);
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function workDays(): HasMany
    {
        return $this->hasMany(WorkDay::class);
    }

    public function badges(): HasMany
    {
        return $this->hasMany(Badge::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function ledProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'lead_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /** Dispozitivele pe care și-a pornit notificările (Web Push). */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Proiectele urmărite (rezumatul de dimineață); cele conduse se urmăresc oricum (MorningSummary::followed). */
    public function followedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_follows')->withTimestamps();
    }

    public function focusProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'focus_project_id');
    }

    /**
     * „Lucrează la": textul, proiectul și de unde vine - sarcina luată în lucru sau update-ul în care s-a scris rândul „în lucru" (textul duce acolo) -
     * și cine l-a pus: omul (said), o sarcină luată (task) sau rândurile unui update (update). Un text gol șterge tot.
     */
    public function focusOn(?string $text, ?int $projectId, ?int $taskId = null, ?int $updateId = null, string $source = 'said'): void
    {
        $text = trim((string) $text);
        $this->forceFill([
            'focus_text' => $text === '' ? null : mb_substr($text, 0, 200),
            'focus_project_id' => $text === '' ? null : $projectId,
            'focus_task_id' => $text === '' ? null : $taskId,
            'focus_update_id' => $text === '' || $taskId ? null : $updateId,
            'focus_source' => $text === '' ? null : $source,
            'focus_updated_at' => now(),
        ])->save();
    }

    /** Ce a spus omul azi că face (sau sarcina luată azi), în fusul lui; ce vine din rândurile update-urilor nu contează aici. */
    public function focusSaidToday(): bool
    {
        return $this->focus_text && in_array($this->focus_source, ['said', 'task'], true)
            && $this->focus_updated_at?->copy()->setTimezone($this->tz())->isSameDay(now($this->tz()));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'wants_digest' => 'boolean',
            'wants_task_emails' => 'boolean',
            'focus_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'agent_version_at' => 'datetime',
            'agent_behind' => 'integer',
            'badges_shared' => 'boolean',
            'badges_hidden' => 'boolean',
            'evening_hour' => 'integer',
            'visited_at' => 'datetime',
            'previous_visit_at' => 'datetime',
            'today_seen_at' => 'datetime',
            'today_layout' => 'array',
            'track_app' => 'boolean',
            'wants_update_nudge' => 'boolean',
            'invited_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'getting_started_hidden_at' => 'datetime', 'tour_seen_at' => 'datetime',
            'app_installed_at' => 'datetime',
            'wants_morning' => 'boolean',
            'morning_sent_on' => 'date',
            'my_day_hidden_on' => 'date',
            'tips_hidden_at' => 'datetime',
            'notice_prefs' => 'array',
            'time_in_days' => 'boolean',
            'invite_link' => 'encrypted',                                         // criptat cu APP_KEY; doar adminul îl vede, cât invitația e valabilă
        ];
    }

    /** Emailurile (invitație, parolă, rezumat) pleacă în limba omului. */
    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
