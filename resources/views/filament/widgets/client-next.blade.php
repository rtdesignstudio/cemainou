{{-- Azi, la client: ce urmează, din ultimele update-uri. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('Coming next') }}</h2></header>
        @forelse ($items as $item)
            <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $item->statusUpdate]) }}" class="wsa-row">
                <span class="wsa-row-title">{{ \App\Support\Markdown::plain($item->text) }}</span>
                <span class="wsa-row-meta">{{ $item->statusUpdate->user?->name }} · {{ $item->statusUpdate->project?->name }}</span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('The next steps from the team\'s updates show up here.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
