<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\WorkCategory;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Models\Project;
use App\Models\ProjectRepo;
use App\Models\ProjectTemplate;
use App\Support\FeedbackWidget;
use App\Support\SimilarProjects;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->live(onBlur: true)
                    // un nume asemănător cu al unui proiect existent: spus imediat, ca să nu apară o dublură (se poate crea oricum)
                    ->helperText(function (?string $state, $record) {
                        $similar = filled($state) ? SimilarProjects::to($state, auth()->user(), $record?->id) : collect();

                        return $similar->isEmpty() ? null : new HtmlString(e(__('Already there, with a similar name:')).' '.$similar->map(fn ($p) => '<a href="'.e(ProjectResource::getUrl('view', ['record' => $p])).'" target="_blank">'.e($p->name).'</a>'.($p->is_archived ? ' ('.e(__('archived')).')' : ''))->join(', ').'. '.e(__('If it is the same project, use that one.')));
                    }),
                Select::make('template_id')->label(__('Template'))->placeholder(__('Without a template'))->visibleOn('create')->dehydrated(false)
                    ->options(fn () => ProjectTemplate::orderBy('name')->pluck('name', 'id'))
                    ->helperText(fn () => new HtmlString(e(__('Its standard tasks are added on the project, free, with due dates from today.')).' <a href="'.e(ProjectTemplateResource::getUrl('index')).'" class="wsa-link-strong">'.e(__('The templates')).'</a>')),
                Select::make('client_id')->label(__('Client'))->relationship('client', 'name', fn ($query) => $query->visibleTo(auth()->user()))->searchable()->preload()->live()
                    ->createOptionForm([TextInput::make('name')->label(__('Name'))->required()->maxLength(255)]),
                Toggle::make('client_visible')->label(__('Visible to the client'))->visible(fn ($get) => filled($get('client_id')))
                    ->helperText(__('The client\'s people (accounts with the Client role) see this project\'s tasks, blockers and updates, except internal ones; they can add tasks and comment. Never hours, notes or documents.')),
                Select::make('lead_id')->label(__('Lead'))->relationship('lead', 'name', fn ($query) => $query->team()->visibleTo(auth()->user()))->searchable()->preload()
                    ->hidden(fn (string $operation) => $operation === 'create' && auth()->user()->isCollaborator())   // colaboratorul își conduce proiectul
                    ->helperText(__('The lead sees the team hours on this project.')),
                Select::make('categories')->label(__('Categories'))->options(WorkCategory::class)->multiple()->helperText(__('One or more, e.g. Design and Development.')),
                TextInput::make('repo_url')->label(__('Git repository'))->maxLength(500)->placeholder(__('git@github.com:team/project.git'))
                    ->helperText(__('The address from `git remote get-url origin`: agents find the project by it.'))->columnSpanFull()
                    ->rules([fn (?Project $record) => self::repoRule($record)]),
                Repeater::make('repos')->label(__('Other repositories'))->columnSpanFull()
                    ->relationship('repos', modifyQueryUsing: fn ($query) => $query->linked())
                    ->simple(TextInput::make('url')->label(__('Repository'))->required()->maxLength(500)->placeholder(__('git@github.com:team/project-api.git'))
                        ->rules([fn ($livewire) => self::repoRule(method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null)]))
                    ->defaultItems(0)->addActionLabel(__('Add a repository'))
                    ->helperText(__('Frontend and backend, a theme and a plugin, or forks: work from any of them lands on this project.')),
                // etapele: pe pagina proiectului, timpul pe fiecare (din aceleași zile ca graficul)
                Repeater::make('stages')->label(__('Stages'))->columnSpanFull()->columns(3)->defaultItems(0)->reorderable(false)->addActionLabel(__('Add a stage'))
                    ->schema([
                        TextInput::make('name')->label(__('Stage'))->required()->maxLength(120)->placeholder(__('Stage 1: logo and palette')),
                        DatePicker::make('from')->label(__('From'))->required()->native(false)->displayFormat('j M Y'),
                        DatePicker::make('to')->label(__('Until'))->native(false)->displayFormat('j M Y')->afterOrEqual('from')
                            ->helperText(__('Empty: until the next stage starts (the last one, until today).')),
                    ])
                    ->helperText(__('The project page shows the time put into each stage.')),
                Textarea::make('description')->label(__('Description'))->columnSpanFull()->helperText(__('Markdown: - list, **bold**, [link](https://...). Agents read it in /brief.')),
                ColorPicker::make('color')->label(__('Color')),
                Toggle::make('is_archived')->label(__('Archived'))->helperText(__('A project with updates or tasks is archived, not deleted.')),
                // widget-ul de feedback de pe site-ul clientului: vizibil doar pe dispozitivele activate de oamenii cemainou (App\Support\FeedbackWidget)
                Section::make(__('Feedback widget on the site'))->description(__('A Feedback button on the client\'s site, seen only by people of cemainou who turn it on from the project\'s page: visitors see nothing. What they send lands on this project.'))
                    ->collapsed(fn ($record) => ! $record?->widget_enabled)->columnSpanFull()->schema([
                        Toggle::make('widget_enabled')->label(__('On'))->live(),
                        TagsInput::make('widget_domains')->label(__('Where it works'))->placeholder(__('The site\'s address, then Enter'))->visible(fn ($get) => $get('widget_enabled'))->live()
                            ->nestedRecursiveRules(['max:255'])->helperText(fn ($get) => self::widgetScope((array) $get('widget_domains')))
                            ->required(fn ($get) => $get('widget_enabled')),
                        View::make('filament.resources.projects.widget-snippet')->visible(fn ($get) => $get('widget_enabled')),
                    ]),
                Section::make(__('Its own channel'))->description(__('Only if this project\'s updates go somewhere else than the team\'s channel (Settings).'))
                    ->collapsed()->columnSpanFull()->columns(2)->schema([
                        TextInput::make('notify_slack_webhook')->label(__('Slack webhook'))->password()->revealable()->url()->startsWith(['https://hooks.slack.com/'])->maxLength(500)->placeholder('https://hooks.slack.com/services/...'),
                        TextInput::make('notify_telegram_chat')->label(__('Telegram chat'))->maxLength(64)->placeholder('-1001234567890')
                            ->helperText(__('With the team\'s bot, from Settings.')),
                    ]),
            ]);
    }

    /** Un repo (oricum ar fi scris: ssh, https, cu .git) aparține unui singur proiect, ca principal sau în plus: altfel agentul n-ar ști pe care să-l aleagă. */
    private static function repoRule(?Project $record): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($record) {
            $key = Project::normalizeRepo($value);
            $owner = Project::repoOwner($key);
            if ($owner && ! $owner->is($record)) {
                $fail(__('Another project already uses this repository.'));
            } elseif ($key && ProjectRepo::where('repo_key', $key)->when($record, fn ($q) => $q->where('project_id', '!=', $record->id))->exists()) {
                $fail(__('Another project already has this repository among its suggestions.'));   // repo_key e unic în project_repos, indiferent de stare
            }
        };
    }

    /** Sub adrese, ce înseamnă exact cele scrise: cu o cale, doar paginile de sub ea; fără, tot site-ul și subdomeniile lui. */
    private static function widgetScope(array $domains): string
    {
        $parts = collect(FeedbackWidget::cleanDomains($domains) ?? [])->map(fn (string $d) => str_contains($d, '/')
            ? __(':address and the pages under it', ['address' => $d])
            : __('the whole :domain, subdomains too (staging.:domain)', ['domain' => $d]));

        return $parts->isEmpty()
            ? __('The site\'s address, or only a part of it with its path (site.md/demo). Press Enter after each.')
            : __('The button works only on: :where. Press Enter after each address.', ['where' => $parts->join('; ')]);
    }
}
