<?php

namespace App\Filament\GlobalSearch;

use App\Filament\Pages\Commands;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Hours;
use App\Filament\Pages\SystemSettings;
use App\Filament\Pages\Week;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\UpdateComment;
use App\Models\UpdateItem;
use App\Models\User;
use App\Support\Markdown;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Căutarea din bara de sus găsește tot, nu doar titlurile: pe lângă proiecte, sarcini, update-uri, clienți și oameni, și rândurile update-urilor,
 * detaliile sarcinilor, documentele, notițele și comentariile - cu un fragment în jurul cuvântului. Clientul caută la fel, doar în proiectele lui:
 * update-urile neinterne, documentele arătate lui, notițele firmei lui și comentariile pe care le vede (la update-uri, doar ale lui).
 */
class EverythingSearch extends DefaultGlobalSearchProvider
{
    private const LIMIT = 5;

    public function getResults(string $query): ?GlobalSearchResults
    {
        $user = auth()->user();
        $results = GlobalSearchResults::make();
        // „179" sau „#179": sarcina cu numărul acela, prima, chiar dacă numărul nu e în titlul ei (căutarea Filament se uită doar în titluri)
        if ($user && preg_match('/^#?(\d{1,7})$/', trim($query), $m) && ($task = Task::visibleTo($user)->with('project')->find((int) $m[1]))) {
            $results->category(__('Task').' #'.$task->id, [new GlobalSearchResult("#{$task->id} {$task->title}", TaskResource::getUrl('view', ['record' => $task]),
                array_filter([__('Project') => $task->project?->name, __('Status') => $task->status->getLabel()]))]);
        }
        foreach ((parent::getResults($query)?->getCategories() ?? []) as $label => $found) {
            $results->category($label, $found);
        }
        $query = trim($query);
        if ($user && ($actions = $this->actions($user, $query))->isNotEmpty()) {
            $results->category(__('Actions'), $actions->values());             // ⌘K: „sarcină" → Sarcină nouă, „ore" → Mergi la Ore
        }
        if (! $user || mb_strlen($query) < 3) {
            return $results;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';

        $lines = UpdateItem::with(['statusUpdate.project', 'statusUpdate.user'])->where('text', 'like', $like)->whereHas('statusUpdate', fn ($q) => $q->visibleTo($user))->latest('id')->limit(self::LIMIT)->get()
            ->filter(fn (UpdateItem $i) => $i->statusUpdate)
            ->map(fn (UpdateItem $i) => new GlobalSearchResult($this->snippet($i->text, $query), UpdateResource::getUrl('view', ['record' => $i->statusUpdate]),
                array_filter([__('Update') => $i->statusUpdate->user?->name.($i->statusUpdate->project ? ' · '.$i->statusUpdate->project->name : '')])));
        $details = Task::visibleTo($user)->with('project')->where('notes', 'like', $like)->where('title', 'not like', $like)->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (Task $t) => new GlobalSearchResult("#{$t->id} {$t->title}", TaskResource::getUrl('view', ['record' => $t]), [__('Details') => $this->snippet($t->notes, $query)]));
        $documents = Document::with('project')->active()->whereIn('project_id', Project::visibleTo($user)->select('id'))->when($user->isClient(), fn ($q) => $q->where('client_visible', true))->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('body', 'like', $like))->latest('updated_at')->limit(self::LIMIT)->get()
            ->filter(fn (Document $d) => $d->project)
            ->map(fn (Document $d) => new GlobalSearchResult($d->title, ProjectResource::getUrl('document', ['record' => $d->project, 'document' => $d]),
                [__('Project') => $d->project->name, __('Text') => $this->snippet((string) $d->body, $query)]));
        $notes = ProjectNote::visibleTo($user)->with(['project', 'author'])->active()->where('body', 'like', $like)->latest('id')->limit(self::LIMIT)->get()
            ->filter(fn (ProjectNote $n) => $n->project)
            ->map(fn (ProjectNote $n) => new GlobalSearchResult($this->snippet($n->body, $query), ProjectResource::getUrl('view', ['record' => $n->project]),
                [__('Note') => $n->author?->name.' · '.$n->project->name]));
        $comments = UpdateComment::visibleTo($user)->with(['statusUpdate', 'author'])->whereHas('statusUpdate', fn ($q) => $q->visibleTo($user))->where('body', 'like', $like)->latest('id')->limit(self::LIMIT)->get()
            ->filter(fn (UpdateComment $c) => $c->statusUpdate)
            ->map(fn (UpdateComment $c) => new GlobalSearchResult($this->snippet($c->body, $query), UpdateResource::getUrl('view', ['record' => $c->statusUpdate]).'#comments', [__('A comment') => $c->author?->name]))
            ->concat(TaskComment::with(['task', 'author'])->whereHas('task', fn ($q) => $q->visibleTo($user))->where('body', 'like', $like)->latest('id')->limit(self::LIMIT)->get()
                ->filter(fn (TaskComment $c) => $c->task)
                ->map(fn (TaskComment $c) => new GlobalSearchResult($this->snippet($c->body, $query), TaskResource::getUrl('view', ['record' => $c->task]), [__('A comment') => $c->author?->name.' · #'.$c->task->id])))
            ->take(self::LIMIT);

        foreach ([__('Lines in updates') => $lines, __('Task details') => $details, __('Documents') => $documents, __('Notes') => $notes, __('Comments') => $comments] as $label => $found) {
            if ($found->isNotEmpty()) {
                $results->category($label, $found->values());
            }
        }

        return $results;
    }

    /**
     * Acțiunile din căutare (⌘K): ce poți face sau unde poți merge, potrivite după cuvintele scrise (în limba omului și în engleză).
     * Notarea rapidă se deschide pe pagina curentă (#capture=…), celelalte duc la pagina lor.
     *
     * @return Collection<int, GlobalSearchResult>
     */
    private function actions(User $user, string $query): Collection
    {
        $q = Str::lower(Str::ascii($query));
        if (mb_strlen($q) < 2) {
            return collect();
        }
        $here = url()->previous() ?: Dashboard::getUrl();
        $capture = fn (string $kind) => strtok(strtok($here, '#'), '?').'#capture='.$kind;   // pe pagina pe care ești (ancora o deschide fără reîncărcare)
        $team = ! $user->isClient();
        $list = array_filter([
            [__('New task'), 'new task sarcina', $capture('task'), true],
            [__('New idea'), 'new idea idee', $capture('idea'), $team],
            [__('New note'), 'new note notita', $capture('note'), $team],
            [__('Add time'), 'add time timp ore adauga', $capture('time'), $team],
            [__('Add a file'), 'add file fisier link', $capture('file'), true],
            [__('New update'), 'new update raport', UpdateResource::getUrl('create'), true],
            [__('New project'), 'new project proiect', ProjectResource::getUrl('create'), $team],
            [__('Go to Today'), 'go today azi', Dashboard::getUrl(), true],
            [__('Go to Week'), 'go week saptamana', Week::getUrl(), $team],
            [__('Go to Hours'), 'go hours ore timp', Hours::getUrl(), $team],
            [__('Go to My tasks'), 'go my tasks sarcinile mele ale mele', TaskResource::getUrl('index', ['tab' => 'mine']), $team],
            [__('Go to Guide'), 'go guide ghid ajutor help', Commands::getUrl(), true],
            [__('Go to Profile'), 'go profile profil notificari telegram agent', filament()->getProfileUrl(), true],
            [__('Go to Settings'), 'go settings setari', SystemSettings::getUrl(), $user->isAdmin()],
        ], fn (array $a) => $a[3]);

        return collect($list)->filter(function (array $a) use ($q) {
            $words = Str::lower(Str::ascii($a[0])).' '.$a[1];

            return collect(preg_split('/\s+/', $q))->filter()->every(fn (string $w) => preg_match('/(^|\s)'.preg_quote($w, '/').'/', $words));
        })->take(5)->map(fn (array $a) => new GlobalSearchResult($a[0], $a[2]));
    }

    /** Un fragment scurt în jurul cuvântului căutat. */
    private function snippet(?string $text, string $query): string
    {
        $plain = Markdown::plain((string) $text);
        $at = mb_stripos($plain, $query);
        if ($at === false || mb_strlen($plain) <= 120) {
            return Str::limit($plain, 120);
        }
        $start = max(0, $at - 40);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($plain, $start), 110);
    }
}
