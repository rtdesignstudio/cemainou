{{-- Muzica biroului: în bara de sus o iconiță (egalizatorul se mișcă cât cântă); la clic, panoul din dreapta. Panoul deschis se reîmprospătează la 10 s, închis la 60 s (iconița); în tab-urile din fundal, Livewire rărește singur. --}}
<div class="wsa-music" wire:poll.{{ $open ? '10s' : '60s' }}>
    @if ($on)
        @php($PREV = '<svg viewBox="0 0 24 24"><path d="M19 5 9 12l10 7zM5 5v14"/></svg>')
        @php($NEXT = '<svg viewBox="0 0 24 24"><path d="M5 5l10 7-10 7zM19 5v14"/></svg>')
        <button type="button" class="wsa-music-btn {{ $playing ? 'is-playing' : '' }}" wire:click="toggle" aria-label="{{ __('Office music') }}"
            data-tip="{{ $playing ? ($by && ! $radio ? __(':name added: :song', ['name' => \Illuminate\Support\Str::before($by->name, ' '), 'song' => $track->title]) : $track->title) : __('Office music') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/></svg>
            <span class="wsa-music-eq" aria-hidden="true"><i></i><i></i><i></i></span>
        </button>
        @if ($playing && ! $open)<button type="button" class="wsa-music-ticker" wire:click="toggle" aria-hidden="true" tabindex="-1"><span>{{ $track->title }}</span></button>@endif{{-- ce cântă, lângă iconiță, pe desktop --}}
        @if ($open)
            @php($here = $where && $where->id === auth()->id())
            @php($adder = $track ? ($radio ? $track->adder : $by) : null)
            @php($fmt = fn (?int $sec) => $sec ? gmdate($sec >= 3600 ? 'G:i:s' : 'i:s', $sec) : '')
            <div @class(['wsa-music-panel', 'is-here' => $here]) role="dialog" aria-label="{{ __('Office music') }}" x-on:keydown.escape.window="$wire.toggle()"
                x-data="{ init() { document.body.classList.add('wsa-music-open') }, destroy() { setTimeout(() => document.body.classList.toggle('wsa-music-open', !! document.querySelector('.wsa-music-panel'))) } }"
                x-on:keydown.space.window="if (! $event.target.closest('input, textarea, select, button, [contenteditable]')) { $event.preventDefault(); $wire.togglePlay() }">{{-- Spațiu = pauză / pornește --}}
                <header><h2>{{ __('Office music') }}</h2><button type="button" class="wsa-music-x" wire:click="toggle" aria-label="{{ __('Close') }}">×</button></header>

                @if (! $online)
                    <div class="wsa-music-msg">{{ __('Nothing plays right now. Start it on your computer, or open the office page on the office screen. The queue is kept.') }}
                        <span class="wsa-music-msg-acts">@if ($canPlay)<button type="button" class="wsa-music-claim" x-on:click="window.dispatchEvent(new CustomEvent('wsa-jukebox-claim'))">{{ __('Start on my computer') }}</button>@endif
                            <a href="{{ route('music.office') }}" target="_blank" class="wsa-link">{{ __('Open the office page') }}</a></span></div>
                @endif

                @if ($track)
                    {{-- ce cântă: clipul (dacă e aici) sau imaginea, titlul, unde și cine, progresul cu timpii pe același rând --}}
                    <section class="wsa-music-block">
                        <div class="wsa-music-video-slot" data-video-slot wire:ignore></div>{{-- aici se așază clipul, dacă muzica cântă la calculatorul ăsta --}}
                        <div class="wsa-music-now">
                            <span class="wsa-music-thumb"><img src="{{ $track->thumb() }}" alt=""></span>
                            <div>
                                <b>{{ $track->title }}</b>
                                <span class="wsa-music-where">
                                    @if ($where){{ __('Playing at') }} <abbr data-tip="{{ $where->name }}">{{ $here ? __('you') : \App\Support\Jukebox::initials($where) }}</abbr>@if ($here)<span class="wsa-away-only">{{ __(', on another device') }}</span>@endif @endif
                                    @if ($where && $adder) · @endif
                                    @if ($adder){{ $radio ? __('First added by') : __('Added by') }} <abbr data-tip="{{ $adder->name }}">{{ \App\Support\Jukebox::initials($adder) }}</abbr>@endif
                                    {{-- „Oprește aici" doar pe dispozitivul care cântă (body.wsa-plays-here); același cont pe telefon vede „Ascultă aici" --}}
                                    @if ($here)<span class="wsa-here-only"> · <button type="button" class="wsa-music-textbtn" x-on:click="window.dispatchEvent(new CustomEvent('wsa-jukebox-stop'))">{{ __('Stop here') }}</button></span>@endif
                                    @if ($here && $canPlay)<span class="wsa-away-only"> · <button type="button" class="wsa-music-textbtn" x-on:click="window.dispatchEvent(new CustomEvent('wsa-jukebox-claim'))">{{ __('Listen here') }}</button></span>
                                    @elseif ($where && ! $here && $canPlay) · <button type="button" class="wsa-music-textbtn" x-on:click="window.dispatchEvent(new CustomEvent('wsa-jukebox-claim'))">{{ __('Listen on my computer') }}</button>@endif
                                </span>
                            </div>
                        </div>
                        <div class="wsa-music-prog" wire:key="prog-{{ $track->id }}" data-pos="{{ (float) $position }}" data-dur="{{ (float) ($duration ?: $track->duration) }}" data-on="{{ $online && ! $paused ? 1 : 0 }}"
                            x-data="{ pos: 0, dur: 0, on: false, dragging: false, timer: null,
                                init() { this.sync(); new MutationObserver(() => this.sync()).observe(this.$el, { attributes: true }); this.timer = setInterval(() => { if (this.on && ! this.dragging && (! this.dur || this.pos < this.dur)) this.pos++ }, 1000) },
                                destroy() { clearInterval(this.timer) },
                                sync() { if (this.dragging) return; this.pos = +$el.dataset.pos; this.dur = +$el.dataset.dur; this.on = $el.dataset.on === '1' }, at(e) { const r = this.$refs.bar.getBoundingClientRect(); return Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * this.dur }, fmt(s) { s = Math.max(0, Math.floor(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') } }">{{-- același element între reîmprospătări (un salt tras cu mouse-ul nu se pierde); valorile vin prin data-* --}}
                            <span x-text="fmt(pos)"></span>
                            <span class="wsa-music-bar is-seek" x-ref="bar" role="slider" aria-label="{{ __('Position in the song') }}" :aria-valuenow="Math.floor(pos)" aria-valuemin="0" :aria-valuemax="Math.floor(dur)"
                                x-on:pointerdown="if (!dur) return; $el.setPointerCapture($event.pointerId); dragging = true; pos = at($event)"
                                x-on:pointermove="if (dragging) pos = at($event)"
                                x-on:pointerup="if (dragging) { dragging = false; pos = at($event); $wire.seek(pos) }"><i :style="`width: ${dur ? Math.min(100, pos / dur * 100) : 0}%`"></i></span>
                            <span x-text="dur ? fmt(dur) : ''"></span>
                        </div>
                    </section>

                    <section class="wsa-music-block">
                        <div class="wsa-music-ctl">
                            <button type="button" wire:click="control('previous')" aria-label="{{ __('Previous song') }}">{!! $PREV !!}</button>
                            @if ($paused)
                                <button type="button" wire:click="control('play')" aria-label="{{ __('Play') }}" class="is-main"><svg viewBox="0 0 24 24"><path d="M7 4v16l13-8z"/></svg></button>
                            @else
                                <button type="button" wire:click="control('pause')" aria-label="{{ __('Pause') }}" class="is-main"><svg viewBox="0 0 24 24"><path d="M8 5v14M16 5v14"/></svg></button>
                            @endif
                            <button type="button" wire:click="control('next')" aria-label="{{ __('Next song') }}">{!! $NEXT !!}</button>
                        </div>
                        <div class="wsa-music-modes">
                            <button type="button" wire:click="control('shuffle')" @class(['is-mode', 'is-on' => $shuffle]) aria-pressed="{{ $shuffle ? 'true' : 'false' }}" aria-label="{{ __('Shuffle') }}" data-tip="{{ $shuffle ? __('Shuffle: on') : __('Shuffle: off') }}"><svg viewBox="0 0 24 24"><path d="M16 4h4v4M4 20 20 4M20 16v4h-4M15 15l5 5M4 4l5 5"/></svg></button>
                            <div class="wsa-music-vol" x-data="{ v: {{ $volume }} }">
                                <button type="button" x-on:click="v = v > 0 ? 0 : 60; $wire.volume(v)" :aria-label="v > 0 ? @js(__('Mute')) : @js(__('Sound on'))"><svg viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4z"/><path x-show="v > 0" d="M16 9a4.5 4.5 0 0 1 0 6"/><path x-show="v > 50" d="M18.5 6.5a8 8 0 0 1 0 11"/><path x-show="v == 0" d="M16 9.5l5 5M21 9.5l-5 5"/></svg></button>
                                <input type="range" min="0" max="100" step="5" x-model.number="v" x-on:change="$wire.volume(v)" aria-label="{{ __('Volume') }}" :style="`--v: ${v}%`">
                            </div>
                            <button type="button" wire:click="control('repeat')" @class(['is-mode', 'is-on' => $repeat]) aria-pressed="{{ $repeat ? 'true' : 'false' }}" aria-label="{{ __('Repeat') }}" data-tip="{{ match ($repeat) { 'one' => __('Repeat: this song. Press again for the queue.'), 'queue' => __('Repeat: the queue. Press again for everything.'), 'all' => __('Repeat: everything, the queue then the library. Press again to stop.'), default => __('Repeat: off. After the queue, the whole library plays once.') } }}"><svg viewBox="0 0 24 24"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></svg>@if ($repeat === 'one')<span class="wsa-music-one">1</span>@elseif ($repeat === 'queue')<span class="wsa-music-one">{{ __('Q') }}</span>@endif</button>
                        </div>
                    </section>
                @endif

                <div class="wsa-music-scroll">{{-- doar partea de jos se derulează; sus, clipul și butoanele stau pe loc --}}
                    <section class="wsa-music-block">
                        <form class="wsa-music-add" wire:submit="add">{{-- Enter pune piesa la coadă --}}
                            <input type="url" wire:model.live.debounce.400ms="url" placeholder="{{ __('Paste a YouTube link') }}" autocomplete="off" @class(['is-filled' => $url !== ''])>
                            <button type="submit">{{ __('To the queue') }}</button>
                        </form>
                        <p class="wsa-meta wsa-music-hint">{{ __('youtube.com/watch?v=… or youtu.be/… · with &t=90 it starts from there') }}</p>
                        @if ($preview)
                            <div class="wsa-music-preview">
                                <span class="wsa-music-thumb"><img src="{{ $preview['thumb'] }}" alt=""></span>
                                <div><b>{{ $preview['title'] }}</b><span>{{ $preview['author'] ?? 'YouTube' }}@if ($preview['start']) · {{ __('from :time', ['time' => $fmt($preview['start'])]) }}@endif</span></div>
                            </div>
                        @endif
                        @if ($error)<p class="wsa-music-msg is-err" wire:key="err-{{ md5($error) }}">{{ $error }}</p>
                        @elseif ($added)<p class="wsa-music-msg is-ok" wire:key="ok-{{ md5($added) }}" x-data x-init="setTimeout(() => $wire.set('added', null), 4000)">{{ $added }}</p>@endif
                    </section>

                    {{-- Biblioteca: o singură listă; sus, ce urmează (în ordine, se trage cu mouse-ul), dedesubt toate celelalte piese ale echipei --}}
                    <section class="wsa-music-block">
                        <div class="wsa-music-libhead">
                            <h3 class="wsa-label">{{ __('Library') }} · {{ $libraryCount }}</h3>
                            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search') }}" aria-label="{{ __('Search the library') }}">
                        </div>
                        {{-- filtre: toate, preferatele (cu voturi), apoi fiecare om care a pus piese; pe un filtru, „Cântă tot" --}}
                        <div class="wsa-music-chips">
                            <button type="button" @class(['is-on' => $person === '']) wire:click="$set('person', '')">{{ __('All') }}</button>
                            @if ($favCount)<button type="button" @class(['is-on' => $person === 'fav']) wire:click="$set('person', 'fav')">{{ __('Favorites') }} <small>{{ $favCount }}</small></button>@endif
                            @foreach ($people as $p)
                                <button type="button" @class(['is-on' => $person === (string) $p['user']->id]) wire:click="$set('person', '{{ $p['user']->id }}')" data-tip="{{ $p['user']->name }}">{{ \App\Support\Jukebox::initials($p['user']) }} <small>{{ $p['n'] }}</small></button>
                            @endforeach
                        </div>
                        @if ($person !== '')
                            @php($who = $person === 'fav' ? __('the favorites') : ($people->firstWhere(fn ($p) => (string) $p['user']->id === $person)['user']->name ?? ''))
                            <div class="wsa-music-playall">
                                <button type="button" wire:click="playAll(true)"><svg viewBox="0 0 24 24"><path d="M7 4v16l13-8z"/></svg> {{ __('Play all from :who', ['who' => $who]) }}</button>
                                <button type="button" class="is-ghost" wire:click="playAll(false)" data-tip="{{ __('After what comes next') }}">{{ __('To the queue') }}</button>
                            </div>
                        @endif
                        <div class="wsa-music-list" x-data="{ drag: null }">
                            @if ($search === '' && $person === '')
                                @foreach ($queue as $n => $q)
                                    <div class="wsa-music-row is-next" wire:key="q-{{ $q->id }}" data-id="{{ $q->id }}" draggable="true"
                                        x-on:dragstart="drag = $el; $el.classList.add('is-drag')" x-on:dragend="$el.classList.remove('is-drag'); drag = null"
                                        x-on:dragover.prevent="if (drag && drag !== $el && drag.classList.contains('is-next')) { const r = $el.getBoundingClientRect(); $el.parentNode.insertBefore(drag, $event.clientY < r.top + r.height / 2 ? $el : $el.nextSibling) }"
                                        x-on:drop.prevent="if (drag?.classList.contains('is-next')) $wire.reorder([...$el.parentNode.querySelectorAll('.is-next')].map(e => e.dataset.id))"
                                        @if ($here) wire:click="playNow({{ $q->track_id }})" role="button" @endif>
                                        <span class="wsa-music-thumb"><img src="{{ $q->track->thumb() }}" alt="" loading="lazy"></span>
                                        <div><b>{{ $q->track->title }}</b><span><em class="wsa-music-tag">{{ __('next :n', ['n' => $n + 1]) }}</em> <abbr data-tip="{{ __('added by :name', ['name' => $q->user?->name]) }}">{{ \App\Support\Jukebox::initials($q->user) }}</abbr>@if ($q->track->duration) · {{ $fmt($q->track->duration) }}@endif</span></div>
                                        <span class="wsa-music-acts" x-on:click.stop>
                                            <button type="button" wire:click="playNow({{ $q->track_id }})" data-tip="{{ __('Play now') }}" aria-label="{{ __('Play now') }}"><svg viewBox="0 0 24 24"><path d="M7 4v16l13-8z"/></svg></button>
                                            <button type="button" wire:click="unqueue({{ $q->id }})" data-tip="{{ __('Take it out of the queue') }}" aria-label="{{ __('Take it out of the queue') }}">×</button>
                                        </span>
                                    </div>
                                @endforeach
                                @if ($queue->isEmpty() && $track)
                                    <p class="wsa-music-msg">{{ __('Nothing comes next: the whole library plays, one song after another. Add a song and it comes next.') }}</p>
                                @endif
                            @endif
                            @forelse ($library as $h)
                                @php($off = $h->excluded_until?->isFuture())
                                <div @class(['wsa-music-row', 'is-lib' => $person !== 'fav', 'is-off' => $off]) wire:key="h-{{ $h->id }}" data-id="{{ $h->id }}" @if ($here && ! $h->blocked) wire:click="playNow({{ $h->id }})" role="button" @endif
                                    @if ($person !== 'fav'){{-- ordinea bibliotecii se trage cu mouse-ul, ca la ce urmează; preferatele rămân după voturi --}}
                                        draggable="true" x-on:dragstart="drag = $el; $el.classList.add('is-drag')" x-on:dragend="$el.classList.remove('is-drag'); drag = null"
                                        x-on:dragover.prevent="if (drag && drag !== $el && drag.classList.contains('is-lib')) { const r = $el.getBoundingClientRect(); $el.parentNode.insertBefore(drag, $event.clientY < r.top + r.height / 2 ? $el : $el.nextSibling) }"
                                        x-on:drop.prevent="if (drag?.classList.contains('is-lib')) $wire.reorderLibrary([...$el.parentNode.querySelectorAll('.is-lib')].map(e => e.dataset.id))"
                                    @endif>
                                    <span class="wsa-music-thumb"><img src="{{ $h->thumb() }}" alt="" loading="lazy"></span>
                                    <div>
                                        <b>{{ $h->title }}</b>
                                        <span>
                                            @if ($off){{ __('excluded until :day', ['day' => $h->excluded_until->locale(app()->getLocale())->dayName]) }}
                                            @elseif ($h->blocked){{ __('does not play on other sites') }}
                                            @elseif ($h->last_played_at){{ $h->last_played_at->isToday() ? __('played at :time', ['time' => $h->last_played_at->timezone(auth()->user()->tz())->format('H:i')]) : __('played on :date', ['date' => $h->last_played_at->timezone(auth()->user()->tz())->locale(app()->getLocale())->translatedFormat('j M, H:i')]) }}@if ($h->adder) · {{ \App\Support\Jukebox::initials($h->adder) }}@endif
                                            @else{{ $h->author ?? 'YouTube' }}
                                            @endif
                                            @if ($h->duration) · {{ $fmt($h->duration) }}@endif
                                        </span>
                                    </div>
                                    <span class="wsa-music-acts" x-on:click.stop>
                                        @php($mine = $h->votes->contains('user_id', auth()->id()))
                                        <button type="button" @class(['wsa-music-vote', 'is-on' => $mine]) wire:click="vote({{ $h->id }})" aria-pressed="{{ $mine ? 'true' : 'false' }}" aria-label="{{ __('Like') }}"
                                            data-tip="{{ $h->votes_count ? __('Liked by :names', ['names' => $h->votes->map(fn ($v) => $v->user?->name)->filter()->join(', ')]) : __('Like') }}"><svg viewBox="0 0 24 24"><path d="M7 10v11H3V10zM7 10l4-8c1.7 0 3 1.3 3 3v4h5.5a2 2 0 0 1 2 2.3l-1.4 8A2 2 0 0 1 18.1 21H7"/></svg>@if ($h->votes_count)<small>{{ $h->votes_count }}</small>@endif</button>
                                        @if (! $h->blocked && ! $off)
                                            <button type="button" wire:click="again({{ $h->id }})" data-tip="{{ __('To the queue') }}" aria-label="{{ __('To the queue') }}">+</button>
                                        @endif
                                        <details class="wsa-music-menu">
                                            <summary aria-label="{{ __('More') }}">⋯</summary>
                                            <div>
                                                @if (! $h->blocked && ! $off)
                                                    <button type="button" wire:click="playNow({{ $h->id }})">{{ __('Play now') }}</button>
                                                    <button type="button" wire:click="exclude({{ $h->id }})">{{ __('Exclude for a week') }}</button>
                                                @elseif ($off)
                                                    <button type="button" wire:click="include({{ $h->id }})">{{ __('Include') }}</button>
                                                @endif
                                                <button type="button" wire:click="remove({{ $h->id }})" wire:confirm="{{ __('Delete this song from the library?') }}">{{ __('Delete') }}</button>
                                            </div>
                                        </details>
                                    </span>
                                </div>
                            @empty
                                @if ($search !== '')<p class="wsa-meta">{{ __('No song matches.') }}</p>@endif
                            @endforelse
                        </div>
                        @if (! $all && $search === '' && $person === '' && $libraryCount > $queue->count() + $library->count() + ($track ? 1 : 0))
                            <button type="button" class="wsa-music-more" wire:click="$set('all', true)">{{ __('See the whole library (:n)', ['n' => $libraryCount]) }}</button>
                        @endif
                    </section>
                </div>
            </div>
        @endif
    @endif
</div>
