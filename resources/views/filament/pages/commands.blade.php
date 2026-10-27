{{--
    Ghidul: cuprinsul, procesele (scheme pas cu pas), ce înseamnă fiecare lucru, cine vede ce, comenzile (compartimente care se deschid,
    cu căutare; stânga comanda de copiat sau fraza de spus, dreapta ce face și unde merge) și, la sfârșit, locurile de conectare.
--}}
<x-filament-panels::page>
    <div class="wsa-cmds" x-data="{
            q: '',
            norm(s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() },
            hit(el) { return ! this.q || el.dataset.q.includes(this.norm(this.q)) },
            any(el) { return [...el.querySelectorAll('[data-q]')].some(r => this.hit(r)) },
        }">
        {{-- turul: ce e cemainou și cum merge, în pași animați, pe tot ecranul --}}
        <button type="button" class="wsa-guide-tour" data-wsa-tour>
            <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M16.5 47.5 46.5 17.5M16.5 17.5H46.5V47.5" fill="none" stroke="currentColor" stroke-width="7"/></svg>
            <span><b>{{ __('How cemainou works') }}</b><span class="wsa-meta">{{ __('A one-minute tour: the team, the agents, tasks, updates and what the client sees.') }}</span></span>
            <span class="wsa-guide-tour-go">{{ __('Start the tour') }}<x-wsa.arrow /></span>
        </button>

        <nav class="wsa-guide-toc" aria-label="{{ __('Contents') }}">
            <a href="#flows">{{ __('How it flows') }}</a>
            <a href="#entities">{{ __('What each thing is') }}</a>
            <a href="#roles">{{ __('Who sees what') }}</a>
            <a href="#commands">{{ __('Agent commands') }}</a>
            <a href="#places">{{ __('Where you work') }}</a>
        </nav>

        @if ($tipsHidden)
            <p class="wsa-meta wsa-guide-tips-off">{{ __('“Did you know” is hidden on Today.') }} <button type="button" class="wsa-inline-btn" wire:click="showTips">{{ __('Show it again') }}</button></p>
        @endif

        {{-- procesele: câte o schemă pe orizontală (pe telefon, pe verticală), pașii numerotați, legați cu săgeți --}}
        <section id="flows" class="wsa-guide-section">
            <header class="wsa-block-head"><h2>{{ __('How it flows') }}</h2><span>{{ __('the work, step by step') }}</span></header>
            @foreach ($flows as $flow)
                <div id="{{ $flow['key'] }}" class="wsa-flow">
                    <div class="wsa-flow-head"><h3>{{ $flow['title'] }}</h3><span class="wsa-meta">{{ $flow['sub'] }}</span></div>
                    <ol class="wsa-flow-steps" style="--steps: {{ count($flow['steps']) }}">
                        @foreach ($flow['steps'] as $step)
                            <li class="wsa-flow-step">
                                <div class="wsa-flow-top"><x-filament::icon :icon="$step['icon']" class="wsa-flow-icon" /><span class="wsa-flow-n">{{ $loop->iteration }}</span></div>
                                <b>{{ $step['title'] }}</b>
                                <p>{{ $step['text'] }}</p>
                                @unless ($loop->last)<span class="wsa-flow-arrow" aria-hidden="true"><x-wsa.arrow /></span>@endunless
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </section>

        {{-- ce înseamnă fiecare lucru: o casetă pe lucru - ce e și cum îl faci --}}
        <section id="entities" class="wsa-guide-section">
            <header class="wsa-block-head"><h2>{{ __('What each thing is') }}</h2><span>{{ __('the words of cemainou') }}</span></header>
            <div class="wsa-entity-grid">
                @foreach ($entities as $e)
                    <article id="{{ $e['key'] }}" class="wsa-entity">
                        <header><x-filament::icon :icon="$e['icon']" class="wsa-entity-icon" /><h3>{{ $e['title'] }}</h3></header>
                        <p>{{ $e['what'] }}</p>
                        <p class="wsa-entity-how"><span class="wsa-label">{{ __('How') }}</span> {{ $e['how'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- rolurile: ce vede și ce poate fiecare --}}
        <section id="roles" class="wsa-guide-section">
            <header class="wsa-block-head"><h2>{{ __('Who sees what') }}</h2><span>{{ __('the roles, and projects for chosen people') }}</span></header>
            <div class="wsa-roles">
                <div class="wsa-roles-row is-head"><span>{{ __('Role') }}</span><span>{{ __('Sees') }}</span><span>{{ __('Can') }}</span></div>
                @foreach ($roles as $r)
                    <div class="wsa-roles-row"><b>{{ $r['role'] }}</b><span>{{ $r['sees'] }}</span><span>{{ $r['can'] }}</span></div>
                @endforeach
            </div>
            <p class="wsa-meta wsa-roles-note">{{ __('Any project can be set to “only chosen people”: then the team sees it only if added, admins always.') }}</p>
        </section>

        <section id="commands" class="wsa-guide-section">
            <header class="wsa-block-head"><h2>{{ __('Agent commands') }}</h2><span>{{ __('commands with “/” in Claude Code; the rest you simply say') }}</span></header>
        <div class="wsa-cmds-bar">
            <label class="wsa-cmds-search">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
                <input type="search" x-model="q" placeholder="{{ __('Search: task, hours, client…') }}" aria-label="{{ __('Search the commands') }}">
            </label>
            <nav class="wsa-cmds-toc" aria-label="{{ __('Sections') }}">
                @foreach ($sections as $s)
                    <a href="#{{ $s['key'] }}">{{ $s['title'] }}</a>
                @endforeach
            </nav>
        </div>

        @foreach ($sections as $s)
            <details id="{{ $s['key'] }}" class="wsa-cmds-section" x-show="any($el)" x-effect="if (q) $el.open = true" @if ($loop->first) open @endif>
                <summary class="wsa-cmds-head">
                    <h2>{{ $s['title'] }}</h2>
                    <span>{{ $s['sub'] }} · {{ count($s['items']) }}</span>
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronDown" class="wsa-cmds-chevron" />
                </summary>
                @foreach ($s['items'] as $item)
                    @php $long = mb_strlen($item['text']) > 60 && $item['kind'] === 'command'; @endphp
                    <div @class(['wsa-cmds-row', 'is-long' => $long]) data-q="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($item['text'].' '.$item['what'])) }}" x-show="hit($el)">
                        <div class="wsa-cmds-what">
                            @if ($item['kind'] === 'command')
                                <span class="wsa-cmds-code" x-data="{ copied: false }">
                                    <code x-ref="c">{{ $item['text'] }}</code>
                                    <button type="button" class="wsa-cmds-copy" title="{{ __('Copy') }}" x-on:click="navigator.clipboard?.writeText($refs.c.innerText).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboard" x-show="! copied" />
                                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" x-show="copied" x-cloak />
                                        <span class="sr-only">{{ __('Copy') }}</span>
                                    </button>
                                </span>
                            @else
                                <p class="wsa-cmds-say">{{ $item['text'] }}</p>
                            @endif
                        </div>
                        <div class="wsa-cmds-desc">
                            <p>{{ $item['what'] }}</p>
                            <span class="wsa-cmds-where">{{ $where[$item['where']] }}</span>
                        </div>
                    </div>
                @endforeach
            </details>
        @endforeach
        <p class="wsa-empty" x-show="q && ! any($root)" x-cloak>{{ __('Nothing matches. Try another word, or simply ask your agent.') }}</p>
        </section>

        <section id="places" class="wsa-cmds-places wsa-guide-section" aria-label="{{ __('Where you work') }}">
            <header class="wsa-block-head"><h2>{{ __('Where you work') }}</h2><span>{{ __('connect once; the steps are in your profile') }}</span></header>
            <div class="wsa-cmds-place-grid">
                @foreach ($places as $p)
                    <a href="{{ $profileUrl }}?connect={{ $p['key'] }}" class="wsa-cmds-place">
                        <x-filament::icon :icon="$p['icon']" class="wsa-place-icon" />
                        <b>{{ $p['name'] }}</b>
                        <span>{{ $p['line'] }}</span>
                        <em>{{ $p['account'] ? __('with your account') : __('a key per device') }}<x-wsa.arrow /></em>
                    </a>
                @endforeach
            </div>
        </section>


    </div>
</x-filament-panels::page>
