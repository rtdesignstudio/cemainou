<?php

namespace App\Models;

use App\Enums\WorkCategory;
use App\Exceptions\KeepsHistory;
use App\Models\Concerns\StoresTimesInUtc;
use App\Support\FeedbackWidget;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['client_id', 'client_visible', 'lead_id', 'name', 'slug', 'description', 'repo_url', 'categories', 'color', 'notify_slack_webhook', 'notify_telegram_chat', 'is_archived', 'restricted', 'widget_enabled', 'widget_domains', 'widget_key', 'stages'])]
class Project extends Model
{
    use HasFactory, StoresTimesInUtc;

    /** Aceleași valori implicite ca în baza de date, ca un model proaspăt creat să le aibă și în memorie. */
    protected $attributes = ['restricted' => false, 'client_id' => null, 'client_visible' => false, 'lead_id' => null, 'description' => null, 'repo_url' => null, 'categories' => null, 'repo_key' => null, 'category' => null, 'color' => null, 'notify_slack_webhook' => null, 'notify_telegram_chat' => null, 'is_archived' => false];

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            $project->slug = $project->slug ?: Str::limit(Str::slug($project->name), 240, '');   // slug-ul poate ieși mai lung decât numele (щ → shch)
            $project->repo_url = self::cleanRepoUrl($project->repo_url);
            $project->repo_key = self::normalizeRepo($project->repo_url);
            $project->widget_domains = FeedbackWidget::cleanDomains($project->widget_domains);   // „https://www.autonord.test/" → „autonord.test"
            if ($project->widget_enabled && ! $project->widget_key) {
                $project->widget_key = Str::lower(Str::random(24));                          // cheia publică din linia de cod a site-ului
            }
        });

        // un proiect cu update-uri sau sarcini se arhivează, nu se șterge
        static::deleting(function (Project $project) {
            if ($project->hasHistory()) {
                throw new KeepsHistory("{$project->name} has updates or tasks: archive it instead.");
            }
        });
    }

    public function hasHistory(): bool
    {
        return $this->updates()->exists() || $this->tasks()->exists() || $this->documents()->exists() || $this->attachments()->exists();
    }

    /**
     * Forma comună a unei adrese de repo, ca agentul să-și găsească proiectul indiferent cum e scris remote-ul:
     * git@github.com:rt/site.git, https://github.com/rt/site, ssh://git@github.com/rt/site.git → github.com/rt/site
     */
    public static function normalizeRepo(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $url = preg_replace('#^[a-z+]+://#i', '', $url);           // https://, ssh://, git+ssh://
        $url = preg_replace('#^[^@/]+@#', '', $url);               // git@, user:token@
        $url = preg_replace('#^([^/:]+):(?!\d+/)#', '$1/', $url);  // host:cale (scp) → host/cale; host:22/ rămâne port
        $url = preg_replace('#^([^/]+):\d+/#', '$1/', $url);       // portul nu contează
        $url = preg_replace('#\.git$#i', '', rtrim($url, '/'));

        return strtolower($url);
    }

    /**
     * Adresa fără parolă sau token (https://oauth2:glpat-…@gitlab.com/x → https://oauth2@gitlab.com/x): un remote cu credențiale
     * vine uneori de la agent sau din formular, iar secretul n-are ce căuta în baza de date sau pe ecran.
     */
    public static function cleanRepoUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url === '' ? null : preg_replace('#^([a-z+]+://)([^/@:]+):[^/@]+@#i', '$1$2@', $url);
    }

    /** Proiectul unui repo, după remote-ul git trimis de agent: repo-ul principal sau unul în plus, legat. */
    public static function findByRepo(?string $remote): ?self
    {
        return self::repoOwner(self::normalizeRepo($remote));
    }

    /** Proiectul care folosește deja un repo (după cheia normalizată), ca principal sau ca repo în plus legat. */
    public static function repoOwner(?string $key): ?self
    {
        if (! $key) {
            return null;
        }

        return self::where('repo_key', $key)->first()
            ?? ProjectRepo::with('project')->linked()->where('repo_key', $key)->first()?->project;
    }

    /** Ce proiecte vede omul: echipa pe toate; clientul doar pe ale firmei lui, pornite ca vizibile. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $mine = DB::table('project_members')->where('user_id', $user->id)->select('project_id');
        if ($user->isClient()) {
            $query->where('client_id', $user->client_id ?? 0)->where('client_visible', true);
        } elseif ($user->isCollaborator()) {                                     // colaboratorul: proiectele pe care e adăugat și cele pe care le conduce
            $query->where(fn ($q) => $q->whereIn('projects.id', $mine)->orWhere('projects.lead_id', $user->id));
        } elseif (! $user->isAdmin()) {                                          // echipa: toate, în afară de cele restrânse la care nu e liderul sau în listă
            $query->where(fn ($q) => $q->where('projects.restricted', false)->orWhere('projects.lead_id', $user->id)->orWhereIn('projects.id', $mine));
        }
    }

    /** Colaboratorii adăugați pe proiect (freelanceri, externi). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('added_by')->withTimestamps();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_archived', false);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /** Oamenii clientului care pot primi raportul lunar: activi, ai firmei proiectului, doar pe un proiect vizibil clientului. @return Collection<int, User> */
    public function clientPeople(): Collection
    {
        if (! $this->client_id || ! $this->client_visible) {
            return collect();
        }

        return User::where('role', 'client')->where('is_active', true)->where('client_id', $this->client_id)->orderBy('name')->get();
    }

    public function updates(): HasMany
    {
        return $this->hasMany(Update::class);
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ProjectNote::class);
    }

    /** Repo-urile în plus (legate, propuse sau respinse); cele legate: ->linked(). */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Contextul proiectului (/ctx), dacă are. */
    public function contextDocument(): ?Document
    {
        return $this->documents()->context()->with('editor')->first();
    }

    /** Fișierele și linkurile proiectului (cele de pe sarcini sunt ale sarcinilor). */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->whereNull('task_id');
    }

    public function repos(): HasMany
    {
        return $this->hasMany(ProjectRepo::class);
    }

    /**
     * Etapele, în ordine, fiecare cu intervalul ei: fără „până la", ține până în ziua dinaintea etapei următoare (ultima, până azi).
     *
     * @return list<array{name: string, from: string, to: string, open: bool}>
     */
    public function stagePeriods(string $today): array
    {
        $stages = collect($this->stages ?? [])->filter(fn ($s) => filled($s['name'] ?? null) && filled($s['from'] ?? null))->sortBy('from')->values();

        return $stages->map(function (array $s, int $i) use ($stages, $today) {
            $next = $stages[$i + 1]['from'] ?? null;
            $to = ($s['to'] ?? null) ?: ($next ? Carbon::parse($next)->subDay()->toDateString() : $today);

            return ['name' => $s['name'], 'from' => $s['from'], 'to' => min($to, $today), 'open' => blank($s['to'] ?? null) && ! $next];
        })->all();
    }

    protected function casts(): array
    {
        return ['notify_slack_webhook' => 'encrypted', 'client_visible' => 'boolean', 'categories' => AsEnumCollection::of(WorkCategory::class), 'is_archived' => 'boolean', 'restricted' => 'boolean', 'widget_enabled' => 'boolean', 'widget_domains' => 'array', 'stages' => 'array'];
    }
}
