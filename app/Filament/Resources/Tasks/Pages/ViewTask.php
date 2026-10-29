<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskActions;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\UpdateItem;
use App\Support\AccessGuard;
use App\Support\BackLinks;
use App\Support\Checklist;
use App\Support\ClientSeen;
use App\Support\Headline;
use App\Support\Inbox;
use App\Support\TaskTime;
use App\Support\TaskTrail;
use App\Support\WorkTime;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/** Pagina unei sarcini: titlul mare, acțiunile (după drepturi), nota și istoria. Omul ei, deschizând-o, o vede. */
class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.view-task';

    /** Lista de sarcini din care s-a venit (TaskTrail), citită o dată la deschidere: drumul înapoi și anterioara / următoarea. */
    public ?array $trail = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->trail = TaskTrail::for($this->record);

        if ($this->record->assignee_id === auth()->id()) {
            $this->record->markSeen();
        }
        Inbox::readAbout(auth()->user(), static::getResource()::getUrl('view', ['record' => $this->record]));   // notificările despre ea: citite
        ClientSeen::record(auth()->user(), $this->record);                          // clientul a deschis-o: echipa vede „văzut de"
        AccessGuard::opened(auth()->user(), $this->record, 'web');                 // din afară: în jurnal, iar ce n-ar trebui văzut ajunge la admin
        $this->askUnblock = request()->boolean('unblock') && $this->record->status === TaskStatus::Blocked
            && auth()->user()->can('move', [$this->record, TaskStatus::Doing]);    // din notificarea „Elena a răspuns · Deblochezi?"
    }

    /** Clientul a răspuns la o sarcină blocată: „Deblochezi?" sus, până răspunzi. */
    public bool $askUnblock = false;

    public function unblock(): void
    {
        abort_unless($this->record->status === TaskStatus::Blocked && auth()->user()->can('move', [$this->record, TaskStatus::Doing]), 403);
        $this->record->moveTo(TaskStatus::Doing, auth()->user());
        $this->askUnblock = false;
        Notification::make()->title(__('Unblocked: back in progress.'))->success()->send();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->title;
    }

    /** Titlul sarcinii (până la 200 de caractere): cu cât e mai lung, cu atât mai mic, ca la update (Headline). */
    public function getHeading(): string|Htmlable
    {
        return Headline::html($this->record->title);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $task = $this->record;

        // la sfârșit, „Copiază linkul": adresa sarcinii, de dat agentului sau unui coleg
        return new HtmlString(e(collect([
            "#{$task->id}",
            $task->project?->name,
            $task->status->getLabel(),
            $task->assignee?->name ?? __('Free'),
            ($due = $task->due()) ? ($due['overdue'] ? __('overdue, :when', ['when' => $due['label']]) : __('due :when', ['when' => $due['label']])) : null,
            $task->repeat?->short(),
            ClientSeen::line($task, $task->project, auth()->user()),
            ($worked = TaskTime::for($task)) && $worked['total'] && auth()->user()->can('viewMinutes', $task->project)
                ? __('worked :time (:people)', ['time' => WorkTime::total($worked['total']), 'people' => $worked['people']->map(fn ($p) => $p['user']->name.' '.WorkTime::format($p['minutes']))->join(', ')]) : null,
            __('created by :name', ['name' => $task->creator?->name ?? '-']),
        ])->filter()->join(' · ')).' '.Blade::render('<x-wsa.copy-task :url="$url" />', ['url' => TaskResource::getUrl('view', ['record' => $task])]));
    }

    /** Bifează un pas din lista de verificat a detaliilor (cine poate mișca sarcina). */
    public function toggleCheck(int $index): void
    {
        $task = $this->record;
        abort_unless(auth()->user()->can('move', [$task, $task->status]) || auth()->user()->can('edit', $task), 403);
        $task->forceFill(['notes' => Checklist::toggle((string) $task->notes, $index)])->save();
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        // la vedere doar ce se folosește des; restul în „More". Ștergerea: doar liderul proiectului și adminul (TaskPolicy::delete).
        return [
            TaskActions::promote(), TaskActions::done(), TaskActions::take(), TaskActions::block(), TaskActions::reassign(), TaskActions::reopen(),
            ActionGroup::make([TaskActions::drop(), TaskActions::edit(), DeleteAction::make()->modalHeading(__('Delete this task?'))])
                ->label(__('More'))->icon(Heroicon::OutlinedChevronDown)->iconPosition(IconPosition::After)->button()->outlined()->color('gray'),
        ];
    }

    protected function getViewData(): array
    {
        $tz = auth()->user()->tz();

        return ['events' => $this->record->events()->with(['user', 'statusUpdate'])->orderBy('id')->get()
            ->map(fn ($event) => ['event' => $event, 'when' => $event->created_at->copy()->setTimezone($tz)->locale(app()->getLocale())->isoFormat('D MMMM, HH:mm')]),
            // povestea sarcinii în update-uri: fiecare rând legat de ea (făcut, în lucru, blocat), cu ziua și omul - cine o primește nu caută prin update-uri
            'mentions' => UpdateItem::with('statusUpdate.user')->where('task_id', $this->record->id)
                ->whereHas('statusUpdate', fn ($u) => $u->visibleTo(auth()->user()))->get()
                ->sortByDesc(fn (UpdateItem $i) => $i->statusUpdate->created_at)->values(),
            'commits' => $this->record->commits()->with('user')->get()];
    }

    /** Înapoi: lista din care s-a venit (cu filtrele ei) și proiectul; altfel clientul și proiectul sarcinii. */
    public function backLinks(): array
    {
        $project = BackLinks::project($this->record->project, auth()->user());

        return $this->trail ? [['label' => $this->trail['label'], 'url' => $this->trail['url']], end($project)] : $project;
    }

    /** Anterioara / următoarea din lista din care s-a venit. @return array{prev: ?string, next: ?string}|null */
    public function backPager(): ?array
    {
        $url = fn (?int $id) => $id ? TaskResource::getUrl('view', ['record' => $id, 'from' => TaskTrail::FROM]) : null;

        return $this->trail && ($this->trail['prev'] || $this->trail['next']) ? ['prev' => $url($this->trail['prev']), 'next' => $url($this->trail['next'])] : null;
    }
}
