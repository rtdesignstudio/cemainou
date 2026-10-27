{{-- Pe telefon și tabletă: contul, ca secțiune la finalul meniului ☰ (feedback #15) - aceleași rânduri ca meniul de la avatar, într-un singur loc;
     avatarul deschide direct Profilul. Pe calculator nu se vede (rămâne meniul de la avatar). --}}
@php
    use Filament\Support\Icons\Heroicon;
    $me = auth()->user();
@endphp
@if ($me)
    <div class="wsa-sidebar-account" id="wsa-sidebar-account">
        <nav>
            @if ($me->connectsAgents())<a href="{{ \App\Filament\Pages\Auth\EditProfile::getUrl() }}?connect=1">{{ \App\Support\Icon::html(Heroicon::OutlinedCommandLine, 'wsa-sidebar-account-icon') }}<span>{{ __('Agents and apps') }}</span></a>@endif
            <a href="{{ \App\Filament\Pages\Notifications::getUrl() }}">{{ \App\Support\Icon::html(Heroicon::OutlinedBell, 'wsa-sidebar-account-icon') }}<span>{{ __('Notifications') }}</span></a>
            @unless ($me->isClient())<a href="{{ \App\Filament\Pages\WhatsNew::getUrl() }}">{{ \App\Support\Icon::html(Heroicon::OutlinedSparkles, 'wsa-sidebar-account-icon') }}<span>{{ __("What's new") }} · {{ config('cemainou.version') }}</span></a>@endunless
            @foreach (\App\Http\Middleware\SetLocale::LOCALES as $code => $label)
                @if (app()->getLocale() !== $code)<a href="{{ route('locale.switch', $code) }}">{{ \App\Support\Icon::html(Heroicon::OutlinedLanguage, 'wsa-sidebar-account-icon') }}<span>{{ $label }}</span></a>@endif
            @endforeach
            <form method="POST" action="{{ filament()->getLogoutUrl() }}">
                @csrf
                <button type="submit">{{ \App\Support\Icon::html(Heroicon::OutlinedArrowLeftOnRectangle, 'wsa-sidebar-account-icon') }}<span>{{ __('Sign out') }}</span></button>
            </form>
        </nav>
    </div>
    <script data-navigate-once>
        // pe telefon, avatarul deschide direct Profilul (restul contului e la finalul meniului ☰); Filament deschide meniul la mousedown, deci îl oprim de acolo
        ['mousedown', 'touchstart', 'click', 'keyup'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                if (window.innerWidth >= 1024 || ! (e.target.closest && e.target.closest('.fi-user-menu .fi-dropdown-trigger'))) return;
                if (type === 'keyup' && e.key !== 'Enter' && e.key !== ' ') return;
                e.stopPropagation();
                if (type === 'mousedown' || type === 'touchstart') return;          // fără preventDefault: atingerea trebuie să dea totuși clicul
                e.preventDefault();                                              // navigăm o singură dată, la clic (sau Enter)
                var url = @js(\App\Filament\Pages\Auth\EditProfile::getUrl());
                window.Livewire && Livewire.navigate ? Livewire.navigate(url) : (location.href = url);
            }, { capture: true });
        });
    </script>
@endif
