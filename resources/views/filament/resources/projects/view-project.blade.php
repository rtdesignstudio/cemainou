@php
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Support\Ago;
    use App\Support\Markdown;
    use App\Support\WorkTime;

    $o = $overview;
    $client = auth()->user()->isClient();
    $viewer = auth()->user();
    $p = $this->record;
    // „Totul despre proiect": fiecare cifră duce la lista lui completă (doar ce vede omul; clientul: fără ore, doar notițele firmei lui)
    $all = array_filter([
        [__('Tasks'), $p->tasks()->open()->count(), TaskResource::getUrl('index', ['tab' => 'open', 'project' => $p->id])],
        $client ? null : [__('Ideas'), $p->tasks()->where('status', \App\Enums\TaskStatus::Idea->value)->count(), TaskResource::getUrl('index', ['tab' => 'ideas', 'project' => $p->id])],
        [__('Finished'), $p->tasks()->whereIn('status', [\App\Enums\TaskStatus::Done->value, \App\Enums\TaskStatus::Dropped->value])->count(), TaskResource::getUrl('index', ['tab' => 'done', 'project' => $p->id])],
        [__('Updates'), $p->updates()->visibleTo($viewer)->count(), UpdateResource::getUrl('index', ['in' => $p->id])],
        [__('Documents'), $p->documents()->active()->where('version', '>', 0)->when($client, fn ($q) => $q->where('client_visible', true))->count()
            + $p->attachments()->whereNull('task_id')->when($client, fn ($q) => $q->where('client_visible', true))->count(), '#documents'],
        [__('Notes'), \App\Models\ProjectNote::visibleTo($viewer)->where('project_id', $p->id)->active()->count(), '#knowledge'],
        $viewer->can('viewMinutes', $p) ? [__('Hours'), null, \App\Filament\Pages\Hours::getUrl(['tab' => 'projects'])] : null,
    ]);
@endphp
<x-filament-panels::page>
    {{-- filele proiectului: „Pe scurt" (pagina asta, activă), apoi celelalte părți, fiecare pe pagina ei; pe telefon se derulează orizontal --}}
    <nav class="wsa-project-all" aria-label="{{ __('Everything about the project') }}">
        <a href="{{ request()->url() }}" aria-current="page" class="is-active">{{ __('Overview') }}</a>
        @foreach ($all as [$label, $count, $url])
            <a href="{{ $url }}">{{ $label }}@if ($count !== null)<span>{{ $count }}</span>@endif</a>
        @endforeach
    </nav>
    {{-- adresa de email a proiectului: ce se trimite sau se redirecționează acolo devine sarcină (când adminul a setat cutia) --}}
    @if ($inbox = \App\Support\InboundMail::addressFor($this->record))
        <p class="wsa-project-inbox" x-data="{ copied: false }">
            <span>{{ __('An email sent or forwarded to') }} <code>{{ $inbox }}</code> {{ __('becomes a task here.') }}</span>
            <button type="button" x-on:click="navigator.clipboard.writeText(@js($inbox)); copied = true; setTimeout(() => copied = false, 1500)"
                x-text="copied ? @js(__('Copied')) : @js(__('Copy the address'))">{{ __('Copy the address') }}</button>
        </p>
    @endif

    {{-- unde suntem: descrierea, banda de cifre, progresul sarcinilor, ce urmează (blocajele întâi), apoi proiectul pe zile --}}
    <section class="wsa-block wsa-overview">
        <header class="wsa-block-head">
            <h2>{{ __('Where we are') }} <x-wsa.health :health="$o['health']" class="wsa-health-head" /></h2>
            <span>
                @if ($o['since']){{ __('since :date', ['date' => $o['since']->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM YYYY')]) }}@endif
                @if ($o['people']) · {{ trans_choice(':count person|:count people', $o['people']) }}@endif
            </span>
        </header>
        {{-- descrierea, ca prim paragraf --}}
        @if ($o['about'])
            <p class="wsa-about wsa-overview-about">{{ Markdown::inline($o['about']) }}</p>
        @endif

        {{-- cifrele, în aceeași bandă ca pe celelalte pagini; timpul doar pentru cine are voie la ore --}}
        <x-wsa.stats class="wsa-overview-band">
            <x-wsa.stat :label="__('Updates')" :href="UpdateResource::getUrl('index', ['in' => $p->id])">{{ $o['updates'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Tasks done')" :href="TaskResource::getUrl('index', ['tab' => 'done', 'project' => $p->id])" :meta="$o['progress'] ? __(':percent% of :total', ['percent' => $o['progress']['percent'], 'total' => $o['progress']['total']]) : null">{{ $o['done'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Open')" :href="TaskResource::getUrl('index', ['tab' => 'open', 'project' => $p->id])">{{ $o['open'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Blocked tasks')" :blocked="$o['blocked'] > 0" :href="TaskResource::getUrl('index', ['tab' => 'open', 'show' => 'blocked', 'project' => $p->id])">{{ $o['blocked'] }}</x-wsa.stat>
            @if ($o['minutes'] !== null)
                <x-wsa.stat :label="__('Time, :month', ['month' => $o['month']])" :href="\App\Filament\Pages\Hours::getUrl(['tab' => 'projects'])">@if ($o['minutes'])<x-wsa.duration :minutes="$o['minutes']" />@else 0m @endif</x-wsa.stat>
                @if ($o['total_minutes'] !== null)
                    <x-wsa.stat :label="__('Total time')" href="#zile" :meta="collect([$o['estimated_minutes'] ? __('of it ~:time estimated from git', ['time' => \App\Support\WorkTime::format($o['estimated_minutes'])]) : null, $o['agent_minutes'] ? __('the agent worked :time', ['time' => \App\Support\WorkTime::format($o['agent_minutes'])]) : null, $o['days'] ? __('since :date', ['date' => \Illuminate\Support\Carbon::parse($o['days']['from'])->locale(app()->getLocale())->isoFormat('D MMM YYYY')]) : null])->filter()->join(' · ') ?: null">@if ($o['total_minutes'])<x-wsa.duration :minutes="$o['total_minutes']" />@else 0m @endif</x-wsa.stat>
                @endif
            @endif
        </x-wsa.stats>

        {{-- progresul sarcinilor, pe toată lățimea --}}
        @if ($o['progress'])
            <div class="wsa-task-progress wsa-overview-progress">
                <div class="wsa-task-progress-head"><span class="wsa-label">{{ __('Tasks') }}</span><span>{{ __(':done of :total done', ['done' => $o['progress']['done'], 'total' => $o['progress']['total']]) }}</span></div>
                <div class="wsa-progress-bar"><span style="width: {{ $o['progress']['percent'] }}%"></span></div>
            </div>
        @endif

        {{-- ce urmează: blocajele întâi, apoi pașii următori din ultimele update-uri, pe două coloane --}}
        <div class="wsa-overview-next">
            <div class="wsa-overview-next-head"><span class="wsa-label">{{ __('What is next') }}</span>
                @if ($o['blockers']->isNotEmpty() || $o['next']->isNotEmpty())<span class="wsa-meta">{{ __('from the latest updates') }}</span>@endif</div>
            @if ($o['blockers']->isEmpty() && $o['next']->isEmpty())
                <p class="wsa-empty">{{ __('Nothing planned yet: the next steps come from the "Next" lines of the latest updates.') }}</p>
            @else
                <div class="wsa-overview-next-list">
                    {{-- un rând din update are acțiunile lui (Rezolvat, Fă sarcină): linkul e doar pe text --}}
                    @foreach ($o['blockers'] as $b)
                        <div class="wsa-week-line is-blocked" wire:key="pb-{{ $b['item_id'] ?? 't'.$b['task_id'] }}">
                            <span class="wsa-mark is-blocked" aria-hidden="true"></span>
                            <span><a href="{{ $b['task_id'] ? TaskResource::getUrl('view', ['record' => $b['task_id']]) : UpdateResource::getUrl('view', ['record' => $b['update']]) }}" class="wsa-week-line-text">{{ Markdown::inline($b['text'], links: false) }}</a><span class="wsa-meta">{{ __('Blocked') }}</span>
                                @if ($b['item_id'] && ! auth()->user()->isClient())<livewire:item-actions :item-id="$b['item_id']" :compact="true" :wire:key="'pia-'.$b['item_id']" />@endif</span>
                        </div>
                    @endforeach
                    @foreach ($o['next'] as $n)
                        <div class="wsa-week-line" wire:key="pn-{{ $n['item_id'] }}">
                            <span class="wsa-mark is-todo" aria-hidden="true"></span>
                            <span><a href="{{ $n['task_id'] ? TaskResource::getUrl('view', ['record' => $n['task_id']]) : UpdateResource::getUrl('view', ['record' => $n['update']]) }}" class="wsa-week-line-text">@if ($n['task_id'])<span class="wsa-id">#{{ $n['task_id'] }}</span>@endif{{ Markdown::inline($n['text'], links: false) }}</a>
                                <span class="wsa-meta">{{ $n['who']?->name }} · {{ $n['update']->created_at->diffForHumans() }}</span>
                                @if (! $n['task_id'] && ! auth()->user()->isClient())<livewire:item-actions :item-id="$n['item_id']" :compact="true" :wire:key="'nia-'.$n['item_id']" />@endif</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- proiectul pe zile, de la prima zi cu ceva făcut: o bară pe zi; clic pe o bară deschide ziua dedesubt --}}
        @if ($t = $o['days'])
            @php
                $fmtDate = fn (string $date) => \Illuminate\Support\Carbon::parse($date)->locale(app()->getLocale())->isoFormat('D MMMM YYYY');
            @endphp
            <div class="wsa-p-timeline" id="zile">
                <div class="wsa-p-timeline-head">
                    <span class="wsa-label">{{ __('Day by day') }}</span>
                    <span class="wsa-meta">{{ __(':from - :to', ['from' => $fmtDate($t['from']), 'to' => $t['to'] === now(auth()->user()->tz())->toDateString() ? __('today') : $fmtDate($t['to'])]) }}
                        · {{ trans_choice(':count active day of :total|:count active days of :total', $t['worked'], ['total' => count($t['days'])]) }}
                        @if ($t['hours'] && $t['max'] > 1) · {{ __('the busiest day: :time', ['time' => WorkTime::total($t['max'], auth()->user())]) }}@endif</span>
                </div>
                <div class="wsa-p-chart" x-data x-init="const s = $el.querySelector('.is-selected'); $el.scrollLeft = s ? s.offsetLeft - $el.clientWidth / 2 : $el.scrollWidth">
                    <div @class(['wsa-h-chart', 'is-dense' => count($t['days']) > 60, 'has-selected' => $this->day !== '']) style="--n: {{ count($t['days']) }}" role="group" aria-label="{{ __('Day by day') }}">
                        @foreach ($t['days'] as $d)
                            <button type="button" wire:click="selectDay('{{ $d['date'] }}')" title="{{ $d['label'] }}" aria-label="{{ $d['label'] }}"
                                @class(['wsa-h-col', 'is-weekend' => $d['weekend'], 'is-empty' => ! $d['active'], 'is-untimed' => $d['untimed'] ?? false, 'is-selected' => $d['date'] === $this->day])>
                                <span class="wsa-h-bar" style="height: {{ $d['height'] }}%">@if (($d['estimated'] ?? 0) > 0 && $d['value'] > 0)<span class="is-estimated" style="height: {{ round($d['estimated'] * 100 / $d['value'], 1) }}%"></span>@endif</span>
                                <span class="wsa-h-day">{{ $d['tick'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- timpul pe etape (Editează proiectul → Etape): o bară pe etapă, cu partea estimată mai deschisă --}}
        @if ($o['stages'] ?? [])
            @php($stageMax = max(1, ...array_column($o['stages'], 'minutes')))
            <div class="wsa-stages">
                <span class="wsa-label">{{ __('By stage') }}</span>
                @foreach ($o['stages'] as $s)
                    <div class="wsa-stage">
                        <div class="wsa-stage-name">{{ $s['name'] }}
                            <span class="wsa-meta">{{ \Illuminate\Support\Carbon::parse($s['from'])->locale(app()->getLocale())->isoFormat('D MMM') }} - {{ $s['open'] ? __('today') : \Illuminate\Support\Carbon::parse($s['to'])->locale(app()->getLocale())->isoFormat('D MMM YYYY') }}@if ($s['worked']) · {{ trans_choice(':count active day|:count active days', $s['worked']) }}@endif</span>
                        </div>
                        <span class="wsa-stage-track"><span class="wsa-stage-bar" style="width: {{ $s['minutes'] ? max(2, round($s['minutes'] * 100 / $stageMax, 1)) : 0 }}%"><i style="width: {{ $s['minutes'] ? round($s['estimated'] * 100 / $s['minutes'], 1) : 0 }}%"></i></span></span>
                        <span class="wsa-stage-time">@if ($s['minutes'])<x-wsa.duration :minutes="$s['minutes']" />@else 0m @endif
                            @if ($s['estimated'])<span class="wsa-meta">{{ __('~:time estimated', ['time' => \App\Support\WorkTime::format($s['estimated'])]) }}</span>@endif</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ziua aleasă în grilă: cine a lucrat și cât, timpul adăugat, update-urile, sarcinile mișcate, documentele, notițele --}}
    @if ($d = $dayDetail)
        <section class="wsa-pp-day wsa-project-day" id="day">
            <header class="wsa-pp-day-head">
                <h2 class="wsa-first-cap">{{ $d['label'] }}</h2>
                <span class="wsa-pp-nav">
                    <button type="button" class="wsa-inline-btn" wire:click="shiftDay(-1)"><x-wsa.arrow to="left" />{{ __('Previous day') }}</button>
                    @unless ($isToday)<button type="button" class="wsa-inline-btn" wire:click="shiftDay(1)">{{ __('Next day') }}<x-wsa.arrow /></button>@endunless
                    <button type="button" class="wsa-inline-btn" wire:click="closeDay">{{ __('Close') }}</button>
                </span>
            </header>
            @if ($d['total'] || $d['code'] || $d['updates']->isNotEmpty())
                <dl class="wsa-pp-daystats">
                    @if ($d['total'])<div><dt class="wsa-label">{{ __('Time') }}</dt><dd><x-wsa.duration :minutes="$d['total']" /></dd></div>@endif
                    @if ($d['people']->isNotEmpty())<div><dt class="wsa-label">{{ __('People') }}</dt><dd>{{ $d['people']->count() }}</dd></div>@endif
                    <div><dt class="wsa-label">{{ __('Updates') }}</dt><dd>{{ $d['updates']->count() }}</dd></div>
                    @if ($d['code'])<div><dt class="wsa-label">{{ __('Code') }}</dt><dd class="is-small">{{ $d['code'] }}</dd></div>@endif
                </dl>
            @endif
            @php($hasTime = $d['people']->isNotEmpty() || $d['manual']->isNotEmpty() || $d['corrections']->isNotEmpty() || $d['estimates']->isNotEmpty() || $d['untimed']->isNotEmpty())
            <div @class(['wsa-pp-cols', 'has-time' => $hasTime])>
                @if ($hasTime)
                    <div class="wsa-pp-col">
                        @if ($d['people']->isNotEmpty())
                            <div class="wsa-group">{{ __('Who worked') }}</div>
                            @foreach ($d['people'] as $row)
                                <div class="wsa-day-project">
                                    <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $row['user'], 'day' => $this->day]) }}">{{ $row['user']->name }}</a>
                                    <span class="wsa-day-bar" style="--share: {{ max(4, round($row['minutes'] / max(1, $d['people']->max('minutes')) * 100)) }}%"></span>
                                    <span class="wsa-meta">{{ \App\Support\WorkTime::format($row['minutes']) }}</span>
                                </div>
                            @endforeach
                        @endif
                        @if ($d['manual']->isNotEmpty())
                            <div class="wsa-group">{{ __('Added time') }}</div>
                            @foreach ($d['manual'] as $entry)
                                <div class="wsa-day-project">
                                    <span>{{ $entry->note ?: ($entry->category?->getLabel() ?? __('Time')) }}</span>
                                    <span class="wsa-meta">{{ $entry->user?->name }}</span>
                                    <span class="wsa-meta">{{ \App\Support\WorkTime::format($entry->minutes) }}</span>
                                </div>
                            @endforeach
                        @endif
                        @if ($d['corrections']->isNotEmpty())
                            <div class="wsa-group">{{ __('Corrected') }}</div>
                            @foreach ($d['corrections'] as $entry)
                                <div class="wsa-day-project">
                                    <span>{{ $entry->note }}</span>
                                    <span class="wsa-meta">{{ $entry->user?->name }}</span>
                                    <span class="wsa-meta">{{ $entry->minutes > 0 ? '+' : '−' }}{{ \App\Support\WorkTime::format(abs($entry->minutes)) }}</span>
                                </div>
                            @endforeach
                        @endif
                        {{-- estimările din git: corectate pe loc de omul lor, de lider sau admin; cine a raportat fără timp și-l poate adăuga --}}
                        @if ($d['estimates']->isNotEmpty() || $d['untimed']->isNotEmpty())
                            <div class="wsa-group">{{ __('Estimated time') }}</div>
                            @foreach ($d['estimates'] as $entry)
                                @php($mayEdit = $entry->user_id === auth()->id() || auth()->user()->leads($this->record))
                                <div class="wsa-day-project wsa-estimate" x-data="{ editing: false, value: @js(\App\Support\WorkTime::format($entry->minutes)) }" wire:key="est-{{ $entry->id }}">
                                    <span>{{ $entry->user?->name }}</span>
                                    <span class="wsa-meta" x-show="! editing">~{{ \App\Support\WorkTime::format($entry->minutes) }}</span>
                                    @if ($mayEdit)
                                        <span class="wsa-estimate-actions" x-show="! editing">
                                            <button type="button" class="wsa-inline-btn" x-on:click="editing = true; $nextTick(() => $refs.input.select())">{{ __('Correct') }}</button>
                                            <button type="button" class="wsa-inline-btn" wire:click="deleteEstimate({{ $entry->id }})" wire:confirm="{{ __('Remove this estimate?') }}">{{ __('Remove') }}</button>
                                        </span>
                                        <form class="wsa-estimate-form" x-show="editing" x-cloak x-on:submit.prevent="$wire.saveEstimate({{ $entry->id }}, value); editing = false">
                                            <input type="text" x-ref="input" x-model="value" class="wsa-estimate-input" aria-label="{{ __('Time') }}" placeholder="2h 30m" x-on:keydown.escape="editing = false">
                                            <button type="submit" class="wsa-inline-btn">{{ __('Save') }}</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                            @foreach ($d['untimed'] as $person)
                                @if ($person->id === auth()->id() || auth()->user()->leads($this->record))
                                    <form class="wsa-day-project wsa-estimate" x-data="{ value: '' }" x-on:submit.prevent="if (value) $wire.addEstimate({{ $person->id }}, value)" wire:key="est-add-{{ $person->id }}">
                                        <span>{{ $person->name }}</span>
                                        <span class="wsa-meta">{{ __('reported, no time') }}</span>
                                        <span class="wsa-estimate-form">
                                            <input type="text" x-model="value" class="wsa-estimate-input" aria-label="{{ __('Time') }}" placeholder="2h 30m">
                                            <button type="submit" class="wsa-inline-btn">{{ __('Add') }}</button>
                                        </span>
                                    </form>
                                @endif
                            @endforeach
                        @endif
                    </div>
                @endif
                <div class="wsa-pp-col">
                    @if ($d['updates']->isNotEmpty())
                        <div class="wsa-group">{{ __('Updates') }}</div>
                        @foreach ($d['updates'] as $update)
                            <a href="{{ UpdateResource::getUrl('view', ['record' => $update]) }}" class="wsa-feed">
                                <div class="wsa-feed-text">{{ Markdown::inline($update->summary, links: false) }}</div>
                                <div class="wsa-feed-meta">{{ $update->user?->name }} · {{ $update->created_at->copy()->setTimezone(auth()->user()->tz())->format('H:i') }}</div>
                            </a>
                            @foreach ($update->items->where('type', \App\Enums\UpdateItemType::Done) as $item)
                                <div class="wsa-item is-done"><p>{{ Markdown::inline($item->text) }}</p></div>
                            @endforeach
                        @endforeach
                    @endif
                    @if ($d['events']->isNotEmpty())
                        <div class="wsa-group">{{ __('Tasks') }}</div>
                        @foreach ($d['events'] as $event)
                            <div class="wsa-history">
                                <div class="wsa-row-when">{{ $event->created_at->copy()->setTimezone(auth()->user()->tz())->format('H:i') }}</div>
                                <div class="wsa-row-text">
                                    @if ($event->task)<a href="{{ TaskResource::getUrl('view', ['record' => $event->task]) }}"><span class="wsa-id">#{{ $event->task->id }}</span>{{ $event->task->title }}</a>@endif
                                    <span class="wsa-meta"> · {{ $event->describe() }}</span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                    @if ($d['documents']->isNotEmpty())
                        <div class="wsa-group">{{ __('Documents') }}</div>
                        @foreach ($d['documents'] as $version)
                            <div class="wsa-history">
                                <div class="wsa-row-when">{{ $version->created_at->copy()->setTimezone(auth()->user()->tz())->format('H:i') }}</div>
                                <div class="wsa-row-text">
                                    <a href="{{ ProjectResource::getUrl('document', ['record' => $this->record, 'document' => $version->document_id, 'v' => $version->version]) }}">{{ $version->title }}</a>
                                    <span class="wsa-meta"> · {{ $version->author?->name }} · {{ $version->version === 1 ? __('created') : __('version :version', ['version' => $version->version]) }}</span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                    @if ($d['notes']->isNotEmpty())
                        <div class="wsa-group">{{ __('Notes for the team') }}</div>
                        @foreach ($d['notes'] as $note)
                            <div class="wsa-note">
                                <div class="wsa-prose is-compact">{{ Markdown::html($note->body, breaks: true) }}</div>
                                <div class="wsa-meta">{{ $note->author?->name }}</div>
                            </div>
                        @endforeach
                    @endif
                    @if ($d['updates']->isEmpty() && $d['events']->isEmpty() && $d['documents']->isEmpty() && $d['notes']->isEmpty() && ! $hasTime)
                        <p class="wsa-empty">{{ __('Nothing recorded on this day.') }}</p>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <div class="wsa-split">
        {{-- cronologia, mare: update-urile desfăcute, pe zile; pentru echipă, și documentele și fișierele noi --}}
        <section class="wsa-block wsa-timeline">
            <header class="wsa-block-head">
                <h2>{{ __('Timeline') }}</h2>
                <span>{{ $client ? __('updates, newest first') : __('updates and documents, newest first') }}</span>
            </header>
            @forelse ($timeline as $entry)
                @php($day = Ago::day($entry['at']))
                @if ($day !== ($lastDay ?? null))<div class="wsa-daybreak">{{ $lastDay = $day }}</div>@endif
                @if ($entry['kind'] === 'update')
                    <x-wsa.update-entry :update="$entry['update']" :groups="$entry['groups']" wire:key="update-{{ $entry['update']->id }}" />
                @else
                    <div class="wsa-entry is-minor">
                        <span>@if ($entry['icon'] ?? null)<x-filament::icon :icon="match ($entry['icon']) { 'done' => \Filament\Support\Icons\Heroicon::OutlinedCheckCircle, 'idea' => \Filament\Support\Icons\Heroicon::OutlinedLightBulb, 'file' => \Filament\Support\Icons\Heroicon::OutlinedPaperClip, 'link' => \Filament\Support\Icons\Heroicon::OutlinedLink, default => \Filament\Support\Icons\Heroicon::OutlinedDocumentText }" class="wsa-entry-icon" />@endif<x-wsa.new :at="$entry['at']" :by="$entry['by']" /><a href="{{ $entry['url'] }}" @if ($entry['external']) target="_blank" rel="noopener noreferrer" @endif>{{ $entry['text'] }}</a></span>
                        <span class="wsa-meta">@if ($entry['extra']){{ $entry['extra'] }} · @endif{{ $entry['who'] ?? __('Someone') }} · <x-wsa.time :at="$entry['at']" /></span>
                    </div>
                @endif
            @empty
                <p class="wsa-empty">{{ __('No updates on this project yet.') }}</p>
            @endforelse
            @if ($more)
                {{-- mai vechi, singure: când capătul listei se apropie de ecran se încarcă următoarele; butonul rămâne, pentru cine nu derulează --}}
                <div class="wsa-more-auto" wire:key="more-{{ $this->shown }}" x-data x-intersect.margin.600px.once="$wire.loadMore()">
                    <span class="wsa-meta" wire:loading wire:target="loadMore">{{ __('Loading older ones…') }}</span>
                    <button type="button" class="wsa-link wsa-more" wire:click="loadMore" wire:loading.remove wire:target="loadMore">{{ __('Older') }}</button>
                </div>
            @endif
        </section>

        <div class="wsa-aside">
            <livewire:project-tasks :project="$this->record" />
            @unless ($client)                    {{-- clientul vede sarcinile, update-urile și ce i-a arătat echipa din documente; notele și repo-urile sunt ale echipei --}}
                <livewire:project-repo-suggestions :project="$this->record" />
            @endunless
            <livewire:project-documents :project="$this->record" />
            <livewire:project-knowledge :project="$this->record" />   {{-- clientul: notițele firmei lui și deciziile pe care le vede --}}
            @if (auth()->user()->seesWholeTeam() || auth()->user()->can('update', $this->record))   {{-- și colaboratorul care îl conduce: îl deschide altora --}}
                <livewire:project-access :project="$this->record" />
            @endif
        </div>
    </div>
</x-filament-panels::page>
