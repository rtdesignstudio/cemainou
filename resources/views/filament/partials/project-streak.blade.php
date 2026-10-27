{{-- Pe pagina unui proiect, în dreapta antetului: seria proiectului (de la 10 zile de lucru la rând cu update), doar badge-ul; ce înseamnă, în tooltip. Pentru echipă, nu pentru client --}}
@php
    $streakProject = \Livewire\Livewire::current()?->record ?? null;
    $streak = $streakProject && ! auth()->user()->isClient() && \App\Support\Badges\Badges::enabled() ? \App\Support\Badges\ProjectStreak::current($streakProject) : 0;
@endphp
@if ($streak >= 10)
    <div class="wsa-badges-recent wsa-project-streak">
        <span data-tip="{{ __('Up to date · :days', ['days' => trans_choice(':count day in a row|:count days in a row', $streak)]) }}: {{ \App\Support\Badges\Catalog::criterion('project_streak') }}">
            {!! \App\Support\Badges\BadgeSvg::badge('project_streak', \App\Support\Badges\Catalog::tierFor('project_streak', $streak), compact: true) !!}
        </span>
    </div>
@endif
