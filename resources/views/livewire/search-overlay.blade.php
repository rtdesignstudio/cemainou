{{--
    Căutarea pe tot ecranul. Se deschide cu ⌘K / Ctrl+K, cu o atingere pe „Caută" din bara de sus sau cu window.wsaSearch(); Esc o închide.
    Săgețile aleg un rezultat, Enter îl deschide, Shift+Enter duce la toate rezultatele. Căutările recente stau în browser (cele mai noi 6).
--}}
<div x-data="{
        shown: false, active: -1, recent: [],
        load() { try { this.recent = JSON.parse(localStorage.getItem('wsa-search-recent') || '[]') } catch (e) { this.recent = [] } },
        remember(q) { q = (q || '').trim(); if (q.length < 2) return; this.recent = [q, ...this.recent.filter((r) => r !== q)].slice(0, 6); try { localStorage.setItem('wsa-search-recent', JSON.stringify(this.recent)) } catch (e) {} },
        open(q = '') { this.load(); this.shown = true; this.active = -1; document.documentElement.classList.add('wsa-search-open'); $wire.show(q); this.$nextTick(() => { this.$refs.input.focus(); this.$refs.input.select() }) },
        close() { this.shown = false; document.documentElement.classList.remove('wsa-search-open') },
        init() { const open = (q) => this.open(q), close = () => this.close(); const shown = () => this.shown;
            window.wsaSearch = (q) => open(q || ''); window.wsaSearchToggle = () => shown() ? close() : open(); window.wsaSearchClose = () => close();
            // ascultătorii: o singură dată pe fereastră (navigarea fără reîncărcare refolosește documentul); câmpul de sus o deschide doar la o atingere sau cu Tab, nu la un focus pus de pagină
            if (! window.wsaSearchBound) {
            window.wsaSearchBound = true;
            window.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); window.wsaSearchToggle() } });
            const field = (el) => el?.closest?.('.fi-global-search-field');
            document.addEventListener('pointerdown', (e) => { const f = field(e.target); if (f) { e.preventDefault(); const i = f.querySelector('input'); const v = i?.value || ''; if (i) { i.value = ''; i.blur() } window.wsaSearch(v) } });
            let tabbed = 0;
            document.addEventListener('keydown', (e) => { if (e.key === 'Tab') tabbed = Date.now() });
            document.addEventListener('focusin', (e) => { if (field(e.target)) { e.target.blur(); if (Date.now() - tabbed < 600) window.wsaSearch('') } });
            document.addEventListener('livewire:navigating', () => window.wsaSearchClose?.());
        }
        },
        items() { return [...this.$root.querySelectorAll('[data-result]')] },
        move(by) { const list = this.items(); if (! list.length) return; this.active = (this.active + by + list.length) % list.length; list.forEach((el, i) => el.classList.toggle('is-active', i === this.active)); list[this.active].scrollIntoView({ block: 'nearest' }) },
        go(e) { if (e.shiftKey) { this.remember($wire.q); window.location = this.$refs.all.href; return } const list = this.items(); const pick = list[this.active] ?? list[0]; if (pick) { this.remember($wire.q); pick.click() } },
    }"
    x-on:keydown.escape.window="if (shown) close()"
    x-show="shown" x-cloak x-transition.opacity.duration.150ms
    class="wsa-search" role="dialog" aria-modal="true" aria-label="{{ __('Search') }}">
    <div class="wsa-search-inner">
        <div class="wsa-search-top">
            {{ svg('heroicon-o-magnifying-glass', 'wsa-search-icon', ['aria-hidden' => 'true']) }}
            <input x-ref="input" type="search" aria-label="{{ __('Search') }}" wire:model.live.debounce.350ms="q" placeholder="{{ __('Search anything…') }}" autocomplete="off" spellcheck="false"
                x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="go($event)" x-on:input="active = -1">
            <button type="button" class="wsa-search-close" x-on:click="close()">{{ __('Close') }} <kbd>Esc</kbd></button>
        </div>
        @include('filament.partials.search.filters')

        <div class="wsa-search-body"
            x-on:click="if ($event.target.closest('[data-result]')) remember($wire.q)">
            @if (trim($q) === '')
                {{-- ecranul gol: ce cauți des, unde ai lucrat azi, ce faci des --}}
                <div class="wsa-search-start">
                    <section class="wsa-search-group" x-show="recent.length">
                        <h3>{{ __('Recent searches') }}</h3>
                        <template x-for="r in recent" :key="r">
                            <a href="#" class="wsa-search-item" data-result x-on:click.prevent="$wire.set('q', r); $refs.input.focus()"><span class="wsa-search-title" x-text="r"></span></a>
                        </template>
                    </section>
                    @if ($start && $start['projects']->isNotEmpty())
                        <section class="wsa-search-group">
                            <h3>{{ __('Where you worked lately') }}</h3>
                            @foreach ($start['projects'] as $p)
                                <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p]) }}" class="wsa-search-item" data-result><span class="wsa-search-title">{{ $p->name }}</span></a>
                            @endforeach
                        </section>
                    @endif
                    <section class="wsa-search-group">
                        <h3>{{ __('Quick actions') }}</h3>
                        @foreach ($actions as $a)
                            <a href="{{ $a['url'] }}" class="wsa-search-item" data-result x-on:click="close()"><span class="wsa-search-title">{{ $a['label'] }}</span></a>
                        @endforeach
                    </section>
                </div>
            @elseif ($results)
                @include('filament.partials.search.results', ['results' => $results, 'q' => $q])
            @elseif (mb_strlen(trim($q)) >= 2)
                <p class="wsa-search-empty">{{ __('Nothing for «:q». Try a shorter word, or another filter.', ['q' => $q]) }}</p>
            @endif
        </div>

        <div class="wsa-search-foot">
            <span><kbd><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowUp" class="wsa-kbd-icon" /></kbd><kbd><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDown" class="wsa-kbd-icon" /></kbd> {{ __('choose') }} · <kbd>Enter</kbd> {{ __('open') }} · <kbd>Shift</kbd><kbd>Enter</kbd> {{ __('all results') }}</span>
            <a x-ref="all" href="{{ $this->allUrl() }}" x-on:click="remember($wire.q)">{{ __('See all results') }}<x-wsa.arrow /></a>
        </div>
    </div>
</div>
