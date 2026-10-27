{{--
    Update-urile ca flux: cifrele săptămânii, filtrele rapide (toate, ale mele, cu blocaje, cu decizii), omul, proiectul, perioada, căutarea și „Compact”;
    apoi pe zile: ora, cine, proiectul, rezumatul, câte rânduri de fiecare fel, blocajele și deciziile la vedere, comentariile și „văzut".
--}}
@php
    use App\Enums\UpdateItemType;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Filament\Resources\Users\UserResource;
    use App\Support\Markdown;
    use Illuminate\Support\Carbon;

    $data = $this->feed();
    $s = $data['summary'];
    $viewer = auth()->user();
    $client = $viewer->isClient();
    $tz = $viewer->tz();
    $count = fn (int $n, UpdateItemType $type) => match ($type) {
        UpdateItemType::Done => trans_choice(':count done|:count done', $n),
        UpdateItemType::Doing => trans_choice(':count in progress|:count in progress', $n),
        UpdateItemType::Next => trans_choice(':count next|:count next', $n),
        default => null,
    };
@endphp
<div @class(['wsa-feed', 'is-compact' => $this->compact])>
    <x-wsa.stats>
        <x-wsa.stat :label="__('Updates this week')" :meta="__('last week: :count', ['count' => $s['previous']])">{{ $s['updates'] }}</x-wsa.stat>
        @unless ($client)
            <x-wsa.stat :label="__('Reported this week')" :meta="__('of :count people', ['count' => $s['team']])">{{ $s['people'] }}</x-wsa.stat>
        @endunless
        <x-wsa.stat :label="__('Blockers this week')" :blocked="$s['blocked'] > 0">{{ $s['blocked'] }}</x-wsa.stat>
        <x-wsa.stat :label="__('Decisions this week')">{{ $s['decisions'] }}</x-wsa.stat>
    </x-wsa.stats>

    <div class="wsa-toolbar wsa-feed-bar">
        <nav class="wsa-tabs" aria-label="{{ __('Show') }}">
            @foreach (['all' => __('All'), 'mine' => __('Mine'), 'blocked' => __('With blockers'), 'decisions' => __('With decisions')] as $key => $label)
                @continue($key === 'mine' && $client)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(['is-on' => $this->filter === $key])>{{ $label }}</button>
            @endforeach
        </nav>
        <div class="wsa-toolbar-tools">
            <label class="wsa-cmds-search">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
                <input type="search" wire:model.live.debounce.300ms="find" placeholder="{{ __('Search the updates') }}" aria-label="{{ __('Search') }}">
            </label>
            <select wire:model.live="period" class="wsa-portfolio-select" aria-label="{{ __('Period') }}">
                <option value="">{{ __('Any time') }}</option>
                <option value="week">{{ __('This week') }}</option>
                <option value="month">{{ __('This month') }}</option>
                <option value="180">{{ __('6 months') }}</option>
                <option value="365">{{ __('A year') }}</option>
                <option value="custom">{{ __('Choose the days') }}</option>
            </select>
            @if ($this->period === 'custom')
                <input type="date" wire:model.live="from" class="wsa-portfolio-select" aria-label="{{ __('From') }}">
                <input type="date" wire:model.live="until" class="wsa-portfolio-select" aria-label="{{ __('Until') }}">
            @endif
            <button type="button" wire:click="$toggle('compact')" @class(['wsa-feed-compact', 'is-on' => $this->compact]) aria-pressed="{{ $this->compact ? 'true' : 'false' }}">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedQueueList" class="wsa-feed-icon" />{{ __('Compact') }}
            </button>
        </div>
    </div>

    {{-- oamenii și proiectele la vedere, ca la Sarcini --}}
    <div class="wsa-task-layers">
        @unless ($client)
            <x-wsa.chips :items="$data['people']->map(fn ($name, $id) => $id === $viewer->id ? __('Me') : $name)" model="person" :all="__('All people')" :current="$this->person" :label="__('People')" />
        @endunless
        <x-wsa.chips :items="$data['projects']" model="projectId" :all="__('All projects')" :current="$this->projectId" :label="__('Projects')" />
    </div>

    @forelse ($data['days'] as $date => $updates)
        <section class="wsa-feed-day" wire:key="day-{{ $date }}">
            <header class="wsa-feed-dayhead">
                <h2>{{ ucfirst(Carbon::parse($date)->locale(app()->getLocale())->isoFormat('dddd, D MMMM')) }}</h2>
                <span class="wsa-meta">{{ trans_choice(':count update|:count updates', $updates->count()) }}</span>
            </header>
            @foreach ($updates as $update)
                @php
                    $byType = $update->items->groupBy(fn ($item) => $item->type->value);
                    $blocked = $byType->get(UpdateItemType::Blocked->value, collect());
                    $decisions = $byType->get(UpdateItemType::Decision->value, collect());
                    $done = $byType->get(UpdateItemType::Done->value, collect());
                    $counts = collect([UpdateItemType::Done, UpdateItemType::Doing, UpdateItemType::Next])
                        ->map(fn ($type) => ($n = $byType->get($type->value, collect())->count()) ? $count($n, $type) : null)->filter();
                @endphp
                <article class="wsa-feed-item" wire:key="u-{{ $update->id }}">
                    <time class="wsa-feed-time" datetime="{{ $update->created_at->toIso8601String() }}">{{ $update->created_at->copy()->setTimezone($tz)->format('H:i') }}</time>
                    <div class="wsa-feed-body">
                        <div class="wsa-feed-meta">
                            @if ($update->user && ! $client)<a href="{{ UserResource::getUrl('view', ['record' => $update->user]) }}">{{ $update->user->name }}</a>@else{{ $update->user?->name }}@endif
                            @if ($update->project) · <a href="{{ ProjectResource::getUrl('view', ['record' => $update->project]) }}" class="wsa-feed-project">{{ $update->project->name }}</a>@endif
                            @if ($update->is_internal) · <span class="wsa-tag is-soft">{{ __('Internal') }}</span>@endif
                        </div>
                        <a href="{{ UpdateResource::getUrl('view', ['record' => $update]) }}" class="wsa-feed-summary"><x-wsa.new :at="$update->created_at" :by="$update->user_id" />{{ Markdown::inline($update->summary, links: false) }}</a>
                        @if (! $this->compact && ($done->isNotEmpty() || $blocked->isNotEmpty() || $decisions->isNotEmpty()))
                            {{-- în card: ce s-a făcut (primele 3), ce e blocat, iar deciziile pe un singur rând --}}
                            <ul class="wsa-feed-lines">
                                @foreach ($blocked->take(3) as $item)
                                    <li><span class="wsa-mark is-blocked" aria-hidden="true"></span><span>@if ($item->task_id)<a href="{{ TaskResource::getUrl('view', ['record' => $item->task_id]) }}" class="wsa-id">#{{ $item->task_id }}</a>@endif{{ Markdown::inline($item->text) }}</span></li>
                                @endforeach
                                @foreach ($done->take(3) as $item)
                                    <li><span class="wsa-mark is-done" aria-hidden="true"></span><span>@if ($item->task_id)<a href="{{ TaskResource::getUrl('view', ['record' => $item->task_id]) }}" class="wsa-id">#{{ $item->task_id }}</a>@endif{{ Markdown::inline($item->text) }}</span></li>
                                @endforeach
                                @if ($done->count() > 3)
                                    <li class="wsa-feed-more-lines"><a href="{{ UpdateResource::getUrl('view', ['record' => $update]) }}">{{ __('+ :count more done', ['count' => $done->count() - 3]) }}</a></li>
                                @endif
                                @if ($decisions->isNotEmpty())
                                    <li class="wsa-feed-decisions"><span class="wsa-mark is-decision" aria-hidden="true"></span><span><strong>{{ __('Decisions') }}:</strong> {{ $decisions->map(fn ($item) => Markdown::plain($item->text))->join(' · ') }}</span></li>
                                @endif
                            </ul>
                        @endif
                        @unless ($this->compact)
                        <p class="wsa-feed-counts">
                            @foreach ($counts as $label)<span>{{ $label }}</span>@endforeach
                            @if ($update->comments_count)<span><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedChatBubbleLeft" class="wsa-feed-icon" />{{ $update->comments_count }}</span>@endif
                            @if ($update->acks_count && ! $client)<span><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedEye" class="wsa-feed-icon" />{{ $update->acks_count }}</span>@endif
                        </p>
                        @endunless
                    </div>
                </article>
            @endforeach
        </section>
    @empty
        <p class="wsa-empty">{{ $this->filter !== 'all' || $this->person || $this->projectId || trim($this->find) !== '' || $this->period !== '' ? __('No update matches.') : __('No updates yet. Run /upd in your agent, or write one with “New update”.') }}</p>
    @endforelse

    @if ($data['more'])
        <div class="wsa-feed-more"><button type="button" class="wsa-inline-btn" wire:click="more" wire:loading.attr="disabled">{{ __('Older updates') }}</button></div>
    @endif
</div>
