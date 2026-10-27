{{-- Pe Azi, între titlu și badge-uri: gluma zilei (aceeași pentru toată echipa), cu inițialele celui care a pus-o, dacă e a echipei; clic pentru
     următoarea, + pune una nouă (scurtă), coșul o șterge (autorul sau un admin), × ascunde glumele (App\Support\Jokes) --}}
@php($jokeUser = auth()->user())
@if (\App\Support\Jokes::showFor($jokeUser) && ($jokes = \App\Support\Jokes::today($jokeUser)))
    <div class="wsa-joke" x-data="{ i: 0, list: @js($jokes), gone: false, adding: false, text: '', saving: false, max: {{ \App\Models\TeamJoke::MAX }},
            csrf() { return document.querySelector('meta[name=csrf-token]')?.content },
            async save() {
                if (this.text.trim().length < 5 || this.saving) return;
                this.saving = true;
                const r = await fetch(@js(route('jokes.store')), { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ text: this.text }) });
                this.saving = false;
                if (r.ok) { this.list.unshift(await r.json()); this.i = 0; this.text = ''; this.adding = false }
            },
            async remove() {
                const j = this.list[this.i];
                if (! j.id || ! confirm(@js(__('Delete this joke?')))) return;
                await fetch(@js(url('/jokes')) + '/' + j.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                this.list.splice(this.i, 1); this.i = 0;
            } }" x-show="! gone && list.length">
        <template x-if="! adding">
            <div class="wsa-joke-row">
                <button type="button" class="wsa-joke-text" x-on:click="i = (i + 1) % list.length" data-tip="{{ __('Another one') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 7H6v5h4v-1c0 2-1 4-3 5M18 7h-4v5h4v-1c0 2-1 4-3 5"/></svg>
                    <span><span x-text="list[i].text">{{ $jokes[0]['text'] }}</span><template x-if="list[i].by"><abbr class="wsa-joke-by" :data-tip="list[i].name" x-text="' · ' + list[i].by"></abbr></template></span>
                </button>
                <button type="button" class="wsa-joke-icon" x-show="list[i].mine && list[i].id" x-on:click="remove()" aria-label="{{ __('Delete this joke') }}" data-tip="{{ __('Delete this joke') }}">
                    <svg viewBox="0 0 24 24"><path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13"/></svg></button>
                <button type="button" class="wsa-joke-icon" x-on:click="adding = true; $nextTick(() => $root.querySelector('input')?.focus())" aria-label="{{ __('Add a joke') }}" data-tip="{{ __('Add a joke for the team') }}">
                    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button>
                <button type="button" class="wsa-joke-x" aria-label="{{ __('Hide the jokes') }}" data-tip="{{ __('Hide the jokes') }}"
                    x-on:click="gone = true; fetch(@js(route('jokes.hide')), { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } })">×</button>
            </div>
        </template>
        <template x-if="adding">
            <form class="wsa-joke-add" x-on:submit.prevent="save()" x-on:keydown.escape="adding = false">
                <input type="text" x-model="text" :maxlength="max" placeholder="{{ __('A short joke for the team') }}" aria-label="{{ __('A short joke for the team') }}">
                <span class="wsa-joke-count" x-text="max - text.length"></span>
                <button type="submit" :disabled="text.trim().length < 5 || saving">{{ __('Add') }}</button>
                <button type="button" class="wsa-joke-x" x-on:click="adding = false" aria-label="{{ __('Cancel') }}">×</button>
            </form>
        </template>
    </div>
@endif
