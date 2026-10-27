<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\AttachmentRole;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attachment;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use App\Models\WidgetInvite;
use App\Support\BackLinks;
use App\Support\Estimates;
use App\Support\FeedbackWidget;
use App\Support\Inbox;
use App\Support\ProjectActivity;
use App\Support\ProjectCards;
use App\Support\QuietProjects;
use App\Support\WorkTime;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/** Pagina unui proiect: sus ansamblul, la stânga cronologia desfăcută, la dreapta sarcinile, documentele și cunoștințele. */
class ViewProject extends ViewRecord
{
    public function mount(int|string $record): void
    {
        parent::mount($record);
        Inbox::readAbout(auth()->user(), static::getResource()::getUrl('view', ['record' => $this->record]));   // notificările despre pagina asta: citite
    }

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.view-project';

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    /** Clientul · liderul (pentru echipă, linkuri la paginile lor) · repo-ul. Seria proiectului stă în dreapta antetului (project-streak). */
    public function getSubheading(): string|Htmlable|null
    {
        $project = $this->record;
        $client = auth()->user()->isClient();
        $lead = $project->lead ? ($client ? e($project->lead->name) : '<a href="'.e(UserResource::getUrl('view', ['record' => $project->lead])).'">'.e($project->lead->name).'</a>') : null;
        $company = $project->client ? ($client ? e($project->client->name) : '<a href="'.e(ClientResource::getUrl('view', ['record' => $project->client])).'">'.e($project->client->name).'</a>') : null;

        return new HtmlString(collect([$company, $lead ? __('Lead :name', ['name' => $lead]) : null, $client ? null : e($project->repo_key)])->filter()->join(' · '));
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $leads = $this->record->lead_id === $user->id;
        $following = $leads || $user->followedProjects()->whereKey($this->record->id)->exists();

        // demo-urile proiectului (rolul Demo), fixate primele în antet; clientul le vede doar pe cele vizibile lui
        $demos = $this->record->attachments()->whereNull('task_id')->where('role', AttachmentRole::Demo->value)
            ->when($user->isClient(), fn ($q) => $q->where('client_visible', true))->latest()->limit(3)->get();

        return [
            ...$demos->map(fn (Attachment $demo, int $i) => Action::make('demo'.$i)
                ->label($demos->count() === 1 ? __('See the demo') : __('Demo: :title', ['title' => Str::limit($demo->title, 30)]))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)->iconPosition('after')->url($demo->href(), shouldOpenInNewTab: true))->all(),
            // widget-ul încă oprit: liderul (sau adminul) îl pornește chiar de aici, într-un pas scurt (adresa site-ului), apoi primește codul și cum îl încearcă
            Action::make('widgetSetup')->visible(fn () => ! $this->record->widget_enabled && ! $this->record->is_archived && $user->can('update', $this->record))
                ->label(__('Feedback on the site'))->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->color('gray')->outlined()
                ->modalHeading(__('Turn on feedback on the site'))->modalWidth('md')
                ->modalDescription(__('A Feedback button on the client\'s site, seen only on the devices you turn on: visitors see nothing. What is sent lands on this project, as tasks.'))
                ->fillForm(fn () => ['domain' => $this->suggestedWidgetDomain()])
                ->schema([TextInput::make('domain')->label(__('The site\'s address'))->placeholder('example.md')->required()->maxLength(255)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if (! preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$~i', FeedbackWidget::cleanDomains([(string) $value])[0] ?? '')) {   // un domeniu adevărat, cu punct
                            $fail(__('Write the site\'s address, for example example.md.'));
                        }
                    })])
                ->modalSubmitActionLabel(__('Turn on'))
                ->action(function (array $data) {
                    $this->record->update(['widget_enabled' => true, 'widget_domains' => [$data['domain']]]);
                    $this->replaceMountedAction('widgetReady');
                }),
            // widget-ul de feedback, un meniu pe domeniu: deschide în browser (pe acest dispozitiv), link și QR fără logare (o dată, în numele tău)
            // sau link și QR pentru cei cu cont (același link; fiecare intră cu contul lui) - feedback-ul e legat mereu de un om; la amândouă, linkul se poate copia
            ...collect($this->record->widget_enabled && FeedbackWidget::canUse($user, $this->record) ? ($this->record->widget_domains ?? []) : [])->take(3)
                ->map(fn (string $domain, int $i) => ActionGroup::make([
                    Action::make('widget'.$i)->label(__('Open in the browser'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(route('widget.activate', ['project' => $this->record, 'domain' => $domain]), shouldOpenInNewTab: true),
                    // invitația la feedback: un link (și QR-ul lui), refolosibil, cu durata aleasă; cine are cont intră pe contul lui, ceilalți cu numele lor
                    Action::make('widgetInvite'.$i)->label(__('Invite to give feedback'))->icon(Heroicon::OutlinedQrCode)
                        ->modalHeading(__('Feedback on :domain', ['domain' => $domain]))->modalWidth('md')
                        // linkul tău încă valabil, dacă e unul: arătat direct, cu QR-ul; dedesubt, „fă unul nou" cu altă durată
                        ->modalSubmitActionLabel(fn () => $this->currentInvite($domain) ? __('Make a new link') : __('Make the link'))
                        ->modalDescription(fn () => $this->currentInvite($domain) ? null : __('One link, with its QR: send it to the client or scan it with a phone. Whoever has an account goes in with it; the others write with their name, through your link.'))
                        ->modalContent(fn () => ($invite = $this->currentInvite($domain)) ? $this->inviteView($invite) : null)
                        ->fillForm(['duration' => '30'])
                        ->schema(fn () => [Radio::make('duration')->label($this->currentInvite($domain) ? __('Or a new link, that works') : __('How long it works'))->required()->options([
                            'once' => __('Once, for 10 minutes'), '7' => __('7 days'), '30' => __('30 days'), 'forever' => __('Until I stop it'),
                        ])])
                        ->action(function (array $data, Action $action) use ($domain, $i) {
                            $invite = WidgetInvite::make(auth()->user(), $this->record, $domain, $data['duration']);
                            $this->replaceMountedAction('widgetInviteShow'.$i, ['invite' => $invite->id]);
                        }),
                    Action::make('widgetInvites'.$i)->label(__('Active invitations'))->icon(Heroicon::OutlinedUserGroup)
                        ->modalHeading(__('Invitations for :domain', ['domain' => $domain]))->modalWidth('lg')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn () => view('filament.resources.projects.widget-invites', ['invites' => WidgetInvite::with('user')->withCount('devices')
                            ->where('project_id', $this->record->id)->where('domain', $domain)->active()->latest()->get()->filter->open()->values(), 'leads' => auth()->user()->leads($this->record)])),
                    // oprirea, o singură dată (sub primul domeniu), pentru cine poate edita proiectul; setările complete rămân în „Editează"
                    Action::make('widgetOff'.$i)->visible($i === 0 && $user->can('update', $this->record))->label(__('Turn off'))->icon(Heroicon::OutlinedNoSymbol)
                        ->requiresConfirmation()->modalHeading(__('Turn off feedback on the site?'))
                        ->modalDescription(__('The button disappears from every device. The code on the site can stay: it does nothing while this is off.'))
                        ->action(fn () => $this->record->update(['widget_enabled' => false])),
                ])->label(count($this->record->widget_domains ?? []) > 1 ? __('Feedback on :domain', ['domain' => $domain]) : __('Feedback on the site'))
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)->button()->color('gray')->outlined())->all(),
            // urmărirea: rezumatul de dimineață include blocajele și update-urile proiectului; liderul îl urmărește oricum
            Action::make('follow')->visible(! $user->isClient())
                ->label($leads ? __('You lead it') : ($following ? __('Following') : __('Follow')))
                ->icon($following ? Heroicon::OutlinedBellAlert : Heroicon::OutlinedBell)
                ->color('gray')->outlined()->disabled($leads)
                ->tooltip(__('The morning summary includes the blockers and yesterday\'s updates of the projects you follow.'))
                ->action(fn () => $user->followedProjects()->toggle([$this->record->id])),
            // raportul lunar pentru client: o pagină frumoasă la un link (fără logare), din update-urile și sarcinile lunii; trimis pe email, la cerere
            Action::make('monthlyReport')->label(__('Monthly report'))->icon(Heroicon::OutlinedDocumentChartBar)->color('gray')->outlined()
                ->visible(fn () => ! $user->isClient())->url(fn () => route('report.team', $this->record)),   // mereu gata; trimiterea, de pe pagina lui
            // sarcinile proiectului devin un șablon pentru proiectele de același fel
            Action::make('saveAsTemplate')->label(__('Save as template'))->color('gray')->outlined()
                ->visible(fn () => ! $user->isClient() && $this->record->tasks()->whereNotIn('status', [TaskStatus::Idea->value, TaskStatus::Dropped->value])->exists())
                ->schema([TextInput::make('name')->label(__('Template name'))->required()->maxLength(120)->default(fn () => $this->record->name)])
                ->modalDescription(__('Its tasks (without ideas and dropped ones) become the template\'s tasks, with their due dates counted from the project\'s start.'))
                ->action(function (array $data) {
                    $template = ProjectTemplate::fromProject($this->record, auth()->user(), $data['name']);
                    Notification::make()->title(__('Template saved.'))->success()
                        ->actions([Action::make('open')->label(__('Open it'))->url(ProjectTemplateResource::getUrl('edit', ['record' => $template]))])->send();
                }),
            // fără niciun semn de 30 de zile: arhivarea, la îndemână (se poate scoate oricând din arhivă, din Editează)
            Action::make('archive')->label(__('Archive'))->icon(Heroicon::OutlinedArchiveBox)->color('gray')->outlined()
                ->visible(fn () => ! $this->record->is_archived && $user->can('update', $this->record)
                    && ($last = QuietProjects::lastSign($this->record) ?? $this->record->created_at) && $last->lt(now()->subDays(QuietProjects::ARCHIVE)))
                ->tooltip(__('No sign of life for more than :days days.', ['days' => QuietProjects::ARCHIVE]))
                ->requiresConfirmation()->modalHeading(__('Archive :project?', ['project' => $this->record->name]))
                ->modalDescription(__('It leaves the active projects, the cards and the radar; its updates, tasks and files stay. It can be brought back from Edit.'))
                ->action(function () {
                    $this->record->forceFill(['is_archived' => true])->save();
                    Notification::make()->title(__(':project archived.', ['project' => $this->record->name]))->success()->send();
                }),
            EditAction::make()->outlined(),
        ];
    }

    /** Câte update-uri arată cronologia; „Mai vechi" mai adaugă 20. */
    public int $shown = 20;

    /** Ziua deschisă din grila „Unde suntem" (Y-m-d); goală = niciuna. */
    #[Url]
    public string $day = '';

    public function selectDay(string $date): void
    {
        $this->day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date <= now(auth()->user()->tz())->toDateString() ? $date : '';
        $this->js("setTimeout(() => document.getElementById('day')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50)");   // ziua, la vedere
    }

    public function shiftDay(int $by): void
    {
        if ($this->day !== '') {
            $this->selectDay(Carbon::parse($this->day)->addDays($by > 0 ? 1 : -1)->toDateString());
        }
    }

    /** Linkul și QR-ul unei invitații tocmai făcute (după „Invită la feedback"). */
    /** După pornire: codul pentru site, cu „Copiază", și „Deschide în browser", ca să-l încerce pe loc. */
    public function widgetReadyAction(): Action
    {
        return Action::make('widgetReady')->modalHeading(__('Feedback on the site is on'))->modalWidth('lg')
            ->modalSubmitAction(false)->modalCancelActionLabel(__('Got it'))
            ->modalContent(fn () => view('filament.resources.projects.widget-ready', [
                'snippet' => FeedbackWidget::snippet($this->record),
                'open' => ($domain = $this->record->widget_domains[0] ?? null) ? route('widget.activate', ['project' => $this->record, 'domain' => $domain]) : null,
            ]));
    }

    /** Adresa propusă la pornirea widget-ului: a demo-ului proiectului, dacă are unul. */
    private function suggestedWidgetDomain(): string
    {
        $demo = $this->record->attachments()->whereNull('task_id')->where('role', AttachmentRole::Demo->value)->latest()->first();
        $host = $demo ? parse_url((string) $demo->href(), PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./', '', $host) : '';
    }

    public function widgetInviteShow0Action(): Action
    {
        return $this->inviteShow('widgetInviteShow0');
    }

    public function widgetInviteShow1Action(): Action
    {
        return $this->inviteShow('widgetInviteShow1');
    }

    public function widgetInviteShow2Action(): Action
    {
        return $this->inviteShow('widgetInviteShow2');
    }

    private function inviteShow(string $name): Action
    {
        return Action::make($name)->modalWidth('md')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
            ->modalHeading(fn (array $arguments) => __('Feedback on :domain', ['domain' => $this->ownInvite($arguments)->domain]))
            ->modalContent(fn (array $arguments) => $this->inviteView($this->ownInvite($arguments)));
    }

    /** Invitația ta încă valabilă (refolosibilă) pe un domeniu, cea mai nouă. */
    private function currentInvite(string $domain): ?WidgetInvite
    {
        return WidgetInvite::where('project_id', $this->record->id)->where('user_id', auth()->id())->where('domain', $domain)
            ->where('single_use', false)->active()->latest('id')->first();
    }

    /** QR-ul și linkul unei invitații, cu cât mai merge. */
    private function inviteView(WidgetInvite $invite): View
    {
        return view('filament.resources.projects.widget-qr', ['url' => $invite->url(), 'svg' => FeedbackWidget::qrSvg($invite->url()),
            'text' => __('Scan it with the phone camera, or send the link: :domain opens with the Feedback button on.', ['domain' => $invite->domain]),
            'note' => $invite->single_use ? __('It works once, for 10 minutes.') : ($invite->expires_at
                ? __('It works until :date, for anyone you give it to. You can stop it any time from “Active invitations”.', ['date' => $invite->expires_at->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM')])
                : __('It works until you stop it, for anyone you give it to: “Active invitations”.'))]);
    }

    private function ownInvite(array $arguments): WidgetInvite
    {
        return WidgetInvite::where('project_id', $this->record->id)->findOrFail((int) ($arguments['invite'] ?? 0));
    }

    /** Oprește o invitație: linkul nu mai merge, iar telefoanele intrate prin ea nu mai trimit. Cel care a dat-o, liderul sau adminul. */
    public function revokeInvite(int $id): void
    {
        $invite = WidgetInvite::where('project_id', $this->record->id)->findOrFail($id);
        abort_unless($invite->user_id === auth()->id() || auth()->user()->leads($this->record), 403);
        $invite->forceFill(['revoked_at' => now()])->save();
        Notification::make()->title(__('Invitation stopped'))->success()->send();
        $this->unmountAction();
    }

    public function closeDay(): void
    {
        $this->day = '';
    }

    /** O estimare (din git) se corectează sau se șterge de omul ei, de liderul proiectului sau de admin. */
    private function estimateFor(int $id): TimeEntry
    {
        $entry = TimeEntry::where('project_id', $this->record->id)->where('is_estimate', true)->findOrFail($id);
        abort_unless($entry->user_id === auth()->id() || auth()->user()->leads($this->record), 403);

        return $entry;
    }

    public function saveEstimate(int $id, string $value): void
    {
        $entry = $this->estimateFor($id);
        $minutes = WorkTime::parseDuration($value);
        if (! $minutes || $minutes > 1440) {
            Notification::make()->title(__('Write it as 2h 30m, 2h or 150'))->danger()->send();

            return;
        }
        $entry->update(['minutes' => $minutes, 'note' => Str::limit('Estimated, corrected by '.auth()->user()->name, 255, '')]);
    }

    public function deleteEstimate(int $id): void
    {
        $this->estimateFor($id)->update(['minutes' => 0]);                    // la zero, nu șters: amprenta paginilor (PageCache) se schimbă, graficul se reface
    }

    /** Timp pentru cineva care a raportat în ziua aceea fără niciun timp: el însuși, liderul sau adminul (legat de update-ul lui din ziua aceea). */
    public function addEstimate(int $userId, string $value): void
    {
        $person = User::findOrFail($userId);
        abort_unless($this->day !== '' && ($person->id === auth()->id() || auth()->user()->leads($this->record)), 403);
        $tz = $person->tz();                                                       // doar cine a raportat pe proiect în ziua aceea (rândul „fără timp"), nu oricine
        abort_unless($this->record->updates()->where('user_id', $person->id)
            ->whereBetween('created_at', [Carbon::parse($this->day, $tz)->startOfDay()->utc(), Carbon::parse($this->day, $tz)->endOfDay()->utc()])->exists(), 403);
        $minutes = WorkTime::parseDuration($value);
        if (! $minutes || $minutes > 1440) {
            Notification::make()->title(__('Write it as 2h 30m, 2h or 150'))->danger()->send();

            return;
        }
        Estimates::fill($person, $this->record, $this->day, $minutes, $person->tz());
    }

    public function loadMore(): void
    {
        $this->shown += 20;
    }

    /**
     * Sus, ansamblul (descrierea, ritmul pe 12 săptămâni, cifrele, ce urmează și ce e blocat); la stânga, cronologia - update-urile desfăcute, cu rândurile lor,
     * și, pentru echipă, documentele și fișierele noi printre ele; la dreapta sarcinile, documentele, cunoștințele.
     */
    protected function getViewData(): array
    {
        $project = $this->record;
        $viewer = auth()->user();
        $updates = $project->updates()->visibleTo($viewer)->with(['user', 'items'])->withCount(['comments as visible_comments_count' => fn ($q) => $q->visibleTo($viewer)])   // numărul de comentarii, pentru toate deodată
            ->latest()->limit($this->shown + 1)->get();
        $more = $updates->count() > $this->shown;
        $updates = $updates->take($this->shown);
        $oldest = $more ? $updates->last()?->created_at : null;                 // documentele și fișierele, doar pe perioada update-urilor arătate

        $entries = $updates->map(fn (Update $update) => ['kind' => 'update', 'at' => $update->created_at, 'update' => $update,
            'groups' => collect(UpdateItemType::cases())->map(fn (UpdateItemType $type) => ['type' => $type, 'items' => $update->items->where('type', $type)->values()])
                ->filter(fn (array $group) => $group['items']->isNotEmpty())->values()]);
        if (! $viewer->isClient()) {                                            // clientul: doar update-urile (neinterne); documentele lui sunt în blocul lor
            $entries = $entries->concat(DocumentVersion::with(['author', 'document'])->whereIn('document_id', $project->documents()->active()->select('id'))
                ->when($oldest, fn ($q) => $q->where('created_at', '>=', $oldest))->latest()->limit(40)->get()
                ->map(fn (DocumentVersion $version) => ['kind' => 'minor', 'at' => $version->created_at, 'by' => $version->user_id, 'who' => $version->author?->name,
                    'text' => $version->version === 1 ? __('New document «:title»', ['title' => $version->title]) : __('«:title» updated (version :version)', ['title' => $version->title, 'version' => $version->version]),
                    'url' => ProjectResource::getUrl('document', ['record' => $project, 'document' => $version->document_id, 'v' => $version->version === $version->document->version ? null : $version->version, 'changes' => $version->version > 1 ? 1 : null]),
                    'external' => false, 'extra' => $version->note, 'icon' => 'document']))
                ->concat($project->attachments()->with('author')->when($oldest, fn ($q) => $q->where('created_at', '>=', $oldest))->latest()->limit(40)->get()
                    ->map(fn (Attachment $attachment) => ['kind' => 'minor', 'at' => $attachment->created_at, 'by' => $attachment->user_id, 'who' => $attachment->author?->name,
                        'text' => $attachment->isFile() ? __('File: :title', ['title' => $attachment->title]) : __('Link: :title', ['title' => $attachment->title]),
                        'url' => $attachment->href(), 'external' => true, 'extra' => null, 'icon' => $attachment->isFile() ? 'file' : 'link']))
                // reperele: sarcinile terminate și ideile notate, printre update-uri
                ->concat($project->tasks()->with('assignee')->where('status', TaskStatus::Done->value)->when($oldest, fn ($q) => $q->where('done_at', '>=', $oldest))->latest('done_at')->limit(40)->get()
                    ->map(fn (Task $task) => ['kind' => 'minor', 'at' => $task->done_at, 'by' => $task->assignee_id, 'who' => $task->assignee?->name,
                        'text' => __('#:id «:title» done', ['id' => $task->id, 'title' => $task->title]), 'url' => TaskResource::getUrl('view', ['record' => $task]),
                        'external' => false, 'extra' => null, 'icon' => 'done']))
                ->concat($project->tasks()->with('creator')->where('status', TaskStatus::Idea->value)->when($oldest, fn ($q) => $q->where('created_at', '>=', $oldest))->latest()->limit(20)->get()
                    ->map(fn (Task $task) => ['kind' => 'minor', 'at' => $task->created_at, 'by' => $task->created_by, 'who' => $task->creator?->name,
                        'text' => __('Idea: :title', ['title' => $task->title]), 'url' => TaskResource::getUrl('view', ['record' => $task]),
                        'external' => false, 'extra' => null, 'icon' => 'idea']));
        }

        return [
            'overview' => $this->overview($project, $viewer),
            'dayDetail' => $this->day !== '' ? ProjectActivity::day($project, $this->day, $viewer) : null,
            'isToday' => $this->day >= now($viewer->tz())->toDateString(),
            'timeline' => $entries->sortByDesc('at')->values(),
            'more' => $more,
        ];
    }

    /** Ansamblul: descrierea (pentru echipă), ritmul, cifrele, de când, cine, ce urmează (din ultimul update al fiecăruia, 14 zile) și ce e blocat. */
    private function overview(Project $project, User $viewer): array
    {
        $client = $viewer->isClient();
        $recent = $project->updates()->visibleTo($viewer)->where('created_at', '>=', now()->subDays(14))->with(['items.task', 'user'])->latest()->get()->unique('user_id');
        $blockedTasks = $project->tasks()->where('status', TaskStatus::Blocked->value)->latest('updated_at')->get(['id', 'title', 'blocked_reason', 'assignee_id']);
        $first = collect([$project->created_at, $project->updates()->min('created_at')])->filter()->map(fn ($at) => Carbon::parse($at))->min();
        $monthStart = now($viewer->tz())->startOfMonth();

        $doneCount = $project->tasks()->where('status', TaskStatus::Done->value)->count();
        $openCount = $project->tasks()->open()->count();

        $days = ProjectActivity::timeline($project, $viewer);

        return [
            'progress' => $doneCount + $openCount > 0 ? ['done' => $doneCount, 'total' => $doneCount + $openCount, 'percent' => (int) round($doneCount * 100 / ($doneCount + $openCount))] : null,
            'about' => $client ? null : $project->description,
            'days' => $days,
            // tot timpul de pe proiect (măsurat + adăugat + estimat din git), din aceleași zile ca graficul
            'total_minutes' => $days && $days['hours'] ? array_sum(array_column($days['days'], 'value')) : ($viewer->can('viewMinutes', $project) ? 0 : null),
            'estimated_minutes' => $days && $days['hours'] ? $days['estimated'] : 0,     // din commit-uri: în total, spus alături
            'agent_minutes' => $days && $days['hours'] ? $days['agent'] : 0,             // cât a lucrat agentul, neîmpărțit: doar informație
            // timpul pe etape (cine vede ore), din aceleași zile ca graficul: măsurat + adăugat + estimat, cu partea estimată spusă
            'stages' => $days && $days['hours'] ? collect($project->stagePeriods(now($viewer->tz())->toDateString()))->map(function (array $s) use ($days) {
                $in = collect($days['days'])->filter(fn (array $d) => $d['date'] >= $s['from'] && $d['date'] <= $s['to']);

                return $s + ['minutes' => (int) $in->sum('value'), 'estimated' => (int) $in->sum('estimated'), 'worked' => $in->where('active', true)->count()];
            })->all() : [],
            'since' => $first,
            'people' => $client ? null : $project->updates()->distinct()->count('user_id'),
            'updates' => $project->updates()->visibleTo($viewer)->count(),
            'done' => $project->tasks()->where('status', TaskStatus::Done->value)->count(),
            'open' => $project->tasks()->open()->count(),
            'blocked' => $blockedTasks->count(),
            'health' => ProjectCards::health($blockedTasks->isNotEmpty() || UpdateItem::recentBlockers(7)->whereHas('statusUpdate', fn ($u) => $u->visibleTo($viewer)->where('project_id', $project->id))->exists(),
                $project->updates()->visibleTo($viewer)->max('created_at') ?? $project->created_at),
            'minutes' => $viewer->can('viewMinutes', $project)                         // luna, din aceleași zile ca graficul (fără o socoteală în plus)
                ? ($days && $days['hours'] ? (int) collect($days['days'])->where('date', '>=', $monthStart->toDateString())->sum('value') : 0) : null,
            'month' => $monthStart->locale(app()->getLocale())->isoFormat('MMMM'),                // în mijlocul frazei: „Timp, octombrie"
            'next' => $recent->flatMap(fn (Update $update) => $update->items->where('type', UpdateItemType::Next)->filter(fn (UpdateItem $item) => $item->isOpen())   // rezolvate sau făcute: nu mai urmează
                ->map(fn ($item) => ['text' => $item->text, 'task_id' => $item->task_id, 'who' => $update->user, 'update' => $update, 'item_id' => $item->id]))->values(),
            'blockers' => $blockedTasks->map(fn (Task $task) => ['text' => $task->title.($task->blocked_reason ? ': '.$task->blocked_reason : ''), 'task_id' => $task->id, 'update' => null, 'item_id' => null])
                ->concat(UpdateItem::recentBlockers(7)->whereHas('statusUpdate', fn ($u) => $u->visibleTo($viewer)->where('project_id', $project->id))->get()
                    ->reject(fn (UpdateItem $item) => ($item->task_id && $blockedTasks->contains('id', $item->task_id))       // același blocaj, o dată
                        || $blockedTasks->contains(fn (Task $task) => $task->blocked_reason && mb_strtolower(trim($task->blocked_reason)) === mb_strtolower(trim($item->text))))
                    ->map(fn (UpdateItem $item) => ['text' => $item->text, 'task_id' => $item->task_id, 'update' => $item->statusUpdate, 'item_id' => $item->id]))
                ->values(),
        ];
    }

    /** Un blocaj sau un pas următor rezolvat ori făcut sarcină (ItemActions): „Ce urmează" se ia din nou. */
    #[On('items-changed')]
    public function itemsChanged(): void {}

    /** Înapoi: clientul (pentru echipă), altfel lista proiectelor. */
    public function backLinks(): array
    {
        $project = $this->record;

        return $project->client && ! auth()->user()->isClient()
            ? BackLinks::to($project->client->name, ClientResource::getUrl('view', ['record' => $project->client]))
            : BackLinks::to(__('Projects'), ProjectResource::getUrl('index'));
    }
}
