{{-- Pe telefon și tabletă, sus în meniul ☰: cine ești, pe un rând (o atingere duce la Profil); dedesubt, doar ce nu e deja în bara de jos.
     Azi, Sarcini, Update-uri și Proiecte stau mereu în bara de jos, așa că în meniu doar l-ar lungi. --}}
@php
    use App\Filament\Pages\Dashboard;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    $me = auth()->user();
    $inBottomBar = [Dashboard::getUrl(), TaskResource::getUrl('index'), UpdateResource::getUrl('index'), ProjectResource::getUrl('index')];
    // un client are doar paginile din bara de jos: lista din meniu ar rămâne goală, cu liniile ei; atunci contul vine direct sub profil
    $more = $me && collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems())->contains(fn ($item) => $item->isVisible() && ! in_array($item->getUrl(), $inBottomBar, true));
@endphp
@if ($me)
    <style>
        @media (max-width: 1023px) {
            @foreach ($inBottomBar as $url)
                .fi-main-sidebar .fi-sidebar-item:has(> .fi-sidebar-item-btn[href="{{ $url }}"]) { display: none; }
            @endforeach
            @unless ($more)
                .fi-main-sidebar .fi-sidebar-nav-groups { display: none; }
                .fi-main-sidebar .wsa-sidebar-account { margin-top: 0.5rem; padding-top: 0; border-top: 0; }
            @endunless
        }
    </style>
    <a href="{{ \App\Filament\Pages\Auth\EditProfile::getUrl() }}" class="wsa-sidebar-me">
        <img src="{{ filament()->getUserAvatarUrl($me) }}" alt="" class="wsa-sidebar-me-avatar">
        <span class="wsa-sidebar-me-text"><b>{{ $me->name }}</b><small>{{ $me->role->getLabel() }} · {{ __('Profile') }}</small></span>
        {{ svg('heroicon-o-chevron-right', 'wsa-sidebar-me-go', ['aria-hidden' => 'true']) }}
    </a>
@endif
