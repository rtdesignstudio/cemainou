{{-- Fila „Notițe" de pe Sarcini: tot ce s-a notat pentru colegi, din toate proiectele, cele noi primele; căutare, proiect, și cele vechi la cerere. --}}
@php
    $data = $this->notes();
    $me = auth()->user();
@endphp
<div class="wsa-notes-page">
    <div class="wsa-portfolio-filters">
        <label class="wsa-cmds-search">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
            <input type="search" wire:model.live.debounce.300ms="noteFind" placeholder="{{ __('Search the notes') }}" aria-label="{{ __('Search the notes') }}">
        </label>
        <label class="wsa-notes-toggle"><input type="checkbox" wire:model.live="withObsolete"> {{ __('Also the ones no longer valid') }}</label>
        <div class="wsa-portfolio-selects">
            @if ($data['projects']->isNotEmpty())
                <select wire:model.live="noteProject" aria-label="{{ __('Project') }}">
                    <option value="">{{ __('All projects') }}</option>
                    @foreach ($data['projects'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            @endif
            <x-filament::button x-data x-on:click="$dispatch('wsa-capture', { kind: 'note' })">{{ __('New note') }}</x-filament::button>
        </div>
    </div>

    @forelse ($data['notes'] as $note)
        <article @class(['wsa-note-row', 'is-obsolete' => $note->obsolete_at]) wire:key="note-{{ $note->id }}">
            <div class="wsa-note-body wsa-prose is-compact">{{ \App\Support\Markdown::html($note->body, breaks: true) }}</div>
            <p class="wsa-note-meta">
                <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $note->project]) }}">{{ $note->project->name }}</a>
                · {{ $note->author?->name ?? __('Someone') }} · <x-wsa.time :at="$note->created_at" />
                @if ($note->statusUpdate) · <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $note->statusUpdate]) }}">{{ __('from an update') }}</a>@endif
                @if ($note->obsolete_at)
                    · <span>{{ __('no longer valid') }}</span>
                @elseif ($me->can('obsolete', $note))
                    · <button type="button" class="wsa-inline-btn" wire:click="obsoleteNote({{ $note->id }})" wire:confirm="{{ __('Mark this note as no longer valid? It stays in the history.') }}">{{ __('No longer valid') }}</button>
                @endif
            </p>
        </article>
    @empty
        <p class="wsa-empty">{{ trim($this->noteFind) !== '' || $this->noteProject ? __('No note matches.') : __('No notes yet. Write what colleagues should know with + → Note, or ask your agent.') }}</p>
    @endforelse
</div>
