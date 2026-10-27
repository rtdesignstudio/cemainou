{{-- Pe Azi, între titlu și logo: badge-urile din ultimele 7 zile, cel mult trei (pe telefon două), centrate cu butonul; ziua și pentru ce, în tooltip; atingi unul și ajungi la ele în profil.
     Pe desktop, desenul mare (cu contur dublu), cât logo-ul; mai mic, cel simplu --}}
@php
    $badgeUser = auth()->user();
    $recentBadges = \App\Support\Badges\Badges::for($badgeUser) ? \App\Support\Badges\Badges::recent($badgeUser) : collect();
@endphp
@if ($recentBadges->isNotEmpty())
    <div class="wsa-badges-recent">
        @foreach ($recentBadges as $b)
            @php($day = \App\Support\Badges\Badges::day($b, $badgeUser))
            <a href="{{ \App\Filament\Pages\Auth\EditProfile::getUrl() }}#badges" data-tip="{{ $day->locale(app()->getLocale())->isoFormat('D MMM') }} · {{ \App\Support\Badges\BadgeSvg::tip($b->key, $b->tier) }}">
                {!! \App\Support\Badges\BadgeSvg::badge($b->key, $b->tier, 'wsa-badge is-small', compact: true) !!}
                {!! \App\Support\Badges\BadgeSvg::badge($b->key, $b->tier, 'wsa-badge is-large') !!}
            </a>
        @endforeach
    </div>
@endif
