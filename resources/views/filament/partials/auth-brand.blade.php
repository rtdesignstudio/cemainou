{{--
    Sub formularul de intrare: „Instalează ca aplicație" (Chrome / Android: fereastra sistemului; iPhone: pașii din Safari), ascuns când e deja instalată;
    apoi cemainou mic, când sus e logo-ul companiei.
--}}
<div class="wsa-auth-foot"
     x-data="{
        prompt: window.wsaInstall ?? null,
        ios: /iphone|ipad|ipod/i.test(navigator.userAgent),
        standalone: window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
        help: false,
        install() { if (this.prompt) { this.prompt.prompt(); this.prompt.userChoice.finally(() => this.prompt = null) } else { this.help = ! this.help } },
     }"
     x-on:wsa-installable.window="prompt = window.wsaInstall">
    <template x-if="! standalone && (prompt || ios)">
        <div class="wsa-install">
            <button type="button" class="wsa-install-btn" x-on:click="install()">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDevicePhoneMobile" class="wsa-install-icon" />
                <span><b>{{ __('Install as an app') }}</b><span>{{ __('on the home screen, with notifications') }}</span></span>
            </button>
            <p class="wsa-install-help" x-show="help" x-cloak>{{ __('In Safari: tap Share, then “Add to Home Screen”.') }}</p>
        </div>
    </template>
    @if (\App\Support\Branding::logoUrl())
        <p class="wsa-auth-brand">cemainou</p>
    @endif
    {{-- limba paginii, înainte de cont (se ține în sesiune; după intrare, pe cont); ce date se păstrează: la intrare doar dacă pagina e publică,
         în invitație mereu (link semnat, dacă e doar pentru cei logați) --}}
    <p class="wsa-auth-privacy wsa-auth-lang">
        @foreach (\App\Http\Middleware\SetLocale::LOCALES as $code => $name)
            @if ($code === app()->getLocale())<b lang="{{ $code }}">{{ $name }}</b>@else<a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" hreflang="{{ $code }}">{{ $name }}</a>@endif
            @unless ($loop->last)<span aria-hidden="true">·</span>@endunless
        @endforeach
        @if (\App\Support\Privacy::isPublic() || request()->routeIs('filament.admin.auth.invitation'))
            <span aria-hidden="true">·</span><a href="{{ \App\Support\Privacy::shareableUrl() }}" target="_blank">{{ __('Privacy') }}</a>
        @endif
    </p>
</div>
