{{-- Azi → Proiectele urmărite (și cele conduse): sarcini deschise, blocate, ultimul update. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('Projects I follow') }}</h2></header>
        @forelse ($projects as $project)
            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $project]) }}" class="wsa-row">
                <span class="wsa-row-title">{{ $project->name }}</span>
                <span class="wsa-row-meta">
                    {{ trans_choice(':count open|:count open', $project->open_count) }}@if ($project->blocked_count) · <span class="wsa-mine-waiting">{{ trans_choice(':count blocked task|:count blocked tasks', $project->blocked_count) }}</span>@endif
                    · {{ $project->updates_max_created_at ? __('last update :when', ['when' => \Illuminate\Support\Carbon::parse($project->updates_max_created_at)->diffForHumans()]) : __('no updates yet') }}
                </span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('You do not follow any project yet: “Follow” on a project page adds it here.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
