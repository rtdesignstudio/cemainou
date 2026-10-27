{{-- Azi → Notițe recente, pentru colegi, din proiectele pe care le văd. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head">
            <h2>{{ __('Recent notes') }}</h2>
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('index', ['tab' => 'notes']) }}" class="wsa-link">{{ __('All notes') }}<x-wsa.arrow /></a>
        </header>
        @forelse ($notes as $note)
            <article class="wsa-comment-row">
                <span class="wsa-comment-text wsa-prose is-compact">{{ \App\Support\Markdown::html(\Illuminate\Support\Str::limit($note->body, 220), breaks: true) }}</span>
                <span class="wsa-meta">{{ $note->project->name }} · {{ $note->author?->name }} · <x-wsa.time :at="$note->created_at" /></span>
            </article>
        @empty
            <p class="wsa-empty">{{ __('No notes yet.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
