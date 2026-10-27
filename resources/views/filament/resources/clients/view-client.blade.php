@php
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Filament\Resources\Users\UserResource;
    use App\Support\Markdown;
    use App\Support\WorkTime;

    $projectLink = fn ($project) => $project ? '<a href="'.e(ProjectResource::getUrl('view', ['record' => $project])).'">'.e($project->name).'</a>' : '';
@endphp
<x-filament-panels::page>
    {{-- cifrele clientului: proiectele, sarcinile deschise (și blocate), timpul lunii și cel total (cine are voie la ore), ultima activitate --}}
    @if ($band)
        <x-wsa.stats>
            <x-wsa.stat :label="__('Active projects')">{{ $band['projects']->count() }}</x-wsa.stat>
            <x-wsa.stat :label="__('Open tasks')" :meta="$band['blocked'] ? trans_choice(':count blocked|:count blocked', $band['blocked']) : null" :blocked="$band['blocked'] > 0">{{ $band['open'] }}</x-wsa.stat>
            @if ($band['month'] !== null)
                <x-wsa.stat :label="__('This month')">@if ($band['month'])<x-wsa.duration :minutes="$band['month']" />@else 0m @endif</x-wsa.stat>
                <x-wsa.stat :label="__('In total')">@if ($band['total'])<x-wsa.duration :minutes="$band['total']" />@else 0m @endif</x-wsa.stat>
            @endif
            <x-wsa.stat :label="__('Last activity')">@if ($band['last'])<x-wsa.time :at="$band['last']" />@else - @endif</x-wsa.stat>
        </x-wsa.stats>
    @endif

    <section class="wsa-block wsa-projects">
        <header class="wsa-block-head">
            <h2>{{ __('Projects') }}</h2>
            <span>{{ trans_choice(':count active|:count active', $cards->count()) }} · {{ trans_choice(':count finished|:count finished', $archived->count()) }}</span>
        </header>
        @if ($cards->isEmpty())
            <p class="wsa-empty">{{ __('No active project for this client.') }}</p>
        @else
            <div class="wsa-portfolio-grid">
                @foreach ($cards as $c)<x-wsa.pcard :c="$c" :period-label="__('Current month')" :from="$from" :to="$to" />@endforeach
            </div>
        @endif
        @if ($archived->isNotEmpty())
            <p class="wsa-archived">
                <span class="wsa-label">{{ __('Finished') }}</span>
                @foreach ($archived as $project)
                    <a href="{{ ProjectResource::getUrl('view', ['record' => $project]) }}">{{ $project->name }}</a>@unless ($loop->last)<span aria-hidden="true"> · </span>@endunless
                @endforeach
            </p>
        @endif
    </section>

    <div class="wsa-split">
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ __('Timeline') }}</h2>
                <span>{{ __('updates on all their projects, newest first') }}</span>
            </header>
            @forelse ($timeline as $u)
                @php($day = \App\Support\Ago::day($u->created_at))
                @if ($day !== ($lastDay ?? null))<div class="wsa-daybreak">{{ $lastDay = $day }}</div>@endif
                <div class="wsa-feed">
                    <x-wsa.new :at="$u->created_at" :by="$u->user_id" /><a href="{{ UpdateResource::getUrl('view', ['record' => $u]) }}" class="wsa-feed-text">{{ Markdown::inline($u->summary, links: false) }}</a>
                    <div class="wsa-feed-meta">
                        @if ($u->user)<a href="{{ UserResource::getUrl('view', ['record' => $u->user]) }}"><b>{{ $u->user->name }}</b></a> · @endif{!! $projectLink($u->project) !!} · <x-wsa.time :at="$u->created_at" />
                        @if ($u->is_internal) · <span class="wsa-tag is-soft">{{ __('Internal') }}</span>@endif
                    </div>
                </div>
            @empty
                <p class="wsa-empty">{{ __('No updates on their projects yet.') }}</p>
            @endforelse
        </section>

        <div class="wsa-aside">
            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('Open tasks') }}</h2>
                    <span>{{ __('asked by the client first') }}</span>
                </header>
                @forelse ($tasks as $task)
                    <a href="{{ TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-task">
                        <span class="wsa-mark is-{{ $task->status->value }}" title="{{ $task->status->getLabel() }}"><span class="sr-only">{{ $task->status->getLabel() }}</span></span>
                        <span class="wsa-task-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</span>
                        <span class="wsa-meta">{{ $task->fromClient() ? __('from :name', ['name' => $task->creator->name]) : ($task->assignee?->name ?? __('Free')) }} · {{ $task->project?->name }}</span>
                    </a>
                @empty
                    <p class="wsa-empty">{{ __('No open tasks.') }}</p>
                @endforelse
                @if ($tasks->isNotEmpty())
                    <x-wsa.task-legend />
                @endif
            </section>

            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('Shown to the client') }}</h2>
                    <span>{{ __('documents, files and links') }}</span>
                </header>
                @forelse ($shared as $item)
                    <div class="wsa-feed">
                        <a href="{{ $item['url'] }}" class="wsa-feed-text" @if ($item['external']) target="_blank" rel="noopener noreferrer" @endif>{{ $item['title'] }}</a>
                        <div class="wsa-feed-meta">{!! $projectLink($item['project']) !!} · <x-wsa.time :at="$item['at']" /></div>
                    </div>
                @empty
                    <p class="wsa-empty">{{ __('Nothing shown to the client yet. A document or file is shown from its project, or by the agent with share_with_client.') }}</p>
                @endforelse
            </section>

            <section class="wsa-block">
                <header class="wsa-block-head">
                    <h2>{{ __('Their people') }}</h2>
                    <span>{{ __('accounts in cemainou') }}</span>
                </header>
                @forelse ($people as $person)
                    <div class="wsa-feed">
                        <div class="wsa-feed-text">{{ $person->name }}@unless ($person->is_active) <span class="wsa-tag is-soft">{{ __('Inactive') }}</span>@endunless</div>
                        <div class="wsa-feed-meta"><a href="mailto:{{ $person->email }}">{{ $person->email }}</a> · {{ $person->last_seen_at ? __('Seen :time', ['time' => $person->last_seen_at->diffForHumans()]) : __('Not seen yet') }}</div>
                    </div>
                @empty
                    <p class="wsa-empty">{{ __('Nobody from the client has an account yet. Invite them from People, with the Client role.') }}</p>
                @endforelse
            </section>

            @if ($hours)
                <section class="wsa-block">
                    <header class="wsa-block-head">
                        <h2>{{ __('Hours') }}</h2>
                        @if ($hours['url'])<a href="{{ $hours['url'] }}" class="wsa-link">{{ __('All hours') }}<x-wsa.arrow /></a>@endif
                    </header>
                    <table class="wsa-hours-mini">
                        <thead><tr><th></th><th>{{ $hours['month'] }}</th><th>{{ $hours['previous'] }}</th></tr></thead>
                        <tbody>
                            @forelse ($hours['rows'] as $row)
                                <tr><th>{!! $projectLink($row['project']) !!}</th><td>{{ WorkTime::total($row['this']) }}</td><td>{{ WorkTime::total($row['last']) }}</td></tr>
                            @empty
                                <tr><th colspan="3" class="wsa-empty">{{ __('No time on their projects in these two months.') }}</th></tr>
                            @endforelse
                        </tbody>
                        @if ($hours['rows']->count() > 1)
                            <tfoot><tr><th>{{ __('Total') }}</th><td>{{ WorkTime::total($hours['this']) }}</td><td>{{ WorkTime::total($hours['last']) }}</td></tr></tfoot>
                        @endif
                    </table>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
