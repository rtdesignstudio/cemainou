<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\TaskStatus;
use App\Filament\Pages\Hours as HoursPage;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\BackLinks;
use App\Support\ClientCards;
use App\Support\Hours;
use App\Support\ProjectPortfolio;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Pagina unui client, pentru echipă: proiectele lui (aceleași carduri ca pe „Azi", toate cele active; dedesubt cele încheiate), cronologia update-urilor
 * de pe toate (și cele interne, marcate), sarcinile deschise (cele cerute de client primele), ce i s-a arătat din documente și fișiere, oamenii lui
 * din aplicație și orele lunii (doar proiectele pe care omul le conduce sau toate, pentru admin: viewMinutes).
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected string $view = 'filament.resources.clients.view-client';

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    /** Câte proiecte · de când lucrăm împreună · persoana de contact (cu email). */
    public function getSubheading(): string|Htmlable|null
    {
        $client = $this->record;
        $ids = $client->projects()->visibleTo(auth()->user())->pluck('id');
        $first = collect([$client->projects()->visibleTo(auth()->user())->min('created_at'), Update::whereIn('project_id', $ids)->min('created_at')])->filter()->min();
        $contact = $client->contact_name || $client->contact_email
            ? e(__('Contact :name', ['name' => $client->contact_name ?? $client->contact_email])).($client->contact_name && $client->contact_email ? ' (<a href="mailto:'.e($client->contact_email).'">'.e($client->contact_email).'</a>)' : '')
            : null;

        return new HtmlString(collect([
            e(trans_choice(':count project|:count projects', $ids->count())),
            $first ? e(__('together since :date', ['date' => Carbon::parse($first)->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('MMMM YYYY')])) : null,
            $contact,
        ])->filter()->join(' · '));
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->outlined()];
    }

    protected function getViewData(): array
    {
        $client = $this->record;
        $viewer = auth()->user();
        $projects = $client->projects()->visibleTo(auth()->user())->with('client')->orderBy('name')->get();
        $ids = $projects->pluck('id');
        $order = [TaskStatus::Blocked->value => 0, TaskStatus::Doing->value => 1, TaskStatus::Todo->value => 2];

        return [
            // același card ca pe Proiecte, pe luna curentă; sus banda clientului (ca un rând din lista Clienți)
            'cards' => ProjectPortfolio::build($viewer, $projects->where('is_archived', false)->values()->load('lead'), $from = now($viewer->tz())->startOfMonth()->toDateString(), $to = now($viewer->tz())->toDateString()),
            'from' => $from,
            'to' => $to,
            'band' => ClientCards::build($viewer, (bool) $client->is_archived, $client->name)['rows']->firstWhere('client.id', $client->id),
            'archived' => $projects->where('is_archived', true)->values(),
            'timeline' => Update::whereIn('project_id', $ids)->with(['user', 'project'])->latest()->limit(25)->get(),
            'tasks' => Task::whereIn('project_id', $ids)->open()->with(['project', 'assignee', 'creator'])->latest()->get()   // cerute de client, apoi blocate, în lucru, de făcut
                ->sortBy([fn (Task $a, Task $b) => $b->fromClient() <=> $a->fromClient(), fn (Task $a, Task $b) => $order[$a->status->value] <=> $order[$b->status->value]])->values(),
            'shared' => $this->shared($ids),
            'people' => User::where('client_id', $client->id)->orderByDesc('is_active')->orderBy('name')->get(),
            'hours' => $this->hours($viewer, $projects),
        ];
    }

    /** Ce vede clientul din documente și fișiere, pe toate proiectele, cele mai noi primele. */
    private function shared(Collection $ids): Collection
    {
        $documents = Document::whereIn('project_id', $ids)->where('client_visible', true)->active()->with('project')->get()
            ->map(fn (Document $document) => ['title' => $document->title, 'project' => $document->project, 'at' => $document->updated_at, 'external' => false,
                'url' => ProjectResource::getUrl('document', ['record' => $document->project_id, 'document' => $document->id])]);
        $attachments = Attachment::whereIn('project_id', $ids)->whereNull('task_id')->where('client_visible', true)->with('project')->get()
            ->map(fn (Attachment $attachment) => ['title' => $attachment->title, 'project' => $attachment->project, 'at' => $attachment->created_at, 'external' => true, 'url' => $attachment->href()]);

        return $documents->concat($attachments)->sortByDesc('at')->values();
    }

    /**
     * Orele pe proiecte: luna aceasta și luna trecută, doar pe proiectele ale căror ore le vede omul.
     *
     * @return array{rows: Collection, this: int, last: int, url: ?string, month: string, previous: string}|null
     */
    private function hours(User $viewer, Collection $projects): ?array
    {
        $visible = $projects->filter(fn (Project $project) => $viewer->can('viewMinutes', $project))->keyBy('id');
        if ($visible->isEmpty()) {
            return null;
        }
        $start = now($viewer->tz())->startOfMonth();
        $previous = $start->copy()->subMonth();
        $days = Hours::between($visible->keys(), $previous->toDateString(), now($viewer->tz())->toDateString());
        $sum = fn (Collection $rows) => $rows->sum('measured') + $rows->sum('added') + $rows->sum('estimated');   // și cel estimat din git
        $rows = $days->groupBy('project_id')->map(fn (Collection $rows, int $id) => [
            'project' => $visible[$id],
            'this' => $sum($rows->where('date', '>=', $start->toDateString())),
            'last' => $sum($rows->where('date', '<', $start->toDateString())),
        ])->sortByDesc(fn (array $row) => $row['this'] + $row['last'])->values();

        return [
            'rows' => $rows,
            'this' => $rows->sum('this'),
            'last' => $rows->sum('last'),
            'url' => Hours::canSee($viewer) ? HoursPage::getUrl(['month' => $start->format('Y-m')]) : null,
            'month' => ucfirst($start->copy()->locale(app()->getLocale())->isoFormat('MMMM')),
            'previous' => ucfirst($previous->locale(app()->getLocale())->isoFormat('MMMM')),
        ];
    }

    public function backLinks(): array
    {
        return BackLinks::to(__('Clients'), ClientResource::getUrl('index'));
    }
}
