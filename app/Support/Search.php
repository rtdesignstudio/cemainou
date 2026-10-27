<?php

namespace App\Support;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Update;
use App\Models\UpdateComment;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Căutarea (ecranul de căutare ⌘K și pagina „Toate rezultatele"): sarcini, proiecte, update-uri (și rândurile lor), documente, oameni, notițe,
 * comentarii - doar ce vede omul pe web. Fiecare rezultat: titlul, un fragment cu potrivirea îngroșată, de unde e și când, adresa.
 * Filtre: felul, „doar ale mele", un proiect. „#179" deschide sarcina direct.
 */
class Search
{
    public const TYPES = ['tasks', 'projects', 'updates', 'documents', 'people', 'notes', 'comments'];

    /** Eticheta fiecărui fel, în limba omului. */
    public static function label(string $type): string
    {
        return match ($type) {
            'tasks' => __('Tasks'), 'projects' => __('Projects'), 'updates' => __('Updates'), 'documents' => __('Documents'),
            'people' => __('People'), 'notes' => __('Notes'), 'comments' => __('Comments'), default => $type,
        };
    }

    /**
     * @return array<string, list<array{title: string, snippet: ?HtmlString, meta: ?string, url: string, id?: int}>> pe feluri, în ordinea TYPES; doar cele cu rezultate
     */
    public static function find(User $user, string $query, ?string $type = null, bool $mine = false, ?int $project = null, int $limit = 6): array
    {
        $q = trim($query);
        $out = [];
        if (preg_match('/^#?(\d{1,7})$/', $q, $m) && ($task = Task::visibleTo($user)->with(['project', 'assignee'])->find((int) $m[1]))) {   // „#179": sarcina, întâi
            $out['tasks'][] = self::task($task, $q);
        }
        if (mb_strlen($q) < 2) {
            return $out;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $client = $user->isClient();
        $projects = $project ? [$project] : null;
        $on = fn ($builder, string $column = 'project_id') => $projects ? $builder->whereIn($column, $projects) : $builder;
        $wants = fn (string $t) => ! $type || $type === $t;

        if ($wants('tasks')) {
            $found = $on(Task::visibleTo($user)->with(['project', 'assignee']))->when($mine, fn ($b) => $b->where('assignee_id', $user->id))
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('notes', 'like', $like))
                ->orderByRaw('case when title like ? then 0 else 1 end', [$like])->latest('updated_at')->limit($limit)->get();
            foreach ($found as $task) {
                if (! collect($out['tasks'] ?? [])->contains('id', $task->id)) {
                    $out['tasks'][] = self::task($task, $q);
                }
            }
        }
        if ($wants('projects') && ! $mine) {
            Project::visibleTo($user)->with('client')->when($projects, fn ($b) => $b->whereIn('id', $projects))
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('description', 'like', $like))
                ->orderBy('is_archived')->orderByRaw('case when name like ? then 0 else 1 end', [$like])->limit($limit)->get()
                ->each(function (Project $p) use (&$out, $q) {
                    $out['projects'][] = ['title' => $p->name, 'snippet' => Str::contains(mb_strtolower($p->name), mb_strtolower($q)) ? null : self::snippet($p->description, $q),
                        'meta' => collect([$p->client?->name ?? __('Internal'), $p->is_archived ? __('archived') : null])->filter()->join(' · '),
                        'url' => ProjectResource::getUrl('view', ['record' => $p])];
                });
        }
        if ($wants('updates') && ! $client) {
            $updates = $on(Update::visibleTo($user)->with(['project', 'user']))->when($mine, fn ($b) => $b->where('user_id', $user->id))
                ->where('summary', 'like', $like)->latest()->limit($limit)->get()
                ->map(fn (Update $u) => self::update($u, $u->summary, $q));
            $lines = UpdateItem::with(['statusUpdate.project', 'statusUpdate.user'])->where('text', 'like', $like)
                ->whereHas('statusUpdate', fn ($u) => $on($u->visibleTo($user))->when($mine, fn ($b) => $b->where('user_id', $user->id)))
                ->latest('id')->limit($limit)->get()->filter(fn (UpdateItem $i) => $i->statusUpdate)
                ->map(fn (UpdateItem $i) => self::update($i->statusUpdate, $i->text, $q));
            $out['updates'] = $updates->concat($lines)->unique('url')->take($limit)->values()->all();
        }
        if ($wants('documents') && ! $mine) {
            $out['documents'] = $on(Document::with('project')->active()->whereIn('project_id', Project::visibleTo($user)->select('id')))
                ->when($client, fn ($b) => $b->where('client_visible', true))
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like))->latest('updated_at')->limit($limit)->get()
                ->filter(fn (Document $d) => $d->project)
                ->map(fn (Document $d) => ['title' => $d->title, 'snippet' => self::snippet($d->body, $q), 'meta' => $d->project->name.' · '.$d->updated_at->diffForHumans(),
                    'url' => ProjectResource::getUrl('document', ['record' => $d->project, 'document' => $d])])->values()->all();
        }
        if ($wants('people') && ! $client && ! $mine && ! $projects) {
            $out['people'] = User::query()->where('is_active', true)->visibleTo($user)                       // colaboratorul: doar oamenii proiectelor lui
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))->orderBy('name')->limit($limit)->get()
                ->map(fn (User $p) => ['title' => $p->name, 'snippet' => null, 'meta' => $p->role->getLabel(), 'url' => UserResource::getUrl('view', ['record' => $p])])->all();
        }
        if ($wants('notes') && ! $client) {
            $out['notes'] = $on(ProjectNote::visibleTo($user)->with(['project', 'author'])->active())->when($mine, fn ($b) => $b->where('user_id', $user->id))
                ->where('body', 'like', $like)->latest('id')->limit($limit)->get()->filter(fn (ProjectNote $n) => $n->project)
                ->map(fn (ProjectNote $n) => ['title' => Str::limit(Markdown::plain($n->body), 90), 'snippet' => self::snippet($n->body, $q),
                    'meta' => collect([$n->author?->name, $n->project->name, $n->created_at->diffForHumans()])->filter()->join(' · '),
                    'url' => ProjectResource::getUrl('view', ['record' => $n->project])])->values()->all();
        }
        if ($wants('comments')) {
            $task = TaskComment::with(['task.project', 'author'])->whereHas('task', fn ($t) => $on($t->visibleTo($user)))->when($mine, fn ($b) => $b->where('user_id', $user->id))
                ->where('body', 'like', $like)->latest('id')->limit($limit)->get()->filter(fn (TaskComment $c) => $c->task)
                ->map(fn (TaskComment $c) => ['title' => "#{$c->task->id} {$c->task->title}", 'snippet' => self::snippet($c->body, $q),
                    'meta' => collect([$c->author?->name, $c->created_at->diffForHumans()])->filter()->join(' · '), 'url' => TaskResource::getUrl('view', ['record' => $c->task]).'#comments']);
            $update = UpdateComment::visibleTo($user)->with(['statusUpdate.project', 'author'])->whereHas('statusUpdate', fn ($u) => $on($u->visibleTo($user)))
                ->when($mine, fn ($b) => $b->where('user_id', $user->id))->where('body', 'like', $like)->latest('id')->limit($limit)->get()->filter(fn (UpdateComment $c) => $c->statusUpdate)
                ->map(fn (UpdateComment $c) => ['title' => Str::limit($c->statusUpdate->summary, 90), 'snippet' => self::snippet($c->body, $q),
                    'meta' => collect([$c->author?->name, $c->statusUpdate->project?->name, $c->created_at->diffForHumans()])->filter()->join(' · '),
                    'url' => UpdateResource::getUrl('view', ['record' => $c->statusUpdate]).'#comments']);
            $out['comments'] = $task->concat($update)->take($limit)->values()->all();
        }

        return array_filter(array_merge(array_fill_keys(self::TYPES, []), $out));
    }

    private static function task(Task $t, string $q): array
    {
        $inTitle = Str::contains(mb_strtolower($t->title), mb_strtolower(ltrim($q, '#')));

        return ['id' => $t->id, 'title' => "#{$t->id} {$t->title}", 'snippet' => $inTitle ? null : self::snippet($t->notes, $q),
            'meta' => collect([$t->project?->name, $t->status->getLabel(), $t->assignee?->name])->filter()->join(' · '),
            'url' => TaskResource::getUrl('view', ['record' => $t])];
    }

    private static function update(Update $u, string $text, string $q): array
    {
        return ['title' => Str::limit(Markdown::plain($u->summary), 110), 'snippet' => $text === $u->summary ? null : self::snippet($text, $q),
            'meta' => collect([$u->user?->name, $u->project?->name, $u->created_at->diffForHumans()])->filter()->join(' · '),
            'url' => UpdateResource::getUrl('view', ['record' => $u])];
    }

    /** Un fragment scurt în jurul cuvântului, cu potrivirile îngroșate (textul e scăpat; doar <mark> e HTML). */
    public static function snippet(?string $text, string $query): ?HtmlString
    {
        $plain = Markdown::plain((string) $text);
        if ($plain === '') {
            return null;
        }
        $at = mb_stripos($plain, $query);
        $start = $at === false || mb_strlen($plain) <= 140 ? 0 : max(0, $at - 50);
        $cut = ($start > 0 ? '…' : '').Str::limit(mb_substr($plain, $start), 140);

        return new HtmlString(self::mark($cut, $query));
    }

    /** Textul scăpat, cu fiecare apariție a căutării în <mark>. */
    public static function mark(string $text, string $query): string
    {
        $q = trim(ltrim($query, '#'));

        return $q === '' ? e($text) : preg_replace('/('.preg_quote(e($q), '/').')/iu', '<mark>$1</mark>', e($text));
    }

    /**
     * Ecranul gol: proiectele la care a lucrat omul azi (sau ultimele lui) și acțiunile rapide.
     *
     * @return array{projects: Collection<int, Project>}
     */
    public static function start(User $user): array
    {
        $tz = $user->tz();
        $today = Activity::where('user_id', $user->id)->whereNotNull('project_id')->where('occurred_at', '>=', now($tz)->startOfDay()->utc())
            ->latest('occurred_at')->pluck('project_id')->unique();
        $recent = $today->count() >= 3 ? $today : $today->concat($user->updates()->latest()->limit(20)->pluck('project_id'))->filter()->unique();

        return ['projects' => Project::visibleTo($user)->whereIn('id', $recent->take(5))->where('is_archived', false)->get()
            ->sortBy(fn (Project $p) => $recent->search($p->id))->values()];
    }

    /** Acțiunile rapide ale ecranului gol: ce face omul des, fără să caute. @return list<array{label: string, url: string}> */
    public static function quickActions(User $user): array
    {
        $here = strtok(strtok(url()->previous() ?: Dashboard::getUrl(), '#'), '?');
        $team = ! $user->isClient();

        return array_values(array_filter([
            ['label' => __('New task'), 'url' => $here.'#capture=task'],
            $team ? ['label' => __('New idea'), 'url' => $here.'#capture=idea'] : null,
            $team ? ['label' => __('My day'), 'url' => Dashboard::getUrl().'#ziua-mea'] : null,
            $team ? ['label' => __('My tasks'), 'url' => TaskResource::getUrl('index', ['tab' => 'mine'])] : null,
            ['label' => __('New update'), 'url' => UpdateResource::getUrl('create')],
        ]));
    }
}
