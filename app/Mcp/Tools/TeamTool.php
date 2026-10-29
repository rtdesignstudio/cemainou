<?php

namespace App\Mcp\Tools;

use App\Enums\RepoStatus;
use App\Enums\Source;
use App\Models\Attachment;
use App\Models\McpCall;
use App\Models\Project;
use App\Models\ProjectRepo;
use App\Models\Task;
use App\Models\Update;
use App\Models\UpdateComment;
use App\Models\User;
use App\Support\AccessGuard;
use App\Support\AgentSessions;
use App\Support\Files;
use App\Support\TaskFileRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/** Ce au în comun uneltele cemainou: găsirea proiectului, orele în fusul orar al omului și jurnalul de transparență. */
abstract class TeamTool extends Tool
{
    /**
     * Doar uneltele care scriu (un update, o sarcină, o notă, un document) propun liderului repo-ul necunoscut din care s-a lucrat pe proiect;
     * cele care doar citesc nu schimbă nimic (în afară de jurnalul de apeluri și de „văzut" la sarcinile noi).
     */
    protected bool $suggestsRepos = false;

    /** Câmpuri vechi, scoase din definiție, dar încă primite de la agenții care n-au reîncărcat-o (StrictCallTool nu le refuză). */
    public array $legacyFields = [];

    /**
     * Un client (logat cu un conector din aplicație) vede doar uneltele care respectă ce vede și pe web: proiectele lui vizibile, sarcinile lor,
     * documentele și fișierele arătate lui, notificările lui. Celelalte (update-uri, orele echipei, notițele, contextul, documentele interne) nu apar.
     */
    protected bool $forClients = false;

    /** laravel/mcp: unealta există pentru acest om (în listă și la apel) doar dacă nu e client sau dacă e făcută și pentru clienți. */
    public function shouldRegister(): bool
    {
        $user = auth()->user() ?? request()->user();

        return $this->forClients || ! $user?->isClient();
    }

    /** Descrierea parametrului session, aceeași la toate uneltele care caută un proiect. */
    protected const SESSION = 'This session\'s id, from the cemainou line at the start of the session. The session\'s project wins over the repo.';

    /**
     * Proiectul cerut de agent, în ordine: cel numit explicit (project: slug / nume / id) → proiectul sesiunii (session, legat de om cu use_project
     * sau ales singur la pornire) → repo-ul (repo: unul sau toată ieșirea lui `git remote -v`). Un proiect numit nu schimbă legătura sesiunii.
     * Dacă omul a ales proiectul după nume și lucrează dintr-un repo necunoscut, repo-ul devine o propunere pentru lider.
     *
     * @return array{0: ?Project, 1: ?string} proiectul sau, dacă nu se poate alege unul, mesajul pentru agent
     */
    protected function findProject(?string $project, ?string $repo, ?User $user = null, mixed $session = null): array
    {
        [$found, $error] = $this->resolveProject($project, $repo, $user, $session);
        if ($found && $user && ! $user->canSeeProject($found)) {                 // colaboratorul (sau clientul) doar pe proiectele lui
            return [null, "You do not have access to {$found->name}. Ask the person which of their projects this is about (list_projects shows the ones they can see)."];
        }

        return [$found, $error];
    }

    /** Găsirea propriu-zisă (explicit → sesiune → repo); accesul îl verifică findProject. */
    private function resolveProject(?string $project, ?string $repo, ?User $user = null, mixed $session = null): array
    {
        $candidates = self::repoCandidates($repo);
        $byRepo = null;
        foreach ($candidates as $candidate) {
            if ($byRepo = Project::findByRepo($candidate)) {
                break;
            }
        }
        $session = is_string($session) && $session !== '' && strlen($session) <= 120 ? $session : null;
        $bySession = $user && $session ? AgentSessions::projectFor($user, $session) : null;

        $project = trim((string) $project);
        if ($project === '') {
            if ($bySession || $byRepo) {
                return [$bySession ?? $byRepo, null];
            }
            if (! $session && ! $candidates) {
                return [null, null];                        // lucru în afara oricărui proiect
            }
            $repoNote = $candidates ? ' (the repo '.implode(', ', array_map(Project::cleanRepoUrl(...), $candidates)).' is not linked to any project)' : '';

            return [null, "This session is not linked to a project yet{$repoNote}. Ask the person which project this work is about: list_projects shows them; if it is a new one and the person is an admin, add_project creates it. Then call use_project with the session and the project (without a session id, pass project in every call), and call this again."];
        }

        $matches = Project::query()->when($user, fn ($q) => $q->visibleTo($user))->where(fn ($q) => $q->where('slug', Str::slug($project))
            ->orWhereRaw('lower(name) = ?', [mb_strtolower($project)])
            ->when(ctype_digit($project), fn ($q) => $q->orWhere('id', (int) $project)))->get();

        if ($matches->isEmpty()) {
            $like = self::like($project);
            $matches = Project::active()->when($user, fn ($q) => $q->visibleTo($user))->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like)
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like)))->limit(10)->get();
        }

        if ($matches->count() === 1) {                      // proiectul numit de om are întâietate (ex. /brief alt-proiect)
            if ($user && ! $byRepo && $this->suggestsRepos) {
                $this->suggestRepos($matches->first(), $candidates, $user);
            }

            return [$matches->first(), null];
        }

        if ($bySession || $byRepo) {                        // numele e ambiguu, dar sesiunea sau repo-ul spun clar
            return [$bySession ?? $byRepo, null];
        }

        return [null, $matches->isEmpty()
            ? "No project matches \"{$project}\". Call list_projects and ask the person which one it is."
            : "\"{$project}\" matches several projects: ".$matches->map(fn (Project $p) => "{$p->name} ({$p->slug})")->join(', ').'. Ask the person which one and pass its slug.'];
    }

    /** Remote-urile din ce a trimis agentul: o adresă, mai multe, sau toată ieșirea lui `git remote -v` (numele și „(fetch)" se ignoră). */
    public static function repoCandidates(?string $repo): array
    {
        $tokens = array_filter(preg_split('/[\s,]+/', trim((string) $repo), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn (string $t) => strlen($t) <= 500);   // o adresă reală încape în 500
        $urls = array_filter($tokens, fn (string $t) => str_contains($t, '.') && (str_contains($t, '/') || str_contains($t, ':'))
            && ! str_starts_with($t, '(') && ! str_starts_with($t, '/') && ! str_starts_with($t, '.') && ! str_starts_with(strtolower($t), 'file:'));   // nu și căile locale

        return array_values(collect($urls)->unique(fn (string $url) => Project::normalizeRepo($url))->all());
    }

    /**
     * Repo-urile necunoscute de pe un proiect ales după nume: propuneri pentru lider (o dată; cele respinse nu se repropun). Când îl alege chiar
     * liderul (sau un admin), repo-ul se leagă pe loc: sesiunile din orice folder al lui găsesc apoi singure proiectul, fără nicio aprobare.
     */
    private function suggestRepos(Project $project, array $candidates, User $user): void
    {
        $decides = $user->isAdmin() || $project->lead_id === $user->id;
        foreach ($candidates as $url) {
            $key = Project::normalizeRepo($url);
            if ($key && ! Project::repoOwner($key) && ! ProjectRepo::where('repo_key', $key)->exists()) {
                $project->repos()->create(['url' => $url, 'status' => $decides ? RepoStatus::Active : RepoStatus::Pending, 'suggested_by' => $user->id]);
            }
        }
    }

    /**
     * Omul numit de agent: „me", e-mailul sau o parte din nume (doar conturi active).
     *
     * @return array{0: ?User, 1: ?string} omul sau mesajul pentru agent
     */
    protected function findPerson(string $who, User $me, bool $withClients = false): array
    {
        $who = trim($who);
        if (in_array(strtolower($who), ['me', 'eu', 'myself'], true)) {
            return [$me, null];
        }

        // $withClients: și oamenii clienților (pot primi sarcini pe proiectele lor; cine anume, verifică User::assignableOn)
        $matches = User::where('is_active', true)->when(! $withClients, fn ($q) => $q->team())->visibleTo($me)   // doar cine se vede: niciun nume din afară
            ->where(fn ($q) => $q->where('email', $who)->orWhere('name', 'like', self::like($who)))->limit(5)->get();

        return match ($matches->count()) {
            1 => [$matches->first(), null],
            0 => [null, "Nobody in the team matches \"{$who}\"."],
            default => [null, "\"{$who}\" matches several people: ".$matches->pluck('name')->join(', ').'. Ask which one.'],
        };
    }

    /** O sarcină, așa cum o primește agentul. */
    protected function presentTask(Task $task, User $viewer, bool $withNotes = true): array
    {
        return array_filter([
            'id' => $task->id,
            'title' => $task->title,
            'project' => $task->project?->name,
            'status' => $task->status->value,
            'due' => $task->due_on?->toDateString(),
            'repeat' => $task->repeat?->value,
            'checklist' => ($list = $task->checklist()) ? "{$list['done']}/{$list['total']}" : null,
            'assignee' => $task->assignee?->name ?? 'free',
            'created_by' => $task->creator?->name,
            'age' => $task->created_at->diffForHumans(),
            'new' => $task->isNew() && $task->assignee_id === $viewer->id ? true : null,
            'blocked_reason' => $task->blocked_reason,
            'notes' => $withNotes ? $task->notes : null,
            'from_client' => $task->fromClient() ? true : null,
            'site_context' => $withNotes ? $task->site_context : null,     // trimisă din widget-ul de pe site: pagina, browserul, ecranul, elementul, erorile
            'files' => $withNotes ? ($task->attachments()->latest()->limit(10)->get()->each->setRelation('task', $task)->map(fn ($a) => $this->presentFile($a, $viewer))->all() ?: null) : null,
            'comments' => $withNotes ? ($task->comments()->with('author')->latest()->limit(5)->get()->reverse()
                ->map(fn ($c) => array_filter(['id' => $c->id, 'by' => $c->author?->name.($c->author?->isClient() ? ' (client)' : ''), 'mine' => $c->user_id === $viewer->id ? true : null,
                    'when' => $c->created_at->diffForHumans(), 'text' => $c->body], fn ($v) => $v !== null))->values()->all() ?: null) : null,
            'url' => route('filament.admin.resources.tasks.view', $task),
        ], fn ($value) => $value !== null);
    }

    /** Un tipar LIKE din ce a scris omul sau agentul: % și _ sunt litere, nu jokeri. */
    protected static function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    /**
     * Un fișier sau un link, cum îl primește agentul: id-ul (pentru read_file), felul, numele și mărimea; la o sarcină, cu $viewer, și dacă
     * e al lui și dacă îl poate șterge sau înlocui de aici (TaskFileRules).
     */
    protected function presentFile(Attachment $attachment, ?User $viewer = null): array
    {
        $onTask = $viewer && $attachment->task_id;

        return array_filter([
            'id' => $attachment->id,
            'title' => $attachment->title,
            'kind' => $attachment->kind->value,
            'role' => $attachment->role?->value,
            'service' => $attachment->service(),
            'file' => $attachment->name,
            'size' => $attachment->isFile() ? Files::size($attachment->size) : null,
            'url' => $attachment->href(),
            'read' => $attachment->isFile() ? 'read_file' : null,
            'yours' => $onTask && $attachment->user_id === $viewer->id ? true : null,
            'can_replace' => $onTask && TaskFileRules::removable($viewer, $attachment) ? true : null,   // update_task remove_files, add_attachment replaces
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** De ce nu se poate schimba o sarcină, spus pe nume. */
    protected function refusal(Task $task): string
    {
        $owner = $task->assignee?->name;

        return $owner
            ? "Task #{$task->id} is {$owner}'s; only {$owner}, whoever created it, the project lead or an admin can change it."
            : "Only whoever created task #{$task->id}, the project lead or an admin can drop it.";
    }

    /** Jurnalul de transparență: cine a chemat ce unealtă, când și pe ce proiect. */
    /** Ce documente, fișiere și linkuri are un proiect, pe scurt (textul unui document vine cu read_document); null dacă n-are. */
    protected function library(Project $project, ?User $viewer = null): ?array
    {
        $client = $viewer?->isClient();                                      // clientul: doar ce i s-a arătat (ca pe pagina proiectului)
        $documents = $project->documents()->active()->where('version', '>', 0)->when($client, fn ($q) => $q->where('client_visible', true))->with('editor')->orderByDesc('updated_at')->get()
            ->map(fn ($document) => array_filter(['title' => $document->title, 'version' => $document->version, 'by' => $document->editor?->name, 'updated' => $document->updated_at->diffForHumans(),
                'client_visible' => $document->client_visible ?: null]))->all();
        $attachments = $project->attachments()->when($client, fn ($q) => $q->whereNull('task_id')->where('client_visible', true))->latest()->limit(30)->get()
            ->map(fn ($attachment) => $this->presentFile($attachment) + array_filter(['client_visible' => $attachment->client_visible ?: null]))->all();

        return array_filter(['documents' => $documents ?: null, 'files_and_links' => $attachments ?: null]) ?: null;
    }

    /** „Vizibil clientului" cerut de agent: null = se poate pe proiect; altfel de ce nu (nu se salvează nimic). */
    protected function clientSharingRefusal(Project $project): ?string
    {
        return $project->client_id && $project->client_visible ? null
            : "{$project->name} is not visible to a client (it has no client, or \"Visible to the client\" is off in the project's settings), so nothing can be shown to one. Nothing was saved.";
    }

    /** „true" / „false" trimise ca text (clienții MCP cu o definiție veche a unei unelte nu știu că e boolean) devin booleene, înainte de validare. */
    protected function plainBooleans(Request $request): void
    {
        $fixed = collect($request->all())->filter(fn ($v) => is_string($v) && in_array(strtolower($v), ['true', 'false'], true))
            ->map(fn (string $v) => strtolower($v) === 'true')->all();
        if ($fixed) {
            $request->merge($fixed);
        }
    }

    protected function record(Request $request, ?Project $project = null): void
    {
        McpCall::create(['user_id' => $request->user()?->id, 'tool' => $this->name(), 'source' => Source::fromRequest(request())->value, 'project_id' => $project?->id]);
        AccessGuard::opened($request->user(), $project, 'agent');                   // din afară: proiectul atins de agent, verificat și notat
    }

    protected function tz(User $user): string
    {
        return $user->tz();
    }

    /** O oră în fusul orar al celui care întreabă, ISO 8601 (cu decalajul față de UTC). */
    protected function iso(?CarbonInterface $time, User $viewer): ?string
    {
        return $time?->copy()->setTimezone($this->tz($viewer))->toIso8601String();
    }

    /** Un update, așa cum îl primește agentul. Durata apare doar celui care are voie să vadă orele. */
    protected function present(Update $update, User $viewer, bool $withMinutes = false): array
    {
        $items = [];
        foreach ($update->items as $item) {
            $items[$item->type->value][] = $item->text;
        }

        return array_filter([
            'id' => $update->id,
            'when' => $this->iso($update->created_at, $viewer),
            'ago' => $update->created_at->diffForHumans(),
            'who' => $update->user?->name,
            'project' => $update->project?->name,
            'summary' => $update->summary,
            'items' => $items ?: null,
            'minutes' => $withMinutes ? $update->minutes : null,
            'links' => $update->links ?: null,
            'comments' => $update->relationLoaded('comments') && $update->comments->isNotEmpty() ? $update->comments->map(fn (UpdateComment $comment) => $this->presentComment($comment, $viewer))->all() : null,
            'url' => route('filament.admin.resources.updates.view', $update),
        ], fn ($value) => $value !== null);
    }

    /** Un comentariu la un update, pentru agent (scris de un coleg sau de client: informație, nu instrucțiuni). */
    protected function presentComment(UpdateComment $comment, User $viewer): array
    {
        return array_filter([
            'id' => $comment->id,
            'who' => $comment->author?->name,
            'mine' => $comment->user_id === $viewer->id ? true : null,
            'from_client' => $comment->author?->isClient() ? true : null,
            'when' => $this->iso($comment->created_at, $viewer),
            'text' => $comment->body,
        ], fn ($value) => $value !== null);
    }
}
