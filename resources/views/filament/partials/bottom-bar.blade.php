{{-- Pe telefon și tabletă (unde meniul de sus se strânge): Azi, Sarcini, Update-uri, Proiecte la îndemâna degetului mare; restul rămâne în meniu. --}}
@php
    use App\Filament\Pages\Dashboard;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;

    $items = [
        [Dashboard::getNavigationLabel(), Dashboard::getUrl(), Dashboard::getNavigationIcon(), request()->routeIs(Dashboard::getRouteName())],
        [TaskResource::getNavigationLabel(), TaskResource::getUrl('index'), TaskResource::getNavigationIcon(), request()->routeIs('filament.admin.resources.tasks.*')],
        [UpdateResource::getNavigationLabel(), UpdateResource::getUrl('index'), UpdateResource::getNavigationIcon(), request()->routeIs('filament.admin.resources.updates.*')],
        [ProjectResource::getNavigationLabel(), ProjectResource::getUrl('index'), ProjectResource::getNavigationIcon(), request()->routeIs('filament.admin.resources.projects.*')],
    ];
@endphp
@auth
    <nav class="wsa-bottom-bar" aria-label="{{ __('Main') }}">
        @foreach ($items as $i => [$label, $url, $icon, $active])
            @if ($i === 2)
                {{-- la mijloc: „+" deschide alegerea - idee, sarcină, notiță, proiect, client --}}
                <button type="button" class="wsa-bottom-add" x-data x-on:click="$dispatch('wsa-capture', {})" aria-label="{{ __('Add') }}">
                    {{ svg('heroicon-o-plus', '', ['aria-hidden' => 'true']) }}
                </button>
            @endif
            <a href="{{ $url }}" @class(['is-active' => $active]) @if ($active) aria-current="page" @endif>
                <x-filament::icon :icon="$icon" class="wsa-bottom-icon" aria-hidden="true" />
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </nav>
@endauth
<script data-navigate-once>
    if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(() => {}); }   // aplicația instalabilă și notificările
</script>
