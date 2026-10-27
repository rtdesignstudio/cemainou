{{-- „cemainou" (doar la RT): filele Cereri, Instalări, Licențe, Feedback; în fiecare, doar ce ne-au spus oamenii. --}}
<x-filament-panels::page>
    <nav class="wsa-tabs wsa-cmn-tabs" aria-label="cemainou">
        @foreach (['requests' => __('Requests'), 'installations' => __('Installations'), 'licenses' => __('Licenses'), 'feedback' => __('Feedback')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['is-on' => $tab === $key]) aria-pressed="{{ ($tab === $key) ? 'true' : 'false' }}">{{ $label }} <span>{{ $counts[$key] }}</span></button>
        @endforeach
    </nav>

    @if ($tab === 'requests')
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ trans_choice(':count download|:count downloads', $total) }}</h2>
                <span>{{ trans_choice(':count wants news about new versions|:count want news about new versions', $updates) }} · <a class="wsa-link" href="{{ route('downloads.export') }}">{{ __('Export their emails (CSV)') }}</a></span>
            </header>
            @forelse ($downloads as $d)
                <div class="wsa-row">
                    <span class="wsa-row-title">{{ $d->name }}@if ($d->company) · {{ $d->company }}@endif</span>
                    <span class="wsa-row-meta">{{ collect([$d->email, $d->team ? __(':team people', ['team' => $d->team]) : null, $d->version, strtoupper($d->lang), $d->wants_updates ? __('wants news') : null, \App\Support\Ago::exact($d->created_at)])->filter()->join(' · ') }}</span>
                    @if ($d->message)<span class="wsa-row-meta">«{{ \Illuminate\Support\Str::limit($d->message, 200) }}»</span>@endif
                </div>
            @empty
                <p class="wsa-meta">{{ __('No downloads yet.') }}</p>
            @endforelse
        </section>
    @elseif ($tab === 'installations')
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ trans_choice(':count installation introduced itself|:count installations introduced themselves', $installations->whereNull('left_at')->count()) }}</h2>
                <span>
                    @if ($estimate && ($estimate['max'] ?? 0))
                        {{ __('About :count check for new versions on a busy day (last 30 days, from the rt.md logs; nothing comes from the installations).', ['count' => $estimate['max']]) }}
                    @else
                        {{ __('How many check for new versions shows here after the first daily count.') }}
                    @endif
                </span>
            </header>
            <p class="wsa-meta wsa-intro">{{ __('Only installations whose admin turned on “Keep me posted” in their Settings, with an email. They can turn it off any time.') }}</p>
            @forelse ($installations as $i)
                <div @class(['wsa-row', 'is-left' => $i->left_at])>
                    <span class="wsa-row-title">{{ $i->company ?: $i->email }}@if ($i->left_at) · <span class="wsa-meta">{{ __('stopped :time', ['time' => $i->left_at->diffForHumans()]) }}</span>@endif</span>
                    <span class="wsa-row-meta">{{ collect([$i->company ? $i->email : null, $i->version ? ($i->version === $current ? $i->version.' · '.__('up to date') : $i->version) : null, isset($licensed[$i->installation]) ? __('licensed') : __('free to use'), __('since :date', ['date' => $i->first_seen_at?->toDateString()]), $i->installation])->filter()->join(' · ') }}</span>
                </div>
            @empty
                <p class="wsa-meta">{{ __('No installation has introduced itself yet.') }}</p>
            @endforelse
        </section>
    @elseif ($tab === 'licenses')
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ trans_choice(':count license|:count licenses', $licenses->count()) }}</h2>
                <span>{{ __('Keys are made with scripts/release-tools.php (the signing key stays off the server). Paste one here to keep track of it.') }}</span>
            </header>
            <form wire:submit="addLicense" class="wsa-cmn-license">
                <input type="text" wire:model="licenseKey" placeholder="CMN1.…" aria-label="{{ __('License key') }}" class="wsa-portfolio-select">
                <input type="text" wire:model="licenseNote" placeholder="{{ __('Note (optional): invoice, contact') }}" aria-label="{{ __('Note') }}" class="wsa-portfolio-select">
                <button type="submit" class="wsa-take">{{ __('Add an issued key') }}</button>
            </form>
            @error('licenseKey')<p class="wsa-error">{{ $message }}</p>@enderror
            @forelse ($licenses as $l)
                <div class="wsa-row">
                    <span class="wsa-row-title">{{ $l->licensee }}@if ($l->license_id) · <span class="wsa-meta">{{ $l->license_id }}</span>@endif</span>
                    <span class="wsa-row-meta">{{ collect([$l->until->isPast() ? __('expired on :date', ['date' => $l->until->toDateString()]) : __('until :date', ['date' => $l->until->toDateString()]), $l->installation === 'any' || ! $l->installation ? __('any installation') : $l->installation, $l->people ? __(':team people', ['team' => $l->people]) : null, $l->note])->filter()->join(' · ') }}</span>
                </div>
            @empty
                <p class="wsa-meta">{{ __('No license kept yet.') }}</p>
            @endforelse
        </section>
    @else
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ trans_choice(':count feedback from installations|:count feedback from installations', $feedback->count()) }}</h2>
                <span>{{ __('Sent by the installations that chose to: the text, the page, the screen and the version, never the person.') }}</span>
            </header>
            @forelse ($feedback as $f)
                <a href="{{ $feedbackUrl($f) }}" class="wsa-row">
                    <span class="wsa-row-title">{{ \Illuminate\Support\Str::limit($f->body, 140) }}</span>
                    <span class="wsa-row-meta">{{ collect([$f->status === 'open' ? __('still open') : __('resolved'), $f->app_version, $f->installation, \App\Support\Ago::exact($f->created_at)])->filter()->join(' · ') }}</span>
                </a>
            @empty
                <p class="wsa-meta">{{ __('No feedback from installations yet.') }}</p>
            @endforelse
        </section>
    @endif
</x-filament-panels::page>
