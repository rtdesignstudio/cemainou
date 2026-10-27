<x-filament-panels::page>
    {{-- din notificarea „Elena a răspuns · Deblochezi?": răspunsul clientului e mai jos, la comentarii; deblocarea, dintr-un clic --}}
    @if ($askUnblock)
        <div class="wsa-unblock">
            <p><b>{{ __('The client answered.') }}</b> {{ __('Unblock the task and get back to it?') }}</p>
            <div>
                <button type="button" class="wsa-unblock-go" wire:click="unblock">{{ __('Unblock') }}</button>
                <a href="#comments" class="wsa-inline-btn">{{ __('See the answer') }}</a>
            </div>
        </div>
    @endif
    @if ($this->record->notes)
        @php $notesHtml = (string) \App\Support\Markdown::html($this->record->notes, breaks: true); $canTick = auth()->user()->can('move', [$this->record, $this->record->status]) || auth()->user()->can('edit', $this->record); @endphp
        @if ($list = $this->record->checklist())
            {{-- pașii: bara pe toată lățimea, cu câți sunt gata în dreapta --}}
            <div class="wsa-task-progress">
                <div class="wsa-task-progress-head"><span class="wsa-label">{{ __('Steps') }}</span><span>{{ __(':done of :total done', ['done' => $list['done'], 'total' => $list['total']]) }}</span></div>
                <div class="wsa-progress-bar" role="progressbar" aria-valuenow="{{ $list['done'] }}" aria-valuemin="0" aria-valuemax="{{ $list['total'] }}"><span style="width: {{ round($list['done'] * 100 / $list['total']) }}%"></span></div>
            </div>
        @endif
        <div class="wsa-notes wsa-prose is-compact wsa-task-notes">{!! \App\Support\Checklist::rows($canTick ? \App\Support\Checklist::interactive($notesHtml) : $notesHtml) !!}</div>
    @endif

    @if ($this->record->status === \App\Enums\TaskStatus::Done && ($this->record->proof || $this->record->uncheckedSteps()))
        <div class="wsa-proof">
            @if ($this->record->proof)
                <p><span class="wsa-label">{{ __('Proof') }}</span> {{ \App\Support\Markdown::inline($this->record->proof) }}</p>
            @endif
            @if ($left = $this->record->uncheckedSteps())
                <p class="wsa-meta">{{ trans_choice('Closed with :count step not ticked.|Closed with :count steps not ticked.', $left) }}</p>
            @endif
        </div>
    @endif

    @if ($this->record->status === \App\Enums\TaskStatus::Blocked && $this->record->blocked_reason)
        <div class="wsa-blocker"><p>{{ __('Waiting for') }}: {{ \App\Support\Markdown::inline($this->record->blocked_reason) }}</p></div>
    @endif

    @if ($site = $this->record->site_context)
        {{-- trimisă din widget-ul de pe site: pagina, browserul, ecranul, elementul arătat și erorile paginii --}}
        <section class="wsa-site-context">
            <span class="wsa-label">{{ __('From the site') }}</span>
            @if ($site['url'] ?? null)
                <p><a href="{{ $site['url'] }}" target="_blank" rel="noopener">{{ $site['title'] ?? $site['url'] }}</a> <span class="wsa-meta">{{ $site['path'] ?? '' }}</span></p>
            @endif
            <div class="wsa-site-chips">
                @foreach (array_filter([
                    $site['browser'] ?? null, $site['os'] ?? null, isset($site['device']) ? __(ucfirst($site['device'])) : null,
                    isset($site['viewport']['w']) ? $site['viewport']['w'].'×'.$site['viewport']['h'].(($site['dpr'] ?? 1) != 1 ? ' @'.$site['dpr'].'x' : '') : null,
                    isset($site['screen']['w']) ? __('Screen').' '.$site['screen']['w'].'×'.$site['screen']['h'] : null,
                    $site['lang'] ?? null, $site['tz'] ?? null, isset($site['theme']) ? __(ucfirst($site['theme']).' theme') : null,
                    ($site['scroll']['max'] ?? 0) > 0 ? __('Scrolled :y of :max px', ['y' => $site['scroll']['y'] ?? 0, 'max' => $site['scroll']['max'] ?? 0]) : null,
                ]) as $chip)
                    <span class="wsa-chip">{{ $chip }}</span>
                @endforeach
            </div>
            @foreach ($site['elements'] ?? array_filter([$site['element'] ?? null]) as $el)
                @if ($el['selector'] ?? null)
                    <p><span class="wsa-meta">{{ __('Element') }}</span> @if ($el['text'] ?? null)«{{ $el['text'] }}» @endif<code>{{ $el['selector'] }}</code></p>
                @endif
            @endforeach
            @if ($site['guest']['name'] ?? null)
                <p><b>{{ __('Sent by :name', ['name' => $site['guest']['name']]) }}</b>@if ($site['guest']['email'] ?? null) · <a href="mailto:{{ $site['guest']['email'] }}">{{ $site['guest']['email'] }}</a>@endif
                    <span class="wsa-meta">{{ __('through the QR code of :name', ['name' => $this->record->creator?->name]) }}</span></p>
            @endif
            @if ($site['referrer'] ?? null)
                <p class="wsa-meta">{{ __('Came from') }} {{ $site['referrer'] }}</p>
            @endif
            @if ($site['errors'] ?? [])
                <details>
                    <summary>{{ trans_choice(':count error on the page|:count errors on the page', count($site['errors'])) }}</summary>
                    @foreach ($site['errors'] as $error)
                        <p><code>{{ $error['msg'] }}</code>@if ($error['src'] ?? null) <span class="wsa-meta">{{ $error['src'] }}{{ isset($error['line']) ? ':'.$error['line'] : '' }}</span>@endif</p>
                    @endforeach
                </details>
            @endif
            {{-- cererile paginii care au dat greș (un formular care primește 500, o imagine care lipsește): de multe ori, cauza --}}
            @if ($site['requests'] ?? [])
                <details>
                    <summary>{{ trans_choice(':count failed request|:count failed requests', count($site['requests'])) }}</summary>
                    @foreach ($site['requests'] as $req)
                        <p><code>{{ $req['method'] ?? 'GET' }} {{ $req['url'] }}</code> <span class="wsa-meta">{{ ($req['status'] ?? 0) ? $req['status'] : __('no answer') }}</span></p>
                    @endforeach
                </details>
            @endif
        </section>
    @endif

    <livewire:task-files :task="$this->record" />
    <livewire:task-comments :task="$this->record" />

    {{-- în update-uri: zilele în care s-a lucrat la ea, cu rândul din update și omul --}}
    @if ($mentions->isNotEmpty())
        <section class="wsa-block">
            <header class="wsa-block-head"><h2>{{ __('In updates') }}</h2><span>{{ trans_choice(':count day of work|:count days of work', $mentions->map(fn ($i) => $i->statusUpdate->created_at->toDateString())->unique()->count()) }}</span></header>
            @foreach ($mentions as $line)
                <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $line->statusUpdate]) }}" class="wsa-row has-mark" wire:key="m-{{ $line->id }}">
                    <span @class(['wsa-mark', 'is-done' => $line->type === \App\Enums\UpdateItemType::Done, 'is-doing' => $line->type === \App\Enums\UpdateItemType::Doing, 'is-blocked' => $line->type === \App\Enums\UpdateItemType::Blocked, 'is-todo' => $line->type === \App\Enums\UpdateItemType::Next]) aria-hidden="true"></span>
                    <span class="wsa-row-title">{{ \App\Support\Markdown::inline($line->text, links: false) }}</span>
                    <span class="wsa-row-meta">{{ $line->type->sectionLabel() }} · {{ $line->statusUpdate->user?->name }} · {{ $line->statusUpdate->created_at->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMM') }}</span>
                </a>
            @endforeach
        </section>
    @endif

    {{-- commit-urile care o pomenesc în mesaj (#12), trimise de agent la /upd; cu link spre GitHub / GitLab, dacă proiectul are repo-ul --}}
    @if ($commits->isNotEmpty())
        <section class="wsa-block">
            <header class="wsa-block-head"><h2>{{ __('Commits') }}</h2><span>{{ $commits->count() }}</span></header>
            @foreach ($commits as $commit)
                @php($commitUrl = \App\Support\UpdateLinks::commitUrl($commit->hash, $this->record->project->repo_key))
                <div class="wsa-row" wire:key="c-{{ $commit->id }}">
                    <span class="wsa-row-title">@if ($commitUrl)<a href="{{ $commitUrl }}" target="_blank" rel="noopener"><code>{{ substr($commit->hash, 0, 7) }}</code></a>@else<code>{{ substr($commit->hash, 0, 7) }}</code>@endif {{ $commit->subject }}</span>
                    <span class="wsa-row-meta">{{ collect([$commit->closes ? __('closes it') : null, $commit->user?->name, $commit->committed_on?->locale(app()->getLocale())->isoFormat('D MMM')])->filter()->join(' · ') }}</span>
                </div>
            @endforeach
        </section>
    @endif

    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('History') }}</h2></header>
        @foreach ($events as $row)
            @php($event = $row['event'])
            <div class="wsa-history">
                <div class="wsa-row-when">{{ $row['when'] }}</div>
                <div class="wsa-row-text">
                    @if ($event->statusUpdate)
                        <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $event->statusUpdate]) }}">{{ $event->describe() }}</a>
                    @else
                        {{ $event->describe() }}
                    @endif
                </div>
            </div>
        @endforeach
    </section>
</x-filament-panels::page>
