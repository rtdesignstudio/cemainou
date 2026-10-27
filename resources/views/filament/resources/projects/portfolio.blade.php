{{--
    Proiectele în carduri (App\Support\ProjectPortfolio): sus cifrele portofoliului și filtrele, apoi câte un card pe proiect -
    starea, timpul investit (total, luna, 7 zile), evoluția pe 12 săptămâni, cine cât a lucrat, sarcinile, blocajul, ultimul update și ce urmează.
--}}
@php
    use App\Filament\Resources\Clients\ClientResource;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Filament\Resources\Users\UserResource;
    use App\Support\Markdown;
    use App\Support\WorkTime;

    // cifrele și cardurile se desenează o dată pe minut (sau imediat ce cineva scrie ceva): PageCache, pe filtre și perioadă
    $isClient = auth()->user()->isClient();
    [$pFrom, $pTo] = $this->period();
    $params = [$this->status, $this->clientId, $this->leadId, trim($this->find), $this->sort, $pFrom, $pTo];
    $built = null;
    $data = function () use (&$built) { return $built ??= $this->portfolio(); };
    $clients = $isClient ? collect() : \App\Models\Client::whereIn('id', \App\Models\Project::visibleTo(auth()->user())->whereNotNull('client_id')->select('client_id'))->orderBy('name')->pluck('name', 'id');
    $leads = $isClient ? collect() : \App\Models\User::whereIn('id', \App\Filament\Resources\Projects\ProjectResource::getEloquentQuery()->where('is_archived', $this->status === 'archived')->whereNotNull('lead_id')->select('lead_id'))->orderBy('name')->pluck('name', 'id')
        ->map(fn ($name, $id) => $id === auth()->id() ? __('Me') : $name);
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->join(''));
    $time = fn (?int $minutes) => $minutes ? WorkTime::format($minutes) : '-';
    $tasksUrl = fn ($project) => TaskResource::getUrl('index', ['tab' => 'open', 'project' => $project->id]);
@endphp
<div class="wsa-portfolio">
    {{-- primul rând: perioada cifrelor din carduri (timpul pe perioadă, graficul, cine cât a lucrat) și Carduri / Tabel; „Timp investit" rămâne totalul --}}
    <div class="wsa-portfolio-top">
        @unless ($isClient)
            <nav class="wsa-tabs" aria-label="{{ __('Period') }}">
                @foreach (['month' => __('Current month'), '30' => __('Last 30 days'), '90' => __('Last 90 days'), '180' => __('6 months'), '365' => __('A year'), 'custom' => __('A range')] as $key => $name)
                    <button type="button" wire:click="pick('{{ $key }}')" @class(['is-on' => $this->range === (string) $key])>{{ $name }}</button>
                @endforeach
            </nav>
        @endunless
        @include('filament.resources.projects.layout-switch')
    </div>
    @unless ($isClient)
        @if ($this->range === 'custom')
            <div class="wsa-grid-nav wsa-portfolio-dates">
                <label class="wsa-h-date"><span class="wsa-label">{{ __('From') }}</span><input type="date" wire:model.live.debounce.500ms="from" max="{{ now(auth()->user()->tz())->toDateString() }}"></label>
                <label class="wsa-h-date"><span class="wsa-label">{{ __('To') }}</span><input type="date" wire:model.live.debounce.500ms="to" max="{{ now(auth()->user()->tz())->toDateString() }}"></label>
            </div>
        @endif
    @endunless

    @if ($this->status === 'active')
        {{ \App\Support\PageCache::html('portfolio-stats', auth()->user(), $params, fn () => view('filament.resources.projects.portfolio-stats', ['summary' => $data()['summary'], 'periodLabel' => $this->periodLabel()])) }}
    @endif

    <div class="wsa-portfolio-filters">
        <div class="wsa-portfolio-tabs" role="tablist">
            @foreach (['active' => __('Active ones'), 'archived' => __('Archived ones')] as $value => $label)
                <button type="button" role="tab" aria-selected="{{ $this->status === $value ? 'true' : 'false' }}" @class(['is-on' => $this->status === $value]) wire:click="$set('status', '{{ $value }}')">{{ $label }}</button>
            @endforeach
        </div>
        <label class="wsa-cmds-search">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
            <input type="search" wire:model.live.debounce.300ms="find" placeholder="{{ __('Search a project or a client') }}" aria-label="{{ __('Search') }}">
        </label>
        <div class="wsa-portfolio-selects">
        <select wire:model.live="sort" class="wsa-portfolio-select" aria-label="{{ __('Order') }}">
            <option value="recent">{{ __('Latest activity first') }}</option>
            @unless ($isClient)<option value="time">{{ __('Most time first') }}</option>@endunless
            <option value="name">{{ __('By name') }}</option>
        </select>
        </div>
    </div>

    {{-- clienții și cine conduce, la vedere, ca la Sarcini --}}
    @unless ($isClient)
        <div class="wsa-task-layers">
            <x-wsa.chips :items="$clients" model="clientId" :all="__('All clients')" :current="$this->clientId" :label="__('Clients')" />
            <x-wsa.chips :items="$leads" model="leadId" :all="__('Any lead')" :current="$this->leadId" :label="__('Lead')" />
        </div>
    @endunless

    {{ \App\Support\PageCache::html('portfolio-grid', auth()->user(), $params, fn () => view('filament.resources.projects.portfolio-grid', [
        'cards' => $data()['cards'], 'empty' => trim($this->find) !== '' || $this->clientId || $this->leadId ? __('No project matches.') : ($this->status === 'archived' ? __('No archived projects.') : __('No projects yet. Start one with “New project”.')),
        'periodLabel' => $this->periodLabel(), 'pFrom' => $pFrom, 'pTo' => $pTo,
    ])) }}
</div>
