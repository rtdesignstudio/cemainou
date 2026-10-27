{{-- Azi, la client: documentele și fișierele arătate lui în ultimele 14 zile. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('New for you') }}</h2><span>{{ __('shared in the last 14 days') }}</span></header>
        @foreach ($documents as $document)
            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('document', ['record' => $document->project, 'document' => $document]) }}" class="wsa-row">
                <span class="wsa-row-title">{{ $document->title }}</span>
                <span class="wsa-row-meta">{{ __('Document') }} · {{ $document->project?->name }} · <x-wsa.time :at="$document->updated_at" /></span>
            </a>
        @endforeach
        @foreach ($files as $file)
            <a href="{{ $file->href() }}" class="wsa-row" target="_blank" rel="noopener">
                <span class="wsa-row-title">{{ $file->title ?: $file->name }}</span>
                <span class="wsa-row-meta">{{ $file->isFile() ? __('File') : __('Web link') }} · {{ $file->project?->name }} · <x-wsa.time :at="$file->created_at" /></span>
            </a>
        @endforeach
        @if ($documents->isEmpty() && $files->isEmpty())
            <p class="wsa-empty">{{ __('When the team shares a document, a mockup or a link with you, it shows up here.') }}</p>
        @endif
    </section>
</x-filament-widgets::widget>
